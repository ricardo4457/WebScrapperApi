<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookSearchRequest;
use App\Http\Services\Book\BookSearchService;
use App\Http\Services\Scrape\ScrapeDispatchService;
use App\Models\Book;
use App\Models\School;
use App\Models\SchoolBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookController extends Controller
{
    public function __construct(
        private BookSearchService $search,
        private ScrapeDispatchService $dispatcher,
    ) {}

    /**
     * GET /api/books/{book}
     *
     * Returns a single book's details, including which schools carry it.
     *
     * This endpoint is read-only and never triggers scraping.
     */

    public function show(Book $book): JsonResponse
    {
      return response()->json([
        'book' => $book->only(['id', 'title', 'publisher', 'discipline', 'price']),
        'schools' => $book->schoolBooks->map(fn($sb) => [
            'school_id' => $sb->school->id,
            'name'      => $sb->school->name,
            'district'  => $sb->school->district,
            'city'      => $sb->school->city,
            'year'      => $sb->year,
        ]),
    ]);
    }

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

    /**
     * GET /api/disciplines
     *
     * Returns the distinct disciplines available in the books table.
     *
     */

    public function disciplines(): JsonResponse
    {
        $disciplines = Book::query()
            ->whereNotNull('discipline')
            ->distinct()
            ->orderBy('discipline')
            ->pluck('discipline');

        return response()->json(['disciplines' => $disciplines]);
    }

/**
 * GET /api/schools/{school}/courses?teaching_cycle=&year=&discover=1
 *
 * Returns the distinct courses already scraped for a school.
 *
 * Used by the frontend wizard to decide whether to show the Course step.
 * By default this endpoint is read-only. If discover=1 is provided and no
 * courses are cached, it starts a full_teaching_cycle scrape and returns
 * HTTP 202 with a run_id for polling.
 */

    public function schoolCourses(School $school, Request $request): JsonResponse
    {
        $courses = SchoolBook::query()
            ->where('school_id', $school->id)
            ->whereNotNull('course')
            ->when(
                $request->filled('teaching_cycle'),
                fn($q) => $q->where('teaching_cycle', $request->input('teaching_cycle'))
            )
            ->distinct()
            ->orderBy('course')
            ->pluck('course');

        if ($courses->isNotEmpty() || !$request->boolean('discover')) {
            return response()->json(['courses' => $courses]);
        }

        if (!$request->filled('year') || !$request->filled('teaching_cycle')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'year e teaching_cycle são obrigatórios para iniciar a descoberta de cursos.',
            ], 422);
        }

        $scrape = $this->dispatcher->dispatch([
            'strategy'       => 'full_teaching_cycle',
            'district'       => $school->district,
            'city'           => $school->city,
            'school'         => $school->name,
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
            'status'     => 'scraping',
            'message'    => 'Cursos ainda não foram descobertos para esta escola, scrape iniciado.',
            'run_id'     => $scrape['run']->id,
            'jobs_total' => $scrape['jobs_total'],
        ], 202);
    }
}
