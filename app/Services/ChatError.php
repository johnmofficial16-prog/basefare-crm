<?php

namespace App\Services;

use RuntimeException;

/** A refusal to show the user as is. */
class ChatError extends RuntimeException
{
    public function __construct(string $message, public int $status = 422, public ?string $field = null)
    {
        parent::__construct($message);
    }
}
