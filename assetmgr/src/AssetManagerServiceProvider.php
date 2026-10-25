<?php

namespace Triglavian\AssetManager;

use Seat\Services\AbstractSeatPlugin;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Triglavian\AssetManager\Console\Commands\SyncCharacters;
use Triglavian\AssetManager\Console\Commands\SyncPrices;
use Triglavian\AssetManager\Console\Commands\SyncCorporations;
use Triglavian\AssetManager\Console\Commands\CaptureValuations;
use Triglavian\AssetManager\Services\Assets\AssetSnapshotSource;
use Triglavian\AssetManager\Services\Assets\EsiAssetSnapshotSource;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;
use Triglavian\AssetManager\Services\Assets\SeatAccessTokenProvider;
use Triglavian\AssetManager\Services\Pricing\MarketPriceSource;
use Triglavian\AssetManager\Services\Pricing\EsiMarketPriceSource;

class AssetManagerServiceProvider extends AbstractSeatPlugin
{
    public function getName(): string
    {
        return 'Triglavian Asset Manager';
    }

    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/bfkerfien/rigauth/tree/main/assetmgr';
    }

    public function getPackagistPackageName(): string
    {
        return 'seat-asset-manager';
    }

    public function getPackagistVendorName(): string
    {
        return 'triglavian';
    }

    public function register(): void
    {
        $this->app->bind(AssetSnapshotSource::class, EsiAssetSnapshotSource::class);
        $this->app->bind(AssetAccessTokenProvider::class, SeatAccessTokenProvider::class);
        $this->app->bind(MarketPriceSource::class, EsiMarketPriceSource::class);
        $this->mergeConfigFrom(__DIR__ . '/../config/assetmgr.php', 'assetmgr');
        $this->registerPermissions(__DIR__ . '/../config/Permissions/corporation.php', 'corporation');
        $this->mergeConfigFrom(__DIR__ . '/../config/assetmgr.sidebar.php', 'package.sidebar');
    }

    public function boot(): void
    {
        Gate::define('assetmgr.enabled', static fn ($user): bool => (bool) config('assetmgr.enabled', false));
        RateLimiter::for('assetmgr-esi', static fn () => Limit::perMinute((int) config('assetmgr.sync.requests_per_minute', 30)));

        if ($this->app->runningInConsole()) {
            $this->commands([SyncCharacters::class, SyncPrices::class, SyncCorporations::class, CaptureValuations::class]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'assetmgr');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'assetmgr');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/assetmgr.php' => config_path('assetmgr.php'),
            ], 'assetmgr-config');
        }
    }
}
