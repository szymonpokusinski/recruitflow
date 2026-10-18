<?php

declare(strict_types=1);

namespace App\Services\Mail\Providers;

use App\Models\MailAccount;
use App\Models\Mail\MailSyncState;
use App\Services\Mail\Contracts\ImapClientInterface;
use App\Services\Mail\Contracts\MailSyncProviderInterface;
use App\Services\Mail\DTO\MailMessageMetadataDTO;
use App\Services\Mail\DTO\MailSyncBatchResult;
use App\Services\Mail\Imap\SocketImapClient;
use App\Services\MailAccountService;
use App\Services\MailProviderResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ImapSyncProvider implements MailSyncProviderInterface
{
    public const MAX_SCAN_MESSAGES = 300;

    public function __construct(
        private readonly ?MailAccountService $accountService = null,
        private readonly ?MailProviderResolver $resolver = null,
        private readonly ?ImapClientInterface $client = null,
    ) {}

    public function supports(MailAccount $account): bool
    {
        // IMAP driver handles password accounts or custom IMAP accounts (non-OAuth)
        if ($account->auth_type === 'oauth') {
            return false;
        }

        return $account->auth_type === 'password'
            || (! empty($account->imap_host) && $account->auth_type !== 'oauth');
    }

    public function fetchBatch(MailAccount $account, MailSyncState $state): MailSyncBatchResult
    {
        $connectionConfig = $this->resolveConnectionConfig($account);

        $client = $this->client ?? app(ImapClientInterface::class);

        try {
            $client->connect($connectionConfig);

            // 1. Strictly EXAMINE INBOX in read-only mode (prevents changing \Seen or any other flags)
            $examine = $client->examineFolder('INBOX');
            $exists = $examine['exists'];
            $currentUidValidity = $examine['uidvalidity'];

            $storedUidValidity = $state->imap_uidvalidity;
            $lastUid = $state->imap_last_uid;

            // 2. Initial sync: when cursor or UIDVALIDITY is not yet set
            if ($storedUidValidity === null || $lastUid === null) {
                return $this->performInitialSync($client, $account, $exists, $currentUidValidity);
            }

            // 3. UIDVALIDITY changed: mailbox was rebuilt on server -> controlled re-scan
            if ($currentUidValidity !== $storedUidValidity) {
                Log::warning(
                    sprintf(
                        'Kursor IMAP UIDVALIDITY dla konta [%s] uległ zmianie (z %d na %d). Wykonywanie kontrolowanego ponownego skanowania ostatnich %d wiadomości.',
                        $account->email,
                        $storedUidValidity,
                        $currentUidValidity,
                        self::MAX_SCAN_MESSAGES
                    )
                );

                return $this->performInitialSync($client, $account, $exists, $currentUidValidity, isUidvalidityReset: true);
            }

            // 4. Incremental sync: fetch only new messages with UID > $lastUid
            return $this->performIncrementalSync($client, $account, $lastUid, $currentUidValidity);

        } finally {
            $client->disconnect();
        }
    }

    /**
     * @param array{exists: int, uidvalidity: int, uidnext: int|null} $examine
     */
    protected function performInitialSync(
        ImapClientInterface $client,
        MailAccount $account,
        int $exists,
        int $currentUidValidity,
        bool $isUidvalidityReset = false
    ): MailSyncBatchResult {
        if ($exists <= 0) {
            return new MailSyncBatchResult(
                messages: [],
                historyId: null,
                imapLastUid: null,
                imapUidvalidity: $currentUidValidity,
                hasMore: false,
            );
        }

        // Scan up to 300 newest messages from INBOX
        $startSeq = max(1, $exists - (self::MAX_SCAN_MESSAGES - 1));
        $endSeq = $exists;

        $records = $client->fetchHeadersBySequenceRange($startSeq, $endSeq);

        $dtos = [];
        $uids = [];

        foreach ($records as $record) {
            $dto = $this->parseHeaderRecord($record, $currentUidValidity);
            if ($dto !== null) {
                $dtos[] = $dto;
                $uids[] = (int) $record['uid'];
            }
        }

        $highestUid = ! empty($uids) ? max($uids) : (! empty($records) ? max(array_column($records, 'uid')) : null);

        if ($isUidvalidityReset) {
            Log::info(
                sprintf(
                    'Ukończono kontrolowane ponowne skanowanie IMAP po zmianie UIDVALIDITY dla konta [%s]. Nowy UIDVALIDITY: %d, najwyższy UID: %s.',
                    $account->email,
                    $currentUidValidity,
                    $highestUid ?? 'brak'
                )
            );
        }

        return new MailSyncBatchResult(
            messages: $dtos,
            historyId: null,
            imapLastUid: $highestUid,
            imapUidvalidity: $currentUidValidity,
            hasMore: false,
        );
    }

    protected function performIncrementalSync(
        ImapClientInterface $client,
        MailAccount $account,
        int $lastUid,
        int $currentUidValidity
    ): MailSyncBatchResult {
        $candidateUids = $client->searchUidsSince($lastUid);

        // Filter strictly to UIDs greater than lastUid
        $newUids = array_values(array_filter($candidateUids, fn ($u) => $u > $lastUid));
        sort($newUids, SORT_NUMERIC);

        // No new messages
        if (empty($newUids)) {
            return new MailSyncBatchResult(
                messages: [],
                historyId: null,
                imapLastUid: $lastUid,
                imapUidvalidity: $currentUidValidity,
                hasMore: false,
            );
        }

        $batchUids = array_slice($newUids, 0, self::MAX_SCAN_MESSAGES);
        $hasMore = count($newUids) > self::MAX_SCAN_MESSAGES;

        $records = $client->fetchHeadersByUids($batchUids);

        $dtos = [];
        foreach ($records as $record) {
            $dto = $this->parseHeaderRecord($record, $currentUidValidity);
            if ($dto !== null) {
                $dtos[] = $dto;
            }
        }

        $newHighestUid = max($batchUids);

        return new MailSyncBatchResult(
            messages: $dtos,
            historyId: null,
            imapLastUid: $newHighestUid,
            imapUidvalidity: $currentUidValidity,
            hasMore: $hasMore,
        );
    }

    /**
     * @param array{uid: int, raw_headers: string} $record
     */
    protected function parseHeaderRecord(array $record, ?int $uidvalidity = null): ?MailMessageMetadataDTO
    {
        $uid = (int) $record['uid'];
        $raw = (string) $record['raw_headers'];

        if ($uid <= 0 || trim($raw) === '') {
            return null;
        }

        // Unfold multiline headers (RFC 2822 section 2.2.3)
        $unfolded = (string) preg_replace("/\r?\n[ \t]+/", ' ', $raw);
        $lines = preg_split('/\r?\n/', $unfolded, -1, PREG_SPLIT_NO_EMPTY);

        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('/^([a-zA-Z0-9_-]+)\s*:\s*(.*)$/', $line, $matches)) {
                $name = strtolower(trim($matches[1]));
                $value = trim($matches[2]);
                $headers[$name] = $value;
            }
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
                $sentAt = now();
            }
        }

        // 4. Message-ID & Reply-To
        $messageId = ! empty($headers['message-id']) ? trim($headers['message-id']) : null;
        $replyTo = $headers['reply-to'] ?? null;
        if ($replyTo !== null) {
            [, $cleanReplyTo] = $this->parseFromHeader($replyTo);
            $replyTo = $cleanReplyTo !== '' ? $cleanReplyTo : $replyTo;
        }

        // 5. Raw headers (In-Reply-To, References)
        $rawHeaders = [];
        if (! empty($headers['in-reply-to'])) {
            $rawHeaders['In-Reply-To'] = $headers['in-reply-to'];
        }
        if (! empty($headers['references'])) {
            $rawHeaders['References'] = $headers['references'];
        }

        $providerMessageId = $uidvalidity !== null ? "{$uidvalidity}:{$uid}" : (string) $uid;

        return new MailMessageMetadataDTO(
            providerMessageId: $providerMessageId,
            senderEmail: $senderEmail,
            subject: $subject,
            sentAt: $sentAt,
            threadId: null,
            messageId: $messageId,
            senderName: $senderName,
            senderDomain: null, // Constructor extracts domain from senderEmail
            replyTo: $replyTo,
            rawHeaders: $rawHeaders,
        );
    }

    /**
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

    /**
     * @return array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     username: string,
     *     password: string,
     *     timeout: int
     * }
     */
    protected function resolveConnectionConfig(MailAccount $account): array
    {
        $providerKey = strtolower((string) ($account->provider ?: ''));
        if ($providerKey === '' || $providerKey === 'generic') {
            $resolver = $this->resolver ?? app(MailProviderResolver::class);
            $resolved = $resolver->resolveFromEmail($account->email);
            $providerKey = $resolved['provider'];
        }

        $providerConfig = config("mail_providers.providers.{$providerKey}", []);
        $imapConfig = $providerConfig['imap'] ?? [];

        $host = (string) ($account->imap_host ?: ($imapConfig['host'] ?? ''));
        $port = (int) ($account->imap_port ?: ($imapConfig['port'] ?? 993));
        $encryption = (string) ($account->imap_encryption ?: ($imapConfig['encryption'] ?? 'ssl'));
        $username = (string) ($account->imap_username ?: $account->email);

        $accountService = $this->accountService ?? app(MailAccountService::class);
        $password = $accountService->getPassword($account);

        if (! is_string($password) || trim($password) === '') {
            throw new RuntimeException("Brak hasła dla konta pocztowego [{$account->email}]. Uzupełnij hasło, aby zsynchronizować pocztę.");
        }

        if ($host === '') {
            throw new RuntimeException("Brak skonfigurowanego hosta serwera IMAP dla konta [{$account->email}].");
        }

        return [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
            'timeout' => 15,
        ];
    }

    public function fetchMessageBody(MailAccount $account, string $providerMessageId): array
    {
        $connectionConfig = $this->resolveConnectionConfig($account);
        $client = $this->client ?? app(ImapClientInterface::class);

        try {
            $client->connect($connectionConfig);
            $client->examineFolder('INBOX');

            $uid = str_contains($providerMessageId, ':')
                ? (int) explode(':', $providerMessageId, 2)[1]
                : (int) $providerMessageId;

            $headersList = $client->fetchHeadersByUids([$uid]);
            $rawHeaders = $headersList[0]['raw_headers'] ?? '';

            $to = null;
            $cc = null;
            if ($rawHeaders !== '') {
                if (preg_match('/^to:\s*(.*?)$/mi', $rawHeaders, $m)) {
                    $to = trim($m[1]);
                }
                if (preg_match('/^cc:\s*(.*?)$/mi', $rawHeaders, $m)) {
                    $cc = trim($m[1]);
                }
            }

            return [
                'success' => true,
                'snippet' => null,
                'body_text' => null,
                'body_html' => null,
                'to_email' => $to,
                'cc_email' => $cc,
                'has_attachments' => false,
                'attachments' => [],
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Błąd IMAP podczas pobierania wiadomości: ' . $e->getMessage(),
            ];
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {}
        }
    }
}
