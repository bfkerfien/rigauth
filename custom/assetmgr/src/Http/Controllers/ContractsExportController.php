<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Triglavian\AssetManager\Services\Assets\CharacterPortfolioResolver;

class ContractsExportController extends Controller
{
    public function __invoke(Request $request, CharacterPortfolioResolver $portfolioResolver)
    {
        abort_unless(config('assetmgr.enabled', false), 404);
        try {
            $characterIds = $portfolioResolver->characterIdsFor($request->user());
        } catch (RuntimeException $exception) {
            abort(503, 'Asset Manager is not yet connected to SeAT character ownership.');
        }

        $query = DB::table('assetmgr_contracts')
            ->where('owner_kind', 'character')
            ->whereIn('owner_id', $characterIds);

        $characterId = $request->integer('character_id') ?: null;
        if ($characterId !== null) {
            if (! in_array($characterId, $characterIds, true)) $query->whereRaw('1 = 0');
            else $query->where('character_id', $characterId);
        }
        $status = trim((string) $request->query('status', ''));
        if ($status !== '') $query->where('status', $status);
        $type = trim((string) $request->query('type', ''));
        if ($type !== '') $query->where('contract_type', $type);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\\\') . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('title', 'like', $like)
                    ->orWhere('contract_id', 'like', $like)
                    ->orWhere('character_name', 'like', $like)
                    ->orWhere('status', 'like', $like);
            });
        }

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) return;

            fputcsv($handle, [
                'Character Owner', 'Contract ID', 'Type', 'Status', 'Availability', 'Title',
                'Date Issued', 'Date Expired', 'Start Location ID', 'End Location ID',
                'Price ISK', 'Reward ISK', 'Collateral ISK', 'Buyout ISK', 'Volume',
            ]);

            $safeCell = static function ($value): string {
                $value = (string) ($value ?? '');
                return preg_match('/^[=+@\-]/', $value) === 1 ? "'" . $value : $value;
            };

            foreach ($query->orderByDesc('date_issued')->orderByDesc('contract_id')->cursor() as $contract) {
                fputcsv($handle, [
                    $safeCell($contract->character_name ?? ('Character ' . $contract->character_id)),
                    $safeCell($contract->contract_id),
                    $safeCell($contract->contract_type),
                    $safeCell($contract->status),
                    $safeCell($contract->availability),
                    $safeCell($contract->title),
                    $safeCell($contract->date_issued),
                    $safeCell($contract->date_expired),
                    $contract->start_location_id,
                    $contract->end_location_id,
                    $contract->price,
                    $contract->reward,
                    $contract->collateral,
                    $contract->buyout,
                    $contract->volume,
                ]);
            }

            fclose($handle);
        }, 'assetmgr-contracts-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
