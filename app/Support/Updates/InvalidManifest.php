<?php

declare(strict_types=1);

namespace App\Support\Updates;

use RuntimeException;

/** The release manifest could not be trusted: not JSON, wrong shape, or a download that is not ours. */
final class InvalidManifest extends RuntimeException {}
