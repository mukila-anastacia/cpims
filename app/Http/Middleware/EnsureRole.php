<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /** Usage on a route: ->middleware('role:Finance Officer,Head Office Manager') */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole(...$roles)) {
            AuditService::log('ACCESS_DENIED', 'route', null, null, ['path' => $request->path()], $user?->id);
            abort(403, 'You do not have permission to do this.');
        }

        return $next($request);
    }
}