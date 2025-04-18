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
Route::get('/offer2', function () {
    return view('new-offer2');
});
Route::get('/offer3', function () {
    return view('new-offer3');
});
Route::get('/offer4', function () {
    return view('new-offer4');
});
Route::get('/offer5', function () {
    return view('new-offer5');
});
Route::get('/offer6', function () {
    return view('new-offer6');
});
Route::get('/offer7', function () {
    return view('new-offer7');
});
Route::get('/offer8', function () {
    return view('new-offer8');
});
Route::get('/offer9', function () {
    return view('new-offer9');
});
Route::get('/offer10', function () {
    return view('new-offer10');
});
Route::get('/offer11', function () {
    return view('new-offer11');
});
Route::get('/offer12', function () {
    return view('new-offer12');
});
Route::get('/offer13', function () {
    return view('new-offer13');
});

