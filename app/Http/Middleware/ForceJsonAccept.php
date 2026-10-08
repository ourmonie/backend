<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRD 40/45 - the API is consumed by a React client and (later) a Flutter app,
 * never a browser address bar.
 *
 * Every request is treated as JSON so that authentication failures return 401
 * and validation failures return 422, instead of Laravel falling back to a
 * redirect to a web `login` route that does not exist in this application.
 */
class ForceJsonAccept
{
    public function handle(Request $request, Closure $next): Response
    {
        // API paths only - the SPA and the exception pages stay HTML.
        if ($request->is('api/*')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
