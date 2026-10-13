<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RegistrationEmailVerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::post('/registration/email-verifications', [RegistrationEmailVerificationController::class, 'store'])
    ->middleware('throttle:registration-email-request');
Route::post('/registration/email-verifications/{challenge}/verify', [RegistrationEmailVerificationController::class, 'verify'])
    ->middleware('throttle:registration-email-verify')->where('challenge', '[a-f0-9]{64}');

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:registration');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/events/{event}/reservations', [ReservationController::class, 'store'])
        ->middleware('throttle:reservations')->whereNumber('event');
});
