<?php

namespace Triglavian\AssetManager\Services\Pricing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EsiMarketPriceSource implements MarketPriceSource
{
    public function fetch(int $regionId, int $typeId): MarketPriceSnapshot
    {
        if ($regionId < 1 || $typeId < 1) {
            throw new RuntimeException('Invalid region or type ID for market pricing.');
        }

        $baseUrl = rtrim((string) config('assetmgr.esi.base_url', 'https://esi.evetech.net/latest'), '/');
        $path = $baseUrl . '/markets/' . $regionId . '/orders/';
        $pageCount = null;
        $expiresAt = null;
        $bestBuy = null;
        $bestSell = null;

        for ($page = 1; $page <= ($pageCount ?? 1); $page++) {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => (string) config('assetmgr.esi.user_agent', 'Triglavian-Asset-Manager'),
            ])->timeout(30)->retry(2, 500, throw: false)->get($path, [
                'order_type' => 'all',
                'type_id' => $typeId,
                'page' => $page,
            ]);
            $response->throw();

            $orders = $response->json();
            if (! is_array($orders) || ! array_is_list($orders)) {
                throw new RuntimeException('ESI returned an unexpected market order page payload.');
            }

            $headerPageCount = (int) $response->header('X-Pages', '1');
            if ($headerPageCount < 1 || ($pageCount !== null && $headerPageCount !== $pageCount)) {
                throw new RuntimeException('ESI market page count changed during pricing.');
            }
            $pageCount = $headerPageCount;

            $expiresHeader = $response->header('Expires');
            if (is_string($expiresHeader) && $expiresHeader !== '') {
                $pageExpiry = Carbon::parse($expiresHeader);
                if ($expiresAt !== null && abs($expiresAt->diffInSeconds($pageExpiry, false)) > 5) {
                    throw new RuntimeException('ESI market cache expiry changed during pagination.');
                }
                $expiresAt = $pageExpiry;
            }

            foreach ($orders as $order) {
                if (! is_array($order) || ! isset($order['price'], $order['is_buy_order'])) {
                    throw new RuntimeException('ESI returned a malformed market order.');
                }
                $price = (float) $order['price'];
                if ($price <= 0) continue;

                if ($order['is_buy_order']) {
                    $bestBuy = $bestBuy === null ? $price : max($bestBuy, $price);
                } else {
                    $bestSell = $bestSell === null ? $price : min($bestSell, $price);
                }
            }
        }

        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo(now()->addSeconds(5))) {
            throw new RuntimeException('ESI market cache expired during pagination; retry after cache refresh.');
        }

        return new MarketPriceSnapshot(
            typeId: $typeId,
            regionId: $regionId,
            bestBuy: $bestBuy === null ? null : number_format($bestBuy, 2, '.', ''),
            bestSell: $bestSell === null ? null : number_format($bestSell, 2, '.', ''),
            pagesReceived: $pageCount ?? 1,
            fetchedAt: now()->toIso8601String(),
            sourceExpiresAt: $expiresAt?->toIso8601String(),
        );
    }
}
