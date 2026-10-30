<?php

namespace Seat\\Web\\Http\\Controllers;

use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\View\\View;
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

        $items = DB::table('character_assets as assets')
            ->join('invTypes as types', 'types.typeID', '=', 'assets.type_id')
            ->join('invGroups as groups', 'groups.groupID', '=', 'types.groupID')
            ->where('assets.character_id', $characterId)
            ->where('groups.categoryID', self::ORE_CATEGORY_ID)
            ->where('types.typeName', 'like', '%Compressed%')
            ->groupBy('assets.type_id', 'types.typeName', 'types.portionSize')
            ->selectRaw('assets.type_id as type_id, types.typeName as name, types.portionSize as portion_size, SUM(assets.quantity) as quantity')
            ->orderBy('types.typeName')
            ->get()
            ->map(function ($item) {
                return [
                    'type_id' => (int) $item->type_id,
                    'name' => $item->name,
                    'portion_size' => max(1, (int) $item->portion_size),
                    'quantity' => max(0, (int) $item->quantity),
                ];
            })
            ->values();

        return response()->json([
            'character_id' => $characterId,
            'items' => $items,
            'note' => 'Inventory is read from SeAT cached character_assets. The cache can lag EVE; refresh the character assets in SeAT before relying on these quantities. All locations in this character cache are included.',
        ]);
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

            $mineralRows = $this->materialRows($minerals);
            $otherRows = $this->materialRows($other);

            return response()->json([
                'product' => $product->typeName,
                'runs' => (int) $data['runs'],
                'product_me' => (int) $data['product_me'],
                'component_me' => (int) $data['component_me'],
                'minerals' => $mineralRows,
                'other_materials' => $otherRows,
                'note' => 'Quantities are calculated from the SDE currently loaded in SeAT. Non-mineral inputs are listed separately; no market prices are assumed.',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    private function materialRows(array $materials): array
    {
        $ids = array_keys($materials);
        $names = $ids ? DB::table('invTypes')->whereIn('typeID', $ids)->pluck('typeName', 'typeID')->all() : [];
        $rows = [];
        foreach ($materials as $typeId => $quantity) {
            $rows[] = [
                'type_id' => (int) $typeId,
                'name' => $names[$typeId] ?? ('Type ' . $typeId),
                'quantity' => (int) $quantity,
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
        if (method_exists($user, 'all_characters')) {
            return $user->all_characters()->sortBy('name')->values();
        }
        return method_exists($user, 'characters') ? $user->characters : collect();
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
