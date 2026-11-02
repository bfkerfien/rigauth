<?php

namespace Triglavian\AssetManager\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;
use Triglavian\AssetManager\Services\Contracts\EsiContractSource;

class EsiContractSourceTest extends TestCase
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
        DB::table('character_infos')->insert(['character_id' => 123, 'name' => 'Contract Owner']);
    }

    private function contract(int $id, bool $forCorporation = false): array
    {
        return [
            'contract_id' => $id,
            'issuer_id' => 123,
            'issuer_corporation_id' => 999,
            'assignee_id' => 456,
            'acceptor_id' => 456,
            'type' => 'item_exchange',
            'availability' => 'public',
            'status' => 'outstanding',
            'title' => 'Test contract',
            'for_corporation' => $forCorporation,
            'start_location_id' => 60000001,
            'end_location_id' => 60000002,
            'date_issued' => '2026-10-24T10:00:00Z',
            'date_expired' => '2026-11-24T10:00:00Z',
            'price' => 100,
            'reward' => 10,
            'collateral' => 0,
            'volume' => 5,
        ];
    }

    public function test_it_fetches_all_pages_and_excludes_corporation_contracts_from_personal_portfolios(): void
    {
        $expiry = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        $sequence = Http::sequence()
            ->push([$this->contract(100), $this->contract(101, true)], 200, ['X-Pages' => '2', 'Expires' => $expiry])
            ->push([$this->contract(99)], 200, ['X-Pages' => '2', 'Expires' => $expiry]);
        Http::fake(['*' => $sequence]);

        $tokens = $this->createMock(AssetAccessTokenProvider::class);
        $tokens->expects(self::once())
            ->method('accessTokenFor')
            ->with('character', 123, 'esi-contracts.read_character_contracts.v1')
            ->willReturn('test-access-token');

        $snapshot = (new EsiContractSource($tokens))->fetchCharacterContracts(123);

        self::assertCount(2, $snapshot->rows);
        self::assertSame(['100', '99'], array_column($snapshot->rows, 'contract_id'));
        self::assertSame('Contract Owner', $snapshot->rows[0]['character_name']);
        self::assertFalse($snapshot->rows[0]['for_corporation']);
        self::assertSame(2, $snapshot->pagesReceived);
        Http::assertSentCount(2);
    }
}
