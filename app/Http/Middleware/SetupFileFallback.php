<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one-time /setup page runs before any database tables exist, so its session and rate-limit
 * cache must not depend on the database. Registered as a global middleware (before StartSession).
 */
class SetupFileFallback
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('setup')) {
            config(['session.driver' => 'file', 'cache.default' => 'file']);
        }

        return $next($request);
    }
}
