<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use EveSeat\Eseye\Containers\EsiAuthentication;
use EveSeat\Eseye\Eseye;
use Seat\Eveapi\Models\RefreshToken;
use Throwable;

class ExplorationController extends Controller
{
    private const REGIONS = ['Branch', 'Tenal', 'Venal'];

    // Pandemic Legion alliance ID. Sovereignty is refreshed from CCP's public ESI map.
    private const PANDEMIC_LEGION_ALLIANCE_ID = 386292982;

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

        $redIds = $this->pandemicLegionSovereigntySystemIds();
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
     * Fetch current sov ownership from CCP ESI and cache it for one hour.
     * A failed refresh falls back to the last successful cached snapshot.
     */
    private function pandemicLegionSovereigntySystemIds(): array
    {
        return Cache::remember('tc_exploration_pl_sov_system_ids', now()->addHour(), function () {
            $response = Http::acceptJson()
                ->timeout(20)
                ->retry(2, 500)
                ->get('https://esi.evetech.net/v1/sovereignty/map/', [
                    'datasource' => 'tranquility',
                ]);

            if (!$response->successful() || !is_array($response->json())) {
                throw new RuntimeException('Unable to retrieve current sovereignty map from ESI.');
            }

            return collect($response->json())
                ->filter(fn ($entry) => (int) ($entry['alliance_id'] ?? 0) === self::PANDEMIC_LEGION_ALLIANCE_ID)
                ->pluck('system_id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();
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
        foreach ($xpath->query('//*[local-name()="text" and contains(concat(" ", normalize-space(@class), " "), " ss ")]') as $label) {
            $name = trim($label->textContent);
            $system = $byName->get(mb_strtolower($name));
            $group = $label->parentNode;
            if (!$system || !$group instanceof \DOMElement) {
                continue;
            }

            $classes = preg_split('/\\s+/', trim($group->getAttribute('class')));
            $classes[] = 'exploration-system';
            $classes[] = 'is-' . $system['status'];
            if ($system['status'] === 'red') {
                $classes[] = 'is-locked';
            }
            $group->setAttribute('class', implode(' ', array_unique(array_filter($classes))));
            $group->setAttribute('data-system-id', (string) $system['id']);
            $group->setAttribute('data-system-name', $system['name']);
            $group->setAttribute('data-status', $system['status']);
            $group->setAttribute('tabindex', $system['status'] === 'red' ? '-1' : '0');
            $group->setAttribute('role', $system['status'] === 'red' ? 'img' : 'button');
            $group->setAttribute('aria-label', $system['name'] . ': ' . ($system['status'] === 'red' ? 'permanently excluded' : ($system['status'] === 'green' ? 'explored' : 'unexplored')));
            $group->setAttribute('aria-pressed', $system['status'] === 'green' ? 'true' : 'false');
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
            $esi = new Eseye(new EsiAuthentication([
                'client_id' => config('eve.client_id'),
                'secret' => config('eve.client_secret'),
                'refresh_token' => $token->refresh_token,
                'token' => $token->token ?? null,
                'scopes' => $scopes,
            ]));

            // Eseye's invoke() returns the decoded response body (stdClass),
            // not an HTTP response wrapper. Read solar_system_id directly.
            $location = $esi->invoke('get', '/characters/{character_id}/location/', [
                'character_id' => $characterId,
            ]);
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
                'message' => 'SeAT found the character token, but ESI location lookup failed. Check the Laravel log for the Exploration live-location error.',
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

        if (in_array($systemId, $this->pandemicLegionSovereigntySystemIds(), true)) {
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
