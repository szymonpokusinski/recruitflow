<?php

return [
    'enabled' => env('RECRUITFLOW_WIDGETS_ENABLED', true),
    // Used only for read-only system verification of desktop links.
    'read_only_preview' => env('RECRUITFLOW_READ_ONLY_PREVIEW', false),
    'companion_enabled' => env('RECRUITFLOW_WIDGET_COMPANION_ENABLED', false),
    // Resolve storage_path at runtime: NativePHP changes the app's storage root.
    'snapshot_directory' => env('RECRUITFLOW_WIDGETS_DIRECTORY'),
    'snapshot_name' => 'dashboard-v1.json',
    // Informational only; actual access is resolved from signed Xcode build settings.
    'app_group' => env('RECRUITFLOW_WIDGETS_APP_GROUP'),
    'stale_after_minutes' => 60,
    'max_offers' => 500,
];
