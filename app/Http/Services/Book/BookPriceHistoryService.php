<?php

namespace App\Http\Services\Book;

use App\Models\Book;
use Illuminate\Support\Facades\DB;

class BookPriceHistoryService
{
    /**
     * Tolerance for comparing currency values.
     */
    private const PRICE_EPSILON = 0.001;

    /**
     * Updates the current price and records price changes.
     */
    public function recordIfChanged(Book $book, ?float $newPrice): void
    {
        if ($newPrice === null) {
            return;
        }

        $hasExistingHistory = $book->priceHistory()->exists();
        $priceUnchanged = $this->pricesAreEqual((float) $book->price, $newPrice);


        if ($hasExistingHistory && $priceUnchanged) {
            return;
        }

        $this->persistPriceChange($book, $newPrice);
    }

    /**
     * Persists the current price and its history.
     */
    private function persistPriceChange(Book $book, float $newPrice): void
    {
        DB::transaction(function () use ($book, $newPrice) {
            $book->update([
                'price' => $newPrice,
            ]);

            $book->priceHistory()->create([
                'price'       => $newPrice,
                'recorded_at' => now(),
            ]);
        });
    }

    /**
     * Compares two prices using a tolerance.
     */
    private function pricesAreEqual(float $a, float $b): bool
    {
        return abs($a - $b) < self::PRICE_EPSILON;
    }
}
