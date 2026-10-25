<?php

namespace Triglavian\AssetManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class CorporationWalletJournalExportController extends Controller
{
    public function __invoke(Request $request, int $corporationId)
    {
        abort_unless(config('assetmgr.enabled', false) && config('assetmgr.corporation_sync_enabled', false), 404);
        abort_unless(in_array($corporationId, config('assetmgr.allowed_corporation_ids', []), true), 404);

        $corporation = CorporationInfo::query()->where('corporation_id', $corporationId)->first();
        abort_unless($corporation !== null, 404);
        Gate::authorize('corporation.assetmgr_wallet_journal_view', $corporation);

        $query = DB::table('assetmgr_financial_records')
            ->where('owner_kind', 'corporation')
            ->where('owner_id', $corporationId)
            ->where('record_kind', 'corporation_wallet_journal');

        $division = $request->integer('division') ?: null;
        if ($division !== null) {
            if ($division < 1 || $division > 7) $query->whereRaw('1 = 0');
            else $query->where('wallet_division', $division);
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

        return response()->streamDownload(function () use ($query, $corporationId): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) return;

            fputcsv($handle, [
                'Corporation Owner', 'Wallet Division', 'Date', 'Reference Type', 'Description',
                'Amount ISK', 'Balance ISK', 'First Party ID', 'Second Party ID', 'Journal Entry ID',
            ]);

            $safeCell = static function ($value): string {
                $value = (string) ($value ?? '');
                return preg_match('/^[=+@\-]/', $value) === 1 ? "'" . $value : $value;
            };

            foreach ($query->orderByDesc('record_at')->orderByDesc('external_id')->cursor() as $record) {
                fputcsv($handle, [
                    $safeCell($record->corporation_name ?? ('Corporation ' . $corporationId)),
                    $record->wallet_division,
                    $safeCell($record->record_at),
                    $safeCell($record->reference_type),
                    $safeCell($record->description ?? $record->reason),
                    $record->amount,
                    $record->balance,
                    $record->first_party_id,
                    $record->second_party_id,
                    $safeCell($record->source_record_id),
                ]);
            }

            fclose($handle);
        }, 'assetmgr-corporation-wallet-journal-' . $corporationId . '-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
