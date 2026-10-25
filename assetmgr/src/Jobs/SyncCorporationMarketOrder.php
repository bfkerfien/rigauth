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
use Triglavian\AssetManager\Services\Markets\CorporationMarketOrderSource;

class SyncCorporationMarketOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(public readonly int $corporationId)
    {
        if ($corporationId < 1) throw new \InvalidArgumentException('Invalid corporation ID.');
        $this->onQueue(config('assetmgr.sync.queue', 'default'));
    }

    public function middleware(): array
    {
        return [
            new RateLimited('assetmgr-esi'),
            (new WithoutOverlapping('assetmgr-corporation-market-orders:' . $this->corporationId))->expireAfter(360),
        ];
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(CorporationMarketOrderSource $source): void
    {
        abort_unless(config('assetmgr.corporation_sync_enabled', false) && in_array($this->corporationId, config('assetmgr.allowed_corporation_ids', []), true), 403);

        $state = DB::table('assetmgr_sync_state')
            ->where('owner_kind', 'corporation')
            ->where('owner_id', $this->corporationId)
            ->where('dataset', 'corporation_market_orders')
            ->first();
        if ($state?->next_eligible_at && Carbon::parse($state->next_eligible_at)->isFuture()) return;

        DB::table('assetmgr_sync_state')->updateOrInsert(
            ['owner_kind' => 'corporation', 'owner_id' => $this->corporationId, 'dataset' => 'corporation_market_orders'],
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
            $snapshot = $source->fetchComplete($this->corporationId);
            if ($snapshot->corporationId !== $this->corporationId || ! $snapshot->allPagesFetched) {
                throw new RuntimeException('Corporation market order source returned an incomplete or mismatched snapshot.');
            }

            $nextEligibleAt = $snapshot->sourceExpiresAt
                ? Carbon::parse($snapshot->sourceExpiresAt)->addSeconds(5)
                : now()->addSeconds((int) config('assetmgr.sync.minimum_interval_seconds', 900));

            DB::transaction(function () use ($snapshot, $nextEligibleAt): void {
                foreach (array_chunk($snapshot->rows, 500) as $chunk) {
                    $rows = array_map(static function (array $row) use ($snapshot): array {
                        return [
                            'owner_kind' => 'corporation',
                            'owner_id' => $snapshot->corporationId,
                            'character_id' => null,
                            'character_name' => null,
                            'corporation_id' => $snapshot->corporationId,
                            'corporation_name' => $row['corporation_name'] ?? null,
                            'issued_by' => $row['issued_by'] ?? null,
                            'wallet_division' => $row['wallet_division'] ?? null,
                            'order_id' => (string) $row['order_id'],
                            'type_id' => (int) $row['type_id'],
                            'type_name' => $row['type_name'] ?? null,
                            'location_id' => (int) $row['location_id'],
                            'location_name' => $row['location_name'] ?? null,
                            'region_id' => $row['region_id'] ?? null,
                            'is_buy_order' => (bool) $row['is_buy_order'],
                            'is_corporation_order' => true,
                            'price' => $row['price'],
                            'volume_total' => (int) $row['volume_total'],
                            'volume_remain' => (int) $row['volume_remain'],
                            'duration' => $row['duration'] ?? null,
                            'order_range' => $row['order_range'] ?? null,
                            'min_volume' => $row['min_volume'] ?? null,
                            'escrow' => $row['escrow'] ?? null,
                            'issued' => $row['issued'] ?? null,
                            'last_seen_at' => now(),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ];
                    }, $chunk);

                    DB::table('assetmgr_market_orders')->upsert(
                        $rows,
                        ['owner_kind', 'owner_id', 'order_id'],
                        [
                            'character_id', 'character_name', 'corporation_id', 'corporation_name',
                            'issued_by', 'wallet_division', 'type_id', 'type_name', 'location_id',
                            'location_name', 'region_id', 'is_buy_order', 'is_corporation_order',
                            'price', 'volume_total', 'volume_remain', 'duration', 'order_range',
                            'min_volume', 'escrow', 'issued', 'last_seen_at', 'updated_at',
                        ]
                    );
                }

                $deleteMissing = DB::table('assetmgr_market_orders')
                    ->where('owner_kind', 'corporation')
                    ->where('owner_id', $snapshot->corporationId);
                if ($snapshot->rows !== []) {
                    $deleteMissing->whereNotIn('order_id', array_column($snapshot->rows, 'order_id'));
                }
                $deleteMissing->delete();

                DB::table('assetmgr_sync_state')
                    ->where('owner_kind', 'corporation')
                    ->where('owner_id', $snapshot->corporationId)
                    ->where('dataset', 'corporation_market_orders')
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
                ->where('owner_kind', 'corporation')
                ->where('owner_id', $this->corporationId)
                ->where('dataset', 'corporation_market_orders')
                ->update([
                    'status' => 'failed',
                    'error_code' => get_class($exception),
                    'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                    'updated_at' => now(),
                ]);

            Log::warning('Asset Manager corporation market order sync failed.', [
                'corporation_id' => $this->corporationId,
                'exception' => get_class($exception),
            ]);
            throw $exception;
        }
    }
}
