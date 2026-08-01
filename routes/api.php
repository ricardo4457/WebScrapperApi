<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ScrapeController;
use App\Http\Controllers\BookController;

// --- Node -> Laravel callback endpoint ---
// Used by the Node scraper. Protected with the dedicated node.apikey
// middleware and intentionally kept outside the verify.app.key group.
Route::post('/book-scraper/callback', [ScrapeController::class, 'callback'])
    ->middleware('node.apikey')
    ->name('book-scraper.callback');

// --- Frontend -> Laravel scraping endpoints ---
// Used by the Vue frontend or API clients. Requests must provide a valid
// X-App-Key and pass the origin verification middleware.
Route::middleware(['verify.app.key', 'verify.origin'])->group(function () {

    // Starts a standard scraping run.
    Route::post('/book-scraper/run', [ScrapeController::class, 'runScrape'])
        ->middleware(['throttle:scrape-run']);

    // Starts a full-district scrape. Cities and schools are discovered
    // automatically by FullDistrictStrategy.
    Route::post('/book-scraper/run/district', [ScrapeController::class, 'runDistrictScrape'])
        ->middleware(['throttle:scrape-run-heavy']);

    // Starts a full-city scrape for a specific district and city.
    Route::post('/book-scraper/run/city', [ScrapeController::class, 'runCityScrape'])
        ->middleware(['throttle:scrape-run-heavy']);

    // Returns the status and progress of a scraping run.
    Route::get('/book-scraper/status/{runId}', [ScrapeController::class, 'monitor'])
        ->name('book-scraper.status');

    // Main search endpoint. Uses cached data when available and starts a
    // scrape on a cache miss. Supports school, city and title searches.
    Route::get('/books/search', [BookController::class, 'search'])
        ->middleware(['throttle:30,1']);
});

// --- Public read-only endpoints ---
// These endpoints never trigger scraping and remain public for frontend
// autocomplete and browsing features.

// Book price history ordered from newest to oldest. No pagination.
Route::get('/books/{book}/price-history', [BookController::class, 'priceHistory'])
    ->middleware(['throttle:30,1']);

// Returns already-scraped schools for autocomplete and browsing.
Route::get('/schools', [BookController::class, 'schools']);

// Returns districts and cities derived from already-scraped data.

Route::get('/locations', [BookController::class, 'locations']);

// Returns distinct disciplines already present in the books table, for
// the discipline dropdown in the search funnel. Never triggers scraping.
Route::get('/disciplines', [BookController::class, 'disciplines']);
