<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'exploration'], function () {
    Route::get('/', 'ExplorationController@index')
        ->name('seatcore::exploration.index');

    Route::post('/{region}/systems/{systemId}/toggle', 'ExplorationController@toggle')
        ->where(['region' => 'Branch|Tenal|Venal', 'systemId' => '[0-9]+'])
        ->name('seatcore::exploration.toggle');

    Route::post('/{region}/reset', 'ExplorationController@reset')
        ->where('region', 'Branch|Tenal|Venal')
        ->name('seatcore::exploration.reset');
});
