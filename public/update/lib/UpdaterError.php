<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/** A step failed for a reason the page can explain. $hostMessage, when set, is the text to send to the hosting company. */
final class UpdaterError extends \RuntimeException
{
    public function __construct(string $message, private readonly string $hostMessage = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function hostMessage(): string
    {
        return $this->hostMessage;
    }
}
