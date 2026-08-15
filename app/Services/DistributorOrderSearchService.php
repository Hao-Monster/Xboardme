<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class DistributorOrderSearchService
{
    public function applyToOrderQuery(
        EloquentBuilder $query,
        ?string $keyword,
        bool $includeSubscription = false
    ): EloquentBuilder {
        $keyword = $this->normalize($keyword);
        if ($keyword === null) {
            return $query;
        }

        $token = $includeSubscription ? $this->subscriptionToken($keyword) : null;

        return $query->where(function (EloquentBuilder $query) use ($keyword, $token) {
            $query->whereRaw('INSTR(v2_order.trade_no, ?) > 0', [$keyword])
                ->orWhereHas('distributorSubscription', function (EloquentBuilder $query) use ($keyword) {
                    $query->whereRaw('INSTR(v2_distributor_order.customer_name, ?) > 0', [$keyword]);
                })
                ->orWhereHas('distributorSubscription.order', function (EloquentBuilder $query) use ($keyword) {
                    $query->whereRaw('INSTR(v2_order.trade_no, ?) > 0', [$keyword]);
                });

            if ($token !== null) {
                $query->orWhereHas('distributorSubscription.subscriber', function (EloquentBuilder $query) use ($token) {
                    $query->where('token', $token);
                });
            }
        });
    }

    public function applyToExportQuery(
        QueryBuilder $query,
        ?string $keyword,
        bool $includeSubscription = false
    ): QueryBuilder {
        $keyword = $this->normalize($keyword);
        if ($keyword === null) {
            return $query;
        }

        $token = $includeSubscription ? $this->subscriptionToken($keyword) : null;

        return $query->where(function (QueryBuilder $query) use ($keyword, $token) {
            $query->whereRaw('INSTR(v2_order.trade_no, ?) > 0', [$keyword])
                ->orWhereRaw('INSTR(v2_distributor_order.customer_name, ?) > 0', [$keyword])
                ->orWhereRaw('INSTR(root_order.trade_no, ?) > 0', [$keyword]);

            if ($token !== null) {
                $query->orWhere('subscriber.token', $token);
            }
        });
    }

    public function normalize(?string $keyword): ?string
    {
        $keyword = trim((string) $keyword);

        return $keyword === '' ? null : $keyword;
    }

    private function subscriptionToken(string $keyword): ?string
    {
        if (!str_contains($keyword, '/')) {
            return $keyword;
        }

        $path = parse_url($keyword, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $token = rawurldecode((string) basename(rtrim($path, '/')));

        return $token === '' ? null : $token;
    }
}
