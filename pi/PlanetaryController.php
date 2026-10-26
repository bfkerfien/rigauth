<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PlanetaryController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(auth()->check(), 401);

        $recipes = [];
        $recipeError = null;
        $required = ['planetSchematics', 'planetSchematicsTypeMap', 'invTypes', 'invGroups'];
        foreach ($required as $table) {
            if (!Schema::hasTable($table)) {
                $recipeError = 'Planetary schematic data is not available in this SeAT database yet. The PI tabs are installed, but recipe rankings need the EVE SDE planetary schematic tables.';
                break;
            }
        }

        if ($recipeError === null) {
            try {
                $schematics = DB::table('planetSchematics as s')
                    ->join('planetSchematicsTypeMap as output', function ($join) {
                        $join->on('output.schematicID', '=', 's.schematicID')->where('output.isInput', '=', 0);
                    })
                    ->join('invTypes as ot', 'ot.typeID', '=', 'output.typeID')
                    ->leftJoin('invGroups as og', 'og.groupID', '=', 'ot.groupID')
                    ->select(['s.schematicID', 's.schematicName', 's.cycleTime', 'output.typeID as outputTypeID', 'output.quantity as outputQuantity', 'ot.typeName as outputName', 'og.groupName as outputGroup'])
                    ->orderBy('s.schematicName')->get();

                $allTypes = DB::table('planetSchematicsTypeMap as m')
                    ->join('invTypes as t', 't.typeID', '=', 'm.typeID')
                    ->select(['m.schematicID', 'm.typeID', 'm.quantity', 'm.isInput', 't.typeName'])
                    ->orderBy('m.schematicID')->get()->groupBy('schematicID');

                foreach ($schematics as $row) {
                    $tier = $this->tierFor((string) ($row->outputGroup ?? ''), (string) $row->outputName);
                    if (!in_array($tier, ['P2', 'P3', 'P4'], true)) continue;
                    $materials = [];
                    foreach ($allTypes->get($row->schematicID, collect()) as $part) {
                        if ((int) $part->isInput !== 1) continue;
                        $materials[] = ['name' => $part->typeName, 'typeId' => (int) $part->typeID, 'quantity' => (int) $part->quantity];
                    }
                    if (!$materials) continue;
                    $recipes[] = [
                        'id' => (int) $row->schematicID,
                        'name' => (string) $row->outputName,
                        'tier' => $tier,
                        'schematic' => (string) $row->schematicName,
                        'cycleSeconds' => (int) ($row->cycleTime ?? 3600),
                        'output' => (int) $row->outputQuantity,
                        'outputTypeId' => (int) $row->outputTypeID,
                        'materials' => $materials,
                    ];
                }
                if (!$recipes) $recipeError = 'No P2/P3/P4 recipes were found in the local planetary schematic tables. Verify the installed EVE SDE version and table names.';
            } catch (\Throwable $e) {
                report($e);
                $recipeError = 'The local planetary schematic data could not be read. Check the SeAT database schema and application logs.';
            }
        }

        return view('web::planetary.index', [
            'recipes' => $recipes,
            'recipeJson' => json_encode($recipes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
            'recipeError' => $recipeError,
        ]);
    }

    private function tierFor(string $group, string $name): string
    {
        $value = mb_strtolower($group . ' ' . $name);
        if (str_contains($value, 'advanced commodity') || str_contains($value, 'advanced commodities')) return 'P4';
        if (str_contains($value, 'specialized commodity') || str_contains($value, 'specialized commodities')) return 'P3';
        if (str_contains($value, 'refined commodity') || str_contains($value, 'refined commodities')) return 'P2';
        if (preg_match('/\b(p[234])\b/i', $group, $match)) return strtoupper($match[1]);
        return '';
    }
}
