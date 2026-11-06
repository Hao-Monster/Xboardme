<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class RestrictSubscriptionGatewayRoutes
{
    /**
     * Dedicated stateless subscription acceleration gateway CIDRs.
     *
     * @var array<int, string>
     */
    private const SUBSCRIPTION_GATEWAY_CIDRS = [
        '103.133.179.159/32',
    ];

    /**
     * Internal loopback / RFC1918 / link-local hops between Host Caddy, Docker Edge, and Octane.
     *
     * @var array<int, string>
     */
    private const INTERNAL_HOP_RANGES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    /**
     * Allowed read-only HTTP methods from the subscription gateway.
     *
     * @var array<int, string>
     */
    private const ALLOWED_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $externalPeer = $this->resolveImmediateExternalPeer($request);
        if ($externalPeer === null || !IpUtils::checkIp($externalPeer, self::SUBSCRIPTION_GATEWAY_CIDRS)) {
            return $next($request);
        }

        if (!$this->isAllowedGatewayRequest($request)) {
            return response('Forbidden', 403)->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }

    private function resolveImmediateExternalPeer(Request $request): ?string
    {
        $remoteAddr = trim((string) $request->server->get('REMOTE_ADDR', ''));
        if ($remoteAddr === '' || filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if (!IpUtils::checkIp($remoteAddr, self::INTERNAL_HOP_RANGES)) {
            return $remoteAddr;
        }

        $xffRaw = (string) $request->headers->get('X-Forwarded-For', '');
        if ($xffRaw === '') {
            return null;
        }

        $hops = array_reverse(explode(',', $xffRaw));
        foreach ($hops as $hopRaw) {
            $hop = trim($hopRaw);
            if ($hop === '' || filter_var($hop, FILTER_VALIDATE_IP) === false) {
                return null;
            }
            if (!IpUtils::checkIp($hop, self::INTERNAL_HOP_RANGES)) {
                return $hop;
            }
        }

        return null;
    }

    private function isAllowedGatewayRequest(Request $request): bool
    {
        if (!in_array(strtoupper($request->getMethod()), self::ALLOWED_METHODS, true)) {
            return false;
        }

        $rawUri = (string) $request->server->get('REQUEST_URI', '');
        $rawPath = (string) strtok($rawUri !== '' ? $rawUri : $request->getPathInfo(), '?');
        $decodedPath = rawurldecode($rawPath);
        if (str_contains($rawPath, '..') || str_contains($decodedPath, '..')) {
            return false;
        }

        $path = '/' . ltrim($request->getPathInfo(), '/');
        if ($path === '/api/v1/client/subscribe') {
            return true;
        }

        if (str_starts_with($path, '/s/') && strlen($path) > 3) {
            return true;
        }

        $claimPrefix = '/api/v1/client/distributor/claim/';
        if (str_starts_with($path, $claimPrefix) && strlen($path) > strlen($claimPrefix)) {
            return true;
        }

        return false;
    }
}
