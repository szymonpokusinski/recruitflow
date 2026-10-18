<?php

declare(strict_types=1);

namespace App\Services\Mail\Providers;

use App\Models\MailAccount;
use App\Models\Mail\MailSyncState;
use App\Services\Mail\Contracts\MailSyncProviderInterface;
use App\Services\Mail\DTO\MailMessageMetadataDTO;
use App\Services\Mail\DTO\MailSyncBatchResult;
use Throwable;

class FakeMailSyncProvider implements MailSyncProviderInterface
{
    /** @var array<MailSyncBatchResult> */
    private array $queuedBatches = [];
    private ?Throwable $throwException = null;
    private int $callsCount = 0;

    public function queueBatch(MailSyncBatchResult $batch): self
    {
        $this->queuedBatches[] = $batch;

        return $this;
    }

    /**
     * @param array<MailMessageMetadataDTO> $messages
     */
    public function setMessages(
        array $messages,
        ?string $historyId = null,
        ?int $imapLastUid = null,
        ?int $imapUidvalidity = null,
        bool $hasMore = false
    ): self {
        $this->queuedBatches = [
            new MailSyncBatchResult(
                messages: $messages,
                historyId: $historyId,
                imapLastUid: $imapLastUid,
                imapUidvalidity: $imapUidvalidity,
                hasMore: $hasMore
            ),
        ];

        return $this;
    }

    public function setThrowException(Throwable $e): self
    {
        $this->throwException = $e;

        return $this;
    }

    public function getCallsCount(): int
    {
        return $this->callsCount;
    }

    public function supports(MailAccount $account): bool
    {
        return true;
    }

    public function fetchBatch(MailAccount $account, MailSyncState $state): MailSyncBatchResult
    {
        $this->callsCount++;

        if ($this->throwException !== null) {
            throw $this->throwException;
        }

        if (! empty($this->queuedBatches)) {
            return array_shift($this->queuedBatches);
        }

        return new MailSyncBatchResult(messages: []);
    }

    private ?array $customBodyResponse = null;

    public function setCustomBodyResponse(?array $response): self
    {
        $this->customBodyResponse = $response;

        return $this;
    }

    public function fetchMessageBody(MailAccount $account, string $providerMessageId): array
    {
        if ($this->customBodyResponse !== null) {
            return $this->customBodyResponse;
        }

        return [
            'success' => true,
            'snippet' => 'Testowy podgląd wiadomości e-mail',
            'body_text' => "Dzień dobry,\n\nTo jest testowa treść wiadomości rekrutacyjnej.\n\nPozdrawiamy,\nZespół Rekrutacji",
            'body_html' => '<p>Dzień dobry,</p><p>To jest testowa treść wiadomości rekrutacyjnej.</p><p>Pozdrawiamy,<br><strong>Zespół Rekrutacji</strong></p>',
            'to_email' => $account->email,
            'cc_email' => null,
            'has_attachments' => false,
            'attachments' => [],
        ];
    }
}
