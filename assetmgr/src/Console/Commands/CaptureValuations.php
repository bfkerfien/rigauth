<?php

namespace Triglavian\AssetManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CaptureValuations extends Command
{
    protected $signature = 'assetmgr:capture-valuations';
    protected $description = 'Store hourly cached inventory valuation snapshots for personal and allowlisted corporation assets';

    public function handle(): int
    {
        if (! config('assetmgr.enabled', false)) {
            $this->error('Asset Manager is disabled in configuration.');
            return self::FAILURE;
        }

        $snapshotAt = now()->startOfHour();
        $owners = DB::table('assetmgr_assets')
            ->select(['owner_kind', 'owner_id'])
            ->distinct()
            ->orderBy('owner_kind')
            ->orderBy('owner_id')
            ->get();

        $captured = 0;
        foreach ($owners as $owner) {
            $jitaPrices = DB::table('assetmgr_prices')
                ->select(['type_id', 'unit_price'])
                ->where('region_id', (int) config('assetmgr.pricing.jita_region_id', 10000002))
                ->where('source', 'esi-orders')
                ->where('price_basis', 'sell');

            $regionalPrices = DB::table('assetmgr_prices')
                ->select(['region_id', 'type_id', 'unit_price'])
                ->where('source', 'esi-orders')
                ->where('price_basis', 'sell');

            $totals = DB::table('assetmgr_assets as assets')
                ->leftJoinSub($jitaPrices, 'jita_prices', static function ($join): void {
                    $join->on('assets.type_id', '=', 'jita_prices.type_id');
                })
                ->leftJoinSub($regionalPrices, 'regional_prices', static function ($join): void {
                    $join->on('assets.type_id', '=', 'regional_prices.type_id')
                        ->on('assets.regional_region_id', '=', 'regional_prices.region_id');
                })
                ->where('assets.owner_kind', $owner->owner_kind)
                ->where('assets.owner_id', $owner->owner_id)
                ->selectRaw('COUNT(*) AS asset_count')
                ->selectRaw('COALESCE(SUM(assets.quantity), 0) AS quantity_sum')
                ->selectRaw('COALESCE(SUM(CASE WHEN jita_prices.unit_price IS NOT NULL THEN jita_prices.unit_price * assets.quantity ELSE 0 END), 0) AS jita_total_value')
                ->selectRaw('COALESCE(SUM(CASE WHEN regional_prices.unit_price IS NOT NULL THEN regional_prices.unit_price * assets.quantity ELSE 0 END), 0) AS regional_total_value')
                ->selectRaw('SUM(CASE WHEN jita_prices.unit_price IS NULL THEN 1 ELSE 0 END) AS jita_unpriced_count')
                ->selectRaw('SUM(CASE WHEN regional_prices.unit_price IS NULL THEN 1 ELSE 0 END) AS regional_unpriced_count')
                ->first();

            DB::table('assetmgr_valuation_snapshots')->updateOrInsert(
                [
                    'owner_kind' => $owner->owner_kind,
                    'owner_id' => $owner->owner_id,
                    'snapshot_at' => $snapshotAt,
                ],
                [
                    'asset_count' => (int) ($totals->asset_count ?? 0),
                    'quantity_sum' => (int) ($totals->quantity_sum ?? 0),
                    'jita_total_value' => $totals->jita_total_value ?? 0,
                    'regional_total_value' => $totals->regional_total_value ?? 0,
                    'jita_unpriced_count' => (int) ($totals->jita_unpriced_count ?? 0),
                    'regional_unpriced_count' => (int) ($totals->regional_unpriced_count ?? 0),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $captured++;
        }

        $this->info(sprintf('Captured %d hourly owner valuation snapshot(s). Missing prices are counted separately from the known subtotal.', $captured));
        return self::SUCCESS;
    }
}
