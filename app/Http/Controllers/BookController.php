<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookSearchRequest;
use App\Http\Services\Book\BookSearchService;
use App\Http\Services\Scrape\ScrapeDispatchService;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookController extends Controller
{
    public function __construct(
        private BookSearchService $search,
        private ScrapeDispatchService $dispatcher,
    ) {}

    /**
     * Searches for books requested by the frontend.
     *
     * Returns cached data when available. Otherwise, starts a
     * scraping job and returns its run identifier.
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
            'state'      => 'scraping',
            'message'    => 'Book data not cached yet, scrape started.',
            'run_id'     => $scrape['run']->id,
            'jobs_total' => $scrape['jobs_total'],
            'status'     => route('book-scraper.status', $scrape['run']->id),
        ], 202);
    }

    /**
     * Returns schools for autocomplete and dropdown lists.
     *
     * If no schools are available for the selected district and city,
     * a discovery scraping job is started.
     */

    public function schools(Request $request): JsonResponse
    {
        $district = $request->input('district');
        $city = $request->input('city');

        if ($district && $city) {
            $concelhoHasSchools = School::where('district', $district)
                ->where('city', $city)
                ->exists();

            if (!$concelhoHasSchools) {
                if (!$request->filled('year') || !$request->filled('teaching_cycle')) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'No schools cached yet for this concelho. Provide year and teaching_cycle to trigger discovery.',
                    ], 422);
                }

                $scrape = $this->dispatcher->dispatch([
                    'strategy'       => 'full_city',
                    'district'       => $district,
                    'city'           => $city,
                    'year'           => $request->input('year'),
                    'teaching_cycle' => $request->input('teaching_cycle'),
                ]);

                if (!$scrape['ok']) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => $scrape['error'],
                    ], $scrape['status']);
                }

                return response()->json([
                    'state'      => 'scraping',
                    'message'    => 'No schools cached yet for this concelho, discovery scrape started.',
                    'run_id'     => $scrape['run']->id,
                    'jobs_total' => $scrape['jobs_total'],
                    'status'     => route('book-scraper.status', $scrape['run']->id),
                ], 202);
            }
        }

        $schools = School::query()
            ->when($district, fn($q) => $q->where('district', $district))
            ->when($city, fn($q) => $q->where('city', $city))
            ->when($request->filled('search'), fn($q) => $q->where('name', 'like', '%' . $request->input('search') . '%'))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'district', 'city', 'name']);

        return response()->json($schools);
    }

    /**
     * Returns available districts or cities.
     *
     * Data is retrieved from the local database. If no cities are found
     * for a district, a discovery scraping job is started.
     */

    public function locations(Request $request): JsonResponse
    {
        if ($request->filled('district')) {
            $district = $request->input('district');

            $cities = School::where('district', $district)
                ->distinct()
                ->orderBy('city')
                ->pluck('city');

            if ($cities->isNotEmpty()) {
                return response()->json(['cities' => $cities]);
            }

            if (!$request->filled('year') || !$request->filled('teaching_cycle')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'No cities cached yet for this district. Provide year and teaching_cycle to trigger discovery.',
                ], 422);
            }

            $scrape = $this->dispatcher->dispatch([
                'strategy'       => 'full_district',
                'district'       => $district,
                'year'           => $request->input('year'),
                'teaching_cycle' => $request->input('teaching_cycle'),
            ]);

            if (!$scrape['ok']) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $scrape['error'],
                ], $scrape['status']);
            }

            return response()->json([
                'state'      => 'scraping',
                'message'    => 'No cities cached yet for this district, discovery scrape started.',
                'run_id'     => $scrape['run']->id,
                'jobs_total' => $scrape['jobs_total'],
                'status'     => route('book-scraper.status', $scrape['run']->id),
            ], 202);
        }

        $districts = School::query()
            ->distinct()
            ->orderBy('district')
            ->pluck('district');

        return response()->json(['districts' => $districts]);
    }
}
