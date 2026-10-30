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

    public function index(): View
    {
        return view('web::cookbook.index', [
            'sdeReady' => $this->sdeReady(),
            'characterCount' => $this->characters()->count(),
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

            $mineralIds = array_keys($minerals);
            $names = $mineralIds
                ? DB::table('invTypes')->whereIn('typeID', $mineralIds)->pluck('typeName', 'typeID')->all()
                : [];

            $mineralRows = [];
            foreach ($minerals as $typeId => $quantity) {
                $mineralRows[] = [
                    'type_id' => (int) $typeId,
                    'name' => $names[$typeId] ?? ('Type ' . $typeId),
                    'quantity' => (int) $quantity,
                ];
            }
            usort($mineralRows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            $otherIds = array_keys($other);
            $otherNames = $otherIds
                ? DB::table('invTypes')->whereIn('typeID', $otherIds)->pluck('typeName', 'typeID')->all()
                : [];
            $otherRows = [];
            foreach ($other as $typeId => $quantity) {
                $otherRows[] = [
                    'type_id' => (int) $typeId,
                    'name' => $otherNames[$typeId] ?? ('Type ' . $typeId),
                    'quantity' => (int) $quantity,
                ];
            }
            usort($otherRows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            return response()->json([
                'product' => $product->typeName,
                'runs' => (int) $data['runs'],
                'product_me' => (int) $data['product_me'],
                'component_me' => (int) $data['component_me'],
                'minerals' => $mineralRows,
                'other_materials' => $otherRows,
                'note' => 'Quantities are calculated from the SDE currently loaded in SeAT. Non-mineral inputs are listed separately; this planner does not claim to price or source them.',
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
        return $user && method_exists($user, 'characters')
            ? $user->characters
            : collect();
    }
}
