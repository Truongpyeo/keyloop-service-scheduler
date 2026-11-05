<?php

use App\Models\Appointment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

it('refuses to run outside the local environment', function () {
    Http::preventStrayRequests();
    $appointment = Appointment::factory()->create();

    $this->artisan('app:concurrency-test', ['--force' => true])
        ->expectsOutputToContain('only runs in the local environment')
        ->assertFailed();

    $this->assertModelExists($appointment);
});

it('refuses to run without --force in the local environment', function () {
    Http::preventStrayRequests();
    $this->app['env'] = 'local';
    $appointment = Appointment::factory()->create();

    $this->artisan('app:concurrency-test')
        ->expectsOutputToContain('Re-run with --force')
        ->assertFailed();

    $this->assertModelExists($appointment);
});
