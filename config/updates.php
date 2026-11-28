<?php

return [
    // 'stable' follows the latest release; 'next' follows the pre-release channel.
    'channel' => env('UPDATE_CHANNEL', 'stable'),

    'repo' => 'amirsubhi/clubcollection',

    // How long a successful check is reused, and how long a failed one (so a slow or blocked GitHub is not retried on every page).
    'cache_seconds' => 3600,
    'failure_cache_seconds' => 900,

    // Seconds to wait for GitHub before giving up.
    'timeout' => 5,

    // Where the installed version is read from (a git checkout has no such file and counts as "dev").
    'release_file' => base_path('release.json'),
];
