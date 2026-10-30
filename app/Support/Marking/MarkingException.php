<?php

namespace App\Support\Marking;

use RuntimeException;

/** Something that stops marks being approved or changed. The message is written to be shown. */
class MarkingException extends RuntimeException
{
    /** @param  list<string>  $blockers  each thing still in the way */
    public function __construct(string $message, public readonly array $blockers = [])
    {
        parent::__construct($message);
    }
}
