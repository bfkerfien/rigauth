<?php
namespace Seat\Web\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DivineReignController extends Controller
{
    private const CHARACTER_NAME = 'DivineReign DivineSpirit';
    private const SYSTEM_NAME = 'MC6-5J';

    private function character(): object
    {
        abort_unless(auth()->check(), 401);
        $characters = auth()->user()->characters;
        $character = $characters ? $characters->first(function ($item) {
            return strcasecmp((string) ($item->name ?? $item->character_name ?? ''), self::CHARACTER_NAME) === 0;
        }) : null;
        abort_unless($character, 403, 'This tracker is restricted to DivineReign DivineSpirit.');
        return $character;
    }

    private function activeSession(int $characterId): ?object
    {
        return DB::table('dr_ratting_sessions')->where('character_id', $characterId)
            ->where('status', 'active')->orderByDesc('id')->first();
    }

    public function index(): View
    {
        $character = $this->character();
        $characterId = (int) $character->character_id;
        $this->syncBountyAllocations($characterId);
        $active = $this->activeSession($characterId);
        $bounties = $active ? $this->sessionBountyTotal($characterId, $active, now()) : 0.0;
        $duration = $active ? max(0, now()->diffInSeconds(Carbon::parse($active->started_at))) : 0;
        $events = $active ? DB::table('dr_ratting_events')->where('session_id', $active->id)->orderByDesc('occurred_at')->limit(12)->get() : collect();
        $counts = ['dread_guristas' => 0, 'hauler' => 0, 'estamel' => 0];
        if ($active) {
            foreach (DB::table('dr_ratting_events')->select('event_type', DB::raw('COUNT(*) as total'))
                ->where('session_id', $active->id)->where('event_action', 'kill')->groupBy('event_type')->get() as $row) {
                $counts[$row->event_type] = (int) $row->total;
            }
            $counts['hauler'] = DB::table('dr_ratting_events')->where('session_id', $active->id)->where('event_type', 'hauler')->count();
            $counts['estamel'] = DB::table('dr_ratting_events')->where('session_id', $active->id)->where('event_type', 'estamel')->count();
        }
        $loot = $active ? (float) DB::table('dr_ratting_events')->where('session_id', $active->id)->sum('loot_isk') : 0.0;
        $recentSessions = DB::table('dr_ratting_sessions')->orderByDesc('started_at')->get();
        $shipStats = DB::table('dr_ratting_sessions')
            ->select('ship_name',
                DB::raw('SUM(COALESCE(duration_seconds, 0)) as total_seconds'),
                DB::raw('SUM(COALESCE(total_income_isk, 0)) as total_income_isk'))
            ->where('status', 'completed')->whereNotNull('ship_name')->where('ship_name', '<>', '')
            ->groupBy('ship_name')->orderBy('ship_name')->get()
            ->map(function ($row) {
                $hours = (float) $row->total_seconds / 3600;
                $row->hours = $hours;
                $row->average_isk_per_hour = $hours > 0 ? (float) $row->total_income_isk / $hours : 0;
                return $row;
            });
        $maxShipHours = max(1, (float) $shipStats->max('hours'));
        $maxShipRate = max(1, (float) $shipStats->max('average_isk_per_hour'));
        $recentEvents = DB::table('dr_ratting_events as e')->join('dr_ratting_sessions as s', 's.id', '=', 'e.session_id')
            ->orderByDesc('e.occurred_at')->limit(30)->get(['e.*', 's.started_at as session_started_at']);
        $latestSnapshot = DB::table('dr_asset_snapshots')->orderByDesc('captured_at')->first();
        $structureId = DB::table('dr_settings')->where('setting_key', 'astra_house_structure_id')->value('setting_value');

        return view('web::divine-reign.index', compact(
            'character', 'active', 'bounties', 'duration', 'events', 'counts', 'loot',
            'recentSessions', 'recentEvents', 'latestSnapshot', 'structureId', 'shipStats', 'maxShipHours', 'maxShipRate'
        ));
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ship_name' => 'required|in:Ishtar,Tengu,Gila',
        ]);
        $character = $this->character();
        $characterId = (int) $character->character_id;
        abort_if($this->activeSession($characterId), 409, 'A ratting session is already running.');
        $id = DB::table('dr_ratting_sessions')->insertGetId([
            'character_id' => $characterId, 'character_name' => self::CHARACTER_NAME,
            'ship_name' => $data['ship_name'],
            'system_name' => self::SYSTEM_NAME, 'started_at' => now(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $snapshot = $this->captureAssetSnapshot($id, $characterId, 'start');
        if ($snapshot !== null) {
            DB::table('dr_ratting_sessions')->where('id', $id)->update(['asset_start_isk' => $snapshot, 'updated_at' => now()]);
        }
        return redirect()->route('seatcore::divine-reign.index')->with(
            $snapshot === null ? 'warning' : 'success',
            $snapshot === null ? 'Timer started. Starting asset value is unavailable; bounty tracking is active.' : 'Ratting session started; asset baseline captured.'
        );
    }

    public function stop(): RedirectResponse
    {
        $character = $this->character();
        $characterId = (int) $character->character_id;
        $session = $this->activeSession($characterId);
        abort_unless($session, 409, 'There is no active ratting session.');
        $stoppedAt = now();
        $assetEnd = $this->captureAssetSnapshot((int) $session->id, $characterId, 'stop');
        $duration = max(1, Carbon::parse($session->started_at)->diffInSeconds($stoppedAt));
        $assetDelta = ($session->asset_start_isk !== null && $assetEnd !== null)
            ? $assetEnd - (float) $session->asset_start_isk : null;
        DB::table('dr_ratting_sessions')->where('id', $session->id)->update([
            'stopped_at' => $stoppedAt, 'duration_seconds' => $duration, 'bounty_isk' => 0,
            'asset_end_isk' => $assetEnd, 'asset_delta_isk' => $assetDelta,
            'total_income_isk' => $assetDelta ?? 0, 'isk_per_hour' => ($assetDelta ?? 0) / ($duration / 3600),
            'status' => 'completed', 'updated_at' => now(),
        ]);
        $this->syncBountyAllocations($characterId);
        return redirect()->route('seatcore::divine-reign.index')->with('success', 'Ratting session stopped. Wallet bounties will continue being attributed to this session for 22 minutes after stop.');
    }

    public function event(Request $request): RedirectResponse
    {
        $character = $this->character();
        $characterId = (int) $character->character_id;
        $session = $this->activeSession($characterId);
        abort_unless($session, 409, 'Start a ratting session before recording an event.');
        $data = $request->validate([
            'event_type' => 'required|in:dread_guristas,hauler,estamel',
            'event_action' => 'required|in:spawn,sighting,kill',
            'loot_isk' => 'nullable|numeric|min:0|max:100000000000000',
            'location' => 'nullable|string|max:160', 'notes' => 'nullable|string|max:2000',
        ]);
        DB::table('dr_ratting_events')->insert([
            'session_id' => $session->id, 'character_id' => $characterId,
            'event_type' => $data['event_type'], 'event_action' => $data['event_action'],
            'location' => $data['location'] ?: self::SYSTEM_NAME, 'loot_isk' => (float) ($data['loot_isk'] ?? 0),
            'notes' => $data['notes'] ?? null, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return redirect()->route('seatcore::divine-reign.index')->with('success', 'Operations event recorded.');
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $this->character();
        $data = $request->validate(['structure_id' => 'nullable|integer|min:1']);
        DB::table('dr_settings')->updateOrInsert(
            ['setting_key' => 'astra_house_structure_id'],
            ['setting_value' => (string) ($data['structure_id'] ?? ''), 'updated_at' => now()]
        );
        return redirect()->route('seatcore::divine-reign.index')->with('success', 'Astrahouse structure ID saved.');
    }

    /**
     * Allocate wallet bounty entries to a session through 22 minutes after it stops.
     * If another session starts during that grace period, the previous session keeps
     * ownership of the overlapping time; the new session's bounty window starts
     * when the previous session's grace period ends.
     */
    private function syncBountyAllocations(int $characterId): void
    {
        if (!Schema::hasTable('character_wallet_journals')) return;

        $sessions = DB::table('dr_ratting_sessions')->where('character_id', $characterId)
            ->orderBy('started_at')->orderBy('id')->get();
        $previousGraceEnd = null;

        foreach ($sessions as $session) {
            $originalStart = Carbon::parse($session->started_at);
            $windowStart = $previousGraceEnd && $previousGraceEnd->gt($originalStart)
                ? $previousGraceEnd->copy() : $originalStart->copy();

            if ($session->status === 'active') {
                $windowEnd = now();
            } elseif ($session->stopped_at) {
                $windowEnd = Carbon::parse($session->stopped_at)->addMinutes(22);
            } else {
                continue;
            }

            $bounties = $windowStart->lte($windowEnd)
                ? $this->bountyTotal($characterId, $windowStart, $windowEnd) : 0.0;

            if ($session->status === 'completed') {
                $duration = max(1, (int) ($session->duration_seconds
                    ?: $originalStart->diffInSeconds(Carbon::parse($session->stopped_at))));
                $assetDelta = $session->asset_delta_isk !== null ? (float) $session->asset_delta_isk : 0.0;
                $income = $bounties + $assetDelta;
                DB::table('dr_ratting_sessions')->where('id', $session->id)->update([
                    'bounty_isk' => $bounties,
                    'total_income_isk' => $income,
                    'isk_per_hour' => $income / ($duration / 3600),
                    'updated_at' => now(),
                ]);
                $previousGraceEnd = $windowEnd->copy();
            }
        }
    }

    private function sessionBountyTotal(int $characterId, object $session, $to): float
    {
        $start = Carbon::parse($session->started_at);
        $previous = DB::table('dr_ratting_sessions')->where('character_id', $characterId)
            ->where('status', 'completed')->where('stopped_at', '<=', $session->started_at)
            ->orderByDesc('stopped_at')->first();

        if ($previous && $previous->stopped_at) {
            $graceEnd = Carbon::parse($previous->stopped_at)->addMinutes(22);
            if ($graceEnd->gt($start)) $start = $graceEnd;
        }

        return $start->lte(Carbon::parse($to))
            ? $this->bountyTotal($characterId, $start, $to) : 0.0;
    }

    private function bountyTotal(int $characterId, $from, $to): float
    {
        if (!Schema::hasTable('character_wallet_journals')) return 0.0;
        return (float) DB::table('character_wallet_journals')->where('character_id', $characterId)
            ->whereBetween('date', [$from, $to])->whereIn('ref_type', ['bounty_prizes', 'bounty_prize'])
            ->where('amount', '>', 0)->sum('amount');
    }

    private function captureAssetSnapshot(int $sessionId, int $characterId, string $phase): ?float
    {
        if (!Schema::hasTable('character_assets') || !Schema::hasTable('solar_systems')) return null;
        $systemId = DB::table('solar_systems')->where('name', self::SYSTEM_NAME)->value('system_id');
        if (!$systemId) return null;
        $structureId = DB::table('dr_settings')->where('setting_key', 'astra_house_structure_id')->value('setting_value');
        $assets = DB::table('character_assets')->where('character_id', $characterId)->get();
        $roots = $assets->filter(function ($asset) use ($systemId, $structureId) {
            return ((int) $asset->location_id === (int) $systemId && $asset->location_type === 'solar_system')
                || ($structureId && (int) $asset->location_id === (int) $structureId);
        });
        $included = []; $frontier = [];
        foreach ($roots as $asset) { $included[(string) $asset->item_id] = $asset; $frontier[(string) $asset->item_id] = true; }
        while ($frontier) {
            $next = [];
            foreach ($assets as $asset) {
                $parent = (string) $asset->location_id; $item = (string) $asset->item_id;
                if (isset($frontier[$parent]) && !isset($included[$item])) { $included[$item] = $asset; $next[$item] = true; }
            }
            $frontier = $next;
        }
        if (!$included) {
            DB::table('dr_asset_snapshots')->insert([
                'session_id' => $sessionId, 'character_id' => $characterId, 'phase' => $phase,
                'captured_at' => now(), 'item_count' => 0, 'unpriced_count' => 0, 'total_value_isk' => 0,
                'source' => 'SeAT assets / Jita regional market', 'created_at' => now(), 'updated_at' => now(),
            ]);
            return 0.0;
        }
        $quantities = [];
        foreach ($included as $asset) {
            $typeId = (int) $asset->type_id;
            $quantities[$typeId] = ($quantities[$typeId] ?? 0) + max(1, (int) $asset->quantity);
        }
        $prices = $this->jitaBuyPrices(array_keys($quantities));
        $total = 0.0; $unpriced = 0;
        foreach ($quantities as $typeId => $quantity) {
            if (!isset($prices[$typeId]) || $prices[$typeId] <= 0) { $unpriced += $quantity; continue; }
            $total += $prices[$typeId] * $quantity;
        }
        DB::table('dr_asset_snapshots')->insert([
            'session_id' => $sessionId, 'character_id' => $characterId, 'phase' => $phase,
            'captured_at' => now(), 'item_count' => count($included), 'unpriced_count' => $unpriced,
            'total_value_isk' => $total, 'source' => 'SeAT assets / Jita regional buy orders',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $total;
    }

    private function jitaBuyPrices(array $typeIds): array
    {
        $prices = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $typeIds))), 80) as $chunk) {
            $key = 'dr_jita_buy_' . md5(implode(',', $chunk));
            $batch = Cache::remember($key, 1800, function () use ($chunk) {
                $url = 'https://market.fuzzwork.co.uk/aggregates/?region=10000002&types=' . implode(',', $chunk);
                $response = Http::timeout(15)->retry(2, 300)->get($url);
                if (!$response->successful()) return [];
                $data = $response->json() ?: []; $result = [];
                foreach ($data as $typeId => $market) {
                    $buy = (float) data_get($market, 'buy.max', 0);
                    $sell = (float) data_get($market, 'sell.min', 0);
                    $result[(int) $typeId] = $buy > 0 ? $buy : $sell;
                }
                return $result;
            });
            $prices += $batch;
        }
        return $prices;
    }
}
