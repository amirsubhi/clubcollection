<?php

declare(strict_types=1);

// The updater library: plain PHP, no Composer, no Laravel. Required in dependency order.
require_once __DIR__.'/UpdaterError.php';
require_once __DIR__.'/Files.php';
require_once __DIR__.'/Lock.php';
require_once __DIR__.'/Journal.php';
require_once __DIR__.'/Download.php';
require_once __DIR__.'/ZipSafety.php';
require_once __DIR__.'/Unpack.php';
require_once __DIR__.'/Report.php';
