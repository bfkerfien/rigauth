<?php

Route::group(['prefix' => 'divine-reign'], function () {
    Route::get('/', 'DivineReignController@index')->name('seatcore::divine-reign.index');
    Route::post('/start', 'DivineReignController@start')->name('seatcore::divine-reign.start');
    Route::post('/stop', 'DivineReignController@stop')->name('seatcore::divine-reign.stop');
    Route::post('/event', 'DivineReignController@event')->name('seatcore::divine-reign.event');
    Route::post('/settings', 'DivineReignController@saveSettings')->name('seatcore::divine-reign.settings');
    Route::post('/exploration/start', 'DivineReignController@startExploration')->name('seatcore::divine-reign.exploration.start');
    Route::post('/exploration/site', 'DivineReignController@recordExplorationSite')->name('seatcore::divine-reign.exploration.site');
    Route::post('/exploration/stop', 'DivineReignController@stopExploration')->name('seatcore::divine-reign.exploration.stop');
});
