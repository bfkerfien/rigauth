<?php

namespace Triglavian\AssetManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\RefreshToken;
use Triglavian\AssetManager\Jobs\SyncMarketOrders as SyncMarketOrdersJob;

class SyncMarketOrders extends Command
{
    protected $signature = 'assetmgr:sync-market-orders';
    protected $description = 'Queue active market order syncs for characters with the required SeAT ESI scope';

    public function handle(): int
    {
        if (! config('assetmgr.enabled', false) || ! config('assetmgr.sync.enabled', false)) {
            $this->error('Asset Manager or synchronization is disabled in configuration.');
            return self::FAILURE;
        }

        $queued = 0;
        $skipped = 0;
        RefreshToken::query()
            ->whereJsonContains('scopes', 'esi-markets.read_character_orders.v1')
            ->select('character_id')
            ->chunkById(250, function ($tokens) use (&$queued, &$skipped): void {
                foreach ($tokens as $token) {
                    $characterId = (int) $token->character_id;
                    $state = DB::table('assetmgr_sync_state')
                        ->where('owner_kind', 'character')
                        ->where('owner_id', $characterId)
                        ->where('dataset', 'market_orders')
                        ->first();

                    if ($state?->next_eligible_at && Carbon::parse($state->next_eligible_at)->isFuture()) {
                        $skipped++;
                        continue;
                    }

                    SyncMarketOrdersJob::dispatch($characterId);
                    $queued++;
                }
            }, 'character_id');

        $this->info(sprintf('Queued %d market order sync(s); skipped %d still inside ESI cache cooldown.', $queued, $skipped));
        return self::SUCCESS;
    }
}
