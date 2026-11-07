<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\DynamicRouteSynchronizer;
use Closure;
use Illuminate\Http\Request;

class SynchronizeDynamicRoutes
{
    /**
     * Ensure long-lived Octane workers reflect runtime changes to secure_path and subscribe_path
     * before the router dispatches the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        DynamicRouteSynchronizer::synchronize();

        return $next($request);
    }
}
