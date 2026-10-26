<?php

use Illuminate\Support\Facades\Route;

Route::get('/pi', 'PlanetaryController@index')
    ->name('seatcore::planetary.index');
