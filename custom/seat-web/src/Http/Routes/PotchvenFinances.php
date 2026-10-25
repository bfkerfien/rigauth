<?php

use Illuminate\Support\Facades\Route;

Route::get('/potchven-finances', 'PotchvenFinancesController@index')
    ->name('seatcore::potchven-finances.index');
Route::post('/potchven-finances/sites', 'PotchvenFinancesController@storeSite')
    ->name('seatcore::potchven-finances.sites.store');
Route::post('/potchven-finances/timer/reset', 'PotchvenFinancesController@resetTimer')
    ->name('seatcore::potchven-finances.timer.reset');
Route::post('/potchven-finances/srp/add-site-without-loot', 'PotchvenFinancesController@addSiteWithoutLoot')
    ->name('seatcore::potchven-finances.srp.add-site-without-loot');
Route::post('/potchven-finances/srp/transfer', 'PotchvenFinancesController@transferToSrp')
    ->name('seatcore::potchven-finances.srp.transfer');
