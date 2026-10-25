<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
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
        Gate::authorize('corporation.assetmgr_view', $corporationId);

        $filters = [
            'q' => $request->query('q', ''),
            'sort' => $request->query('sort', 'item'),
            'direction' => $request->query('direction', 'asc'),
        ];

        return view('assetmgr::corporation', [
            'assets' => $assetQuery->corporation($corporationId, $filters)->paginate(50)->withQueryString(),
            'search' => (string) $filters['q'],
            'sort' => $filters['sort'],
            'direction' => $filters['direction'],
            'corporationId' => $corporationId,
            'corporationName' => \Seat\Eveapi\Models\Corporation\CorporationInfo::query()
                ->where('corporation_id', $corporationId)->value('name') ?? ('Corporation ' . $corporationId),
        ]);
    }
}
