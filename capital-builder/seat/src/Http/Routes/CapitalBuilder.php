<?php

use Illuminate\\Support\\Facades\\Route;

Route::group(['middleware' => ['auth', 'locale']], function () {
    Route::get('/capital-builder', 'CapitalBuilderController@index')->name('seatcore::capital-builder.index');
    Route::get('/capital-builder/products', 'CapitalBuilderController@search')->name('seatcore::capital-builder.products');
    Route::post('/capital-builder/calculate', 'CapitalBuilderController@calculate')->name('seatcore::capital-builder.calculate');
});
