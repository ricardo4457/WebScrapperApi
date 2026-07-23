<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookSearchRequest;
use App\Http\Services\Book\BookSearchService;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookController extends Controller
{
    public function __construct(
        private BookSearchService $search,
    ) {}

    /**
     * Main frontend entrypoint: GET /api/books/search
     * ?district=&city=&school=&year=&teaching_cycle=
     *
     * Serves straight from the DB if cached. On a miss, triggers a live
     * scrape and returns 202 with a run_id — the frontend polls
     * GET /book-scraper/status/{runId} and re-calls this endpoint once
     * that run reports 'completed'.
     */
    public function search(BookSearchRequest $request): JsonResponse
    {
        $result = $this->search->search($request->validated());

        if ($result['found']) {
            return response()->json([
                'status' => 'found',
                'school' => $result['school'],
                'books'  => $result['books'],
            ]);
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

    /**
     * GET /api/schools?district=&city=&search=
     *
     * Pure DB read for autocomplete/dropdowns over already-scraped
     * schools. Never triggers a scrape.
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
