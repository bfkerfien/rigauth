<?php

namespace Triglavian\AssetManager\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;
use Triglavian\AssetManager\Services\Finance\EsiCorporationWalletJournalSource;

class EsiCorporationWalletJournalSourceTest extends TestCase
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

        Schema::create('corporation_infos', function (Blueprint $table): void {
            $table->unsignedBigInteger('corporation_id')->primary();
            $table->string('name');
        });
        DB::table('corporation_infos')->insert(['corporation_id' => 999, 'name' => 'Test Corporation']);
    }

    private function entry(int $id): array
    {
        return [
            'id' => $id,
            'date' => '2026-10-24T10:00:00Z',
            'ref_type' => 'market_transaction',
            'first_party_id' => 555,
            'second_party_id' => 999,
            'amount' => -100,
            'balance' => 900,
            'reason' => 'Test payment',
            'description' => 'Journal entry',
            'context_id' => 777,
            'context_id_type' => 'market_transaction_id',
        ];
    }

    public function test_it_fetches_all_division_journal_pages_and_keeps_corporation_ownership(): void
    {
        $expiry = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        $sequence = Http::sequence()
            ->push([$this->entry(100)], 200, ['X-Pages' => '2', 'Expires' => $expiry])
            ->push([$this->entry(99)], 200, ['X-Pages' => '2', 'Expires' => $expiry]);
        Http::fake(['*' => $sequence]);

        $tokens = $this->createMock(AssetAccessTokenProvider::class);
        $tokens->expects(self::once())
            ->method('accessTokenFor')
            ->with('corporation', 999, 'esi-wallet.read_corporation_wallets.v1')
            ->willReturn('test-access-token');

        $batch = (new EsiCorporationWalletJournalSource($tokens))->fetchSince(999, 3, null);

        self::assertCount(2, $batch->rows);
        self::assertSame(999, $batch->corporationId);
        self::assertSame(3, $batch->division);
        self::assertSame('Test Corporation', $batch->rows[0]['corporation_name']);
        self::assertSame(3, $batch->rows[0]['wallet_division']);
        self::assertSame(2, $batch->pagesReceived);
        Http::assertSentCount(2);
    }
}
