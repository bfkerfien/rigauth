<?php

namespace Triglavian\AssetManager\Services\Finance;

interface WalletJournalSource
{
    public function fetchComplete(int $characterId): WalletJournalSnapshot;
}
