<?php

namespace App\Http\Requests\Server;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ServerReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userLimit = (int) config('server_security.report_limits.users', 100000);
        $deviceLimit = (int) config('server_security.report_limits.devices_per_user', 64);
        $cpuCoreLimit = (int) config('server_security.report_limits.cpu_cores', 1024);

        return [
            'report_id' => 'sometimes|required|uuid',

            'traffic' => "nullable|array|max:{$userLimit}",
            'traffic.*' => 'bail|required|array|size:2',
            'traffic.*.0' => 'bail|required|integer|min:0',
            'traffic.*.1' => 'bail|required|integer|min:0',

            'alive' => "nullable|array|max:{$userLimit}",
            'alive.*' => "bail|required|array|max:{$deviceLimit}",
            'alive.*.*' => 'bail|required|string|ip',

            'online' => "nullable|array|max:{$userLimit}",
            'online.*' => 'bail|required|integer|min:0|max:1000000',

            'status' => 'nullable|array',
            'status.cpu' => 'required_with:status|numeric|min:0|max:100',
            'status.mem' => 'required_with:status|array',
            'status.mem.total' => 'required_with:status|integer|min:0',
            'status.mem.used' => 'required_with:status|integer|min:0',
            'status.swap' => 'required_with:status|array',
            'status.swap.total' => 'required_with:status|integer|min:0',
            'status.swap.used' => 'required_with:status|integer|min:0',
            'status.disk' => 'required_with:status|array',
            'status.disk.total' => 'required_with:status|integer|min:0',
            'status.disk.used' => 'required_with:status|integer|min:0',
            'status.kernel_status' => 'sometimes|nullable',

            'metrics' => 'nullable|array',
            'metrics.uptime' => 'sometimes|integer|min:0',
            'metrics.goroutines' => 'sometimes|integer|min:0',
            'metrics.active_connections' => 'sometimes|integer|min:0',
            'metrics.total_connections' => 'sometimes|integer|min:0',
            'metrics.total_users' => 'sometimes|integer|min:0',
            'metrics.active_users' => 'sometimes|integer|min:0',
            'metrics.inbound_speed' => 'sometimes|integer|min:0',
            'metrics.outbound_speed' => 'sometimes|integer|min:0',
            'metrics.cpu_per_core' => "sometimes|array|max:{$cpuCoreLimit}",
            'metrics.cpu_per_core.*' => 'numeric|min:0|max:100',
            'metrics.load' => 'sometimes|array',
            'metrics.load.load1' => 'sometimes|numeric|min:0',
            'metrics.load.load5' => 'sometimes|numeric|min:0',
            'metrics.load.load15' => 'sometimes|numeric|min:0',
            'metrics.speed_limiter' => 'sometimes|array',
            'metrics.speed_limiter.has_limits' => 'sometimes|boolean',
            'metrics.speed_limiter.limited_users' => 'sometimes|integer|min:0',
            'metrics.gc' => 'sometimes|array',
            'metrics.gc.num_gc' => 'sometimes|integer|min:0',
            'metrics.gc.last_pause_ms' => 'sometimes|numeric|min:0',
            'metrics.api' => 'sometimes|array',
            'metrics.api.success' => 'sometimes|integer|min:0',
            'metrics.api.failure' => 'sometimes|integer|min:0',
            'metrics.ws' => 'sometimes|array',
            'metrics.ws.enabled' => 'sometimes|boolean',
            'metrics.ws.connected' => 'sometimes|boolean',
            'metrics.limits' => 'sometimes|array',
            'metrics.limits.device_limit_events' => 'sometimes|integer|min:0',
            'metrics.limits.speed_limited_users' => 'sometimes|integer|min:0',
            'metrics.kernel_status' => 'sometimes|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (['traffic', 'alive', 'online'] as $field) {
                $values = $this->input($field);
                if (!is_array($values)) {
                    continue;
                }

                foreach (array_keys($values) as $userId) {
                    $normalized = (string) $userId;
                    if (!ctype_digit($normalized) || (int) $normalized <= 0) {
                        $validator->errors()->add("{$field}.{$normalized}", 'The user id must be a positive integer.');
                    }
                }
            }
        });
    }
}
