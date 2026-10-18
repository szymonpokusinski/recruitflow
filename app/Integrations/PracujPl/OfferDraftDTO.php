<?php

namespace App\Integrations\PracujPl;

final readonly class OfferDraftDTO
{
    public function __construct(
        public string $providerOfferId,
        public string $canonicalUrl,
        public string $status,
        public array $form,
        public array $details,
        public array $warnings,
        public array $fieldSources,
    ) {}

    public function toArray(): array
    {
        return [
            'provider' => 'pracuj.pl',
            'providerOfferId' => $this->providerOfferId,
            'canonicalUrl' => $this->canonicalUrl,
            'status' => $this->status,
            'form' => $this->form,
            'details' => $this->details,
            'warnings' => $this->warnings,
            'fieldSources' => $this->fieldSources,
        ];
    }
}
