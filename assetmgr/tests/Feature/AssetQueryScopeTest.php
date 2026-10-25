<?php

namespace Triglavian\AssetManager\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Triglavian\AssetManager\Services\Assets\AssetQuery;

class AssetQueryScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('assetmgr_assets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('owner_kind');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('character_id')->nullable();
            $table->string('character_name')->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->string('corporation_name')->nullable();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('parent_item_id')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->string('type_name');
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('location_id');
            $table->string('location_name')->nullable();
            $table->string('location_flag')->nullable();
            $table->string('location_type')->nullable();
            $table->unsignedBigInteger('regional_region_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
        });

        Schema::create('assetmgr_prices', function (Blueprint $table): void {
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('region_id');
            $table->string('source');
            $table->string('price_basis');
            $table->decimal('unit_price', 20, 2)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('source_expires_at')->nullable();
        });

        DB::table('assetmgr_assets')->insert([
            [
                'owner_kind' => 'character', 'owner_id' => 123, 'character_id' => 123,
                'character_name' => 'Owner One', 'item_id' => 1001, 'type_id' => 34,
                'type_name' => 'Tritanium', 'quantity' => 10, 'location_id' => 60000001,
                'location_name' => 'Jita', 'regional_region_id' => 10000002,
            ],
            [
                'owner_kind' => 'character', 'owner_id' => 999, 'character_id' => 999,
                'character_name' => 'Other User', 'item_id' => 1002, 'type_id' => 35,
                'type_name' => 'Pyerite', 'quantity' => 20, 'location_id' => 60000001,
                'location_name' => 'Jita', 'regional_region_id' => 10000002,
            ],
            [
                'owner_kind' => 'corporation', 'owner_id' => 777, 'corporation_id' => 777,
                'corporation_name' => 'Test Corp', 'item_id' => 1003, 'type_id' => 36,
                'type_name' => 'Mexallon', 'quantity' => 30, 'location_id' => 60000001,
                'location_name' => 'Jita', 'regional_region_id' => 10000002,
            ],
        ]);
    }

    public function test_personal_query_only_returns_characters_in_the_verified_portfolio(): void
    {
        $rows = (new AssetQuery())->personal([123])->get();

        self::assertCount(1, $rows);
        self::assertSame(123, (int) $rows->first()->character_id);
        self::assertSame('Owner One', $rows->first()->character_name);
    }

    public function test_filtering_by_an_unlinked_character_cannot_escape_the_portfolio_scope(): void
    {
        $rows = (new AssetQuery())->personal([123], ['character_id' => 999])->get();

        self::assertCount(0, $rows);
    }

    public function test_corporation_query_never_includes_personal_assets(): void
    {
        $rows = (new AssetQuery())->corporation(777)->get();

        self::assertCount(1, $rows);
        self::assertSame('corporation', $rows->first()->owner_kind);
        self::assertSame(777, (int) $rows->first()->owner_id);
    }
}
