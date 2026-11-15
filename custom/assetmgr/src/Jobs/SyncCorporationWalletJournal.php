<?php

namespace Triglavian\AssetManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Triglavian\AssetManager\Services\Finance\CorporationWalletJournalSource;

class SyncCorporationWalletJournal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(public readonly int $corporationId, public readonly int $division)
    {
        if ($corporationId < 1 || $division < 1 || $division > 7) {
            throw new \InvalidArgumentException('Invalid corporation wallet journal owner.');
        }
        $this->onQueue(config('assetmgr.sync.queue', 'default'));
    }

    private function dataset(): string
    {
        return 'corp_wallet_journal_' . $this->division;
    }

    public function middleware(): array
    {
        return [
            new RateLimited('assetmgr-esi'),
            (new WithoutOverlapping('assetmgr-corp-wallet-journal:' . $this->corporationId . ':' . $this->division))->expireAfter(360),
        ];
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(CorporationWalletJournalSource $source): void
    {
        abort_unless(
            config('assetmgr.corporation_sync_enabled', false)
            && in_array($this->corporationId, config('assetmgr.allowed_corporation_ids', []), true),
            403
        );

        $dataset = $this->dataset();
        $state = DB::table('assetmgr_sync_state')
            ->where('owner_kind', 'corporation')
            ->where('owner_id', $this->corporationId)
            ->where('dataset', $dataset)
            ->first();
        if ($state?->next_eligible_at && Carbon::parse($state->next_eligible_at)->isFuture()) return;

        DB::table('assetmgr_sync_state')->updateOrInsert(
            ['owner_kind' => 'corporation', 'owner_id' => $this->corporationId, 'dataset' => $dataset],
            [
                'status' => 'running',
                'last_started_at' => now(),
                'http_status' => null,
                'error_code' => null,
                'error_message' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        try {
            $lastKnownId = DB::table('assetmgr_financial_records')
                ->where('owner_kind', 'corporation')
                ->where('owner_id', $this->corporationId)
                ->where('record_kind', 'corporation_wallet_journal')
                ->where('wallet_division', $this->division)
                ->orderByRaw('LENGTH(source_record_id) DESC')
                ->orderByDesc('source_record_id')
                ->value('source_record_id');

            $batch = $source->fetchSince($this->corporationId, $this->division, $lastKnownId === null ? null : (string) $lastKnownId);
            if ($batch->corporationId !== $this->corporationId || $batch->division !== $this->division) {
                throw new RuntimeException('Corporation wallet journal source returned the wrong owner or division.');
            }

            $expiry = $batch->sourceExpiresAt ? Carbon::parse($batch->sourceExpiresAt) : null;
            $nextEligibleAt = $expiry && $expiry->isFuture()
                ? $expiry->addSeconds(5)
                : now()->addSeconds((int) config('assetmgr.sync.minimum_interval_seconds', 900));

            DB::transaction(function () use ($batch, $dataset, $nextEligibleAt): void {
                foreach (array_chunk($batch->rows, 500) as $chunk) {
                    $rows = array_map(function (array $row) use ($batch): array {
                        return [
                            'owner_kind' => 'corporation',
                            'owner_id' => $batch->corporationId,
                            'character_id' => null,
                            'character_name' => null,
                            'corporation_id' => $batch->corporationId,
                            'corporation_name' => $row['corporation_name'] ?? null,
                            'wallet_division' => $batch->division,
                            'record_kind' => 'corporation_wallet_journal',
                            'external_id' => 'd' . $batch->division . ':' . (string) $row['external_id'],
                            'source_record_id' => (string) $row['source_record_id'],
                            'record_at' => $row['record_at'],
                            'reference_type' => $row['reference_type'],
                            'first_party_id' => $row['first_party_id'] ?? null,
                            'second_party_id' => $row['second_party_id'] ?? null,
                            'amount' => $row['amount'] ?? null,
                            'balance' => $row['balance'] ?? null,
                            'reason' => $row['reason'] ?? null,
                            'description' => $row['description'] ?? null,
                            'context_id' => $row['context_id'] ?? null,
                            'context_id_type' => $row['context_id_type'] ?? null,
                            'payload' => $row['payload'] ?? null,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ];
                    }, $chunk);

                    DB::table('assetmgr_financial_records')->upsert(
                        $rows,
                        ['owner_kind', 'owner_id', 'record_kind', 'external_id'],
                        [
                            'corporation_id', 'corporation_name', 'wallet_division', 'source_record_id',
                            'record_at', 'reference_type', 'first_party_id', 'second_party_id',
                            'amount', 'balance', 'reason', 'description', 'context_id', 'context_id_type',
                            'payload', 'updated_at',
                        ]
                    );
                }

                DB::table('assetmgr_sync_state')
                    ->where('owner_kind', 'corporation')
                    ->where('owner_id', $batch->corporationId)
                    ->where('dataset', $dataset)
                    ->update([
                        'status' => 'success',
                        'last_success_at' => now(),
                        'next_eligible_at' => $nextEligibleAt,
                        'pages_received' => $batch->pagesReceived,
                        'records_received' => count($batch->rows),
                        'error_code' => null,
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
            });
        } catch (Throwable $exception) {
            DB::table('assetmgr_sync_state')
                ->where('owner_kind', 'corporation')
                ->where('owner_id', $this->corporationId)
                ->where('dataset', $dataset)
                ->update([
                    'status' => 'failed',
                    'error_code' => get_class($exception),
                    'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                    'updated_at' => now(),
                ]);

            Log::warning('Asset Manager corporation wallet journal sync failed.', [
                'corporation_id' => $this->corporationId,
                'division' => $this->division,
                'exception' => get_class($exception),
            ]);
            throw $exception;
        }
    }
}
