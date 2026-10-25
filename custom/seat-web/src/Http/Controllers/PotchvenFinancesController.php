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

    public function index(): View
    {
        $this->authorizeFinance();
        $db = DB::connection();
        $schema = $db->getSchemaBuilder();
        $has = fn (string $table) => $schema->hasTable($table);
        $start = now()->startOfMonth();

        $owners = $has('fin_owners') ? $db->table('fin_owners')->orderBy('owner_type')->orderBy('display_name')->get() : collect();
        $sites = $has('fin_site_events') ? $db->table('fin_site_events')->where('status', 'successful')->where('occurred_at', '>=', $start)->count() : 0;
        $expenses = 0;
        if ($has('fin_wallet_entries') && $has('fin_owners')) {
            $expenses = (float) $db->table('fin_wallet_entries as e')->join('fin_owners as o', 'o.id', '=', 'e.owner_id')
                ->where('o.owner_type', 'character')->where('e.is_operational_expense', 1)->where('e.amount_isk', '<', 0)
                ->where('e.occurred_at', '>=', $start)->sum(DB::raw('-e.amount_isk'));
        }
        $pendingSrp = $has('fin_srp_obligations') ? (float) $db->table('fin_srp_obligations')->where('status', 'pending_transfer')->sum('amount_isk') : 0;
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
        $entries = ($has('fin_wallet_entries') && $has('fin_owners'))
            ? $db->table('fin_wallet_entries as e')->join('fin_owners as o', 'o.id', '=', 'e.owner_id')->orderByDesc('e.occurred_at')->limit(30)
                ->get(['e.occurred_at', 'o.display_name', 'e.amount_isk', 'e.reference_type', 'e.description', 'e.is_operational_expense'])
            : collect();
        $siteEvents = $has('fin_site_events') ? $db->table('fin_site_events')->orderByDesc('occurred_at')->limit(20)->get() : collect();
        $daysElapsed = max(1, (int) now()->day);
        $timerResetAt = $has('fin_app_settings')
            ? $db->table('fin_app_settings')->where('setting_key', '180_day_timer_reset_at')->value('setting_value')
            : null;
        $timerResetAt = $timerResetAt ? Carbon::parse($timerResetAt) : now();
        $monthlyCountdown = max(0, 180 - (int) $timerResetAt->diffInDays(now()));
        $siteRevenue = $sites * 45000000;

        return view('web::potchven-finances.index', compact(
            'owners', 'sites', 'daysElapsed', 'expenses', 'pendingSrp', 'assetValue',
            'corpWallet', 'siteRevenue', 'entries', 'siteEvents', 'monthlyCountdown'
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
        DB::table('fin_app_settings')->updateOrInsert(
            ['setting_key' => '180_day_timer_reset_at'],
            ['setting_value' => now()->toDateTimeString(), 'updated_at' => now()]
        );
        return redirect()->route('seatcore::potchven-finances.index')->with('success', '180-day timer reset to 180 days.');
    }

    public function storeSite(Request $request): RedirectResponse
    {
        $this->authorizeFinance();
        return redirect()->route('seatcore::potchven-finances.index')
            ->with('info', 'Site results are matched automatically from corporate reward payouts. Run the Potchven finance sync to refresh payout matching and SRP obligations.');
    }
}