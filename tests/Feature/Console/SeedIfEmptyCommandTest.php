<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('seeds the database when it has no data', function () {
    $this->artisan('app:seed-if-empty')->assertSuccessful();

    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
});

it('does not seed the database when it already has data', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    $this->artisan('app:seed-if-empty')->assertSuccessful();

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
});
