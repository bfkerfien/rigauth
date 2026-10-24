<?php

use Illuminate\Support\Facades\Route;

Route::get('/market', 'MarketController@index')
    ->name('seatcore::market.index');

/*
 * Short-lived, signed troubleshooting access. The generated URL expires;
 * after redemption, only this browser session gets a 15-minute Market grant.
 */
Route::get('/market/temporary-access', 'MarketController@temporaryAccess')
    ->middleware('signed')
    ->name('seatcore::market.temporary-access');
