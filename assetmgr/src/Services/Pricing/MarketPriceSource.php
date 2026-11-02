<?php

namespace Triglavian\AssetManager\Services\Pricing;

interface MarketPriceSource
{
    public function fetch(int $regionId, int $typeId): MarketPriceSnapshot;
}
