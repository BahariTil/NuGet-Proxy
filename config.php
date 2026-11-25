<?php

declare(strict_types=1);

return [
    // Replace this with a NEW random 64-character token.
    // The same token must be used in your NuGet source URL.
    'access_token' => 'YOUR_SECRET_TOKEN',

    // PHP temporary storage. A private Nexora-specific folder is created inside it.
    // If sys_get_temp_dir() is not writable on your host, set an absolute writable path.
    'temp_dir' => sys_get_temp_dir(),

    // Maximum size of ONE downloaded .nupkg file (200 MiB).
    'max_package_bytes' => 200 * 1024 * 1024,

    // Maximum size of JSON responses from NuGet (20 MiB).
    'max_json_bytes' => 20 * 1024 * 1024,

    // cURL connection timeout in seconds.
    'connect_timeout' => 25,

    // Maximum total time for one upstream request in seconds.
    'request_timeout' => 240,

    // Temporary files older than this are deleted on the next package request.
    // Normal downloads should finish well before this value.
    'stale_temp_age_seconds' => 3600,
];
