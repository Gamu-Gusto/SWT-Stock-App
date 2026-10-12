<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Immediately ends the session of a user who has been deactivated.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Your account has been deactivated. Contact the owner.', 'code' => 'account_inactive'], 401);
        }
        // Accounts with a temporary password may only change it (and sign out) until they do.
        if ($user && $user->must_change_password && ! $request->is('api/v1/auth/*', 'api/v1/lookups')) {
            return response()->json(['message' => 'Please change your temporary password before continuing.', 'code' => 'password_change_required'], 403);
        }

        return $next($request);
    }
}
