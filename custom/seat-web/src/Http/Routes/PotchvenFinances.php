<?php

use Illuminate\Support\Facades\Route;

Route::get('/potchven-finances', 'PotchvenFinancesController@index')
    ->name('seatcore::potchven-finances.index');
Route::post('/potchven-finances/sites', 'PotchvenFinancesController@storeSite')
    ->name('seatcore::potchven-finances.sites.store');
Route::post('/potchven-finances/timer/reset', 'PotchvenFinancesController@resetTimer')
    ->name('seatcore::potchven-finances.timer.reset');
