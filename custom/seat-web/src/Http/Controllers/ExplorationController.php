<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Seat\Services\Contracts\EsiClient;
use Seat\Eveapi\Models\RefreshToken;
use Throwable;

class ExplorationController extends Controller
{
    private const REGIONS = ['Branch', 'Tenal', 'Venal'];

    // Synergy of Steel [SYN] alliance ID. Red systems are based on alliance sovereignty claims.
    private const SOV_ALLIANCE_ID = 99002685;

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

    /**
     * Build a native Exploration map from EVE SDE system coordinates and stargate connections.
     * Every system is rendered as its own SVG node with its SeAT solar_system_id.
     */
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

        $redIds = $this->synergyOfSteelSovereigntySystemIds();
        $mapped = $systems->map(function ($system) use ($greenIds, $redIds) {
            $id = (int) $system->id;
            $isRed = in_array($id, $redIds, true);
            $isGreen = in_array($id, $greenIds, true) && !$isRed;

            return [
                'id' => $id,
                'name' => (string) $system->name,
                'x' => (float) $system->x,
                'z' => (float) $system->z,
                'status' => $isRed ? 'red' : ($isGreen ? 'green' : 'white'),
            ];
        })->values();

        $svg = $this->renderNativeExplorationMap($region, $mapped);

        return [
            'systems' => $mapped->map(fn ($system) => [
                'id' => $system['id'],
                'name' => $system['name'],
                'status' => $system['status'],
            ])->all(),
            'svg' => $svg,
            'green_count' => $mapped->where('status', 'green')->count(),
        ];
    }

    /**
     * Render a native, topology-first star map. EVE's gate graph drives the
     * composition; SDE coordinates provide a stable starting orientation.
     * The SVG is generated locally and has no DOTLAN artwork/runtime dependency.
     */
    private function renderNativeExplorationMap(string $region, $systems): string
    {
        if ($systems->isEmpty()) {
            return '';
        }

        $width = 1600.0;
        $height = 1050.0;
        $padding = 105.0;
        $systemIds = $systems->pluck('id')->map(fn ($id) => (int) $id)->all();
        $systemSet = array_fill_keys($systemIds, true);
        $minX = (float) $systems->min('x');
        $maxX = (float) $systems->max('x');
        $minZ = (float) $systems->min('z');
        $maxZ = (float) $systems->max('z');
        $rangeX = max(1.0, $maxX - $minX);
        $rangeZ = max(1.0, $maxZ - $minZ);

        // Normalize the SDE projection into a broad initial graph layout.
        $positions = [];
        $anchors = [];
        $names = [];
        $statuses = [];
        foreach ($systems as $system) {
            $id = (int) $system['id'];
            $point = [
                'x' => 180.0 + (($system['x'] - $minX) / $rangeX) * 1240.0,
                'y' => 130.0 + (1.0 - (($system['z'] - $minZ) / $rangeZ)) * 790.0,
            ];
            $positions[$id] = $point;
            $anchors[$id] = $point;
            $names[$id] = (string) $system['name'];
            $statuses[$id] = (string) $system['status'];
        }

        $edges = [];
        if (Schema::hasTable('mapSolarSystemJumps')) {
            $jumps = DB::table('mapSolarSystemJumps')
                ->whereIn('fromSolarSystemID', $systemIds)
                ->whereIn('toSolarSystemID', $systemIds)
                ->get(['fromSolarSystemID', 'toSolarSystemID']);

            foreach ($jumps as $jump) {
                $from = (int) $jump->fromSolarSystemID;
                $to = (int) $jump->toSolarSystemID;
                if ($from === $to || !isset($systemSet[$from], $systemSet[$to])) {
                    continue;
                }
                $key = min($from, $to) . ':' . max($from, $to);
                $edges[$key] = [$from, $to];
            }
        }

        // Deterministic force-directed relaxation: gate-connected systems form
        // recognizable clusters while repulsion prevents marker pile-ups.
        $ids = array_keys($positions);
        $count = count($ids);
        $iterations = 190;
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $forces = [];
            foreach ($ids as $id) {
                $forces[$id] = ['x' => 0.0, 'y' => 0.0];
            }

            for ($i = 0; $i < $count; $i++) {
                $a = $ids[$i];
                for ($j = $i + 1; $j < $count; $j++) {
                    $b = $ids[$j];
                    $dx = $positions[$a]['x'] - $positions[$b]['x'];
                    $dy = $positions[$a]['y'] - $positions[$b]['y'];
                    $distanceSquared = ($dx * $dx) + ($dy * $dy);
                    if ($distanceSquared < 0.01) {
                        $dx = (($a % 17) - 8) * 0.2;
                        $dy = (($b % 19) - 9) * 0.2;
                        $distanceSquared = max(0.01, ($dx * $dx) + ($dy * $dy));
                    }
                    $distance = sqrt($distanceSquared);
                    $push = min(22.0, 5200.0 / $distanceSquared);
                    $fx = ($dx / $distance) * $push;
                    $fy = ($dy / $distance) * $push;
                    $forces[$a]['x'] += $fx;
                    $forces[$a]['y'] += $fy;
                    $forces[$b]['x'] -= $fx;
                    $forces[$b]['y'] -= $fy;
                }
            }

            foreach ($edges as [$a, $b]) {
                $dx = $positions[$b]['x'] - $positions[$a]['x'];
                $dy = $positions[$b]['y'] - $positions[$a]['y'];
                $distance = max(0.01, sqrt(($dx * $dx) + ($dy * $dy)));
                $pull = ($distance - 88.0) * 0.018;
                $fx = ($dx / $distance) * $pull;
                $fy = ($dy / $distance) * $pull;
                $forces[$a]['x'] += $fx;
                $forces[$a]['y'] += $fy;
                $forces[$b]['x'] -= $fx;
                $forces[$b]['y'] -= $fy;
            }

            $temperature = max(0.7, 13.0 * (1.0 - ($iteration / $iterations)));
            foreach ($ids as $id) {
                // A light anchor keeps the broad regional orientation familiar.
                $forces[$id]['x'] += ($anchors[$id]['x'] - $positions[$id]['x']) * 0.003;
                $forces[$id]['y'] += ($anchors[$id]['y'] - $positions[$id]['y']) * 0.003;
                $magnitude = sqrt(($forces[$id]['x'] ** 2) + ($forces[$id]['y'] ** 2));
                if ($magnitude > 0.001) {
                    $step = min($temperature, $magnitude);
                    $positions[$id]['x'] += ($forces[$id]['x'] / $magnitude) * $step;
                    $positions[$id]['y'] += ($forces[$id]['y'] / $magnitude) * $step;
                }
            }
        }

        // Fit the relaxed graph into the viewBox without distorting its aspect.
        $xs = array_column($positions, 'x');
        $ys = array_column($positions, 'y');
        $layoutMinX = min($xs);
        $layoutMaxX = max($xs);
        $layoutMinY = min($ys);
        $layoutMaxY = max($ys);
        $layoutWidth = max(1.0, $layoutMaxX - $layoutMinX);
        $layoutHeight = max(1.0, $layoutMaxY - $layoutMinY);
        $scale = min(($width - 2 * $padding) / $layoutWidth, ($height - 2 * $padding) / $layoutHeight);
        $offsetX = ($width - ($layoutWidth * $scale)) / 2.0;
        $offsetY = ($height - ($layoutHeight * $scale)) / 2.0;
        foreach ($positions as $id => $point) {
            $positions[$id] = [
                'x' => $offsetX + (($point['x'] - $layoutMinX) * $scale),
                'y' => $offsetY + (($point['y'] - $layoutMinY) * $scale),
            ];
        }

        $safeRegion = htmlspecialchars($region, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" class="exploration-map" viewBox="0 0 1600 1050" role="img" aria-label="Native EVE star map of ' . $safeRegion . '">';
        $svg .= '<defs>
            <radialGradient id="exploration-space"><stop offset="0" stop-color="#101c2b"/><stop offset="1" stop-color="#050a12"/></radialGradient>
            <pattern id="exploration-grid" width="80" height="80" patternUnits="userSpaceOnUse"><path d="M 80 0 L 0 0 0 80" fill="none" stroke="#172536" stroke-width="1" opacity=".36"/></pattern>
            <filter id="exploration-glow" x="-100%" y="-100%" width="300%" height="300%"><feGaussianBlur stdDeviation="3" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
        </defs>';
        $svg .= '<rect width="1600" height="1050" fill="url(#exploration-space)"/><rect width="1600" height="1050" fill="url(#exploration-grid)"/>';
        $svg .= '<text x="42" y="58" class="exploration-region-title">' . $safeRegion . '</text>';
        $svg .= '<text x="44" y="82" class="exploration-region-subtitle">NEW EDEN · STARGATE NETWORK</text>';

        // Gate connections are drawn first so all system labels remain legible.
        $svg .= '<g class="exploration-map-links">';
        foreach ($edges as [$from, $to]) {
            $a = $positions[$from];
            $b = $positions[$to];
            $svg .= '<line x1="' . round($a['x'], 2) . '" y1="' . round($a['y'], 2)
                . '" x2="' . round($b['x'], 2) . '" y2="' . round($b['y'], 2) . '"/>';
        }
        $svg .= '</g>';

        foreach ($ids as $id) {
            $point = $positions[$id];
            $name = htmlspecialchars($names[$id], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $status = $statuses[$id];
            $label = $name . ': ' . ($status === 'red' ? 'permanently excluded' : ($status === 'green' ? 'explored' : 'unexplored'));
            $svg .= '<g class="exploration-system is-' . $status . '" data-system-id="' . $id
                . '" data-system-name="' . $name . '" data-status="' . $status . '" transform="translate('
                . round($point['x'], 2) . ' ' . round($point['y'], 2) . ')" tabindex="' . ($status === 'red' ? '-1' : '0')
                . '" role="' . ($status === 'red' ? 'img' : 'button') . '" aria-label="'
                . htmlspecialchars($label, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" aria-pressed="'
                . ($status === 'green' ? 'true' : 'false') . '">';
            $svg .= '<circle class="exploration-hit" r="17"/>';
            $svg .= '<circle class="s" r="4.2"/>';
            $svg .= '<text class="ss" x="9" y="3">' . $name . '</text>';
            $svg .= '</g>';
        }

        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Fetch current sovereignty claims from CCP's post-Equinox ESI endpoint.
     * /sovereignty/map was removed at compatibility date 2026-05-19.
     */
    private function synergyOfSteelSovereigntySystemIds(): array
    {
        return Cache::remember('tc_exploration_syn_sov_system_ids_v4', now()->addMinutes(15), function () {
            $response = Http::acceptJson()
                ->withHeaders(['X-Compatibility-Date' => '2026-08-18'])
                ->timeout(25)
                ->retry(2, 500)
                ->get('https://esi.evetech.net/sovereignty/systems', [
                    'datasource' => 'tranquility',
                ]);

            if (!$response->successful() || !is_array($response->json())) {
                logger()->error('Exploration sovereignty ESI request failed.', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 1000),
                ]);
                return Cache::get('tc_exploration_syn_sov_system_ids_v4_stale', []);
            }

            $payload = $response->json();
            $claims = $payload['solar_systems'] ?? $payload;
            if (!is_array($claims)) {
                logger()->error('Exploration sovereignty ESI returned an unexpected payload.');
                return Cache::get('tc_exploration_syn_sov_system_ids_v3_stale', []);
            }

            $ids = collect($claims)
                ->filter(function ($entry) {
                    // ESI sovereignty payloads have appeared in both direct
                    // alliance_id form and wrapped claim.alliance form.
                    $allianceId = $entry['alliance_id']
                        ?? $entry['claim']['alliance']['alliance_id']
                        ?? 0;
                    return (int) $allianceId === self::SOV_ALLIANCE_ID;
                })
                ->pluck('solar_system_id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();

            Cache::put('tc_exploration_syn_sov_system_ids_v4_stale', $ids, now()->addDays(7));
            return $ids;
        });
    }

    /**
     * Return the authenticated user's main character location for the live map overlay.
     * Location is fetched on demand and is never persisted by this feature.
     */
    public function playerLocation(): JsonResponse
    {
        $user = request()->user();

        // SeAT exposes the selected character through the main_character relationship.
        // Do not rely only on main_character_id: that accessor is not consistently
        // populated on every SeAT user model/version.
        $mainCharacter = $user->main_character ?? null;
        $characterId = (int) (
            $mainCharacter->character_id
            ?? $mainCharacter->id
            ?? $user->main_character_id
            ?? 0
        );

        if ($characterId < 1) {
            return response()->json([
                'status' => 'not_configured',
                'message' => 'Select a main character in your SeAT profile to show its live location.',
            ])->header('Cache-Control', 'no-store');
        }

        $token = RefreshToken::query()->where('character_id', $characterId)->first();
        if (!$token) {
            return response()->json([
                'status' => 'not_configured',
                'message' => 'The selected main character has no ESI refresh token in SeAT. Re-authorize that character.',
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
                'message' => 'The selected character is authenticated, but its SeAT token does not include esi-location.read_location.v1. Re-authorize it with that ESI scope.',
            ])->header('Cache-Control', 'no-store');
        }

        try {
            // Use SeAT's configured ESI client so its Guzzle HTTP client,
            // request factories, logger, cache, and ESI configuration are initialized.
            // RefreshToken implements SeAT's EsiToken contract directly.
            $esi = app(EsiClient::class);
            $esi->setAuthentication($token);

            $response = $esi->invoke('get', '/characters/{character_id}/location/', [
                'character_id' => $characterId,
            ]);

            if ($response->isFailed()) {
                return response()->json([
                    'status' => 'unavailable',
                    'message' => 'ESI location request failed (' . $response->getStatusCode() . '): ' . mb_substr($response->error(), 0, 250),
                ])->header('Cache-Control', 'no-store');
            }

            $location = $response->getBody();
            $systemId = (int) ($location->solar_system_id ?? 0);

            if ($systemId < 1) {
                return response()->json([
                    'status' => 'unavailable',
                    'message' => 'ESI responded, but did not include a solar_system_id for this character.',
                ])->header('Cache-Control', 'no-store');
            }

            return response()->json([
                'status' => 'located',
                'character_id' => $characterId,
                'character_name' => $mainCharacter->name ?? $user->name ?? 'Main character',
                'solar_system_id' => $systemId,
            ])->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            report($exception);
            logger()->warning('Exploration live character location failed.', [
                'user_id' => $user->id ?? null,
                'character_id' => $characterId,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => 'unavailable',
                'message' => 'ESI location lookup failed: ' . get_class($exception) . ': ' . mb_substr($exception->getMessage(), 0, 300),
            ])->header('Cache-Control', 'no-store');
        }
    }

    private function explorationSystem(string $region, int $systemId): ?string
    {
        abort_unless(in_array($region, self::REGIONS, true), 404);
        return DB::table('mapDenormalize as systems')
            ->join('invNames as regions', 'regions.itemID', '=', 'systems.regionID')
            ->where('systems.groupID', 5)->where('regions.itemName', $region)
            ->where('systems.itemID', $systemId)->value('systems.itemName');
    }

    public function signatures(string $region, int $systemId): JsonResponse
    {
        $systemName = $this->explorationSystem($region, $systemId);
        abort_unless($systemName, 404);
        $signatures = DB::table('tc_exploration_signatures')
            ->where('solar_system_id', $systemId)->orderBy('signature_id')
            ->get(['signature_id', 'group_name', 'type_name', 'signature_name', 'signal_strength', 'scanned_at']);
        return response()->json(['system' => $systemName, 'signatures' => $signatures, 'scanned_at' => $signatures->max('scanned_at')]);
    }

    public function saveSignatures(Request $request, string $region, int $systemId): JsonResponse
    {
        $systemName = $this->explorationSystem($region, $systemId);
        abort_unless($systemName, 404);
        $validated = $request->validate(['scan_data' => ['required', 'string', 'max:100000']]);
        $lines = preg_split('/\\r\\n|\\r|\\n/', trim($validated['scan_data']));
        $rows = array_values(array_filter(array_map(fn ($line) => str_getcsv($line, "\t"), $lines),
            fn ($row) => count($row) > 1 || trim((string) ($row[0] ?? '')) !== ''));
        if (count($rows) < 2) {
            return response()->json(['message' => 'No scan rows found. Copy the Probe Scanner results, including column headings.'], 422);
        }

        $normalize = fn ($value) => mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)));
        $headers = array_map($normalize, $rows[0]);
        $find = function (array $names) use ($headers) {
            foreach ($names as $name) { $index = array_search($name, $headers, true); if ($index !== false) return $index; }
            return null;
        };
        $idCol = $find(['id', 'signatureid', 'scanid']);
        $groupCol = $find(['group', 'groupname', 'category']);
        $typeCol = $find(['type', 'typename']);
        $nameCol = $find(['name', 'signature', 'signaturename']);
        $strengthCol = $find(['signalstrength', 'strength', 'certainty']);

        // EVE Probe Scanner clipboard order: ID, Group, Type, Name, Signal Strength.
        if ($idCol === null || $strengthCol === null) {
            [$idCol, $groupCol, $typeCol, $nameCol, $strengthCol] = [0, 1, 2, 3, 4];
            $dataRows = $rows;
        } else {
            $dataRows = array_slice($rows, 1);
        }

        $parsed = [];
        foreach ($dataRows as $row) {
            $sigId = strtoupper(trim((string) ($row[$idCol] ?? '')));
            if (!preg_match('/^[A-Z0-9]{3}-[A-Z0-9]{3}$/', $sigId)) continue;
            $strength = str_replace('%', '', trim((string) ($row[$strengthCol] ?? '')));
            if ($strength === '' || !is_numeric($strength)) continue;
            $parsed[$sigId] = [
                'region_name' => $region, 'solar_system_id' => $systemId, 'system_name' => $systemName,
                'signature_id' => $sigId,
                'group_name' => trim((string) ($row[$groupCol] ?? '')),
                'type_name' => trim((string) ($row[$typeCol] ?? '')),
                'signature_name' => trim((string) ($row[$nameCol] ?? '')),
                'signal_strength' => max(0, min(100, (float) $strength)),
                'raw_data' => implode("\t", $row), 'scanned_by' => $request->user()->id,
                'scanned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        if (!$parsed) {
            return response()->json(['message' => 'No valid cosmic signatures found. The previous scan was left unchanged. Check that rows include signature IDs and signal strengths.'], 422);
        }

        DB::transaction(function () use ($systemId, $parsed) {
            // New scan submissions replace the complete previous list for this system.
            DB::table('tc_exploration_signatures')->where('solar_system_id', $systemId)->delete();
            DB::table('tc_exploration_signatures')->insert(array_values($parsed));
        });
        return response()->json(['status' => 'saved', 'system' => $systemName, 'count' => count($parsed)]);
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

        if (in_array($systemId, $this->synergyOfSteelSovereigntySystemIds(), true)) {
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
