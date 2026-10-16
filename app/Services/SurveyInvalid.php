<?php

namespace App\Services;

/** A survey that isn't complete yet, with the message per question. */
final class SurveyInvalid extends \InvalidArgumentException
{
    public function __construct(string $message, public array $fields)
    {
        parent::__construct($message);
    }
}
