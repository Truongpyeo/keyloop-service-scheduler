<?php

use App\Models\Dealership;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('seeds the database when it has no dealerships', function () {
    $this->artisan('app:seed-if-empty')->assertSuccessful();

    $this->assertDatabaseHas('dealerships', ['name' => 'Keyloop Da Nang']);
});

it('does not seed the database when a dealership already exists', function () {
    Dealership::factory()->create(['name' => 'Existing Dealership']);

    $this->artisan('app:seed-if-empty')->assertSuccessful();

    $this->assertDatabaseCount('dealerships', 1);
    $this->assertDatabaseMissing('dealerships', ['name' => 'Keyloop Da Nang']);
});
