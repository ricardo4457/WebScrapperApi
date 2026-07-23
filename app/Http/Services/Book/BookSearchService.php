<?php

namespace App\Http\Services\Book;

use App\Http\Services\Scrape\ScrapeDispatchService;
use App\Models\School;
use Illuminate\Database\Eloquent\Collection;

class BookSearchService
{
    public function __construct(
        private ScrapeDispatchService $dispatcher,
    ) {}

    /**
     * Look up the book list for a school/year/(teaching_cycle) scope.
     *
     * Matches the school by `name` only — deliberately not filtering by
     * district/city too, since BookImportService::findOrCreateSchool()
     * already treats `name` as the sole identity key when importing
     * scraped data (firstOrCreate(['name' => ...], [...])). Filtering on
     * district/city here as well could cause false negatives (and a
     * pointless re-scrape) if either was recorded slightly differently
     * on first import than what the frontend sends now.
     *
     * @return array{
     *   found: bool,
     *   school: ?School,
     *   books: Collection,
     *   scrape: ?array
     * }
     */
    public function search(array $params): array
    {
        $school = School::where('name', $params['school'])->first();

        if ($school) {
            $books = $this->booksFor($school, $params['year'], $params['teaching_cycle'] ?? null);

            if ($books->isNotEmpty()) {
                return [
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
            'found'  => false,
            'school' => $school,
            'books'  => new Collection(),
            'scrape' => $scrape,
        ];
    }

    private function booksFor(School $school, string $year, ?string $cycle): Collection
    {
        return $school->books()
            ->wherePivot('year', $year)
            ->when($cycle, fn($q) => $q->wherePivot('teaching_cycle', $cycle))
            ->get();
    }
}
