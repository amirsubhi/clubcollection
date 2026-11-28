<?php

declare(strict_types=1);

namespace App\Support\Updates;

/**
 * One row of the update pre-checks. A pass has an empty message unless it carries a non-blocking note.
 * message and fix are the plain lines the super admin reads; hostMessage is the copyable text for the host's support
 * desk (or whoever installed the portal) and is the only place technical terms may appear.
 */
final class PreCheck
{
    public function __construct(
        public readonly string $key,
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?string $fix,
        public readonly ?string $hostMessage,
        public readonly bool $blocking,
    ) {}
}
