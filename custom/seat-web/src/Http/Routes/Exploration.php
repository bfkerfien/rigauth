<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'exploration'], function () {
    Route::get('/player-location', 'ExplorationController@playerLocation')
        ->name('seatcore::exploration.player-location');

    Route::get('/', 'ExplorationController@index')
        ->name('seatcore::exploration.index');

    Route::get('/all-wormholes', 'ExplorationController@allWormholes')
        ->name('seatcore::exploration.wormholes.all');

    Route::get('/{region}/systems/{systemId}/signatures', 'ExplorationController@signatures')
        ->where(['region' => 'Branch|Tenal|Venal', 'systemId' => '[0-9]+'])
        ->name('seatcore::exploration.signatures.index');

    Route::post('/{region}/systems/{systemId}/signatures', 'ExplorationController@saveSignatures')
        ->where(['region' => 'Branch|Tenal|Venal', 'systemId' => '[0-9]+'])
        ->name('seatcore::exploration.signatures.save');

    Route::post('/{region}/systems/{systemId}/signatures/{signatureId}/wormhole-notes', 'ExplorationController@saveWormholeNotes')
        ->where(['region' => 'Branch|Tenal|Venal', 'systemId' => '[0-9]+', 'signatureId' => '[A-Za-z0-9-]+'])
        ->name('seatcore::exploration.signatures.wormhole-notes');

    Route::post('/{region}/systems/{systemId}/toggle', 'ExplorationController@toggle')
        ->where(['region' => 'Branch|Tenal|Venal', 'systemId' => '[0-9]+'])
        ->name('seatcore::exploration.toggle');

    Route::post('/{region}/reset', 'ExplorationController@reset')
        ->where('region', 'Branch|Tenal|Venal')
        ->name('seatcore::exploration.reset');
});
