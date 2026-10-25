<?php

namespace Triglavian\AssetManager\Services\Assets;

interface AssetAccessTokenProvider
{
    /**
     * Obtain a current access token through SeAT's own token storage/refresh
     * services. Implementations must not log or persist the returned token in
     * this package's tables.
     */
    public function accessTokenFor(string $ownerKind, int $ownerId): string;
}
