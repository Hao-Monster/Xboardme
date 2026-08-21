<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V1\Server\ShadowsocksTidalabController;
use App\Http\Controllers\V1\Server\TrojanTidalabController;
use App\Http\Controllers\V1\Server\UniProxyController;
use App\Http\Controllers\V2\Server\ServerController;
use App\Http\Controllers\V2\Server\MachineController;
use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'server',
        ], function ($route) {
            $route->match(['GET', 'POST'], 'handshake', [ServerController::class, 'handshake'])
                ->middleware(['server.body:handshake', 'throttle:server-handshake', 'server.v2']);
            $route->post('report', [ServerController::class, 'report'])
                ->middleware(['server.body:report', 'throttle:server-report', 'server.v2']);
            $route->get('config', [UniProxyController::class, 'config'])
                ->middleware(['server.body:control', 'throttle:server-pull', 'server.v2']);
            $route->get('user', [UniProxyController::class, 'user'])
                ->middleware(['server.body:control', 'throttle:server-pull', 'server.v2']);
            $route->post('push', [UniProxyController::class, 'push'])
                ->middleware(['server.body:report', 'throttle:server-report', 'server.v2']);
            $route->post('alive', [UniProxyController::class, 'alive'])
                ->middleware(['server.body:report', 'throttle:server-report', 'server.v2']);
            $route->get('alivelist', [UniProxyController::class, 'alivelist'])
                ->middleware(['server.body:control', 'throttle:server-pull', 'server.v2']);
            $route->post('status', [UniProxyController::class, 'status'])
                ->middleware(['server.body:control', 'throttle:server-report', 'server.v2']);
        });

        $router->group([
            'prefix' => 'server/machine',
        ], function ($route) {
            $route->post('enroll', [MachineController::class, 'enroll'])
                ->middleware(['server.body:handshake', 'throttle:server-handshake']);
            $route->post('nodes', [MachineController::class, 'nodes'])
                ->middleware(['server.body:control', 'throttle:server-machine']);
            $route->post('status', [MachineController::class, 'status'])
                ->middleware(['server.body:control', 'throttle:server-machine']);
        });
    }
}
