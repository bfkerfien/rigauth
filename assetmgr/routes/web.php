<?php

use Illuminate\Support\Facades\Route;
use Triglavian\AssetManager\Http\Controllers\AssetExportController;
use Triglavian\AssetManager\Http\Controllers\AssetIndexController;
use Triglavian\AssetManager\Http\Controllers\AssetRefreshController;
use Triglavian\AssetManager\Http\Controllers\CorporationAssetIndexController;
use Triglavian\AssetManager\Http\Controllers\CorporationAssetExportController;
use Triglavian\AssetManager\Http\Controllers\WalletJournalController;
use Triglavian\AssetManager\Http\Controllers\WalletJournalRefreshController;
use Triglavian\AssetManager\Http\Controllers\WalletJournalExportController;
use Triglavian\AssetManager\Http\Controllers\WalletTransactionsController;
use Triglavian\AssetManager\Http\Controllers\WalletTransactionsExportController;
use Triglavian\AssetManager\Http\Controllers\MarketOrdersController;
use Triglavian\AssetManager\Http\Controllers\MarketOrdersExportController;
use Triglavian\AssetManager\Http\Controllers\MarketOrdersRefreshController;
use Triglavian\AssetManager\Http\Controllers\CorporationMarketOrdersController;
use Triglavian\AssetManager\Http\Controllers\CorporationMarketOrdersExportController;
use Triglavian\AssetManager\Http\Controllers\CorporationMarketOrdersRefreshController;
use Triglavian\AssetManager\Http\Controllers\CorporationMarketOrdersController;
use Triglavian\AssetManager\Http\Controllers\CorporationMarketOrdersExportController;
use Triglavian\AssetManager\Http\Controllers\CorporationMarketOrdersRefreshController;

Route::middleware(['web', 'auth'])
    ->prefix('assetmgr')
    ->name('assetmgr.')
    ->group(function (): void {
        Route::post('/refresh', AssetRefreshController::class)->middleware('throttle:5,1')->name('refresh');
        Route::post('/wallet-journal/refresh', WalletJournalRefreshController::class)->middleware('throttle:5,1')->name('wallet-journal.refresh');
        Route::post('/market-orders/refresh', MarketOrdersRefreshController::class)->middleware('throttle:5,1')->name('market-orders.refresh');
        Route::get('/wallet-journal/export.csv', WalletJournalExportController::class)->name('wallet-journal.export');
        Route::get('/wallet-transactions/export.csv', WalletTransactionsExportController::class)->name('wallet-transactions.export');
        Route::get('/market-orders/export.csv', MarketOrdersExportController::class)->name('market-orders.export');
        Route::get('/market-orders', MarketOrdersController::class)->name('market-orders');
        Route::get('/wallet-transactions', WalletTransactionsController::class)->name('wallet-transactions');
        Route::get('/wallet-journal', WalletJournalController::class)->name('wallet-journal');
        Route::get('/export.csv', AssetExportController::class)->name('export');
        Route::post('/corporations/{corporationId}/market-orders/refresh', CorporationMarketOrdersRefreshController::class)->middleware('throttle:5,1')->name('corporation-market-orders.refresh');
        Route::get('/corporations/{corporationId}/market-orders/export.csv', CorporationMarketOrdersExportController::class)->name('corporation-market-orders.export');
        Route::get('/corporations/{corporationId}/market-orders', CorporationMarketOrdersController::class)->name('corporation-market-orders');
        Route::get('/corporations/export.csv', CorporationAssetExportController::class)->name('corporation.export');
        Route::get('/corporations', CorporationAssetIndexController::class)->name('corporation.index');
        Route::get('/', AssetIndexController::class)->name('index');
    });
