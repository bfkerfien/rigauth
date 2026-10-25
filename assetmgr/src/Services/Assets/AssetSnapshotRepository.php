<?php

namespace Triglavian\AssetManager\Services\Assets;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AssetSnapshotRepository
{
    /**
     * Commit only a validated complete snapshot. A staging run is created and
     * reconciled inside one transaction so failed inserts cannot erase the last
     * good snapshot.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function replaceOwnerSnapshot(string $ownerKind, int $ownerId, array $rows): void
    {
        if (! in_array($ownerKind, ['character', 'corporation'], true) || $ownerId < 1) {
            throw new RuntimeException('Invalid asset snapshot owner.');
        }

        $runId = (string) Str::uuid();
        DB::transaction(function () use ($ownerKind, $ownerId, $rows, $runId): void {
            $now = now();
            $staging = [];
            foreach ($rows as $row) {
                if (! isset($row['item_id'], $row['type_id'], $row['location_id'])) {
                    throw new RuntimeException('Asset row is missing a required item, type, or location ID.');
                }
                $itemId = (int) $row['item_id'];
                if ($itemId < 1 || (int) $row['type_id'] < 1 || (int) $row['location_id'] < 1) {
                    throw new RuntimeException('Asset row contains an invalid ESI identifier.');
                }
                $staging[] = [
                    'sync_run_id' => $runId,
                    'owner_kind' => $ownerKind,
                    'owner_id' => $ownerId,
                    'item_id' => $itemId,
                    'payload' => json_encode($row, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ];
            }

            foreach (array_chunk($staging, 500) as $chunk) {
                DB::table('assetmgr_asset_staging')->insert($chunk);
            }

            $prepared = [];
            foreach ($rows as $row) {
                $prepared[] = [
                    'owner_kind' => $ownerKind,
                    'owner_id' => $ownerId,
                    'character_id' => $row['character_id'] ?? ($ownerKind === 'character' ? $ownerId : null),
                    'character_name' => $row['character_name'] ?? null,
                    'corporation_id' => $row['corporation_id'] ?? ($ownerKind === 'corporation' ? $ownerId : null),
                    'corporation_name' => $row['corporation_name'] ?? null,
                    'item_id' => (int) $row['item_id'],
                    'type_id' => (int) $row['type_id'],
                    'type_name' => (string) ($row['type_name'] ?? ('Type ' . $row['type_id'])),
                    'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                    'location_id' => (int) $row['location_id'],
                    'location_name' => $row['location_name'] ?? null,
                    'location_flag' => $row['location_flag'] ?? null,
                    'location_type' => $row['location_type'] ?? null,
                    'parent_item_id' => $row['parent_item_id'] ?? null,
                    'regional_region_id' => $row['regional_region_id'] ?? null,
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ];
            }

            foreach (array_chunk($prepared, 500) as $chunk) {
                DB::table('assetmgr_assets')->upsert(
                    $chunk,
                    ['owner_kind', 'owner_id', 'item_id'],
                    [
                        'character_id', 'character_name', 'corporation_id', 'corporation_name', 'type_id',
                        'type_name', 'quantity', 'location_id', 'location_name',
                        'location_flag', 'location_type', 'parent_item_id', 'regional_region_id',
                        'last_seen_at', 'updated_at',
                    ]
                );
            }

            // This is safe only because callers can construct a CompleteAssetSnapshot
            // after every page succeeds. Empty complete snapshots intentionally clear an owner.
            DB::table('assetmgr_assets')
                ->where('owner_kind', $ownerKind)
                ->where('owner_id', $ownerId)
                ->whereNotIn('item_id', DB::table('assetmgr_asset_staging')
                    ->select('item_id')
                    ->where('sync_run_id', $runId)
                    ->where('owner_kind', $ownerKind)
                    ->where('owner_id', $ownerId))
                ->delete();

            DB::table('assetmgr_asset_staging')->where('sync_run_id', $runId)->delete();
        });
    }
}
