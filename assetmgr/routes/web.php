<?php

use Illuminate\Support\Facades\Route;
use Triglavian\AssetManager\Http\Controllers\AssetExportController;
use Triglavian\AssetManager\Http\Controllers\AssetIndexController;
use Triglavian\AssetManager\Http\Controllers\AssetRefreshController;
use Triglavian\AssetManager\Http\Controllers\CorporationAssetIndexController;
use Triglavian\AssetManager\Http\Controllers\CorporationAssetExportController;
use Triglavian\AssetManager\Http\Controllers\WalletJournalController;
use Triglavian\AssetManager\Http\Controllers\WalletJournalRefreshController;

Route::middleware(['web', 'auth'])
    ->prefix('assetmgr')
    ->name('assetmgr.')
    ->group(function (): void {
        Route::post('/refresh', AssetRefreshController::class)->middleware('throttle:5,1')->name('refresh');
        Route::post('/wallet-journal/refresh', WalletJournalRefreshController::class)->middleware('throttle:5,1')->name('wallet-journal.refresh');
        Route::get('/wallet-journal', WalletJournalController::class)->name('wallet-journal');
        Route::get('/export.csv', AssetExportController::class)->name('export');
        Route::get('/corporations/export.csv', CorporationAssetExportController::class)->name('corporation.export');
        Route::get('/corporations', CorporationAssetIndexController::class)->name('corporation.index');
        Route::get('/', AssetIndexController::class)->name('index');
    });
