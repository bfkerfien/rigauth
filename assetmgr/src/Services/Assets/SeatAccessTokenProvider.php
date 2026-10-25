<?php

namespace Triglavian\AssetManager\Services\Assets;

use RuntimeException;
use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\Character\CharacterRole;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Eveapi\Jobs\Token\RefreshAccessToken;

class SeatAccessTokenProvider implements AssetAccessTokenProvider
{
    public function accessTokenFor(string $ownerKind, int $ownerId, ?string $requiredScope = null): string
    {
        if (! in_array($ownerKind, ['character', 'corporation'], true) || $ownerId < 1) {
            throw new RuntimeException('Invalid owner for SeAT token resolution.');
        }

        if ($ownerKind === 'character') {
            $requiredScope ??= 'esi-assets.read_assets.v1';
            $tokenRecord = RefreshToken::query()->where('character_id', $ownerId)->first();

            if ($tokenRecord === null) {
                throw new RuntimeException('No active SeAT refresh-token record exists for this character.');
            }
            if (! $tokenRecord->hasScope($requiredScope)) {
                throw new RuntimeException('This character has not granted the requested ESI scope.');
            }

            return $this->currentAccessToken($tokenRecord);
        }

        // Corporation access requires the requested scope and an appropriate role
        // on a character whose current SeAT affiliation matches this corporation.
        $requiredScope ??= 'esi-assets.read_corporation_assets.v1';
        $requiredRoles = $requiredScope === 'esi-assets.read_corporation_assets.v1' ? ['Director'] : ['Director', 'Accountant'];
        $tokens = RefreshToken::query()
            ->whereJsonContains('scopes', $requiredScope)
            ->get();

        if ($tokens->isEmpty()) {
            throw new RuntimeException('No active SeAT token has the requested corporation scope.');
        }

        $directorIds = CharacterRole::query()
            ->where('scope', 'roles')
            ->whereIn('role', $requiredRoles)
            ->whereIn('character_id', $tokens->pluck('character_id'))
            ->pluck('character_id');

        if ($directorIds->isEmpty()) {
            throw new RuntimeException('No token-linked character has a compatible corporation role in SeAT.');
        }

        $corporationDirectorIds = CharacterAffiliation::query()
            ->where('corporation_id', $ownerId)
            ->whereIn('character_id', $directorIds)
            ->pluck('character_id');

        $tokenRecord = $tokens
            ->whereIn('character_id', $corporationDirectorIds)
            ->sortByDesc('expires_on')
            ->first();

        if ($tokenRecord === null || ! $tokenRecord->hasScope($requiredScope)) {
            throw new RuntimeException('No active corporation-role token is authorized for this corporation.');
        }

        return $this->currentAccessToken($tokenRecord);
    }

    private function currentAccessToken(RefreshToken $tokenRecord): string
    {
        // Use SeAT's own refresh-token job; never implement or store token rotation here.
        if ($tokenRecord->isExpired()) {
            RefreshAccessToken::dispatchSync($tokenRecord);
            $tokenRecord->refresh();
        }
        $accessToken = $tokenRecord->getAccessToken();
        if ($accessToken === '' || $tokenRecord->isExpired()) {
            throw new RuntimeException('The SeAT access token is expired or unavailable; refresh this character in SeAT.');
        }

        return $accessToken;
    }
}
