<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $allowed = collect(explode('|', $permission))->contains(fn (string $candidate): bool => $request->user()?->hasPermission($candidate));
        abort_unless($allowed, 403);

        return $next($request);
    }
}
