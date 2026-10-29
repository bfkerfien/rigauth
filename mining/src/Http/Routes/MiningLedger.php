<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => ['auth', 'locale'],
], function () {
    Route::get('/mining', 'MiningLedgerController@index')
        ->name('seatcore::mining.index');
    Route::post('/mining/operations', 'MiningLedgerController@storeOperation')
        ->name('seatcore::mining.operations.store');
    Route::post('/mining/yields', 'MiningLedgerController@storeYield')
        ->name('seatcore::mining.yields.store');
    Route::post('/mining/import', 'MiningLedgerController@importCsv')
        ->name('seatcore::mining.import');
});
