<?php

use Illuminate\Support\Facades\DB;

it('returns 200 with the database up when the database is reachable', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'database' => 'up']);
});

it('returns 503 with the database down when the database is unreachable', function () {
    config(['database.connections.sqlite.database' => '/nonexistent/database.sqlite']);
    DB::purge('sqlite');

    $this->getJson('/api/health')
        ->assertServiceUnavailable()
        ->assertExactJson(['status' => 'error', 'database' => 'down']);
});
