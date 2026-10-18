<?php

return [
    'credential_store' => env('INTEGRATION_CREDENTIAL_STORE', PHP_OS_FAMILY === 'Darwin' ? 'keychain' : 'encrypted_file'),
    'keychain_service' => 'pl.szymonpokusinski.recruitflow.integrations',
    'oauth_timeout_seconds' => 600,
    'max_rows' => 50000,
    'http_timeout_seconds' => 20,
];
