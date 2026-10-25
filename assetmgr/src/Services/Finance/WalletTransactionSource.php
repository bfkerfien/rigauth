<?php

namespace Triglavian\AssetManager\Services\Finance;

interface WalletTransactionSource
{
    public function fetchSince(int $characterId, ?string $lastKnownId = null): WalletTransactionSnapshot;
}
