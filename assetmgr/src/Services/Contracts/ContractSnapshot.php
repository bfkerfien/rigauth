<?php

namespace Triglavian\AssetManager\Services\Contracts;

use InvalidArgumentException;

final class ContractSnapshot
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
            throw new InvalidArgumentException('Contract snapshot is incomplete.');
        }

        foreach ($rows as $row) {
            if (! isset($row['contract_id'], $row['contract_type'], $row['status'])) {
                throw new InvalidArgumentException('Contract row is missing stable identifiers.');
            }
        }
    }
}
