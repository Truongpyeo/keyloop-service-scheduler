<?php

namespace App\Exceptions;

use App\Enums\BookingFailureReason;
use Exception;

class BookingConflictException extends Exception
{
    public function __construct(public readonly BookingFailureReason $reason)
    {
        parent::__construct($reason->message());
    }
}
