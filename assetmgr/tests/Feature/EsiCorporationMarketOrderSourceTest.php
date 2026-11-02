<?php

namespace Triglavian\AssetManager\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;
use Triglavian\AssetManager\Services\Assets\AssetMetadataResolver;
use Triglavian\AssetManager\Services\Markets\EsiCorporationMarketOrderSource;

class EsiCorporationMarketOrderSourceTest extends TestCase
{
    private function order(int $id): array
    {
        return [
            'order_id' => $id,
            'type_id' => 34,
            'location_id' => 60000001,
            'region_id' => 10000002,
            'price' => 10.25,
            'volume_total' => 100,
            'volume_remain' => 50,
            'is_buy_order' => false,
            'issued' => '2026-10-24T10:00:00Z',
            'duration' => 30,
            'range' => 'region',
            'min_volume' => 1,
            'escrow' => 0,
            'issued_by' => 123,
            'wallet_division' => 3,
        ];
    }

    public function test_it_fetches_all_corporation_order_pages_with_corporation_ownership(): void
    {
        $expiry = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        $sequence = Http::sequence()
            ->push([$this->order(100)], 200, ['X-Pages' => '2', 'Expires' => $expiry])
            ->push([$this->order(99)], 200, ['X-Pages' => '2', 'Expires' => $expiry]);
        Http::fake(['*' => $sequence]);

        $tokens = $this->createMock(AssetAccessTokenProvider::class);
        $tokens->expects(self::once())
            ->method('accessTokenFor')
            ->with('corporation', 999, 'esi-markets.read_corporation_orders.v1')
            ->willReturn('test-access-token');

        $metadata = $this->createMock(AssetMetadataResolver::class);
        $metadata->expects(self::once())
            ->method('resolve')
            ->with('corporation', 999, self::anything())
            ->willReturn([
                ['item_id' => 100, 'type_name' => 'Tritanium', 'location_name' => 'Jita', 'regional_region_id' => 10000002, 'corporation_name' => 'Test Corp'],
                ['item_id' => 99, 'type_name' => 'Pyerite', 'location_name' => 'Jita', 'regional_region_id' => 10000002, 'corporation_name' => 'Test Corp'],
            ]);

        $snapshot = (new EsiCorporationMarketOrderSource($tokens, $metadata))->fetchComplete(999);

        self::assertCount(2, $snapshot->rows);
        self::assertSame(['100', '99'], array_column($snapshot->rows, 'order_id'));
        self::assertSame(999, $snapshot->corporationId);
        self::assertTrue($snapshot->rows[0]['is_corporation_order']);
        self::assertSame('Test Corp', $snapshot->rows[0]['corporation_name']);
        self::assertSame(3, $snapshot->rows[0]['wallet_division']);
        self::assertSame(2, $snapshot->pagesReceived);
        Http::assertSentCount(2);
    }
}
