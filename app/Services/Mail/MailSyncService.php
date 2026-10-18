<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\MailAccount;
use App\Models\Mail\MailMessage;
use App\Models\Mail\MailSyncState;
use App\Services\Mail\Contracts\MailSyncProviderInterface;
use App\Services\Mail\DTO\MailSyncResult;
use App\Services\Mail\Providers\MailSyncProviderResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MailSyncService
{
    public function __construct(
        private readonly MailRuleEvaluator $evaluator,
        private readonly MailSyncProviderResolver $providerResolver,
    ) {}

    /**
     * Synchronize a single mail account.
     *
     * Coordinates:
     * 1. MailSyncProvider (Gmail / IMAP / Fake) fetching metadata batch.
     * 2. MailRuleEvaluator qualifying relevant messages.
     * 3. Saving qualified messages in MailMessage (skipping ignored and unmatched).
     * 4. Deduplication and preserving manual user classifications.
     * 5. Updating MailSyncState cursors and metrics (only on success).
     */
    public function syncAccount(
        MailAccount $account,
        ?MailSyncProviderInterface $customProvider = null
    ): MailSyncResult {
        @set_time_limit(300);
        @ignore_user_abort(true);

        // Prevent concurrent synchronization of the same mailbox across manual & scheduled triggers
        $lock = Cache::lock("mail-sync-account-{$account->id}", 180);
        if (! $lock->get()) {
            $existingState = MailSyncState::where('mail_account_id', $account->id)->first();
            if ($existingState && $existingState->sync_status === 'syncing' && $existingState->updated_at && $existingState->updated_at->lt(now()->subSeconds(90))) {
                Log::warning(sprintf('Wykryto osieroconą blokadę synchronizacji konta [%s]. Resetowanie blokady.', $account->email));
                try {
                    $lock->forceRelease();
                } catch (Throwable) {}

                if (! $lock->get()) {
                    return new MailSyncResult(
                        success: false,
                        error: sprintf('Synchronizacja konta [%s] jest już w toku.', $account->email),
                    );
                }
            } else {
                return new MailSyncResult(
                    success: false,
                    error: sprintf('Synchronizacja konta [%s] jest już w toku.', $account->email),
                );
            }
        }

        $syncState = MailSyncState::firstOrCreate(
            ['mail_account_id' => $account->id],
            [
                'sync_status' => 'idle',
                'last_synced_at' => null,
                'total_messages_count' => 0,
                'relevant_messages_count' => 0,
                'ignored_messages_count' => 0,
                'last_batch_count' => 0,
            ]
        );

        $completedCleanly = false;
        register_shutdown_function(function () use (&$completedCleanly, $syncState, $account, $lock) {
            if (! $completedCleanly) {
                $lastError = error_get_last();
                $errorMsg = $lastError ? $lastError['message'] : 'Synchronizacja została nieoczekiwanie przerwana.';
                try {
                    $syncState->sync_status = 'failed';
                    $syncState->last_error = $errorMsg;
                    $syncState->last_error_at = now();
                    $syncState->save();
                } catch (Throwable) {}
                try {
                    $account->update(['last_error' => $errorMsg]);
                } catch (Throwable) {}
                try {
                    $lock->forceRelease();
                } catch (Throwable) {}
            }
        });

        try {
            $syncState->sync_status = 'syncing';
            $syncState->last_error = null;
            $syncState->save();

            try {
                $provider = $customProvider ?? $this->providerResolver->resolve($account);

                $totalFetchedCount = 0;
                $totalQualifiedCount = 0;
                $totalIgnoredCount = 0;
                $totalUnmatchedCount = 0;
                $processedProviderIds = [];

                $finalHistoryId = null;
                $finalImapLastUid = null;
                $finalImapUidvalidity = null;

                $maxBatches = 50;
                $batchIndex = 0;

                do {
                    $batchIndex++;
                    $batch = $provider->fetchBatch($account, $syncState);

                    $totalFetchedCount += count($batch->messages);

                    if ($batch->historyId !== null) {
                        $finalHistoryId = $batch->historyId;
                    }
                    if ($batch->imapLastUid !== null) {
                        $finalImapLastUid = $batch->imapLastUid;
                    }
                    if ($batch->imapUidvalidity !== null) {
                        $finalImapUidvalidity = $batch->imapUidvalidity;
                    }

                    foreach ($batch->messages as $dto) {
                        // Prevent duplicate processing within the same sync run
                        if (isset($processedProviderIds[$dto->providerMessageId])) {
                            continue;
                        }
                        $processedProviderIds[$dto->providerMessageId] = true;

                        // Check for existing message in SQLite (by provider message ID, RFC Message-ID, or legacy UID)
                        $legacyUid = str_contains($dto->providerMessageId, ':') ? explode(':', $dto->providerMessageId, 2)[1] : null;

                        $existing = MailMessage::query()
                            ->where('mail_account_id', $account->id)
                            ->where(function ($query) use ($dto, $legacyUid) {
                                $query->where('provider_message_id', $dto->providerMessageId);
                                if (! empty($dto->messageId)) {
                                    $query->orWhere('message_id', $dto->messageId);
                                }
                                if ($legacyUid !== null) {
                                    $query->orWhere('provider_message_id', $legacyUid);
                                }
                            })
                            ->first();

                        // If existing message was found by Message-ID or legacy UID but has updated provider ID (e.g. UIDVALIDITY changed), update it
                        if ($existing !== null && $existing->provider_message_id !== $dto->providerMessageId) {
                            $existing->update(['provider_message_id' => $dto->providerMessageId]);
                        }

                        // Evaluate with existing MailRuleEvaluator
                        $evaluation = $this->evaluator->evaluate(
                            $dto->senderEmail,
                            $dto->subject,
                            $account->id,
                            $existing
                        );

                        if ($existing !== null) {
                        // Message already in database
                        if ($existing->is_manually_classified) {
                            // User's manual decision takes absolute precedence — do not overwrite status/category!
                            if ($existing->status === 'relevant' || $existing->status === 'to_review') {
                                $totalQualifiedCount++;
                            } else {
                                $totalIgnoredCount++;
                            }
                            continue;
                        }

                        // Existing message: update metadata / snippet if available
                        $updatePayload = [];
                        if (empty($existing->snippet) && ! empty($dto->snippet)) {
                            $updatePayload['snippet'] = $dto->snippet;
                        }
                        if (empty($existing->to_email) && ! empty($dto->toEmail)) {
                            $updatePayload['to_email'] = $dto->toEmail;
                        }
                        if (empty($existing->cc_email) && ! empty($dto->ccEmail)) {
                            $updatePayload['cc_email'] = $dto->ccEmail;
                        }
                        if (! empty($updatePayload)) {
                            $existing->update($updatePayload);
                        }

                        // Existing message was not manually classified: update rule/category if qualified
                        if ($evaluation['status'] !== 'ignored' && $evaluation['matched_rule'] !== null && $evaluation['matched_rule']->action === 'qualify') {
                            $existing->update([
                                'category' => $evaluation['category'],
                                'matched_rule_id' => $evaluation['matched_rule']->id,
                            ]);
                            $totalQualifiedCount++;
                        }
                        continue;
                    }

                    // New message: check qualification
                    $isQualified = ($evaluation['status'] !== 'ignored')
                        && ($evaluation['matched_rule'] !== null)
                        && ($evaluation['matched_rule']->action === 'qualify');

                    if ($isQualified) {
                        MailMessage::create([
                            'mail_account_id' => $account->id,
                            'provider_message_id' => $dto->providerMessageId,
                            'thread_id' => $dto->threadId,
                            'message_id' => $dto->messageId,
                            'sender_email' => $dto->senderEmail,
                            'sender_name' => $dto->senderName,
                            'sender_domain' => $dto->senderDomain,
                            'reply_to' => $dto->replyTo,
                            'to_email' => $dto->toEmail,
                            'cc_email' => $dto->ccEmail,
                            'subject' => $dto->subject,
                            'snippet' => $dto->snippet,
                            'body_text' => $dto->bodyText,
                            'body_html' => $dto->bodyHtml,
                            'has_attachments' => $dto->hasAttachments,
                            'attachments' => $dto->attachments,
                            'raw_headers' => ! empty($dto->rawHeaders) ? $dto->rawHeaders : null,
                            'body_fetched_at' => ($dto->bodyText !== null || $dto->bodyHtml !== null) ? now() : null,
                            'sent_at' => $dto->sentAt,
                            'category' => $evaluation['category'],
                            'status' => 'to_review',
                            'matched_rule_id' => $evaluation['matched_rule']->id,
                            'is_manually_classified' => false,
                        ]);
                        $totalQualifiedCount++;
                    } elseif ($evaluation['status'] === 'ignored') {
                        // Ignored message: do NOT save to MailMessage
                        $totalIgnoredCount++;
                    } else {
                        // Unmatched / non-recruitment message: do NOT save to MailMessage
                        $totalUnmatchedCount++;
                    }
                }
            } while ($batch->hasMore && $batchIndex < $maxBatches);

            // Success: Update cursors ONLY on successful completion of the entire batch sequence
            if ($finalHistoryId !== null) {
                $syncState->history_id = $finalHistoryId;
            }
            if ($finalImapLastUid !== null) {
                $syncState->imap_last_uid = $finalImapLastUid;
            }
            if ($finalImapUidvalidity !== null) {
                $syncState->imap_uidvalidity = $finalImapUidvalidity;
            }

            $totalMessagesCount = MailMessage::where('mail_account_id', $account->id)->count();
            $relevantMessagesCount = MailMessage::where('mail_account_id', $account->id)->where('status', 'relevant')->count();
            $ignoredMessagesCount = MailMessage::where('mail_account_id', $account->id)->where('status', 'ignored')->count();

            $syncState->sync_status = 'idle';
            $syncState->last_synced_at = now();
            $syncState->total_messages_count = $totalMessagesCount;
            $syncState->relevant_messages_count = $relevantMessagesCount;
            $syncState->ignored_messages_count = $ignoredMessagesCount;
            $syncState->last_batch_count = $totalQualifiedCount;
            $syncState->last_error = null;
            $syncState->last_error_at = null;
            $syncState->save();

            $account->update([
                'last_synced_at' => now(),
                'last_error' => null,
            ]);

            $completedCleanly = true;

            return new MailSyncResult(
                success: true,
                fetchedCount: $totalFetchedCount,
                qualifiedCount: $totalQualifiedCount,
                ignoredCount: $totalIgnoredCount,
                unmatchedCount: $totalUnmatchedCount,
            );

        } catch (Throwable $e) {
            $completedCleanly = true;
            // Failure: Do NOT update sync cursors or last_synced_at!
            $syncState->sync_status = 'failed';
            $syncState->last_error = $e->getMessage();
            $syncState->last_error_at = now();
            $syncState->save();

            $account->update([
                'last_error' => $e->getMessage(),
            ]);

            return new MailSyncResult(
                success: false,
                error: $e->getMessage(),
            );
        }
    } finally {
        $lock->release();
    }
}

    /**
     * Synchronize all accounts that have auto_sync enabled and are due for synchronization.
     * Each mailbox is synchronized in isolation so that one failure never blocks the others.
     *
     * @return array{
     *     total_due: int,
     *     success_count: int,
     *     failure_count: int,
     *     results: array<string, MailSyncResult>
     * }
     */
    public function syncDueAccounts(int $intervalMinutes = 5): array
    {
        $accounts = MailAccount::dueForAutoSync($intervalMinutes)->get();

        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($accounts as $account) {
            try {
                $result = $this->syncAccount($account);
                $results[$account->id] = $result;

                if ($result->success) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            } catch (Throwable $e) {
                $failureCount++;
                Log::error(sprintf('Nieoczekiwany błąd podczas automatycznej synchronizacji konta [%s]: %s', $account->email, $e->getMessage()), [
                    'account_id' => $account->id,
                    'exception' => $e,
                ]);

                $results[$account->id] = new MailSyncResult(
                    success: false,
                    error: $e->getMessage(),
                );
            }
        }

        return [
            'total_due' => $accounts->count(),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'results' => $results,
        ];
    }
}
