<?php

namespace App\Exceptions;

use RuntimeException;

class TrackerException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
