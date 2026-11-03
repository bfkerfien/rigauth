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
     * Total telemetry units in Corporation Hangar Division 3 across all stations.
     * Type 89219 is the corporation telemetry item; each 60 units represents one site.
     */
    private function telemetryQuantity($atOrBefore = null): ?int
    {
        $db = DB::connection();
        $schema = $db->getSchemaBuilder();
        foreach (['fin_owners', 'fin_asset_snapshots', 'fin_asset_items'] as $table) {
            if (!$schema->hasTable($table)) {
                return null;
            }
        }

        $ownerId = $db->table('fin_owners')
            ->where('owner_type', 'corporation')
            ->where('is_active', 1)
            ->value('id');
        if (!$ownerId) {
            return null;
        }

        $snapshotQuery = $db->table('fin_asset_snapshots')->where('owner_id', $ownerId);
        if ($atOrBefore !== null) {
            $snapshotQuery->where('captured_at', '<=', $atOrBefore);
        }
        $latestCapturedAt = $snapshotQuery->max('captured_at');
        if (!$latestCapturedAt) {
            return null;
        }

        return (int) $db->table('fin_asset_items as i')
            ->join('fin_asset_snapshots as s', 's.id', '=', 'i.snapshot_id')
            ->where('s.owner_id', $ownerId)
            ->where('s.captured_at', $latestCapturedAt)
            ->where('i.item_type_id', 89219)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(i.raw_payload, '$.location_flag')) = ?", ['CorpSAG3'])
            ->sum('i.quantity');
    }


    /**
     * Calculate one session from the synced EVE wallet journals.
     * Corp reward ticks are the three 9,000,000 ISK corporation payouts per site.
     * Corporation Division 2 expenses and character expenses are kept separate.
     */
    private function calculateIskHourSessionTotals($session): array
    {
        $db = DB::connection();
        $start = Carbon::parse($session->started_at);
        $stop = Carbon::parse($session->stopped_at);
        $corpOwnerId = $db->table('fin_owners')
            ->where('owner_type', 'corporation')
            ->where('is_active', 1)
            ->value('id');

        $ticks = ['count' => 0, 'isk' => 0.0];
        $srpExpenses = 0.0;
        if ($corpOwnerId && Schema::hasTable('fin_wallet_entries')) {
            $tickQuery = $db->table('fin_wallet_entries')
                ->where('owner_id', $corpOwnerId)
                ->where('occurred_at', '>=', $start)
                ->where('occurred_at', '<=', $stop)
                ->where('source_type', 'journal')
                ->where('reference_type', 'corporate_reward_payout')
                ->where('amount_isk', 9000000);
            $ticks = [
                'count' => (int) $tickQuery->count(),
                'isk' => (float) $tickQuery->sum('amount_isk'),
            ];

            $srpExpenses = (float) $db->table('fin_wallet_entries')
                ->where('owner_id', $corpOwnerId)
                ->where('source_type', 'journal_division_2')
                ->where('occurred_at', '>=', $start)
                ->where('occurred_at', '<=', $stop)
                ->where('amount_isk', '<', 0)
                ->sum(DB::raw('-amount_isk'));
        }

        $characterExpenses = (float) $db->table('fin_wallet_entries as e')
            ->join('fin_owners as o', 'o.id', '=', 'e.owner_id')
            ->where('o.owner_type', 'character')
            ->where('o.is_active', 1)
            ->where('e.source_type', 'journal')
            ->where('e.occurred_at', '>=', $start)
            ->where('e.occurred_at', '<=', $stop)
            ->where('e.amount_isk', '<', 0)
            ->where('e.is_operational_expense', 1)
            ->where(function ($query) {
                $query->whereNull('e.reference_type')
                    ->orWhereRaw('LOWER(e.reference_type) <> ?', ['corporation_tax']);
            })
            ->sum(DB::raw('-e.amount_isk'));

        $telemetryUnits = (int) ($session->telemetry_units ?? 0);
        $telemetryValue = $telemetryUnits * 300000;
        $durationSeconds = max(1, (int) $start->diffInSeconds($stop));
        $netIsk = $ticks['isk'] + $telemetryValue - $srpExpenses - $characterExpenses;

        return [
            'corp_ticks_count' => $ticks['count'],
            'corp_ticks_isk' => $ticks['isk'],
            'telemetry_value_isk' => $telemetryValue,
            'srp_expenses_isk' => $srpExpenses,
            'character_expenses_isk' => $characterExpenses,
            'net_isk' => $netIsk,
            'duration_seconds' => $durationSeconds,
            'isk_per_hour' => $netIsk / ($durationSeconds / 3600),
        ];
    }

    public function startIskHour(): RedirectResponse
    {
        $this->authorizeFinance();
        abort_unless(Schema::hasTable('fin_isk_hour_sessions') && Schema::hasTable('fin_wallet_entries') && Schema::hasTable('fin_owners'), 500, 'ISK per Hour tracking tables are not available.');
        abort_unless(DB::table('fin_owners')->where('owner_type', 'corporation')->where('is_active', 1)->exists(), 500, 'No active corporation finance owner is configured.');

        if (DB::table('fin_isk_hour_sessions')->whereNull('stopped_at')->exists()) {
            return redirect()->route('seatcore::potchven-finances.index')
                ->with('warning', 'An ISK per Hour session is already running. Stop it before starting another.');
        }

        $now = now();
        DB::table('fin_isk_hour_sessions')->insert([
            'started_at' => $now,
            'telemetry_units' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()->route('seatcore::potchven-finances.index')
            ->with('success', 'ISK per Hour session started. Corporation ticks and wallet expenses will be counted from this time.');
    }

    public function stopIskHour(Request $request): RedirectResponse
    {
        $this->authorizeFinance();
        abort_unless(Schema::hasTable('fin_isk_hour_sessions') && Schema::hasTable('fin_wallet_entries') && Schema::hasTable('fin_owners'), 500, 'ISK per Hour tracking tables are not available.');
        $validated = $request->validate([
            'telemetry_units' => 'required|integer|min:0|max:1000000000',
        ]);

        $session = DB::table('fin_isk_hour_sessions')->whereNull('stopped_at')->orderByDesc('id')->first();
        if (!$session) {
            return redirect()->route('seatcore::potchven-finances.index')
                ->with('info', 'There is no active ISK per Hour session to stop.');
        }

        $stoppedAt = now();
        DB::table('fin_isk_hour_sessions')->where('id', $session->id)->update([
            'stopped_at' => $stoppedAt,
            'telemetry_units' => (int) $validated['telemetry_units'],
            'updated_at' => $stoppedAt,
        ]);

        $session = DB::table('fin_isk_hour_sessions')->where('id', $session->id)->first();
        $totals = $this->calculateIskHourSessionTotals($session);
        DB::table('fin_isk_hour_sessions')->where('id', $session->id)->update($totals + ['updated_at' => now()]);

        return redirect()->route('seatcore::potchven-finances.index')
            ->with('success', 'Session stopped. Calculated net earnings: ' . number_format($totals['net_isk'], 0) . ' ISK at ' . number_format($totals['isk_per_hour'], 0) . ' ISK/hour.');
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
            // Prefer the latest saved inventory snapshot at or before period start so
            // telemetry already earned this period is retained. If none exists, start
            // from current inventory to avoid counting older items as new sites.
            $baselineQuantity = $this->telemetryQuantity($periodStart) ?? $telemetryQuantity;
            $telemetryBaseline = (string) $baselineQuantity;
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
        // Dashboard asset totals are sourced from the native Asset Manager tables.
        // Asset Manager owns inventory sync and Jita sell-price freshness; do not use
        // the legacy finance asset snapshots for these two portfolio indicators.
        $personalPortfolioValue = 0.0;
        $corporationHangarValue = 0.0;
        $personalWalletValue = 0.0;
        $assetManagerAvailable = $has('assetmgr_assets') && $has('assetmgr_prices');
        $jitaRegionId = 10000002;
        $priceCutoff = now()->subSeconds((int) config('assetmgr.pricing.stale_after_seconds', 3600));

        if ($assetManagerAvailable && $has('fin_owners')) {
            $characterIds = $db->table('fin_owners')
                ->where('owner_type', 'character')->where('is_active', 1)
                ->pluck('eve_id')->map(fn ($id) => (int) $id)->filter()->values()->all();
            $corporationId = (int) ($db->table('fin_owners')
                ->where('owner_type', 'corporation')->where('is_active', 1)->value('eve_id') ?? 0);

            $jitaSellPrices = $db->table('assetmgr_prices')
                ->select(['type_id', 'unit_price'])
                ->where('region_id', $jitaRegionId)
                ->where('source', 'esi-orders')->where('price_basis', 'sell')
                ->whereNotNull('unit_price')
                ->where(function ($query) use ($priceCutoff) {
                    $query->where('source_expires_at', '>', now())
                        ->orWhere(function ($fallback) use ($priceCutoff) {
                            $fallback->whereNull('source_expires_at')->where('fetched_at', '>=', $priceCutoff);
                        });
                });

            if (count($characterIds)) {
                $personalPortfolioValue = (float) $db->table('assetmgr_assets as a')
                    ->joinSub($jitaSellPrices, 'p', fn ($join) => $join->on('p.type_id', '=', 'a.type_id'))
                    ->where('a.owner_kind', 'character')->whereIn('a.owner_id', $characterIds)
                    ->sum(DB::raw('a.quantity * p.unit_price'));

                if ($has('fin_wallet_snapshots')) {
                    $personalWalletValue = (float) $db->table('fin_wallet_snapshots as s')
                        ->joinSub(
                            $db->table('fin_wallet_snapshots')->select('owner_id', DB::raw('MAX(captured_at) as captured_at'))->groupBy('owner_id'),
                            'latest', fn ($join) => $join->on('latest.owner_id', '=', 's.owner_id')->on('latest.captured_at', '=', 's.captured_at')
                        )
                        ->join('fin_owners as o', 'o.id', '=', 's.owner_id')
                        ->where('o.owner_type', 'character')->where('o.is_active', 1)
                        ->sum('s.balance_isk');
                }
            }

            if ($corporationId > 0) {
                $corporationHangarValue = (float) $db->table('assetmgr_assets as a')
                    ->joinSub($jitaSellPrices, 'p', fn ($join) => $join->on('p.type_id', '=', 'a.type_id'))
                    ->where('a.owner_kind', 'corporation')->where('a.owner_id', $corporationId)
                    ->whereIn('a.location_flag', ['CorpSAG2', 'CorpSAG4', 'CorpSAG5', 'CorpSAG6'])
                    ->sum(DB::raw('a.quantity * p.unit_price'));
            }
        }
        $personalNetValue = $personalPortfolioValue + $personalWalletValue - 90000000;
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
        $elapsedCalendarDays = (int) $timerResetAt->copy()->startOfDay()->diffInDays(now()->startOfDay());
        $monthlyCountdown = max(0, 180 - $elapsedCalendarDays);
        $siteRevenue = $sites * 27000000;

        $activeIskHourSession = null;
        $iskHourLedger = collect();
        $weekdayLabels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $weekdayBuckets = array_fill(0, 7, []);
        $iskHourWeekdayAverages = collect();
        if ($has('fin_isk_hour_sessions')) {
            $activeIskHourSession = $db->table('fin_isk_hour_sessions')->whereNull('stopped_at')->orderByDesc('id')->first();
            $completedSessions = $db->table('fin_isk_hour_sessions')->whereNotNull('stopped_at')->orderBy('started_at')->get();
            foreach ($completedSessions as $session) {
                $totals = $this->calculateIskHourSessionTotals($session);
                $db->table('fin_isk_hour_sessions')->where('id', $session->id)->update($totals + ['updated_at' => now()]);
                foreach ($totals as $key => $value) {
                    $session->{$key} = $value;
                }
                $weekday = (int) Carbon::parse($session->started_at)->dayOfWeek;
                $weekdayBuckets[$weekday][] = (float) $session->isk_per_hour;
            }
            $maxMagnitude = 0.0;
            $averages = [];
            foreach ($weekdayBuckets as $index => $values) {
                $average = count($values) ? array_sum($values) / count($values) : 0.0;
                $averages[$index] = $average;
                $maxMagnitude = max($maxMagnitude, abs($average));
            }
            foreach ($weekdayLabels as $index => $label) {
                $iskHourWeekdayAverages->push([
                    'label' => $label,
                    'average' => $averages[$index],
                    'sessions' => count($weekdayBuckets[$index]),
                    'bar_percent' => $maxMagnitude > 0 ? (abs($averages[$index]) / $maxMagnitude) * 100 : 0,
                    'is_negative' => $averages[$index] < 0,
                ]);
            }
            $iskHourLedger = $completedSessions->sortByDesc('started_at')->take(100)->values();
        }

        return view('web::potchven-finances.index', compact(
            'owners', 'sites', 'daysElapsed', 'expenses', 'pendingSrp', 'personalNetValue', 'corporationHangarValue', 'assetManagerAvailable',
            'corpWallet', 'srpWallet', 'siteRevenue', 'entries', 'siteEvents', 'monthlyCountdown', 'lastFinanceSync', 'financeSyncErrors', 'periodStart',
            'activeIskHourSession', 'iskHourLedger', 'iskHourWeekdayAverages'
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

        // This control is deliberately independent: it changes only the 180-day countdown.
        DB::table('fin_app_settings')->updateOrInsert(
            ['setting_key' => '180_day_timer_reset_at'],
            ['setting_value' => now()->toDateTimeString(), 'updated_at' => now()]
        );

        return redirect()->route('seatcore::potchven-finances.index')
            ->with('success', 'The 180-day timer has been reset. The financial-period counter, telemetry baseline, wallet reporting period, and historical records were not changed.');
    }

    public function resetFinancialPeriod(): RedirectResponse
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

        // Restart the financial counter and its wallet-expense reporting window.
        DB::table('fin_app_settings')->updateOrInsert(
            ['setting_key' => 'finance_period_start_at'],
            ['setting_value' => $resetAt, 'updated_at' => now()]
        );

        // Capture current CorpSAG3 telemetry so only newly earned sites count.
        if ($telemetryQuantity !== null) {
            DB::table('fin_app_settings')->updateOrInsert(
                ['setting_key' => 'telemetry_baseline_quantity'],
                ['setting_value' => (string) $telemetryQuantity, 'updated_at' => now()]
            );
        }

        return redirect()->route('seatcore::potchven-finances.index')
            ->with('success', 'The financial period has been reset. Days Since Financial Reset is now day 1, the telemetry counter starts from its current baseline, and wallet expenses are counted from this date. The 180-day countdown, live wallet balances, historical wallet entries, site records, and outstanding SRP obligations were preserved.');
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
