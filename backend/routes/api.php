<?php

use App\Http\Controllers\Api\V1;
use App\Http\Controllers\Api\V1\Admin;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {

    // --- Authentication (website: cookie session via Sanctum) -------------
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [V1\AuthController::class, 'register'])->name('register');
            Route::post('login', [V1\AuthController::class, 'login'])->name('login');
            // Mobile clients: bearer tokens instead of cookies.
            Route::post('token', [V1\TokenController::class, 'store'])->name('token.store');
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [V1\AuthController::class, 'logout'])->name('logout');
            Route::get('me', [V1\AuthController::class, 'me'])->name('me');
            Route::delete('token', [V1\TokenController::class, 'destroy'])->name('token.destroy');
        });
    });

    // --- Signed-in user ----------------------------------------------------
    Route::middleware('auth:sanctum')->prefix('me')->name('me.')->group(function () {
        Route::get('favorite-routes', [V1\FavoriteRouteController::class, 'index'])->name('favorite-routes.index');
        Route::put('favorite-routes', [V1\FavoriteRouteController::class, 'update'])->name('favorite-routes.update');
        Route::get('favorite-routes/departures', [V1\FavoriteRouteController::class, 'departures'])->name('favorite-routes.departures');
    });

    // --- Public data -------------------------------------------------------
    Route::get('stations', [V1\StationController::class, 'index'])->name('stations.index');
    Route::get('stations/{station}', [V1\StationController::class, 'show'])->name('stations.show');
    Route::get('stations/{station}/schedules', [V1\ScheduleController::class, 'station'])->name('stations.schedules');
    Route::get('stations/{station}/destinations', [V1\ScheduleController::class, 'destinations'])->name('stations.destinations');
    Route::get('schedules', [V1\ScheduleController::class, 'index'])->name('schedules.index');
    Route::get('schedules/dates', [V1\ScheduleController::class, 'dates'])->name('schedules.dates');
    Route::get('schedules/next', [V1\ScheduleController::class, 'next'])->name('schedules.next');
    Route::get('schedules/trains/{trainNumber}/stops', [V1\ScheduleController::class, 'trainStops'])->where('trainNumber', '[A-Za-z0-9]+')->name('schedules.train-stops');
    Route::get('schedules/upcoming', [V1\ScheduleController::class, 'upcoming'])->name('schedules.upcoming');

    // --- Data pushed by krl-sync on Vercel (shared-secret token, see SYNC_INGEST_TOKEN) --
    Route::prefix('ingest')->name('ingest.')->middleware('ingest')->group(function () {
        Route::get('config', [V1\IngestController::class, 'config'])->name('config');
        Route::post('start', [V1\IngestController::class, 'start'])->name('start');
        Route::post('stations', [V1\IngestController::class, 'stations'])->name('stations');
        Route::post('schedules', [V1\IngestController::class, 'schedules'])->name('schedules');
        Route::post('stops', [V1\IngestController::class, 'stops'])->name('stops');
        Route::post('progress', [V1\IngestController::class, 'progress'])->name('progress');
        Route::post('finish', [V1\IngestController::class, 'finish'])->name('finish');
        Route::get('runs/latest', [V1\IngestController::class, 'latest'])->name('runs.latest');
    });

    // --- Admin (separate "admin" session guard) ----------------------------
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::post('auth/login', [Admin\AuthController::class, 'login'])->middleware('throttle:auth')->name('auth.login');

        Route::middleware(['auth:admin', 'admin'])->group(function () {
            Route::post('auth/logout', [Admin\AuthController::class, 'logout'])->name('auth.logout');
            Route::get('auth/me', [Admin\AuthController::class, 'me'])->name('auth.me');

            Route::get('dashboard', Admin\DashboardController::class)->name('dashboard');

            Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [Admin\UserController::class, 'show'])->name('users.show');
            Route::patch('users/{user}/role', [Admin\UserController::class, 'updateRole'])->name('users.role');

            Route::get('stations', [Admin\StationController::class, 'index'])->name('stations.index');
            Route::get('settings/sync-stations', [Admin\SyncStationsController::class, 'show'])->name('settings.sync-stations.show');
            Route::put('settings/sync-stations', [Admin\SyncStationsController::class, 'update'])->name('settings.sync-stations.update');
            Route::delete('settings/sync-stations', [Admin\SyncStationsController::class, 'reset'])->name('settings.sync-stations.reset');
            Route::put('settings/ingest-auto-sync', [Admin\SyncStationsController::class, 'autoSync'])->name('settings.ingest-auto-sync.update');
            Route::get('stations/{station:id}', [Admin\StationController::class, 'show'])->whereNumber('station')->name('stations.show');
            Route::patch('stations/{station:id}', [Admin\StationController::class, 'update'])->whereNumber('station')->name('stations.update');

            Route::get('schedules', [Admin\ScheduleController::class, 'index'])->name('schedules.index');

            Route::get('sync-logs', [Admin\SyncController::class, 'index'])->name('sync.index');
            Route::get('sync-logs/{syncLog}', [Admin\SyncController::class, 'show'])->name('sync.show');
            Route::post('sync/import', [Admin\SyncController::class, 'import'])->middleware('throttle:admin-sync')->name('sync.import');
        });
    });
});
