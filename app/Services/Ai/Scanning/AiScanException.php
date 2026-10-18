<?php

declare(strict_types=1);

namespace App\Services\Ai\Scanning;

use RuntimeException;

final class AiScanException extends RuntimeException
{
    private const MESSAGES = [
        'category_inactive' => 'Kategoria jest nieaktywna.',
        'category_busy' => 'Kategoria ma już oczekujący lub działający skan.',
        'prompt_missing' => 'Kategoria nie ma aktywnego promptu.',
        'invalid_configuration' => 'Konfiguracja skanowania kategorii jest nieprawidłowa.',
        'identity_conflict' => 'Tożsamości wyniku wskazują różne istniejące pozycje. Poprzednie dane zachowano.',
        'interrupted' => 'Poprzedni skan został przerwany lub przekroczył czas wykonania.',
        'stale_run' => 'Run nie jest już aktywny; nie zapisano spóźnionych wyników.',
        'scan_failed' => 'Nie udało się zakończyć skanowania kategorii.',
    ];

    public readonly string $reason;

    public function __construct(string $reason)
    {
        $this->reason = array_key_exists($reason, self::MESSAGES) ? $reason : 'scan_failed';
        parent::__construct(self::MESSAGES[$this->reason]);
    }
}
