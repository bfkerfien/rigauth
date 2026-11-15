<?php

namespace Triglavian\AssetManager\Services\Finance;

interface CorporationWalletJournalSource
{
    public function fetchSince(int $corporationId, int $division, ?string $lastKnownId = null): CorporationWalletJournalBatch;
}
