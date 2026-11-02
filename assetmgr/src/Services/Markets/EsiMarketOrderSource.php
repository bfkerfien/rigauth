<?php

namespace Triglavian\AssetManager\Services\Markets;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;
use Triglavian\AssetManager\Services\Assets\AssetMetadataResolver;

class EsiMarketOrderSource implements MarketOrderSource
{
    public function __construct(
        private readonly AssetAccessTokenProvider $tokens,
        private readonly AssetMetadataResolver $metadata,
    ) {
    }

    public function fetchComplete(int $characterId): MarketOrderSnapshot
    {
        if ($characterId < 1) throw new RuntimeException('Invalid character ID for market order sync.');

        $token = $this->tokens->accessTokenFor('character', $characterId, 'esi-markets.read_character_orders.v1');
        $baseUrl = rtrim((string) config('assetmgr.esi.base_url', 'https://esi.evetech.net'), '/');
        $url = $baseUrl . '/characters/' . $characterId . '/orders/';
        $pageCount = null;
        $expiresAt = null;
        $rowsById = [];

        for ($page = 1; $page <= ($pageCount ?? 1); $page++) {
            $response = Http::withToken($token)->withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => (string) config('assetmgr.esi.user_agent', 'Triglavian-Asset-Manager'),
                'X-Compatibility-Date' => (string) config('assetmgr.esi.compatibility_date', '2026-08-18'),
            ])->timeout(30)->retry(2, 500, throw: false)->get($url, ['page' => $page]);
            $response->throw();

            $entries = $response->json();
            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new RuntimeException('ESI returned an unexpected market order page payload.');
            }

            $headerPageCount = (int) $response->header('X-Pages', '1');
            if ($headerPageCount < 1 || ($pageCount !== null && $headerPageCount !== $pageCount)) {
                throw new RuntimeException('ESI market order page count changed during pagination.');
            }
            $pageCount = $headerPageCount;

            $expiresHeader = $response->header('Expires');
            if (is_string($expiresHeader) && $expiresHeader !== '') {
                $pageExpiry = Carbon::parse($expiresHeader);
                if ($expiresAt !== null && abs($expiresAt->diffInSeconds($pageExpiry, false)) > 5) {
                    throw new RuntimeException('ESI market order cache expiry changed during pagination.');
                }
                $expiresAt = $pageExpiry;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry) || ! isset(
                    $entry['order_id'],
                    $entry['type_id'],
                    $entry['location_id'],
                    $entry['price'],
                    $entry['volume_total'],
                    $entry['volume_remain'],
                    $entry['is_buy_order'],
                    $entry['issued']
                )) {
                    throw new RuntimeException('ESI returned a malformed market order.');
                }

                // Corporation-owned orders are not placed into the character portfolio.
                if ((bool) ($entry['is_corporation'] ?? false)) continue;

                $id = (string) $entry['order_id'];
                $rowsById[$id] = [
                    'order_id' => $id,
                    'type_id' => (int) $entry['type_id'],
                    'type_name' => null,
                    'location_id' => (int) $entry['location_id'],
                    'location_name' => null,
                    'region_id' => null,
                    'is_buy_order' => (bool) $entry['is_buy_order'],
                    'is_corporation_order' => false,
                    'price' => number_format((float) $entry['price'], 2, '.', ''),
                    'volume_total' => (int) $entry['volume_total'],
                    'volume_remain' => (int) $entry['volume_remain'],
                    'duration' => isset($entry['duration']) ? (int) $entry['duration'] : null,
                    'order_range' => $entry['range'] ?? null,
                    'min_volume' => isset($entry['min_volume']) ? (int) $entry['min_volume'] : null,
                    'escrow' => isset($entry['escrow']) ? number_format((float) $entry['escrow'], 2, '.', '') : null,
                    'issued' => Carbon::parse($entry['issued'])->toDateTimeString(),
                    'character_id' => $characterId,
                    'character_name' => null,
                ];
            }
        }

        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo(now()->addSeconds(5))) {
            throw new RuntimeException('ESI market order cache expired during pagination; retry after cache refresh.');
        }

        $metadataRows = array_map(static fn (array $row): array => [
            'item_id' => (int) $row['order_id'],
            'type_id' => $row['type_id'],
            'quantity' => $row['volume_remain'],
            'location_id' => $row['location_id'],
            'location_type' => null,
        ], array_values($rowsById));
        $resolvedRows = $this->metadata->resolve('character', $characterId, $metadataRows);
        $metadataById = [];
        foreach ($resolvedRows as $row) $metadataById[(string) $row['item_id']] = $row;

        foreach ($rowsById as $id => &$row) {
            $resolved = $metadataById[$id] ?? [];
            $row['type_name'] = $resolved['type_name'] ?? ('Type ' . $row['type_id']);
            $row['location_name'] = $resolved['location_name'] ?? null;
            $row['region_id'] = $resolved['regional_region_id'] ?? null;
            $row['character_name'] = $resolved['character_name'] ?? null;
        }
        unset($row);

        return new MarketOrderSnapshot(
            characterId: $characterId,
            rows: array_values($rowsById),
            pagesReceived: $pageCount ?? 1,
            fetchedAt: now()->toIso8601String(),
            sourceExpiresAt: $expiresAt?->toIso8601String(),
            allPagesFetched: true,
        );
    }
}
