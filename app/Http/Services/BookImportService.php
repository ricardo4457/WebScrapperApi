<?php

namespace App\Http\Services;

use App\Models\Book;
use App\Models\School;
use App\Models\SchoolBook;
use Illuminate\Support\Facades\Log;
use Exception;

class BookImportService
{
    /**
     * Import books from scraper payload.
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

                    $this->attachBookToSchool($school->id, $entry, $item);
                }
            } catch (Exception $e) {
                Log::error('[BookImportService] Error processing school batch: ' . $e->getMessage(), [
                    'school' => $entry['school'] ?? 'Unknown'
                ]);
                throw $e;
            }
        }
    }

    /**
     * Find or create a school.
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
     * Find or create a book.
     */
    protected function findOrCreateBook(array $item): Book
    {
        return Book::firstOrCreate(
            [
                'title'     => $item['title'],
                'publisher' => $item['publisher'] ?? null,
            ],
            [
                'cover_path' => $item['cover_path'] ?? null,
                'price'      => $item['price'] ?? null,
                'discipline' => $item['discipline'] ?? null,
                'type'       => $item['type'] ?? null,
            ]
        );
    }

    /**
     * Link book to school.
     */
    protected function attachBookToSchool(int $schoolId, Book $book, array $item): void
    {
        SchoolBook::updateOrCreate(
            [
                'school_id' => $schoolId,
                'book_id'   => $book->id,
                'year'      => $item['year'] ?? null,
            ],
            [
                'teaching_cycle' => $book->teaching_cycle ?? null,
            ]
        );
    }
}
