<?php

namespace App\Http\Services\Book;

use App\Models\Book;
use App\Models\School;
use App\Models\SchoolBook;
use Illuminate\Support\Facades\Log;
use Throwable;

class BookImportService
{

    public function __construct(
        protected BookPriceHistoryService $priceHistory,
    ) {}

    /**
     * Imports scraped books into the database.
     */

    public function import(array $books): void
    {
        foreach ($books as $entry) {
            try {
                if (empty($entry['school']['name'])) {
                    Log::warning('[BookImportService] Skipping entry with invalid school name.', ['entry' => $entry]);
                    continue;
                }

                $school = $this->findOrCreateSchool($entry['school']);

                foreach ($entry['items'] as $item) {
                    if (empty($item['title'])) {
                        Log::warning('[BookImportService] Skipping item without title.', ['school' => $school->name, 'item' => $item]);
                        continue;
                    }

                    $book = $this->findOrCreateBook($item);

                    $this->attachBookToSchool($school->id, $book, $item);
                }
            } catch (Throwable $e) {
                Log::error('[BookImportService] Error processing school batch: ' . $e->getMessage(), [
                    'school' => $entry['school'] ?? 'Unknown'
                ]);
                throw $e;
            }
        }
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
                'publisher' => $item['publisher'] ?? null,
            ],
            [
                'authors'    => $item['authors'] ?? null,
                'cover_path' => $item['cover_path'] ?? null,
                'price'      => $item['price'] ?? null,
                'discipline' => $item['discipline'] ?? null,
                'type'       => $item['type'] ?? null,
            ]
        );

        $this->priceHistory->recordIfChanged($book, $item['price'] ?? null);

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
