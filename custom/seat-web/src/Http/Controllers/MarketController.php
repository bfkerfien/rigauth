<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MarketController extends Controller
{
    private function authorizeMarket(): void
    {
        $user = auth()->user();
        $marketeer = $user && method_exists($user, 'roles')
            && $user->roles()->where('title', 'Marketeers')->exists();
        $temporaryGrantUntil = (int) session('market_temp_access_until', 0);
        $temporaryGrant = $temporaryGrantUntil >= now()->timestamp;

        abort_unless($user && ($user->can('global.superuser') || $marketeer || $temporaryGrant), 403);
    }

    /**
     * Redeem a signed, expiring URL to grant this authenticated browser session
     * temporary Market access for troubleshooting.
     */
    public function temporaryAccess(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        session(['market_temp_access_until' => now()->addMinutes(15)->timestamp]);

        return redirect()->route('seatcore::market.index');
    }

    public function index(Request $request): View
    {
        $this->authorizeMarket();
        $tab = (string) $request->query('tab', 'shame-list');
        if (!in_array($tab, ['shame-list', 'under-30', '18-percent', 'doctrine-health'], true)) {
            $tab = 'shame-list';
        }

        // Reuse the existing SeAT Fittings collector data instead of duplicating it.
        $run = DB::table('fit_collection_runs')
            ->where('status', 'complete')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        $comparisons = $run
            ? DB::table('fit_item_comparisons as c')
                ->leftJoin('fit_market_type_volumes as v', 'v.type_id', '=', 'c.type_id')
                ->where('c.run_id', $run->id)
                ->orderBy('c.type_name')
                ->limit(10000)
                ->get([
                    'c.type_id', 'c.type_name', 'c.structure_sell_min', 'c.jita_sell_min',
                    'c.sell_difference', 'c.sell_difference_percent', 'c.structure_buy_max',
                    'c.jita_buy_max', 'c.structure_sell_volume', 'c.jita_sell_volume',
                    'v.volume as item_volume',
                ])
            : collect();

        $withMarkup = $comparisons->map(function ($row) {
            $jita = (float) ($row->jita_sell_min ?? 0);
            $structure = $row->structure_sell_min === null ? null : (float) $row->structure_sell_min;
            $row->markup_percent = $structure !== null && $jita > 0
                ? (($structure / $jita) - 1) * 100
                : null;
            return $row;
        });

        $shameList = $withMarkup->filter(function ($row) {
            return $row->markup_percent !== null && $row->markup_percent > 30;
        })->values();

        $under30 = $withMarkup->filter(function ($row) {
            return $row->markup_percent !== null && $row->markup_percent >= 0 && $row->markup_percent <= 30;
        })->values();

        $profit18 = $withMarkup->filter(function ($row) {
            if ($row->item_volume === null || $row->structure_sell_min === null || $row->jita_sell_min === null) {
                return false;
            }
            $netStructure = (float) $row->structure_sell_min * (1 - 0.0437);
            $transport = (float) $row->item_volume * 500;
            $jitaTotal = (float) $row->jita_sell_min + $transport;
            $row->net_structure_sale = $netStructure;
            $row->transport_cost = $transport;
            $row->jita_total_cost = $jitaTotal;
            $row->saving_percent = $netStructure > 0 ? (($netStructure - $jitaTotal) / $netStructure) * 100 : -INF;
            $row->qualifies_18_percent = $netStructure > 0 && $jitaTotal <= $netStructure * (1 - 0.18);
            return true;
        })->sortByDesc('saving_percent')->values();

        $profit18Count = $profit18->filter(function ($row) {
            return $row->qualifies_18_percent;
        })->count();

        $doctrineHealth = $this->doctrineHealth($run);

        return view('web::market.index', compact(
            'tab', 'run', 'shameList', 'under30', 'profit18', 'profit18Count', 'doctrineHealth'
        ));
    }

    private function doctrineHealth($run): array
    {
        $doctrines = DB::table('fit_doctrines')
            ->whereRaw("LOWER(name) <> 'unassigned'")
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $assignments = DB::table('fit_doctrine_assignments as da')
            ->join('fit_fits as f', 'f.id', '=', 'da.fit_id')
            ->join('fit_items as i', 'i.fit_id', '=', 'f.id')
            ->orderBy('da.doctrine')
            ->orderBy('f.fit_name')
            ->get([
                'da.doctrine', 'f.id as fit_id', 'f.fit_name',
                'i.type_id', 'i.type_name', 'i.quantity_per_ship',
            ]);

        $reports = [];
        foreach ($doctrines as $doctrine) {
            $reports[$doctrine] = ['doctrine' => $doctrine, 'fit_ids' => [], 'demand' => []];
        }

        foreach ($assignments as $assignment) {
            $name = $assignment->doctrine;
            if (strtolower($name) === 'unassigned') {
                continue;
            }
            if (!isset($reports[$name])) {
                $reports[$name] = ['doctrine' => $name, 'fit_ids' => [], 'demand' => []];
            }
            $fitId = (int) $assignment->fit_id;
            $reports[$name]['fit_ids'][$fitId] = $assignment->fit_name;
            $typeId = (int) $assignment->type_id;
            if (!isset($reports[$name]['demand'][$typeId])) {
                $reports[$name]['demand'][$typeId] = [
                    'type_id' => $typeId,
                    'type_name' => $assignment->type_name,
                    'needed' => 0,
                ];
            }
            $reports[$name]['demand'][$typeId]['needed'] += (int) $assignment->quantity_per_ship * 100;
        }

        $books = [];
        if ($run) {
            $orders = DB::table('fit_market_orders')
                ->where('run_id', $run->id)
                ->where('source', 'structure')
                ->where('is_buy_order', false)
                ->where('volume_remain', '>', 0)
                ->orderBy('type_id')
                ->orderBy('price')
                ->get(['type_id', 'price', 'volume_remain']);

            foreach ($orders as $order) {
                $typeId = (int) $order->type_id;
                if (!isset($books[$typeId])) {
                    $books[$typeId] = [];
                }
                $books[$typeId][] = [
                    'price' => (float) $order->price,
                    'volume' => (int) $order->volume_remain,
                ];
            }
        }

        $result = [];
        foreach ($reports as $report) {
            $missingTypes = 0;
            $requiredTypes = count($report['demand']);
            $totalShortfall = 0;

            foreach ($report['demand'] as $typeId => $demand) {
                $available = array_sum(array_column($books[$typeId] ?? [], 'volume'));
                $shortfall = max(0, $demand['needed'] - $available);
                if ($shortfall > 0) {
                    $missingTypes++;
                    $totalShortfall += $shortfall;
                }
            }

            $result[] = [
                'doctrine' => $report['doctrine'],
                'fit_count' => count($report['fit_ids']),
                'required_item_types' => $requiredTypes,
                'missing_item_count' => $missingTypes,
                'total_shortfall' => $totalShortfall,
                'health_percent' => $requiredTypes > 0
                    ? (($requiredTypes - $missingTypes) / $requiredTypes) * 100
                    : null,
            ];
        }

        usort($result, function ($a, $b) {
            return strcasecmp($a['doctrine'], $b['doctrine']);
        });

        return [
            'fits' => DB::table('fit_fits')->count(),
            'doctrines' => count($doctrines),
            'items' => DB::table('fit_items')->count(),
            'reports' => $result,
        ];
    }
}
