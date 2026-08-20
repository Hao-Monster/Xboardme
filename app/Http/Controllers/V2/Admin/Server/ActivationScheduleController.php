<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerActivationSchedule;
use App\Services\ServerActivationScheduleService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ActivationScheduleController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate([
            'server_id' => 'required|integer|exists:v2_server,id',
        ]);
        $server = $this->linkedServer((int) $params['server_id']);
        $schedule = ServerActivationSchedule::query()
            ->where('server_id', $server->id)
            ->first();

        return $this->success($schedule ? $this->serialize($schedule) : null);
    }

    public function save(Request $request, ServerActivationScheduleService $service)
    {
        $params = $request->validate([
            'server_id' => 'required|integer|exists:v2_server,id',
            'enable_at' => 'required|integer|min:1',
            'disable_at' => 'required|integer|gt:enable_at',
        ]);
        $server = $this->linkedServer((int) $params['server_id']);
        if ((int) $params['disable_at'] <= now()->timestamp) {
            throw ValidationException::withMessages([
                'disable_at' => ['关闭时间必须晚于当前时间。'],
            ]);
        }

        $schedule = $service->save(
            $server,
            (int) $params['enable_at'],
            (int) $params['disable_at']
        );

        return $this->success($this->serialize($schedule));
    }

    public function drop(Request $request, ServerActivationScheduleService $service)
    {
        $params = $request->validate([
            'server_id' => 'required|integer|exists:v2_server,id',
        ]);
        $this->linkedServer((int) $params['server_id']);

        return $this->success($service->cancel((int) $params['server_id']));
    }

    private function linkedServer(int $serverId): Server
    {
        $server = Server::query()->findOrFail($serverId);
        if ($server->machine_id === null) {
            throw ValidationException::withMessages([
                'server_id' => ['仅关联到服务器的节点可以设置激活计划。'],
            ]);
        }

        return $server;
    }

    /** @return array<string, int|string|null> */
    private function serialize(ServerActivationSchedule $schedule): array
    {
        $now = now()->timestamp;
        $phase = $now < $schedule->enable_at
            ? 'pending'
            : ($now < $schedule->disable_at ? 'active' : 'completed');

        return [
            'server_id' => $schedule->server_id,
            'enable_at' => $schedule->enable_at,
            'disable_at' => $schedule->disable_at,
            'revision' => $schedule->revision,
            'enabled_applied_at' => $schedule->enabled_applied_at,
            'disabled_applied_at' => $schedule->disabled_applied_at,
            'phase' => $phase,
        ];
    }
}
