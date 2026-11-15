<?php

namespace Triglavian\AssetManager\Services\Markets;

interface MarketOrderSource
{
    public function fetchComplete(int $characterId): MarketOrderSnapshot;
}
