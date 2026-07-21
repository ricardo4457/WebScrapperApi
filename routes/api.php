<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ScrapeController;

// Callback Route (Uses our updated dynamic middleware)
Route::post('/book-scraper/callback', [ScrapeController::class, 'callback'])
    ->middleware('node.apikey')
    ->name('book-scraper.callback');

// Run Route (Temporarily commented out auth:sanctum for Postman testing)
Route::post('/book-scraper/run', [ScrapeController::class, 'runScrape'])
    ->middleware(['throttle:10,1']);

// Full-district run: separate shape ({ year, district, teaching_cycle }
// only) since FullDistrictStrategy now discovers cities/schools itself.
Route::post('/book-scraper/run/district', [ScrapeController::class, 'runDistrictScrape'])
    ->middleware(['throttle:10,1']);

Route::get('/book-scraper/status/{runId}', [ScrapeController::class, 'monitor'])
    ->name('book-scraper.status');
