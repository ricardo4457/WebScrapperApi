<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ScrapeController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');


Route::post('/book-scraper/callback', [ScrapeController::class, 'callback'])
    ->middleware('node.apikey')
    ->name('book-scraper.callback');

Route::post('/book-scraper/run', [ScrapeController::class, 'runScrape'])
    ->middleware(['auth:sanctum', 'throttle:10,1']);
