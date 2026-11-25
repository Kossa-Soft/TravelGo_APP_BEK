<?php

use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TravelGoController;
use App\Http\Controllers\Admin\TravelGoGeoController;
use Illuminate\Http\Request;
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
        Route::get('/', function (Request $request) {
            return $request->user('admin')->hasPermission('integrations.alphasms.view')
                ? to_route('admin.integrations.show') : to_route('admin.travelgo.home');
        })->name('home');
        Route::post('logout', [SessionController::class, 'destroy'])->name('logout');

        Route::prefix('travelgo')->name('travelgo.')->where(['type' => 'countries|regions|cities|places|routes'])->group(function (): void {
            Route::get('/', [TravelGoController::class, 'home'])->name('home');
            Route::get('publication', [TravelGoController::class, 'publication'])->name('publication');
            Route::get('audit', [TravelGoController::class, 'audit'])->name('audit');
            Route::post('publish', [TravelGoController::class, 'publish'])->name('publish');
            Route::get('media/{type}/{image}/{size}.webp', [TravelGoController::class, 'media'])
                ->whereUuid('image')->where('size', '400|1200')->name('media');
            Route::put('routes/{id}/stops', [TravelGoController::class, 'stops'])->name('stops');
            Route::post('routes/{id}/path/recalculate', [TravelGoController::class, 'recalculate'])->name('recalculate');
            Route::post('routes/{id}/duplicate', [TravelGoController::class, 'duplicate'])->name('duplicate');
            Route::get('{type}', [TravelGoController::class, 'index'])->name('index');
            Route::get('{type}/create', [TravelGoController::class, 'create'])->name('create');
            Route::post('{type}', [TravelGoController::class, 'store'])->name('store');
            Route::get('{type}/{id}', [TravelGoController::class, 'edit'])->name('edit');
            Route::patch('{type}/{id}', [TravelGoController::class, 'update'])->name('update');
            Route::post('{type}/{id}/status', [TravelGoController::class, 'status'])->name('status');
            Route::post('{type}/{id}/images', [TravelGoController::class, 'upload'])->middleware('throttle:20,1')->name('upload');
            Route::delete('{type}/{id}', [TravelGoController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('v1')->name('travelgo.api.')->where(['type' => 'countries|regions|cities|places|routes'])->group(function (): void {
            Route::get('geo/search', [TravelGoGeoController::class, 'search'])->middleware('throttle:12,1')->name('geo.search');
            Route::get('geo/timezone', [TravelGoGeoController::class, 'timeZone'])->middleware('throttle:20,1')->name('geo.timezone');
            Route::get('audit', [TravelGoController::class, 'audit'])->name('audit');
            Route::post('publish', [TravelGoController::class, 'publish'])->name('publish');
            Route::get('countries/{country}/cities', [TravelGoController::class, 'countriesCities'])->name('countries.cities');
            Route::get('cities/{city}/places', [TravelGoController::class, 'cityPlaces'])->name('cities.places');
            Route::put('routes/{id}/stops', [TravelGoController::class, 'stops'])->name('stops');
            Route::post('routes/{id}/path/recalculate', [TravelGoController::class, 'recalculate'])->name('recalculate');
            Route::post('routes/{id}/duplicate', [TravelGoController::class, 'duplicate'])->name('duplicate');
            Route::get('{type}', [TravelGoController::class, 'index'])->name('index');
            Route::post('{type}', [TravelGoController::class, 'store'])->name('store');
            Route::get('{type}/{id}', [TravelGoController::class, 'show'])->name('show');
            Route::patch('{type}/{id}', [TravelGoController::class, 'update'])->name('update');
            Route::post('{type}/{id}/status', [TravelGoController::class, 'status'])->name('status');
            Route::post('{type}/{id}/images', [TravelGoController::class, 'upload'])->middleware('throttle:20,1')->name('upload');
            Route::delete('{type}/{id}', [TravelGoController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('staff')->name('staff.')->middleware('can:staff.manage')->group(function (): void {
            Route::get('/', [StaffController::class, 'index'])->name('index');
            Route::post('/', [StaffController::class, 'store'])->middleware('throttle:8,1')->name('store');
            Route::get('{admin}', [StaffController::class, 'edit'])->name('edit');
            Route::patch('{admin}', [StaffController::class, 'update'])->middleware('throttle:12,1')->name('update');
        });

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
