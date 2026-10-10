<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PageExpiredException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Prepended globally rather than appended to the api group: Laravel's
        // middleware priority sorts auth:sanctum ahead of group middleware, so
        // an appended entry would run too late to affect the auth response.
        $middleware->prepend(\App\Http\Middleware\ForceJsonAccept::class);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdminAccess::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every failure a client sees should read like us talking to a
        // member, never like a framework default (requirement 15).
        $json = fn ($request) => $request->expectsJson() || $request->is('api/*');

        $exceptions->render(function (AuthenticationException $e, $request) use ($json) {
            if ($json($request)) {
                return response()->json(['message' => 'Your session has expired. Please sign in again.'], 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, $request) use ($json) {
            if ($json($request)) {
                return response()->json(['message' => 'You do not have permission to perform this action.'], 403);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) use ($json) {
            if ($json($request)) {
                return response()->json(['message' => 'We could not find what you were looking for.'], 404);
            }
        });

        $exceptions->render(function (PageExpiredException $e, $request) use ($json) {
            if ($json($request)) {
                return response()->json(['message' => 'Your session expired. Please refresh the page and try again.'], 419);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, $request) use ($json) {
            if ($json($request)) {
                return response()->json(['message' => 'Too many attempts. Please wait a moment and try again.'], 429);
            }
        });

        // Method-not-allowed and maintenance-mode responses only; anything
        // else (403, 404, 422...) keeps its own precise handling above.
        $exceptions->render(function (HttpException $e, $request) use ($json) {
            if (! $json($request)) {
                return null;
            }

            $messages = [
                405 => 'That action is not available here.',
                503 => 'The service is temporarily unavailable. Please try again shortly.',
            ];

            $status = $e->getStatusCode();

            return isset($messages[$status])
                ? response()->json(['message' => $messages[$status]], $status)
                : null;
        });
    })->create();
