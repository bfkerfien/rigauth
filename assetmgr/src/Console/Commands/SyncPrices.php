<?php

namespace Triglavian\AssetManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Triglavian\AssetManager\Jobs\RefreshMarketPrice;

class SyncPrices extends Command
{
    protected $signature = 'assetmgr:sync-prices';
    protected $description = 'Queue cached Jita and regional market price refreshes for inventory types';

    public function handle(): int
    {
        if (! config('assetmgr.enabled', false) || ! config('assetmgr.sync.enabled', false)) {
            $this->error('Asset Manager or synchronization is disabled in configuration.');
            return self::FAILURE;
        }

        $typeIds = DB::table('assetmgr_assets')->distinct()->orderBy('type_id')->pluck('type_id');
        $regionIds = DB::table('assetmgr_assets')->whereNotNull('regional_region_id')
            ->distinct()->pluck('regional_region_id')->map(static fn ($id): int => (int) $id)->all();
        $regionIds[] = (int) config('assetmgr.pricing.jita_region_id', 10000002);
        $regionIds = array_values(array_unique(array_filter($regionIds, static fn (int $id): bool => $id > 0)));

        $queued = 0;
        $skipped = 0;
        foreach ($regionIds as $regionId) {
            foreach ($typeIds as $typeId) {
                $typeId = (int) $typeId;
                $cached = DB::table('assetmgr_prices')
                    ->where('type_id', $typeId)
                    ->where('region_id', $regionId)
                    ->where('source', 'esi-orders')
                    ->orderByDesc('fetched_at')
                    ->first();

                if ($cached && $cached->source_expires_at && \Illuminate\Support\Carbon::parse($cached->source_expires_at)->isFuture()) {
                    $skipped++;
                    continue;
                }

                RefreshMarketPrice::dispatch((int) $regionId, $typeId);
                $queued++;
            }
        }

        $this->info(sprintf('Queued %d region/type price refresh(es); skipped %d still cached by ESI.', $queued, $skipped));
        return self::SUCCESS;
    }
}
