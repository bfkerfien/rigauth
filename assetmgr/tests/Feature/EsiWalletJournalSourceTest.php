<?php

namespace Triglavian\AssetManager\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;
use Triglavian\AssetManager\Services\Finance\EsiWalletJournalSource;

class EsiWalletJournalSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('character_infos', function (Blueprint $table): void {
            $table->unsignedBigInteger('character_id')->primary();
            $table->string('name');
        });
        DB::table('character_infos')->insert(['character_id' => 123, 'name' => 'Wallet Owner']);
    }

    public function test_it_fetches_all_wallet_journal_pages_and_deduplicates_entries(): void
    {
        $expiry = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        $sequence = Http::sequence()
            ->push([
                ['id' => 1, 'date' => '2026-10-24T10:00:00Z', 'ref_type' => 'market_transaction', 'amount' => -100, 'balance' => 900],
            ], 200, ['X-Pages' => '2', 'Expires' => $expiry])
            ->push([
                ['id' => 1, 'date' => '2026-10-24T10:00:00Z', 'ref_type' => 'market_transaction', 'amount' => -100, 'balance' => 900],
                ['id' => 2, 'date' => '2026-10-24T10:01:00Z', 'ref_type' => 'market_transaction', 'amount' => 50, 'balance' => 950],
            ], 200, ['X-Pages' => '2', 'Expires' => $expiry]);
        Http::fake(['*' => $sequence]);

        $tokens = $this->createMock(AssetAccessTokenProvider::class);
        $tokens->expects(self::once())
            ->method('accessTokenFor')
            ->with('character', 123, 'esi-wallet.read_character_wallet.v1')
            ->willReturn('test-access-token');

        $snapshot = (new EsiWalletJournalSource($tokens))->fetchSince(123, null);

        self::assertCount(2, $snapshot->rows);
        self::assertSame('Wallet Owner', $snapshot->rows[0]['character_name']);
        self::assertSame('market_transaction', $snapshot->rows[0]['reference_type']);
        self::assertSame(2, $snapshot->pagesReceived);
        Http::assertSentCount(2);
    }

    public function test_incremental_sync_stops_when_a_page_overlaps_the_latest_stored_id(): void
    {
        $expiry = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        $sequence = Http::sequence()
            ->push([
                ['id' => 200, 'date' => '2026-10-24T10:00:00Z', 'ref_type' => 'market_transaction', 'amount' => -100, 'balance' => 900],
                ['id' => 199, 'date' => '2026-10-24T09:59:00Z', 'ref_type' => 'market_transaction', 'amount' => -50, 'balance' => 1000],
            ], 200, ['X-Pages' => '10', 'Expires' => $expiry]);
        Http::fake(['*' => $sequence]);

        $tokens = $this->createMock(AssetAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willReturn('test-access-token');

        $snapshot = (new EsiWalletJournalSource($tokens))->fetchSince(123, '199');

        self::assertCount(2, $snapshot->rows);
        self::assertSame(1, $snapshot->pagesReceived);
        Http::assertSentCount(1);
    }

    public function test_it_rejects_wallet_journal_page_count_changes(): void
    {
        $expiry = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        $sequence = Http::sequence()
            ->push([], 200, ['X-Pages' => '2', 'Expires' => $expiry])
            ->push([], 200, ['X-Pages' => '1', 'Expires' => $expiry]);
        Http::fake(['*' => $sequence]);

        $tokens = $this->createMock(AssetAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willReturn('test-access-token');

        $this->expectException(\RuntimeException::class);
        (new EsiWalletJournalSource($tokens))->fetchComplete(123);
    }
}
