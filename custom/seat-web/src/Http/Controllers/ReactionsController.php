<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ReactionsController extends Controller
{
    public function market(Request $request)
    {
        abort_unless(auth()->check(), 401);
        $names = collect(explode(',', (string) $request->query('names', '')))
            ->map(fn ($name) => trim($name))->filter()->unique()->take(100)->values();
        if ($names->isEmpty()) {
            return response()->json(['error' => 'Provide at least one item name.'], 400);
        }
        $baseUrl = rtrim((string) config('services.gas_calculator.url', env('GAS_CALCULATOR_URL', 'https://gas.triglavianconsortium.com')), '/');
        $response = Http::acceptJson()->timeout(45)->retry(2, 300)
            ->get($baseUrl . '/api/market', ['names' => $names->implode(',')]);
        return response()->json($response->json(), $response->status());
    }

    public function recipe(Request $request)
    {
        abort_unless(auth()->check(), 401);
        $product = trim((string) $request->query('product', ''));
        $activity = (int) $request->query('activity', 1);
        if ($product === '' || !in_array($activity, [1, 11], true)) {
            return response()->json(['error' => 'Provide a product and activity 1 or 11.'], 400);
        }
        $baseUrl = rtrim((string) config('services.gas_calculator.url', env('GAS_CALCULATOR_URL', 'https://gas.triglavianconsortium.com')), '/');
        $response = Http::acceptJson()->timeout(30)->retry(2, 300)
            ->get($baseUrl . '/api/recipe', ['product' => $product, 'activity' => $activity]);
        return response()->json($response->json(), $response->status());
    }

    public function index(Request $request): View
    {
        abort_unless(auth()->check(), 401);

        $search = trim((string) $request->query('q', ''));
        $assets = collect();
        $inventoryError = null;

        if (!Schema::hasTable('assetmgr_assets')) {
            $inventoryError = 'Asset Manager inventory tables are not installed yet. Install/enable the Asset Manager package and sync assets.';
        } else {
            $user = $request->user();
            $characterIds = collect();
            if (method_exists($user, 'associatedCharacterIds')) {
                $characterIds = collect($user->associatedCharacterIds());
            } elseif (isset($user->characters)) {
                $characterIds = collect($user->characters)->map(fn ($character) => $character->character_id ?? $character->id);
            }
            $characterIds = $characterIds->map(fn ($id) => (int) $id)->filter()->unique()->values();

            if ($characterIds->isNotEmpty()) {
                $assets = DB::table('assetmgr_assets')
                    ->where('owner_kind', 'character')
                    ->whereIn('character_id', $characterIds)
                    ->where('quantity', '>', 0)
                    ->select(['owner_kind','character_name','type_id','type_name','quantity','location_id','location_name','jita_unit_price','regional_unit_price','last_seen_at'])
                    ->orderBy('type_name')->limit(20000)->get();
            }
        }

        $baseUrl = rtrim((string) config('services.gas_calculator.url', env('GAS_CALCULATOR_URL', 'https://gas.triglavianconsortium.com')), '/');
        $response = Http::acceptJson()->timeout(20)->retry(2, 300)->get($baseUrl . '/api/reactions', ['category' => 'all']);
        $recipeError = $response->successful() ? null : 'Could not load reaction formulas from gas.triglavianconsortium.com. The standalone calculator is unchanged.';
        $recipes = $response->successful() ? collect($response->json('products', [])) : collect();

        $byId = $assets->groupBy(fn ($asset) => (int) $asset->type_id);
        $byName = $assets->groupBy(fn ($asset) => mb_strtolower((string) $asset->type_name));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $recipes = $recipes->filter(function ($recipe) use ($needle) {
                if (str_contains(mb_strtolower((string) ($recipe['product'] ?? '')), $needle)) return true;
                foreach (($recipe['materials'] ?? []) as $material) if (str_contains(mb_strtolower((string) ($material['name'] ?? '')), $needle)) return true;
                foreach (($recipe['products'] ?? []) as $product) if (str_contains(mb_strtolower((string) ($product['name'] ?? '')), $needle)) return true;
                return false;
            });
        }

        $rows = $recipes->map(function ($recipe) use ($byId, $byName) {
            $materials = collect($recipe['materials'] ?? [])->map(function ($material) use ($byId, $byName) {
                $typeId = (int) ($material['typeId'] ?? $material['type_id'] ?? 0);
                $name = (string) ($material['name'] ?? 'Unknown material');
                $matches = $typeId ? $byId->get($typeId, collect()) : $byName->get(mb_strtolower($name), collect());
                $perRun = max(0, (int) ($material['quantity'] ?? 0));
                $owned = (int) $matches->sum('quantity');
                return [
                    'name' => $name, 'per_run' => $perRun, 'owned' => $owned,
                    'runs' => $perRun > 0 ? intdiv($owned, $perRun) : 0,
                    'locations' => $matches->take(10)->map(fn ($asset) => [
                        'owner' => $asset->character_name ?: 'Character',
                        'location' => $asset->location_name ?: (string) $asset->location_id,
                        'quantity' => (int) $asset->quantity,
                    ])->values()->all(),
                ];
            })->values();
            $runs = $materials->isEmpty() ? 0 : (int) $materials->min('runs');
            $missing = $materials->map(fn ($material) => [
                'name' => $material['name'],
                'quantity' => max(0, $material['per_run'] * ($runs + 1) - $material['owned']),
            ])->filter(fn ($material) => $material['quantity'] > 0)->values()->all();

            return [
                'product' => (string) ($recipe['product'] ?? collect($recipe['products'] ?? [])->first()['name'] ?? 'Unknown product'),
                'category' => (string) ($recipe['category'] ?? 'gas'),
                'type_id' => (int) ($recipe['typeId'] ?? 0),
                'group' => (string) ($recipe['formulaGroup'] ?? $recipe['formulaName'] ?? $recipe['category'] ?? ''),
                'materials' => $materials->all(),
                'products' => collect($recipe['products'] ?? [])->map(fn ($product) => [
                    'name' => $product['name'] ?? 'Unknown product',
                    'quantity' => (int) ($product['quantity'] ?? 0),
                ])->all(),
                'runs' => $runs,
                'missing' => $missing,
            ];
        })->sortByDesc('runs')->values();

        $inventory = $assets->groupBy(fn ($asset) => mb_strtolower((string) $asset->type_name))
            ->map(fn ($group) => [
                'name' => $group->first()->type_name,
                'quantity' => (int) $group->sum('quantity'),
                'locations' => $group->count(),
            ])->sortBy('name')->values();

        return view('web::reactions.index', compact('rows','search','recipeError','inventory','inventoryError'));
    }
}
