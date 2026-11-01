<?php

namespace App\Http\Middleware;

use App\Support\SubscriptionTiming;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SubscriptionRequestTiming
{
    public function handle(Request $request, Closure $next): mixed
    {
        $timing = SubscriptionTiming::attach($request);
        $response = null;
        $unhandledException = null;

        try {
            $result = $next($request);
            if ($result instanceof Response) {
                $response = $result;
            }

            return $result;
        } catch (Throwable $exception) {
            $unhandledException = $exception;
            throw $exception;
        } finally {
            try {
                $timing->finish($request, $response, $unhandledException);
            } finally {
                SubscriptionTiming::detach($request);
            }
        }
    }
}
