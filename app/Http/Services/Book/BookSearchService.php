<?php

namespace App\Http\Services\Book;

use App\Http\Services\Scrape\ScrapeDispatchService;
use App\Models\Book;
use App\Models\School;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BookSearchService
{
    private const DEFAULT_PER_PAGE = 15;

    public function __construct(
        private ScrapeDispatchService $dispatcher,
    ) {}

    /**
     * Searches books using one of three mutually exclusive modes:
     *
     * - school: exact school search with scraping fallback
     * - city: books already scraped for schools in a city
     * - title: direct search by book title
     *
     * Database results are returned as paginated collections.
     *
     */

    public function search(array $params): array
    {
        if (!empty($params['school'])) {
            return $this->searchBySchool($params);
        }

        if (!empty($params['city'])) {
            return $this->searchByCity($params);
        }

        return $this->searchByTitle($params);
    }

    /**

     * Searches books for a specific school.
     *
     * If no cached books are found for the requested year, teaching cycle
     * and/or course, a live scraping job is started using the same filters.
     */

    private function searchBySchool(array $params): array
    {
        $school = School::where('name', $params['school'])->first();

        if ($school) {
            $query = $school->books()
                ->wherePivot('year', $params['year']);

            if (!empty($params['teaching_cycle'])) {
                $query->wherePivot('teaching_cycle', $params['teaching_cycle']);
            }

            if (!empty($params['course'])) {
                $query->wherePivot('course', $params['course']);
            }

            $books = $query
                ->orderBy('price')
                ->paginate($this->perPage($params));

            if ($books->total() > 0) {
                return [
                    'mode'   => 'school',
                    'found'  => true,
                    'school' => $school,
                    'books'  => $books,
                    'scrape' => null,
                    'error'  => null,
                ];
            }
        }

        // No cached books found for the requested year and teaching cycle.
        // Resolve the location and start a live single-school scrape.
        $district = $params['district'] ?? $school?->district;
        $city = $params['city'] ?? $school?->city;

        if (!$district || !$city) {
            return [
                'mode'   => 'school',
                'found'  => false,
                'school' => $school,
                'books'  => null,
                'scrape' => null,
                'error'  => 'Escola desconhecida — indica também district e city para poder iniciar o scrape.',
            ];
        }

        $scrape = $this->dispatcher->dispatch([
            'strategy'       => 'single_school',
            'district'       => $district,
            'city'           => $city,
            'school'         => $params['school'],
            'year'           => $params['year'],
            'teaching_cycle' => $params['teaching_cycle'] ?? null,
            'course'         => $params['course'] ?? null,
        ]);

        return [
            'mode'   => 'school',
            'found'  => false,
            'school' => $school,
            'books'  => null,
            'scrape' => $scrape,
            'error'  => null,
        ];
    }

    /**

     * Searches books already scraped for schools in a specific city.
     *
     * Results may be filtered by year, teaching cycle and course.
     * If no cached books are found for that scope, a city discovery scrape
     * is started with the same filters.
     */


    private function searchByCity(array $params): array
    {
        $books = Book::query()
            ->whereHas('schoolBooks', function ($query) use ($params) {
                $query->whereHas('school', fn($q) => $q->where('city', $params['city']))
                    ->when(!empty($params['year']), fn($q) => $q->where('year', $params['year']))
                    ->when(!empty($params['teaching_cycle']), fn($q) => $q->where('teaching_cycle', $params['teaching_cycle']))
                    ->when(!empty($params['course']), fn($q) => $q->where('course', $params['course']));
            })
            ->distinct()
            ->orderBy('price')
            ->paginate($this->perPage($params));

        if ($books->total() > 0) {
            return [
                'mode'   => 'city',
                'found'  => true,
                'school' => null,
                'books'  => $books,
                'scrape' => null,
                'error'  => null,
            ];
        }

        $district = $params['district'] ?? School::where('city', $params['city'])->value('district');

        if (!$district) {
            return [
                'mode'   => 'city',
                'found'  => false,
                'school' => null,
                'books'  => null,
                'scrape' => null,
                'error'  => 'Concelho desconhecido — indica também district para poder iniciar o scrape.',
            ];
        }

        $scrape = $this->dispatcher->dispatch([
            'strategy'       => 'full_city',
            'district'       => $district,
            'city'           => $params['city'],
            'year'           => $params['year'],
            'teaching_cycle' => $params['teaching_cycle'] ?? null,
            'course'         => $params['course'] ?? null,
        ]);

        return [
            'mode'   => 'city',
            'found'  => false,
            'school' => null,
            'books'  => null,
            'scrape' => $scrape,
            'error'  => null,
        ];
    }

    /**
     * Searches books by title across all cached books.
     *
     * This mode is database-only and never starts a scraping job.
     */

    private function searchByTitle(array $params): array
    {
        $books = Book::query()
            ->where('title', 'like', '%' . $params['q'] . '%')
            ->orderBy('price')
            ->paginate($this->perPage($params));

        return [
            'mode'   => 'title',
            'found'  => true,
            'school' => null,
            'books'  => $books,
            'scrape' => null,
            'error'  => null,
        ];
    }

    private function perPage(array $params): int
    {
        return (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE);
    }
}
