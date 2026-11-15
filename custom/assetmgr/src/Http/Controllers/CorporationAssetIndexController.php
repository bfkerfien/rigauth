<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Illuminate\Support\Facades\DB;
use Triglavian\AssetManager\Services\Assets\AssetQuery;

class CorporationAssetIndexController extends Controller
{
    public function __invoke(Request $request, AssetQuery $assetQuery)
    {
        abort_unless(config('assetmgr.enabled', false) && config('assetmgr.corporation_sync_enabled', false), 404);

        $corporationId = $request->integer('corporation_id');
        $allowedCorporations = config('assetmgr.allowed_corporation_ids', []);
        abort_unless($corporationId > 0 && in_array($corporationId, $allowedCorporations, true), 404);

        // SeAT's corporation policy enforces CEO/Director or delegated corporation permission.
        $corporation = CorporationInfo::query()->where('corporation_id', $corporationId)->first();
        abort_unless($corporation !== null, 404);
        Gate::authorize('corporation.assetmgr_view', $corporation);

        $filters = [
            'q' => $request->query('q', ''),
            'sort' => $request->query('sort', 'item'),
            'parent_item_id' => $request->integer('parent_item_id') ?: null,
            'direction' => $request->query('direction', 'asc'),
        ];

        $valuationNow = now();
        $staleCutoff = $valuationNow->copy()->subSeconds((int) config('assetmgr.pricing.stale_after_seconds', 3600));
        $currentValuation = DB::query()
            ->fromSub($assetQuery->corporation($corporationId)->reorder(), 'inventory')
            ->selectRaw('COUNT(*) AS asset_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) AS quantity_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN jita_unit_price IS NOT NULL AND (jita_price_expires_at > ? OR (jita_price_expires_at IS NULL AND jita_price_updated_at >= ?)) THEN jita_unit_price * quantity ELSE 0 END), 0) AS jita_total_value', [$valuationNow, $staleCutoff])
            ->selectRaw('COALESCE(SUM(CASE WHEN regional_unit_price IS NOT NULL AND (regional_price_expires_at > ? OR (regional_price_expires_at IS NULL AND regional_price_updated_at >= ?)) THEN regional_unit_price * quantity ELSE 0 END), 0) AS regional_total_value', [$valuationNow, $staleCutoff])
            ->selectRaw('SUM(CASE WHEN jita_unit_price IS NULL OR (jita_price_expires_at IS NOT NULL AND jita_price_expires_at <= ?) OR (jita_price_expires_at IS NULL AND jita_price_updated_at < ?) THEN 1 ELSE 0 END) AS jita_unpriced_count', [$valuationNow, $staleCutoff])
            ->selectRaw('SUM(CASE WHEN regional_unit_price IS NULL OR (regional_price_expires_at IS NOT NULL AND regional_price_expires_at <= ?) OR (regional_price_expires_at IS NULL AND regional_price_updated_at < ?) THEN 1 ELSE 0 END) AS regional_unpriced_count', [$valuationNow, $staleCutoff])
            ->first();
        $valuationHistory = DB::table('assetmgr_valuation_snapshots')
            ->where('owner_kind', 'corporation')
            ->where('owner_id', $corporationId)
            ->orderByDesc('snapshot_at')
            ->limit(24)
            ->get();

        return view('assetmgr::corporation', [
            'assets' => $assetQuery->corporation($corporationId, $filters)->paginate(50)->withQueryString(),
            'currentValuation' => $currentValuation,
            'valuationHistory' => $valuationHistory,
            'search' => (string) $filters['q'],
            'parentItemId' => $filters['parent_item_id'],
            'sort' => $filters['sort'],
            'direction' => $filters['direction'],
            'corporationId' => $corporationId,
            'corporationName' => $corporation->name ?? ('Corporation ' . $corporationId),
            'canViewCorporationMarketOrders' => Gate::allows('corporation.assetmgr_market_orders_view', $corporation),
            'canViewCorporationWalletJournal' => Gate::allows('corporation.assetmgr_wallet_journal_view', $corporation),
        ]);
    }
}
