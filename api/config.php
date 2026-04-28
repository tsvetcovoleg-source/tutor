<?php

// Copy this file and update values for your shared hosting environment.
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'voice_tutor',
    'db_user' => 'root',
    'db_pass' => '',
    'db_charset' => 'utf8mb4',
    'max_upload_bytes' => 10 * 1024 * 1024,
    'allowed_extensions' => ['webm', 'mp4', 'ogg', 'm4a'],

    // Debug mode adds detailed error payloads in API responses.
    // IMPORTANT: Set to false in production.
    'debug' => true,
];
