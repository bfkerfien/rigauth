<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ExplorationController extends Controller
{
    private const REGIONS = ['Branch', 'Tenal', 'Venal'];

    // Add the user's permanent exclusions here when their system list is supplied.
    // These systems are rendered red and cannot be changed or removed by map reset.
    private const PERMANENT_RED_SYSTEMS = [
        'Branch' => [],
        'Tenal' => [],
        'Venal' => [],
    ];

    public function index(): View
    {
        $maps = [];
        $mapError = null;
        $requiredTables = ['mapSolarSystems', 'mapRegions'];

        foreach ($requiredTables as $table) {
            if (!Schema::hasTable($table)) {
                $mapError = 'The EVE Static Data map tables are not installed. Import the SeAT SDE before using Exploration.';
                break;
            }
        }

        foreach (self::REGIONS as $region) {
            $maps[$region] = $mapError ? ['systems' => [], 'links' => [], 'green_count' => 0] : $this->buildMap($region);
        }

        return view('web::exploration.index', compact('maps', 'mapError'));
    }

    private function buildMap(string $region): array
    {
        $systems = DB::table('mapSolarSystems as systems')
            ->join('mapRegions as regions', 'regions.regionID', '=', 'systems.regionID')
            ->where('regions.regionName', $region)
            ->select([
                'systems.solarSystemID as id',
                'systems.solarSystemName as name',
                'systems.x',
                'systems.y',
            ])
            ->orderBy('systems.solarSystemName')
            ->get();

        $greenIds = Schema::hasTable('tc_exploration_system_status')
            ? DB::table('tc_exploration_system_status')
                ->where('region_name', $region)
                ->pluck('solar_system_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $redNames = array_map('mb_strtolower', self::PERMANENT_RED_SYSTEMS[$region] ?? []);
        $minX = (float) $systems->min('x');
        $maxX = (float) $systems->max('x');
        $minY = (float) $systems->min('y');
        $maxY = (float) $systems->max('y');
        $rangeX = max(1.0, $maxX - $minX);
        $rangeY = max(1.0, $maxY - $minY);
        $width = 1200.0;
        $height = 800.0;
        $padding = 58.0;

        $mapped = $systems->map(function ($system) use ($greenIds, $redNames, $minX, $minY, $rangeX, $rangeY, $width, $height, $padding) {
            $name = (string) $system->name;
            $isRed = in_array(mb_strtolower($name), $redNames, true);
            $isGreen = in_array((int) $system->id, $greenIds, true) && !$isRed;

            return [
                'id' => (int) $system->id,
                'name' => $name,
                'x' => $padding + (((float) $system->x - $minX) / $rangeX) * ($width - ($padding * 2)),
                'y' => $height - $padding - (((float) $system->y - $minY) / $rangeY) * ($height - ($padding * 2)),
                'status' => $isRed ? 'red' : ($isGreen ? 'green' : 'white'),
            ];
        })->values();

        $links = [];
        if (Schema::hasTable('mapSolarSystemJumps') && $systems->isNotEmpty()) {
            $ids = $systems->pluck('id')->map(fn ($id) => (int) $id)->all();
            $positions = $mapped->keyBy('id');
            $links = DB::table('mapSolarSystemJumps')
                ->whereIn('fromSolarSystemID', $ids)
                ->whereIn('toSolarSystemID', $ids)
                ->get(['fromSolarSystemID', 'toSolarSystemID'])
                ->map(function ($jump) use ($positions) {
                    $from = $positions->get((int) $jump->fromSolarSystemID);
                    $to = $positions->get((int) $jump->toSolarSystemID);

                    if (!$from || !$to || $from['id'] >= $to['id']) {
                        return null;
                    }

                    return ['x1' => $from['x'], 'y1' => $from['y'], 'x2' => $to['x'], 'y2' => $to['y']];
                })
                ->filter()
                ->values()
                ->all();
        }

        return [
            'systems' => $mapped->all(),
            'links' => $links,
            'green_count' => $mapped->where('status', 'green')->count(),
        ];
    }

    public function toggle(Request $request, string $region, int $systemId): JsonResponse
    {
        abort_unless(in_array($region, self::REGIONS, true), 404);

        $systemName = DB::table('mapSolarSystems as systems')
            ->join('mapRegions as regions', 'regions.regionID', '=', 'systems.regionID')
            ->where('regions.regionName', $region)
            ->where('systems.solarSystemID', $systemId)
            ->value('systems.solarSystemName');

        abort_unless($systemName, 404);

        $redNames = array_map('mb_strtolower', self::PERMANENT_RED_SYSTEMS[$region] ?? []);
        if (in_array(mb_strtolower((string) $systemName), $redNames, true)) {
            return response()->json(['message' => 'This system is permanently marked red.'], 403);
        }

        $query = DB::table('tc_exploration_system_status')
            ->where('region_name', $region)
            ->where('solar_system_id', $systemId);

        if ($query->exists()) {
            $query->delete();
            $status = 'white';
        } else {
            DB::table('tc_exploration_system_status')->insert([
                'region_name' => $region,
                'solar_system_id' => $systemId,
                'system_name' => $systemName,
                'marked_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $status = 'green';
        }

        return response()->json(['status' => $status, 'system' => $systemName]);
    }

    public function reset(string $region): JsonResponse
    {
        abort_unless(in_array($region, self::REGIONS, true), 404);

        $removed = DB::table('tc_exploration_system_status')
            ->where('region_name', $region)
            ->delete();

        return response()->json(['status' => 'reset', 'removed' => $removed]);
    }
}
