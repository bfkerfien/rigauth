<?php

namespace Triglavian\AssetManager\Services\Assets;

use InvalidArgumentException;

final class CompleteAssetSnapshot
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public readonly string $ownerKind,
        public readonly int $ownerId,
        public readonly array $rows,
        public readonly int $pagesReceived,
        public readonly string $fetchedAt,
        public readonly bool $allPagesFetched,
    ) {
        if (! in_array($ownerKind, ['character', 'corporation'], true) || $ownerId < 1) {
            throw new InvalidArgumentException('Snapshot owner is invalid.');
        }
        if ($pagesReceived < 1 || ! $allPagesFetched) {
            throw new InvalidArgumentException('Incomplete asset snapshots cannot be committed.');
        }
        foreach ($rows as $row) {
            if (! isset($row['type_id'], $row['location_id'])) {
                throw new InvalidArgumentException('Snapshot row is missing required ESI identifiers.');
            }
            if ((int) $row['type_id'] < 1 || (int) $row['location_id'] < 1) {
                throw new InvalidArgumentException('Snapshot row contains invalid ESI identifiers.');
            }
        }
    }
}
