<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ScrapeController;
use App\Http\Controllers\BookController;

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

// Full-city run: like full-district, but scoped to one city/concelho
// ({ year, district, city, teaching_cycle }) since FullCityStrategy still
// needs the district to reach the city step in the site's cascading combo.
Route::post('/book-scraper/run/city', [ScrapeController::class, 'runCityScrape'])
    ->middleware(['throttle:10,1']);

Route::get('/book-scraper/status/{runId}', [ScrapeController::class, 'monitor'])
    ->name('book-scraper.status');

// --- Frontend-facing read endpoints ---


// Main search: DB lookup, falls back to a live scrape on a miss (202 + run_id).
// Supports three modes: school,city,book title. All paginated.
Route::get('/books/search', [BookController::class, 'search'])
    ->middleware(['throttle:30,1']);

// Book price history, ordered from newest to oldest. No pagination.
Route::get('/books/{book}/price-history', [BookController::class, 'priceHistory'])
    ->middleware(['throttle:30,1']);

// Autocomplete/browse over already-scraped schools. Never triggers a scrape.
Route::get('/schools', [BookController::class, 'schools']);

// Cascading district/city selects, derived from already-scraped data.
Route::get('/locations', [BookController::class, 'locations']);
