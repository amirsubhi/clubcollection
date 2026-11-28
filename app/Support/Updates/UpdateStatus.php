<?php

declare(strict_types=1);

namespace App\Support\Updates;

use Carbon\CarbonImmutable;

/** The answer to "is there a newer version?", always safe to show: a failure is an `error` sentence, never an exception. */
final class UpdateStatus
{
    public function __construct(
        public readonly string $installed,
        public readonly ?Manifest $latest,
        public readonly bool $available,
        public readonly ?string $error,
        public readonly CarbonImmutable $checkedAt,
    ) {}
}
