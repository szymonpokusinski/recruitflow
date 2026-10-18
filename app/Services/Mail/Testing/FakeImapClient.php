<?php

declare(strict_types=1);

namespace App\Services\Mail\Testing;

use App\Services\Mail\Contracts\ImapClientInterface;
use Throwable;

class FakeImapClient implements ImapClientInterface
{
    /** @var array<array<string, mixed>> */
    public array $connectedConfigs = [];

    /** @var array<string> */
    public array $examinedFolders = [];

    /** @var array<int> */
    public array $searchedSince = [];

    /** @var array<array{start: int, end: int}> */
    public array $fetchedSequenceRanges = [];

    /** @var array<array<int>> */
    public array $fetchedUids = [];

    public bool $disconnected = false;

    /** @var array{exists: int, uidvalidity: int, uidnext: int|null} */
    public array $examineResult = [
        'exists' => 0,
        'uidvalidity' => 1000,
        'uidnext' => null,
    ];

    /** @var array<int> */
    public array $searchUidsResult = [];

    /** @var array<array{uid: int, raw_headers: string}> */
    public array $sequenceHeadersResult = [];

    /** @var array<array{uid: int, raw_headers: string}> */
    public array $uidHeadersResult = [];

    public ?Throwable $throwOnConnect = null;

    public ?Throwable $throwOnExamine = null;

    public ?Throwable $throwOnSearch = null;

    public ?Throwable $throwOnFetch = null;

    public function connect(array $config): void
    {
        if ($this->throwOnConnect !== null) {
            throw $this->throwOnConnect;
        }

        $this->connectedConfigs[] = $config;
        $this->disconnected = false;
    }

    public function examineFolder(string $folder = 'INBOX'): array
    {
        if ($this->throwOnExamine !== null) {
            throw $this->throwOnExamine;
        }

        $this->examinedFolders[] = $folder;

        return $this->examineResult;
    }

    public function searchUidsSince(int $sinceUid): array
    {
        if ($this->throwOnSearch !== null) {
            throw $this->throwOnSearch;
        }

        $this->searchedSince[] = $sinceUid;

        return $this->searchUidsResult;
    }

    public function fetchHeadersBySequenceRange(int $startSeq, int $endSeq): array
    {
        if ($this->throwOnFetch !== null) {
            throw $this->throwOnFetch;
        }

        $this->fetchedSequenceRanges[] = ['start' => $startSeq, 'end' => $endSeq];

        return $this->sequenceHeadersResult;
    }

    public function fetchHeadersByUids(array $uids): array
    {
        if ($this->throwOnFetch !== null) {
            throw $this->throwOnFetch;
        }

        $this->fetchedUids[] = $uids;

        return $this->uidHeadersResult;
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }
}
