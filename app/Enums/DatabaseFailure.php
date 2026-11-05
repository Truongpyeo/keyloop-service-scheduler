<?php

namespace App\Enums;

use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;

enum DatabaseFailure: string
{
    case Deadlock = 'db_deadlock';
    case LockWaitTimeout = 'db_lock_timeout';
    case Unavailable = 'db_unavailable';

    /**
     * Classify a query failure that a client can safely retry, or null for any other error.
     */
    public static function tryFromException(QueryException $exception): ?self
    {
        return match (true) {
            ($exception->errorInfo[1] ?? null) === 1213 => self::Deadlock,
            ($exception->errorInfo[1] ?? null) === 1205 => self::LockWaitTimeout,
            app(LostConnectionDetector::class)->causedByLostConnection($exception) => self::Unavailable,
            default => null,
        };
    }
}
