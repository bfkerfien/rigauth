<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Triglavian\AssetManager\Services\Assets\AssetQuery;
use Triglavian\AssetManager\Services\Assets\CharacterPortfolioResolver;

class AssetIndexController extends Controller
{
    public function __invoke(Request $request, CharacterPortfolioResolver $portfolioResolver, AssetQuery $assetQuery): View
    {
        abort_unless(config('assetmgr.enabled', false), 404);

        try {
            $characterIds = $portfolioResolver->characterIdsFor($request->user());
        } catch (RuntimeException $exception) {
            Log::warning('Asset Manager character portfolio integration is not configured.', [
                'exception' => get_class($exception),
            ]);
            abort(503, 'Asset Manager is not yet connected to SeAT character ownership.');
        }

        $filters = [
            'q' => $request->query('q', ''),
            'character_id' => $request->integer('character_id') ?: null,
            'sort' => $request->query('sort', 'character'),
            'direction' => $request->query('direction', 'asc'),
        ];

        $currentValuation = DB::query()
            ->fromSub($assetQuery->personal($characterIds)->reorder(), 'inventory')
            ->selectRaw('COUNT(*) AS asset_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) AS quantity_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN jita_unit_price IS NOT NULL THEN jita_unit_price * quantity ELSE 0 END), 0) AS jita_total_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN regional_unit_price IS NOT NULL THEN regional_unit_price * quantity ELSE 0 END), 0) AS regional_total_value')
            ->selectRaw('SUM(CASE WHEN jita_unit_price IS NULL THEN 1 ELSE 0 END) AS jita_unpriced_count')
            ->selectRaw('SUM(CASE WHEN regional_unit_price IS NULL THEN 1 ELSE 0 END) AS regional_unpriced_count')
            ->first();

        $valuationHistory = DB::table('assetmgr_valuation_snapshots')
            ->where('owner_kind', 'character')
            ->whereIn('owner_id', $characterIds)
            ->selectRaw('snapshot_at, SUM(jita_total_value) AS jita_total_value, SUM(regional_total_value) AS regional_total_value, SUM(jita_unpriced_count) AS jita_unpriced_count, SUM(regional_unpriced_count) AS regional_unpriced_count')
            ->groupBy('snapshot_at')
            ->orderByDesc('snapshot_at')
            ->limit(24)
            ->get();

        $syncStates = DB::table('assetmgr_sync_state')
            ->where('owner_kind', 'character')
            ->whereIn('owner_id', $characterIds)
            ->where('dataset', 'assets')
            ->orderBy('owner_id')
            ->get();

        return view('assetmgr::index', [
            'assets' => $assetQuery->personal($characterIds, $filters)->paginate(50)->withQueryString(),
            'syncStates' => $syncStates,
            'currentValuation' => $currentValuation,
            'valuationHistory' => $valuationHistory,
            'search' => (string) $filters['q'],
            'characterId' => $filters['character_id'],
            'sort' => $filters['sort'],
            'direction' => $filters['direction'],
        ]);
    }
}
