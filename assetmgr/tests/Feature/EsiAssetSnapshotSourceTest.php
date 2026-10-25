<?php

namespace Triglavian\AssetManager\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;
use Triglavian\AssetManager\Services\Assets\AssetMetadataResolver;
use Triglavian\AssetManager\Services\Assets\EsiAssetSnapshotSource;

class EsiAssetSnapshotSourceTest extends TestCase
{
    private function expiry(): string
    {
        return gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
    }

    private function source(): EsiAssetSnapshotSource
    {
        $tokens = $this->createMock(AssetAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->with('character', 123, 'esi-assets.read_assets.v1')->willReturn('test-access-token');

        $metadata = $this->createMock(AssetMetadataResolver::class);
        $metadata->method('resolve')->willReturnCallback(
            static fn (string $kind, int $ownerId, array $rows): array => $rows
        );

        return new EsiAssetSnapshotSource($tokens, $metadata);
    }

    public function test_it_fetches_all_pages_and_deduplicates_overlapping_item_ids(): void
    {
        $expiry = $this->expiry();
        $sequence = Http::sequence()
            ->push([
                ['item_id' => 1, 'type_id' => 34, 'location_id' => 60000001, 'location_type' => 'station'],
            ], 200, ['X-Pages' => '2', 'Expires' => $expiry])
            ->push([
                ['item_id' => 1, 'type_id' => 34, 'location_id' => 60000001, 'location_type' => 'station'],
                ['item_id' => 2, 'type_id' => 35, 'location_id' => 60000001, 'location_type' => 'station'],
            ], 200, ['X-Pages' => '2', 'Expires' => $expiry]);
        Http::fake(['*' => $sequence]);

        $snapshot = $this->source()->fetchComplete('character', 123);

        self::assertCount(2, $snapshot->rows);
        self::assertSame(2, $snapshot->pagesReceived);
        self::assertTrue($snapshot->allPagesFetched);
        Http::assertSentCount(2);
    }

    public function test_it_rejects_page_count_changes(): void
    {
        $expiry = $this->expiry();
        $sequence = Http::sequence()
            ->push([], 200, ['X-Pages' => '2', 'Expires' => $expiry])
            ->push([], 200, ['X-Pages' => '3', 'Expires' => $expiry]);
        Http::fake(['*' => $sequence]);

        $this->expectException(RuntimeException::class);
        $this->source()->fetchComplete('character', 123);
    }
}
