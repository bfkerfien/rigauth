<?php

namespace Triglavian\AssetManager\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Triglavian\AssetManager\Services\Assets\AssetSnapshotRepository;
use Triglavian\AssetManager\Services\Assets\CompleteAssetSnapshot;

class AssetSnapshotRepositoryTest extends TestCase
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

        Schema::create('assetmgr_assets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('owner_kind');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('character_id')->nullable();
            $table->string('character_name')->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->string('corporation_name')->nullable();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('type_id');
            $table->string('type_name');
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('location_id');
            $table->string('location_name')->nullable();
            $table->string('location_flag')->nullable();
            $table->string('location_type')->nullable();
            $table->unsignedBigInteger('parent_item_id')->nullable();
            $table->unsignedBigInteger('regional_region_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_kind', 'owner_id', 'item_id']);
        });

        Schema::create('assetmgr_asset_staging', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('sync_run_id', 36);
            $table->string('owner_kind');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('item_id');
            $table->text('payload');
            $table->timestamp('created_at');
            $table->unique(['sync_run_id', 'owner_kind', 'owner_id', 'item_id']);
        });

        DB::table('assetmgr_assets')->insert([
            ['owner_kind' => 'character', 'owner_id' => 123, 'character_id' => 123, 'item_id' => 1, 'type_id' => 34, 'type_name' => 'Old One', 'quantity' => 1, 'location_id' => 60000001],
            ['owner_kind' => 'character', 'owner_id' => 123, 'character_id' => 123, 'item_id' => 2, 'type_id' => 35, 'type_name' => 'Removed Item', 'quantity' => 1, 'location_id' => 60000001],
            ['owner_kind' => 'character', 'owner_id' => 999, 'character_id' => 999, 'item_id' => 9, 'type_id' => 36, 'type_name' => 'Other Owner', 'quantity' => 1, 'location_id' => 60000001],
        ]);
    }

    private function row(int $itemId, int $typeId, int $quantity): array
    {
        return [
            'item_id' => $itemId,
            'type_id' => $typeId,
            'type_name' => 'Type ' . $typeId,
            'quantity' => $quantity,
            'location_id' => 60000001,
            'location_type' => 'station',
            'location_name' => 'Jita',
            'character_id' => 123,
            'character_name' => 'Owner One',
        ];
    }

    public function test_complete_snapshot_upserts_rows_and_removes_only_missing_items_for_that_owner(): void
    {
        $snapshot = new CompleteAssetSnapshot(
            ownerKind: 'character',
            ownerId: 123,
            rows: [$this->row(1, 34, 5), $this->row(3, 35, 2)],
            pagesReceived: 2,
            fetchedAt: now()->toIso8601String(),
            allPagesFetched: true,
        );

        (new AssetSnapshotRepository())->replaceOwnerSnapshot('character', 123, $snapshot);

        $rows = DB::table('assetmgr_assets')->where('owner_kind', 'character')->where('owner_id', 123)->orderBy('item_id')->get();
        self::assertSame([1, 3], $rows->pluck('item_id')->map(static fn ($id): int => (int) $id)->all());
        self::assertSame(5, (int) $rows->first()->quantity);
        self::assertSame(1, DB::table('assetmgr_assets')->where('owner_kind', 'character')->where('owner_id', 999)->count());
        self::assertSame(0, DB::table('assetmgr_asset_staging')->count());
    }

    public function test_complete_empty_snapshot_clears_only_its_owner(): void
    {
        $snapshot = new CompleteAssetSnapshot(
            ownerKind: 'character',
            ownerId: 123,
            rows: [],
            pagesReceived: 1,
            fetchedAt: now()->toIso8601String(),
            allPagesFetched: true,
        );

        (new AssetSnapshotRepository())->replaceOwnerSnapshot('character', 123, $snapshot);

        self::assertSame(0, DB::table('assetmgr_assets')->where('owner_kind', 'character')->where('owner_id', 123)->count());
        self::assertSame(1, DB::table('assetmgr_assets')->where('owner_kind', 'character')->where('owner_id', 999)->count());
    }
}
