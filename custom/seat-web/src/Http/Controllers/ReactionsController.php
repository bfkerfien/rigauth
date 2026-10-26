<?php

namespace Seat\\Web\\Http\\Controllers;

use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Cache;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\View\\View;

class ReactionsController extends Controller
{
    private function catalog(): array
    {
        $path = base_path('data/reactions.json');
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) && is_array($data['products'] ?? null) ? $data['products'] : [];
    }

    public function market(Request $request)
    {
        abort_unless(auth()->check(), 401);
        $names = collect(explode(',', (string) $request->query('names', '')))
            ->map(fn ($name) => trim($name))->filter()->unique()->take(100)->values();
        if ($names->isEmpty()) {
            return response()->json(['error' => 'Provide at least one item name.'], 400);
        }

        $catalog = collect($this->catalog());
        $idsByName = [];
        foreach ($catalog as $recipe) {
            foreach (array_merge($recipe['materials'] ?? [], $recipe['products'] ?? []) as $item) {
                if (!empty($item['name']) && !empty($item['typeId'])) {
                    $idsByName[mb_strtolower($item['name'])] = (int) $item['typeId'];
                }
            }
        }
        if (Schema::hasTable('invTypes')) {
            $types = DB::table('invTypes')->whereIn('typeName', $names->all())->get(['typeID', 'typeName']);
            foreach ($types as $type) $idsByName[mb_strtolower($type->typeName)] = (int) $type->typeID;
        }

        $prices = [];
        foreach ($names as $name) {
            $typeId = $idsByName[mb_strtolower($name)] ?? null;
            if (!$typeId) {
                $prices[$name] = ['typeId' => null, 'buyPrice' => null, 'sellPrice' => null, 'splitPrice' => null, 'volume' => null, 'source' => 'Item type not found in local EVE data'];
                continue;
            }
            $prices[$name] = Cache::remember('reaction.jita.price.'.$typeId, 300, function () use ($typeId) {
                $orders = [];
                for ($page = 1; $page <= 5; $page++) {
                    try {
                        $response = Http::acceptJson()->timeout(12)->withHeaders(['User-Agent' => 'Triglavian-Consortium-SeAT-Reactions'])
                            ->get('https://esi.evetech.net/latest/markets/10000002/orders/', [
                                'order_type' => 'all', 'type_id' => $typeId, 'page' => $page,
                            ]);
                        if (!$response->successful()) break;
                        $batch = $response->json();
                        if (!is_array($batch) || !$batch) break;
                        $orders = array_merge($orders, $batch);
                        if (count($batch) < 1000) break;
                    } catch (\\Throwable $e) {
                        break;
                    }
                }
                $station = 60003760;
                $buy = collect($orders)->filter(fn ($o) => (int) ($o['location_id'] ?? 0) === $station && !empty($o['is_buy_order']))->max('price');
                $sell = collect($orders)->filter(fn ($o) => (int) ($o['location_id'] ?? 0) === $station && empty($o['is_buy_order']))->min('price');
                return [
                    'typeId' => $typeId, 'buyPrice' => $buy, 'sellPrice' => $sell,
                    'splitPrice' => $buy !== null && $sell !== null ? ($buy + $sell) / 2 : null,
                    'volume' => null, 'volumeFetched' => false,
                    'price' => $sell, 'source' => 'ESI Jita IV-4 CNAP orders',
                    'updatedAt' => now()->timestamp * 1000,
                ];
            });
        }
        return response()->json([
            'hub' => 'jita', 'hubName' => 'Jita IV-4 CNAP', 'regionId' => 10000002,
            'stationId' => 60003760, 'cacheSeconds' => 300, 'prices' => $prices,
        ]);
    }

    public function recipe(Request $request)
    {
        abort_unless(auth()->check(), 401);
        $product = trim((string) $request->query('product', ''));
        $activity = (int) $request->query('activity', 1);
        if ($product === '' || !in_array($activity, [1, 11], true)) {
            return response()->json(['error' => 'Provide a product and activity 1 or 11.'], 400);
        }
        if (!Schema::hasTable('industryActivityProducts') || !Schema::hasTable('industryActivityMaterials') || !Schema::hasTable('invTypes')) {
            return response()->json(['error' => 'Local EVE industry blueprint tables are not available in this SeAT database.'], 503);
        }
        $type = DB::table('invTypes')->where('typeName', $product)->first(['typeID', 'typeName']);
        if (!$type) return response()->json(['error' => 'Product not found in local EVE type data.'], 404);

        $output = DB::table('industryActivityProducts as p')
            ->join('industryActivityMaterials as m', function ($join) {
                $join->on('m.blueprintTypeID', '=', 'p.blueprintTypeID')->on('m.activityID', '=', 'p.activityID');
            })
            ->join('invTypes as mt', 'mt.typeID', '=', 'm.typeID')
            ->where('p.typeID', $type->typeID)->where('p.activityID', $activity)
            ->select(['p.blueprintTypeID', 'p.activityID', 'p.quantity as output_quantity', 'm.quantity as input_quantity', 'mt.typeName as material_name', 'm.typeID as material_type_id'])
            ->get();
        if ($output->isEmpty()) return response()->json(['error' => 'No local blueprint recipe found for this product/activity.'], 404);
        $first = $output->first();
        return response()->json([
            'product' => $type->typeName, 'typeId' => (int) $type->typeID,
            'activity' => $activity, 'blueprintTypeId' => (int) $first->blueprintTypeID,
            'products' => [['name' => $type->typeName, 'typeId' => (int) $type->typeID, 'quantity' => (int) $first->output_quantity]],
            'materials' => $output->map(fn ($row) => ['name' => $row->material_name, 'typeId' => (int) $row->material_type_id, 'quantity' => (int) $row->input_quantity])->values(),
            'source' => 'Local EVE industry blueprint data',
        ]);
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
                $assets = DB::table('assetmgr_assets')->where('owner_kind', 'character')->whereIn('character_id', $characterIds)
                    ->where('quantity', '>', 0)
                    ->select(['owner_kind','character_name','type_id','type_name','quantity','location_id','location_name','jita_unit_price','regional_unit_price','last_seen_at'])
                    ->orderBy('type_name')->limit(20000)->get();
            }
        }

        $recipes = collect($this->catalog());
        $recipeError = $recipes->isEmpty() ? 'The local reaction catalog is missing or invalid. Restore data/reactions.json; no standalone service is used at runtime.' : null;
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
                return ['name' => $name, 'per_run' => $perRun, 'owned' => $owned, 'runs' => $perRun > 0 ? intdiv($owned, $perRun) : 0,
                    'locations' => $matches->take(10)->map(fn ($asset) => ['owner' => $asset->character_name ?: 'Character', 'location' => $asset->location_name ?: (string) $asset->location_id, 'quantity' => (int) $asset->quantity])->values()->all()];
            })->values();
            $runs = $materials->isEmpty() ? 0 : (int) $materials->min('runs');
            $missing = $materials->map(fn ($material) => ['name' => $material['name'], 'quantity' => max(0, $material['per_run'] * ($runs + 1) - $material['owned'])])
                ->filter(fn ($material) => $material['quantity'] > 0)->values()->all();
            $formulaGroup = (string) ($recipe['formulaGroup'] ?? $recipe['formulaName'] ?? '');
            $category = match (true) {
                str_contains(mb_strtolower($formulaGroup), 'biochemical'), str_contains(mb_strtolower($formulaGroup), 'booster') => 'biochemical',
                str_contains(mb_strtolower($formulaGroup), 'molecular-forged') => 'molecular',
                str_contains(mb_strtolower($formulaGroup), 'moon composite') => 'moon',
                default => (string) ($recipe['category'] ?? 'gas'),
            };
            return [
                'product' => (string) ($recipe['product'] ?? collect($recipe['products'] ?? [])->first()['name'] ?? 'Unknown product'),
                'category' => $category, 'type_id' => (int) ($recipe['typeId'] ?? 0),
                'group' => $formulaGroup ?: (string) ($recipe['category'] ?? ''),
                'materials' => $materials->all(),
                'products' => collect($recipe['products'] ?? [])->map(fn ($product) => ['name' => $product['name'] ?? 'Unknown product', 'quantity' => (int) ($product['quantity'] ?? 0)])->all(),
                'runs' => $runs, 'missing' => $missing,
            ];
        })->sortByDesc('runs')->values();

        $inventory = $assets->groupBy(fn ($asset) => mb_strtolower((string) $asset->type_name))
            ->map(fn ($group) => ['name' => $group->first()->type_name, 'quantity' => (int) $group->sum('quantity'), 'locations' => $group->count()])
            ->sortBy('name')->values();
        return view('web::reactions.index', compact('rows','search','recipeError','inventory','inventoryError'));
    }
}
