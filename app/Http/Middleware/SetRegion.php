<?php

namespace App\Http\Middleware;

use App\Support\Region;
use Closure;
use Illuminate\Http\Request;

/** Pins every catalogue query in this request to the signed-in user's region. */
class SetRegion
{
    public function handle(Request $request, Closure $next)
    {
        Region::set($request->user()?->region);

        return $next($request);
    }
}
