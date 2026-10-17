<?php

namespace App\Hr\Http;

use RuntimeException;

/**
 * An error the API reports to the user in plain words. Anything else that is
 * thrown becomes a generic "Something went wrong" with a reference code.
 */
final class ApiError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        string $message,
        public readonly array $fields = []
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'That record'): self
    {
        return new self(404, 'not_found', $what . " wasn't found. It may have been removed.");
    }

    public static function forbidden(string $message = "You don't have access to that."): self
    {
        return new self(403, 'forbidden', $message);
    }

    /** @param array<string,string> $fields field => message, shown next to each field */
    public static function invalid(string $message, array $fields = []): self
    {
        return new self(422, 'invalid', $message, $fields);
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'conflict', $message);
    }
}
