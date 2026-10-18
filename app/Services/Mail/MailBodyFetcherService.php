<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Mail\MailMessage;
use App\Services\Mail\Providers\MailSyncProviderResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

class MailBodyFetcherService
{
    public function __construct(
        private readonly MailSyncProviderResolver $providerResolver,
    ) {}

    /**
     * Fetch complete message content (text, html, snippet, attachments) and persist to database.
     *
     * @return array{
     *     success: bool,
     *     requires_reauth?: bool,
     *     scope_needed?: string,
     *     error?: string,
     *     mail_message: MailMessage
     * }
     */
    public function fetchAndSaveBody(MailMessage $message): array
    {
        // If already fetched and has body, return immediately
        if (! empty($message->body_text) || ! empty($message->body_html)) {
            return [
                'success' => true,
                'mail_message' => $message->fresh(['mailAccount', 'matchedRule']),
            ];
        }

        $account = $message->mailAccount;
        if (! $account) {
            return [
                'success' => false,
                'error' => 'Konto pocztowe powiązane z tą wiadomością nie istnieje.',
                'mail_message' => $message,
            ];
        }

        try {
            $provider = $this->providerResolver->resolve($account);

            $result = $provider->fetchMessageBody($account, $message->provider_message_id);

            if (! empty($result['snippet']) && empty($message->snippet)) {
                $message->snippet = $result['snippet'];
            }
            if (! empty($result['to_email']) && empty($message->to_email)) {
                $message->to_email = $result['to_email'];
            }
            if (! empty($result['cc_email']) && empty($message->cc_email)) {
                $message->cc_email = $result['cc_email'];
            }

            if (! empty($result['success'])) {
                $message->body_text = $result['body_text'] ?? null;
                $message->body_html = $result['body_html'] ?? null;
                $message->has_attachments = (bool) ($result['has_attachments'] ?? false);
                $message->attachments = $result['attachments'] ?? [];
                $message->body_fetched_at = now();
                $message->save();

                return [
                    'success' => true,
                    'mail_message' => $message->fresh(['mailAccount', 'matchedRule']),
                ];
            }

            // Save any snippet or recipients retrieved even on partial error
            $message->save();

            return [
                'success' => false,
                'requires_reauth' => $result['requires_reauth'] ?? false,
                'scope_needed' => $result['scope_needed'] ?? null,
                'error' => $result['error'] ?? 'Nie udało się pobrać treści wiadomości.',
                'mail_message' => $message->fresh(['mailAccount', 'matchedRule']),
            ];

        } catch (Throwable $e) {
            Log::error("Błąd podczas pobierania treści wiadomości [{$message->id}]: " . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'mail_message' => $message->fresh(['mailAccount', 'matchedRule']),
            ];
        }
    }
}
