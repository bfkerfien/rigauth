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

        // ZXA-V6 is solar system 30003620 and belongs to Tenal (09-4XW).
        // Keep it as a real clickable Tenal node even if a local SDE region join is stale.
        if ($region === 'Tenal' && !$systems->contains(fn ($system) => (int) $system->id === 30003620)) {
            $zxa = DB::table('mapDenormalize')
                ->where('itemID', 30003620)
                ->where('groupID', 5)
                ->select(['itemID as id', 'itemName as name', 'x', 'z'])
                ->first();
            if ($zxa) $systems->push($zxa);
        }

        $greenIds = Schema::hasTable('tc_exploration_system_status')
            ? DB::table('tc_exploration_system_status')
                ->where('region_name', $region)
                ->pluck('solar_system_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $redIds = $this->synergyOfSteelSovereigntySystemIds();
        $mapped = $systems->map(function ($system) use ($greenIds, $redIds) {
            $name = (string) $system->name;
            $isRed = in_array((int) $system->id, $redIds, true);
            $isGreen = in_array((int) $system->id, $greenIds, true) && !$isRed;

            return [
                'id' => (int) $system->id,
                'name' => $name,
                'status' => $isRed ? 'red' : ($isGreen ? 'green' : 'white'),
            ];
        })->values();

        $svgPath = base_path('packages/eveseat/web/resources/exploration/' . $region . '.dark.svg');
        $svg = $this->prepareDotlanSvg($svgPath, $mapped);

        return [
            'systems' => $mapped->all(),
            'svg' => $svg,
            'green_count' => $mapped->where('status', 'green')->count(),
        ];
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
     * Load DOTLAN's original vector map and attach SeAT system identifiers/statuses
     * to the existing DOTLAN system groups. The map's original geometry and gates
     * remain untouched.
     */
    private function prepareDotlanSvg(string $path, $systems): string
    {
        if (!is_file($path)) {
            return '';
        }

        $byName = $systems->keyBy(fn ($system) => mb_strtolower($system['name']));
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->load($path, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$document->documentElement || strtolower($document->documentElement->localName) !== 'svg') {
            return '';
        }

        $root = $document->documentElement;
        $root->setAttribute('class', trim($root->getAttribute('class') . ' exploration-map'));
        $root->setAttribute('role', 'img');
        $root->setAttribute('aria-label', 'DOTLAN ' . basename($path, '.dark.svg') . ' solar system map');
        $xpath = new \DOMXPath($document);
        // DOTLAN system labels can sit inside links and shared SVG groups.
        // Never assign system classes to a shared group: doing so recolors an
        // entire region and makes the whole map respond as one system.
        foreach ($xpath->query('//*[local-name()="text"]') as $label) {
            $name = trim($label->textContent);
            $system = $byName->get(mb_strtolower($name));
            if (!$system) continue;

            $node = null;
            $ancestor = $label->parentNode;
            while ($ancestor instanceof \DOMElement && $ancestor !== $root) {
                $tag = strtolower($ancestor->localName);
                if ($tag === 'g' || $tag === 'a') {
                    // A system container must not contain labels for other
                    // systems. Prefer the smallest qualifying ancestor.
                    $labels = $xpath->query('.//*[local-name()="text" and contains(concat(" ", normalize-space(@class), " "), " ss ")]', $ancestor);
                    if ($labels !== false && $labels->length === 1) {
                        $node = $ancestor;
                        break;
                    }
                }
                $ancestor = $ancestor->parentNode;
            }
            // Safe fallback: only the label is interactive; never the map root.
            if (!$node) $node = $label;

            $classes = preg_split('/\\s+/', trim($node->getAttribute('class')));
            $classes[] = 'exploration-system';
            $classes[] = 'is-' . $system['status'];
            if ($system['status'] === 'red') $classes[] = 'is-locked';
            $node->setAttribute('class', implode(' ', array_unique(array_filter($classes))));
            $node->setAttribute('data-system-id', (string) $system['id']);
            $node->setAttribute('data-system-name', $system['name']);
            $node->setAttribute('data-status', $system['status']);
            $node->setAttribute('tabindex', $system['status'] === 'red' ? '-1' : '0');
            $node->setAttribute('role', $system['status'] === 'red' ? 'img' : 'button');
            $node->setAttribute('aria-label', $system['name'] . ': ' . ($system['status'] === 'red' ? 'permanently excluded' : ($system['status'] === 'green' ? 'explored' : 'unexplored')));
            $node->setAttribute('aria-pressed', $system['status'] === 'green' ? 'true' : 'false');
        }

        // The downloaded DOTLAN map contains external links. Remove every
        // link destination, including namespaced xlink:href, so clicking
        // anywhere in Exploration never navigates away to DOTLAN.
        foreach ($xpath->query('//*[local-name()="a"]') as $anchor) {
            $anchor->removeAttribute('href');
            $anchor->removeAttributeNS('http://www.w3.org/1999/xlink', 'href');
            $anchor->removeAttributeNS('http://www.w3.org/2000/svg', 'href');
        }

        // Remove executable or embedded content before rendering the downloaded SVG inline.
        foreach ($xpath->query('//*[local-name()="script" or local-name()="foreignObject"]') as $unsafe) {
            $unsafe->parentNode?->removeChild($unsafe);
        }
        foreach ($xpath->query('//@*') as $attribute) {
            if (str_starts_with(strtolower($attribute->nodeName), 'on')) {
                $attribute->ownerElement?->removeAttributeNode($attribute);
            }
        }

        return $document->saveXML($document->documentElement) ?: '';
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
