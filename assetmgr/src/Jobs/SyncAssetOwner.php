<?php

namespace Triglavian\AssetManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Triglavian\AssetManager\Services\Assets\AssetSnapshotRepository;
use Triglavian\AssetManager\Services\Assets\AssetSnapshotSource;

class SyncAssetOwner implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(public readonly string $ownerKind, public readonly int $ownerId)
    {
        if (! in_array($ownerKind, ['character', 'corporation'], true) || $ownerId < 1) {
            throw new \InvalidArgumentException('Invalid asset sync owner.');
        }
        $this->onQueue(config('assetmgr.sync.queue', 'default'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('assetmgr:' . $this->ownerKind . ':' . $this->ownerId))->expireAfter(240)];
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(AssetSnapshotSource $source, AssetSnapshotRepository $repository): void
    {
        $existing = DB::table('assetmgr_sync_state')
            ->where('owner_kind', $this->ownerKind)
            ->where('owner_id', $this->ownerId)
            ->where('dataset', 'assets')
            ->first();

        if ($existing?->next_eligible_at && Carbon::parse($existing->next_eligible_at)->isFuture()) {
            return;
        }

        $now = now();
        DB::table('assetmgr_sync_state')->updateOrInsert(
            ['owner_kind' => $this->ownerKind, 'owner_id' => $this->ownerId, 'dataset' => 'assets'],
            [
                'status' => 'running', 'last_started_at' => $now,
                'error_code' => null, 'error_message' => null,
                'updated_at' => $now, 'created_at' => $now,
            ]
        );

        try {
            $snapshot = $source->fetchComplete($this->ownerKind, $this->ownerId);
            if (!$snapshot->allPagesFetched || $snapshot->ownerKind !== $this->ownerKind || $snapshot->ownerId !== $this->ownerId) {
                throw new \RuntimeException('Snapshot completeness or owner validation failed.');
            }

            $repository->replaceOwnerSnapshot($snapshot->ownerKind, $snapshot->ownerId, $snapshot->rows);

            DB::table('assetmgr_sync_state')
                ->where('owner_kind', $this->ownerKind)->where('owner_id', $this->ownerId)->where('dataset', 'assets')
                ->update([
                    'status' => 'success', 'last_success_at' => now(),
                    'pages_received' => $snapshot->pagesReceived,
                    'records_received' => count($snapshot->rows),
                    'next_eligible_at' => $snapshot->sourceExpiresAt
                        ? Carbon::parse($snapshot->sourceExpiresAt)->addSeconds(5)
                        : now()->addSeconds((int) config('assetmgr.sync.minimum_interval_seconds', 900)),
                    'error_code' => null, 'error_message' => null, 'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            DB::table('assetmgr_sync_state')
                ->where('owner_kind', $this->ownerKind)->where('owner_id', $this->ownerId)->where('dataset', 'assets')
                ->update([
                    'status' => 'failed',
                    'error_code' => (string) $exception->getCode(),
                    'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);

            Log::warning('Asset Manager sync failed; last good asset rows were retained.', [
                'owner_kind' => $this->ownerKind,
                'owner_id' => $this->ownerId,
                'exception' => get_class($exception),
            ]);
            throw $exception;
        }
    }
}
