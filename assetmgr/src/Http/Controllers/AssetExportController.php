<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Triglavian\AssetManager\Services\Assets\AssetQuery;
use Triglavian\AssetManager\Services\Assets\CharacterPortfolioResolver;

class AssetExportController extends Controller
{
    public function __invoke(Request $request, CharacterPortfolioResolver $portfolioResolver, AssetQuery $assetQuery)
    {
        abort_unless(config('assetmgr.enabled', false), 404);

        try {
            $characterIds = $portfolioResolver->characterIdsFor($request->user());
        } catch (RuntimeException $exception) {
            abort(503, 'Asset Manager is not yet connected to SeAT character ownership.');
        }

        $filters = [
            'q' => $request->query('q', ''),
            'character_id' => $request->integer('character_id') ?: null,
            'parent_item_id' => $request->integer('parent_item_id') ?: null,
            'sort' => $request->query('sort', 'character'),
            'direction' => $request->query('direction', 'asc'),
        ];
        $query = $assetQuery->personal($characterIds, $filters);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) return;

            fputcsv($handle, [
                'Item', 'Type ID', 'Quantity', 'Character Owner', 'Location',
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
                    $safeCell($asset->character_name ?? ('Character ' . $asset->character_id)),
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
        }, 'assetmgr-assets-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
