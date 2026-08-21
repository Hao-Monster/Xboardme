<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Server\DeepbworkController;
use App\Http\Controllers\V1\Server\ShadowsocksTidalabController;
use App\Http\Controllers\V1\Server\TrojanTidalabController;
use App\Http\Controllers\V1\Server\UniProxyController;
use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'server',
        ], function ($router) {
            $router->group([
                'prefix' => 'UniProxy',
            ], function ($route) {
                $route->get('config', [UniProxyController::class, 'config'])
                    ->middleware(['server.body:control', 'throttle:server-pull', 'server']);
                $route->get('user', [UniProxyController::class, 'user'])
                    ->middleware(['server.body:control', 'throttle:server-pull', 'server']);
                $route->post('push', [UniProxyController::class, 'push'])
                    ->middleware(['server.body:report', 'throttle:server-report', 'server']);
                $route->post('alive', [UniProxyController::class, 'alive'])
                    ->middleware(['server.body:report', 'throttle:server-report', 'server']);
                $route->get('alivelist', [UniProxyController::class, 'alivelist'])
                    ->middleware(['server.body:control', 'throttle:server-pull', 'server']);
                $route->post('status', [UniProxyController::class, 'status'])
                    ->middleware(['server.body:control', 'throttle:server-report', 'server']);
            });
            $router->group([
                'prefix' => 'ShadowsocksTidalab',
            ], function ($route) {
                $route->get('user', [ShadowsocksTidalabController::class, 'user'])
                    ->middleware(['server.body:control', 'throttle:server-pull', 'server:shadowsocks']);
                $route->post('submit', [ShadowsocksTidalabController::class, 'submit'])
                    ->middleware(['server.body:report', 'throttle:server-report', 'server:shadowsocks']);
            });
            $router->group([
                'prefix' => 'TrojanTidalab',
            ], function ($route) {
                $route->get('config', [TrojanTidalabController::class, 'config'])
                    ->middleware(['server.body:control', 'throttle:server-pull', 'server:trojan']);
                $route->get('user', [TrojanTidalabController::class, 'user'])
                    ->middleware(['server.body:control', 'throttle:server-pull', 'server:trojan']);
                $route->post('submit', [TrojanTidalabController::class, 'submit'])
                    ->middleware(['server.body:report', 'throttle:server-report', 'server:trojan']);
            });
        });
    }
}
