<?php

namespace Triglavian\AssetManager\Services\Markets;

use InvalidArgumentException;

final class CorporationMarketOrderSnapshot
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public readonly int $corporationId,
        public readonly array $rows,
        public readonly int $pagesReceived,
        public readonly string $fetchedAt,
        public readonly ?string $sourceExpiresAt,
        public readonly bool $allPagesFetched,
    ) {
        if ($corporationId < 1 || $pagesReceived < 1 || ! $allPagesFetched) {
            throw new InvalidArgumentException('Corporation market order snapshot is incomplete.');
        }

        foreach ($rows as $row) {
            if (! isset($row['order_id'], $row['type_id'], $row['location_id'], $row['price'])) {
                throw new InvalidArgumentException('Corporation market order row is missing stable identifiers.');
            }
        }
    }
}
