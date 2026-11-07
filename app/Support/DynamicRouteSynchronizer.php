<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use WeakMap;

final class DynamicRouteSynchronizer
{
    public const METADATA_KEY = 'xboard_dynamic_route';
    public const KIND_ADMIN_WEB = 'admin_web';
    public const KIND_ADMIN_API_V2 = 'admin_api_v2';
    public const KIND_CLIENT_SUBSCRIBE = 'client_subscribe';

    /**
     * @var WeakMap<RouteCollection, array{secure_path: string, subscribe_path: string}>|null
     */
    private static ?WeakMap $compiledSignatures = null;

    private static bool $synchronizing = false;

    public static function resolveSecurePath(): string
    {
        $defaultFallback = hash('crc32b', (string) config('app.key'));
        $legacyDefault = self::normalizeSegment(admin_setting('frontend_admin_path', $defaultFallback));
        $effectiveDefault = $legacyDefault !== '' ? $legacyDefault : $defaultFallback;
        $normalized = self::normalizeSegment(admin_setting('secure_path', $effectiveDefault));

        return $normalized !== '' ? $normalized : $effectiveDefault;
    }

    public static function resolveSubscribePath(): string
    {
        $normalized = self::normalizeSegment(admin_setting('subscribe_path', 's'));

        return $normalized !== '' ? $normalized : 's';
    }

    private static function normalizeSegment(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value, "/ \t\n\r\0\x0B");
        }

        return '';
    }

    public static function synchronize(?Container $app = null): void
    {
        if (self::$synchronizing) {
            return;
        }

        self::$synchronizing = true;
        try {
            $container = $app ?? app();
            if (!$container->bound('router')) {
                return;
            }

            /** @var Router $router */
            $router = $container->make('router');

            $routes = $router->getRoutes();
            if (!$routes instanceof RouteCollection || $routes->count() === 0) {
                return;
            }

            $desiredSecurePath = self::resolveSecurePath();
            $desiredSubscribePath = self::resolveSubscribePath();

            $map = self::$compiledSignatures ??= new WeakMap();
            if (isset($map[$routes])) {
                $recorded = $map[$routes];
                if (
                    $recorded['secure_path'] === $desiredSecurePath
                    && $recorded['subscribe_path'] === $desiredSubscribePath
                ) {
                    return;
                }
                $currentSecurePath = $recorded['secure_path'];
                $currentSubscribePath = $recorded['subscribe_path'];
            } else {
                [$currentSecurePath, $currentSubscribePath] = self::inspectCurrentPaths($routes);
                if ($currentSecurePath === null && $currentSubscribePath === null) {
                    return;
                }

                $currentSecurePath ??= $desiredSecurePath;
                $currentSubscribePath ??= $desiredSubscribePath;

                if (
                    $currentSecurePath === $desiredSecurePath
                    && $currentSubscribePath === $desiredSubscribePath
                ) {
                    $map[$routes] = [
                        'secure_path' => $desiredSecurePath,
                        'subscribe_path' => $desiredSubscribePath,
                    ];
                    return;
                }
            }

            $rebuilt = new RouteCollection();
            $oldAdminApiPrefix = 'api/v2/' . $currentSecurePath;
            $newAdminApiPrefix = 'api/v2/' . $desiredSecurePath;

            foreach ($routes->getRoutes() as $route) {
                $kind = $route->action['metadata'][self::METADATA_KEY] ?? null;

                if ($kind === self::KIND_ADMIN_WEB) {
                    self::rebindRouteUri($route, $desiredSecurePath);
                } elseif ($kind === self::KIND_CLIENT_SUBSCRIBE) {
                    self::rebindRouteUri($route, $desiredSubscribePath . '/{token}');
                } elseif ($kind === self::KIND_ADMIN_API_V2 && $currentSecurePath !== '') {
                    $uri = $route->uri();
                    if ($uri === $oldAdminApiPrefix) {
                        self::rebindRouteUri($route, $newAdminApiPrefix);
                    } elseif (str_starts_with($uri, $oldAdminApiPrefix . '/')) {
                        $suffix = substr($uri, strlen($oldAdminApiPrefix) + 1);
                        self::rebindRouteUri($route, $newAdminApiPrefix . '/' . $suffix);
                    }

                    $prefix = (string) ($route->action['prefix'] ?? '');
                    if ($prefix === $oldAdminApiPrefix) {
                        $route->action['prefix'] = $newAdminApiPrefix;
                    } elseif (str_starts_with($prefix, $oldAdminApiPrefix . '/')) {
                        $route->action['prefix'] = $newAdminApiPrefix . '/' . substr($prefix, strlen($oldAdminApiPrefix) + 1);
                    }
                }

                $rebuilt->add($route);
            }

            $rebuilt->refreshNameLookups();
            $rebuilt->refreshActionLookups();
            $router->setRoutes($rebuilt);

            if ($container->resolved('url')) {
                $container->make('url')->setRoutes($rebuilt);
            }

            $map[$rebuilt] = [
                'secure_path' => $desiredSecurePath,
                'subscribe_path' => $desiredSubscribePath,
            ];
        } finally {
            self::$synchronizing = false;
        }
    }

    private static function rebindRouteUri(Route $route, string $uri): void
    {
        $route->setUri($uri);
        $route->parameterNames = null;
        $route->computedMiddleware = null;
        $route->compiled = $route->toSymfonyRoute()->compile();
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function inspectCurrentPaths(RouteCollection $routes): array
    {
        $securePath = null;
        $subscribePath = null;

        foreach ($routes->getRoutes() as $route) {
            $kind = $route->action['metadata'][self::METADATA_KEY] ?? null;
            if ($kind === self::KIND_ADMIN_WEB && $securePath === null) {
                $securePath = trim($route->uri(), '/');
            } elseif ($kind === self::KIND_CLIENT_SUBSCRIBE && $subscribePath === null) {
                $uri = trim($route->uri(), '/');
                if (str_ends_with($uri, '/{token}')) {
                    $subscribePath = substr($uri, 0, -strlen('/{token}'));
                }
            }

            if ($securePath !== null && $subscribePath !== null) {
                break;
            }
        }

        return [$securePath, $subscribePath];
    }
}
