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
            Route::get('settings/stations-api', [Admin\StationsApiController::class, 'show'])->name('settings.stations-api.show');
            Route::put('settings/stations-api', [Admin\StationsApiController::class, 'update'])->name('settings.stations-api.update');
            Route::delete('settings/stations-api', [Admin\StationsApiController::class, 'reset'])->name('settings.stations-api.reset');
            Route::get('settings/sync-stations', [Admin\SyncStationsController::class, 'show'])->name('settings.sync-stations.show');
            Route::put('settings/sync-stations', [Admin\SyncStationsController::class, 'update'])->name('settings.sync-stations.update');
            Route::delete('settings/sync-stations', [Admin\SyncStationsController::class, 'reset'])->name('settings.sync-stations.reset');
            Route::get('settings/auto-sync', [Admin\AutoSyncController::class, 'show'])->name('settings.auto-sync.show');
            Route::put('settings/auto-sync', [Admin\AutoSyncController::class, 'update'])->name('settings.auto-sync.update');
            Route::delete('settings/auto-sync', [Admin\AutoSyncController::class, 'reset'])->name('settings.auto-sync.reset');
            Route::get('settings/schedules-api', [Admin\SchedulesApiController::class, 'show'])->name('settings.schedules-api.show');
            Route::put('settings/schedules-api', [Admin\SchedulesApiController::class, 'update'])->name('settings.schedules-api.update');
            Route::delete('settings/schedules-api', [Admin\SchedulesApiController::class, 'reset'])->name('settings.schedules-api.reset');
            Route::post('settings/schedules-api/test', [Admin\SchedulesApiController::class, 'test'])->middleware('throttle:admin-sync')->name('settings.schedules-api.test');
            Route::get('settings/train-stops-api', [Admin\TrainStopsApiController::class, 'show'])->name('settings.train-stops-api.show');
            Route::put('settings/train-stops-api', [Admin\TrainStopsApiController::class, 'update'])->name('settings.train-stops-api.update');
            Route::delete('settings/train-stops-api', [Admin\TrainStopsApiController::class, 'reset'])->name('settings.train-stops-api.reset');
            Route::post('settings/train-stops-api/test', [Admin\TrainStopsApiController::class, 'test'])->middleware('throttle:admin-sync')->name('settings.train-stops-api.test');
            Route::post('settings/stations-api/test', [Admin\StationsApiController::class, 'test'])->middleware('throttle:admin-sync')->name('settings.stations-api.test');
            Route::get('stations/{station:id}', [Admin\StationController::class, 'show'])->whereNumber('station')->name('stations.show');
            Route::patch('stations/{station:id}', [Admin\StationController::class, 'update'])->whereNumber('station')->name('stations.update');

            Route::get('schedules', [Admin\ScheduleController::class, 'index'])->name('schedules.index');

            Route::get('sync-logs', [Admin\SyncController::class, 'index'])->name('sync.index');
            Route::get('sync-logs/{syncLog}', [Admin\SyncController::class, 'show'])->name('sync.show');
            Route::get('sync-logs/{syncLog}/requests', [Admin\SyncController::class, 'requests'])->name('sync.requests');
            Route::get('kci-watch', [Admin\SyncController::class, 'watch'])->name('kci-watch');
            Route::post('sync/import', [Admin\SyncController::class, 'import'])->middleware('throttle:admin-sync')->name('sync.import');
            Route::post('sync/kci', [Admin\SyncController::class, 'kci'])->middleware('throttle:admin-sync')->name('sync.kci');
        });
    });
});
