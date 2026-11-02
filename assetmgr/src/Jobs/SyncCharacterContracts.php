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
use Triglavian\AssetManager\Services\Contracts\ContractSource;

class SyncCharacterContracts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(public readonly int $characterId)
    {
        if ($characterId < 1) throw new \InvalidArgumentException('Invalid character ID.');
        $this->onQueue(config('assetmgr.sync.queue', 'default'));
    }

    public function middleware(): array
    {
        return [
            new RateLimited('assetmgr-esi'),
            (new WithoutOverlapping('assetmgr-contracts:' . $this->characterId))->expireAfter(360),
        ];
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ContractSource $source): void
    {
        $state = DB::table('assetmgr_sync_state')
            ->where('owner_kind', 'character')
            ->where('owner_id', $this->characterId)
            ->where('dataset', 'contracts')
            ->first();
        if ($state?->next_eligible_at && Carbon::parse($state->next_eligible_at)->isFuture()) return;

        DB::table('assetmgr_sync_state')->updateOrInsert(
            ['owner_kind' => 'character', 'owner_id' => $this->characterId, 'dataset' => 'contracts'],
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
            $snapshot = $source->fetchCharacterContracts($this->characterId);
            if ($snapshot->characterId !== $this->characterId || ! $snapshot->allPagesFetched) {
                throw new RuntimeException('Contract source returned an incomplete or mismatched snapshot.');
            }

            $nextEligibleAt = $snapshot->sourceExpiresAt
                ? Carbon::parse($snapshot->sourceExpiresAt)->addSeconds(5)
                : now()->addSeconds((int) config('assetmgr.sync.minimum_interval_seconds', 900));

            DB::transaction(function () use ($snapshot, $nextEligibleAt): void {
                foreach (array_chunk($snapshot->rows, 500) as $chunk) {
                    $rows = array_map(static function (array $row) use ($snapshot): array {
                        return [
                            'owner_kind' => 'character',
                            'owner_id' => $snapshot->characterId,
                            'character_id' => $snapshot->characterId,
                            'character_name' => $row['character_name'] ?? null,
                            'corporation_id' => null,
                            'corporation_name' => null,
                            'contract_id' => (string) $row['contract_id'],
                            'issuer_id' => $row['issuer_id'] ?? null,
                            'issuer_corporation_id' => $row['issuer_corporation_id'] ?? null,
                            'assignee_id' => $row['assignee_id'] ?? null,
                            'acceptor_id' => $row['acceptor_id'] ?? null,
                            'contract_type' => $row['contract_type'] ?? null,
                            'availability' => $row['availability'] ?? null,
                            'status' => $row['status'] ?? null,
                            'title' => $row['title'] ?? null,
                            'for_corporation' => false,
                            'start_location_id' => $row['start_location_id'] ?? null,
                            'end_location_id' => $row['end_location_id'] ?? null,
                            'date_issued' => $row['date_issued'] ?? null,
                            'date_expired' => $row['date_expired'] ?? null,
                            'date_accepted' => $row['date_accepted'] ?? null,
                            'date_completed' => $row['date_completed'] ?? null,
                            'days_to_complete' => $row['days_to_complete'] ?? null,
                            'price' => $row['price'] ?? null,
                            'reward' => $row['reward'] ?? null,
                            'collateral' => $row['collateral'] ?? null,
                            'buyout' => $row['buyout'] ?? null,
                            'volume' => $row['volume'] ?? null,
                            'last_seen_at' => now(),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ];
                    }, $chunk);

                    DB::table('assetmgr_contracts')->upsert(
                        $rows,
                        ['owner_kind', 'owner_id', 'contract_id'],
                        [
                            'character_id', 'character_name', 'issuer_id', 'issuer_corporation_id',
                            'assignee_id', 'acceptor_id', 'contract_type', 'availability', 'status',
                            'title', 'start_location_id', 'end_location_id', 'date_issued',
                            'date_expired', 'date_accepted', 'date_completed', 'days_to_complete',
                            'price', 'reward', 'collateral', 'buyout', 'volume', 'last_seen_at', 'updated_at',
                        ]
                    );
                }

                DB::table('assetmgr_sync_state')
                    ->where('owner_kind', 'character')
                    ->where('owner_id', $snapshot->characterId)
                    ->where('dataset', 'contracts')
                    ->update([
                        'status' => 'success',
                        'last_success_at' => now(),
                        'next_eligible_at' => $nextEligibleAt,
                        'pages_received' => $snapshot->pagesReceived,
                        'records_received' => count($snapshot->rows),
                        'error_code' => null,
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
            });
        } catch (Throwable $exception) {
            DB::table('assetmgr_sync_state')
                ->where('owner_kind', 'character')
                ->where('owner_id', $this->characterId)
                ->where('dataset', 'contracts')
                ->update([
                    'status' => 'failed',
                    'error_code' => get_class($exception),
                    'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                    'updated_at' => now(),
                ]);

            Log::warning('Asset Manager contract sync failed.', [
                'character_id' => $this->characterId,
                'exception' => get_class($exception),
            ]);
            throw $exception;
        }
    }
}
