<?php

use Illuminate\Support\Facades\Route;

Route::get('/potchven-finances', 'PotchvenFinancesController@index')
    ->name('seatcore::potchven-finances.index');
Route::post('/potchven-finances/sites', 'PotchvenFinancesController@storeSite')
    ->name('seatcore::potchven-finances.sites.store');
Route::post('/potchven-finances/isk-hour/start', 'PotchvenFinancesController@startIskHour')
    ->name('seatcore::potchven-finances.isk-hour.start');
Route::post('/potchven-finances/isk-hour/stop', 'PotchvenFinancesController@stopIskHour')
    ->name('seatcore::potchven-finances.isk-hour.stop');
Route::post('/potchven-finances/timer/reset', 'PotchvenFinancesController@resetTimer')
    ->name('seatcore::potchven-finances.timer.reset');
Route::post('/potchven-finances/financial-period/reset', 'PotchvenFinancesController@resetFinancialPeriod')
    ->name('seatcore::potchven-finances.period.reset');
Route::post('/potchven-finances/srp/add-site-without-loot', 'PotchvenFinancesController@addSiteWithoutLoot')
    ->name('seatcore::potchven-finances.srp.add-site-without-loot');
Route::post('/potchven-finances/srp/transfer', 'PotchvenFinancesController@transferToSrp')
    ->name('seatcore::potchven-finances.srp.transfer');
