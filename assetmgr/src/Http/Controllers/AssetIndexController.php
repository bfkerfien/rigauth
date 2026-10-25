<?php

namespace Triglavian\\AssetManager\\Http\\Controllers;

use Illuminate\\Http\\Request;
use Illuminate\\Routing\\Controller;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\View\\View;

class AssetIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless(config('assetmgr.enabled', false), 404);

        /*
         * This initial query is deliberately local-only. The SeAT user-to-
         * character mapping and permission scopes must be wired to the actual
         * installed SeAT models before production use.
         */
        $query = DB::table('assetmgr_assets')
            ->orderBy('character_name')
            ->orderBy('type_name')
            ->select([
                'owner_kind',
                'owner_id',
                'character_id',
                'character_name',
                'type_id',
                'type_name',
                'quantity',
                'location_id',
                'location_name',
                'jita_unit_price',
                'regional_unit_price',
                'last_seen_at',
            ]);

        $characterId = $request->integer('character_id');
        if ($characterId > 0) {
            $query->where('character_id', $characterId);
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('type_name', 'like', '%' . addcslashes($search, '%_') . '%')
                    ->orWhere('character_name', 'like', '%' . addcslashes($search, '%_') . '%')
                    ->orWhere('location_name', 'like', '%' . addcslashes($search, '%_') . '%');
            });
        }

        return view('assetmgr::index', [
            'assets' => $query->paginate(50)->withQueryString(),
            'search' => $search,
            'characterId' => $characterId ?: null,
        ]);
    }
}
