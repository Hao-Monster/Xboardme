<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\DistributorOrder;
use App\Models\User;
use App\Services\DistributorOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DistributorClaimController extends Controller
{
    public function claim(Request $request, string $token)
    {
        if ($request->isMethod('HEAD')) {
            return response('', 405, ['Allow' => 'GET', 'Cache-Control' => 'no-store']);
        }

        $purpose = strtolower((string) ($request->header('Sec-Purpose') ?: $request->header('Purpose')));
        if (str_contains($purpose, 'prefetch')) {
            return response('', 425, ['Cache-Control' => 'no-store']);
        }

        if (!preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            return response('Invalid claim token', 404, ['Content-Type' => 'text/plain']);
        }

        $delivery = DB::transaction(function () use ($request, $token) {
            $delivery = DistributorOrder::query()
                ->where('claim_token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (!$delivery || $delivery->delivery_status !== DistributorOrder::DELIVERY_PENDING) {
                return null;
            }

            /** @var User|null $subscriber */
            $subscriber = $delivery->subscriber()->first();
            if (!$subscriber) {
                return null;
            }

            $now = time();
            $delivery->delivery_status = DistributorOrder::DELIVERY_CLAIMED;
            $delivery->claimed_at = $now;
            $delivery->claim_ip = mb_substr((string) $request->ip(), 0, 45);
            $delivery->claim_ua = mb_substr((string) $request->userAgent(), 0, 255);
            $delivery->claim_token = null;
            $delivery->save();

            return $delivery->setRelation('subscriber', $subscriber);
        });

        if (!$delivery) {
            return response('This subscription QR code has already been used or closed.', 410, [
                'Content-Type' => 'text/plain',
                'Cache-Control' => 'no-store',
            ]);
        }

        $url = app(DistributorOrderService::class)->subscriptionUrl($delivery);
        $query = $request->query();
        unset($query['token']);
        if ($query) {
            [$baseUrl, $fragment] = array_pad(explode('#', $url, 2), 2, '');
            $url = $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query($query);
            if ($fragment !== '') {
                $url .= '#' . $fragment;
            }
        }

        return redirect()->away($url, 302, [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
