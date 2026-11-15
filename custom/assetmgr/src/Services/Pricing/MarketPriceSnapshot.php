<?php

namespace Triglavian\AssetManager\Services\Pricing;

final class MarketPriceSnapshot
{
    public function __construct(
        public readonly int $typeId,
        public readonly int $regionId,
        public readonly ?string $bestBuy,
        public readonly ?string $bestSell,
        public readonly int $pagesReceived,
        public readonly string $fetchedAt,
        public readonly ?string $sourceExpiresAt,
    ) {
    }
}
