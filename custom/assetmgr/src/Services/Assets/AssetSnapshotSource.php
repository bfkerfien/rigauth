<?php

namespace Triglavian\AssetManager\Services\Assets;

interface AssetSnapshotSource
{
    /**
     * Fetch and validate every page from ESI. Implementations must honor ESI
     * cache expiry and rate limits and throw on any incomplete page sequence.
     *
     * @throws \Throwable When the snapshot cannot be proven complete.
     */
    public function fetchComplete(string $ownerKind, int $ownerId): CompleteAssetSnapshot;
}
