<?php

declare(strict_types=1);

namespace App\Services\Mail\Contracts;

interface ImapClientInterface
{
    /**
     * Connect and authenticate to IMAP server using LOGIN command.
     *
     * @param array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     username: string,
     *     password: string,
     *     timeout?: int
     * } $config
     */
    public function connect(array $config): void;

    /**
     * Examine mailbox in strictly READ-ONLY mode (EXAMINE command).
     *
     * @return array{exists: int, uidvalidity: int, uidnext: int|null}
     */
    public function examineFolder(string $folder = 'INBOX'): array;

    /**
     * Search message UIDs greater than a given UID.
     *
     * @return array<int>
     */
    public function searchUidsSince(int $sinceUid): array;

    /**
     * Fetch RFC 822 headers for message sequence range (used in initial sync).
     * Strictly uses BODY.PEEK to prevent changing \Seen flag.
     *
     * @return array<array{uid: int, raw_headers: string}>
     */
    public function fetchHeadersBySequenceRange(int $startSeq, int $endSeq): array;

    /**
     * Fetch RFC 822 headers for specific message UIDs (used in incremental sync).
     * Strictly uses BODY.PEEK to prevent changing \Seen flag.
     *
     * @param array<int> $uids
     * @return array<array{uid: int, raw_headers: string}>
     */
    public function fetchHeadersByUids(array $uids): array;

    /**
     * Gracefully logout and close the connection.
     */
    public function disconnect(): void;
}
