<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use RuntimeException;

class CookbookController extends Controller
{
    private const ACTIVITY_MANUFACTURING = 1;
    private const MINERAL_TYPE_IDS = [34, 35, 36, 37, 38, 39, 40, 11399];
    private const ORE_CATEGORY_ID = 25;
    // These are the exact standard asteroid ores shown by the original standalone picker.
    // Ice, moon ores and named/quality variants are intentionally absent.
    private const STANDARD_ORE_SECTIONS = [
        'Simple ores' => ['Mordunium', 'Plagioclase', 'Pyroxeres', 'Scordite', 'Veldspar'],
        'Coherent ores' => ['Griemeer', 'Hedbergite', 'Hemorphite', 'Jaspet', 'Kernite', 'Nocxite', 'Omber', 'Ytirium'],
        'Variegated ores' => ['Crokite', 'Dark Ochre', 'Gneiss', 'Kylixium'],
        'Complex ores' => ['Arkonor', 'Bistot', 'Ducinium', 'Eifyrium', 'Hezorime', 'Spodumain', 'Ueganite'],
        'Abyssal ores' => ['Bezdnacine', 'Rakovene', 'Talassonite'],
        'Mercoxit' => ['Mercoxit'],
    ];

    private static function standardCompressedOreNames(): array
    {
        $names = [];
        foreach (self::STANDARD_ORE_SECTIONS as $ores) {
            foreach ($ores as $ore) {
                $names[] = 'Compressed ' . $ore;
            }
        }
        return $names;
    }

    public function index(): View
    {
        $characters = $this->characters();

        return view('web::cookbook.index', [
            'sdeReady' => $this->sdeReady(),
            'characters' => $characters,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        abort_unless(auth()->check(), 401);
        if (!$this->sdeReady()) {
            return response()->json(['error' => 'SeAT SDE manufacturing tables are not available. Update/import the SeAT SDE first.'], 503);
        }

        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['items' => []]);
        }

        $items = DB::table('industryActivityProducts as products')
            ->join('invTypes as product_types', 'product_types.typeID', '=', 'products.productTypeID')
            ->join('invTypes as blueprints', 'blueprints.typeID', '=', 'products.typeID')
            ->where('products.activityID', self::ACTIVITY_MANUFACTURING)
            ->where('product_types.published', 1)
            ->where('product_types.typeName', 'like', '%' . addcslashes($term, '%_') . '%')
            ->select([
                'products.productTypeID as product_type_id',
                'products.typeID as blueprint_type_id',
                'product_types.typeName as product_name',
                'blueprints.typeName as blueprint_name',
            ])
            ->orderBy('product_types.typeName')
            ->limit(30)
            ->get();

        return response()->json(['items' => $items]);
    }

    public function inventory(Request $request): JsonResponse
    {
        abort_unless(auth()->check(), 401);
        abort_unless(Schema::hasTable('character_assets') && Schema::hasTable('invTypes') && Schema::hasTable('invGroups') && Schema::hasTable('invTypeMaterials'), 503, 'SeAT character asset or SDE tables are unavailable.');

        $data = $request->validate(['character_id' => ['required', 'integer', 'min:1']]);
        $characterId = (int) $data['character_id'];
        $this->authorizeCharacter($characterId);

        try {
            $refineryId = $this->structureId('COOKBOOK_REFINERY_STRUCTURE_ID', 'BKG-Q2 - Draken Barrel Refinery');
            $manufacturingId = $this->structureId('COOKBOOK_MANUFACTURING_STRUCTURE_ID', 'BKG-Q2 - Draken Manufacturing');

            // Walk each configured facility independently. Never read the character's global asset list.
            $refineryAssets = $this->assetsAtStructure($characterId, $refineryId);
            $manufacturingAssets = $this->assetsAtStructure($characterId, $manufacturingId);

            $allTypeIds = $refineryAssets->pluck('type_id')->merge($manufacturingAssets->pluck('type_id'))->unique()->values()->all();
            $typeNames = $allTypeIds
                ? DB::table('invTypes')->whereIn('typeID', $allTypeIds)->pluck('typeName', 'typeID')->all()
                : [];

            $oreTypeIds = $refineryAssets->pluck('type_id')->unique()->values()->all();
            $oreTypes = $oreTypeIds
                ? DB::table('invTypes as types')
                    ->join('invGroups as groups', 'groups.groupID', '=', 'types.groupID')
                    ->where('groups.categoryID', self::ORE_CATEGORY_ID)
                    ->whereIn('types.typeID', $oreTypeIds)
                    ->get(['types.typeID', 'types.typeName', 'types.portionSize'])
                    ->keyBy('typeID')
                : collect();

            $oreRows = [];
            foreach ($refineryAssets as $asset) {
                $typeId = (int) $asset->type_id;
                if (!$oreTypes->has($typeId)) continue;
                $oreRows[] = [
                    'item_id' => (int) $asset->item_id,
                    'type_id' => $typeId,
                    'name' => $typeNames[$typeId] ?? ('Type ' . $typeId),
                    'quantity' => max(0, (int) $asset->quantity),
                    'location_id' => (int) $asset->location_id,
                    'portion_size' => max(1, (int) $oreTypes->get($typeId)->portionSize),
                    'is_compressed' => stripos($typeNames[$typeId] ?? '', 'Compressed ') === 0,
                ];
            }

            $mineralRows = [];
            foreach ($manufacturingAssets as $asset) {
                $typeId = (int) $asset->type_id;
                if (!in_array($typeId, self::MINERAL_TYPE_IDS, true)) continue;
                $mineralRows[] = [
                    'item_id' => (int) $asset->item_id,
                    'type_id' => $typeId,
                    'name' => $typeNames[$typeId] ?? ('Type ' . $typeId),
                    'quantity' => max(0, (int) $asset->quantity),
                    'location_id' => (int) $asset->location_id,
                ];
            }

            $compressedTotals = [];
            foreach ($oreRows as $row) {
                if (!$row['is_compressed'] || !in_array($row['name'], self::standardCompressedOreNames(), true)) continue;
                $id = $row['type_id'];
                if (!isset($compressedTotals[$id])) {
                    $compressedTotals[$id] = [
                        'type_id' => $id, 'name' => $row['name'],
                        'portion_size' => $row['portion_size'], 'quantity' => 0,
                    ];
                }
                $compressedTotals[$id]['quantity'] += $row['quantity'];
            }

            $mineralTotals = [];
            foreach ($mineralRows as $row) {
                $id = $row['type_id'];
                $mineralTotals[$id] = ($mineralTotals[$id] ?? 0) + $row['quantity'];
            }

            return response()->json([
                'character_id' => $characterId,
                'refinery_structure_id' => $refineryId,
                'manufacturing_structure_id' => $manufacturingId,
                'ore_assets' => $oreRows,
                'mineral_assets' => $mineralRows,
                'ore_asset_count' => count($oreRows),
                'mineral_asset_count' => count($mineralRows),
                'items' => array_values($compressedTotals),
                'materials' => $mineralTotals,
                'note' => 'Draken Barrel Refinery: ore and compressed ore only. Draken Manufacturing manufacturing facility: minerals only. Nested containers are included within each selected structure; all other locations and item categories are excluded.',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function oreTypes(): JsonResponse
    {
        abort_unless(auth()->check(), 401);
        abort_unless(Schema::hasTable('invTypes') && Schema::hasTable('invGroups') && Schema::hasTable('invTypeMaterials'), 503, 'SeAT ore and reprocessing SDE data is unavailable.');

        $items = DB::table('invTypes as types')
            ->join('invGroups as groups', 'groups.groupID', '=', 'types.groupID')
            ->where('groups.categoryID', self::ORE_CATEGORY_ID)
            ->whereIn('types.typeName', self::standardCompressedOreNames())
            ->where('types.published', 1)
            ->orderBy('types.typeName')
            ->get(['types.typeID as type_id', 'types.typeName as name', 'types.portionSize as portion_size'])
            ->map(function ($item) {
                $baseName = preg_replace('/^Compressed /', '', $item->name);
                foreach (self::STANDARD_ORE_SECTIONS as $section => $ores) {
                    if (in_array($baseName, $ores, true)) {
                        $item->section = $section;
                        break;
                    }
                }
                return $item;
            })
            ->values();

        return response()->json(['items' => $items]);
    }

    public function orePlan(Request $request): JsonResponse
    {
        abort_unless(auth()->check(), 401);
        abort_unless(Schema::hasTable('character_assets') && Schema::hasTable('invTypes') && Schema::hasTable('invTypeMaterials'), 503, 'SeAT character asset or reprocessing SDE data is unavailable.');

        $data = $request->validate([
            'character_id' => ['required', 'integer', 'min:1'],
            'ore_type_ids' => ['required', 'array', 'min:1', 'max:30'],
            'ore_type_ids.*' => ['required', 'integer', 'min:1'],
            'yield_percent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'mineral_needs' => ['required', 'array', 'min:1', 'max:50'],
            'mineral_needs.*.type_id' => ['required', 'integer', 'min:1'],
            'mineral_needs.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ]);

        $characterId = (int) $data['character_id'];
        $this->authorizeCharacter($characterId);

        try {
            $refineryId = $this->structureId('COOKBOOK_REFINERY_STRUCTURE_ID', 'BKG-Q2 - Draken Barrel Refinery');
            $manufacturingId = $this->structureId('COOKBOOK_MANUFACTURING_STRUCTURE_ID', 'BKG-Q2 - Draken Manufacturing');
            $refineryAssets = $this->assetsAtStructure($characterId, $refineryId);
            $manufacturingAssets = $this->assetsAtStructure($characterId, $manufacturingId);

            $needs = [];
            foreach ($data['mineral_needs'] as $row) {
                $typeId = (int) $row['type_id'];
                $needs[$typeId] = max(0, (int) $row['quantity']);
            }
            $mineralStock = [];
            foreach ($manufacturingAssets as $asset) {
                $typeId = (int) $asset->type_id;
                if (!in_array($typeId, self::MINERAL_TYPE_IDS, true)) continue;
                $mineralStock[$typeId] = ($mineralStock[$typeId] ?? 0) + max(0, (int) $asset->quantity);
            }
            $deficits = [];
            foreach ($needs as $typeId => $required) {
                $deficits[$typeId] = max(0, $required - (int) ($mineralStock[$typeId] ?? 0));
            }

            // Sum every eligible compressed ore stack across the selected character's cached locations before calculating whole batches.
            $oreIds = $refineryAssets->pluck('type_id')->unique()->values()->all();
            $oreCatalog = $oreIds
                ? DB::table('invTypes as types')
                    ->join('invGroups as groups', 'groups.groupID', '=', 'types.groupID')
                    ->where('groups.categoryID', self::ORE_CATEGORY_ID)
                    ->whereIn('types.typeID', $oreIds)
                    ->whereIn('types.typeName', self::standardCompressedOreNames())
                    ->get(['types.typeID', 'types.typeName', 'types.portionSize'])
                    ->keyBy('typeID')
                : collect();
            $oreQuantities = [];
            foreach ($refineryAssets as $asset) {
                $typeId = (int) $asset->type_id;
                if ($oreCatalog->has($typeId)) {
                    $oreQuantities[$typeId] = ($oreQuantities[$typeId] ?? 0) + max(0, (int) $asset->quantity);
                }
            }

            $oreStockRows = [];
            $stockMinerals = [];
            $yieldFactor = (float) $data['yield_percent'] / 100;
            foreach ($oreQuantities as $typeId => $quantity) {
                $ore = $oreCatalog->get($typeId);
                if (!$ore) {
                    continue;
                }
                $portion = max(1, (int) $ore->portionSize);
                $batches = intdiv($quantity, $portion);
                if ($batches > 0) {
                    $materials = DB::table('invTypeMaterials')->where('typeID', $typeId)->get(['materialTypeID', 'quantity']);
                    if ($materials->isEmpty()) {
                        throw new RuntimeException('No reprocessing material data exists for cached compressed ore stock ' . $ore->typeName . '.');
                    }
                    foreach ($materials as $material) {
                        $mineralId = (int) $material->materialTypeID;
                        $yield = (int) floor($batches * (int) $material->quantity * $yieldFactor);
                        $stockMinerals[$mineralId] = ($stockMinerals[$mineralId] ?? 0) + $yield;
                    }
                }
                $oreStockRows[$typeId] = [
                    'type_id' => $typeId,
                    'name' => $ore->typeName,
                    'quantity' => $quantity,
                    'portion_size' => $portion,
                    'complete_batches' => $batches,
                    'leftover_units' => $quantity % $portion,
                ];
            }

            // Refinery ore is counted first, then only the residual mineral deficit is planned.
            foreach ($deficits as $typeId => $quantity) {
                $deficits[$typeId] = max(0, $quantity - (int) ($stockMinerals[$typeId] ?? 0));
            }

            $selectedIds = array_values(array_unique(array_map('intval', $data['ore_type_ids'])));
            $selectedOres = DB::table('invTypes as types')
                ->join('invGroups as groups', 'groups.groupID', '=', 'types.groupID')
                ->where('groups.categoryID', self::ORE_CATEGORY_ID)
                ->whereIn('types.typeID', $selectedIds)
                ->whereIn('types.typeName', self::standardCompressedOreNames())
                ->orderBy('types.typeName')
                ->get(['types.typeID', 'types.typeName', 'types.portionSize']);
            abort_unless($selectedOres->count() === count($selectedIds), 422, 'One or more selected types are not published compressed ore types.');

            $candidates = [];
            foreach ($selectedOres as $ore) {
                $materials = DB::table('invTypeMaterials')
                    ->where('typeID', (int) $ore->typeID)
                    ->get(['materialTypeID', 'quantity']);
                abort_unless($materials->isNotEmpty(), 422, 'No reprocessing composition is available for ' . $ore->typeName . '.');
                $composition = [];
                foreach ($materials as $material) {
                    $composition[(int) $material->materialTypeID] = (int) $material->quantity;
                }
                $candidates[(int) $ore->typeID] = [
                    'type_id' => (int) $ore->typeID,
                    'name' => $ore->typeName,
                    'portion_size' => max(1, (int) $ore->portionSize),
                    'composition' => $composition,
                ];
            }

            // Standalone Cookbook basket algorithm: choose the selected compressed
            // ore that covers the greatest amount of current mineral demand, then take
            // whole batches until that ore's next relevant mineral is satisfied.
            // The original default is blend; market-price optimization is not available.
            $remaining = $deficits;
            $plannedBatches = [];
            $iterations = 0;
            while (array_sum($remaining) > 0.001 && $iterations < 400) {
                $iterations++;
                $bestId = null;
                $bestScore = 0.0;
                foreach ($candidates as $candidateId => $candidate) {
                    $yieldSum = 0.0;
                    foreach ($needs as $mineralId => $required) {
                        $mineralId = (int) $mineralId;
                        $rawYield = (int) ($candidate['composition'][$mineralId] ?? 0);
                        if ($rawYield > 0 && ($remaining[$mineralId] ?? 0) > 0) {
                            $yieldSum += min((float) $remaining[$mineralId], $rawYield * $yieldFactor);
                        }
                    }
                    if ($yieldSum > $bestScore) {
                        $bestScore = $yieldSum;
                        $bestId = $candidateId;
                    }
                }
                if ($bestId === null) break;
                $candidate = $candidates[$bestId];
                $batchesNeeded = PHP_INT_MAX;
                foreach ($needs as $mineralId => $required) {
                    $mineralId = (int) $mineralId;
                    $perBatch = (int) ($candidate['composition'][$mineralId] ?? 0) * $yieldFactor;
                    if (($remaining[$mineralId] ?? 0) > 0 && $perBatch > 0) {
                        $batchesNeeded = min($batchesNeeded, (int) ceil($remaining[$mineralId] / $perBatch));
                    }
                }
                if ($batchesNeeded === PHP_INT_MAX || $batchesNeeded < 1) break;
                $plannedBatches[$bestId] = ($plannedBatches[$bestId] ?? 0) + $batchesNeeded;
                foreach ($needs as $mineralId => $required) {
                    $mineralId = (int) $mineralId;
                    if (($remaining[$mineralId] ?? 0) <= 0) continue;
                    $rawYield = (int) ($candidate['composition'][$mineralId] ?? 0);
                    $remaining[$mineralId] = max(0, $remaining[$mineralId] - ($batchesNeeded * $rawYield * $yieldFactor));
                }
            }

            $planRows = [];
            $newMineralOutput = [];
            foreach ($plannedBatches as $typeId => $batches) {
                $candidate = $candidates[$typeId];
                $units = $batches * $candidate['portion_size'];
                $planRows[] = [
                    'type_id' => $typeId,
                    'name' => $candidate['name'],
                    'batches' => $batches,
                    'portion_size' => $candidate['portion_size'],
                    'additional_units' => $units,
                ];
                foreach ($candidate['composition'] as $mineralId => $rawYield) {
                    $newMineralOutput[$mineralId] = ($newMineralOutput[$mineralId] ?? 0)
                        + (int) floor($batches * $rawYield * $yieldFactor);
                }
            }

            $allMineralIds = array_unique(array_merge(array_keys($needs), array_keys($stockMinerals), array_keys($newMineralOutput)));
            $names = $allMineralIds ? DB::table('invTypes')->whereIn('typeID', $allMineralIds)->pluck('typeName', 'typeID')->all() : [];
            $mineralRows = [];
            foreach ($needs as $mineralId => $required) {
                $available = (int) ($mineralStock[$mineralId] ?? 0);
                $fromOreStock = (int) ($stockMinerals[$mineralId] ?? 0);
                $remainingBeforeNewOre = max(0, $required - $available - $fromOreStock);
                $fromNewOre = (int) ($newMineralOutput[$mineralId] ?? 0);
                $mineralRows[] = [
                    'type_id' => $mineralId,
                    'name' => $names[$mineralId] ?? ('Type ' . $mineralId),
                    'required' => $required,
                    'at_manufacturing' => $available,
                    'from_refinery_ore_stock' => $fromOreStock,
                    'remaining_before_new_ore' => $remainingBeforeNewOre,
                    'from_additional_ore' => $fromNewOre,
                    'surplus' => max(0, $fromNewOre - $remainingBeforeNewOre),
                ];
            }

            return response()->json([
                'ore_plan' => $planRows,
                'yield_percent' => (float) $data['yield_percent'],
                'ore_stock' => array_values($oreStockRows),
                'minerals' => $mineralRows,
                'note' => 'Only minerals at Draken Manufacturing manufacturing facility are deducted as existing mineral stock. Only raw and compressed ore at Draken Barrel Refinery is considered refinery stock; compressed standard asteroid ores are reprocessed. Other stations and item categories are excluded.',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function reprocess(Request $request): JsonResponse
    {
        abort_unless(auth()->check(), 401);
        abort_unless(Schema::hasTable('invTypes') && Schema::hasTable('invTypeMaterials'), 503, 'SeAT reprocessing SDE data (invTypeMaterials) is unavailable.');

        $data = $request->validate([
            'yield_percent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.type_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ]);

        try {
            $minerals = [];
            $oreRows = [];
            foreach ($data['items'] as $entry) {
            $typeId = (int) $entry['type_id'];
            $quantity = (int) $entry['quantity'];
            if ($quantity < 1) {
                continue;
            }

            $ore = DB::table('invTypes as types')
                ->join('invGroups as groups', 'groups.groupID', '=', 'types.groupID')
                ->where('groups.categoryID', self::ORE_CATEGORY_ID)
                ->where('types.typeID', $typeId)
                ->whereIn('types.typeName', self::standardCompressedOreNames())
                ->first(['types.typeID', 'types.typeName', 'types.portionSize']);
            abort_unless($ore, 422, 'One selected item is not a compressed ore type in the SeAT SDE.');

            $batchSize = max(1, (int) $ore->portionSize);
            $batches = intdiv($quantity, $batchSize);
            if ($batches < 1) {
                $oreRows[] = [
                    'type_id' => $typeId,
                    'name' => $ore->typeName,
                    'quantity' => $quantity,
                    'batch_size' => $batchSize,
                    'batches' => 0,
                    'leftover' => $quantity,
                ];
                continue;
            }

            $materials = DB::table('invTypeMaterials')
                ->where('typeID', $typeId)
                ->get(['materialTypeID', 'quantity']);
            if ($materials->isEmpty()) {
                throw new RuntimeException('No reprocessing material data exists for ' . $ore->typeName . ' in invTypeMaterials.');
            }

            foreach ($materials as $material) {
                $mineralId = (int) $material->materialTypeID;
                $yield = (int) floor($batches * (int) $material->quantity * ((float) $data['yield_percent'] / 100));
                if ($yield > 0) {
                    $minerals[$mineralId] = ($minerals[$mineralId] ?? 0) + $yield;
                }
            }

            $oreRows[] = [
                'type_id' => $typeId,
                'name' => $ore->typeName,
                'quantity' => $quantity,
                'batch_size' => $batchSize,
                'batches' => $batches,
                'leftover' => $quantity % $batchSize,
            ];
        }

        $names = $minerals
            ? DB::table('invTypes')->whereIn('typeID', array_keys($minerals))->pluck('typeName', 'typeID')->all()
            : [];
        $mineralRows = [];
        foreach ($minerals as $typeId => $quantity) {
            $mineralRows[] = [
                'type_id' => (int) $typeId,
                'name' => $names[$typeId] ?? ('Type ' . $typeId),
                'quantity' => $quantity,
            ];
        }
        usort($mineralRows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            return response()->json([
                'yield_percent' => (float) $data['yield_percent'],
                'ores' => $oreRows,
                'minerals' => $mineralRows,
                'note' => 'Estimated SDE reprocessing output using the yield percentage you entered. Enter your actual combined facility and character yield; this planner does not infer skills, implants, rigs, tax, or structure bonuses. Only complete ore batches are processed; leftovers are shown.',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function calculate(Request $request): JsonResponse
    {
        abort_unless(auth()->check(), 401);
        if (!$this->sdeReady()) {
            return response()->json(['error' => 'SeAT SDE manufacturing tables are not available.'], 503);
        }

        $data = $request->validate([
            'product_type_id' => ['required', 'integer', 'min:1'],
            'runs' => ['required', 'integer', 'min:1', 'max:100000'],
            'product_me' => ['required', 'integer', 'min:0', 'max:10'],
            'component_me' => ['required', 'integer', 'min:0', 'max:10'],
            'character_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $product = DB::table('invTypes')->where('typeID', $data['product_type_id'])->first();
            abort_unless($product, 404, 'Product type was not found in the SeAT SDE.');

            $blueprint = DB::table('industryActivityProducts')
                ->where('activityID', self::ACTIVITY_MANUFACTURING)
                ->where('productTypeID', $data['product_type_id'])
                ->orderBy('typeID')
                ->first();
            abort_unless($blueprint, 422, 'No published manufacturing blueprint was found for this product.');

            $minerals = [];
            $other = [];
            $stack = [];
            $this->expandBlueprint(
                (int) $blueprint->typeID,
                (int) $data['runs'],
                (int) $data['product_me'],
                (int) $data['component_me'],
                $minerals,
                $other,
                $stack,
                0
            );

            $inventory = [];
            if (!empty($data['character_id'])) {
                $characterId = (int) $data['character_id'];
                $this->authorizeCharacter($characterId);
                abort_unless(Schema::hasTable('character_assets'), 503, 'SeAT character asset cache is unavailable.');
                $manufacturingId = $this->structureId('COOKBOOK_MANUFACTURING_STRUCTURE_ID', 'BKG-Q2 - Draken Manufacturing');
                foreach ($this->assetsAtStructure($characterId, $manufacturingId) as $asset) {
                    $typeId = (int) $asset->type_id;
                    if (!in_array($typeId, self::MINERAL_TYPE_IDS, true)) continue;
                    $inventory[$typeId] = ($inventory[$typeId] ?? 0) + max(0, (int) $asset->quantity);
                }
            }

            $mineralRows = $this->materialRows($minerals, $inventory);
            $otherRows = $this->materialRows($other, $inventory);

            return response()->json([
                'product' => $product->typeName,
                'runs' => (int) $data['runs'],
                'product_me' => (int) $data['product_me'],
                'component_me' => (int) $data['component_me'],
                'inventory_character_id' => !empty($data['character_id']) ? (int) $data['character_id'] : null,
                'minerals' => $mineralRows,
                'other_materials' => $otherRows,
                'note' => 'Quantities are calculated from the SDE currently loaded in SeAT. Non-mineral inputs are listed separately; no market prices are assumed.',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    private function structureId(string $environmentKey, string $structureName): int
    {
        $configured = trim((string) env($environmentKey, ''));
        if ($configured !== '' && ctype_digit($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        // Verified Triglavian Consortium BKG-Q2 facility IDs. Environment values
        // remain an override, but deployment does not depend on structure-name cache lookup.
        $knownFacilityIds = [
            'COOKBOOK_REFINERY_STRUCTURE_ID' => 1035861034790,
            'COOKBOOK_MANUFACTURING_STRUCTURE_ID' => 1035860820236,
        ];
        if (isset($knownFacilityIds[$environmentKey])) {
            return $knownFacilityIds[$environmentKey];
        }

        if (Schema::hasTable('universe_structures') && Schema::hasColumn('universe_structures', 'name')) {
            // SeAT/EVE caches may store the structure's actual name without the
            // solar-system prefix used in the Cookbook label. Try exact first,
            // then a unique suffix/name match; never guess if it is ambiguous.
            $shortName = preg_replace('/^BKG-Q2\\s*[-–—:]\\s*/i', '', $structureName);
            $exact = DB::table('universe_structures')
                ->where('name', $structureName)
                ->limit(2)
                ->pluck('structure_id');

            if ($exact->count() === 1) {
                return (int) $exact->first();
            }

            $matches = DB::table('universe_structures')
                ->where('name', 'like', '%' . addcslashes($shortName, '%_') . '%')
                ->limit(3)
                ->pluck('structure_id');

            if ($matches->count() === 1) {
                return (int) $matches->first();
            }
            if ($matches->count() > 1) {
                throw new RuntimeException('More than one cached SeAT structure matches "' . $shortName . '". Set ' . $environmentKey . ' in /opt/seat-docker/.env to the exact structure ID.');
            }
        }

        throw new RuntimeException('Could not resolve the BKG-Q2 structure "' . $structureName . '" from SeAT universe_structures. SeAT may cache the name without the BKG-Q2 prefix, or the structure may not be cached yet. Set ' . $environmentKey . ' in /opt/seat-docker/.env to its exact structure ID; no global inventory fallback is used.');
    }

    private function assetsAtStructure(int $characterId, int $structureId)
    {
        // Start only at the configured structure. Recurse through inventory containers,
        // never through ships, so ore/minerals in docked ship cargo are not counted.
        $assets = collect();
        $frontier = DB::table('character_assets')
            ->where('character_id', $characterId)
            ->where('location_id', $structureId)
            ->get(['item_id', 'type_id', 'quantity', 'location_id']);

        $visited = [];
        $depth = 0;
        $maxDepth = 20;
        $maxAssets = 100000;

        while ($frontier->isNotEmpty() && $depth <= $maxDepth) {
            $newParents = [];
            $frontierTypeIds = $frontier->pluck('type_id')->unique()->values()->all();
            $containerTypes = $frontierTypeIds
                ? DB::table('invTypes as types')
                    ->join('invGroups as groups', 'groups.groupID', '=', 'types.groupID')
                    ->whereIn('types.typeID', $frontierTypeIds)
                    ->where('groups.groupName', 'like', '%Container%')
                    ->pluck('types.typeID')
                    ->map(fn ($id) => (int) $id)
                    ->all()
                : [];
            $containerTypeLookup = array_fill_keys($containerTypes, true);

            foreach ($frontier as $asset) {
                $itemId = (int) $asset->item_id;
                if (isset($visited[$itemId])) continue;
                $visited[$itemId] = true;
                $assets->push($asset);

                if (isset($containerTypeLookup[(int) $asset->type_id])) {
                    $newParents[] = $itemId;
                }
                if ($assets->count() >= $maxAssets) {
                    throw new RuntimeException('The selected structure contains more than 100,000 cached asset entries; narrow the inventory scope before loading it.');
                }
            }

            if (!$newParents || $depth >= $maxDepth) break;

            $frontier = collect();
            foreach (array_chunk($newParents, 500) as $parentIds) {
                $children = DB::table('character_assets')
                    ->where('character_id', $characterId)
                    ->whereIn('location_id', $parentIds)
                    ->get(['item_id', 'type_id', 'quantity', 'location_id']);
                foreach ($children as $child) {
                    if (!isset($visited[(int) $child->item_id])) $frontier->push($child);
                }
            }
            $depth++;
        }

        return $assets;
    }

    private function materialRows(array $materials, array $inventory = []): array
    {
        $ids = array_keys($materials);
        $names = $ids ? DB::table('invTypes')->whereIn('typeID', $ids)->pluck('typeName', 'typeID')->all() : [];
        $rows = [];
        foreach ($materials as $typeId => $quantity) {
            $rows[] = [
                'type_id' => (int) $typeId,
                'name' => $names[$typeId] ?? ('Type ' . $typeId),
                'quantity' => (int) $quantity,
                'available_quantity' => (int) ($inventory[$typeId] ?? 0),
                'shortfall_quantity' => max(0, (int) $quantity - (int) ($inventory[$typeId] ?? 0)),
            ];
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $rows;
    }

    private function expandBlueprint(
        int $blueprintTypeId,
        int $runs,
        int $me,
        int $componentMe,
        array &$minerals,
        array &$other,
        array &$stack,
        int $depth
    ): void {
        if ($depth > 12) {
            throw new RuntimeException('Blueprint expansion exceeded 12 levels; check the SDE blueprint data.');
        }
        if (isset($stack[$blueprintTypeId])) {
            throw new RuntimeException('A recursive blueprint cycle was found in the SDE data.');
        }

        $stack[$blueprintTypeId] = true;
        $materials = DB::table('industryActivityMaterials')
            ->where('typeID', $blueprintTypeId)
            ->where('activityID', self::ACTIVITY_MANUFACTURING)
            ->get(['materialTypeID', 'quantity']);

        if ($materials->isEmpty()) {
            unset($stack[$blueprintTypeId]);
            throw new RuntimeException('No manufacturing materials found for blueprint type ' . $blueprintTypeId . '.');
        }

        foreach ($materials as $material) {
            $materialTypeId = (int) $material->materialTypeID;
            $quantity = (int) ceil(((int) $material->quantity) * $runs * (1 - ($me / 100)));
            if ($quantity <= 0) {
                continue;
            }

            if (in_array($materialTypeId, self::MINERAL_TYPE_IDS, true)) {
                $minerals[$materialTypeId] = ($minerals[$materialTypeId] ?? 0) + $quantity;
                continue;
            }

            $componentBlueprint = DB::table('industryActivityProducts')
                ->where('activityID', self::ACTIVITY_MANUFACTURING)
                ->where('productTypeID', $materialTypeId)
                ->orderBy('typeID')
                ->first();

            if ($componentBlueprint) {
                $this->expandBlueprint(
                    (int) $componentBlueprint->typeID,
                    $quantity,
                    $componentMe,
                    $componentMe,
                    $minerals,
                    $other,
                    $stack,
                    $depth + 1
                );
            } else {
                $other[$materialTypeId] = ($other[$materialTypeId] ?? 0) + $quantity;
            }
        }

        unset($stack[$blueprintTypeId]);
    }

    private function sdeReady(): bool
    {
        return Schema::hasTable('invTypes')
            && Schema::hasTable('industryActivityProducts')
            && Schema::hasTable('industryActivityMaterials');
    }

    private function characters()
    {
        $user = auth()->user();
        if (!$user) {
            return collect();
        }
        return method_exists($user, 'characters') ? $user->characters->sortBy('name')->values() : collect();
    }

    private function authorizeCharacter(int $characterId): void
    {
        $user = auth()->user();
        $allowed = method_exists($user, 'associatedCharacterIds')
            ? $user->associatedCharacterIds()
            : $this->characters()->pluck('character_id')->all();
        abort_unless(in_array($characterId, array_map('intval', $allowed), true), 403, 'That character is not linked to your SeAT account.');
    }
}
