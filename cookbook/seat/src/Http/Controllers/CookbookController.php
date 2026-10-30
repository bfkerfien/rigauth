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
        abort_unless(Schema::hasTable('character_assets') && Schema::hasTable('invTypes') && Schema::hasTable('invGroups'), 503, 'SeAT character asset or SDE tables are unavailable.');

        $data = $request->validate(['character_id' => ['required', 'integer', 'min:1']]);
        $characterId = (int) $data['character_id'];
        $this->authorizeCharacter($characterId);

        try {
            $refineryId = $this->structureId('COOKBOOK_REFINERY_STRUCTURE_ID', 'Draken Barrel Refinery');
            $manufacturingId = $this->structureId('COOKBOOK_MANUFACTURING_STRUCTURE_ID', 'Draken Manufacturing');
            $refineryAssets = $this->assetsAtStructure($characterId, $refineryId);
            $manufacturingAssets = $this->assetsAtStructure($characterId, $manufacturingId);

            $oreTypeIds = $refineryAssets->pluck('type_id')->unique()->values()->all();
            $oreTypes = $oreTypeIds
                ? DB::table('invTypes')->whereIn('typeID', $oreTypeIds)->where('typeName', 'like', '%Compressed%')->get(['typeID', 'typeName', 'portionSize'])->keyBy('typeID')
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
                'manufacturing_location' => 'BKG-Q2 — Draken Manufacturing',
                'items' => array_values($oreTotals),
                'materials' => $materialTotals,
                'note' => 'Ore is read only from BKG-Q2 — Draken Barrel Refinery. Manufacturing material stock is read only from BKG-Q2 — Draken Manufacturing. Nested containers are included. Data comes from SeAT cached character assets and may be stale until ESI assets refresh.',
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
            ->where('types.published', 1)
            ->orderBy('types.typeName')
            ->get(['types.typeID as type_id', 'types.typeName as name', 'types.portionSize as portion_size']);

        return response()->json(['items' => $items]);
    }

    public function orePlan(Request $request): JsonResponse
    {
        abort_unless(auth()->check(), 401);
        abort_unless(Schema::hasTable('character_assets') && Schema::hasTable('invTypes') && Schema::hasTable('invTypeMaterials'), 503, 'SeAT character asset or reprocessing SDE data is unavailable.');

        $data = $request->validate([
            'character_id' => ['required', 'integer', 'min:1'],
            'ore_type_id' => ['required', 'integer', 'min:1'],
            'yield_percent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'mineral_needs' => ['required', 'array', 'min:1', 'max:50'],
            'mineral_needs.*.type_id' => ['required', 'integer', 'min:1'],
            'mineral_needs.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ]);

        $characterId = (int) $data['character_id'];
        $this->authorizeCharacter($characterId);

        try {
            $refineryId = $this->structureId('COOKBOOK_REFINERY_STRUCTURE_ID', 'Draken Barrel Refinery');
            $manufacturingId = $this->structureId('COOKBOOK_MANUFACTURING_STRUCTURE_ID', 'Draken Manufacturing');
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

            $oreIds = $refineryAssets->pluck('type_id')->unique()->values()->all();
            $oreCatalog = $oreIds
                ? DB::table('invTypes')->whereIn('typeID', $oreIds)->where('typeName', 'like', '%Compressed%')->get(['typeID', 'typeName', 'portionSize'])->keyBy('typeID')
                : collect();
            $oreStockRows = [];
            $stockMinerals = [];
            $yieldFactor = (float) $data['yield_percent'] / 100;

            foreach ($refineryAssets as $asset) {
                $typeId = (int) $asset->type_id;
                $ore = $oreCatalog->get($typeId);
                if (!$ore) {
                    continue;
                }
                $portion = max(1, (int) $ore->portionSize);
                $quantity = max(0, (int) $asset->quantity);
                $batches = intdiv($quantity, $portion);
                if ($batches > 0) {
                    $materials = DB::table('invTypeMaterials')->where('typeID', $typeId)->get(['materialTypeID', 'quantity']);
                    foreach ($materials as $material) {
                        $mineralId = (int) $material->materialTypeID;
                        $yield = (int) floor($batches * (int) $material->quantity * $yieldFactor);
                        $stockMinerals[$mineralId] = ($stockMinerals[$mineralId] ?? 0) + $yield;
                    }
                }
                $oreStockRows[$typeId] = [
                    'type_id' => $typeId,
                    'name' => $ore->typeName,
                    'quantity' => ($oreStockRows[$typeId]['quantity'] ?? 0) + $quantity,
                    'portion_size' => $portion,
                ];
            }

            foreach ($deficits as $typeId => $quantity) {
                $deficits[$typeId] = max(0, $quantity - (int) ($stockMinerals[$typeId] ?? 0));
            }

            $targetOre = DB::table('invTypes')
                ->where('typeID', (int) $data['ore_type_id'])
                ->where('typeName', 'like', '%Compressed%')
                ->first(['typeID', 'typeName', 'portionSize']);
            abort_unless($targetOre, 422, 'Select a compressed ore type from the SeAT SDE.');

            $targetMaterials = DB::table('invTypeMaterials')
                ->where('typeID', (int) $data['ore_type_id'])
                ->get(['materialTypeID', 'quantity']);
            abort_unless($targetMaterials->isNotEmpty(), 422, 'No reprocessing composition is available for the selected compressed ore.');

            $perBatch = [];
            foreach ($targetMaterials as $material) {
                $mineralId = (int) $material->materialTypeID;
                $perBatch[$mineralId] = (int) $material->quantity;
            }

            $requiredBatches = 0;
            $uncovered = [];
            foreach ($deficits as $mineralId => $remaining) {
                if ($remaining <= 0) {
                    continue;
                }
                $baseYield = (int) ($perBatch[$mineralId] ?? 0);
                if ($baseYield <= 0 || $baseYield * $yieldFactor < 1) {
                    $uncovered[$mineralId] = $remaining;
                    continue;
                }
                $requiredBatches = max($requiredBatches, (int) ceil($remaining / ($baseYield * $yieldFactor)));
            }
            if ($uncovered) {
                $names = DB::table('invTypes')->whereIn('typeID', array_keys($uncovered))->pluck('typeName', 'typeID')->all();
                $labels = [];
                foreach ($uncovered as $id => $quantity) {
                    $labels[] = ($names[$id] ?? ('Type ' . $id)) . ': ' . number_format($quantity);
                }
                throw new RuntimeException('The selected ore cannot supply every remaining mineral. Choose another compressed ore: ' . implode('; ', $labels));
            }

            $additionalOutput = [];
            foreach ($perBatch as $mineralId => $baseYield) {
                $amount = (int) floor($requiredBatches * $baseYield * $yieldFactor);
                $additionalOutput[$mineralId] = $amount;
            }

            $names = DB::table('invTypes')->whereIn('typeID', array_unique(array_merge(array_keys($needs), array_keys($stockMinerals), array_keys($additionalOutput))))->pluck('typeName', 'typeID')->all();
            $mineralRows = [];
            foreach ($needs as $mineralId => $required) {
                $available = (int) ($mineralStock[$mineralId] ?? 0);
                $fromOreStock = (int) ($stockMinerals[$mineralId] ?? 0);
                $remaining = (int) ($deficits[$mineralId] ?? 0);
                $fromNewOre = (int) ($additionalOutput[$mineralId] ?? 0);
                $mineralRows[] = [
                    'type_id' => $mineralId,
                    'name' => $names[$mineralId] ?? ('Type ' . $mineralId),
                    'required' => $required,
                    'at_manufacturing' => $available,
                    'from_refinery_ore_stock' => $fromOreStock,
                    'remaining_before_new_ore' => $remaining,
                    'from_additional_ore' => $fromNewOre,
                    'surplus' => max(0, $fromNewOre - $remaining),
                ];
            }

            return response()->json([
                'selected_ore' => $targetOre->typeName,
                'yield_percent' => (float) $data['yield_percent'],
                'ore_stock' => array_values($oreStockRows),
                'required_batches' => $requiredBatches,
                'portion_size' => max(1, (int) $targetOre->portionSize),
                'additional_ore_units' => $requiredBatches * max(1, (int) $targetOre->portionSize),
                'minerals' => $mineralRows,
                'note' => 'Ore stock at Draken Barrel Refinery is converted first, then existing minerals at Draken Manufacturing are deducted from blueprint requirements. Additional ore is the number of complete batches of the selected compressed ore needed to cover every remaining mineral; surplus is shown. This is a single-ore-type plan, not a multi-ore optimized mix.',
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

            $ore = DB::table('invTypes')
                ->where('typeID', $typeId)
                ->where('typeName', 'like', '%Compressed%')
                ->first(['typeID', 'typeName', 'portionSize']);
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
                $manufacturingId = $this->structureId('COOKBOOK_MANUFACTURING_STRUCTURE_ID', 'Draken Manufacturing');
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

        foreach (['eve_names', 'eveNames'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            $idColumn = in_array('id', $columns, true) ? 'id' : (in_array('item_id', $columns, true) ? 'item_id' : null);
            if (!$idColumn || !in_array('name', $columns, true)) {
                continue;
            }
            $matches = DB::table($table)->where('name', $structureName)->limit(3)->pluck($idColumn);
            if ($matches->count() === 1) {
                return (int) $matches->first();
            }
            if ($matches->count() > 1) {
                throw new RuntimeException('More than one EVE name matches ' . $structureName . '. Set ' . $environmentKey . ' in /opt/seat-docker/.env to the exact structure ID.');
            }
        }

        throw new RuntimeException('Could not resolve the BKG-Q2 structure "' . $structureName . '" from SeAT name cache. Set ' . $environmentKey . ' in /opt/seat-docker/.env to that structure ID; no global inventory fallback is used.');
    }

    private function assetsAtStructure(int $characterId, int $structureId)
    {
        $assets = DB::table('character_assets')
            ->where('character_id', $characterId)
            ->get(['item_id', 'type_id', 'quantity', 'location_id']);

        $parents = [];
        foreach ($assets as $asset) {
            $parents[(int) $asset->item_id] = (int) $asset->location_id;
        }

        return $assets->filter(function ($asset) use ($parents, $structureId) {
            $locationId = (int) $asset->location_id;
            $visited = [];
            while (isset($parents[$locationId]) && !isset($visited[$locationId])) {
                $visited[$locationId] = true;
                $locationId = (int) $parents[$locationId];
            }
            return $locationId === $structureId;
        })->values();
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
