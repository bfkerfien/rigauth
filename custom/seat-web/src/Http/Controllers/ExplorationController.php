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

    // Tenal constellations and Branch systems that must always render red.
    private const TENAL_RED_CONSTELLATIONS = ['1D-65L', 'O-E019', 'E-72A3', '9P57-V', '2-ZRM0'];
    private const BRANCH_RED_SYSTEMS = ['3KNA-N', 'ME-4IU', 'BU-IU4', '1-7JR4', 'QYZM-W', 'CH9L-K', '9-B1DS'];
    // Branch systems highlighted in light grey with a teal oval; they remain fully interactive.
    private const BRANCH_TEAL_SYSTEMS = ['KJ-QWL', 'SBB-RE', 'KMQ4-V', '5-P1Y2', 'J52-BH', 'C-LBQS', 'BWI1-9', 'KL3O-J', 'X4UV-Z', 'JRZ-B9', 'V8W-QS', 'OJ-A8M', 'R4O-I6', 'Q-NJZ4', 'NLPB-0', '3-TD6L', 'CX-1XF', 'SVB-RE'];

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

        $redIds = $this->redSystemIds($region);
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
        $layoutFile = __DIR__ . '/../../resources/exploration/layouts.php';
        if (!is_file($layoutFile)) {
            logger()->error('Exploration native layout asset is missing.', ['path' => $layoutFile]);
            return '';
        }

        $layouts = require $layoutFile;
        $layout = $layouts[$region] ?? null;
        if (!is_array($layout) || empty($layout['nodes'])) {
            logger()->error('Exploration native layout is invalid.', ['region' => $region]);
            return '';
        }

        // Preserve the exact DOTLAN-authored 1024x768 node and gate coordinates.
        // Only the visual rendering and interactive SeAT state are native.
        $liveSystems = $systems->keyBy(fn ($system) => (int) $system['id']);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" class="exploration-map" viewBox="0 0 1024 768" role="img" aria-label="Native exploration map of ' . htmlspecialchars($region, ENT_QUOTES | ENT_XML1, 'UTF-8') . '">';
        $svg .= '<defs>
            <radialGradient id="exploration-space" cx="50%" cy="48%" r="75%"><stop offset="0" stop-color="#1b1b1b"/><stop offset="1" stop-color="#111111"/></radialGradient>
            <filter id="exploration-glow" x="-80%" y="-80%" width="260%" height="260%"><feGaussianBlur stdDeviation="2.2" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
        </defs>';
        $svg .= '<rect x="0" y="0" width="1024" height="768" fill="url(#exploration-space)"/>';
        $svg .= '<g class="exploration-map-links">';
        foreach (($layout['edges'] ?? []) as $edge) {
            [$from, $to, $x1, $y1, $x2, $y2, $class] = $edge;
            $class = in_array($class, ['j', 'jc', 'jr', 'jx', 'jxc', 'jxr', 'jb'], true) ? $class : 'j';
            $stroke = $class === 'jc' || $class === 'jxc' ? '#dd0000' : ($class === 'jr' || $class === 'jxr' ? '#800080' : '#bbbbbb');
            $strokeWidth = in_array($class, ['jx', 'jxc', 'jxr', 'jb'], true) ? '2.5' : '1';
            $svg .= '<line class="' . $class . '" x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" style="stroke:' . $stroke . ';stroke-width:' . $strokeWidth . ';fill:none;opacity:.9"/>';
        }
        $svg .= '</g>';

        foreach (($layout['nodes'] ?? []) as $node) {
            [$id, $name, $x, $y, $fill, $rect, $extra] = $node;
            $id = (int) $id;
            [$rx, $ry, $rw, $rh] = $rect;
            $name = (string) $name;
            $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $system = $liveSystems->get($id);

            if (!$system) {
                // DOTLAN's adjacent-region and out-of-region systems remain visible
                // as context, but are not clickable or status-bearing.
                $svg .= '<g class="exploration-external-system" transform="translate(' . $x . ' ' . $y . ')">';
                $svg .= '<rect x="' . $rx . '" y="' . $ry . '" width="' . $rw . '" height="' . $rh . '" rx="11" fill="#252525" stroke="#777" stroke-width=".8"/>';
                $svg .= '<text class="exploration-external-label" style="font:9px Arial,sans-serif;fill:#ddd" x="' . ($rx + $rw / 2) . '" y="' . ($ry + 10.5) . '" text-anchor="middle">' . $safeName . '</text>';
                if ($extra !== '') {
                    $svg .= '<text class="exploration-external-region" style="font:7px Arial,sans-serif;fill:#aaa" x="' . ($rx + $rw / 2) . '" y="' . ($ry + 18.5) . '" text-anchor="middle">' . htmlspecialchars((string) $extra, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</text>';
                }
                $svg .= '</g>';
                continue;
            }

            $status = (string) $system['status'];
            $isBranchTeal = $region === 'Branch' && in_array($name, self::BRANCH_TEAL_SYSTEMS, true);
            $nodeClass = 'exploration-system is-' . $status . ($isBranchTeal ? ' is-branch-teal' : '');
            $label = $name . ': ' . ($status === 'red' ? 'permanently excluded' : ($status === 'green' ? 'explored' : 'unexplored'));
            if ($isBranchTeal) {
                $label .= ', teal highlighted system';
            }
            $svg .= '<g class="' . $nodeClass . '" data-system-id="' . $id . '" data-system-name="' . $safeName . '" data-status="' . $status . '" transform="translate(' . $x . ' ' . $y . ')" tabindex="' . ($status === 'red' ? '-1' : '0') . '" role="' . ($status === 'red' ? 'img' : 'button') . '" aria-label="' . htmlspecialchars($label, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" aria-pressed="' . ($status === 'green' ? 'true' : 'false') . '">';
            if ($isBranchTeal) {
                $svg .= '<ellipse class="exploration-branch-teal-oval" cx="' . ($rx + $rw / 2) . '" cy="' . ($ry + $rh / 2) . '" rx="' . ($rw / 2 + 7) . '" ry="' . ($rh / 2 + 6) . '"/>';
            }
            $svg .= '<rect class="exploration-hit" x="' . $rx . '" y="' . $ry . '" width="' . $rw . '" height="' . $rh . '" rx="11"/>';
            $svg .= '<rect class="s" x="' . $rx . '" y="' . $ry . '" width="' . $rw . '" height="' . $rh . '" rx="11" style="fill:' . htmlspecialchars((string) $fill, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"/>';
            $svg .= '<text class="ss" style="text-anchor:middle;dominant-baseline:auto;font:9px Arial,sans-serif" x="' . ($rx + $rw / 2) . '" y="' . ($ry + 10.5) . '" text-anchor="middle">' . $safeName . '</text>';
            if ($extra !== '') {
                $svg .= '<text class="st" style="text-anchor:middle;font:7px Verdana,Arial,sans-serif;fill:#ddd" x="' . ($rx + $rw / 2) . '" y="' . ($ry + 18.5) . '" text-anchor="middle">' . htmlspecialchars((string) $extra, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</text>';
            }
            $svg .= '</g>';
        }

        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Systems that must remain red: Synergy of Steel sovereignty plus any
     * explicitly excluded constellations configured for the requested region.
     */
    private function redSystemIds(string $region): array
    {
        $ids = $this->synergyOfSteelSovereigntySystemIds();

        if ($region === 'Branch') {
            $regionId = DB::table('invNames')
                ->where('itemName', 'Branch')
                ->value('itemID');

            if (!$regionId) {
                logger()->error('Exploration: Branch region ID was not found in invNames.');
                return array_values(array_unique($ids));
            }

            $normalize = static fn ($value) => strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $value));
            $wanted = array_fill_keys(array_map($normalize, self::BRANCH_RED_SYSTEMS), true);
            $branchSystems = DB::table('mapDenormalize')
                ->where('groupID', 5)
                ->where('regionID', (int) $regionId)
                ->get(['itemID', 'itemName']);

            $found = $branchSystems
                ->filter(fn ($row) => isset($wanted[$normalize($row->itemName)]));
            $foundNames = $found->pluck('itemName')->map($normalize)->all();
            $missing = array_values(array_diff(array_keys($wanted), $foundNames));

            if ($missing) {
                logger()->error('Exploration: Branch red-system lookup incomplete.', [
                    'missing_normalized_systems' => $missing,
                    'region_id' => (int) $regionId,
                ]);
            }

            $ids = array_merge($ids, $found->pluck('itemID')->map(fn ($id) => (int) $id)->all());

            // Teal-highlighted Branch systems remain interactive even if sovereignty data marks them red.
            $tealIds = $branchSystems
                ->filter(fn ($row) => in_array($row->itemName, self::BRANCH_TEAL_SYSTEMS, true))
                ->pluck('itemID')
                ->map(fn ($id) => (int) $id)
                ->all();
            $ids = array_values(array_diff(array_unique($ids), $tealIds));
            return array_values(array_unique($ids));
        }

        if ($region !== 'Tenal') {
            return array_values(array_unique($ids));
        }

        $regionId = DB::table('invNames')
            ->where('itemName', 'Tenal')
            ->value('itemID');

        if (!$regionId) {
            logger()->error('Exploration: Tenal region ID was not found in invNames.');
            return array_values(array_unique($ids));
        }

        $normalize = static fn ($value) => strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $value));
        $wanted = array_fill_keys(array_map($normalize, self::TENAL_RED_CONSTELLATIONS), true);

        // SeAT's imported legacy SDE stores constellation records in mapDenormalize
        // (groupID 4), with their IDs in itemID and names in itemName.
        $constellations = DB::table('mapDenormalize')
            ->where('groupID', 4)
            ->where('regionID', (int) $regionId)
            ->get(['itemID', 'itemName']);

        $constellationIds = $constellations
            ->filter(fn ($row) => isset($wanted[$normalize($row->itemName)]))
            ->pluck('itemID')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $resolvedNames = $constellations
            ->filter(fn ($row) => isset($wanted[$normalize($row->itemName)]))
            ->pluck('itemName')
            ->map($normalize)
            ->all();
        $missing = array_values(array_diff(array_keys($wanted), $resolvedNames));

        if ($missing) {
            logger()->error('Exploration: Tenal red constellation lookup incomplete.', [
                'missing_normalized_constellations' => $missing,
                'region_id' => (int) $regionId,
            ]);
        }

        if ($constellationIds) {
            $systemIds = DB::table('mapDenormalize')
                ->where('groupID', 5)
                ->where('regionID', (int) $regionId)
                ->whereIn('constellationID', $constellationIds)
                ->pluck('itemID')
                ->map(fn ($id) => (int) $id)
                ->all();
            $ids = array_merge($ids, $systemIds);
        }

        return array_values(array_unique($ids));
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
            ->get(['signature_id', 'group_name', 'type_name', 'signature_name', 'signal_strength', 'scanned_at', 'wormhole_type', 'connection_system', 'wormhole_exits']);
        return response()->json(['system' => $systemName, 'signatures' => $signatures, 'scanned_at' => $signatures->max('scanned_at')]);
    }

    /**
     * Return every recorded wormhole across the Exploration regions.
     * The entered timestamp is the signature record creation time, not its
     * most recent scan/update time.
     */
    /**
     * List every system in the tracked regions with its most recent scan result.
     */
    public function systemScans(): JsonResponse
    {
        // Backfill the displayed last-scan value from existing signature rows
        // until a system receives its first explicit scan-history entry.
        $signatureScans = DB::table('tc_exploration_signatures')
            ->select('solar_system_id')
            ->selectRaw('MAX(scanned_at) as signature_scanned_at')
            ->selectRaw('COUNT(*) as existing_signature_count')
            ->groupBy('solar_system_id');

        // Exclude permanently red/locked systems from the scan report in every tracked region.
        $redSystemIds = collect(self::REGIONS)
            ->flatMap(fn ($region) => $this->redSystemIds($region))
            ->unique()
            ->values()
            ->all();

        $systems = DB::table('mapDenormalize as systems')
            ->join('invNames as regions', 'regions.itemID', '=', 'systems.regionID')
            ->leftJoin('tc_exploration_system_scans as scans', 'scans.solar_system_id', '=', 'systems.itemID')
            ->leftJoinSub($signatureScans, 'signature_scans', function ($join) {
                $join->on('signature_scans.solar_system_id', '=', 'systems.itemID');
            })
            ->where('systems.groupID', 5)
            ->whereIn('regions.itemName', self::REGIONS)
            ->whereNotIn('systems.itemID', $redSystemIds)
            ->orderBy('regions.itemName')
            ->orderBy('systems.itemName')
            ->get([
                'regions.itemName as region_name',
                'systems.itemID as solar_system_id',
                'systems.itemName as system_name',
                DB::raw('COALESCE(scans.scanned_at, signature_scans.signature_scanned_at) as scanned_at'),
                DB::raw('CASE WHEN scans.id IS NOT NULL THEN scans.signature_count ELSE signature_scans.existing_signature_count END as signature_count'),
                DB::raw("CASE WHEN scans.id IS NOT NULL THEN scans.scan_status ELSE 'with_signatures' END as scan_status"),
            ]);

        return response()->json(['systems' => $systems]);
    }

    /**
     * Record a scan timestamp without submitting or changing any signature entries.
     */
    public function markNoSignatures(Request $request, string $region, int $systemId): JsonResponse
    {
        abort_unless(in_array($region, self::REGIONS, true), 404);
        $systemName = $this->explorationSystem($region, $systemId);
        abort_unless($systemName, 404);

        // Timestamp-only scan: retain the currently stored signatures unchanged.
        DB::table('tc_exploration_system_scans')->updateOrInsert(
            ['solar_system_id' => $systemId],
            [
                'region_name' => $region,
                'system_name' => $systemName,
                // The column is NOT NULL; zero means this action did not submit a signature list.
                'signature_count' => 0,
                'scan_status' => 'timestamp_only',
                'scanned_by' => $request->user()->id,
                'scanned_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'timestamp_only',
            'system' => $systemName,
            'scanned_at' => now()->toDateTimeString(),
            'signature_count' => 0,
        ]);
    }

    public function allWormholes(): JsonResponse
    {
        $rows = DB::table('tc_exploration_signatures')
            ->where(function ($query) {
                $query->where('group_name', 'like', '%wormhole%')
                    ->orWhere('type_name', 'like', '%wormhole%')
                    ->orWhere('signature_name', 'like', '%wormhole%')
                    ->orWhereNotNull('wormhole_type')
                    ->orWhereNotNull('connection_system')
                    ->orWhereNotNull('wormhole_exits');
            })
            ->orderByDesc('created_at')
            ->orderBy('region_name')
            ->orderBy('system_name')
            ->orderBy('signature_id')
            ->get([
                'region_name',
                'solar_system_id',
                'system_name',
                'signature_id',
                'group_name',
                'type_name',
                'signature_name',
                'signal_strength',
                'scanned_at',
                'created_at',
                'updated_at',
                'wormhole_type',
                'connection_system',
                'wormhole_exits',
                'raw_data',
            ]);

        return response()->json(['wormholes' => $rows]);
    }

    /**
     * Delete one saved wormhole signature without disturbing other signatures
     * or the system's last-scanned timestamp.
     */
    public function deleteWormhole(string $region, int $systemId, string $signatureId): JsonResponse
    {
        $systemName = $this->explorationSystem($region, $systemId);
        abort_unless($systemName, 404);

        $signature = DB::table('tc_exploration_signatures')
            ->where('region_name', $region)
            ->where('solar_system_id', $systemId)
            ->where('signature_id', strtoupper($signatureId))
            ->where(function ($query) {
                $query->where('group_name', 'like', '%wormhole%')
                    ->orWhere('type_name', 'like', '%wormhole%')
                    ->orWhere('signature_name', 'like', '%wormhole%')
                    ->orWhereNotNull('wormhole_type')
                    ->orWhereNotNull('connection_system')
                    ->orWhereNotNull('wormhole_exits');
            });

        abort_unless($signature->exists(), 404, 'Wormhole signature not found.');

        DB::transaction(function () use ($signature, $systemId) {
            $signature->delete();
            $remaining = DB::table('tc_exploration_signatures')
                ->where('solar_system_id', $systemId)
                ->count();

            DB::table('tc_exploration_system_scans')
                ->where('solar_system_id', $systemId)
                ->update([
                    'signature_count' => $remaining,
                    'scan_status' => $remaining > 0 ? 'with_signatures' : 'timestamp_only',
                    'updated_at' => now(),
                ]);
        });

        return response()->json([
            'status' => 'deleted',
            'region' => $region,
            'system' => $systemName,
            'signature_id' => strtoupper($signatureId),
        ]);
    }

    public function saveWormholeNotes(Request $request, string $region, int $systemId, string $signatureId): JsonResponse
    {
        $systemName = $this->explorationSystem($region, $systemId);
        abort_unless($systemName, 404);

        $validated = $request->validate([
            'wormhole_type' => ['nullable', 'string', 'max:64'],
            'connection_system' => ['nullable', 'string', 'max:128'],
            'wormhole_exits' => ['nullable', 'string', 'max:10000'],
        ]);

        $updated = DB::table('tc_exploration_signatures')
            ->where('region_name', $region)
            ->where('solar_system_id', $systemId)
            ->where('signature_id', strtoupper($signatureId))
            ->update([
                'wormhole_type' => trim((string) ($validated['wormhole_type'] ?? '')) ?: null,
                'connection_system' => trim((string) ($validated['connection_system'] ?? '')) ?: null,
                'wormhole_exits' => trim((string) ($validated['wormhole_exits'] ?? '')) ?: null,
                'updated_at' => now(),
            ]);

        abort_unless($updated > 0 || DB::table('tc_exploration_signatures')
            ->where('region_name', $region)->where('solar_system_id', $systemId)
            ->where('signature_id', strtoupper($signatureId))->exists(), 404);

        return response()->json(['status' => 'saved', 'signature_id' => strtoupper($signatureId)]);
    }

    public function saveSignatures(Request $request, string $region, int $systemId): JsonResponse
    {
        $systemName = $this->explorationSystem($region, $systemId);
        abort_unless($systemName, 404);
        $validated = $request->validate([
            'scan_data' => ['required', 'string', 'max:100000'],
            'mode' => ['nullable', 'in:replace,add'],
        ]);
        $mode = $validated['mode'] ?? 'replace';
        $lines = preg_split('/\\r\\n|\\r|\\n/', trim($validated['scan_data']));
        $rows = array_values(array_filter(array_map(fn ($line) => str_getcsv($line, "\t"), $lines),
            fn ($row) => count($row) > 1 || trim((string) ($row[0] ?? '')) !== ''));
        if (count($rows) === 0) {
            return response()->json(['message' => 'The pasted scan is empty. Copy the Probe Scanner rows and paste them again.'], 422);
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

        DB::transaction(function () use ($systemId, $region, $systemName, $request, $parsed, $mode) {
            if ($mode === 'replace') {
                DB::table('tc_exploration_signatures')->where('solar_system_id', $systemId)->delete();
                DB::table('tc_exploration_signatures')->insert(array_values($parsed));
            } else {
                // Add new IDs and refresh matching IDs without erasing saved wormhole notes.
                DB::table('tc_exploration_signatures')->upsert(
                    array_values($parsed),
                    ['solar_system_id', 'signature_id'],
                    ['group_name', 'type_name', 'signature_name', 'signal_strength', 'raw_data', 'scanned_by', 'scanned_at', 'updated_at']
                );
            }

            $signatureCount = DB::table('tc_exploration_signatures')
                ->where('solar_system_id', $systemId)
                ->count();

            DB::table('tc_exploration_system_scans')->updateOrInsert(
                ['solar_system_id' => $systemId],
                [
                    'region_name' => $region,
                    'system_name' => $systemName,
                    'signature_count' => $signatureCount,
                    'scan_status' => 'with_signatures',
                    'scanned_by' => $request->user()->id,
                    'scanned_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        });
        return response()->json([
            'status' => 'saved',
            'mode' => $mode,
            'system' => $systemName,
            'count' => count($parsed),
            'total_count' => DB::table('tc_exploration_signatures')->where('solar_system_id', $systemId)->count(),
        ]);
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

        if (in_array($systemId, $this->redSystemIds($region), true)) {
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
