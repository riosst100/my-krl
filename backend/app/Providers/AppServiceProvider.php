<?php

namespace App\Providers;

use App\Services\Kci\Clients\HttpKciClient;
use App\Services\Kci\Clients\MockKciClient;
use App\Services\Kci\Contracts\KciClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap the KCI data source without touching the rest of the app.
        $this->app->bind(KciClient::class, function () {
            $config = config('kci');
            $driver = $config['driver'] === 'auto'
                ? ($config['base_url'] ? 'http' : 'mock')
                : $config['driver'];

            return $driver === 'http'
                ? new HttpKciClient(
                    baseUrl: rtrim((string) $config['base_url'], '/'),
                    apiKey: $config['api_key'],
                    timeout: $config['timeout'],
                    retries: $config['retries'],
                    endpoints: $config['endpoints'],
                )
                : new MockKciClient;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Brute-force protection for login/register (per email + IP, and per IP).
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // Manual import (pasted KCI JSON): limit each endpoint separately per admin.
        RateLimiter::for('admin-sync', fn (Request $request) => Limit::perMinute(5)
            ->by(($request->user()?->id ?: $request->ip()).'|'.$request->path()));
    }
}
