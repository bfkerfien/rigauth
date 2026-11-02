<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Triglavian\AssetManager\Services\Assets\CharacterPortfolioResolver;

class ContractsController extends Controller
{
    public function __invoke(Request $request, CharacterPortfolioResolver $portfolioResolver)
    {
        abort_unless(config('assetmgr.enabled', false), 404);
        try {
            $characterIds = $portfolioResolver->characterIdsFor($request->user());
        } catch (RuntimeException $exception) {
            abort(503, 'Asset Manager is not yet connected to SeAT character ownership.');
        }

        $query = DB::table('assetmgr_contracts')
            ->where('owner_kind', 'character')
            ->whereIn('owner_id', $characterIds);

        $characterId = $request->integer('character_id') ?: null;
        if ($characterId !== null) {
            if (! in_array($characterId, $characterIds, true)) $query->whereRaw('1 = 0');
            else $query->where('character_id', $characterId);
        }

        $status = trim((string) $request->query('status', ''));
        if ($status !== '') $query->where('status', $status);

        $type = trim((string) $request->query('type', ''));
        if ($type !== '') $query->where('contract_type', $type);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\\\') . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('title', 'like', $like)
                    ->orWhere('contract_id', 'like', $like)
                    ->orWhere('character_name', 'like', $like)
                    ->orWhere('status', 'like', $like);
            });
        }

        $syncStates = DB::table('assetmgr_sync_state')
            ->where('owner_kind', 'character')
            ->whereIn('owner_id', $characterIds)
            ->where('dataset', 'contracts')
            ->orderBy('owner_id')
            ->get();

        return view('assetmgr::contracts', [
            'contracts' => $query->orderByDesc('date_issued')->orderByDesc('contract_id')->paginate(50)->withQueryString(),
            'search' => $search,
            'status' => $status,
            'type' => $type,
            'characterId' => $characterId,
            'syncStates' => $syncStates,
        ]);
    }
}
