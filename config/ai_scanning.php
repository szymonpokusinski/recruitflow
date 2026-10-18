<?php

declare(strict_types=1);

return [
    // Scheduling is opt-in; manual ai:scan works independently of this switch.
    'enabled' => env('AI_SCANNING_ENABLED', false),
    'queue' => 'ai-scans',
    'job_timeout' => 930,
    'running_lease_seconds' => 960,
    'queued_lease_seconds' => 86400,
];
