<?php

Route::get('/triglavian', 'TriglavianSetupController@index')
    ->name('seatcore::configuration.triglavian.index');

Route::post('/triglavian/stations', 'TriglavianSetupController@saveStation')
    ->name('seatcore::configuration.triglavian.stations.save');

Route::delete('/triglavian/stations/{id}', 'TriglavianSetupController@deleteStation')
    ->where('id', '[0-9]+')
    ->name('seatcore::configuration.triglavian.stations.delete');

Route::post('/triglavian/fittings', 'TriglavianSetupController@saveFitting')
    ->name('seatcore::configuration.triglavian.fittings.save');

Route::delete('/triglavian/fittings/{id}', 'TriglavianSetupController@deleteFitting')
    ->where('id', '[0-9]+')
    ->name('seatcore::configuration.triglavian.fittings.delete');
