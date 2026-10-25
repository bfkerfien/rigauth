<?php

namespace Triglavian\AssetManager\Services\Finance;

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
    }
}
