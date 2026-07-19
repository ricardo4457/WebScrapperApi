<?php

namespace App\Http\Services\Book;

use App\Models\Book;
use App\Models\School;
use App\Models\SchoolBook;
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
     * Imports scraped books into the database.
     */
    public function import(array $books): array
    {
        $report = [
            'imported' => 0,
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

                foreach ($entry['items'] as $item) {
                    $this->importItem($school, $item, $report);
                }
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
     * Imports a book for the given school.
     */
    protected function importItem(School $school, array $item, array &$report): void
    {
        try {
            if (empty($item['title'])) {
                Log::warning('[BookImportService] Skipping item without title.', ['school' => $school->name, 'item' => $item]);
                $report['skipped']++;
                $report['errors'][] = [
                    'school' => $school->name,
                    'item'   => null,
                    'reason' => 'Missing book title.',
                ];
                return;
            }

            $this->validateItem($item);

            // Keep the book and school link in sync.
            DB::transaction(function () use ($school, $item) {
                $book = $this->findOrCreateBook($item);
                $this->attachBookToSchool($school->id, $book, $item);
            });

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
     * Finds or creates a book and updates its price history.
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

    /**
     * Links a book to a school.
     */

    protected function attachBookToSchool(int $schoolId, Book $book, array $item): void
    {
        SchoolBook::updateOrCreate(
            [
                'school_id'      => $schoolId,
                'book_id'        => $book->id,
                'year'           => $item['year'] ?? null,
                'teaching_cycle' => $item['teaching_cycle'] ?? null,
            ],
            []
        );
    }
}
