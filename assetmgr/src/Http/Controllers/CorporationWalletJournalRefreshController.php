<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Triglavian\AssetManager\Jobs\SyncCorporationWalletJournal;

class CorporationWalletJournalRefreshController extends Controller
{
    public function __invoke(Request $request, int $corporationId)
    {
        abort_unless(config('assetmgr.enabled', false) && config('assetmgr.sync.enabled', false) && config('assetmgr.corporation_sync_enabled', false), 404);
        abort_unless(in_array($corporationId, config('assetmgr.allowed_corporation_ids', []), true), 404);

        $corporation = CorporationInfo::query()->where('corporation_id', $corporationId)->first();
        abort_unless($corporation !== null, 404);
        Gate::authorize('corporation.assetmgr_wallet_journal_view', $corporation);

        $queued = 0;
        $skipped = 0;
        for ($division = 1; $division <= 7; $division++) {
            $dataset = 'corp_wallet_journal_' . $division;
            $state = DB::table('assetmgr_sync_state')
                ->where('owner_kind', 'corporation')
                ->where('owner_id', $corporationId)
                ->where('dataset', $dataset)
                ->first();

            if ($state?->next_eligible_at && Carbon::parse($state->next_eligible_at)->isFuture()) {
                $skipped++;
                continue;
            }

            SyncCorporationWalletJournal::dispatch($corporationId, $division);
            $queued++;
        }

        return redirect()->route('assetmgr.corporation-wallet-journal', ['corporationId' => $corporationId])
            ->with('status', sprintf('Queued %d corporation wallet division sync(s); skipped %d inside ESI cache cooldown.', $queued, $skipped));
    }
}
