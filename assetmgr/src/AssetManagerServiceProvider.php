<?php

namespace Triglavian\AssetManager;

use Illuminate\Support\ServiceProvider;
use Triglavian\AssetManager\Console\Commands\SyncCharacters;
use Triglavian\AssetManager\Console\Commands\SyncPrices;
use Triglavian\AssetManager\Services\Assets\AssetSnapshotSource;
use Triglavian\AssetManager\Services\Assets\EsiAssetSnapshotSource;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;
use Triglavian\AssetManager\Services\Assets\SeatAccessTokenProvider;
use Triglavian\AssetManager\Services\Pricing\MarketPriceSource;
use Triglavian\AssetManager\Services\Pricing\EsiMarketPriceSource;

class AssetManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AssetSnapshotSource::class, EsiAssetSnapshotSource::class);
        $this->app->bind(AssetAccessTokenProvider::class, SeatAccessTokenProvider::class);
        $this->app->bind(MarketPriceSource::class, EsiMarketPriceSource::class);
        $this->mergeConfigFrom(__DIR__ . '/../config/assetmgr.php', 'assetmgr');
        $this->mergeConfigFrom(__DIR__ . '/../config/assetmgr.sidebar.php', 'package.sidebar');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncCharacters::class, SyncPrices::class]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'assetmgr');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/assetmgr.php' => config_path('assetmgr.php'),
            ], 'assetmgr-config');
        }
    }
}
