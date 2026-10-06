<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Requests from the Next.js origin get session + CSRF (Sanctum SPA auth).
        // Behind a TLS-terminating reverse proxy (Caddy) in local dev: honour X-Forwarded-* so URLs are https.
        // The API port is only published on 127.0.0.1. Restrict TRUSTED_PROXIES in production.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        $middleware->statefulApi();
        $middleware->throttleApi();

        $middleware->alias(['admin' => EnsureUserIsAdmin::class]);

        // API clients get a 401 JSON response instead of a redirect.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Do not leak model/class names in 404 responses.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $message = $e->getPrevious() instanceof ModelNotFoundException ? 'Resource not found.' : 'Endpoint not found.';

                return response()->json(['message' => $message], 404);
            }
        });

        // CSRF token mismatch: the session expired or the page is stale.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getStatusCode() === 419) {
                return response()->json(['message' => 'Your session has expired. Please refresh the page and try again.'], 419);
            }
        });

        // Database unavailable / query failures: friendly message, details only in logs.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->is('api/*') && ! config('app.debug')) {
                return response()->json(['message' => 'The service is temporarily unavailable. Please try again later.'], 503);
            }
        });
    })->create();
