<?php

use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\SessionController;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (): Response => response()->view('errors.403', status: 403)
    ->header('Cache-Control', 'private, no-store'));

Route::get('account/delete', [AccountDeletionController::class, 'show'])
    ->middleware('cache.headers:private;no_store')->name('account.delete');
Route::post('account/delete', [AccountDeletionController::class, 'destroy'])
    ->middleware('throttle:15,1')->name('account.delete.store');

Route::prefix('admin')->name('admin.')->middleware('cache.headers:private;no_store')->group(function (): void {
    Route::middleware('guest:admin')->group(function (): void {
        Route::get('login', [SessionController::class, 'create'])->name('login');
        Route::post('login', [SessionController::class, 'store'])->middleware('throttle:admin-login')->name('login.store');
    });

    Route::middleware('auth:admin')->group(function (): void {
        Route::get('/', fn () => to_route('admin.integrations.show'))->name('home');
        Route::post('logout', [SessionController::class, 'destroy'])->name('logout');

        Route::middleware('can:integrations.alphasms.view')->group(function (): void {
            Route::get('integrations', [IntegrationController::class, 'show'])->name('integrations.show');
            Route::middleware('can:integrations.alphasms.update')->group(function (): void {
                Route::patch('integrations/alphasms', [IntegrationController::class, 'update'])
                    ->middleware('throttle:10,1')->name('integrations.update');
                Route::post('integrations/alphasms/check', [IntegrationController::class, 'check'])
                    ->middleware('throttle:5,1')->name('integrations.check');
            });
        });
    });
});
