<?php

namespace Triglavian\AssetManager\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;
use Triglavian\AssetManager\Services\Assets\AssetMetadataResolver;
use Triglavian\AssetManager\Services\Markets\EsiMarketOrderSource;

class EsiMarketOrderSourceTest extends TestCase
{
    private function order(int $id, bool $isCorporation = false): array
    {
        return [
            'order_id' => $id,
            'type_id' => 34,
            'location_id' => 60000001,
            'price' => 10.25,
            'volume_total' => 100,
            'volume_remain' => 50,
            'is_buy_order' => true,
            'is_corporation' => $isCorporation,
            'issued' => '2026-10-24T10:00:00Z',
            'duration' => 30,
            'range' => 'region',
            'min_volume' => 1,
            'escrow' => 500,
        ];
    }

    public function test_it_fetches_all_order_pages_and_keeps_corporation_orders_out_of_personal_portfolios(): void
    {
        $expiry = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        $sequence = Http::sequence()
            ->push([$this->order(100), $this->order(101, true)], 200, ['X-Pages' => '2', 'Expires' => $expiry])
            ->push([$this->order(99)], 200, ['X-Pages' => '2', 'Expires' => $expiry]);
        Http::fake(['*' => $sequence]);

        $tokens = $this->createMock(AssetAccessTokenProvider::class);
        $tokens->expects(self::once())
            ->method('accessTokenFor')
            ->with('character', 123, 'esi-markets.read_character_orders.v1')
            ->willReturn('test-access-token');

        $metadata = $this->createMock(AssetMetadataResolver::class);
        $metadata->expects(self::once())
            ->method('resolve')
            ->with('character', 123, self::anything())
            ->willReturn([]);

        $snapshot = (new EsiMarketOrderSource($tokens, $metadata))->fetchComplete(123);

        self::assertCount(2, $snapshot->rows);
        self::assertSame(['100', '99'], array_column($snapshot->rows, 'order_id'));
        self::assertTrue($snapshot->allPagesFetched);
        self::assertSame(2, $snapshot->pagesReceived);
        Http::assertSentCount(2);
    }

    public function test_it_rejects_market_order_page_count_changes(): void
    {
        $expiry = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        $sequence = Http::sequence()
            ->push([], 200, ['X-Pages' => '2', 'Expires' => $expiry])
            ->push([], 200, ['X-Pages' => '1', 'Expires' => $expiry]);
        Http::fake(['*' => $sequence]);

        $tokens = $this->createMock(AssetAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willReturn('test-access-token');
        $metadata = $this->createMock(AssetMetadataResolver::class);
        $metadata->method('resolve')->willReturn([]);

        $this->expectException(\RuntimeException::class);
        (new EsiMarketOrderSource($tokens, $metadata))->fetchComplete(123);
    }
}
