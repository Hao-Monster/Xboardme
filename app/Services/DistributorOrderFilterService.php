<?php

namespace App\Services;

use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DistributorOrderFilterService
{
    public const MAX_RANGE_DAYS = 366;

    /** @return array<string, mixed> */
    public function rules(bool $withPagination = true): array
    {
        $periods = array_values(array_unique([
            ...array_keys(Plan::LEGACY_PERIOD_MAPPING),
            ...array_values(Plan::LEGACY_PERIOD_MAPPING),
        ]));

        $rules = [
            'start_date' => ['nullable', 'required_with:end_date', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'required_with:start_date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'periods' => ['nullable', 'array', 'max:8'],
            'periods.*' => ['string', Rule::in($periods)],
            'min_amount' => ['nullable', 'string', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'],
            'max_amount' => ['nullable', 'string', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'],
        ];
        if ($withPagination) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', Rule::in([20, 50, 100])];
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{start_at:?int,end_at:?int,periods:array<int,string>,min_amount:?int,max_amount:?int}
     */
    public function normalize(array $input): array
    {
        $start = isset($input['start_date'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['start_date'], config('app.timezone'))
            : null;
        $end = isset($input['end_date'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['end_date'], config('app.timezone'))
            : null;
        if ($start && $end && $start->diffInDays($end) + 1 > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'end_date' => '查询时间范围不能超过366天',
            ]);
        }

        $minAmount = isset($input['min_amount']) ? $this->yuanToCents((string) $input['min_amount']) : null;
        $maxAmount = isset($input['max_amount']) ? $this->yuanToCents((string) $input['max_amount']) : null;
        if ($minAmount !== null && $maxAmount !== null && $minAmount > $maxAmount) {
            throw ValidationException::withMessages([
                'max_amount' => '最高订单金额不能小于最低订单金额',
            ]);
        }

        return [
            'start_at' => $start?->startOfDay()->timestamp,
            'end_at' => $end?->addDay()->startOfDay()->timestamp,
            'periods' => array_values(array_unique(array_map(
                static fn(string $period): string => PlanService::getPeriodKey($period),
                $input['periods'] ?? []
            ))),
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
        ];
    }

    /**
     * @param EloquentBuilder|QueryBuilder $query
     * @param array{start_at:?int,end_at:?int,periods:array<int,string>,min_amount:?int,max_amount:?int} $filters
     */
    public function apply(EloquentBuilder|QueryBuilder $query, array $filters, string $prefix = ''): void
    {
        $column = static fn(string $name): string => $prefix !== '' ? "{$prefix}.{$name}" : $name;
        if ($filters['start_at'] !== null) {
            $query->where($column('created_at'), '>=', $filters['start_at']);
        }
        if ($filters['end_at'] !== null) {
            $query->where($column('created_at'), '<', $filters['end_at']);
        }
        if ($filters['periods'] !== []) {
            $query->whereIn($column('period'), $filters['periods']);
        }
        if ($filters['min_amount'] !== null) {
            $query->where($column('total_amount'), '>=', $filters['min_amount']);
        }
        if ($filters['max_amount'] !== null) {
            $query->where($column('total_amount'), '<=', $filters['max_amount']);
        }
    }

    private function yuanToCents(string $amount): int
    {
        [$yuan, $cents] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $yuan * 100) + (int) str_pad($cents, 2, '0');
    }
}
