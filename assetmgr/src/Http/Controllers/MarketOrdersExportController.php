<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Triglavian\AssetManager\Services\Assets\CharacterPortfolioResolver;

class MarketOrdersExportController extends Controller
{
    public function __invoke(Request $request, CharacterPortfolioResolver $portfolioResolver)
    {
        abort_unless(config('assetmgr.enabled', false), 404);
        try {
            $characterIds = $portfolioResolver->characterIdsFor($request->user());
        } catch (RuntimeException $exception) {
            abort(503, 'Asset Manager is not yet connected to SeAT character ownership.');
        }

        $query = DB::table('assetmgr_market_orders')
            ->where('owner_kind', 'character')
            ->whereIn('owner_id', $characterIds);

        $characterId = $request->integer('character_id') ?: null;
        if ($characterId !== null) {
            if (! in_array($characterId, $characterIds, true)) $query->whereRaw('1 = 0');
            else $query->where('character_id', $characterId);
        }

        $side = (string) $request->query('side', '');
        if (in_array($side, ['buy', 'sell'], true)) $query->where('is_buy_order', $side === 'buy');

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\\\') . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('type_name', 'like', $like)
                    ->orWhere('location_name', 'like', $like)
                    ->orWhere('order_id', 'like', $like)
                    ->orWhere('character_name', 'like', $like);
            });
        }

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) return;

            fputcsv($handle, [
                'Character Owner', 'Side', 'Item', 'Type ID', 'Order ID', 'Price ISK',
                'Volume Remaining', 'Volume Total', 'Location', 'Region ID', 'Issued',
                'Duration Days', 'Range', 'Minimum Volume', 'Escrow ISK',
            ]);

            $safeCell = static function ($value): string {
                $value = (string) ($value ?? '');
                return preg_match('/^[=+@\-]/', $value) === 1 ? "'" . $value : $value;
            };

            foreach ($query->orderByDesc('issued')->orderByDesc('order_id')->cursor() as $order) {
                fputcsv($handle, [
                    $safeCell($order->character_name ?? ('Character ' . $order->character_id)),
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
                    $order->duration,
                    $safeCell($order->order_range),
                    $order->min_volume,
                    $order->escrow,
                ]);
            }

            fclose($handle);
        }, 'assetmgr-market-orders-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
