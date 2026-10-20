<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RegistrationEmailVerificationController;
use App\Http\Controllers\SpaAuthController;
use App\Http\Middleware\EnsureSpaOrigin;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{event}', [EventController::class, 'show'])->whereNumber('event');

Route::post('/registration/email-verifications', [RegistrationEmailVerificationController::class, 'store'])
    ->middleware('throttle:registration-email-request');
Route::post('/registration/email-verifications/{challenge}/verify', [RegistrationEmailVerificationController::class, 'verify'])
    ->middleware('throttle:registration-email-verify')->where('challenge', '[a-f0-9]{64}');

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:registration');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/login/email-verifications/{challenge}/verify', [AuthController::class, 'verifyLogin'])
    ->middleware('throttle:login-email-verify')->where('challenge', '[a-f0-9]{64}');

Route::prefix('spa')->middleware(EnsureSpaOrigin::class)->group(function (): void {
    Route::post('/registration/email-verifications', [RegistrationEmailVerificationController::class, 'store'])
        ->middleware('throttle:registration-email-request');
    Route::post('/registration/email-verifications/{challenge}/verify', [RegistrationEmailVerificationController::class, 'verify'])
        ->middleware('throttle:registration-email-verify')->where('challenge', '[a-f0-9]{64}');
    Route::post('/register', [SpaAuthController::class, 'register'])->middleware('throttle:registration');
    Route::post('/login', [SpaAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/login/email-verifications/{challenge}/verify', [SpaAuthController::class, 'verifyLogin'])
        ->middleware('throttle:login-email-verify')->where('challenge', '[a-f0-9]{64}');

    Route::middleware('auth:web')->group(function (): void {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::delete('/trusted-devices', [AuthController::class, 'revokeTrustedDevices']);
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::delete('/trusted-devices', [AuthController::class, 'revokeTrustedDevices']);
    Route::post('/events/{event}/reservations', [ReservationController::class, 'store'])
        ->middleware('throttle:reservations')->whereNumber('event');
});
