<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

it('returns 503 with Retry-After for a retryable database failure', function (string $driverMessage, ?int $driverCode, string $reason) {
    $pdoException = new PDOException($driverMessage);
    $pdoException->errorInfo = $driverCode === null ? null : ['HY000', $driverCode, $driverMessage];
    Route::get('/api/test/database-failure', fn () => throw new QueryException('mysql', 'select 1', [], $pdoException));

    $this->getJson('/api/test/database-failure')
        ->assertServiceUnavailable()
        ->assertHeader('Retry-After', '5')
        ->assertJsonPath('reason', $reason);
})->with([
    'deadlock' => ['Deadlock found when trying to get lock', 1213, 'db_deadlock'],
    'lock wait timeout' => ['Lock wait timeout exceeded; try restarting transaction', 1205, 'db_lock_timeout'],
    'connection refused' => ['SQLSTATE[HY000] [2002] Connection refused', null, 'db_unavailable'],
]);

it('does not turn other query errors into 503', function () {
    $pdoException = new PDOException("Unknown column 'nope'");
    $pdoException->errorInfo = ['42S22', 1054, "Unknown column 'nope'"];
    Route::get('/api/test/database-failure', fn () => throw new QueryException('mysql', 'select nope', [], $pdoException));

    $this->getJson('/api/test/database-failure')->assertInternalServerError();
});
