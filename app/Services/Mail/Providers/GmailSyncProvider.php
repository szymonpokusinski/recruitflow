<?php

declare(strict_types=1);

namespace App\Services\Mail\Providers;

use App\Integrations\Google\GoogleOAuthService;
use App\Models\Integrations\IntegrationAccount;
use App\Models\MailAccount;
use App\Models\Mail\MailSyncState;
use App\Services\Mail\Contracts\MailSyncProviderInterface;
use App\Services\Mail\DTO\MailMessageMetadataDTO;
use App\Services\Mail\DTO\MailSyncBatchResult;
use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GmailSyncProvider implements MailSyncProviderInterface
{
    public const MAX_SCAN_MESSAGES = 300;
    public const GMAIL_API_BASE = 'https://gmail.googleapis.com/gmail/v1';

    public function __construct(
        private readonly ?GoogleOAuthService $oauthService = null,
    ) {}

    public function supports(MailAccount $account): bool
    {
        $provider = strtolower((string) ($account->provider ?? ''));
        $oauthProvider = strtolower((string) ($account->oauth_provider ?? ''));

        // Gmail sync requires OAuth2 and specifically Google/Gmail provider
        if ($account->auth_type === 'oauth') {
            return $oauthProvider === 'google' || $provider === 'gmail';
        }

        return false;
    }

    public function fetchBatch(MailAccount $account, MailSyncState $state): MailSyncBatchResult
    {
        // 1. Resolve active OAuth access token (or refresh from Keychain)
        $token = $this->resolveAccessToken($account);

        $historyId = $state->history_id ? trim((string) $state->history_id) : '';

        // If history_id exists, perform incremental sync via users.history.list
        if ($historyId !== '') {
            return $this->fetchIncrementalBatch($token, $account, $state, $historyId);
        }

        // Otherwise perform initial sync (up to 300 messages from INBOX)
        return $this->fetchInitialBatch($token, $account);
    }

    /**
     * Incremental sync: fetch changes since startHistoryId using users.history.list.
     */
    protected function fetchIncrementalBatch(
        string &$token,
        MailAccount $account,
        MailSyncState $state,
        string $startHistoryId
    ): MailSyncBatchResult {
        $candidateIds = [];
        $pageToken = null;
        $latestHistoryId = $startHistoryId;

        do {
            $queryParams = [
                'startHistoryId=' . urlencode($startHistoryId),
                'labelId=INBOX',
                'historyTypes=messageAdded',
                'historyTypes=labelAdded',
                'maxResults=100',
            ];
            if ($pageToken !== null) {
                $queryParams[] = 'pageToken=' . urlencode($pageToken);
            }

            $url = self::GMAIL_API_BASE . '/users/me/history?' . implode('&', $queryParams);
            $response = Http::withToken($token)->get($url);

            if ($response->status() === 401) {
                $token = $this->refreshToken($account);
                $response = Http::withToken($token)->get($url);
            }

            // HTTP 404 indicates historyId expired (Gmail stores history for ~30 days).
            // Fall back to controlled re-sync of last 300 inbox messages without deleting existing data.
            if ($response->status() === 404) {
                Log::warning(
                    sprintf(
                        'Kursor Gmail historyId [%s] dla konta [%s] wygasł (HTTP 404). Wykonywanie kontrolowanej ponownej synchronizacji ostatnich 300 wiadomości.',
                        $startHistoryId,
                        $account->email
                    )
                );

                return $this->fetchInitialBatch($token, $account, isHistoryFallback: true);
            }

            if (! $response->successful()) {
                $this->handleApiError('Pobieranie historii zmian Gmail', $response);
            }

            $data = $response->json() ?? [];
            if (! empty($data['historyId'])) {
                $latestHistoryId = (string) $data['historyId'];
            }

            $historyRecords = $data['history'] ?? [];
            foreach ($historyRecords as $record) {
                // 1. messagesAdded
                foreach ($record['messagesAdded'] ?? [] as $added) {
                    $id = $added['message']['id'] ?? null;
                    if (! empty($id)) {
                        $candidateIds[$id] = true;
                    }
                }

                // 2. labelsAdded with INBOX
                foreach ($record['labelsAdded'] ?? [] as $labelAdded) {
                    $labelIdsAdded = $labelAdded['labelIds'] ?? [];
                    $msg = $labelAdded['message'] ?? [];
                    $msgLabels = $msg['labelIds'] ?? [];

                    if (in_array('INBOX', $labelIdsAdded, true) || in_array('INBOX', $msgLabels, true)) {
                        $id = $msg['id'] ?? null;
                        if (! empty($id)) {
                            $candidateIds[$id] = true;
                        }
                    }
                }
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        // If no candidate messages changed, return empty batch with the latest historyId
        if (empty($candidateIds)) {
            return new MailSyncBatchResult(
                messages: [],
                historyId: $latestHistoryId,
                imapLastUid: null,
                imapUidvalidity: null,
                hasMore: false,
            );
        }

        // Fetch metadata for deduplicated candidate IDs in batch
        $candidateIdList = array_map('strval', array_keys($candidateIds));
        $dtos = $this->fetchMessagesMetadataBatch($token, $candidateIdList, $account);

        return new MailSyncBatchResult(
            messages: $dtos,
            historyId: $latestHistoryId,
            imapLastUid: null,
            imapUidvalidity: null,
            hasMore: false,
        );
    }

    /**
     * Initial sync (or fallback on expired historyId): scan up to 300 INBOX messages.
     */
    protected function fetchInitialBatch(
        string &$token,
        MailAccount $account,
        bool $isHistoryFallback = false
    ): MailSyncBatchResult {
        if ($isHistoryFallback) {
            Log::info(
                sprintf(
                    'Ukończono awaryjne odtworzenie historii dla konta [%s]: przeskanowano ostatnie wiadomości skrzynki odbiorczej bez modyfikacji istniejących decyzji.',
                    $account->email
                )
            );
        }

        // 1. Fetch baseline historyId from profile BEFORE scanning
        $profile = $this->fetchProfile($token, $account);
        $baselineHistoryId = (string) ($profile['historyId'] ?? '');

        // 2. Collect up to 300 message references from INBOX
        // Strictly: do NOT use parameter 'q', because 'q' is forbidden with gmail.metadata scope
        $messageRefs = $this->listInboxMessages($token, $account, self::MAX_SCAN_MESSAGES);

        // 3. Fetch metadata headers in concurrent batches
        $messageIds = [];
        foreach ($messageRefs as $ref) {
            $msgId = (string) ($ref['id'] ?? '');
            if ($msgId !== '') {
                $messageIds[] = $msgId;
            }
        }
        $dtos = $this->fetchMessagesMetadataBatch($token, $messageIds, $account);

        return new MailSyncBatchResult(
            messages: $dtos,
            historyId: $baselineHistoryId !== '' ? $baselineHistoryId : null,
            imapLastUid: null,
            imapUidvalidity: null,
            hasMore: false,
        );
    }

    protected function resolveAccessToken(MailAccount $account): string
    {
        $credentialRef = $account->credential_ref;
        if (empty($credentialRef)) {
            throw new RuntimeException("Konto [{$account->email}] nie posiada powiązanych poświadczeń OAuth.");
        }

        $integrationAccount = IntegrationAccount::where('credential_ref', $credentialRef)->first();
        if (! $integrationAccount || $integrationAccount->status !== 'connected') {
            throw new RuntimeException("Autoryzacja Google dla konta [{$account->email}] wygasła lub została usunięta. Połącz konto Gmail ponownie.");
        }

        $scopes = $integrationAccount->scopes ?? [];
        $hasScope = in_array(GoogleOAuthService::GMAIL_METADATA_SCOPE, $scopes, true)
            || in_array(GoogleOAuthService::GMAIL_READONLY_SCOPE, $scopes, true)
            || in_array(GoogleOAuthService::GMAIL_IMAP_SCOPE, $scopes, true);

        if (! $hasScope) {
            throw new RuntimeException("Konto Google nie posiada wymaganego uprawnienia do Gmaila (brak zakresu gmail.metadata). Połącz konto ponownie.");
        }

        $oauth = $this->oauthService ?? app(GoogleOAuthService::class);

        return $oauth->accessToken($integrationAccount);
    }

    protected function refreshToken(MailAccount $account): string
    {
        $credentialRef = $account->credential_ref;
        $integrationAccount = IntegrationAccount::where('credential_ref', $credentialRef)->first();
        if (! $integrationAccount) {
            throw new RuntimeException("Brak powiązanego konta integracji dla [{$account->email}].");
        }

        $oauth = $this->oauthService ?? app(GoogleOAuthService::class);

        return $oauth->accessToken($integrationAccount, forceRefresh: true);
    }

    protected function fetchProfile(string &$token, MailAccount $account): array
    {
        $url = self::GMAIL_API_BASE . '/users/me/profile';
        $response = Http::withToken($token)->get($url);

        if ($response->status() === 401) {
            $token = $this->refreshToken($account);
            $response = Http::withToken($token)->get($url);
        }

        if (! $response->successful()) {
            $this->handleApiError('Pobieranie profilu Gmail', $response);
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<array{id: string, threadId: string}>
     */
    protected function listInboxMessages(string &$token, MailAccount $account, int $maxToScan = 300): array
    {
        $collected = [];
        $pageToken = null;

        do {
            $remaining = $maxToScan - count($collected);
            $pageSize = min(100, $remaining);

            $params = [
                'labelIds' => 'INBOX',
                'maxResults' => $pageSize,
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $url = self::GMAIL_API_BASE . '/users/me/messages';
            $response = Http::withToken($token)->get($url, $params);

            if ($response->status() === 401) {
                $token = $this->refreshToken($account);
                $response = Http::withToken($token)->get($url, $params);
            }

            if (! $response->successful()) {
                $this->handleApiError('Pobieranie listy wiadomości Gmail', $response);
            }

            $data = $response->json() ?? [];
            $messages = $data['messages'] ?? [];
            foreach ($messages as $msg) {
                $collected[] = $msg;
                if (count($collected) >= $maxToScan) {
                    break;
                }
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken !== null && count($collected) < $maxToScan);

        return $collected;
    }

    /**
     * Fetch message metadata for an array of message IDs using concurrent requests (chunks of 25).
     *
     * @param array<string> $messageIds
     * @return array<MailMessageMetadataDTO>
     */
    public function fetchMessagesMetadataBatch(string &$token, array $messageIds, MailAccount $account): array
    {
        $messageIds = array_values(array_filter(array_unique($messageIds), fn ($id) => trim((string) $id) !== ''));
        if (empty($messageIds)) {
            return [];
        }

        $headersToRequest = ['From', 'Subject', 'Reply-To', 'To', 'Cc', 'Date', 'Message-ID', 'In-Reply-To', 'References'];
        $queryString = 'format=METADATA&' . implode('&', array_map(fn ($h) => 'metadataHeaders=' . urlencode($h), $headersToRequest));

        $chunkSize = 25;
        $chunks = array_chunk($messageIds, $chunkSize);
        $dtos = [];

        foreach ($chunks as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk, $token, $queryString) {
                return array_map(function ($msgId) use ($pool, $token, $queryString) {
                    $url = self::GMAIL_API_BASE . "/users/me/messages/{$msgId}?{$queryString}";
                    return $pool->as((string) $msgId)->withToken($token)->timeout(15)->get($url);
                }, $chunk);
            });

            // Check if any response returned 401 (token expired)
            $needsTokenRefresh = false;
            foreach ($chunk as $msgId) {
                $resp = $responses[$msgId] ?? null;
                if ($resp instanceof Response && $resp->status() === 401) {
                    $needsTokenRefresh = true;
                    break;
                }
            }

            if ($needsTokenRefresh) {
                $token = $this->refreshToken($account);
                $responses = Http::pool(function (Pool $pool) use ($chunk, $token, $queryString) {
                    return array_map(function ($msgId) use ($pool, $token, $queryString) {
                        $url = self::GMAIL_API_BASE . "/users/me/messages/{$msgId}?{$queryString}";
                        return $pool->as((string) $msgId)->withToken($token)->timeout(15)->get($url);
                    }, $chunk);
                });
            }

            foreach ($chunk as $msgId) {
                $resp = $responses[$msgId] ?? null;
                if (! ($resp instanceof Response)) {
                    if ($resp instanceof Throwable) {
                        Log::warning("Błąd sieci podczas pobierania metadanych wiadomości [{$msgId}]: " . $resp->getMessage());
                    }
                    continue;
                }

                // If message was deleted between listing and fetch, skip it
                if ($resp->status() === 404) {
                    continue;
                }

                if (! $resp->successful()) {
                    $this->handleApiError("Pobieranie metadanych wiadomości [{$msgId}]", $resp);
                }

                $data = $resp->json() ?? [];
                $dto = $this->parseMessageData($data);
                if ($dto !== null) {
                    $dtos[] = $dto;
                }
            }
        }

        return $dtos;
    }

    public function fetchMessageMetadata(string &$token, string $messageId, MailAccount $account): ?MailMessageMetadataDTO
    {
        $batch = $this->fetchMessagesMetadataBatch($token, [$messageId], $account);

        return $batch[0] ?? null;
    }

    public function parseMessageData(array $data): ?MailMessageMetadataDTO
    {
        if (empty($data['id'])) {
            return null;
        }

        // Verify current INBOX membership: if message does not currently have INBOX label, skip it
        $labelIds = $data['labelIds'] ?? [];
        if (! in_array('INBOX', $labelIds, true)) {
            return null;
        }

        $headers = [];
        foreach ($data['payload']['headers'] ?? [] as $header) {
            $name = strtolower(trim((string) ($header['name'] ?? '')));
            $headers[$name] = (string) ($header['value'] ?? '');
        }

        // 1. Sender (From)
        $fromRaw = $headers['from'] ?? '';
        [$senderName, $senderEmail] = $this->parseFromHeader($fromRaw);
        if ($senderEmail === '') {
            return null;
        }

        // 2. Subject
        $subjectRaw = $headers['subject'] ?? '(Brak tematu)';
        $subject = $this->decodeHeader($subjectRaw);

        // 3. Date
        $dateRaw = $headers['date'] ?? null;
        $sentAt = now();
        if (! empty($dateRaw)) {
            try {
                $sentAt = Carbon::parse($dateRaw);
            } catch (Throwable) {
                if (! empty($data['internalDate'])) {
                    $sentAt = Carbon::createFromTimestampMs((int) $data['internalDate']);
                }
            }
        } elseif (! empty($data['internalDate'])) {
            $sentAt = Carbon::createFromTimestampMs((int) $data['internalDate']);
        }

        // 4. Message-ID & Reply-To
        $rfcMessageId = $headers['message-id'] ?? null;
        $replyTo = $headers['reply-to'] ?? null;
        if ($replyTo !== null) {
            [, $cleanReplyTo] = $this->parseFromHeader($replyTo);
            $replyTo = $cleanReplyTo !== '' ? $cleanReplyTo : $replyTo;
        }

        // 5. Snippet
        $snippetRaw = (string) ($data['snippet'] ?? '');
        $snippet = $snippetRaw !== '' ? html_entity_decode($snippetRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;

        // 6. To & Cc
        $to = $headers['to'] ?? null;
        $cc = $headers['cc'] ?? null;

        // 7. Raw headers (In-Reply-To, References)
        $rawHeaders = [];
        if (! empty($headers['in-reply-to'])) {
            $rawHeaders['In-Reply-To'] = $headers['in-reply-to'];
        }
        if (! empty($headers['references'])) {
            $rawHeaders['References'] = $headers['references'];
        }

        return new MailMessageMetadataDTO(
            providerMessageId: (string) $data['id'],
            senderEmail: $senderEmail,
            subject: $subject,
            sentAt: $sentAt,
            threadId: ! empty($data['threadId']) ? (string) $data['threadId'] : null,
            messageId: $rfcMessageId,
            senderName: $senderName,
            senderDomain: null,
            replyTo: $replyTo,
            rawHeaders: $rawHeaders,
            snippet: $snippet,
            toEmail: $to,
            ccEmail: $cc,
        );
    }

    /**
     * Fetch complete message content (text, html, snippet, attachments) via format=FULL.
     *
     * @return array{
     *     success: bool,
     *     requires_reauth?: bool,
     *     scope_needed?: string,
     *     error?: string,
     *     snippet?: ?string,
     *     body_text?: ?string,
     *     body_html?: ?string,
     *     to_email?: ?string,
     *     cc_email?: ?string,
     *     has_attachments?: bool,
     *     attachments?: array
     * }
     */
    public function fetchMessageBody(MailAccount $account, string $providerMessageId): array
    {
        $token = $this->resolveAccessToken($account);

        $url = self::GMAIL_API_BASE . "/users/me/messages/{$providerMessageId}?format=FULL";
        $response = Http::withToken($token)->get($url);

        if ($response->status() === 401) {
            $token = $this->refreshToken($account);
            $response = Http::withToken($token)->get($url);
        }

        // 403 Forbidden: Missing scope (e.g. only gmail.metadata was granted)
        if ($response->status() === 403) {
            $metaUrl = self::GMAIL_API_BASE . "/users/me/messages/{$providerMessageId}?format=METADATA&metadataHeaders=From&metadataHeaders=To&metadataHeaders=Cc&metadataHeaders=Subject&metadataHeaders=Date";
            $metaResp = Http::withToken($token)->get($metaUrl);
            $metaData = $metaResp->successful() ? ($metaResp->json() ?? []) : [];
            $snippet = ! empty($metaData['snippet']) ? html_entity_decode((string) $metaData['snippet'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;

            return [
                'success' => false,
                'requires_reauth' => true,
                'scope_needed' => GoogleOAuthService::GMAIL_READONLY_SCOPE,
                'error' => 'Konto Google zostało połączone z uprawnieniem tylko do metadanych (gmail.metadata). Aby pobrać pełną treść wiadomości HTML i załączniki, połącz konto ponownie z uprawnieniem do odczytu poczty (gmail.readonly).',
                'snippet' => $snippet,
            ];
        }

        if ($response->status() === 404) {
            return [
                'success' => false,
                'error' => 'Wiadomość nie istnieje w skrzynce Gmail (została usunięta).',
            ];
        }

        if (! $response->successful()) {
            $this->handleApiError("Pobieranie pełnej treści wiadomości [{$providerMessageId}]", $response);
        }

        $data = $response->json() ?? [];
        $payload = $data['payload'] ?? [];
        $snippet = ! empty($data['snippet']) ? html_entity_decode((string) $data['snippet'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;

        $parsed = $this->extractBodyAndAttachments($payload);

        $headers = [];
        foreach ($payload['headers'] ?? [] as $header) {
            $name = strtolower(trim((string) ($header['name'] ?? '')));
            $headers[$name] = (string) ($header['value'] ?? '');
        }

        return [
            'success' => true,
            'snippet' => $snippet,
            'body_text' => $parsed['text'],
            'body_html' => $parsed['html'],
            'to_email' => $headers['to'] ?? null,
            'cc_email' => $headers['cc'] ?? null,
            'has_attachments' => count($parsed['attachments']) > 0,
            'attachments' => $parsed['attachments'],
        ];
    }

    protected function extractBodyAndAttachments(array $payload): array
    {
        $text = null;
        $html = null;
        $attachments = [];

        $walk = function (array $part) use (&$walk, &$text, &$html, &$attachments) {
            $mime = strtolower((string) ($part['mimeType'] ?? ''));
            $filename = trim((string) ($part['filename'] ?? ''));
            $body = $part['body'] ?? [];
            $bodyData = $body['data'] ?? null;
            $attachmentId = $body['attachmentId'] ?? null;
            $size = (int) ($body['size'] ?? 0);

            if ($filename !== '' || $attachmentId !== null) {
                $attachments[] = [
                    'name' => $filename !== '' ? $filename : 'załącznik',
                    'size' => $size,
                    'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
                    'attachment_id' => $attachmentId,
                ];
            } elseif ($mime === 'text/plain' && $bodyData !== null) {
                $decoded = base64_decode(strtr((string) $bodyData, '-_', '+/'));
                if ($decoded !== false) {
                    $text = $text !== null ? ($text . "\n\n" . $decoded) : $decoded;
                }
            } elseif ($mime === 'text/html' && $bodyData !== null) {
                $decoded = base64_decode(strtr((string) $bodyData, '-_', '+/'));
                if ($decoded !== false) {
                    $html = $html !== null ? ($html . "<hr>" . $decoded) : $decoded;
                }
            }

            foreach ($part['parts'] ?? [] as $subPart) {
                if (is_array($subPart)) {
                    $walk($subPart);
                }
            }
        };

        $walk($payload);

        return [
            'text' => $text,
            'html' => $html,
            'attachments' => $attachments,
        ];
    }

    /**
     * Parse RFC From header into [senderName, senderEmail].
     *
     * @return array{0: ?string, 1: string}
     */
    protected function parseFromHeader(string $from): array
    {
        $from = trim($from);
        if ($from === '') {
            return [null, ''];
        }

        if (preg_match('/^(.*?)\s*<([^>]+)>$/u', $from, $matches)) {
            $name = trim($matches[1], " \t\n\r\0\x0B\"'");
            $email = strtolower(trim($matches[2]));
            $decodedName = $name !== '' ? $this->decodeHeader($name) : null;

            return [$decodedName, $email];
        }

        if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return [null, strtolower($from)];
        }

        if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/u', $from, $matches)) {
            return [null, strtolower($matches[1])];
        }

        return [null, strtolower($from)];
    }

    protected function decodeHeader(string $value): string
    {
        if (str_contains($value, '=?')) {
            $decoded = mb_decode_mimeheader($value);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $value;
    }

    protected function handleApiError(string $action, Response $response): void
    {
        $body = $response->body();
        if (str_contains($body, 'SERVICE_DISABLED') || str_contains($body, 'service is currently disabled')) {
            $link = 'https://console.developers.google.com/apis/api/gmail.googleapis.com/overview';
            if (preg_match('/project[=:\s]+([0-9]+)/i', $body, $matches)) {
                $link .= '?project=' . $matches[1];
            }
            throw new RuntimeException(
                "Usługa Gmail API nie jest włączona w projekcie Google Cloud. Włącz ją w konsoli: {$link}"
            );
        }

        $status = $response->status();
        $errorMessage = $response->json('error.message') ?? $body;
        throw new RuntimeException("{$action} nie powiodło się (HTTP {$status}): {$errorMessage}");
    }
}
