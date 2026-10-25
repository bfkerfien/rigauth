<?php

namespace Triglavian\AssetManager\Services\Assets;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class AssetSnapshotRepository
{
    /**
     * Store a complete, already-validated snapshot in a transaction.
     *
     * The caller must not invoke this until every ESI page has been fetched and
     * validated. Partial snapshots must never be reconciled as authoritative.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function replaceOwnerSnapshot(string $ownerKind, int $ownerId, array $rows): void
    {
        if (! in_array($ownerKind, ['character', 'corporation'], true) || $ownerId < 1) {
            throw new RuntimeException('Invalid asset snapshot owner.');
        }

        DB::transaction(function () use ($ownerKind, $ownerId, $rows): void {
            $now = now();

            foreach (array_chunk($rows, 500) as $chunk) {
                $prepared = array_map(static function (array $row) use ($ownerKind, $ownerId, $now): array {
                    return [
                        'owner_kind' => $ownerKind,
                        'owner_id' => $ownerId,
                        'character_id' => $row['character_id'] ?? null,
                        'character_name' => $row['character_name'] ?? null,
                        'corporation_id' => $row['corporation_id'] ?? null,
                        'item_id' => $row['item_id'] ?? null,
                        'type_id' => (int) $row['type_id'],
                        'type_name' => (string) ($row['type_name'] ?? ('Type ' . $row['type_id'])),
                        'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                        'location_id' => (int) $row['location_id'],
                        'location_name' => $row['location_name'] ?? null,
                        'location_flag' => $row['location_flag'] ?? null,
                        'parent_item_id' => $row['parent_item_id'] ?? null,
                        'regional_region_id' => $row['regional_region_id'] ?? null,
                        'last_seen_at' => $now,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ];
                }, $chunk);

                DB::table('assetmgr_assets')->upsert(
                    $prepared,
                    ['owner_kind', 'owner_id', 'item_id'],
                    [
                        'character_id', 'character_name', 'corporation_id', 'type_id',
                        'type_name', 'quantity', 'location_id', 'location_name',
                        'location_flag', 'parent_item_id', 'regional_region_id',
                        'last_seen_at', 'updated_at',
                    ]
                );
            }

            /*
             * Deletion reconciliation is intentionally not implemented in this
             * first scaffold: it requires a durable sync-run ID/staging table so
             * an empty or incomplete result can never erase good data.
             */
        });
    }
}
