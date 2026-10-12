<?php

use App\Exceptions\InventoryException;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetupFileFallback;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Public, session-less, rate-limited endpoints for the public website (disabled by default).
            Route::middleware('throttle:public-api')->prefix('api/public')->group(base_path('routes/public.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The private API serves only the same-origin SPA, so it always uses the encrypted session
        // cookie and CSRF protection (no API tokens, no cross-site access).
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ValidateCsrfToken::class,
        ]);
        $middleware->append(SecurityHeaders::class);
        $middleware->prepend(SetupFileFallback::class);
        $middleware->alias(['active' => EnsureUserIsActive::class]);
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ?: null);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->expectsJson() ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation']);

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $message = $e->getPrevious() instanceof ModelNotFoundException ? 'The requested record was not found.' : 'Not found.';

                return response()->json(['message' => $message, 'code' => 'not_found'], 404);
            }
        });
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $message = $e->getPrevious() instanceof AuthorizationException && $e->getMessage() !== 'This action is unauthorized.'
                    ? $e->getMessage() : 'You do not have permission to do this.';

                return response()->json(['message' => $message, 'code' => 'forbidden'], 403);
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Your session has expired. Please sign in again.', 'code' => 'unauthenticated'], 401);
            }
        });
        $exceptions->render(function (Throwable $e, Request $request) {
            // Never leak internals in production API responses.
            if ($request->is('api/*') && ! config('app.debug') && ! $e instanceof HttpExceptionInterface
                && ! $e instanceof ValidationException && ! $e instanceof InventoryException) {
                return response()->json(['message' => 'Something went wrong. The error has been logged; please try again.', 'code' => 'server_error'], 500);
            }
        });
    })->create();
