<?php

namespace Triglavian\AssetManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Triglavian\AssetManager\Jobs\SyncCorporationMarketOrder;

class SyncCorporationMarketOrders extends Command
{
    protected $signature = 'assetmgr:sync-corporation-market-orders';
    protected $description = 'Queue allowlisted corporation market order syncs';

    public function handle(): int
    {
        if (! config('assetmgr.enabled', false) || ! config('assetmgr.sync.enabled', false) || ! config('assetmgr.corporation_sync_enabled', false)) {
            $this->error('Asset Manager or synchronization is disabled in configuration.');
            return self::FAILURE;
        }

        $corporationIds = array_values(array_unique(array_filter(
            array_map('intval', (array) config('assetmgr.allowed_corporation_ids', [])),
            static fn (int $id): bool => $id > 0
        )));
        if ($corporationIds === []) {
            $this->warn('No corporation IDs are allowlisted for Asset Manager sync.');
            return self::SUCCESS;
        }

        $queued = 0;
        $skipped = 0;
        foreach ($corporationIds as $corporationId) {
            $state = DB::table('assetmgr_sync_state')
                ->where('owner_kind', 'corporation')
                ->where('owner_id', $corporationId)
                ->where('dataset', 'corporation_market_orders')
                ->first();

            if ($state?->next_eligible_at && Carbon::parse($state->next_eligible_at)->isFuture()) {
                $skipped++;
                continue;
            }

            SyncCorporationMarketOrder::dispatch($corporationId);
            $queued++;
        }

        $this->info(sprintf('Queued %d allowlisted corporation order sync(s); skipped %d inside ESI cache cooldown.', $queued, $skipped));
        return self::SUCCESS;
    }
}
