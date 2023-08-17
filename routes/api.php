<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::group(['namespace' => 'Api'], function () {
    Route::any('/login', 'LoginController@login');
    Route::any('/get_profile', 'LoginController@get_profile')->middleware('CheckUser');
    Route::any('/update_profile', 'LoginController@update_profile')->middleware('CheckUser');
    Route::any('/bind_fcmtoken', 'LoginController@bind_fcmtoken')->middleware('CheckUser');
    Route::any('/contact', 'LoginController@contact')->middleware('CheckUser');
    Route::any('/upload_photo', 'LoginController@upload_photo')->middleware('CheckUser');
    Route::any('/send_notice', 'LoginController@send_notice')->middleware('CheckUser');
    Route::any('/get_rtc_token', 'AccessTokenController@get_rtc_token')->middleware('CheckUser');
    Route::any('/send_notice_test', 'LoginController@send_notice_test');
});