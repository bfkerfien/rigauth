<?php

namespace Triglavian\AssetManager\Services\Finance;

use InvalidArgumentException;

final class WalletJournalSnapshot
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public readonly int $characterId,
        public readonly array $rows,
        public readonly int $pagesReceived,
        public readonly string $fetchedAt,
        public readonly ?string $sourceExpiresAt,
    ) {
        if ($characterId < 1 || $pagesReceived < 1) {
            throw new InvalidArgumentException('Wallet journal snapshot identity or pagination is invalid.');
        }

        foreach ($rows as $row) {
            if (! isset($row['external_id'], $row['record_at'], $row['reference_type'])) {
                throw new InvalidArgumentException('Wallet journal row is missing stable identifiers.');
            }
        }
    }
}
