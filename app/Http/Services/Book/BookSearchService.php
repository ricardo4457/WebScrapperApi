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


        $district = $params['district'] ?? $school?->district;
        $city = $params['city'] ?? $school?->city;

        if (!$district || !$city) {
            return [
                'mode'   => 'school',
                'found'  => false,
                'school' => $school,
                'books'  => null,
                'scrape' => null,
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
            'error'  => null,
        ];
    }

    private function perPage(array $params): int
    {
        return (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE);
    }
}
