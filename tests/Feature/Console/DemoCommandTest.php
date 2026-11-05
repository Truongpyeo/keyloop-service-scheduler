<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

it('fails with a clear message when the API is not reachable', function () {
    $this->seed();
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('app:demo')
        ->expectsOutputToContain('Cannot reach the API at http://localhost:8000')
        ->assertFailed();
});

it('creates its own demo vehicles instead of using the seeded ones', function () {
    $this->seed();
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('app:demo')->assertFailed();

    $this->assertDatabaseHas('customers', ['email' => 'demo@example.com']);
    $this->assertDatabaseHas('vehicles', ['vin' => 'DEMA0000000000001']);
    $this->assertDatabaseHas('vehicles', ['vin' => 'DEMA0000000000002']);
});
