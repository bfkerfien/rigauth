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
    // Keep the planner on base-grade compressed asteroid ores only.
    // Exclude current II/III/IV grades and legacy +5/+10/+15 variant names.
    private const NON_BASE_ORE_MARKERS = [
        'II-Grade', 'III-Grade', 'IV-Grade',
        'Concentrated', 'Dense', 'Condensed', 'Massive', 'Solid', 'Viscous',
        'Azure', 'Rich', 'Silvery', 'Golden', 'Luminous', 'Fiery', 'Pure',
        'Pristine', 'Vivid', 'Radiant', 'Vitric', 'Glazed', 'Lustrous', 'Glistening',
        'Iridescent', 'Prismatic', 'Onyx', 'Obsidian', 'Sharp', 'Crystalline',
        'Bright', 'Gleaming', 'Crimson', 'Prime', 'Triclinic', 'Monoclinic',
        'Magma', 'Vitreous', 'Noble', 'Royal', 'Doped', 'Boosted', 'Plum',
        'Prize', 'Bootleg', 'Firewater', 'Abyssal', 'Hadal', 'Clear', 'Inky',
        'Opaque', 'Dull', 'Serrated', 'Kaolin', 'Argil', 'Adobe', 'Fragrant',
        'Intoxicating', 'Foggy', 'Overcast', 'Stormy',
        'Stable', 'Glossy', 'Opulent', 'Sparkling', 'Platinoid',
        'Resplendent', 'Immaculate', 'Scintillating', 'Brilliant', 'Jet',
        'Pellucid', 'Dazzling', 'Flawless', 'Cubic', 'Imperial', 'Augmented',
        'Plunder', 'Moonshine', 'Ambrosial',
    ];

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
            $manufacturingId = $this->structureId('COOKBOOK_MANUFACTURING_STRUCTURE_ID', 'BKG-Q2 - The New Dominion');
            $refineryAssets = $this->assetsAtStructure($characterId, $refineryId);
            $manufacturingAssets = $this->assetsAtStructure($characterId, $manufacturingId);

            $oreTypeIds = $refineryAssets->pluck('type_id')->unique()->values()->all();
            $oreTypes = $oreTypeIds
                ? DB::table('invTypes as types')
                    ->join('invGroups as groups', 'groups.groupID', '=', 'types.groupID')
                    ->where('groups.categoryID', self::ORE_CATEGORY_ID)
                    ->whereIn('types.typeID', $oreTypeIds)
                    ->where('types.typeName', 'like', '%Compressed%')
                    ->whereRaw("types.typeName = CONCAT('Compressed ', REPLACE(groups.groupName, 'Compressed ', ''))")
                    ->whereExists(function ($materials) {
                        $materials->select(DB::raw(1))
                            ->from('invTypeMaterials as ore_minerals')
                            ->whereColumn('ore_minerals.typeID', 'types.typeID')
                            ->whereIn('ore_minerals.materialTypeID', self::MINERAL_TYPE_IDS);
                    })
                    ->whereNotExists(function ($materials) {
                        $materials->select(DB::raw(1))
                            ->from('invTypeMaterials as non_asteroid_products')
                            ->whereColumn('non_asteroid_products.typeID', 'types.typeID')
                            ->whereNotIn('non_asteroid_products.materialTypeID', self::MINERAL_TYPE_IDS);
                    })
                    ->where(function ($query) {
                        foreach (self::NON_BASE_ORE_MARKERS as $marker) {
                            $query->where('types.typeName', 'not like', '%' . $marker . '%');
                        }
                    })
                    ->get(['types.typeID', 'types.typeName', 'types.portionSize'])
                    ->keyBy('typeID')
                : collect();

            $oreTotals = [];
            foreach ($refineryAssets as $asset) {
                $type = $oreTypes->get((int) $asset->type_id);
                if (!$type) {
                    continue;
                }
                $typeId = (int) $asset->type_id;
                if (!isset($oreTotals[$typeId])) {
                    $oreTotals[$typeId] = [
                        'type_id' => $typeId,
                        'name' => $type->typeName,
                        'portion_size' => max(1, (int) $type->portionSize),
                        'quantity' => 0,
                    ];
                }
                $oreTotals[$typeId]['quantity'] += max(0, (int) $asset->quantity);
            }

            $materialTotals = [];
            foreach ($manufacturingAssets as $asset) {
                $typeId = (int) $asset->type_id;
                $materialTotals[$typeId] = ($materialTotals[$typeId] ?? 0) + max(0, (int) $asset->quantity);
            }

            return response()->json([
                'character_id' => $characterId,
                'refinery_location' => 'BKG-Q2 — Draken Barrel Refinery',
                'manufacturing_location' => 'BKG-Q2 — The New Dominion (temporary manufacturing stock)',
                'items' => array_values($oreTotals),
                'materials' => $materialTotals,
                'note' => 'Ore is read only from BKG-Q2 — Draken Barrel Refinery. Manufacturing material stock is temporarily read only from BKG-Q2 — The New Dominion, pending identification of the dedicated Draken Manufacturing structure. Nested containers are included. Data comes from SeAT cached character assets and may be stale until ESI assets refresh.',
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
            ->where('types.typeName', 'like', '%Compressed%')
                    ->whereRaw("types.typeName = CONCAT('Compressed ', REPLACE(groups.groupName, 'Compressed ', ''))")
                    ->whereExists(function ($materials) {
                        $materials->select(DB::raw(1))
                            ->from('invTypeMaterials as ore_minerals')
                            ->whereColumn('ore_minerals.typeID', 'types.typeID')
                            ->whereIn('ore_minerals.materialTypeID', self::MINERAL_TYPE_IDS);
                    })
                    ->whereNotExists(function ($materials) {
                        $materials->select(DB::raw(1))
                            ->from('invTypeMaterials as non_asteroid_products')
                            ->whereColumn('non_asteroid_products.typeID', 'types.typeID')
                            ->whereNotIn('non_asteroid_products.materialTypeID', self::MINERAL_TYPE_IDS);
                    })
                    ->where(function ($query) {
                        foreach (self::NON_BASE_ORE_MARKERS as $marker) {
                            $query->where('types.typeName', 'not like', '%' . $marker . '%');
                        }
                    })
            ->where('types.published', 1)
            ->orderBy('groups.groupName')
            ->orderBy('types.typeName')
            ->get(['types.typeID as type_id', 'types.typeName as name', 'types.portionSize as portion_size', 'groups.groupName as section']);

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
            $manufacturingId = $this->structureId('COOKBOOK_MANUFACTURING_STRUCTURE_ID', 'BKG-Q2 - The New Dominion');
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
                $mineralStock[$typeId] = ($mineralStock[$typeId] ?? 0) + max(0, (int) $asset->quantity);
            }
            $deficits = [];
            foreach ($needs as $typeId => $required) {
                $deficits[$typeId] = max(0, $required - (int) ($mineralStock[$typeId] ?? 0));
            }

            // Sum every compressed ore stack at the refinery before calculating whole batches.
            $oreIds = $refineryAssets->pluck('type_id')->unique()->values()->all();
            $oreCatalog = $oreIds
                ? DB::table('invTypes as types')
                    ->join('invGroups as groups', 'groups.groupID', '=', 'types.groupID')
                    ->where('groups.categoryID', self::ORE_CATEGORY_ID)
                    ->whereIn('types.typeID', $oreIds)
                    ->where('types.typeName', 'like', '%Compressed%')
                    ->whereRaw("types.typeName = CONCAT('Compressed ', REPLACE(groups.groupName, 'Compressed ', ''))")
                    ->whereExists(function ($materials) {
                        $materials->select(DB::raw(1))
                            ->from('invTypeMaterials as ore_minerals')
                            ->whereColumn('ore_minerals.typeID', 'types.typeID')
                            ->whereIn('ore_minerals.materialTypeID', self::MINERAL_TYPE_IDS);
                    })
                    ->whereNotExists(function ($materials) {
                        $materials->select(DB::raw(1))
                            ->from('invTypeMaterials as non_asteroid_products')
                            ->whereColumn('non_asteroid_products.typeID', 'types.typeID')
                            ->whereNotIn('non_asteroid_products.materialTypeID', self::MINERAL_TYPE_IDS);
                    })
                    ->where(function ($query) {
                        foreach (self::NON_BASE_ORE_MARKERS as $marker) {
                            $query->where('types.typeName', 'not like', '%' . $marker . '%');
                        }
                    })
                    ->get(['types.typeID', 'types.typeName', 'types.portionSize'])
                    ->keyBy('typeID')
                : collect();
            $oreQuantities = [];
            foreach ($refineryAssets as $asset) {
                $typeId = (int) $asset->type_id;
                $oreQuantities[$typeId] = ($oreQuantities[$typeId] ?? 0) + max(0, (int) $asset->quantity);
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
                        throw new RuntimeException('No reprocessing material data exists for refinery stock ' . $ore->typeName . '.');
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
                ->where('types.typeName', 'like', '%Compressed%')
                    ->whereRaw("types.typeName = CONCAT('Compressed ', REPLACE(groups.groupName, 'Compressed ', ''))")
                    ->whereExists(function ($materials) {
                        $materials->select(DB::raw(1))
                            ->from('invTypeMaterials as ore_minerals')
                            ->whereColumn('ore_minerals.typeID', 'types.typeID')
                            ->whereIn('ore_minerals.materialTypeID', self::MINERAL_TYPE_IDS);
                    })
                    ->whereNotExists(function ($materials) {
                        $materials->select(DB::raw(1))
                            ->from('invTypeMaterials as non_asteroid_products')
                            ->whereColumn('non_asteroid_products.typeID', 'types.typeID')
                            ->whereNotIn('non_asteroid_products.materialTypeID', self::MINERAL_TYPE_IDS);
                    })
                    ->where(function ($query) {
                        foreach (self::NON_BASE_ORE_MARKERS as $marker) {
                            $query->where('types.typeName', 'not like', '%' . $marker . '%');
                        }
                    })
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

            // Greedily select the ore batch that covers the largest share of the current mineral deficit.
            // Recalculate after each choice so the mix can switch ore types as minerals are satisfied.
            $remaining = $deficits;
            $plannedBatches = [];
            $iterations = 0;
            while (array_sum($remaining) > 0 && $iterations < 500) {
                $iterations++;
                $bestId = null;
                $bestScore = 0.0;
                foreach ($candidates as $candidateId => $candidate) {
                    $score = 0.0;
                    foreach ($remaining as $mineralId => $deficit) {
                        if ($deficit <= 0) {
                            continue;
                        }
                        $rawYield = (int) ($candidate['composition'][$mineralId] ?? 0);
                        if ($rawYield > 0) {
                            $score += min($deficit, $rawYield * $yieldFactor) / $deficit;
                        }
                    }
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestId = $candidateId;
                    }
                }

                if ($bestId === null) {
                    break;
                }

                $candidate = $candidates[$bestId];
                $batchesNeededForNextCoverage = PHP_INT_MAX;
                foreach ($remaining as $mineralId => $deficit) {
                    $rawYield = (int) ($candidate['composition'][$mineralId] ?? 0);
                    if ($deficit > 0 && $rawYield > 0 && $rawYield * $yieldFactor > 0) {
                        $batchesNeededForNextCoverage = min(
                            $batchesNeededForNextCoverage,
                            (int) ceil($deficit / ($rawYield * $yieldFactor))
                        );
                    }
                }
                if ($batchesNeededForNextCoverage === PHP_INT_MAX || $batchesNeededForNextCoverage < 1) {
                    break;
                }

                $plannedBatches[$bestId] = ($plannedBatches[$bestId] ?? 0) + $batchesNeededForNextCoverage;
                foreach ($candidate['composition'] as $mineralId => $rawYield) {
                    if (!array_key_exists($mineralId, $remaining) || $remaining[$mineralId] <= 0) {
                        continue;
                    }
                    $produced = (int) floor($batchesNeededForNextCoverage * $rawYield * $yieldFactor);
                    $remaining[$mineralId] = max(0, $remaining[$mineralId] - $produced);
                }
            }

            $uncovered = array_filter($remaining, fn ($quantity) => $quantity > 0);
            if ($uncovered) {
                $names = DB::table('invTypes')->whereIn('typeID', array_keys($uncovered))->pluck('typeName', 'typeID')->all();
                $labels = [];
                foreach ($uncovered as $id => $quantity) {
                    $labels[] = ($names[$id] ?? ('Type ' . $id)) . ': ' . number_format($quantity);
                }
                throw new RuntimeException('The selected ore mix cannot cover these remaining minerals. Select additional compressed ore types: ' . implode('; ', $labels));
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
                'note' => 'Existing minerals at the temporary manufacturing location, The New Dominion, are deducted first. Complete batches of every compressed ore type already at Draken Barrel Refinery are then converted at the entered yield. The remaining mineral deficits are covered by the selected compressed ore mix. The suggested mix uses a transparent greedy coverage calculation; it is not guaranteed to be the mathematically cheapest or lowest-volume mix.',
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
                ->where('types.typeName', 'like', '%Compressed%')
                    ->whereRaw("types.typeName = CONCAT('Compressed ', REPLACE(groups.groupName, 'Compressed ', ''))")
                    ->whereExists(function ($materials) {
                        $materials->select(DB::raw(1))
                            ->from('invTypeMaterials as ore_minerals')
                            ->whereColumn('ore_minerals.typeID', 'types.typeID')
                            ->whereIn('ore_minerals.materialTypeID', self::MINERAL_TYPE_IDS);
                    })
                    ->whereNotExists(function ($materials) {
                        $materials->select(DB::raw(1))
                            ->from('invTypeMaterials as non_asteroid_products')
                            ->whereColumn('non_asteroid_products.typeID', 'types.typeID')
                            ->whereNotIn('non_asteroid_products.materialTypeID', self::MINERAL_TYPE_IDS);
                    })
                    ->where(function ($query) {
                        foreach (self::NON_BASE_ORE_MARKERS as $marker) {
                            $query->where('types.typeName', 'not like', '%' . $marker . '%');
                        }
                    })
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
                $manufacturingId = $this->structureId('COOKBOOK_MANUFACTURING_STRUCTURE_ID', 'BKG-Q2 - The New Dominion');
                foreach ($this->assetsAtStructure($characterId, $manufacturingId) as $asset) {
                    $typeId = (int) $asset->type_id;
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

        if (Schema::hasTable('universe_structures')) {
            $matches = DB::table('universe_structures')
                ->where('name', $structureName)
                ->limit(3)
                ->pluck('structure_id');
            if ($matches->count() === 1) {
                return (int) $matches->first();
            }
            if ($matches->count() > 1) {
                throw new RuntimeException('More than one SeAT universe structure matches ' . $structureName . '. Set ' . $environmentKey . ' in /opt/seat-docker/.env to the exact structure ID.');
            }
        }

        throw new RuntimeException('Could not resolve the BKG-Q2 structure "' . $structureName . '" from SeAT universe_structures. Confirm SeAT has cached this structure or set ' . $environmentKey . ' in /opt/seat-docker/.env to its structure ID; no global inventory fallback is used.');
    }

    private function assetsAtStructure(int $characterId, int $structureId)
    {
        // Anchor the query at the requested structure and recurse only into its containers.
        // Assets at other locations are never loaded into the Cookbook inventory result.
        $rows = DB::select(
            'WITH RECURSIVE facility_assets AS (
                SELECT item_id, type_id, quantity, location_id, 0 AS depth
                FROM character_assets
                WHERE character_id = ? AND location_id = ?
                UNION ALL
                SELECT child.item_id, child.type_id, child.quantity, child.location_id, parent.depth + 1 AS depth
                FROM character_assets AS child
                INNER JOIN facility_assets AS parent ON child.location_id = parent.item_id
                WHERE child.character_id = ? AND parent.depth < 20
            )
            SELECT item_id, type_id, quantity, location_id FROM facility_assets',
            [$characterId, $structureId, $characterId]
        );

        return collect($rows);
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
