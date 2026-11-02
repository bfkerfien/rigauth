<?php

namespace Triglavian\AssetManager\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Triglavian\AssetManager\Services\Pricing\EsiMarketPriceSource;

class EsiMarketPriceSourceTest extends TestCase
{
    public function test_it_calculates_best_buy_and_best_sell_from_cached_orders(): void
    {
        $expiry = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        Http::fake(['*' => Http::response([
            ['price' => 100.00, 'is_buy_order' => true],
            ['price' => 110.00, 'is_buy_order' => true],
            ['price' => 125.00, 'is_buy_order' => false],
            ['price' => 120.00, 'is_buy_order' => false],
        ], 200, ['X-Pages' => '1', 'Expires' => $expiry])]);

        $snapshot = (new EsiMarketPriceSource())->fetch(10000002, 34);

        self::assertSame('110.00', $snapshot->bestBuy);
        self::assertSame('120.00', $snapshot->bestSell);
        self::assertSame(10000002, $snapshot->regionId);
        self::assertSame(34, $snapshot->typeId);
    }
}
