<?php

use Illuminate\Support\Facades\Route;

Route::get('/triglavian/sso/authorize')
    ->name('seatcore::triglavian.sso.authorize')
    ->uses('TriglavianSsoController@authorize');

// Market Doctor is a native, authenticated SeAT page.
Route::get('/market-doctor', function () {
    return view('web::market-doctor');
})->name('seatcore::market-doctor.index');
