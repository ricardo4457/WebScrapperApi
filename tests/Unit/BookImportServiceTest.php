<?php

use App\Http\Services\Book\BookImportService;
use App\Models\Book;
use App\Models\SchoolBook;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

if (! function_exists('bookItem')) {
    function bookItem(string $title, array $overrides = []): array
    {
        return array_merge([
            'title'          => $title,
            'publisher'      => 'Editora X',
            'type'           => 'manual',
            'price'          => 10.0,
            'year'           => '2026',
            'teaching_cycle' => '3ciclo',
        ], $overrides);
    }
}

if (! function_exists('schoolPayload')) {
    function schoolPayload(): array
    {
        return [
            'name'     => 'Escola Teste',
            'district' => 'Porto',
            'city'     => 'Porto',
        ];
    }
}

it('findOrCreateBook não duplica livro com o mesmo (title, publisher)', function () {
    $service = app(BookImportService::class);

    $payload = [[
        'school' => schoolPayload(),
        'items'  => [bookItem('Matemática 9')],
    ]];

    $service->import($payload);
    $service->import($payload);

    expect(Book::count())->toBe(1);
});

it('syncScope remove ligações school_books que já não vêm no payload', function () {
    $service = app(BookImportService::class);

    $itemA = bookItem('Livro A');
    $itemB = bookItem('Livro B');

    $service->import([[
        'school' => schoolPayload(),
        'items'  => [$itemA, $itemB],
    ]]);

    expect(SchoolBook::count())->toBe(2);

    // Segundo payload só traz o Livro A
    $service->import([[
        'school' => schoolPayload(),
        'items'  => [$itemA],
    ]]);

    $bookA = Book::where('title', 'Livro A')->first();
    $bookB = Book::where('title', 'Livro B')->first();

    expect(SchoolBook::count())->toBe(1)
        ->and(SchoolBook::where('book_id', $bookA->id)->exists())->toBeTrue()
        ->and(SchoolBook::where('book_id', $bookB->id)->exists())->toBeFalse();
});

it('syncScope adiciona só os livros novos (não recria os existentes)', function () {
    $service = app(BookImportService::class);

    $itemA = bookItem('Livro A');
    $itemB = bookItem('Livro B');

    $service->import([[
        'school' => schoolPayload(),
        'items'  => [$itemA],
    ]]);

    $originalLink = SchoolBook::first();

    $service->import([[
        'school' => schoolPayload(),
        'items'  => [$itemA, $itemB],
    ]]);

    expect(SchoolBook::count())->toBe(2);

    $linkA = SchoolBook::where('book_id', $originalLink->book_id)->first();

    // Mesma linha (mesmo id), não foi apagada e recriada
    expect($linkA->id)->toBe($originalLink->id)
        ->and($linkA->created_at->equalTo($originalLink->created_at))->toBeTrue();
});

it('item sem title é ignorado e contabilizado em skipped', function () {
    $service = app(BookImportService::class);

    $itemSemTitle = bookItem('placeholder');
    unset($itemSemTitle['title']);

    $report = $service->import([[
        'school' => schoolPayload(),
        'items'  => [$itemSemTitle],
    ]]);

    expect($report['skipped'])->toBe(1)
        ->and(Book::count())->toBe(0);
});
