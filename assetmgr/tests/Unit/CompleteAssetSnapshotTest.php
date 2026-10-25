<?php

namespace Triglavian\AssetManager\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Triglavian\AssetManager\Services\Assets\CompleteAssetSnapshot;

class CompleteAssetSnapshotTest extends TestCase
{
    public function test_rejects_incomplete_pagination(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CompleteAssetSnapshot('character', 123, [], 0, date(DATE_ATOM), false);
    }

    public function test_rejects_invalid_owner(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CompleteAssetSnapshot('unknown', 123, [], 1, date(DATE_ATOM), true);
    }

    public function test_rejects_rows_without_stable_asset_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CompleteAssetSnapshot('character', 123, [
            ['type_id' => 34, 'location_id' => 60000001],
        ], 1, date(DATE_ATOM), true);
    }

    public function test_accepts_a_complete_empty_inventory_page(): void
    {
        $snapshot = new CompleteAssetSnapshot('character', 123, [], 1, date(DATE_ATOM), true);

        self::assertSame('character', $snapshot->ownerKind);
        self::assertSame(123, $snapshot->ownerId);
        self::assertSame([], $snapshot->rows);
        self::assertTrue($snapshot->allPagesFetched);
    }
}
