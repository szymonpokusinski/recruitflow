<?php

declare(strict_types=1);

namespace App\Services\Ai\Gemini;

use App\Services\Ai\AiClock;
use App\Services\Ai\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use SensitiveParameter;
use Throwable;

final class GeminiClient
{
    public const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    public function __construct(private readonly AiClock $clock) {}

    public function run(#[SensitiveParameter] string $apiKey, array $payload, #[SensitiveParameter] array $configuration): array
    {
        $config = [
            'timeout' => $configuration['timeout'] ?? 60,
            'request_timeout' => $configuration['request_timeout'] ?? 60,
            'connect_timeout' => $configuration['connect_timeout'] ?? 5,
            'max_attempts' => $configuration['max_attempts'] ?? 3,
            'retry_delay_ms' => $configuration['retry_delay_ms'] ?? 250,
        ];
        try {
            Validator::make($config, [
                'timeout' => ['required', 'integer', 'between:1,900'],
                'request_timeout' => ['required', 'integer', 'between:1,900'],
                'connect_timeout' => ['required', 'integer', 'between:1,30'],
                'max_attempts' => ['required', 'integer', 'between:1,5'],
                'retry_delay_ms' => ['required', 'integer', 'between:1,5000'],
            ])->validate();
        } catch (ValidationException) {
            throw new AiProviderException('invalid_configuration');
        }
        $deadline = $this->clock->now() + $config['timeout'];
        $requestId = (string) Str::uuid();
        for ($attempt = 1; $attempt <= $config['max_attempts']; $attempt++) {
            $response = null;
            $remaining = $this->remaining($deadline, $requestId);
            try {
                $response = Http::acceptJson()->asJson()->withoutRedirecting()
                    ->withHeaders(['x-goog-api-key' => $apiKey])
                    ->timeout(min($remaining, $config['request_timeout']))
                    ->connectTimeout(min($remaining, $config['connect_timeout']))
                    ->post(self::ENDPOINT, $payload);
                $this->remaining($deadline, $requestId);
                if ($response->successful()) {
                    try {
                        if (strlen($response->body()) > 2_000_000) {
                            throw new AiProviderException('invalid_response', $requestId, phase: 'run');
                        }
                        $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
                        if (! is_array($data) || array_is_list($data)) {
                            throw new AiProviderException('invalid_response', $requestId, phase: 'run');
                        }

                        return $data;
                    } catch (JsonException) {
                        throw new AiProviderException('invalid_response', $requestId, phase: 'run');
                    }
                }
                $status = $response->status();
                $error = new AiProviderException(
                    in_array($status, [401, 403], true) ? 'gemini_auth_failed' : 'gemini_request_failed',
                    $requestId, 'request', $status, in_array($status, [408, 429, 500, 502, 503, 504], true),
                );
            } catch (ConnectionException) {
                $error = new AiProviderException('gemini_request_failed', $requestId, 'request', retryable: true);
            } catch (AiProviderException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new AiProviderException('gemini_request_failed', $requestId, 'request');
            }
            if (! $error->retryable || $attempt === $config['max_attempts']) {
                throw $error;
            }
            $delay = $config['retry_delay_ms'] * (2 ** ($attempt - 1));
            $retryAfter = $response?->header('Retry-After');
            if (is_string($retryAfter) && strlen($retryAfter) < 100) {
                $seconds = ctype_digit($retryAfter) ? min(900, (float) $retryAfter) : max(0, (strtotime($retryAfter) ?: time()) - time());
                $delay = max($delay, (int) (min(900, $seconds) * 1000));
            }
            if ($delay / 1000 >= $this->remaining($deadline, $requestId)) {
                throw new AiProviderException('gemini_request_timeout', $requestId, 'request', retryable: true);
            }
            $this->clock->sleep($delay);
        }

        throw new AiProviderException('gemini_request_failed', $requestId, 'request');
    }

    private function remaining(float $deadline, string $requestId): float
    {
        $remaining = $deadline - $this->clock->now();
        if ($remaining <= 0) {
            throw new AiProviderException('gemini_request_timeout', $requestId, 'request', retryable: true);
        }

        return $remaining;
    }
}
