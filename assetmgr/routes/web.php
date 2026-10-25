<?php

use Illuminate\\Support\\Facades\\Route;
use Triglavian\\AssetManager\\Http\\Controllers\\AssetIndexController;

/*
 * SeAT-specific middleware and permission identifiers must be confirmed against
 * the installed version before enabling this route in production.
 */
Route::middleware(['web', 'auth'])
    ->prefix('assetmgr')
    ->name('assetmgr.')
    ->group(function (): void {
        Route::get('/', AssetIndexController::class)
            ->name('index');
    });
