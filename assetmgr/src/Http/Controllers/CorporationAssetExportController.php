<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Triglavian\AssetManager\Services\Assets\AssetQuery;

class CorporationAssetExportController extends Controller
{
    public function __invoke(Request $request, AssetQuery $assetQuery)
    {
        abort_unless(config('assetmgr.enabled', false) && config('assetmgr.corporation_sync_enabled', false), 404);

        $corporationId = $request->integer('corporation_id');
        $allowedCorporations = config('assetmgr.allowed_corporation_ids', []);
        abort_unless($corporationId > 0 && in_array($corporationId, $allowedCorporations, true), 404);
        Gate::authorize('corporation.assetmgr_view', $corporationId);

        $filters = [
            'q' => $request->query('q', ''),
            'sort' => $request->query('sort', 'item'),
            'parent_item_id' => $request->integer('parent_item_id') ?: null,
            'direction' => $request->query('direction', 'asc'),
        ];
        $query = $assetQuery->corporation($corporationId, $filters);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) return;

            fputcsv($handle, [
                'Item', 'Type ID', 'Quantity', 'Corporation Owner', 'Location',
                'Region ID', 'Jita Best Sell Unit Price', 'Jita Total Sell Value',
                'Regional Best Sell Unit Price', 'Regional Total Sell Value', 'Last Seen',
            ]);

            $safeCell = static function ($value): string {
                $value = (string) ($value ?? '');
                return preg_match('/^[=+@\-]/', $value) === 1 ? "'" . $value : $value;
            };

            foreach ($query->cursor() as $asset) {
                $jita = $asset->jita_unit_price === null ? null : (float) $asset->jita_unit_price;
                $regional = $asset->regional_unit_price === null ? null : (float) $asset->regional_unit_price;
                fputcsv($handle, [
                    $safeCell($asset->type_name),
                    (int) $asset->type_id,
                    (int) $asset->quantity,
                    $safeCell($asset->corporation_name ?? ('Corporation ' . $corporationId)),
                    $safeCell($asset->location_name ?? $asset->location_id),
                    $asset->regional_region_id,
                    $jita,
                    $jita === null ? null : $jita * (int) $asset->quantity,
                    $regional,
                    $regional === null ? null : $regional * (int) $asset->quantity,
                    $safeCell($asset->last_seen_at),
                ]);
            }

            fclose($handle);
        }, 'assetmgr-corporation-' . $corporationId . '-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
