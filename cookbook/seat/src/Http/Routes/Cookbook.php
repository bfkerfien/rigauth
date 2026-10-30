<?php

use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['auth', 'locale']], function () {
    Route::get('/cookbook', 'CookbookController@index')->name('seatcore::cookbook.index');
    Route::get('/cookbook/products', 'CookbookController@search')->name('seatcore::cookbook.products');
    Route::post('/cookbook/calculate', 'CookbookController@calculate')->name('seatcore::cookbook.calculate');
});
