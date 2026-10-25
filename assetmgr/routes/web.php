<?php

use Illuminate\Support\Facades\Route;
use Triglavian\AssetManager\Http\Controllers\AssetExportController;
use Triglavian\AssetManager\Http\Controllers\AssetIndexController;
use Triglavian\AssetManager\Http\Controllers\CorporationAssetIndexController;
use Triglavian\AssetManager\Http\Controllers\CorporationAssetExportController;

Route::middleware(['web', 'auth'])
    ->prefix('assetmgr')
    ->name('assetmgr.')
    ->group(function (): void {
        Route::get('/export.csv', AssetExportController::class)->name('export');
        Route::get('/corporations/export.csv', CorporationAssetExportController::class)->name('corporation.export');
        Route::get('/corporations', CorporationAssetIndexController::class)->name('corporation.index');
        Route::get('/', AssetIndexController::class)->name('index');
    });
