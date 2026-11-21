<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ReactionsController extends Controller
{
    private function blueprintData(): array
    {
        foreach ([
            dirname(__DIR__, 3) . '/data/production-blueprints.json',
            base_path('packages/eveseat/web/data/production-blueprints.json'),
            base_path('data/production-blueprints.json'),
        ] as $path) {
            if (is_file($path) && is_readable($path)) {
                $data = json_decode((string) file_get_contents($path), true);
                if (is_array($data) && is_array($data['products'] ?? null)) return $data;
            }
        }

        // Keep a controller-embedded copy as a fallback if package data files are
        // not installed alongside the controller in a SeAT deployment.
        $embedded = json_decode('{"source":"EVE Online SDE manufacturing base materials, hardcoded so production trees do not depend on SeAT industry tables. Reactions expand from data/reactions.json.","sde_as_of":"2026-10-23","moonMaterials":["Atmospheric Gases","Evaporite Deposits","Hydrocarbons","Silicates","Cadmium","Caesium","Chromium","Cobalt","Dysprosium","Hafnium","Mercury","Neodymium","Promethium","Scandium","Technetium","Thulium","Titanium","Tungsten","Vanadium","Platinum"],"products":{"Bustard":{"typeId":12731,"blueprintTypeId":12732,"outputQuantity":1,"materials":[["Scalar Capacitor Unit",300],["Morphite",105],["Magpulse Thruster",75],["Gravimetric Sensor Cluster",135],["Construction Blocks",225],["R.A.M.- Starship Tech",15],["Titanium Diborite Armor Plate",5250],["Quantum Microprocessor",1800],["Sustained Shield Emitter",375],["Graviton Reactor Unit",45],["Tayra",1]]},"Crane":{"typeId":12729,"blueprintTypeId":12730,"outputQuantity":1,"materials":[["Scalar Capacitor Unit",225],["Morphite",60],["Magpulse Thruster",113],["Gravimetric Sensor Cluster",90],["Construction Blocks",113],["R.A.M.- Starship Tech",11],["Titanium Diborite Armor Plate",3000],["Quantum Microprocessor",1200],["Sustained Shield Emitter",150],["Graviton Reactor Unit",38],["Badger",1]]},"Scalar Capacitor Unit":{"typeId":11552,"blueprintTypeId":17327,"outputQuantity":1,"materials":[["Nanotransistors",1],["Nonlinear Metamaterials",2],["Fullerides",11],["Titanium Carbide",27]]},"Magpulse Thruster":{"typeId":11533,"blueprintTypeId":17356,"outputQuantity":1,"materials":[["Phenolic Composites",3],["Ferrogel",1],["Titanium Carbide",13]]},"Gravimetric Sensor Cluster":{"typeId":11534,"blueprintTypeId":17340,"outputQuantity":1,"materials":[["Titanium Carbide",22],["Nanotransistors",1],["Hypersynaptic Fibers",2]]},"Quantum Microprocessor":{"typeId":11540,"blueprintTypeId":17351,"outputQuantity":1,"materials":[["Phenolic Composites",6],["Nanotransistors",2],["Nonlinear Metamaterials",2],["Titanium Carbide",17]]},"Sustained Shield Emitter":{"typeId":11558,"blueprintTypeId":17352,"outputQuantity":1,"materials":[["Ferrogel",1],["Sylramic Fibers",9],["Titanium Carbide",22]]},"Graviton Reactor Unit":{"typeId":11550,"blueprintTypeId":17353,"outputQuantity":1,"materials":[["Fermionic Condensates",2],["Titanium Carbide",9]]},"R.A.M.- Starship Tech":{"typeId":11478,"blueprintTypeId":11890,"outputQuantity":1,"materials":[["Tritanium",556],["Pyerite",444],["Mexallon",222],["Isogen",82],["Nocxium",36]]},"Titanium Diborite Armor Plate":{"typeId":11544,"blueprintTypeId":17328,"outputQuantity":1,"materials":[["Sylramic Fibers",11],["Titanium Carbide",44]]}},"purchaseOnly":["Tayra","Badger","Construction Blocks","Morphite"],"itemTypes":{"Morphite":11399,"Construction Blocks":3828,"Tayra":12745,"Badger":648}}', true);
        return is_array($embedded) ? $embedded : [];
    }

    private function catalog(): array
    {
        // The catalog is installed inside the SeAT web package, not Laravel's application root.
        $candidates = [
            dirname(__DIR__, 3) . '/data/reactions.json',
            base_path('packages/eveseat/web/data/reactions.json'),
            base_path('data/reactions.json'),
        ];
        foreach ($candidates as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && is_array($data['products'] ?? null) && count($data['products']) === 120) {
                return $data['products'];
            }
        }
        return [];
    }

    public function bustardProduction(): View
    {
        abort_unless(auth()->check(), 401);
        return view('web::reactions.ship-tree', ['ship' => 'Bustard']);
    }

    public function craneProduction(): View
    {
        abort_unless(auth()->check(), 401);
        return view('web::reactions.ship-tree', ['ship' => 'Crane']);
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
        $blueprintData = $this->blueprintData();
        foreach (($blueprintData['products'] ?? []) as $itemName => $recipe) {
            if (!empty($recipe['typeId'])) $idsByName[mb_strtolower($itemName)] = (int) $recipe['typeId'];
        }
        foreach (($blueprintData['itemTypes'] ?? []) as $itemName => $typeId) {
            $idsByName[mb_strtolower($itemName)] = (int) $typeId;
        }
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
        $uncached = [];
        foreach ($names as $name) {
            $typeId = $idsByName[mb_strtolower($name)] ?? null;
            if (!$typeId) {
                $prices[$name] = ['typeId' => null, 'buyPrice' => null, 'sellPrice' => null, 'splitPrice' => null, 'volume' => null, 'source' => 'Item type not found in local EVE data'];
                continue;
            }
            $cached = Cache::get('reaction.jita.price.v2.'.$typeId);
            if (is_array($cached)) {
                $prices[$name] = $cached;
            } else {
                $uncached[$typeId] = $typeId;
            }
        }

        // Fetch up to ten item types concurrently instead of waiting for each type's ESI
        // requests serially. This keeps initial leaderboard loading responsive.
        foreach (array_chunk(array_values($uncached), 10) as $typeBatch) {
            $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($typeBatch) {
                $requests = [];
                foreach ($typeBatch as $typeId) {
                    for ($page = 1; $page <= 5; $page++) {
                        $key = $typeId.'_'.$page;
                        $requests[$key] = $pool->as($key)->acceptJson()->timeout(10)
                            ->withHeaders(['User-Agent' => 'Triglavian-Consortium-SeAT-Reactions'])
                            ->get('https://esi.evetech.net/latest/markets/10000002/orders/', [
                                'order_type' => 'all', 'type_id' => $typeId, 'page' => $page,
                            ]);
                    }
                }
                return $requests;
            });

            foreach ($typeBatch as $typeId) {
                $orders = [];
                for ($page = 1; $page <= 5; $page++) {
                    $response = $responses[$typeId.'_'.$page] ?? null;
                    if (!$response || !$response->successful()) break;
                    $batch = $response->json();
                    if (!is_array($batch) || !$batch) break;
                    $orders = array_merge($orders, $batch);
                    if (count($batch) < 1000) break;
                }
                $station = 60003760;
                // EVE market terminology:
                // buyPrice = highest buy order (best bid).
                // sellPrice = lowest sell order (best ask).
                $buy = collect($orders)->filter(fn ($o) => (int) ($o['location_id'] ?? 0) === $station && !empty($o['is_buy_order']))->max('price');
                $sell = collect($orders)->filter(fn ($o) => (int) ($o['location_id'] ?? 0) === $station && empty($o['is_buy_order']))->min('price');
                $value = [
                    'typeId' => $typeId, 'buyPrice' => $buy, 'sellPrice' => $sell,
                    'splitPrice' => $buy !== null && $sell !== null ? ($buy + $sell) / 2 : null,
                    'volume' => null, 'volumeFetched' => false,
                    'price' => $sell, 'source' => 'ESI Jita IV-4 CNAP orders',
                    'updatedAt' => now()->timestamp * 1000,
                ];
                Cache::put('reaction.jita.price.v2.'.$typeId, $value, 300);
                foreach ($names as $name) {
                    if (($idsByName[mb_strtolower($name)] ?? null) === $typeId) $prices[$name] = $value;
                }
            }
        }
        return response()->json([
            'hub' => 'jita', 'hubName' => 'Jita IV-4 CNAP', 'regionId' => 10000002,
            'stationId' => 60003760, 'cacheSeconds' => 300, 'prices' => $prices,
        ]);
    }


    public function history(Request $request)
    {
        abort_unless(auth()->check(), 401);
        $names = collect(explode(',', (string) $request->query('names', '')))
            ->map(fn ($name) => trim($name))->filter()->unique()->take(10)->values();
        if ($names->isEmpty()) return response()->json(['error' => 'Provide item names.'], 400);
        if (!Schema::hasTable('invTypes')) return response()->json(['error' => 'Local EVE type data is unavailable.'], 503);

        $result = [];
        foreach ($names as $name) {
            $type = DB::table('invTypes')->whereRaw('LOWER(typeName) = ?', [mb_strtolower($name)])->first(['typeID', 'typeName']);
            if (!$type) {
                $type = DB::table('invTypes')->where('typeName', 'like', '%'.$name.'%')->orderByRaw('CASE WHEN LOWER(typeName) LIKE ? THEN 0 ELSE 1 END', [mb_strtolower($name).'%'])->first(['typeID', 'typeName']);
            }
            if (!$type) { $result[$name] = ['error' => 'Item type not found in local EVE data.']; continue; }
            $cacheKey = 'reaction.jita.history.'.(int) $type->typeID;
            $history = Cache::remember($cacheKey, 3600, function () use ($type) {
                $response = Http::acceptJson()->timeout(20)->withHeaders(['User-Agent' => 'Triglavian-Consortium-SeAT-Reactions'])
                    ->get('https://esi.evetech.net/latest/markets/10000002/history/', ['type_id' => (int) $type->typeID]);
                if (!$response->successful() || !is_array($response->json())) return null;
                $cutoff = now()->subDays(59)->toDateString();
                return collect($response->json())->filter(fn ($point) => ($point['date'] ?? '') >= $cutoff)
                    ->sortBy('date')->values()->all();
            });
            $result[$name] = ['typeId' => (int) $type->typeID, 'typeName' => $type->typeName, 'points' => $history, 'source' => 'ESI Jita region market history'];
        }
        return response()->json(['regionId' => 10000002, 'days' => 60, 'history' => $result]);
    }

    public function recipe(Request $request)
    {
        abort_unless(auth()->check(), 401);
        $product = trim((string) $request->query('product', ''));
        $activity = (int) $request->query('activity', 1);
        if ($product === '' || !in_array($activity, [1, 11], true)) {
            return response()->json(['error' => 'Provide a product and activity 1 or 11.'], 400);
        }

        // Production tree recipes are shipped with this package. They work even when
        // this SeAT install has no industryActivity* tables or local SDE blueprint data.
        if ($request->boolean('tree')) {
            $data = $this->blueprintData();
            if (!isset($data['products'][$product])) return response()->json(['error' => 'No hardcoded ship blueprint found for this product.'], 404);
            $me = max(-10, min(10, (int) $request->query('me', 0)));
            $pe = max(-10, min(20, (int) $request->query('pe', 0)));
            $rawMaterials = [];
            $tree = $this->buildStaticProductionNode($product, 1, 0, [], $rawMaterials, $me, $pe, true, $data);
            $root = $data['products'][$product];
            return response()->json([
                'product' => $product, 'typeId' => (int) $root['typeId'], 'tree' => $tree,
                'rawMaterials' => array_values($rawMaterials), 'settings' => ['me' => $me, 'pe' => $pe],
                'source' => 'Hardcoded EVE SDE ship/component blueprints plus the local reaction catalog; raw inputs priced at Jita sell',
            ]);
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

    private function buildStaticProductionNode(
        string $name,
        int $quantityNeeded,
        int $depth,
        array $path,
        array &$rawMaterials,
        int $me,
        int $pe,
        bool $isRoot,
        array $data
    ): array {
        $blueprint = $data['products'][$name] ?? null;
        $reaction = null;
        foreach ($this->catalog() as $candidate) {
            if (strcasecmp((string) ($candidate['product'] ?? ''), $name) === 0) {
                $reaction = $candidate;
                break;
            }
        }

        $typeId = (int) ($blueprint['typeId'] ?? $reaction['typeId'] ?? 0);
        $isLoop = in_array($name, $path, true);
        if ($depth >= 16 || $isLoop || (!$blueprint && !$reaction)) {
            $key = mb_strtolower($name);
            if (!isset($rawMaterials[$key])) {
                $rawMaterials[$key] = [
                    'typeId' => $typeId ?: null,
                    'name' => $name,
                    'groupName' => in_array($name, $data['moonMaterials'] ?? [], true) ? 'Moon Materials' : '',
                    'quantity' => 0,
                ];
            }
            $rawMaterials[$key]['quantity'] += $quantityNeeded;
            return [
                'typeId' => $typeId ?: null, 'name' => $name,
                'groupName' => $rawMaterials[$key]['groupName'], 'quantityNeeded' => $quantityNeeded,
                'activityId' => null, 'activityName' => 'Raw input', 'runs' => null,
                'children' => [], 'cyclePrevented' => $isLoop,
            ];
        }

        $outputPerRun = max(1, (int) ($blueprint['outputQuantity'] ?? (($reaction['products'][0]['quantity'] ?? 1))));
        $runs = (int) ceil($quantityNeeded / $outputPerRun);
        $materials = [];
        if ($blueprint) {
            foreach ($blueprint['materials'] as [$materialName, $quantity]) {
                $required = (int) $quantity * $runs;
                if ($isRoot && $me !== 0) $required = max(1, (int) ceil($required * (1 - ($me / 100))));
                $materials[] = [$materialName, $required];
            }
            $activityId = 1;
            $blueprintTypeId = $blueprint['blueprintTypeId'] ?? null;
        } else {
            foreach ($reaction['materials'] ?? [] as $material) {
                $materials[] = [$material['name'], (int) $material['quantity'] * $runs];
            }
            $activityId = 11;
            $blueprintTypeId = $reaction['formulaId'] ?? null;
        }

        $children = [];
        foreach ($materials as [$materialName, $quantity]) {
            $children[] = $this->buildStaticProductionNode(
                $materialName, $quantity, $depth + 1, array_merge($path, [$name]),
                $rawMaterials, $me, $pe, false, $data
            );
        }
        return [
            'typeId' => $typeId ?: null, 'name' => $name,
            'groupName' => $blueprint ? 'Manufacturing Components' : (string) ($reaction['formulaGroup'] ?? 'Reaction'),
            'quantityNeeded' => $quantityNeeded, 'activityId' => $activityId,
            'activityName' => $activityId === 11 ? 'Reaction' : 'Manufacturing',
            'blueprintTypeId' => $blueprintTypeId, 'outputPerRun' => $outputPerRun,
            'runs' => $runs, 'meApplied' => $isRoot && $activityId === 1 ? $me : 0,
            'peApplied' => $isRoot && $activityId === 1 ? $pe : 0,
            'estimatedSeconds' => null, 'children' => $children,
        ];
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
