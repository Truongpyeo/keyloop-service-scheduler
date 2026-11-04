<?php

use App\Models\Appointment;
use App\Models\Dealership;
use App\Models\Technician;
use App\Models\TechnicianSkill;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('belongs to a dealership and has many appointments', function () {
    $dealership = Dealership::factory()->create();
    $technician = Technician::factory()->for($dealership)->create();
    $appointment = Appointment::factory()->for($dealership)->for($technician)->create();

    expect($technician->dealership->is($dealership))->toBeTrue();
    expect($technician->appointments->modelKeys())->toBe([$appointment->id]);
});

it('has many skills that each belong back to the technician', function () {
    $technician = Technician::factory()->withSkills('general', 'brakes')->create();

    expect($technician->skills->pluck('skill')->all())->toEqualCanonicalizing(['general', 'brakes']);
    expect($technician->skills->every(fn (TechnicianSkill $skill) => $skill->technician->is($technician)))->toBeTrue();
});

it('rejects the same skill twice for one technician', function () {
    $technician = Technician::factory()->withSkills('brakes')->create();

    expect(fn () => $technician->skills()->create(['skill' => 'brakes']))->toThrow(QueryException::class);
});
