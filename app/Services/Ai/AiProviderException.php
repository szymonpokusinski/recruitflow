<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

final class AiProviderException extends RuntimeException
{
    // Never expose the provider's HTTP body, prompt, headers or exception message.
    private const MESSAGES = [
        'unknown_provider' => 'Wybrany provider AI nie jest skonfigurowany.',
        'provider_disabled' => 'Wybrany provider AI jest wyłączony.',
        'invalid_driver' => 'Driver AI nie implementuje wymaganego kontraktu.',
        'missing_credentials' => 'Brak klucza API providera AI.',
        'credential_store_error' => 'Nie udało się odczytać bezpiecznego magazynu poświadczeń AI.',
        'invalid_configuration' => 'Konfiguracja providera AI jest nieprawidłowa.',
        'cursor_start_failed' => 'Nie udało się uruchomić agenta Cursor.',
        'cursor_start_timeout' => 'Przekroczono czas uruchamiania agenta Cursor.',
        'cursor_run_failed' => 'Nie udało się odczytać lub zakończyć runu Cursor.',
        'cursor_run_timeout' => 'Przekroczono czas oczekiwania na wynik Cursor.',
        'cursor_run_cancelled' => 'Run Cursor został anulowany.',
        'cursor_run_expired' => 'Run Cursor wygasł.',
        'cursor_auth_failed' => 'Cursor odrzucił klucz API lub uprawnienia konta.',
        'gemini_request_failed' => 'Nie udało się wykonać żądania Gemini.',
        'gemini_request_timeout' => 'Przekroczono czas oczekiwania na odpowiedź Gemini.',
        'gemini_auth_failed' => 'Gemini odrzucił klucz API lub uprawnienia konta.',
        'gemini_run_failed' => 'Gemini nie zakończył generowania kompletnego wyniku.',
        'unsupported_operation' => 'Provider AI nie obsługuje tej operacji.',
        'unsupported_options' => 'Provider AI nie obsługuje przekazanych opcji.',
        'invalid_response' => 'Odpowiedź providera AI nie spełnia wspólnego kontraktu.',
        'provider_failed' => 'Wywołanie providera AI nie powiodło się. Szczegóły identyfikuje request_id.',
    ];

    public readonly string $errorCode;

    public function __construct(
        string $errorCode,
        public readonly ?string $requestId = null,
        public readonly ?string $phase = null,
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = false,
        public readonly array $context = [],
    ) {
        $this->errorCode = array_key_exists($errorCode, self::MESSAGES) ? $errorCode : 'provider_failed';
        parent::__construct(self::MESSAGES[$this->errorCode]);
    }
}
