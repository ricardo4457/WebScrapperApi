<?php

namespace App\Http\Services\Book;

use App\Http\Services\Scrape\ScrapeDispatchService;
use App\Models\Book;
use App\Models\School;
use App\Models\SchoolBook;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class BookSearchService
{
    private const DEFAULT_PER_PAGE = 15;

    // Cached data is considered stale after one academic year.
    private const STALE_AFTER_MONTHS = 12;

    public function __construct(
        private ScrapeDispatchService $dispatcher,
    ) {}

    // Selects the search mode based on the provided parameters.
    public function search(array $params): array
    {
        if (!empty($params['school']) || !empty($params['school_id'])) {
            return $this->searchBySchool($params);
        }

        return $this->searchByTitle($params);
    }

    // Searches for books associated with a specific school.

    private function searchBySchool(array $params): array
    {
        // Prefer the school ID to avoid name matching issues.
        $school = !empty($params['school_id'])
            ? School::find($params['school_id'])
            : School::where('name', $params['school'] ?? null)->first();

        // The scraper uses the school name to navigate the source website.
        $schoolName = $params['school'] ?? $school?->name;

        // Search cached books before starting a new scrape.
        if ($school) {
            $query = $school->books()
                ->wherePivot('year', $params['year']);

            if (!empty($params['teaching_cycle'])) {
                $query->wherePivot('teaching_cycle', $params['teaching_cycle']);
            }

            if (!empty($params['course'])) {
                $query->wherePivot('course', $params['course']);
            }

            if (!empty($params['discipline'])) {
                $query->where('discipline', $params['discipline']);
            }

            $books = $query
                ->orderBy('price')
                ->paginate($this->perPage($params));

            // Return cached results immediately and refresh stale data in background.
            if ($books->total() > 0) {
                $refresh = $this->refreshIfStale($school, $schoolName, $params);

                return [
                    'mode'   => 'school',
                    'found'  => true,
                    'school' => $school,
                    'books'  => $books,
                    'scrape' => $refresh,
                    'stale'  => $refresh !== null,
                    'error'  => null,
                ];
            }
        }

        // Use the provided location or the school's stored location.
        $district = $params['district'] ?? $school?->district;
        $city = $params['city'] ?? $school?->city;

        if (!$district || !$city) {
            return [
                'mode'   => 'school',
                'found'  => false,
                'school' => $school,
                'books'  => null,
                'scrape' => null,
                'stale'  => false,
                'error'  => 'Escola desconhecida — indica district e city.',
            ];
        }

        // If the school is unknown, scrape the entire city to discover it.
        if (!$school) {
            $cityScrape = $this->dispatcher->dispatch([
                'strategy' => 'full_city',
                'district' => $district,
                'city'     => $city,
                'year'     => $params['year'],
                'teaching_cycle' => $params['teaching_cycle'] ?? null,
                'course'   => $params['course'] ?? null,
            ]);

            return [
                'mode'   => 'school',
                'found'  => false,
                'school' => null,
                'books'  => null,
                'scrape' => $cityScrape,
                'stale'  => false,
                'error'  => 'A validar escola — scrape da cidade iniciado.',
            ];
        }

        // If the school exists but has no cached books, scrape only that school.
        $scrape = $this->dispatcher->dispatch([
            'strategy' => 'single_school',
            'district' => $district,
            'city'     => $city,
            'school'   => $schoolName,
            'year'     => $params['year'],
            'teaching_cycle' => $params['teaching_cycle'] ?? null,
            'course'   => $params['course'] ?? null,
        ]);

        return [
            'mode'   => 'school',
            'found'  => false,
            'school' => $school,
            'books'  => null,
            'scrape' => $scrape,
            'stale'  => false,
            'error'  => null,
        ];
    }


    // Searches cached books by title.
    private function searchByTitle(array $params): array
    {
        $books = Book::query()
            ->where('title', 'like', '%' . $params['q'] . '%')
            ->when(!empty($params['discipline']), fn($q) => $q->where('discipline', $params['discipline']))
            ->orderBy('price')
            ->paginate($this->perPage($params));

        return [
            'mode'   => 'title',
            'found'  => true,
            'school' => null,
            'books'  => $books,
            'scrape' => null,
            'stale'  => false,
            'error'  => null,
        ];
    }

    // Checks whether cached books need to be refreshed.
    private function refreshIfStale(School $school, ?string $schoolName, array $params): ?array
    {
        $lastUpdatedAt = SchoolBook::query()
            ->where('school_id', $school->id)
            ->where('year', $params['year'])
            ->when(!empty($params['teaching_cycle']), fn($q) => $q->where('teaching_cycle', $params['teaching_cycle']))
            ->when(!empty($params['course']), fn($q) => $q->where('course', $params['course']))
            ->max('updated_at');

        // Compare the last update with the configured stale threshold.
        $threshold = now()->subMonths(self::STALE_AFTER_MONTHS);

        $isStale = !$lastUpdatedAt || Carbon::parse($lastUpdatedAt)->lt($threshold);

        if (!$isStale) {
            return null;
        }
        // Refresh only the selected school when its data is outdated.

        return $this->dispatcher->dispatch([
            'strategy'       => 'single_school',
            'district'       => $params['district'] ?? $school->district,
            'city'           => $params['city'] ?? $school->city,
            'school'         => $schoolName ?? $school->name,
            'year'           => $params['year'],
            'teaching_cycle' => $params['teaching_cycle'] ?? null,
            'course'         => $params['course'] ?? null,
        ]);
    }

    // Returns the requested page size or the default value.
    private function perPage(array $params): int
    {
        return (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE);
    }
}
