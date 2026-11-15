<?php

namespace Triglavian\AssetManager\Services\Markets;

interface CorporationMarketOrderSource
{
    public function fetchComplete(int $corporationId): CorporationMarketOrderSnapshot;
}
