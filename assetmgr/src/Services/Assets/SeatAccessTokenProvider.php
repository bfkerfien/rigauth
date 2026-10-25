<?php

namespace Triglavian\AssetManager\Services\Assets;

use RuntimeException;
use Seat\Eveapi\Models\RefreshToken;

class SeatAccessTokenProvider implements AssetAccessTokenProvider
{
    public function accessTokenFor(string $ownerKind, int $ownerId): string
    {
        if ($ownerKind !== 'character') {
            // Corporation sync needs a separate director/scope-aware token resolver.
            // Do not silently use an arbitrary character token for corporation data.
            throw new RuntimeException('Corporation asset token resolution is not configured yet.');
        }

        $tokenRecord = RefreshToken::query()->where('character_id', $ownerId)->first();
        if ($tokenRecord === null) {
            throw new RuntimeException('No active SeAT refresh-token record exists for this character.');
        }

        if (! $tokenRecord->hasScope('esi-assets.read_assets.v1')) {
            throw new RuntimeException('This character has not granted the ESI asset-read scope.');
        }

        // SeAT's model accessor returns an empty token when the access token is expired.
        // Token refresh itself remains SeAT's responsibility.
        $accessToken = $tokenRecord->getAccessToken();
        if ($accessToken === '') {
            throw new RuntimeException('The SeAT access token is expired or unavailable; refresh this character in SeAT.');
        }

        return $accessToken;
    }
}
