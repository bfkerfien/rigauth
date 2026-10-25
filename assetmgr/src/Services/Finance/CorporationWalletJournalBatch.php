<?php

namespace Triglavian\AssetManager\Services\Finance;

use InvalidArgumentException;

final class CorporationWalletJournalBatch
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public readonly int $corporationId,
        public readonly int $division,
        public readonly array $rows,
        public readonly int $pagesReceived,
        public readonly string $fetchedAt,
        public readonly ?string $sourceExpiresAt,
    ) {
        if ($corporationId < 1 || $division < 1 || $division > 7 || $pagesReceived < 1) {
            throw new InvalidArgumentException('Corporation wallet journal batch identity or pagination is invalid.');
        }

        foreach ($rows as $row) {
            if (! isset($row['external_id'], $row['record_at'], $row['reference_type'])) {
                throw new InvalidArgumentException('Corporation wallet journal row is missing stable identifiers.');
            }
        }
    }
}
