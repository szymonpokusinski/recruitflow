<?php

declare(strict_types=1);

namespace App\Services\Mail\Providers;

use App\Models\MailAccount;
use App\Services\Mail\Contracts\MailSyncProviderInterface;
use InvalidArgumentException;

class MailSyncProviderResolver
{
    private ?MailSyncProviderInterface $overrideProvider = null;

    public function __construct(
        private readonly ?GmailSyncProvider $gmailProvider = null,
        private readonly ?ImapSyncProvider $imapProvider = null,
    ) {}

    /**
     * Set a custom provider to override resolution (primarily for testing).
     */
    public function setOverrideProvider(?MailSyncProviderInterface $provider): self
    {
        $this->overrideProvider = $provider;

        return $this;
    }

    /**
     * Resolve the suitable provider for the given MailAccount.
     */
    public function resolve(MailAccount $account): MailSyncProviderInterface
    {
        if ($this->overrideProvider !== null) {
            return $this->overrideProvider;
        }

        $gmail = $this->gmailProvider ?? new GmailSyncProvider();
        if ($gmail->supports($account)) {
            return $gmail;
        }

        $imap = $this->imapProvider ?? new ImapSyncProvider();
        if ($imap->supports($account)) {
            return $imap;
        }

        throw new InvalidArgumentException(
            sprintf('Nie znaleziono odpowiedniego dostawcy synchronizacji dla konta [%s].', $account->email)
        );
    }
}
