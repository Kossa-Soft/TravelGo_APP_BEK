<?php

use App\Http\Controllers\Mobile\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('register/start', [AuthController::class, 'startRegistration']);
    Route::post('register/verify', [AuthController::class, 'verifyRegistration']);
    Route::post('code/resend', [AuthController::class, 'resend']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('password/forgot', [AuthController::class, 'startRecovery']);
    Route::post('password/verify', [AuthController::class, 'verifyRecovery']);
    Route::post('password/reset', [AuthController::class, 'resetPassword']);
});

Route::get('me', [AuthController::class, 'me']);
