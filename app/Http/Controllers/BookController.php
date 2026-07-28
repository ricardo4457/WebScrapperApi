<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookSearchRequest;
use App\Http\Services\Book\BookSearchService;
use App\Models\Book;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookController extends Controller
{
    public function __construct(
        private BookSearchService $search,
    ) {}

    /**
     * Main frontend endpoint for book searches.
     *
     * Supported search modes (see BookSearchRequest):
     * - school: exact school search
     * - city: books available in a city
     * - q: direct search by book title
     *
     * Pagination is supported in all modes through the page and per_page
     * parameters.
     *
     * School and city searches first check the local database. If no data
     * is found, a scraping job is started and the endpoint returns HTTP 202
     * with a run identifier. The frontend should poll the scraping status
     * endpoint and repeat the request after completion.
     *
     * District and city may be resolved automatically from existing records.
     * If the required location information cannot be determined, the endpoint
     * returns HTTP 422.
     *
     * The q mode is database-only and never triggers scraping.
     */

    public function search(BookSearchRequest $request): JsonResponse
    {
        $result = $this->search->search($request->validated());

        if (in_array($result['mode'], ['school', 'city'], true) && !$result['found']) {
            if ($result['error']) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $result['error'],
                ], 422);
            }

            $scrape = $result['scrape'];

            if (!$scrape['ok']) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $scrape['error'],
                ], $scrape['status']);
            }

            return response()->json([
                'status'     => 'scraping',
                'message'    => 'Book data not cached yet, scrape started.',
                'run_id'     => $scrape['run']->id,
                'jobs_total' => $scrape['jobs_total'],
            ], 202);
        }

        return response()->json([
            'status' => 'found',
            'mode'   => $result['mode'],
            'school' => $result['school'],
            'books'  => $result['books'],
        ]);
    }

 /**
     * GET /api/books/{book}/price-history
     *
     * Returns the full price history for a single book, ordered from newest
     * to oldest.
     *
     * No pagination is applied to this endpoint.
     */

    public function priceHistory(Book $book): JsonResponse
    {
        $history = $book->priceHistory()
            ->orderByDesc('recorded_at')
            ->get();

        return response()->json([
            'book'    => $book->only(['id', 'title', 'publisher', 'price']),
            'history' => $history,
        ]);
    }

 /**
     * GET /api/schools?district=&city=&search=
     *
     * Returns schools from the local database for autocomplete and dropdown
     * components.
     *
     * This endpoint is read-only and never triggers scraping.
     */

    public function schools(Request $request): JsonResponse
    {
        $schools = School::query()
            ->when($request->filled('district'), fn($q) => $q->where('district', $request->input('district')))
            ->when($request->filled('city'), fn($q) => $q->where('city', $request->input('city')))
            ->when($request->filled('search'), fn($q) => $q->where('name', 'like', '%' . $request->input('search') . '%'))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'district', 'city', 'name']);

        return response()->json($schools);
    }

    /**
     * GET /api/locations?district=
     *
     * Distinct districts (no param), or cities within a district (with
     * ?district=), for cascading selects. Only reflects what's already
     * been scraped at least once.
     */
    
    public function locations(Request $request): JsonResponse
    {
        if ($request->filled('district')) {
            $cities = School::where('district', $request->input('district'))
                ->distinct()
                ->orderBy('city')
                ->pluck('city');

            return response()->json(['cities' => $cities]);
        }

        $districts = School::query()
            ->distinct()
            ->orderBy('district')
            ->pluck('district');

        return response()->json(['districts' => $districts]);
    }
}
