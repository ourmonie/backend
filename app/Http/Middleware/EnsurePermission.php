<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRD 31 - granular permission gate.
 *
 * Usage: ->middleware('permission:kyc.review')
 *        ->middleware('permission:report.view,report.export')  (any-of)
 *
 * Permissions are rows in the `permissions` table and are assigned per role,
 * so none of them are hardcoded into the route definitions beyond the code
 * they name.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $abilities = array_values(array_filter($abilities));

        if ($abilities === []) {
            return $next($request);
        }

        $granted = $user->hasRole(Role::SUPER_ADMIN)
            || collect($abilities)->contains(fn (string $ability) => $user->hasPermission($ability));

        if (! $granted) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'required' => $abilities,
            ], 403);
        }

        return $next($request);
    }
}
