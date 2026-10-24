<?php

use Illuminate\Support\Facades\Route;

Route::get('/market', 'MarketController@index')
    ->name('seatcore::market.index');
