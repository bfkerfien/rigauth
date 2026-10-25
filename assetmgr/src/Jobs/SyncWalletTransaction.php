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
use Triglavian\AssetManager\Services\Finance\WalletTransactionSource;

class SyncWalletTransaction implements ShouldQueue
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
            (new WithoutOverlapping('assetmgr-wallet-transactions:' . $this->characterId))->expireAfter(360),
        ];
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(WalletTransactionSource $source): void
    {
        $state = DB::table('assetmgr_sync_state')
            ->where('owner_kind', 'character')
            ->where('owner_id', $this->characterId)
            ->where('dataset', 'wallet_transactions')
            ->first();

        if ($state?->next_eligible_at && Carbon::parse($state->next_eligible_at)->isFuture()) return;

        DB::table('assetmgr_sync_state')->updateOrInsert(
            ['owner_kind' => 'character', 'owner_id' => $this->characterId, 'dataset' => 'wallet_transactions'],
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
                ->where('owner_kind', 'character')
                ->where('owner_id', $this->characterId)
                ->where('record_kind', 'wallet_transaction')
                ->orderByRaw('LENGTH(external_id) DESC')
                ->orderByDesc('external_id')
                ->value('external_id');

            $snapshot = $source->fetchSince($this->characterId, $lastKnownId === null ? null : (string) $lastKnownId);
            if ($snapshot->characterId !== $this->characterId) {
                throw new RuntimeException('Wallet transaction source returned a snapshot for a different character.');
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
                            'record_kind' => 'wallet_transaction',
                            'external_id' => (string) $row['external_id'],
                            'record_at' => $row['record_at'] ?? null,
                            'reference_type' => $row['reference_type'] ?? null,
                            'type_id' => $row['type_id'] ?? null,
                            'type_name' => $row['type_name'] ?? null,
                            'location_id' => $row['location_id'] ?? null,
                            'location_name' => $row['location_name'] ?? null,
                            'quantity' => $row['quantity'] ?? null,
                            'unit_price' => $row['unit_price'] ?? null,
                            'is_buy' => $row['is_buy'] ?? null,
                            'first_party_id' => $row['first_party_id'] ?? null,
                            'second_party_id' => $row['second_party_id'] ?? null,
                            'amount' => $row['amount'] ?? null,
                            'balance' => null,
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
                            'character_id', 'character_name', 'record_at', 'reference_type',
                            'type_id', 'type_name', 'location_id', 'location_name', 'quantity',
                            'unit_price', 'is_buy', 'first_party_id', 'second_party_id',
                            'amount', 'reason', 'description', 'context_id', 'context_id_type',
                            'payload', 'updated_at',
                        ]
                    );
                }

                DB::table('assetmgr_sync_state')
                    ->where('owner_kind', 'character')
                    ->where('owner_id', $snapshot->characterId)
                    ->where('dataset', 'wallet_transactions')
                    ->update([
                        'status' => 'success',
                        'last_success_at' => now(),
                        'next_eligible_at' => $nextEligibleAt,
                        'pages_received' => $snapshot->requestsFetched,
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
                ->where('dataset', 'wallet_transactions')
                ->update([
                    'status' => 'failed',
                    'error_code' => get_class($exception),
                    'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                    'updated_at' => now(),
                ]);

            Log::warning('Asset Manager wallet transaction sync failed.', [
                'character_id' => $this->characterId,
                'exception' => get_class($exception),
            ]);
            throw $exception;
        }
    }
}
