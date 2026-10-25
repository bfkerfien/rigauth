<?php

namespace Triglavian\AssetManager\Services\Finance;

interface WalletJournalSource
{
    public function fetchSince(int $characterId, ?string $lastKnownId = null): WalletJournalSnapshot;
}
