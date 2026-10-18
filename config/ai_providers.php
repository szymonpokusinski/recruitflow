<?php

declare(strict_types=1);

use App\Services\Ai\Providers\CursorProvider;
use App\Services\Ai\Providers\FakeProvider;
use App\Services\Ai\Providers\GeminiProvider;

return [
    'default' => env('AI_DEFAULT_PROVIDER', env('AI_PROVIDER', 'cursor')),

    // Only this trusted registry may resolve PHP classes; the database stores keys.
    'drivers' => [
        'cursor' => CursorProvider::class,
        'fake' => FakeProvider::class,
        'gemini' => GeminiProvider::class,
    ],

    'providers' => [
        'gemini' => [
            'driver' => 'gemini',
            'enabled' => true,
            'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
            'api_key' => env('GEMINI_API_KEY'),
            'credential_ref' => env('GEMINI_CREDENTIAL_REF') ?: null,
            'google_search_grounding' => env('GEMINI_SEARCH_GROUNDING', false),
            'timeout' => (int) env('GEMINI_TIMEOUT', 60),
            'request_timeout' => 60,
            'connect_timeout' => 5,
            'max_attempts' => 3,
            'retry_delay_ms' => 250,
            // Same env/Keychain credential selection as other drivers.
            'model_credentials' => [],
        ],
        'cursor' => [
            'driver' => 'cursor',
            'enabled' => true,
            'model' => env('CURSOR_MODEL') ?: null,
            'api_key' => env('CURSOR_API_KEY'),
            'credential_ref' => env('CURSOR_CREDENTIAL_REF') ?: null,
            'timeout' => (int) env('CURSOR_TIMEOUT', 180),
            'request_timeout' => 15,
            'connect_timeout' => 5,
            'poll_interval_ms' => 1000,
            'max_attempts' => 3,
            'retry_delay_ms' => 250,
            // Optional per-model api_key / credential_ref overrides, using env()
            // or Keychain references. Never put literal API keys in this file.
            'model_credentials' => [],
        ],
        'fake' => [
            'driver' => 'fake',
            'enabled' => env('AI_FAKE_ENABLED', false),
            'model' => 'fake-v1',
            // No generated offers or scores. Tests inject explicit fixtures.
            'items' => [],
        ],
    ],
];
