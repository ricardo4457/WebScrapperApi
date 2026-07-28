<?php

use App\Http\Services\Book\BookPriceHistoryService;
use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeBook(float $price = 10.0): Book
{
    return Book::create([
        'title'     => 'Livro Teste',
        'publisher' => 'Editora X',
        'price'     => $price,
        'type'      => 'manual',
    ]);
}

it('não cria histórico se o preço não mudou (dentro do PRICE_EPSILON)', function () {
    $service = new BookPriceHistoryService();
    $book = makeBook(10.00);

    // Primeira chamada regista sempre o preço inicial
    $service->recordIfChanged($book, 10.00);
    expect($book->priceHistory()->count())->toBe(1);

    // Diferença de 0.0005, abaixo da tolerância de 0.001
    $service->recordIfChanged($book, 10.0005);

    expect($book->priceHistory()->count())->toBe(1);
});

it('cria entrada em book_price_histories quando o preço muda', function () {
    $service = new BookPriceHistoryService();
    $book = makeBook(10.00);

    $service->recordIfChanged($book, 10.00);
    expect($book->priceHistory()->count())->toBe(1);

    $service->recordIfChanged($book, 12.50);
    $book->refresh();

    expect($book->priceHistory()->count())->toBe(2)
        ->and((float) $book->price)->toBe(12.50);
});
