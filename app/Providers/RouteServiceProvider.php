<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureServerRateLimiting();

        // HTTPS scheme is forced per-request via middleware (Octane-safe).
        parent::boot();
    }

    private function configureServerRateLimiting(): void
    {
        foreach (['handshake', 'pull', 'report', 'machine'] as $profile) {
            RateLimiter::for("server-{$profile}", function (Request $request) use ($profile) {
                $ip = $request->ip() ?: 'unknown';
                $peer = (string) ($request->server('REMOTE_ADDR') ?: 'unknown');
                $credential = hash('sha256', implode('|', [
                    $request->input('machine_id', ''),
                    $request->input('node_id', ''),
                    (string) ($request->bearerToken() ?: $request->input('token', '')),
                ]));

                return [
                    Limit::perMinute(max(1, (int) config("server_security.rate_limits.{$profile}.per_ip")))
                        ->by("server:{$profile}:ip:{$ip}"),
                    Limit::perMinute(max(1, (int) config("server_security.rate_limits.{$profile}.per_peer")))
                        ->by("server:{$profile}:peer:{$peer}"),
                    Limit::perMinute(max(1, (int) config("server_security.rate_limits.{$profile}.per_credential")))
                        ->by("server:{$profile}:credential:{$credential}"),
                ];
            });
        }
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();

        //
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::group([
            'prefix' => '/api/v1',
            'middleware' => 'api',
            'namespace' => $this->namespace
        ], function ($router) {
            foreach (glob(app_path('Http//Routes//V1') . '/*.php') as $file) {
                $this->app->make('App\\Http\\Routes\\V1\\' . basename($file, '.php'))->map($router);
            }
        });


        Route::group([
            'prefix' => '/api/v2',
            'middleware' => 'api',
            'namespace' => $this->namespace
        ], function ($router) {
            foreach (glob(app_path('Http//Routes//V2') . '/*.php') as $file) {
                $this->app->make('App\\Http\\Routes\\V2\\' . basename($file, '.php'))->map($router);
            }
        });
    }
}
