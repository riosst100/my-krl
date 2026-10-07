<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the endpoints that receive data pushed from the local machine: the
 * request must carry the shared secret (SYNC_INGEST_TOKEN) as a bearer token.
 * Without a configured secret the receiving API is switched off.
 */
class EnsureIngestToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('kci.ingest_token');

        if ($expected === '') {
            return response()->json(['message' => 'Data ingestion is not enabled on this server.'], 403);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Invalid ingest token.'], 401);
        }

        return $next($request);
    }
}
