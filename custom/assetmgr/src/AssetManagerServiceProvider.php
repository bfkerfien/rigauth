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
use Triglavian\AssetManager\Console\Commands\SyncWalletJournals;
use Triglavian\AssetManager\Console\Commands\SyncWalletTransactions;
use Triglavian\AssetManager\Console\Commands\SyncMarketOrders;
use Triglavian\AssetManager\Console\Commands\SyncCorporationMarketOrders;
use Triglavian\AssetManager\Console\Commands\SyncCorporationWalletJournals;
use Triglavian\AssetManager\Console\Commands\SyncContracts;
use Triglavian\AssetManager\Services\Assets\AssetSnapshotSource;
use Triglavian\AssetManager\Services\Assets\EsiAssetSnapshotSource;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;
use Triglavian\AssetManager\Services\Assets\SeatAccessTokenProvider;
use Triglavian\AssetManager\Services\Pricing\MarketPriceSource;
use Triglavian\AssetManager\Services\Pricing\EsiMarketPriceSource;
use Triglavian\AssetManager\Services\Finance\WalletJournalSource;
use Triglavian\AssetManager\Services\Finance\EsiWalletJournalSource;
use Triglavian\AssetManager\Services\Finance\WalletTransactionSource;
use Triglavian\AssetManager\Services\Finance\EsiWalletTransactionSource;
use Triglavian\AssetManager\Services\Finance\CorporationWalletJournalSource;
use Triglavian\AssetManager\Services\Finance\EsiCorporationWalletJournalSource;
use Triglavian\AssetManager\Services\Markets\MarketOrderSource;
use Triglavian\AssetManager\Services\Markets\EsiMarketOrderSource;
use Triglavian\AssetManager\Services\Markets\CorporationMarketOrderSource;
use Triglavian\AssetManager\Services\Markets\EsiCorporationMarketOrderSource;
use Triglavian\AssetManager\Services\Contracts\ContractSource;
use Triglavian\AssetManager\Services\Contracts\EsiContractSource;

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
        $this->app->bind(WalletJournalSource::class, EsiWalletJournalSource::class);
        $this->app->bind(WalletTransactionSource::class, EsiWalletTransactionSource::class);
        $this->app->bind(CorporationWalletJournalSource::class, EsiCorporationWalletJournalSource::class);
        $this->app->bind(MarketOrderSource::class, EsiMarketOrderSource::class);
        $this->app->bind(CorporationMarketOrderSource::class, EsiCorporationMarketOrderSource::class);
        $this->app->bind(ContractSource::class, EsiContractSource::class);
        $this->mergeConfigFrom(__DIR__ . '/../config/assetmgr.php', 'assetmgr');
        $this->registerPermissions(__DIR__ . '/../config/Permissions/corporation.php', 'corporation');
        $this->registerPermissions(__DIR__ . '/../config/Permissions/corporation_market_orders.php', 'corporation');
        $this->registerPermissions(__DIR__ . '/../config/Permissions/corporation_wallet_journal.php', 'corporation');
        $this->mergeConfigFrom(__DIR__ . '/../config/assetmgr.sidebar.php', 'package.sidebar');
    }

    public function boot(): void
    {
        Gate::define('assetmgr.enabled', static fn ($user): bool => (bool) config('assetmgr.enabled', false));
        RateLimiter::for('assetmgr-esi', static fn () => Limit::perMinute((int) config('assetmgr.sync.requests_per_minute', 30)));

        if ($this->app->runningInConsole()) {
            $this->commands([SyncCharacters::class, SyncPrices::class, SyncCorporations::class, CaptureValuations::class, SyncWalletJournals::class, SyncWalletTransactions::class, SyncMarketOrders::class, SyncCorporationMarketOrders::class, SyncCorporationWalletJournals::class, SyncContracts::class]);
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
