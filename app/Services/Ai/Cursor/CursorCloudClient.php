<?php

declare(strict_types=1);

namespace App\Services\Ai\Cursor;

use App\Services\Ai\AiClock;
use App\Services\Ai\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;
use SensitiveParameter;
use Throwable;

/**
 * NativePHP uses REST and Cursor-hosted no-repo agents, not a local SDK process.
 * No repos, named environments, envVars or MCP servers are sent to the runtime.
 */
final class CursorCloudClient
{
    private const BASE_URL = 'https://api.cursor.com';

    private const RETRYABLE_STATUS = [408, 429, 500, 502, 503, 504];

    public function __construct(private readonly AiClock $clock) {}

    public function run(#[SensitiveParameter] string $apiKey, string $prompt, ?string $model, #[SensitiveParameter] array $configuration): array
    {
        $configuration = $this->configuration($configuration, $model);
        $deadline = $this->clock->now() + $configuration['timeout'];
        $agentId = 'bc-'.Str::uuid();
        $runId = null;
        $terminal = false;
        $cleanupRequired = false;
        $failure = null;
        $phase = 'start';
        $result = null;
        $payload = [
            'agentId' => $agentId,
            'name' => 'RecruitFlow category research',
            'prompt' => ['text' => $prompt],
            'mode' => 'agent',
            'autoCreatePR' => false,
        ];
        if ($model !== null) {
            $payload['model'] = ['id' => $model];
        }

        try {
            // Every retry uses the same bc-UUID. A 409 recovers this agent only.
            $response = $this->request('POST', '/v1/agents', $payload, $apiKey, $configuration, $deadline, 'start', $agentId, allowConflict: true);
            $cleanupRequired = true;
            if ($response->status() === 409) {
                $recovered = $this->json($this->request('GET', '/v1/agents/'.$agentId, [], $apiKey, $configuration, $deadline, 'start', $agentId), 'start', $agentId);
                $initial = ['agent' => $recovered, 'run' => ['id' => $recovered['latestRunId'] ?? null, 'agentId' => $recovered['id'] ?? null]];
            } else {
                $initial = $this->json($response, 'start', $agentId);
            }
            $candidate = $initial['run']['id'] ?? null;
            if (($initial['agent']['id'] ?? null) !== $agentId || ($initial['run']['agentId'] ?? null) !== $agentId
                || ! is_string($candidate) || ! preg_match('/^run-[a-zA-Z0-9-]{1,100}$/', $candidate)) {
                throw new AiProviderException('cursor_start_failed', $agentId, phase: 'start');
            }
            $runId = $candidate;
            $phase = 'run';
            while (true) {
                $run = $this->json($this->request('GET', '/v1/agents/'.$agentId.'/runs/'.$runId, [], $apiKey, $configuration, $deadline, 'run', $runId), 'run', $runId);
                if (($run['id'] ?? null) !== $runId || ($run['agentId'] ?? null) !== $agentId) {
                    throw new AiProviderException('cursor_run_failed', $runId, phase: 'run');
                }
                $status = $run['status'] ?? null;
                if (in_array($status, ['FINISHED', 'ERROR', 'CANCELLED', 'EXPIRED'], true)) {
                    $terminal = true;
                    if ($status !== 'FINISHED') {
                        $code = match ($status) {
                            'CANCELLED' => 'cursor_run_cancelled', 'EXPIRED' => 'cursor_run_expired', default => 'cursor_run_failed',
                        };
                        throw new AiProviderException($code, $runId, phase: 'run');
                    }
                    if (! is_string($run['result'] ?? null)) {
                        throw new AiProviderException('invalid_response', $runId, phase: 'run');
                    }
                    $result = $run['result'];
                    break;
                }
                if (! in_array($status, ['CREATING', 'RUNNING'], true)) {
                    throw new AiProviderException('cursor_run_failed', $runId, phase: 'run');
                }
                $this->pause($configuration['poll_interval_ms'], $deadline, 'run', $runId);
            }
        } catch (AiProviderException $exception) {
            $failure = $exception;
            // A transport/5xx failure can happen after POST actually created an agent.
            $cleanupRequired = $cleanupRequired || ($phase === 'start' && ($exception->httpStatus === null || $exception->httpStatus >= 500));
        } catch (Throwable) {
            $failure = new AiProviderException('cursor_'.$phase.'_failed', $runId ?? $agentId, phase: $phase);
            $cleanupRequired = true;
        }

        $cleanup = $cleanupRequired ? $this->cleanup($agentId, $runId, $terminal, $apiKey, $configuration) : ['attempted' => false, 'archived' => false, 'cancelled' => false];
        $context = [
            'agent_id' => $agentId, 'run_id' => $runId, 'runtime' => 'cloud',
            'model_source' => $model === null ? 'unknown_server_default' : 'request',
            'cleanup' => $cleanup,
        ];
        if ($failure !== null) {
            throw new AiProviderException(
                $failure->errorCode, $failure->requestId, $failure->phase, $failure->httpStatus,
                $failure->retryable, $context,
            );
        }

        return ['result' => $result, 'model' => $model, 'context' => $context];
    }

    private function configuration(#[SensitiveParameter] array $configuration, ?string $model): array
    {
        $config = [
            'timeout' => $configuration['timeout'] ?? 180,
            'request_timeout' => $configuration['request_timeout'] ?? 15,
            'connect_timeout' => $configuration['connect_timeout'] ?? 5,
            'poll_interval_ms' => $configuration['poll_interval_ms'] ?? 1000,
            'max_attempts' => $configuration['max_attempts'] ?? 3,
            'retry_delay_ms' => $configuration['retry_delay_ms'] ?? 250,
        ];
        try {
            Validator::make($config + ['model' => $model], [
                'timeout' => ['required', 'integer', 'between:1,900'],
                'request_timeout' => ['required', 'integer', 'between:1,60'],
                'connect_timeout' => ['required', 'integer', 'between:1,30'],
                'poll_interval_ms' => ['required', 'integer', 'between:1,10000'],
                'max_attempts' => ['required', 'integer', 'between:1,5'],
                'retry_delay_ms' => ['required', 'integer', 'between:1,5000'],
                'model' => ['nullable', ...($model !== null ? ['required'] : []), 'string', 'max:100'],
            ])->validate();
        } catch (Throwable) {
            throw new AiProviderException('invalid_configuration');
        }

        return $config;
    }

    private function request(string $method, string $path, array $payload, #[SensitiveParameter] string $apiKey, array $configuration, float $deadline, string $phase, string $requestId, bool $allowConflict = false): Response
    {
        for ($attempt = 1; $attempt <= $configuration['max_attempts']; $attempt++) {
            $remaining = $this->remaining($deadline, $phase, $requestId);
            $response = null;
            try {
                $response = Http::acceptJson()->asJson()->withBasicAuth($apiKey, '')
                    ->withoutRedirecting()
                    ->timeout(min($remaining, $configuration['request_timeout']))
                    ->connectTimeout(min($remaining, $configuration['connect_timeout']))
                    ->send($method, self::BASE_URL.$path, $method === 'GET' || $payload === [] ? [] : ['json' => $payload]);
                $this->remaining($deadline, $phase, $requestId);
                if ($response->successful() || ($allowConflict && $response->status() === 409)) {
                    return $response;
                }
                $retryable = in_array($response->status(), self::RETRYABLE_STATUS, true);
                $code = in_array($response->status(), [401, 403], true) ? 'cursor_auth_failed' : 'cursor_'.$phase.'_failed';
                $error = new AiProviderException($code, $requestId, $phase, $response->status(), $retryable);
            } catch (ConnectionException) {
                $error = new AiProviderException('cursor_'.$phase.'_failed', $requestId, $phase, retryable: true);
            }
            if (! $error->retryable || $attempt === $configuration['max_attempts']) {
                throw $error;
            }
            $delay = $configuration['retry_delay_ms'] * (2 ** ($attempt - 1));
            $retryAfter = $response?->header('Retry-After');
            if (is_string($retryAfter) && strlen($retryAfter) < 100) {
                $seconds = ctype_digit($retryAfter) ? (float) $retryAfter : max(0, (strtotime($retryAfter) ?: time()) - time());
                $delay = max($delay, min(900_000, (int) ($seconds * 1000)));
            }
            $this->pause($delay, $deadline, $phase, $requestId);
        }

        throw new AiProviderException('cursor_'.$phase.'_failed', $requestId, $phase);
    }

    private function json(Response $response, string $phase, string $requestId): array
    {
        try {
            if (strlen($response->body()) > 2_000_000) {
                throw new AiProviderException('cursor_'.$phase.'_failed', $requestId, $phase);
            }
            $value = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($value)) {
                throw new AiProviderException('cursor_'.$phase.'_failed', $requestId, $phase);
            }

            return $value;
        } catch (JsonException) {
            throw new AiProviderException('cursor_'.$phase.'_failed', $requestId, $phase);
        }
    }

    private function remaining(float $deadline, string $phase, string $requestId): float
    {
        $remaining = $deadline - $this->clock->now();
        if ($remaining <= 0) {
            throw new AiProviderException('cursor_'.$phase.'_timeout', $requestId, $phase, retryable: true);
        }

        return $remaining;
    }

    private function pause(int $milliseconds, float $deadline, string $phase, string $requestId): void
    {
        if ($milliseconds / 1000 >= $this->remaining($deadline, $phase, $requestId)) {
            throw new AiProviderException('cursor_'.$phase.'_timeout', $requestId, $phase, retryable: true);
        }
        $this->clock->sleep($milliseconds);
    }

    private function cleanup(string $agentId, ?string $runId, bool $terminal, #[SensitiveParameter] string $apiKey, array $configuration): array
    {
        // At most five additional seconds; cleanup never replaces a primary error.
        $deadline = $this->clock->now() + 5;
        $configuration = array_replace($configuration, ['max_attempts' => 1, 'request_timeout' => 2, 'connect_timeout' => 1]);
        $state = ['attempted' => true, 'archived' => false, 'cancelled' => false];
        if ($runId !== null && ! $terminal) {
            try {
                $this->request('POST', '/v1/agents/'.$agentId.'/runs/'.$runId.'/cancel', [], $apiKey, $configuration, $deadline, 'run', $runId);
                $state['cancelled'] = true;
            } catch (Throwable) {
                // Surface cleanup flags in result metadata / exception context.
            }
        }
        try {
            $this->request('POST', '/v1/agents/'.$agentId.'/archive', [], $apiKey, $configuration, $deadline, 'run', $runId ?? $agentId);
            $state['archived'] = true;
        } catch (Throwable) {
            // The consumer can display that the agent may need manual archiving.
        }

        return $state;
    }
}
