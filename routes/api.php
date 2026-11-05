<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\CancelAppointmentController;
use App\Http\Controllers\DealershipAvailabilityController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::get('/dealerships/{dealership}/availability', DealershipAvailabilityController::class)->name('dealerships.availability');

Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
Route::patch('/appointments/{appointment}/cancel', CancelAppointmentController::class)->name('appointments.cancel');
