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

        $syncStates = DB::table('assetmgr_sync_state')
            ->where('owner_kind', 'character')
            ->whereIn('owner_id', $characterIds)
            ->where('dataset', 'assets')
            ->orderBy('owner_id')
            ->get();

        return view('assetmgr::index', [
            'assets' => $assetQuery->personal($characterIds, $filters)->paginate(50)->withQueryString(),
            'syncStates' => $syncStates,
            'search' => (string) $filters['q'],
            'characterId' => $filters['character_id'],
            'sort' => $filters['sort'],
            'direction' => $filters['direction'],
        ]);
    }
}
