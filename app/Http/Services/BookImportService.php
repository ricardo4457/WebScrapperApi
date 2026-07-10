<?php

namespace App\Services;

use App\Models\Book;
use App\Models\School;
use App\Models\SchoolBook;

class BookImportService
{
    /**
     * Import all books returned by the scraper.
     */
    public function import(array $books): void
    {
        foreach ($books as $entry) {

            $school = $this->findOrCreateSchool($entry['school']);

            foreach ($entry['items'] as $item) {

                $book = $this->findOrCreateBook($item);

                $this->attachBookToSchool(
                    $school->id,
                    $book->id,
                    $item
                );
            }
        }
    }


    /**
     * Create or retrieve a school.
     */
    protected function findOrCreateSchool(array $school): School
    {
        return School::firstOrCreate(
            [
                'name' => $school['name'],
            ],
            [
                'district' => $school['district'] ?? null,
                'city'     => $school['city'] ?? null,
            ]
        );
    }


    /**
     * Create or retrieve a book.
     */
    protected function findOrCreateBook(array $item): Book
    {
        return Book::firstOrCreate(
            [
                'title' => $item['title'],
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
     * Link a book to a school.
     */
    protected function attachBookToSchool(
        int $schoolId,
        int $bookId,
        array $item
    ): void {

        SchoolBook::updateOrCreate(
            [
                'school_id' => $schoolId,
                'book_id'   => $bookId,
                'year'      => $item['year'] ?? null,
                'teaching_cycle' => $item['teaching_cycle'] ?? null,
            ]
        );
    }
}
