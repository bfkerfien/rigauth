<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class CorporationMarketOrdersController extends Controller
{
    public function __invoke(Request $request, int $corporationId)
    {
        abort_unless(config('assetmgr.enabled', false) && config('assetmgr.corporation_sync_enabled', false), 404);
        abort_unless(in_array($corporationId, config('assetmgr.allowed_corporation_ids', []), true), 404);

        $corporation = CorporationInfo::query()->where('corporation_id', $corporationId)->first();
        abort_unless($corporation !== null, 404);
        Gate::authorize('corporation.assetmgr_market_orders_view', $corporation);

        $query = DB::table('assetmgr_market_orders')
            ->where('owner_kind', 'corporation')
            ->where('owner_id', $corporationId);

        $side = (string) $request->query('side', '');
        if (in_array($side, ['buy', 'sell'], true)) $query->where('is_buy_order', $side === 'buy');

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\\\') . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('type_name', 'like', $like)
                    ->orWhere('location_name', 'like', $like)
                    ->orWhere('order_id', 'like', $like)
                    ->orWhere('issued_by', 'like', $like);
            });
        }

        $syncState = DB::table('assetmgr_sync_state')
            ->where('owner_kind', 'corporation')
            ->where('owner_id', $corporationId)
            ->where('dataset', 'corporation_market_orders')
            ->first();

        return view('assetmgr::corporation-market-orders', [
            'orders' => $query->orderByDesc('issued')->orderByDesc('order_id')->paginate(50)->withQueryString(),
            'search' => $search,
            'side' => $side,
            'corporationId' => $corporationId,
            'corporationName' => $corporation->name ?? ('Corporation ' . $corporationId),
            'canViewCorporationWalletJournal' => Gate::allows('corporation.assetmgr_wallet_journal_view', $corporation),
            'syncState' => $syncState,
        ]);
    }
}
