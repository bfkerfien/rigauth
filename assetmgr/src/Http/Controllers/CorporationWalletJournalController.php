<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class CorporationWalletJournalController extends Controller
{
    public function __invoke(Request $request, int $corporationId)
    {
        abort_unless(config('assetmgr.enabled', false) && config('assetmgr.corporation_sync_enabled', false), 404);
        abort_unless(in_array($corporationId, config('assetmgr.allowed_corporation_ids', []), true), 404);

        $corporation = CorporationInfo::query()->where('corporation_id', $corporationId)->first();
        abort_unless($corporation !== null, 404);
        Gate::authorize('corporation.assetmgr_wallet_journal_view', $corporation);

        $query = DB::table('assetmgr_financial_records')
            ->where('owner_kind', 'corporation')
            ->where('owner_id', $corporationId)
            ->where('record_kind', 'corporation_wallet_journal');

        $division = $request->integer('division') ?: null;
        if ($division !== null) {
            if ($division < 1 || $division > 7) $query->whereRaw('1 = 0');
            else $query->where('wallet_division', $division);
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\\\') . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('description', 'like', $like)
                    ->orWhere('reason', 'like', $like)
                    ->orWhere('reference_type', 'like', $like)
                    ->orWhere('first_party_id', 'like', $like)
                    ->orWhere('second_party_id', 'like', $like);
            });
        }

        $syncStates = DB::table('assetmgr_sync_state')
            ->where('owner_kind', 'corporation')
            ->where('owner_id', $corporationId)
            ->whereIn('dataset', array_map(static fn (int $division): string => 'corp_wallet_journal_' . $division, range(1, 7)))
            ->orderBy('dataset')
            ->get();

        return view('assetmgr::corporation-wallet-journal', [
            'records' => $query->orderByDesc('record_at')->orderByDesc('external_id')->paginate(50)->withQueryString(),
            'search' => $search,
            'division' => $division,
            'corporationId' => $corporationId,
            'corporationName' => $corporation->name ?? ('Corporation ' . $corporationId),
            'canViewCorporationMarketOrders' => Gate::allows('corporation.assetmgr_market_orders_view', $corporation),
            'syncStates' => $syncStates,
        ]);
    }
}
