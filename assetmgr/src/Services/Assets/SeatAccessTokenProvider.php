<?php

namespace Triglavian\AssetManager\Services\Assets;

use RuntimeException;
use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\Character\CharacterRole;
use Seat\Eveapi\Models\RefreshToken;

class SeatAccessTokenProvider implements AssetAccessTokenProvider
{
    public function accessTokenFor(string $ownerKind, int $ownerId): string
    {
        if (! in_array($ownerKind, ['character', 'corporation'], true) || $ownerId < 1) {
            throw new RuntimeException('Invalid owner for SeAT token resolution.');
        }

        if ($ownerKind === 'character') {
            $tokenRecord = RefreshToken::query()->where('character_id', $ownerId)->first();

            if ($tokenRecord === null) {
                throw new RuntimeException('No active SeAT refresh-token record exists for this character.');
            }
            if (! $tokenRecord->hasScope('esi-assets.read_assets.v1')) {
                throw new RuntimeException('This character has not granted the ESI asset-read scope.');
            }

            return $this->currentAccessToken($tokenRecord);
        }

        // Corporation assets require both the corporation scope and a Director role
        // on a character whose current SeAT affiliation matches this corporation.
        $tokens = RefreshToken::query()
            ->where('expires_on', '>', now())
            ->whereJsonContains('scopes', 'esi-assets.read_corporation_assets.v1')
            ->get();

        if ($tokens->isEmpty()) {
            throw new RuntimeException('No active SeAT token has the corporation asset scope.');
        }

        $directorIds = CharacterRole::query()
            ->where('scope', 'roles')
            ->where('role', 'Director')
            ->whereIn('character_id', $tokens->pluck('character_id'))
            ->pluck('character_id');

        if ($directorIds->isEmpty()) {
            throw new RuntimeException('No token-linked character has the Director role in SeAT.');
        }

        $corporationDirectorIds = CharacterAffiliation::query()
            ->where('corporation_id', $ownerId)
            ->whereIn('character_id', $directorIds)
            ->pluck('character_id');

        $tokenRecord = $tokens
            ->whereIn('character_id', $corporationDirectorIds)
            ->sortByDesc('expires_on')
            ->first();

        if ($tokenRecord === null || ! $tokenRecord->hasScope('esi-assets.read_corporation_assets.v1')) {
            throw new RuntimeException('No active Director token is authorized for this corporation.');
        }

        return $this->currentAccessToken($tokenRecord);
    }

    private function currentAccessToken(RefreshToken $tokenRecord): string
    {
        // SeAT owns refresh-token rotation; this package never stores tokens.
        $accessToken = $tokenRecord->getAccessToken();
        if ($accessToken === '') {
            throw new RuntimeException('The SeAT access token is expired or unavailable; refresh this character in SeAT.');
        }

        return $accessToken;
    }
}
