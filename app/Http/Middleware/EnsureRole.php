<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware: ->middleware('role:admin') or 'role:moderator' (admins also pass a moderator check). */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        $ok = $user && ($role === 'admin' ? $user->isAdmin() : $user->isStaff());
        abort_unless($ok, 403, 'This needs a '.$role.' account.');

        return $next($request);
    }
}
