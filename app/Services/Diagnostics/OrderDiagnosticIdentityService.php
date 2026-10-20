<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use App\Models\Order;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use App\Support\Setting as SettingSupport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class OrderDiagnosticIdentityService
{
    public const SETTING_PANEL_SCOPE = 'diagnostics_panel_scope';

    /**
     * Read panel_scope without generating any temporary fallback.
     */
    public function getPanelScope(): ?string
    {
        try {
            $raw = Setting::query()
                ->where('name', self::SETTING_PANEL_SCOPE)
                ->value('value');
        } catch (\Throwable $e) {
            throw new DiagnosticDomainException(
                'diagnostics_scope_read_failed',
                '无法读取诊断范围配置',
                503
            );
        }

        if ($raw === null) {
            return null;
        }

        $scope = is_string($raw) ? trim($raw) : '';
        if ($scope === '') {
            return null;
        }

        if (!DiagnosticContract::isValidPanelScope((string) $raw)) {
            throw new DiagnosticDomainException(
                'invalid_panel_scope',
                '诊断面板范围配置格式无效',
                503
            );
        }

        return (string) $raw;
    }

    /**
     * Require a valid initialized panel_scope or fail closed.
     */
    public function requirePanelScope(): string
    {
        $scope = $this->getPanelScope();
        if ($scope === null) {
            throw new DiagnosticDomainException(
                'diagnostics_not_initialized',
                '客户连接诊断尚未初始化 panel_scope',
                409
            );
        }

        return $scope;
    }

    /**
     * Idempotently initialize diagnostics_panel_scope using unique constraint on v2_settings.name.
     *
     * @return array{panel_scope: string, created: bool}
     */
    public function initializePanelScope(): array
    {
        $existing = $this->getPanelScope();
        if ($existing !== null) {
            return [
                'panel_scope' => $existing,
                'created' => false,
            ];
        }

        $candidate = strtolower((string) Str::uuid());
        $now = now();

        $inserted = DB::table('v2_settings')->insertOrIgnore([
            'group' => 'diagnostics',
            'type' => 'string',
            'name' => self::SETTING_PANEL_SCOPE,
            'value' => $candidate,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            Cache::store(config('cache.settings_store', 'redis'))->forget(SettingSupport::CACHE_KEY);
        } catch (\Throwable) {
            // Ignore cache store flush errors when using non-default test stores
        }

        $persisted = $this->requirePanelScope();
        $created = ($inserted > 0 && $persisted === $candidate);

        if ($created) {
            $this->syncOnlineNodesAfterInitialize($persisted);
        }

        return [
            'panel_scope' => $persisted,
            'created' => $created,
        ];
    }

    /**
     * Compute opaque 24-hex diagnostic_id for a subscriber user ID if panel_scope is initialized.
     */
    public function computeUserDiagnosticId(int $subscriberUserId, ?string $panelScope = null): ?string
    {
        if ($subscriberUserId <= 0) {
            return null;
        }

        $scope = $panelScope ?? $this->getPanelScope();
        if ($scope === null) {
            return null;
        }

        return DiagnosticContract::computeDiagnosticId($scope, $subscriberUserId);
    }

    /**
     * Lookup an order by exact trade_no OR positive integer order_id and return the minimal safe identity card.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function lookupOrder(array $input): array
    {
        $resolved = $this->resolveOrderFromInput($input);
        return $resolved['public_card'];
    }

    /**
     * Resolve full order identity (including internal subscriber User model) from order_id or trade_no.
     *
     * @param array<string, mixed> $input
     * @return array{order: Order, subscriber: User, subscriber_user_id: int, diagnostic_id: string, panel_scope: string, public_card: array<string, mixed>}
     */
    public function resolveOrderFromInput(array $input): array
    {
        $hasOrderId = array_key_exists('order_id', $input) && $input['order_id'] !== null && $input['order_id'] !== '';
        $hasTradeNo = array_key_exists('trade_no', $input) && $input['trade_no'] !== null && $input['trade_no'] !== '';

        if ($hasOrderId === $hasTradeNo) {
            throw new DiagnosticDomainException(
                'invalid_lookup_input',
                '必须且只能提供 trade_no 或 order_id 其中一项',
                422
            );
        }

        $panelScope = $this->requirePanelScope();

        if ($hasOrderId) {
            $rawOrderId = $input['order_id'];
            if (is_int($rawOrderId)) {
                $orderId = $rawOrderId;
            } elseif (is_string($rawOrderId) && preg_match('/\A[1-9]\d*\z/', $rawOrderId) === 1) {
                $orderId = (int) $rawOrderId;
            } else {
                throw new DiagnosticDomainException(
                    'invalid_lookup_input',
                    'order_id 必须为正整数',
                    422
                );
            }

            if ($orderId <= 0) {
                throw new DiagnosticDomainException(
                    'invalid_lookup_input',
                    'order_id 必须为正整数',
                    422
                );
            }

            $order = Order::with([
                'user',
                'plan',
                'distributorOrder.subscriber',
                'distributorSubscription.subscriber',
            ])->find($orderId);

            if (!$order) {
                throw new DiagnosticDomainException(
                    'order_not_found',
                    '订单不存在',
                    404
                );
            }

            return $this->resolveOrderIdentity($order, $panelScope);
        }

        $rawTradeNo = $input['trade_no'];
        if (!is_string($rawTradeNo)) {
            throw new DiagnosticDomainException(
                'invalid_lookup_input',
                'trade_no 必须为完整订单号字符串',
                422
            );
        }

        $tradeNo = trim($rawTradeNo);
        if (
            $tradeNo === ''
            || strlen($tradeNo) > 36
            || preg_match('/[\x00-\x1f\x7f]/', $tradeNo) === 1
        ) {
            throw new DiagnosticDomainException(
                'invalid_lookup_input',
                'trade_no 长度必须为 1 至 36 字符且不含控制字符',
                422
            );
        }

        $candidates = Order::with([
            'user',
            'plan',
            'distributorOrder.subscriber',
            'distributorSubscription.subscriber',
        ])->where('trade_no', $tradeNo)->get();

        // Enforce strict binary string equality in PHP to prevent SQL collation / numeric coercion prefix matches
        $exactMatches = $candidates->filter(
            static fn (Order $candidate): bool => (string) $candidate->trade_no === $tradeNo
        )->values();

        if ($exactMatches->count() !== 1) {
            throw new DiagnosticDomainException(
                'order_not_found',
                '订单不存在',
                404
            );
        }

        /** @var Order $order */
        $order = $exactMatches->first();
        return $this->resolveOrderIdentity($order, $panelScope);
    }

    /**
     * Resolve subscriber identity for a loaded Order model.
     * Strictly resolves distributor subscriber for distributor initial & renewal orders; never falls back to distributor.
     *
     * @return array{order: Order, subscriber: User, subscriber_user_id: int, diagnostic_id: string, panel_scope: string, public_card: array<string, mixed>}
     */
    public function resolveOrderIdentity(Order $order, ?string $panelScope = null): array
    {
        $scope = $panelScope ?? $this->requirePanelScope();

        $distributorOrder = $order->distributorSubscription ?: $order->distributorOrder;
        $buyer = $order->getRelationValue('user');
        $hasDistributorRelation = $distributorOrder !== null
            || ($order->distributor_order_id !== null && (int) $order->distributor_order_id > 0)
            || ($buyer instanceof User && (bool) $buyer->is_distributor);

        $plan = $order->getRelationValue('plan');
        $planName = $plan instanceof \App\Models\Plan ? (string) $plan->name : '';

        if ($hasDistributorRelation) {
            $subscriber = $distributorOrder !== null ? $distributorOrder->getRelationValue('subscriber') : null;
            if (
                $distributorOrder === null
                || (int) ($distributorOrder->subscriber_user_id ?? 0) <= 0
                || !$subscriber instanceof User
                || (int) $subscriber->id <= 0
            ) {
                throw new DiagnosticDomainException(
                    'subscription_user_missing',
                    '分销订单缺少实际订阅用户（subscriber），禁止回退到分销商账号',
                    409
                );
            }

            $isRenewal = (int) $order->type === Order::TYPE_RENEWAL
                || ($order->distributorSubscription !== null && (int) $order->distributorSubscription->order_id !== (int) $order->id);
            $orderType = $isRenewal ? 'distributor_renewal' : 'distributor_initial';

            $customerDisplayName = trim((string) ($distributorOrder->customer_name ?? ''));
            if ($customerDisplayName === '') {
                $customerDisplayName = trim((string) ($distributorOrder->subscription_name ?? ''));
            }
            if ($customerDisplayName === '') {
                $code = trim((string) ($distributorOrder->subscription_code ?? ''));
                $customerDisplayName = $code !== '' ? "分销订阅 #{$code}" : "分销订单 #{$order->trade_no}";
            }

            $subscriptionName = trim((string) ($distributorOrder->subscription_name ?? ''));
            if ($subscriptionName === '') {
                $subscriptionName = $planName;
            }
        } else {
            $subscriber = $order->getRelationValue('user');
            if (
                (int) ($order->user_id ?? 0) <= 0
                || !$subscriber instanceof User
                || (int) $subscriber->id <= 0
            ) {
                throw new DiagnosticDomainException(
                    'subscription_user_missing',
                    '订单缺少关联的实际订阅用户',
                    409
                );
            }

            $orderType = 'direct';
            $customerDisplayName = self::maskCustomerDisplay((string) $subscriber->email, (int) $order->id);
            $subscriptionName = $planName;
        }

        $subscriberUserId = (int) $subscriber->id;
        $diagnosticId = DiagnosticContract::computeDiagnosticId($scope, $subscriberUserId);

        $publicCard = [
            'order_id' => (int) $order->id,
            'trade_no' => (string) $order->trade_no,
            'order_status' => (int) $order->status,
            'order_status_label' => Order::$statusMap[(int) $order->status] ?? (string) $order->status,
            'order_type' => $orderType,
            'order_type_code' => (int) $order->type,
            'order_type_label' => Order::$typeMap[(int) $order->type] ?? (string) $order->type,
            'customer_display_name' => $customerDisplayName,
            'subscription_name' => $subscriptionName,
            'diagnostic_id' => $diagnosticId,
        ];

        return [
            'order' => $order,
            'subscriber' => $subscriber,
            'subscriber_user_id' => $subscriberUserId,
            'diagnostic_id' => $diagnosticId,
            'panel_scope' => $scope,
            'public_card' => $publicCard,
        ];
    }

    /**
     * Resolve all enabled nodes currently accessible to the subscriber's permission group.
     *
     * @return Collection<int, Server>
     */
    public function resolveEligibleNodesForSubscriber(User $subscriber): Collection
    {
        $groupId = (int) ($subscriber->group_id ?? 0);
        if ($groupId <= 0 || !$subscriber->isAvailable()) {
            return collect();
        }

        return Server::query()
            ->where('enabled', true)
            ->whereJsonContains('group_ids', (string) $groupId)
            ->orderBy('sort', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Hot-sync online nodes after panel_scope is initialized for the first time.
     */
    private function syncOnlineNodesAfterInitialize(string $panelScope): void
    {
        try {
            $servers = Server::query()->where('enabled', true)->get();
            foreach ($servers as $server) {
                if (!NodeSyncService::isNodeOnline((int) $server->id)) {
                    continue;
                }
                $users = ServerService::getAvailableUsers($server)->toArray();
                NodeSyncService::push((int) $server->id, 'sync.users', ['users' => $users]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Diagnostics] Failed to push hot user sync after panel_scope initialization.', [
                'panel_scope' => $panelScope,
                'exception' => $e::class,
            ]);
        }
    }

    private static function maskCustomerDisplay(string $email, int $orderId): string
    {
        $trimmed = trim($email);
        if ($trimmed === '' || !str_contains($trimmed, '@')) {
            return "订单客户 #{$orderId}";
        }

        [$local, $domain] = explode('@', $trimmed, 2);
        $prefix = mb_substr($local, 0, min(2, mb_strlen($local, 'UTF-8')), 'UTF-8');
        return sprintf('%s***@%s', $prefix, $domain);
    }
}
