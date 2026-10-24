<?php

use Illuminate\\Support\\Facades\\Route;

Route::get('/potchven-finances', 'PotchvenFinancesController@index')
    ->name('seatcore::potchven-finances.index');
Route::post('/potchven-finances/sites', 'PotchvenFinancesController@storeSite')
    ->name('seatcore::potchven-finances.sites.store');
