<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Triglavian\AssetManager\Jobs\SyncCorporationMarketOrder;

class CorporationMarketOrdersRefreshController extends Controller
{
    public function __invoke(Request $request, int $corporationId)
    {
        abort_unless(config('assetmgr.enabled', false) && config('assetmgr.sync.enabled', false) && config('assetmgr.corporation_sync_enabled', false), 404);
        abort_unless(in_array($corporationId, config('assetmgr.allowed_corporation_ids', []), true), 404);

        $corporation = CorporationInfo::query()->where('corporation_id', $corporationId)->first();
        abort_unless($corporation !== null, 404);
        Gate::authorize('corporation.assetmgr_market_orders_view', $corporation);

        $state = DB::table('assetmgr_sync_state')
            ->where('owner_kind', 'corporation')
            ->where('owner_id', $corporationId)
            ->where('dataset', 'corporation_market_orders')
            ->first();
        if ($state?->next_eligible_at && Carbon::parse($state->next_eligible_at)->isFuture()) {
            return redirect()->route('assetmgr.corporation-market-orders', ['corporationId' => $corporationId])
                ->with('status', 'Corporation market orders are still inside the ESI cache cooldown.');
        }

        SyncCorporationMarketOrder::dispatch($corporationId);

        return redirect()->route('assetmgr.corporation-market-orders', ['corporationId' => $corporationId])
            ->with('status', 'Queued corporation market order sync.');
    }
}
