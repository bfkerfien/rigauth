<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class PotchvenFinancesController extends Controller
{
    private function authorizeFinance(): void
    {
        abort_unless(auth()->check(), 401);
        $characters = auth()->user()->characters;
        $allowed = $characters && $characters->contains(function ($character) {
            return strcasecmp((string) ($character->name ?? $character->character_name ?? ''), 'Turaneau') === 0;
        });
        abort_unless($allowed, 403);
    }


    /**
     * Total qualifying telemetry reports in Corporation Office Hangar 1 at Nani I.
     * ESI's CorpSAG1 flag is corporation hangar division 1; it is independent of
     * the wallet division used for operating expenses.
     */
    private function telemetryQuantity(): ?int
    {
        $db = DB::connection();
        $schema = $db->getSchemaBuilder();
        foreach (['fin_owners', 'fin_asset_snapshots', 'fin_asset_items'] as $table) {
            if (!$schema->hasTable($table)) {
                return null;
            }
        }

        $stationId = (int) env('FIN_TELEMETRY_STATION_ID', 60011173);
        $typeIds = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string) env('FIN_TELEMETRY_TYPE_IDS', '87222,87258'))
        ), fn ($id) => $id > 0)));
        if (!$stationId || !$typeIds) {
            return null;
        }

        $ownerId = $db->table('fin_owners')
            ->where('owner_type', 'corporation')
            ->where('is_active', 1)
            ->value('id');
        if (!$ownerId) {
            return null;
        }

        $latestCapturedAt = $db->table('fin_asset_snapshots')
            ->where('owner_id', $ownerId)
            ->max('captured_at');
        if (!$latestCapturedAt) {
            return null;
        }

        return (int) $db->table('fin_asset_items as i')
            ->join('fin_asset_snapshots as s', 's.id', '=', 'i.snapshot_id')
            ->where('s.owner_id', $ownerId)
            ->where('s.captured_at', $latestCapturedAt)
            ->where('i.location_id', $stationId)
            ->whereIn('i.item_type_id', $typeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(i.raw_payload, '$.location_flag')) = ?", ['CorpSAG1'])
            ->sum('i.quantity');
    }

    public function index(): View
    {
        $this->authorizeFinance();
        $db = DB::connection();
        $schema = $db->getSchemaBuilder();
        $has = fn (string $table) => $schema->hasTable($table);
        $periodStartValue = $has('fin_app_settings')
            ? $db->table('fin_app_settings')->where('setting_key', 'finance_period_start_at')->value('setting_value')
            : null;
        $periodStart = $periodStartValue ? Carbon::parse($periodStartValue) : now()->startOfMonth();
        $start = $periodStart;

        $owners = $has('fin_owners') ? $db->table('fin_owners')->orderBy('owner_type')->orderBy('display_name')->get() : collect();
        $telemetryQuantity = $this->telemetryQuantity();
        $telemetryBaseline = $has('fin_app_settings')
            ? $db->table('fin_app_settings')->where('setting_key', 'telemetry_baseline_quantity')->value('setting_value')
            : null;
        if ($telemetryQuantity !== null && $telemetryBaseline === null && $has('fin_app_settings')) {
            // First activation starts from the current inventory, avoiding a false count
            // of telemetry accumulated before this feature was enabled.
            $telemetryBaseline = (string) $telemetryQuantity;
            $db->table('fin_app_settings')->updateOrInsert(
                ['setting_key' => 'telemetry_baseline_quantity'],
                ['setting_value' => $telemetryBaseline, 'updated_at' => now()]
            );
        }
        $sites = ($telemetryQuantity !== null && $telemetryBaseline !== null)
            ? intdiv(max(0, $telemetryQuantity - (int) $telemetryBaseline), 60)
            : 0;
        $expenses = 0;
        if ($has('fin_wallet_entries') && $has('fin_owners')) {
            $expenses = (float) $db->table('fin_wallet_entries as e')->join('fin_owners as o', 'o.id', '=', 'e.owner_id')
                ->where('o.owner_type', 'character')->where('e.is_operational_expense', 1)->where('e.amount_isk', '<', 0)
                ->where('e.occurred_at', '>=', $start)->sum(DB::raw('-e.amount_isk'));
        }
        $lastSrpTransfer = $has('fin_app_settings')
            ? $db->table('fin_app_settings')->where('setting_key', 'srp_last_transfer_at')->value('setting_value')
            : null;
        $pendingSrp = 0;
        if ($has('fin_srp_obligations') && $has('fin_site_events')) {
            $pendingQuery = $db->table('fin_srp_obligations as o')
                ->join('fin_site_events as s', 's.id', '=', 'o.site_event_id')
                ->where('o.status', 'pending_transfer');
            if ($lastSrpTransfer) {
                $pendingQuery->where('s.occurred_at', '>', $lastSrpTransfer);
            }
            $pendingSrp = (float) $pendingQuery->sum('o.amount_isk');
        }
        $assetValue = 0;
        if ($has('fin_asset_snapshots')) {
            $assetValue = (float) $db->table('fin_asset_snapshots as s')->joinSub(
                $db->table('fin_asset_snapshots')->select('owner_id', DB::raw('MAX(captured_at) as captured_at'))->groupBy('owner_id'),
                'latest', fn ($join) => $join->on('latest.owner_id', '=', 's.owner_id')->on('latest.captured_at', '=', 's.captured_at')
            )->sum('s.total_estimated_value_isk');
        }
        $corpWallet = 0;
        if ($has('fin_wallet_snapshots') && $has('fin_owners')) {
            $corpWallet = (float) $db->table('fin_wallet_snapshots as s')->joinSub(
                $db->table('fin_wallet_snapshots')->select('owner_id', DB::raw('MAX(captured_at) as captured_at'))->groupBy('owner_id'),
                'latest', fn ($join) => $join->on('latest.owner_id', '=', 's.owner_id')->on('latest.captured_at', '=', 's.captured_at')
            )->join('fin_owners as o', 'o.id', '=', 's.owner_id')->where('o.owner_type', 'corporation')->sum('s.balance_isk');
        }
        // The latest corporation wallet snapshot stores Division 3 (Corp Main Wallet).
        // The sync worker stores Division 2 (SRP Wallet) separately because the legacy
        // wallet-snapshot table does not identify which division each balance belongs to.
        $srpWallet = 0;
        if ($has('fin_app_settings')) {
            $srpWallet = (float) ($db->table('fin_app_settings')
                ->where('setting_key', 'srp_wallet_balance_isk')->value('setting_value') ?? 0);
        }
        $entries = ($has('fin_wallet_entries') && $has('fin_owners'))
            ? $db->table('fin_wallet_entries as e')->join('fin_owners as o', 'o.id', '=', 'e.owner_id')->orderByDesc('e.occurred_at')->limit(30)
                ->get(['e.occurred_at', 'o.display_name', 'e.amount_isk', 'e.reference_type', 'e.description', 'e.is_operational_expense'])
            : collect();
        $siteEvents = $has('fin_site_events') ? $db->table('fin_site_events')->orderByDesc('occurred_at')->limit(20)->get() : collect();
        $lastFinanceSync = $has('fin_sync_state')
            ? $db->table('fin_sync_state')->whereNotNull('last_success_at')->max('last_success_at')
            : null;
        $financeSyncErrors = $has('fin_sync_state')
            ? $db->table('fin_sync_state')->whereNotNull('last_error')->orderByDesc('id')->limit(5)->get(['resource_key', 'last_error'])
            : collect();
        $daysElapsed = max(1, (int) $periodStart->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1);
        $timerResetAt = $has('fin_app_settings')
            ? $db->table('fin_app_settings')->where('setting_key', '180_day_timer_reset_at')->value('setting_value')
            : null;
        $timerResetAt = $timerResetAt ? Carbon::parse($timerResetAt) : now();
        $monthlyCountdown = max(0, 180 - (int) $timerResetAt->diffInDays(now()));
        $siteRevenue = $sites * 45000000;

        return view('web::potchven-finances.index', compact(
            'owners', 'sites', 'daysElapsed', 'expenses', 'pendingSrp', 'assetValue',
            'corpWallet', 'srpWallet', 'siteRevenue', 'entries', 'siteEvents', 'monthlyCountdown', 'lastFinanceSync', 'financeSyncErrors', 'periodStart'
        ));
    }

    public function resetTimer(): RedirectResponse
    {
        $this->authorizeFinance();
        $schema = Schema::connection(DB::getDefaultConnection());
        if (!$schema->hasTable('fin_app_settings')) {
            $schema->create('fin_app_settings', function ($table) {
                $table->string('setting_key', 100)->primary();
                $table->string('setting_value', 255);
                $table->timestamp('updated_at')->useCurrent();
            });
        }
        $resetAt = now()->toDateTimeString();
        $telemetryQuantity = $this->telemetryQuantity();
        foreach (['180_day_timer_reset_at', 'finance_period_start_at'] as $settingKey) {
            DB::table('fin_app_settings')->updateOrInsert(
                ['setting_key' => $settingKey],
                ['setting_value' => $resetAt, 'updated_at' => now()]
            );
        }
        if ($telemetryQuantity !== null) {
            DB::table('fin_app_settings')->updateOrInsert(
                ['setting_key' => 'telemetry_baseline_quantity'],
                ['setting_value' => (string) $telemetryQuantity, 'updated_at' => now()]
            );
        }
        return redirect()->route('seatcore::potchven-finances.index')
            ->with('success', 'New financial reporting period started. Telemetry site counts, revenue, expenses, and the 180-day countdown now start from zero. Historical wallet and site records were preserved; outstanding SRP obligations remain payable.');
    }

    public function addSiteWithoutLoot(): RedirectResponse
    {
        $this->authorizeFinance();
        abort_unless(Schema::hasTable('fin_site_events') && Schema::hasTable('fin_srp_obligations'), 500, 'Finance site and SRP tables are not available.');

        DB::transaction(function () {
            $now = now();
            $note = 'Site Without Loot — manually added 27,000,000 ISK SRP transfer obligation.';
            $siteId = DB::table('fin_site_events')->insertGetId([
                'occurred_at' => $now,
                'status' => 'needs_review',
                'payout_count' => 0,
                'total_payout_isk' => 0,
                'revenue_credit_isk' => 0,
                'source' => 'manual',
                'review_note' => $note,
            ]);
            DB::table('fin_srp_obligations')->insert([
                'site_event_id' => $siteId,
                'amount_isk' => 27000000,
                'status' => 'pending_transfer',
                'note' => $note,
            ]);
        });

        return redirect()->route('seatcore::potchven-finances.index')
            ->with('success', 'Added 27,000,000 ISK to pending SRP transfer for Site Without Loot.');
    }

    public function transferToSrp(): RedirectResponse
    {
        $this->authorizeFinance();
        abort_unless(Schema::hasTable('fin_app_settings') && Schema::hasTable('fin_srp_obligations') && Schema::hasTable('fin_site_events'), 500, 'Finance SRP tables are not available.');

        $transferAt = now()->toDateTimeString();
        DB::transaction(function () use ($transferAt) {
            DB::table('fin_srp_obligations')->where('status', 'pending_transfer')->update(['status' => 'transferred']);
            DB::table('fin_app_settings')->updateOrInsert(
                ['setting_key' => 'srp_last_transfer_at'],
                ['setting_value' => $transferAt, 'updated_at' => now()]
            );
        });

        return redirect()->route('seatcore::potchven-finances.index')
            ->with('success', 'Pending SRP obligations marked as transferred. This records the transfer in the dashboard; it does not move ISK in EVE. Any new SRP obligations created after this time will appear as pending.');
    }

    public function storeSite(Request $request): RedirectResponse
    {
        $this->authorizeFinance();
        return redirect()->route('seatcore::potchven-finances.index')
            ->with('info', 'Site results are matched automatically from corporate reward payouts. Run the Potchven finance sync to refresh payout matching and SRP obligations.');
    }
}
