<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Triglavian\AssetManager\Services\Assets\CharacterPortfolioResolver;

class WalletJournalController extends Controller
{
    public function __invoke(Request $request, CharacterPortfolioResolver $portfolioResolver)
    {
        abort_unless(config('assetmgr.enabled', false), 404);

        try {
            $characterIds = $portfolioResolver->characterIdsFor($request->user());
        } catch (RuntimeException $exception) {
            abort(503, 'Asset Manager is not yet connected to SeAT character ownership.');
        }

        $query = DB::table('assetmgr_financial_records')
            ->where('owner_kind', 'character')
            ->where('record_kind', 'wallet_journal')
            ->whereIn('owner_id', $characterIds);

        $characterId = $request->integer('character_id') ?: null;
        if ($characterId !== null) {
            if (! in_array($characterId, $characterIds, true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('character_id', $characterId);
            }
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
            ->where('owner_kind', 'character')
            ->whereIn('owner_id', $characterIds)
            ->where('dataset', 'wallet_journal')
            ->orderBy('owner_id')
            ->get();

        return view('assetmgr::wallet-journal', [
            'records' => $query->orderByDesc('record_at')->orderByDesc('external_id')->paginate(50)->withQueryString(),
            'search' => $search,
            'characterId' => $characterId,
            'syncStates' => $syncStates,
        ]);
    }
}
