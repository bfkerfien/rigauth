<?php

namespace Triglavian\AssetManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\Character\CharacterRole;
use Seat\Eveapi\Models\RefreshToken;
use Triglavian\AssetManager\Jobs\SyncAssetOwner;

class SyncCorporations extends Command
{
    protected $signature = 'assetmgr:sync-corporations';
    protected $description = 'Queue eligible allowlisted corporation asset synchronizations';

    public function handle(): int
    {
        if (! config('assetmgr.enabled', false) || ! config('assetmgr.sync.enabled', false) || ! config('assetmgr.corporation_sync_enabled', false)) {
            $this->error('Asset Manager, synchronization, and corporation synchronization must all be enabled.');
            return self::FAILURE;
        }

        $allowed = config('assetmgr.allowed_corporation_ids', []);
        if ($allowed === []) {
            $this->error('No corporation IDs are allowlisted. Set SEAT_ASSETMGR_CORPORATION_IDS first.');
            return self::FAILURE;
        }

        $tokenCharacterIds = RefreshToken::query()
            ->where('expires_on', '>', now())
            ->whereJsonContains('scopes', 'esi-assets.read_corporation_assets.v1')
            ->pluck('character_id');

        $directorIds = CharacterRole::query()
            ->where('scope', 'roles')
            ->where('role', 'Director')
            ->whereIn('character_id', $tokenCharacterIds)
            ->pluck('character_id');

        $corporationIds = CharacterAffiliation::query()
            ->whereIn('character_id', $directorIds)
            ->whereIn('corporation_id', $allowed)
            ->distinct()
            ->pluck('corporation_id');

        $queued = 0;
        $skipped = 0;
        foreach ($corporationIds as $corporationId) {
            $corporationId = (int) $corporationId;
            $state = DB::table('assetmgr_sync_state')
                ->where('owner_kind', 'corporation')
                ->where('owner_id', $corporationId)
                ->where('dataset', 'assets')
                ->first();

            if ($state?->next_eligible_at && Carbon::parse($state->next_eligible_at)->isFuture()) {
                $skipped++;
                continue;
            }

            SyncAssetOwner::dispatch('corporation', $corporationId);
            $queued++;
        }

        $this->info(sprintf('Queued %d allowlisted corporation sync(s); skipped %d still within ESI cache cooldown.', $queued, $skipped));
        return self::SUCCESS;
    }
}
