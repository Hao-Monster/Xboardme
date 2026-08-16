<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Support\Str;

class DistributorAccess
{
    private const ALLOWED_ROUTES = [
        'api/v1/user/info',
        'api/v1/user/checkLogin',
        'api/v1/user/changePassword',
        'api/v1/user/update',
        'api/v1/user/getActiveSession',
        'api/v1/user/removeActiveSession',
        'api/v1/user/logout',
        'api/v1/user/getQuickLoginUrl',
        'api/v1/user/transfer',
        'api/v1/user/plan/fetch',
        'api/v1/user/order/*',
        'api/v1/user/invite/*',
        'api/v1/user/knowledge/*',
        'api/v1/user/client-catalog*',
        'api/v1/user/notice/fetch',
        'api/v1/user/comm/config',
        'api/v1/user/distributor/*',
        'api/v2/user/info',
    ];

    public function handle($request, Closure $next)
    {
        $user = $request->user();
        if (!$user?->is_distributor) {
            return $next($request);
        }

        $uri = ltrim((string) $request->route()?->uri(), '/');
        if (!Str::is(self::ALLOWED_ROUTES, $uri)) {
            throw new ApiException('分销商账号无权访问该功能', 403);
        }

        return $next($request);
    }
}
