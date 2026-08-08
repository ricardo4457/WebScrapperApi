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

    /**
     * Os dados de manuais estão presos a um ano letivo. Passado este
     * número de meses sem atualização, o registo em cache é tratado como
     * desatualizado (o ano letivo seguinte já deve ter uma lista nova no
     * Wook) e dispara-se um rescrape em segundo plano.
     */
    private const STALE_AFTER_MONTHS = 12;

    public function __construct(
        private ScrapeDispatchService $dispatcher,
    ) {}

    public function search(array $params): array
    {
        if (!empty($params['school'])) {
            return $this->searchBySchool($params);
        }

        return $this->searchByTitle($params);
    }


    private function searchBySchool(array $params): array
    {
        $school = School::where('name', $params['school'])->first();

        //  Caso exista escola → tenta livros
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

            if ($books->total() > 0) {
                // Fail-safe: cached data may belong to a past academic year.
                // Serve cache immediately (no blocking), but if it's too old,
                // trigger a background re-scrape to fetch updated data.
                $refresh = $this->refreshIfStale($school, $params);

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

        //  1. Se a escola NÃO existe → faz full_city primeiro
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

        //  2. Escola existe mas sem livros → faz single_school
        $scrape = $this->dispatcher->dispatch([
            'strategy' => 'single_school',
            'district' => $district,
            'city'     => $city,
            'school'   => $params['school'],
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

    /**
     * Checks if cached books are outdated for this scope.
     * If so, triggers a background re-scrape.
     *
     * Safe to call on every request .
     *
     * Returns null if data is fresh, or dispatch result if refreshed.
     */
    private function refreshIfStale(School $school, array $params): ?array
    {
        $lastUpdatedAt = SchoolBook::query()
            ->where('school_id', $school->id)
            ->where('year', $params['year'])
            ->when(!empty($params['teaching_cycle']), fn($q) => $q->where('teaching_cycle', $params['teaching_cycle']))
            ->when(!empty($params['course']), fn($q) => $q->where('course', $params['course']))
            ->max('updated_at');

        $threshold = now()->subMonths(self::STALE_AFTER_MONTHS);

        $isStale = !$lastUpdatedAt || Carbon::parse($lastUpdatedAt)->lt($threshold);

        if (!$isStale) {
            return null;
        }

        return $this->dispatcher->dispatch([
            'strategy'       => 'single_school',
            'district'       => $params['district'] ?? $school->district,
            'city'           => $params['city'] ?? $school->city,
            'school'         => $params['school'],
            'year'           => $params['year'],
            'teaching_cycle' => $params['teaching_cycle'] ?? null,
            'course'         => $params['course'] ?? null,
        ]);
    }


    private function perPage(array $params): int
    {
        return (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE);
    }
}
