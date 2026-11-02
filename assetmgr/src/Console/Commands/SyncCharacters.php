<?php

namespace Triglavian\AssetManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\RefreshToken;
use Triglavian\AssetManager\Jobs\SyncAssetOwner;

class SyncCharacters extends Command
{
    protected $signature = 'assetmgr:sync-characters';
    protected $description = 'Queue eligible SeAT character asset synchronizations';

    public function handle(): int
    {
        if (! config('assetmgr.enabled', false) || ! config('assetmgr.sync.enabled', false)) {
            $this->error('Asset Manager or synchronization is disabled in configuration.');
            return self::FAILURE;
        }

        $queued = 0;
        $skipped = 0;
        RefreshToken::query()
            ->whereJsonContains('scopes', 'esi-assets.read_assets.v1')
            ->select('character_id')
            ->chunkById(250, function ($tokens) use (&$queued, &$skipped): void {
                foreach ($tokens as $token) {
                    $characterId = (int) $token->character_id;
                    $state = DB::table('assetmgr_sync_state')
                        ->where('owner_kind', 'character')
                        ->where('owner_id', $characterId)
                        ->where('dataset', 'assets')
                        ->first();

                    if ($state?->next_eligible_at && \Illuminate\Support\Carbon::parse($state->next_eligible_at)->isFuture()) {
                        $skipped++;
                        continue;
                    }

                    SyncAssetOwner::dispatch('character', $characterId);
                    $queued++;
                }
            }, 'character_id');

        $this->info(sprintf('Queued %d character asset sync(s); skipped %d still within ESI cache cooldown.', $queued, $skipped));
        return self::SUCCESS;
    }
}
