<?php

namespace Triglavian\AssetManager\Services\Finance;

use InvalidArgumentException;

final class WalletTransactionSnapshot
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public readonly int $characterId,
        public readonly array $rows,
        public readonly int $requestsFetched,
        public readonly string $fetchedAt,
        public readonly ?string $sourceExpiresAt,
    ) {
        if ($characterId < 1 || $requestsFetched < 1) {
            throw new InvalidArgumentException('Wallet transaction snapshot identity or pagination is invalid.');
        }

        foreach ($rows as $row) {
            if (! isset($row['external_id'], $row['record_at'], $row['type_id'], $row['quantity'])) {
                throw new InvalidArgumentException('Wallet transaction row is missing stable identifiers.');
            }
        }
    }
}
