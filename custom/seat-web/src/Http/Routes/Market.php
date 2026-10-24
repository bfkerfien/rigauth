<?php
use Illuminate\Support\Facades\Route;
Route::get('/market','MarketController@index')->name('seatcore::market.index');
Route::post('/market/shame-list','MarketController@storeShame')->name('seatcore::market.shame.store');
Route::post('/market/shame-list/{id}/resolve','MarketController@resolveShame')->whereNumber('id')->name('seatcore::market.shame.resolve');
