<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Triglavian\AssetManager\Services\Assets\CharacterPortfolioResolver;

class MarketOrdersController extends Controller
{
    public function __invoke(Request $request, CharacterPortfolioResolver $portfolioResolver)
    {
        abort_unless(config('assetmgr.enabled', false), 404);
        try {
            $characterIds = $portfolioResolver->characterIdsFor($request->user());
        } catch (RuntimeException $exception) {
            abort(503, 'Asset Manager is not yet connected to SeAT character ownership.');
        }

        $query = DB::table('assetmgr_market_orders')
            ->where('owner_kind', 'character')
            ->whereIn('owner_id', $characterIds);

        $characterId = $request->integer('character_id') ?: null;
        if ($characterId !== null) {
            if (! in_array($characterId, $characterIds, true)) $query->whereRaw('1 = 0');
            else $query->where('character_id', $characterId);
        }

        $side = (string) $request->query('side', '');
        if (in_array($side, ['buy', 'sell'], true)) $query->where('is_buy_order', $side === 'buy');

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\\\') . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('type_name', 'like', $like)
                    ->orWhere('location_name', 'like', $like)
                    ->orWhere('order_id', 'like', $like)
                    ->orWhere('character_name', 'like', $like);
            });
        }

        $syncStates = DB::table('assetmgr_sync_state')
            ->where('owner_kind', 'character')
            ->whereIn('owner_id', $characterIds)
            ->where('dataset', 'market_orders')
            ->orderBy('owner_id')
            ->get();

        return view('assetmgr::market-orders', [
            'orders' => $query->orderByDesc('issued')->orderByDesc('order_id')->paginate(50)->withQueryString(),
            'search' => $search,
            'characterId' => $characterId,
            'side' => $side,
            'syncStates' => $syncStates,
        ]);
    }
}
