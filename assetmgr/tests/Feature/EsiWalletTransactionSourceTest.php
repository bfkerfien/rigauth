<?php

namespace Triglavian\AssetManager\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;
use Triglavian\AssetManager\Services\Assets\AssetMetadataResolver;
use Triglavian\AssetManager\Services\Finance\EsiWalletTransactionSource;

class EsiWalletTransactionSourceTest extends TestCase
{
    private function transaction(int $id): array
    {
        return [
            'transaction_id' => $id,
            'date' => '2026-10-24T10:00:00Z',
            'type_id' => 34,
            'location_id' => 60000001,
            'quantity' => 2,
            'unit_price' => 10.0,
            'is_buy' => true,
            'client_id' => 555,
            'journal_ref_id' => 777,
        ];
    }

    public function test_it_fetches_transaction_cursor_pages_and_calculates_buy_amounts(): void
    {
        $expiry = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        $firstPage = [];
        for ($id = 2501; $id >= 2; $id--) $firstPage[] = $this->transaction($id);
        $sequence = Http::sequence()
            ->push($firstPage, 200, ['Expires' => $expiry])
            ->push([$this->transaction(1)], 200, ['Expires' => $expiry]);
        Http::fake(['*' => $sequence]);

        $tokens = $this->createMock(AssetAccessTokenProvider::class);
        $tokens->expects(self::once())
            ->method('accessTokenFor')
            ->with('character', 123, 'esi-wallet.read_character_wallet.v1')
            ->willReturn('test-access-token');

        $metadata = $this->createMock(AssetMetadataResolver::class);
        $metadata->expects(self::once())
            ->method('resolve')
            ->with('character', 123, self::anything())
            ->willReturn([]);

        $snapshot = (new EsiWalletTransactionSource($tokens, $metadata))->fetchSince(123, null);

        self::assertCount(2501, $snapshot->rows);
        self::assertSame('2501', $snapshot->rows[0]['external_id']);
        self::assertSame('-20.00', $snapshot->rows[0]['amount']);
        self::assertSame('market_buy', $snapshot->rows[0]['reference_type']);
        self::assertSame(2, $snapshot->requestsFetched);
        Http::assertSentCount(2);
    }

    public function test_incremental_sync_stops_at_an_existing_transaction_id(): void
    {
        $expiry = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        Http::fake(['*' => Http::response([$this->transaction(200), $this->transaction(199)], 200, ['Expires' => $expiry])]);

        $tokens = $this->createMock(AssetAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willReturn('test-access-token');
        $metadata = $this->createMock(AssetMetadataResolver::class);
        $metadata->method('resolve')->willReturn([]);

        $snapshot = (new EsiWalletTransactionSource($tokens, $metadata))->fetchSince(123, '199');

        self::assertCount(2, $snapshot->rows);
        self::assertSame(1, $snapshot->requestsFetched);
        Http::assertSentCount(1);
    }
}
