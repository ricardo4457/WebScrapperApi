<?php

use App\Models\Book;
use App\Models\ScrapeRun;
use App\Models\ScrapeRunJob;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Testes de integração ao endpoint /api/book-scraper/callback.
 *
 * O fluxo atual difere do desenho inicial: o worker Node envia vários
 * callbacks "partial" (lotes de livros, à medida que vão sendo extraídos)
 * e um único callback "final" por job (completed ou failed), cada um
 * identificado por um número de tentativa (attempt), usado para distinguir
 * callbacks de uma tentativa anterior (retry do BullMQ) dos da tentativa
 * atual. A decisão de ignorar callbacks para um run já terminado foi
 * também movida do serviço para o controller.
 */

function createActiveRun(array $overrides = []): ScrapeRun
{
    return ScrapeRun::create(array_merge([
        'token'      => 'run-token-teste',
        'status'     => 'running',
        'jobs_total' => 1,
        'jobs_done'  => 0,
        'jobs_failed' => 0,
        'params'     => [],
    ], $overrides));
}

function createJob(ScrapeRun $run, array $overrides = []): ScrapeRunJob
{
    return $run->jobs()->create(array_merge([
        'job_token'         => 'job-token-teste',
        'status'            => 'pending',
        'last_attempt_seen' => 0,
    ], $overrides));
}

function bookBatch(string $title = 'Manual de Teste'): array
{
    return [[
        'school' => ['name' => 'Escola Teste', 'district' => 'Porto', 'city' => 'Ermesinde'],
        'items'  => [[
            'title'          => $title,
            'publisher'      => 'Editora Teste',
            'price'          => 10.5,
            'type'           => 'Livro',
            'year'           => '5º Ano',
            'teaching_cycle' => '2º Ciclo',
        ]],
    ]];
}

// --- VerifyNodeApiKey ----------------------------------------------------

test('callback sem run_token devolve 401', function () {
    $response = $this->postJson(route('book-scraper.callback'), [
        'job_token' => 'job-token-teste',
        'status'    => 'partial',
        'attempt'   => 0,
    ]);

    $response->assertStatus(401);
});

test('callback com run_token inválido devolve 401', function () {
    $response = $this->postJson(route('book-scraper.callback'), [
        'run_token' => 'token-que-nao-existe',
        'job_token' => 'job-token-teste',
        'status'    => 'partial',
        'attempt'   => 0,
    ]);

    $response->assertStatus(401);
});

test('callback com run_token de uma execução já terminada passa o middleware, mas é ignorado pelo controller', function () {
    // O middleware só confirma que o token pertence a um run conhecido;
    // decidir se esse run ainda está ativo já não é responsabilidade dele.
    $run = createActiveRun(['status' => 'completed', 'jobs_done' => 1]);
    $job = createJob($run, ['status' => 'completed', 'books_imported' => 3]);

    $response = $this->postJson(route('book-scraper.callback'), [
        'run_token' => $run->token,
        'job_token' => $job->job_token,
        'status'    => 'partial',
        'attempt'   => 0,
        'books'     => bookBatch(),
    ]);

    // Passou o middleware (não é 401) mas o controller ignora-o.
    $response->assertStatus(200);
    $response->assertJson(['message' => 'Run already finished; callback ignored.']);

    $job->refresh();
    expect($job->books_imported)->toBe(3);
    expect(Book::count())->toBe(0);
});

// --- Validação do payload --------------------------------------------------

test('callback sem o campo status devolve 422', function () {
    $run = createActiveRun();
    createJob($run);

    $response = $this->postJson(route('book-scraper.callback'), [
        'run_token' => $run->token,
        'job_token' => 'job-token-teste',
        'attempt'   => 0,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('status');
});

test('callback sem o campo attempt devolve 422', function () {
    $run = createActiveRun();
    createJob($run);

    $response = $this->postJson(route('book-scraper.callback'), [
        'run_token' => $run->token,
        'job_token' => 'job-token-teste',
        'status'    => 'partial',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('attempt');
});

test('callback com status fora dos valores permitidos devolve 422', function () {
    $run = createActiveRun();
    createJob($run);

    $response = $this->postJson(route('book-scraper.callback'), [
        'run_token' => $run->token,
        'job_token' => 'job-token-teste',
        'status'    => 'queued',
        'attempt'   => 0,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('status');
});

test('callback com books num formato inválido devolve 422', function () {
    $run = createActiveRun();
    createJob($run);

    $response = $this->postJson(route('book-scraper.callback'), [
        'run_token' => $run->token,
        'job_token' => 'job-token-teste',
        'status'    => 'partial',
        'attempt'   => 0,
        'books'     => 'isto-nao-e-um-array',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('books');
});

// --- Lotes parciais (streaming) --------------------------------------------

test('callback partial importa o lote e marca o job como running', function () {
    $run = createActiveRun();
    $job = createJob($run);

    $response = $this->postJson(route('book-scraper.callback'), [
        'run_token' => $run->token,
        'job_token' => $job->job_token,
        'status'    => 'partial',
        'attempt'   => 0,
        'books'     => bookBatch(),
    ]);

    $response->assertStatus(200);

    $job->refresh();
    expect($job->status)->toBe('running');
    expect($job->books_imported)->toBe(1);
    expect(Book::where('title', 'Manual de Teste')->count())->toBe(1);

    // O run só se fecha quando o job recebe o callback final.
    $run->refresh();
    expect($run->status)->toBe('running');
    expect($run->jobs_done)->toBe(0);
});

test('lote parcial de uma tentativa antiga (attempt inferior) é ignorado', function () {
    $run = createActiveRun();
    $job = createJob($run, ['last_attempt_seen' => 2, 'books_imported' => 5]);

    $response = $this->postJson(route('book-scraper.callback'), [
        'run_token' => $run->token,
        'job_token' => $job->job_token,
        'status'    => 'partial',
        'attempt'   => 1, // tentativa anterior à já registada (2)
        'books'     => bookBatch('Livro de uma Tentativa Antiga'),
    ]);

    $response->assertStatus(200);

    $job->refresh();
    // Nada deve ter mudado: o lote pertence a uma tentativa já superada.
    expect($job->books_imported)->toBe(5);
    expect(Book::where('title', 'Livro de uma Tentativa Antiga')->count())->toBe(0);
});

// --- Callback final ---------------------------------------------------------

test('callback final completed fecha o job e o run quando não há mais jobs pendentes', function () {
    $run = createActiveRun();
    $job = createJob($run);

    $this->postJson(route('book-scraper.callback'), [
        'run_token' => $run->token,
        'job_token' => $job->job_token,
        'status'    => 'partial',
        'attempt'   => 0,
        'books'     => bookBatch(),
    ])->assertStatus(200);

    $response = $this->postJson(route('book-scraper.callback'), [
        'run_token' => $run->token,
        'job_token' => $job->job_token,
        'status'    => 'completed',
        'final'     => true,
        'attempt'   => 0,
        'books'     => [],
    ]);

    $response->assertStatus(200);

    $job->refresh();
    $run->refresh();

    expect($job->status)->toBe('completed');
    expect($run->jobs_done)->toBe(1);
    expect($run->status)->toBe('completed');
});

// --- Idempotência ------------------------------------------------------------

test('callback final duplicado com o mesmo job_token não reprocessa o job', function () {
    // jobs_total = 2 para o run não se fechar logo a seguir ao primeiro
    // callback final, isolando a duplicação do encerramento do run.
    $run = createActiveRun(['jobs_total' => 2]);
    $job = createJob($run, ['job_token' => 'job-token-duplicado']);
    createJob($run, ['job_token' => 'job-token-em-falta']);

    $finalPayload = [
        'run_token' => $run->token,
        'job_token' => 'job-token-duplicado',
        'status'    => 'completed',
        'final'     => true,
        'attempt'   => 0,
        'books'     => [],
    ];

    $this->postJson(route('book-scraper.callback'), $finalPayload)->assertStatus(200);
    $job->refresh();
    $firstReportedAt = $job->reported_at;

    $this->postJson(route('book-scraper.callback'), $finalPayload)->assertStatus(200);
    $job->refresh();
    $run->refresh();

    expect($job->status)->toBe('completed');
    expect($job->reported_at->equalTo($firstReportedAt))->toBeTrue();
    // Se tivesse reprocessado, jobs_done teria sido incrementado duas vezes.
    expect($run->jobs_done)->toBe(1);
});
