<?php

namespace App\Http\Services\Book;

use App\Models\Book;
use App\Models\School;
use App\Models\SchoolBook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class BookImportService
{
    public function __construct(
        protected BookPriceHistoryService $priceHistory,
    ) {}

    /**
     * Imports scraped books into the database and reconciles each school's
     * book list against the scraped list, treating the payload as the
     * source of truth for the (school, year, teaching_cycle) scope it
     * covers: existing links no longer present are removed, missing ones
     * are added, and books are never duplicated (reused via
     * findOrCreateBook()).
     */
    public function import(array $books): array
    {
        $report = [
            'imported' => 0,
            'removed'  => 0,
            'skipped'  => 0,
            'errors'   => [],
        ];

        foreach ($books as $entry) {
            try {
                if (empty($entry['school']['name'])) {
                    Log::warning('[BookImportService] Skipping entry with invalid school name.', ['entry' => $entry]);
                    $report['skipped']++;
                    $report['errors'][] = [
                        'school' => null,
                        'item'   => null,
                        'reason' => 'Missing school name.',
                    ];
                    continue;
                }

                $this->validateSchool($entry['school']);

                $school = $this->findOrCreateSchool($entry['school']);

                $this->syncSchoolBooks($school, $entry['items'] ?? [], $report);
            } catch (Throwable $e) {
                Log::error('[BookImportService] Error processing school batch: ' . $e->getMessage(), [
                    'school' => $entry['school'] ?? 'Unknown',
                ]);
                $report['skipped']++;
                $report['errors'][] = [
                    'school' => $entry['school']['name'] ?? null,
                    'item'   => null,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $report;
    }

    /**
     * Reconciles a school's books against the scraped list.
     */
    protected function syncSchoolBooks(School $school, array $items, array &$report): void
    {
        $scopes = [];

        foreach ($items as $item) {
            if (empty($item['title'])) {
                Log::warning('[BookImportService] Skipping item without title.', ['school' => $school->name, 'item' => $item]);
                $report['skipped']++;
                $report['errors'][] = [
                    'school' => $school->name,
                    'item'   => null,
                    'reason' => 'Missing book title.',
                ];
                continue;
            }

            try {
                $this->validateItem($item);
            } catch (Throwable $e) {
                Log::error('[BookImportService] Error validating item: ' . $e->getMessage(), [
                    'school' => $school->name,
                    'item'   => $item,
                ]);
                $report['skipped']++;
                $report['errors'][] = [
                    'school' => $school->name,
                    'item'   => $item['title'] ?? null,
                    'reason' => $e->getMessage(),
                ];
                continue;
            }

            $scopeKey = $item['year'] . '|' . $item['teaching_cycle'];

            $scopes[$scopeKey]['year'] ??= $item['year'];
            $scopes[$scopeKey]['teaching_cycle'] ??= $item['teaching_cycle'];
            $scopes[$scopeKey]['items'][] = $item;
        }

        foreach ($scopes as $scope) {
            $this->syncScope($school, $scope['year'], $scope['teaching_cycle'], $scope['items'], $report);
        }
    }

    /**
     * Synchronizes the books for a specific school, year and teaching cycle.
     * Reuses existing books, creates new ones when necessary and updates
     * the relationships atomically.
     */

    protected function syncScope(School $school, string $year, string $cycle, array $items, array &$report): void
    {
        DB::transaction(function () use ($school, $year, $cycle, $items, &$report) {
            $incomingBookIds = new Collection();

            foreach ($items as $item) {
                try {
                    $book = $this->findOrCreateBook($item);
                    $incomingBookIds->push($book->id);
                    $report['imported']++;
                } catch (Throwable $e) {
                    Log::error('[BookImportService] Error importing item: ' . $e->getMessage(), [
                        'school' => $school->name,
                        'item'   => $item,
                    ]);
                    $report['skipped']++;
                    $report['errors'][] = [
                        'school' => $school->name,
                        'item'   => $item['title'] ?? null,
                        'reason' => $e->getMessage(),
                    ];
                }
            }

            $incomingBookIds = $incomingBookIds->unique()->values();

            $currentLinksQuery = SchoolBook::query()
                ->where('school_id', $school->id)
                ->where('year', $year)
                ->where('teaching_cycle', $cycle);

            $existingBookIds = (clone $currentLinksQuery)->pluck('book_id');

            $toRemove = $existingBookIds->diff($incomingBookIds);
            $toAdd = $incomingBookIds->diff($existingBookIds);

            if ($toRemove->isNotEmpty()) {
                (clone $currentLinksQuery)->whereIn('book_id', $toRemove)->delete();

                $report['removed'] += $toRemove->count();

                Log::info('[BookImportService] Removed stale school-book links.', [
                    'school'         => $school->name,
                    'year'           => $year,
                    'teaching_cycle' => $cycle,
                    'book_ids'       => $toRemove->values()->all(),
                ]);
            }

            if ($toAdd->isNotEmpty()) {
                $now = now();

                SchoolBook::insert(
                    $toAdd->map(fn(int $bookId) => [
                        'school_id'      => $school->id,
                        'book_id'        => $bookId,
                        'year'           => $year,
                        'teaching_cycle' => $cycle,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ])->all()
                );
            }
        });
    }

    /**
     * Validates the school data.
     */
    protected function validateSchool(array $school): void
    {
        Validator::make($school, [
            'district' => ['required', 'string'],
            'city'     => ['required', 'string'],
        ])->validate();
    }

    /**
     * Validates the book data.
     */
    protected function validateItem(array $item): void
    {
        Validator::make($item, [
            'publisher'      => ['required', 'string'],
            'type'           => ['required', 'string'],
            'price'          => ['required', 'numeric'],
            'year'           => ['required', 'string'],
            'teaching_cycle' => ['required', 'string'],
        ])->validate();
    }

    /**
     * Finds or creates a school.
     */
    protected function findOrCreateSchool(array $school): School
    {
        return School::firstOrCreate(
            ['name' => $school['name']],
            [
                'district' => $school['district'] ?? null,
                'city'     => $school['city'] ?? null,
            ]
        );
    }

    /**
     * Finds or creates a book
     */
    protected function findOrCreateBook(array $item): Book
    {
        $book = Book::firstOrCreate(
            [
                'title'     => $item['title'],
                'publisher' => $item['publisher'],
            ],
            [
                'authors'    => $item['authors'] ?? null,
                'cover_path' => $item['cover_path'] ?? null,
                'price'      => $item['price'],
                'discipline' => $item['discipline'] ?? null,
                'type'       => $item['type'],
            ]
        );

        $this->priceHistory->recordIfChanged($book, $item['price']);

        return $book;
    }
}
