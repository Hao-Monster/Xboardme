<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class DistributorSettlementService
{
    /** @return array<int, string> */
    public function monthRules(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'regex:/^\d{4}-(?:0[1-9]|1[0-2])$/',
            'date_format:Y-m',
        ];
    }

    /**
     * @param EloquentBuilder|QueryBuilder $query
     */
    public function applyMonth(
        EloquentBuilder|QueryBuilder $query,
        ?string $month,
        string $prefix = ''
    ): void {
        if ($month === null || $month === '') {
            return;
        }

        [$startAt, $endAt] = $this->monthRange($month);
        $column = $prefix !== '' ? "{$prefix}.created_at" : 'created_at';
        $query->where($column, '>=', $startAt)
            ->where($column, '<', $endAt);
    }

    public function unsettledOrderQuery(int $distributorUserId, string $month): EloquentBuilder
    {
        $query = Order::query()
            ->where('user_id', $distributorUserId)
            ->whereNotNull('distributor_order_id')
            ->where('status', Order::STATUS_COMPLETED)
            ->whereNull('paid_at')
            ->whereHas('distributorSubscription', fn($query) => $query
                ->where('distributor_user_id', $distributorUserId));

        $this->applyMonth($query, $month, 'v2_order');

        return $query;
    }

    /** @return array{0:int,1:int} */
    private function monthRange(string $month): array
    {
        $start = CarbonImmutable::createFromFormat(
            '!Y-m',
            $month,
            config('app.timezone')
        );

        return [$start->timestamp, $start->addMonth()->timestamp];
    }
}
