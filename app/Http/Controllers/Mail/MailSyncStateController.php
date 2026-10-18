<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailAccount;
use App\Models\Mail\MailMessage;
use App\Models\Mail\MailSyncState;
use Illuminate\Http\JsonResponse;

class MailSyncStateController extends Controller
{
    public function index(): JsonResponse
    {
        $accounts = MailAccount::query()
            ->orderBy('created_at')
            ->get();

        $items = $accounts->map(function (MailAccount $account) {
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

            // Dynamically refresh counts from mail_messages
            $totalCount = MailMessage::where('mail_account_id', $account->id)->count();
            $relevantCount = MailMessage::where('mail_account_id', $account->id)->where('status', 'relevant')->count();
            $ignoredCount = MailMessage::where('mail_account_id', $account->id)->where('status', 'ignored')->count();
            $toReviewCount = MailMessage::where('mail_account_id', $account->id)->where('status', 'to_review')->count();

            // Auto-recover stale 'syncing' states if process was terminated
            if ($syncState->sync_status === 'syncing' && $syncState->updated_at && $syncState->updated_at->lt(now()->subSeconds(90))) {
                $syncState->update([
                    'sync_status' => 'idle',
                ]);
            }

            if ($syncState->total_messages_count !== $totalCount ||
                $syncState->relevant_messages_count !== $relevantCount ||
                $syncState->ignored_messages_count !== $ignoredCount) {
                $syncState->update([
                    'total_messages_count' => $totalCount,
                    'relevant_messages_count' => $relevantCount,
                    'ignored_messages_count' => $ignoredCount,
                ]);
            }

            return [
                'account' => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'email' => $account->email,
                    'provider' => $account->provider,
                    'auth_type' => $account->auth_type,
                    'status' => $account->status,
                    'auto_sync' => (bool) $account->auto_sync,
                    'last_tested_at' => $account->last_tested_at,
                    'last_test_result' => $account->last_test_result,
                ],
                'sync_state' => [
                    'id' => $syncState->id,
                    'sync_status' => $syncState->sync_status,
                    'last_synced_at' => $syncState->last_synced_at,
                    'total_messages_count' => $totalCount,
                    'relevant_messages_count' => $relevantCount,
                    'ignored_messages_count' => $ignoredCount,
                    'to_review_messages_count' => $toReviewCount,
                    'last_batch_count' => $syncState->last_batch_count,
                    'history_id' => $syncState->history_id,
                    'imap_last_uid' => $syncState->imap_last_uid,
                    'imap_uidvalidity' => $syncState->imap_uidvalidity,
                    'last_error' => $syncState->last_error,
                    'last_error_at' => $syncState->last_error_at,
                ],
                'schedule_interval' => 'every_5_minutes',
                'status_message' => $syncState->last_synced_at === null
                    ? 'Synchronizacja nie została jeszcze uruchomiona'
                    : 'Konto aktywne',
            ];
        });

        return response()->json([
            'accounts' => $items,
            'summary' => [
                'total_accounts' => $accounts->count(),
                'active_accounts' => $accounts->filter(fn (MailAccount $a) => in_array($a->status, ['active', 'connected'], true))->count(),
                'total_messages' => MailMessage::count(),
                'relevant_messages' => MailMessage::where('status', 'relevant')->count(),
            ],
        ]);
    }
}
