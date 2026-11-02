<?php

namespace Triglavian\AssetManager\Services\Markets;

use InvalidArgumentException;

final class MarketOrderSnapshot
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public readonly int $characterId,
        public readonly array $rows,
        public readonly int $pagesReceived,
        public readonly string $fetchedAt,
        public readonly ?string $sourceExpiresAt,
        public readonly bool $allPagesFetched,
    ) {
        if ($characterId < 1 || $pagesReceived < 1 || ! $allPagesFetched) {
            throw new InvalidArgumentException('Market order snapshot is incomplete.');
        }

        foreach ($rows as $row) {
            if (! isset($row['order_id'], $row['type_id'], $row['location_id'], $row['price'])) {
                throw new InvalidArgumentException('Market order row is missing stable identifiers.');
            }
        }
    }
}
