<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Diagnostics\DiagnosticDomainException;
use App\Services\Diagnostics\DiagnosticLeaseService;
use App\Services\Diagnostics\DiagnosticQueryService;
use App\Services\Diagnostics\OrderDiagnosticIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class OrderDiagnosticsController extends Controller
{
    /**
     * Allowed keys to retain on the Request object so RequestLog middleware never persists unexpected fields.
     */
    private const ALLOWED_AUDIT_KEYS = [
        'order_id',
        'trade_no',
        'diagnostic_id',
        'from',
        'to',
        'filters',
        'cursor',
        'limit',
        'node_id',
        'runtime_id',
        'connection_id',
        'session_id',
        'idempotency_key',
    ];

    private const FORBIDDEN_IDENTITY_INPUT_KEYS = [
        'uuid',
        'token',
        'subscribe_url',
        'email',
        'user_id',
        'subscriber_user_id',
    ];

    public function __construct(
        private readonly OrderDiagnosticIdentityService $identityService,
        private readonly DiagnosticLeaseService $leaseService,
        private readonly DiagnosticQueryService $queryService
    ) {
    }

    /**
     * POST /api/v2/<secure_path>/diagnostics/initialize
     */
    public function initialize(Request $request): JsonResponse
    {
        return $this->executeAction($request, function (User $admin) use ($request): array {
            $this->sanitizeRequestForAudit($request, []);
            return $this->identityService->initializePanelScope();
        });
    }

    /**
     * POST /api/v2/<secure_path>/order/diagnostics/lookup
     */
    public function lookup(Request $request): JsonResponse
    {
        return $this->executeAction($request, function (User $admin) use ($request): array {
            $input = $request->all();
            $this->rejectForbiddenIdentityInputs($input);
            $this->sanitizeRequestForAudit($request, ['order_id', 'trade_no']);

            return $this->identityService->lookupOrder($input);
        });
    }

    /**
     * POST /api/v2/<secure_path>/order/diagnostics/query
     */
    public function query(Request $request): JsonResponse
    {
        return $this->executeAction($request, function (User $admin) use ($request): array {
            $input = $request->all();
            $this->rejectForbiddenIdentityInputs($input);
            $this->sanitizeRequestForAudit($request, ['order_id', 'diagnostic_id', 'from', 'to', 'filters', 'cursor', 'limit']);

            return $this->queryService->queryConnections((int) $admin->id, $input);
        });
    }

    /**
     * POST /api/v2/<secure_path>/order/diagnostics/connection
     */
    public function connection(Request $request): JsonResponse
    {
        return $this->executeAction($request, function (User $admin) use ($request): array {
            $input = $request->all();
            $this->rejectForbiddenIdentityInputs($input);
            $this->sanitizeRequestForAudit($request, [
                'order_id',
                'diagnostic_id',
                'node_id',
                'runtime_id',
                'connection_id',
                'from',
                'to',
                'cursor',
            ]);

            return $this->queryService->queryConnectionDetail((int) $admin->id, $input);
        });
    }

    /**
     * POST /api/v2/<secure_path>/order/diagnostics/start
     */
    public function start(Request $request): JsonResponse
    {
        return $this->executeAction($request, function (User $admin) use ($request): array {
            $input = $request->all();
            $this->rejectForbiddenIdentityInputs($input);

            if (array_key_exists('diagnostic_id', $input)) {
                throw new DiagnosticDomainException(
                    'lease_requires_order_mode',
                    '只读客户日志 ID 模式不允许开启详细诊断租约，必须绑定订单 order_id',
                    422
                );
            }

            $orderId = $this->requireOrderId($input);
            $idempotencyKey = isset($input['idempotency_key']) && is_string($input['idempotency_key'])
                ? $input['idempotency_key']
                : '';

            $this->sanitizeRequestForAudit($request, ['order_id', 'idempotency_key']);
            return $this->leaseService->startLease((int) $admin->id, $orderId, $idempotencyKey);
        });
    }

    /**
     * POST /api/v2/<secure_path>/order/diagnostics/stop
     */
    public function stop(Request $request): JsonResponse
    {
        return $this->executeAction($request, function (User $admin) use ($request): array {
            $input = $request->all();
            $this->rejectForbiddenIdentityInputs($input);

            if (array_key_exists('diagnostic_id', $input)) {
                throw new DiagnosticDomainException(
                    'lease_requires_order_mode',
                    '只读客户日志 ID 模式不允许操作详细诊断租约，必须绑定订单 order_id',
                    422
                );
            }

            $orderId = $this->requireOrderId($input);
            $sessionId = isset($input['session_id']) && is_string($input['session_id'])
                ? $input['session_id']
                : '';
            $idempotencyKey = isset($input['idempotency_key']) && is_string($input['idempotency_key'])
                ? $input['idempotency_key']
                : '';

            $this->sanitizeRequestForAudit($request, ['order_id', 'session_id', 'idempotency_key']);
            return $this->leaseService->stopLease((int) $admin->id, $orderId, $sessionId, $idempotencyKey);
        });
    }

    /**
     * GET /api/v2/<secure_path>/order/diagnostics/status
     */
    public function status(Request $request): JsonResponse
    {
        return $this->executeAction($request, function (User $admin) use ($request): array {
            $input = $request->all();
            $this->rejectForbiddenIdentityInputs($input);

            if (array_key_exists('diagnostic_id', $input)) {
                throw new DiagnosticDomainException(
                    'lease_requires_order_mode',
                    '只读客户日志 ID 模式不支持租约状态查询，必须提供 order_id',
                    422
                );
            }

            $orderId = $this->requireOrderId($input);
            return $this->leaseService->getOrderStatus($orderId);
        });
    }

    /**
     * @param callable(User): array<string, mixed> $callback
     */
    private function executeAction(Request $request, callable $callback): JsonResponse
    {
        /** @var User|null $admin */
        $admin = Auth::guard('sanctum')->user() ?? $request->user();
        if (!$admin || !$admin->is_admin) {
            return $this->fail(
                [403000, 'Unauthorized'],
                null,
                ['code' => 'admin_unauthorized']
            );
        }

        try {
            $data = $callback($admin);
            return $this->success($data);
        } catch (DiagnosticDomainException $e) {
            return $this->fail(
                [$e->httpStatus() * 1000, $e->getMessage()],
                null,
                array_merge(['code' => $e->errorCode()], $e->context())
            );
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requireOrderId(array $input): int
    {
        $raw = $input['order_id'] ?? null;
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/\A[1-9]\d*\z/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        throw new DiagnosticDomainException(
            'invalid_order_id',
            'order_id 必须为正整数',
            422
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function rejectForbiddenIdentityInputs(array $input): void
    {
        foreach (self::FORBIDDEN_IDENTITY_INPUT_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                throw new DiagnosticDomainException(
                    'forbidden_identity_parameter',
                    sprintf('禁止通过敏感客户参数 %s 查询或操作连接诊断', $key),
                    422
                );
            }
        }
    }

    /**
     * @param list<string> $allowedKeys
     */
    private function sanitizeRequestForAudit(Request $request, array $allowedKeys): void
    {
        $whitelist = array_intersect($allowedKeys, self::ALLOWED_AUDIT_KEYS);
        $safe = [];
        foreach ($whitelist as $key) {
            if ($request->has($key)) {
                $safe[$key] = $request->input($key);
            }
        }
        $request->replace($safe);
    }
}
