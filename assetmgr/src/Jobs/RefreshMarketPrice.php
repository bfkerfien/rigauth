<?php

namespace Triglavian\AssetManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Triglavian\AssetManager\Services\Pricing\MarketPriceSource;
use Triglavian\AssetManager\Services\Pricing\PriceCacheRepository;

class RefreshMarketPrice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(public readonly int $regionId, public readonly int $typeId)
    {
        if ($regionId < 1 || $typeId < 1) throw new \InvalidArgumentException('Invalid market price key.');
        $this->onQueue(config('assetmgr.sync.queue', 'default'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('assetmgr-price:' . $this->regionId . ':' . $this->typeId))->expireAfter(240)];
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(MarketPriceSource $source, PriceCacheRepository $prices): void
    {
        $cached = DB::table('assetmgr_prices')
            ->where('type_id', $this->typeId)
            ->where('region_id', $this->regionId)
            ->where('source', 'esi-orders')
            ->orderByDesc('fetched_at')
            ->first();

        if ($cached !== null) {
            if ($cached->source_expires_at && Carbon::parse($cached->source_expires_at)->isFuture()) return;
            if (! $cached->source_expires_at && Carbon::parse($cached->fetched_at)->addSeconds(
                (int) config('assetmgr.pricing.stale_after_seconds', 3600)
            )->isFuture()) return;
        }

        try {
            $snapshot = $source->fetch($this->regionId, $this->typeId);
            foreach (['buy' => $snapshot->bestBuy, 'sell' => $snapshot->bestSell] as $basis => $value) {
                $prices->store([
                    'type_id' => $snapshot->typeId,
                    'region_id' => $snapshot->regionId,
                    'source' => 'esi-orders',
                    'price_basis' => $basis,
                    'unit_price' => $value,
                    'source_expires_at' => $snapshot->sourceExpiresAt,
                    'fetched_at' => $snapshot->fetchedAt,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Asset Manager market price refresh failed.', [
                'region_id' => $this->regionId,
                'type_id' => $this->typeId,
                'exception' => get_class($exception),
            ]);
            throw $exception;
        }
    }
}
