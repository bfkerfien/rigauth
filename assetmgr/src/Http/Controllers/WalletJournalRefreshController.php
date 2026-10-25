<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\RefreshToken;
use RuntimeException;
use Triglavian\AssetManager\Jobs\SyncWalletJournal;
use Triglavian\AssetManager\Services\Assets\CharacterPortfolioResolver;

class WalletJournalRefreshController extends Controller
{
    public function __invoke(Request $request, CharacterPortfolioResolver $portfolioResolver)
    {
        abort_unless(config('assetmgr.enabled', false) && config('assetmgr.sync.enabled', false), 404);

        try {
            $characterIds = $portfolioResolver->characterIdsFor($request->user());
        } catch (RuntimeException $exception) {
            abort(503, 'Asset Manager is not yet connected to SeAT character ownership.');
        }

        $eligibleIds = RefreshToken::query()
            ->whereIn('character_id', $characterIds)
            ->whereJsonContains('scopes', 'esi-wallet.read_character_wallet.v1')
            ->pluck('character_id')->map(static fn ($id): int => (int) $id)->all();

        $queued = 0;
        $skipped = 0;
        foreach ($characterIds as $characterId) {
            if (! in_array((int) $characterId, $eligibleIds, true)) {
                $skipped++;
                continue;
            }
            $state = DB::table('assetmgr_sync_state')
                ->where('owner_kind', 'character')
                ->where('owner_id', $characterId)
                ->where('dataset', 'wallet_journal')
                ->first();

            if ($state?->next_eligible_at && Carbon::parse($state->next_eligible_at)->isFuture()) {
                $skipped++;
                continue;
            }

            SyncWalletJournal::dispatch((int) $characterId);
            $queued++;
        }

        return redirect()->route('assetmgr.wallet-journal')->with(
            'status',
            sprintf('Queued %d linked character wallet-journal sync(s); skipped %d without wallet scope or still inside ESI cache cooldown.', $queued, $skipped)
        );
    }
}
