<?php

use Illuminate\Support\Facades\Route;
use Triglavian\AssetManager\Http\Controllers\AssetExportController;
use Triglavian\AssetManager\Http\Controllers\AssetIndexController;

Route::middleware(['web', 'auth'])
    ->prefix('assetmgr')
    ->name('assetmgr.')
    ->group(function (): void {
        Route::get('/export.csv', AssetExportController::class)->name('export');
        Route::get('/', AssetIndexController::class)->name('index');
    });
