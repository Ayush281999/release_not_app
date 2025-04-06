<?php

use App\Http\Controllers\GitHubWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
// Route::post('/github-webhook', [GitHubWebhookController::class, 'handleGitHubWebhook']);
Route::post('/github', [GitHubWebhookController::class, 'handleGitHubWebhok']);
