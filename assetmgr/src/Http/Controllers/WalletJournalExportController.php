<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Triglavian\AssetManager\Services\Assets\CharacterPortfolioResolver;

class WalletJournalExportController extends Controller
{
    public function __invoke(Request $request, CharacterPortfolioResolver $portfolioResolver)
    {
        abort_unless(config('assetmgr.enabled', false), 404);

        try {
            $characterIds = $portfolioResolver->characterIdsFor($request->user());
        } catch (RuntimeException $exception) {
            abort(503, 'Asset Manager is not yet connected to SeAT character ownership.');
        }

        $query = DB::table('assetmgr_financial_records')
            ->where('owner_kind', 'character')
            ->where('record_kind', 'wallet_journal')
            ->whereIn('owner_id', $characterIds);

        $characterId = $request->integer('character_id') ?: null;
        if ($characterId !== null) {
            if (! in_array($characterId, $characterIds, true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('character_id', $characterId);
            }
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\\\') . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('description', 'like', $like)
                    ->orWhere('reason', 'like', $like)
                    ->orWhere('reference_type', 'like', $like)
                    ->orWhere('first_party_id', 'like', $like)
                    ->orWhere('second_party_id', 'like', $like);
            });
        }

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) return;

            fputcsv($handle, [
                'Date', 'Character Owner', 'Reference Type', 'Description', 'Amount ISK',
                'Balance ISK', 'First Party ID', 'Second Party ID', 'Journal Entry ID',
            ]);

            $safeCell = static function ($value): string {
                $value = (string) ($value ?? '');
                return preg_match('/^[=+@\-]/', $value) === 1 ? "'" . $value : $value;
            };

            foreach ($query->orderByDesc('record_at')->orderByDesc('external_id')->cursor() as $record) {
                fputcsv($handle, [
                    $safeCell($record->record_at),
                    $safeCell($record->character_name ?? ('Character ' . $record->character_id)),
                    $safeCell($record->reference_type),
                    $safeCell($record->description ?? $record->reason),
                    $record->amount,
                    $record->balance,
                    $record->first_party_id,
                    $record->second_party_id,
                    $safeCell($record->external_id),
                ]);
            }

            fclose($handle);
        }, 'assetmgr-wallet-journal-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
