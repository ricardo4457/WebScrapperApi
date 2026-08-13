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
            'book' => $book->only(['id', 'title', 'publisher', 'discipline', 'price', 'cover_path', 'authors', 'type']),
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
     * - q: direct search by book title
     *
     * Pagination is supported in both modes through the page and per_page
     * parameters.
     *
     * School searches first check the local database. If no data is found,
     * a scraping job is started and the endpoint returns HTTP 202 with a
     * run identifier. The frontend should poll the scraping status
     * endpoint and repeat the request after completion.
     *
     * The q mode is database-only and never triggers scraping.
     */

    public function search(BookSearchRequest $request): JsonResponse
    {
        $result = $this->search->search($request->validated());

        if ($result['mode'] === 'school' && !$result['found']) {
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

        // stale=true means cached data is older than the expected academic year.
        // Returned books come from cache, but a background re-scrape is triggered
        // (fail-safe) to fetch updated data. The frontend can use refresh_run_id
        // to poll the scrape status, just like when no cache is available.
        $stale = $result['stale'] ?? false;
        $refreshRun = $stale ? ($result['scrape']['run'] ?? null) : null;

        return response()->json([
            'status'          => 'found',
            'mode'            => $result['mode'],
            'school'          => $result['school'],
            'books'           => $result['books'],
            'stale'           => $stale,
            'refresh_run_id'  => $refreshRun?->id,
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
     * Returns cached schools for autocomplete/dropdown components.
     * If discover=1 and no schools are found, starts a full_city scrape.
     */

    public function schools(Request $request): JsonResponse
    {
        $district = $request->input('district');
        $city = $request->input('city');
        $year = $request->input('year');
        $teachingCycle = $request->input('teaching_cycle');

        $schools = School::query()
            ->when($district, fn($q) => $q->whereRaw('LOWER(TRIM(district)) = ?', [mb_strtolower(trim($district))]))
            ->when($city, fn($q) => $q->whereRaw('LOWER(TRIM(city)) = ?', [mb_strtolower(trim($city))]))
            ->when(
                $year || $teachingCycle,
                fn($q) => $q->whereHas('schoolBooks', function ($sq) use ($year, $teachingCycle) {
                    $sq->when($year, fn($x) => $x->where('year', $year))
                        ->when($teachingCycle, fn($x) => $x->where('teaching_cycle', $teachingCycle));
                })
            )
            ->when(
                $request->filled('search'),
                fn($q) => $q->where('name', 'like', '%' . $request->input('search') . '%')
            )
            ->orderBy('name')
            ->distinct()
            ->limit(50)
            ->get(['id', 'district', 'city', 'name']);

        // Return cached schools for the selected scope.
        if ($schools->isNotEmpty()) {
            return response()->json([
                'status' => 'found',
                'schools' => $schools,
            ]);
        }

        // The city is already cached, but no schools match the selected year/cycle.
        $cityExists = School::query()
            ->when($district, fn($q) => $q->where('district', $district))
            ->when($city, fn($q) => $q->where('city', $city))
            ->exists();

        if ($cityExists) {
            return response()->json([
                'status' => 'not_found',
                'schools' => [],
            ]);
        }

        // No cached schools for this city: optionally start a full city scrape.
        if ($request->boolean('discover') && $district && $city) {

            if (!$year || !$teachingCycle) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'year e teaching_cycle são obrigatórios para discover=1.',
                ], 422);
            }

            $scrape = $this->dispatcher->dispatch([
                'strategy' => 'full_city',
                'district' => $district,
                'city' => $city,
                'year' => $year,
                'teaching_cycle' => $teachingCycle,
            ]);

            if (!$scrape['ok']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $scrape['error'],
                ], $scrape['status']);
            }

            return response()->json([
                'status' => 'scraping',
                'message' => 'Schools not cached yet, full city scrape started.',
                'run_id' => $scrape['run']->id,
                'jobs_total' => $scrape['jobs_total'],
            ], 202);
        }

        // Empty result without discovery.
        return response()->json([
            'status' => 'not_found',
            'schools' => [],
        ]);
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

    // Choose the cheapest discovery strategy available.
    public function schoolDisciplines(School $school, Request $request): JsonResponse
    {
        $disciplines = Book::query()
            ->whereNotNull('discipline')
            ->whereHas('schoolBooks', function ($q) use ($school, $request) {
                $q->where('school_id', $school->id)
                    ->when(
                        $request->filled('year'),
                        fn($q) => $q->where('year', $request->input('year'))
                    )
                    ->when(
                        $request->filled('teaching_cycle'),
                        fn($q) => $q->where('teaching_cycle', $request->input('teaching_cycle'))
                    )
                    ->when(
                        $request->filled('course'),
                        fn($q) => $q->where('course', $request->input('course'))
                    );
            })
            ->distinct()
            ->orderBy('discipline')
            ->pluck('discipline');

        if ($disciplines->isNotEmpty() || !$request->boolean('discover')) {
            return response()->json(['disciplines' => $disciplines]);
        }

        if (!$request->filled('year') || !$request->filled('teaching_cycle')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'year e teaching_cycle são obrigatórios para iniciar a descoberta de disciplinas.',
            ], 422);
        }

        $hasCourse = $request->filled('course');

        $scrape = $this->dispatcher->dispatch([
            'strategy'       => $hasCourse ? 'single_school' : 'full_teaching_cycle',
            'district'       => $school->district,
            'city'           => $school->city,
            'school'         => $school->name,
            'year'           => $request->input('year'),
            'teaching_cycle' => $request->input('teaching_cycle'),
            ...($hasCourse ? ['course' => $request->input('course')] : []),
        ]);

        if (!$scrape['ok']) {
            return response()->json([
                'status'  => 'error',
                'message' => $scrape['error'],
            ], $scrape['status']);
        }

        return response()->json([
            'status'     => 'scraping',
            'message'    => 'Disciplinas ainda não foram descobertas para esta escola, scrape iniciado.',
            'run_id'     => $scrape['run']->id,
            'jobs_total' => $scrape['jobs_total'],
        ], 202);
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
