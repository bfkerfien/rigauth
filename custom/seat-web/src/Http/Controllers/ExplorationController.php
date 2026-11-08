<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use EveSeat\Eseye\Containers\EsiAuthentication;
use EveSeat\Eseye\Eseye;
use Seat\Eveapi\Models\RefreshToken;
use Throwable;

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
        $requiredTables = ['mapDenormalize', 'invNames'];

        foreach ($requiredTables as $table) {
            if (!Schema::hasTable($table)) {
                $mapError = 'The SeAT SDE tables mapDenormalize and invNames are missing. Run the SeAT SDE import before using Exploration.';
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
        $systems = DB::table('mapDenormalize as systems')
            ->join('invNames as regions', 'regions.itemID', '=', 'systems.regionID')
            ->where('systems.groupID', 5)
            ->where('regions.itemName', $region)
            ->select([
                'systems.itemID as id',
                'systems.itemName as name',
                'systems.x',
                'systems.z',
            ])
            ->orderBy('systems.itemName')
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
        $minY = (float) $systems->min('z');
        $maxY = (float) $systems->max('z');
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
                'y' => $height - $padding - (((float) $system->z - $minY) / $rangeY) * ($height - ($padding * 2)),
                'status' => $isRed ? 'red' : ($isGreen ? 'green' : 'white'),
            ];
        })->values();

        // Stargate rows use itemName = "Stargate (Destination System)" and
        // solarSystemID = the system containing that gate. Resolve destinations by system name.
        $links = [];
        if ($systems->isNotEmpty()) {
            $ids = $systems->pluck('id')->map(fn ($id) => (int) $id)->all();
            $positions = $mapped->keyBy('id');
            $systemIdsByName = $mapped->keyBy(fn ($system) => mb_strtolower($system['name']));
            $gates = DB::table('mapDenormalize')
                ->where('groupID', 10)
                ->whereIn('solarSystemID', $ids)
                ->get(['solarSystemID', 'itemName']);

            $seenLinks = [];
            foreach ($gates as $gate) {
                if (!preg_match('/^Stargate \\((.+)\\)$/u', (string) $gate->itemName, $matches)) {
                    continue;
                }

                $fromId = (int) $gate->solarSystemID;
                $to = $systemIdsByName->get(mb_strtolower(trim($matches[1])));
                if (!$to || $fromId === (int) $to['id']) {
                    continue;
                }

                $key = min($fromId, (int) $to['id']) . ':' . max($fromId, (int) $to['id']);
                if (isset($seenLinks[$key])) {
                    continue;
                }
                $seenLinks[$key] = true;

                $from = $positions->get($fromId);
                if (!$from) {
                    continue;
                }

                $links[] = [
                    'x1' => $from['x'], 'y1' => $from['y'],
                    'x2' => $to['x'], 'y2' => $to['y'],
                ];
            }
        }

        return [
            'systems' => $mapped->all(),
            'links' => $links,
            'green_count' => $mapped->where('status', 'green')->count(),
        ];
    }

    /**
     * Return the authenticated user's main character location for the live map overlay.
     * Location is fetched on demand and is never persisted by this feature.
     */
    public function playerLocation(): JsonResponse
    {
        $user = request()->user();
        $characterId = (int) ($user->main_character_id ?? 0);

        if ($characterId < 1) {
            return response()->json([
                'status' => 'not_configured',
                'message' => 'No main character is selected in SeAT.',
            ])->header('Cache-Control', 'no-store');
        }

        $token = RefreshToken::find($characterId);
        if (!$token) {
            return response()->json([
                'status' => 'not_configured',
                'message' => 'No SeAT ESI token is available for your main character.',
            ])->header('Cache-Control', 'no-store');
        }

        $scopes = $token->scopes;
        if (is_string($scopes)) {
            $decoded = json_decode($scopes, true);
            $scopes = is_array($decoded) ? $decoded : preg_split('/\\s+/', trim($scopes));
        }
        $scopes = is_array($scopes) ? $scopes : [];

        if (!in_array('esi-location.read_location.v1', $scopes, true)) {
            return response()->json([
                'status' => 'needs_authorization',
                'message' => 'Authorize esi-location.read_location.v1 for your main character in SeAT.',
            ])->header('Cache-Control', 'no-store');
        }

        try {
            $esi = new Eseye(new EsiAuthentication([
                'client_id' => config('eve.client_id'),
                'secret' => config('eve.client_secret'),
                'refresh_token' => $token->refresh_token,
                'token' => $token->token,
                'scopes' => $scopes,
            ]));

            $response = $esi->invoke('get', '/characters/{character_id}/location/', [
                'character_id' => $characterId,
            ]);
            $location = $response->getBody();
            $systemId = (int) ($location->solar_system_id ?? 0);

            if ($systemId < 1) {
                return response()->json([
                    'status' => 'unavailable',
                    'message' => 'EVE Online has not returned a current solar-system location.',
                ])->header('Cache-Control', 'no-store');
            }

            return response()->json([
                'status' => 'located',
                'character_id' => $characterId,
                'character_name' => $user->name ?? 'Main character',
                'solar_system_id' => $systemId,
            ])->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'unavailable',
                'message' => 'Live location is temporarily unavailable. Check the character ESI authorization and try again.',
            ])->header('Cache-Control', 'no-store');
        }
    }

    public function toggle(Request $request, string $region, int $systemId): JsonResponse
    {
        abort_unless(in_array($region, self::REGIONS, true), 404);

        $systemName = DB::table('mapDenormalize as systems')
            ->join('invNames as regions', 'regions.itemID', '=', 'systems.regionID')
            ->where('systems.groupID', 5)
            ->where('regions.itemName', $region)
            ->where('systems.itemID', $systemId)
            ->value('systems.itemName');

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
