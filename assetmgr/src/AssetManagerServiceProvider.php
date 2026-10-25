<?php

namespace Triglavian\AssetManager;

use Illuminate\Support\ServiceProvider;
use Triglavian\AssetManager\Services\Assets\AssetSnapshotSource;
use Triglavian\AssetManager\Services\Assets\EsiAssetSnapshotSource;

class AssetManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AssetSnapshotSource::class, EsiAssetSnapshotSource::class);
        $this->mergeConfigFrom(__DIR__ . '/../config/assetmgr.php', 'assetmgr');
    }

    public function boot(): void
    {
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
