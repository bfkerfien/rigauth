<?php

use Illuminate\Support\Facades\Route;

Route::get('/triglavian/sso/authorize')
    ->name('seatcore::triglavian.sso.authorize')
    ->uses('TriglavianSsoController@authorize');
