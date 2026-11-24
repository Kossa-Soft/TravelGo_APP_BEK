<?php

use App\Http\Controllers\Mobile\AuthController;
use App\Http\Controllers\Mobile\ProfileController;
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
Route::patch('me', [ProfileController::class, 'update']);
Route::delete('me', [ProfileController::class, 'destroy']);
Route::post('me/password/verify', [ProfileController::class, 'verifyPassword']);
Route::post('me/password', [ProfileController::class, 'changePassword']);
Route::post('me/phone/start', [ProfileController::class, 'startPhoneChange']);
Route::post('me/phone/verify', [ProfileController::class, 'verifyPhoneChange']);
Route::post('me/avatar', [ProfileController::class, 'uploadAvatar'])->middleware('throttle:30,1');
Route::delete('me/avatar', [ProfileController::class, 'deleteAvatar']);
Route::get('avatars/{user}/{avatar}/{size}.webp', [ProfileController::class, 'avatar'])
    ->whereUuid(['user', 'avatar'])->where('size', '256|512');
