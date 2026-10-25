<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Triglavian\AssetManager\Services\Assets\CorporationAccessPolicy;

class CorporationMarketOrdersExportController extends Controller
{
    public function __invoke(Request $request, int $corporationId, CorporationAccessPolicy $accessPolicy)
    {
        abort_unless(config('assetmgr.enabled', false), 404);
        abort_unless($accessPolicy->isAllowed($corporationId), 404);

        $corporation = CorporationInfo::query()->where('corporation_id', $corporationId)->first();
        abort_unless($corporation !== null, 404);
        Gate::authorize('corporation.assetmgr_market_orders_view', $corporation);

        $query = DB::table('assetmgr_market_orders')
            ->where('owner_kind', 'corporation')
            ->where('owner_id', $corporationId);

        $side = (string) $request->query('side', '');
        if (in_array($side, ['buy', 'sell'], true)) $query->where('is_buy_order', $side === 'buy');

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\\\') . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('type_name', 'like', $like)
                    ->orWhere('location_name', 'like', $like)
                    ->orWhere('order_id', 'like', $like)
                    ->orWhere('issued_by', 'like', $like);
            });
        }

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) return;

            fputcsv($handle, [
                'Corporation Owner', 'Side', 'Item', 'Type ID', 'Order ID', 'Price ISK',
                'Volume Remaining', 'Volume Total', 'Location', 'Region ID', 'Issued',
                'Issuer Character ID', 'Wallet Division', 'Duration Days', 'Range', 'Escrow ISK',
            ]);

            $safeCell = static function ($value): string {
                $value = (string) ($value ?? '');
                return preg_match('/^[=+@\-]/', $value) === 1 ? "'" . $value : $value;
            };

            foreach ($query->orderByDesc('issued')->orderByDesc('order_id')->cursor() as $order) {
                fputcsv($handle, [
                    $safeCell($order->corporation_name ?? ('Corporation ' . $corporationId)),
                    $order->is_buy_order ? 'Buy' : 'Sell',
                    $safeCell($order->type_name),
                    $order->type_id,
                    $safeCell($order->order_id),
                    $order->price,
                    $order->volume_remain,
                    $order->volume_total,
                    $safeCell($order->location_name),
                    $order->region_id,
                    $safeCell($order->issued),
                    $order->issued_by,
                    $order->wallet_division,
                    $order->duration,
                    $safeCell($order->order_range),
                    $order->escrow,
                ]);
            }

            fclose($handle);
        }, 'assetmgr-corporation-market-orders-' . $corporationId . '-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
