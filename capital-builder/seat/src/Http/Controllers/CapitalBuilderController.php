<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use RuntimeException;

class CapitalBuilderController extends Controller
{
    private const ACTIVITY_MANUFACTURING = 1;
    private const MINERAL_TYPE_IDS = [34, 35, 36, 37, 38, 39, 40, 11399];

    public function index(): View
    {
        return view('web::capital-builder.index', [
            'sdeReady' => Schema::hasTable('invTypes')
                && Schema::hasTable('industryActivityProducts')
                && Schema::hasTable('industryActivityMaterials'),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        abort_unless(auth()->check(), 401);
        if (!$this->sdeReady()) {
            return response()->json(['error' => 'SeAT manufacturing SDE tables are unavailable.'], 503);
        }
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) return response()->json(['items' => []]);

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
            ->limit(40)
            ->get();

        return response()->json(['items' => $items]);
    }

    public function calculate(Request $request): JsonResponse
    {
        abort_unless(auth()->check(), 401);
        if (!$this->sdeReady()) {
            return response()->json(['error' => 'SeAT manufacturing SDE tables are unavailable.'], 503);
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
                ->orderBy('typeID')->first();
            abort_unless($blueprint, 422, 'No manufacturing blueprint was found for this product in the SeAT SDE.');

            $minerals = [];
            $other = [];
            $components = [];
            $stack = [];
            $this->expandBlueprint(
                (int) $blueprint->typeID,
                (int) $data['runs'],
                (int) $data['product_me'],
                (int) $data['component_me'],
                $minerals,
                $other,
                $components,
                $stack,
                0
            );

            return response()->json([
                'product' => $product->typeName,
                'runs' => (int) $data['runs'],
                'product_me' => (int) $data['product_me'],
                'component_me' => (int) $data['component_me'],
                'minerals' => $this->materialRows($minerals),
                'other_materials' => $this->materialRows($other),
                'component_builds' => $this->materialRows($components),
                'note' => 'The builder recursively expands every required material that has a manufacturing blueprint in the loaded SeAT SDE. Component quantities are consolidated by product type; minerals and non-manufacturable inputs are consolidated separately. Blueprint ME is applied at each manufacturing level.',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    private function expandBlueprint(
        int $blueprintTypeId,
        int $runs,
        int $me,
        int $componentMe,
        array &$minerals,
        array &$other,
        array &$components,
        array &$stack,
        int $depth
    ): void {
        if ($depth > 20) throw new RuntimeException('Blueprint expansion exceeded 20 levels; check the SDE data.');
        if (isset($stack[$blueprintTypeId])) throw new RuntimeException('A recursive blueprint cycle was found in the SDE.');
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
            if ($quantity <= 0) continue;

            if (in_array($materialTypeId, self::MINERAL_TYPE_IDS, true)) {
                $minerals[$materialTypeId] = ($minerals[$materialTypeId] ?? 0) + $quantity;
                continue;
            }

            $componentBlueprint = DB::table('industryActivityProducts')
                ->where('activityID', self::ACTIVITY_MANUFACTURING)
                ->where('productTypeID', $materialTypeId)
                ->orderBy('typeID')->first();

            if ($componentBlueprint) {
                $components[$materialTypeId] = ($components[$materialTypeId] ?? 0) + $quantity;
                $this->expandBlueprint(
                    (int) $componentBlueprint->typeID,
                    $quantity,
                    $componentMe,
                    $componentMe,
                    $minerals,
                    $other,
                    $components,
                    $stack,
                    $depth + 1
                );
            } else {
                $other[$materialTypeId] = ($other[$materialTypeId] ?? 0) + $quantity;
            }
        }
        unset($stack[$blueprintTypeId]);
    }

    private function materialRows(array $materials): array
    {
        $ids = array_keys($materials);
        $names = $ids ? DB::table('invTypes')->whereIn('typeID', $ids)->pluck('typeName', 'typeID')->all() : [];
        $rows = [];
        foreach ($materials as $typeId => $quantity) {
            $rows[] = ['type_id' => (int) $typeId, 'name' => $names[$typeId] ?? ('Type ' . $typeId), 'quantity' => (int) $quantity];
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $rows;
    }

    private function sdeReady(): bool
    {
        return Schema::hasTable('invTypes')
            && Schema::hasTable('industryActivityProducts')
            && Schema::hasTable('industryActivityMaterials');
    }
}
