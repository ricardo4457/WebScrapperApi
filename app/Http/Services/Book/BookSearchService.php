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
     * The school is matched by name only to stay consistent with the
     * import process, which treats the school name as the unique key.
     * If no books are found for the requested year and teaching cycle,
     * a live scraping job is started.
     */

    private function searchBySchool(array $params): array
    {
        $school = School::where('name', $params['school'])->first();

        if ($school) {
            $books = $school->books()
                ->wherePivot('year', $params['year'])
                ->when($params['teaching_cycle'] ?? null, fn($q) => $q->wherePivot('teaching_cycle', $params['teaching_cycle']))
                ->orderBy('price')
                ->paginate($this->perPage($params));

            if ($books->total() > 0) {
                return [
                    'mode'   => 'school',
                    'found'  => true,
                    'school' => $school,
                    'books'  => $books,
                    'scrape' => null,
                ];
            }
        }

        // Miss: school unknown, or no books cached yet for this exact
        // (year, teaching_cycle) scope. Trigger a live single-school scrape.
        $scrape = $this->dispatcher->dispatch([
            'strategy'       => 'single_school',
            'district'       => $params['district'],
            'city'           => $params['city'],
            'school'         => $params['school'],
            'year'           => $params['year'],
            'teaching_cycle' => $params['teaching_cycle'] ?? null,
        ]);

        return [
            'mode'   => 'school',
            'found'  => false,
            'school' => $school,
            'books'  => null,
            'scrape' => $scrape,
        ];
    }

    /**
     * Searches books already scraped for schools in a specific city.
     *
     * Results are filtered through the school_books relation and may be
     * restricted by year and teaching cycle. If no books are found, a
     * city discovery scrape is started.
     */

    private function searchByCity(array $params): array
    {
        $books = Book::query()
            ->whereHas('schoolBooks', function ($query) use ($params) {
                $query->whereHas('school', fn($q) => $q->where('city', $params['city']))
                    ->when(!empty($params['year']), fn($q) => $q->where('year', $params['year']))
                    ->when(!empty($params['teaching_cycle']), fn($q) => $q->where('teaching_cycle', $params['teaching_cycle']));
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
            ];
        }

        $scrape = $this->dispatcher->dispatch([
            'strategy'       => 'full_city',
            'district'       => $params['district'],
            'city'           => $params['city'],
            'year'           => $params['year'],
            'teaching_cycle' => $params['teaching_cycle'],
        ]);

        return [
            'mode'   => 'city',
            'found'  => false,
            'school' => null,
            'books'  => null,
            'scrape' => $scrape,
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
        ];
    }

    private function perPage(array $params): int
    {
        return (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE);
    }
}
