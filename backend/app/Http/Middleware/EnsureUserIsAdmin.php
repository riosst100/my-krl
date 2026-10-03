<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Must run after auth:admin. Re-checks the role from the database on every
 * request so a demoted admin loses access immediately.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('admin');

        if (! $user?->isAdmin()) {
            Auth::guard('admin')->logout();

            return response()->json(['message' => 'You do not have access to the admin area.'], Response::HTTP_FORBIDDEN);
        }

        // Policies and $request->user() resolve the admin for the rest of the request.
        Auth::shouldUse('admin');

        return $next($request);
    }
}
