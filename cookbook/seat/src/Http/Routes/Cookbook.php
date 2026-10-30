<?php

use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['auth', 'locale']], function () {
    Route::get('/cookbook', 'CookbookController@index')->name('seatcore::cookbook.index');
    Route::get('/cookbook/products', 'CookbookController@search')->name('seatcore::cookbook.products');
    Route::post('/cookbook/calculate', 'CookbookController@calculate')->name('seatcore::cookbook.calculate');
    Route::post('/cookbook/inventory', 'CookbookController@inventory')->name('seatcore::cookbook.inventory');
    Route::post('/cookbook/reprocess', 'CookbookController@reprocess')->name('seatcore::cookbook.reprocess');
    Route::get('/cookbook/ore-types', 'CookbookController@oreTypes')->name('seatcore::cookbook.ore-types');
    Route::post('/cookbook/ore-plan', 'CookbookController@orePlan')->name('seatcore::cookbook.ore-plan');
});
