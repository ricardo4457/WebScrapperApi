<?php

namespace App\Http\Services\Book;

use App\Models\Book;
use Illuminate\Support\Facades\DB;

class BookPriceHistoryService
{
    /**
     * Updates the current book price and records it if it changed.
     *
     * @param Book $book The book model to update
     * @param float|null $newPrice The new price to set; if null, no action is taken
     * @return void
     */
    public function recordIfChanged(Book $book, ?float $newPrice): void
    {
        // Skip if no price is provided
        if ($newPrice === null) {
            return;
        }

        // Skip if price hasn't changed (cast to float for comparison)
        if ((float) $book->price === $newPrice) {
            return;
        }

        // Atomically update the book price and create a history record
        DB::transaction(function () use ($book, $newPrice) {
            // Update the book with the new price
            $book->update([
                'price' => $newPrice,
            ]);

            // Record the price change in the history
            $book->priceHistory()->create([
                'price'       => $newPrice,
                'recorded_at' => now(),
            ]);
        });
    }
}
