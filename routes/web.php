<?php

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GitHubWebhookController;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});
Route::get('/test', function () {
    return view('welcometest');
});
Route::get('/test2', function () {
    return view('welcometests');
});
Route::get('/webinar', function () {
    return view('welcome-webinar');
});
Route::get('/session', function () {
    return view('welcome-session');
});
Route::get('/offer', function () {
    return view('new-offer');
});
