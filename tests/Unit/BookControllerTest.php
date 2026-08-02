<?php

use App\Models\Book;
use App\Models\BookPriceHistory;
use App\Models\School;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);
// --- Test setup ----------------------------------------------------------
beforeEach(function () {
    Config::set('services.frontend.app_key', 'chave-secreta');
    Config::set('services.frontend.allowed_origins', ['http://localhost:5173']);
    Config::set('services.node_scraper.url', 'http://node-scraper.test');
    Config::set('services.node_scraper.callback_base_url', 'http://laravel.test');
});

// --- Protected endpoints: verify.app.key / verify.origin -----------------
if (! function_exists('protectedHeaders')) {
    function protectedHeaders(): array
    {
        return [
            'X-App-Key' => 'chave-secreta',
            'Origin'    => 'http://localhost:5173',
        ];
    }
}

// --- Helpers -------------------------------------------------------------
if (! function_exists('makeSchoolWithBook')) {
    function makeSchoolWithBook(array $schoolOverrides = [], array $pivot = []): array
    {
        $school = School::create(array_merge([
            'district' => 'Porto',
            'city'     => 'Ermesinde',
            'name'     => 'Escola Teste',
        ], $schoolOverrides));

        $book = Book::create([
            'title'      => 'Matemática 9',
            'publisher'  => 'Editora X',
            'price'      => 15.0,
            'type'       => 'manual',
            'discipline' => 'Matemática',
        ]);

        $school->books()->attach($book->id, array_merge([
            'year'           => '9º Ano',
            'teaching_cycle' => '3º Ciclo',
        ], $pivot));

        return [$school, $book];
    }
}

// --- GET /books/search ---------------------------------------------------

test('books/search sem X-App-Key devolve 401', function () {
    $response = $this->getJson('/api/books/search?q=matematica', [
        'Origin' => 'http://localhost:5173',
    ]);

    $response->assertStatus(401);
});

test('books/search com Origin não permitido devolve 403', function () {
    $response = $this->getJson('/api/books/search?q=matematica', [
        'X-App-Key' => 'chave-secreta',
        'Origin'    => 'https://site-desconhecido.com',
    ]);

    $response->assertStatus(403);
});

test('books/search sem q, school ou city devolve 422', function () {
    $response = $this->getJson('/api/books/search', protectedHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('q');
});

test('books/search com school sem year devolve 422', function () {
    $response = $this->getJson('/api/books/search?school=Escola+Teste', protectedHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('year');
});

test('books/search por título (q) devolve livros já em cache sem disparar scrape', function () {
    makeSchoolWithBook();

    Http::fake();

    $response = $this->getJson('/api/books/search?q=matematica', protectedHeaders());

    $response->assertStatus(200);
    $response->assertJsonPath('status', 'found');
    $response->assertJsonPath('mode', 'title');

    Http::assertNothingSent();
});

test('books/search por escola encontra livros já em cache', function () {
    makeSchoolWithBook();

    $response = $this->getJson('/api/books/search?school=Escola+Teste&year=9º+Ano', protectedHeaders());

    $response->assertStatus(200);
    $response->assertJsonPath('status', 'found');
    $response->assertJsonPath('mode', 'school');
});

test('books/search por escola sem livros em cache dispara scrape quando há district/city', function () {
    Http::fake([
        'node-scraper.test/*' => Http::response(['job_tokens' => ['a', 'b'], 'jobs_total' => 2], 200),
    ]);

    $response = $this->getJson(
        '/api/books/search?school=Escola+Nova&district=Porto&city=Ermesinde&year=9º+Ano',
        protectedHeaders()
    );

    $response->assertStatus(202);
    $response->assertJsonPath('status', 'scraping');
    $response->assertJsonStructure(['run_id', 'jobs_total']);
});

test('books/search por escola desconhecida sem district/city devolve 422', function () {
    Http::fake();

    $response = $this->getJson('/api/books/search?school=Escola+Nova&year=9º+Ano', protectedHeaders());

    $response->assertStatus(422);
    Http::assertNothingSent();
});

test('books/search por concelho encontra livros já em cache', function () {
    makeSchoolWithBook();

    $response = $this->getJson('/api/books/search?city=Ermesinde&year=9º+Ano', protectedHeaders());

    $response->assertStatus(200);
    $response->assertJsonPath('status', 'found');
    $response->assertJsonPath('mode', 'city');
});

// --- Price history -------------------------------------------------------

test('price-history devolve o histórico ordenado do mais recente para o mais antigo', function () {
    $book = Book::create([
        'title'     => 'Livro Teste',
        'publisher' => 'Editora X',
        'price'     => 12.0,
        'type'      => 'manual',
    ]);

    BookPriceHistory::create([
        'book_id'     => $book->id,
        'price'       => 10.0,
        'recorded_at' => now()->subDays(2),
    ]);

    BookPriceHistory::create([
        'book_id'     => $book->id,
        'price'       => 12.0,
        'recorded_at' => now(),
    ]);

    $response = $this->getJson("/api/books/{$book->id}/price-history");

    $response->assertStatus(200);

    $history = $response->json('history');

    expect($history)->toHaveCount(2)
        ->and((float) $history[0]['price'])->toBe(12.0)
        ->and((float) $history[1]['price'])->toBe(10.0);
});

// --- Schools -------------------------------------------------------------

test('schools filtra por district, city e search', function () {
    School::create(['district' => 'Porto', 'city' => 'Ermesinde', 'name' => 'Escola A']);
    School::create(['district' => 'Porto', 'city' => 'Valongo', 'name' => 'Escola B']);
    School::create(['district' => 'Lisboa', 'city' => 'Lisboa', 'name' => 'Escola C']);

    $response = $this->getJson('/api/schools?district=Porto&search=Escola+A');

    $response->assertStatus(200);

    $names = collect($response->json())->pluck('name');

    expect($names)->toEqual(collect(['Escola A']));
});

// --- Locations -----------------------------------------------------------

test('locations sem parâmetros devolve os distritos distintos', function () {
    School::create(['district' => 'Porto', 'city' => 'Ermesinde', 'name' => 'Escola A']);
    School::create(['district' => 'Porto', 'city' => 'Valongo', 'name' => 'Escola B']);
    School::create(['district' => 'Lisboa', 'city' => 'Lisboa', 'name' => 'Escola C']);

    $response = $this->getJson('/api/locations');

    $response->assertStatus(200);

    expect($response->json('districts'))->toEqual(['Lisboa', 'Porto']);
});

test('locations com district devolve as cidades desse distrito', function () {
    School::create(['district' => 'Porto', 'city' => 'Ermesinde', 'name' => 'Escola A']);
    School::create(['district' => 'Porto', 'city' => 'Valongo', 'name' => 'Escola B']);
    School::create(['district' => 'Lisboa', 'city' => 'Lisboa', 'name' => 'Escola C']);

    $response = $this->getJson('/api/locations?district=Porto');

    $response->assertStatus(200);

    expect($response->json('cities'))->toEqual(['Ermesinde', 'Valongo']);
});

// --- Disciplines ---------------------------------------------------------

test('disciplines devolve as disciplinas distintas, ignorando nulos', function () {
    Book::create([
        'title'      => 'Livro A',
        'price'      => 10,
        'type'       => 'manual',
        'discipline' => 'Matemática',
    ]);

    Book::create([
        'title'      => 'Livro B',
        'price'      => 10,
        'type'       => 'manual',
        'discipline' => 'Português',
    ]);

    Book::create([
        'title'      => 'Livro C',
        'price'      => 10,
        'type'       => 'manual',
        'discipline' => null,
    ]);

    $response = $this->getJson('/api/disciplines');

    $response->assertStatus(200);

    expect($response->json('disciplines'))->toEqual(['Matemática', 'Português']);
});
