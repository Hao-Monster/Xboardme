<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\SubscriptionTiming;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class Client
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $timing = SubscriptionTiming::forRequest($request);
        $user = $timing !== null
            ? $timing->measure(SubscriptionTiming::STAGE_TOKEN_LOOKUP, fn (): User => $this->resolveUser($request))
            : $this->resolveUser($request);

        Auth::setUser($user);
        return $next($request);
    }

    /**
     * @param \Illuminate\Http\Request $request
     */
    private function resolveUser($request): User
    {
        $token = $request->input('token', $request->route('token'));
        if (empty($token)) {
            throw new ApiException('token is null', 403);
        }
        if (!is_string($token)) {
            throw new ApiException('token is error', 403);
        }
        $user = User::where('token', $token)->first();
        if (!$user) {
            throw new ApiException('token is error', 403);
        }

        return $user;
    }
}
