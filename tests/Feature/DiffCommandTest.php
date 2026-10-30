<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use SchemaSketch\Laravel\Commands\DiffCommand;

// Runs against a real database (SQLite here, MySQL and PostgreSQL in CI),
// so the diagram below must match what every driver reports.
const DIFF_API = 'https://schemasketch.test/api';

beforeEach(function () {
    config(['schemasketch.api_url' => DIFF_API, 'schemasketch.token' => 'tok', 'schemasketch.diagram' => 'd1']);
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('email')->unique();
        $t->boolean('active')->default(true);
    });
    Schema::create('posts', function (Blueprint $t) {
        $t->id();
        $t->foreignId('user_id')->constrained()->cascadeOnDelete();
        $t->string('title', 120);
        $t->index(['user_id', 'title']);
    });
});

function matchingDiagram(array $override = []): array
{
    return [
        'version' => 4, 'name' => 'Shop', 'syntax_errors' => [], 'enums' => [], 'table_groups' => [],
        'tables' => [
            tbl('users', [
                col('id', 'bigint', ['pk' => true, 'increment' => true]),
                col('email', 'varchar(255)', ['not_null' => true, 'unique' => true]),
                col('active', 'boolean', ['not_null' => true, 'default' => ['value' => 'true', 'expression' => false]]),
            ]),
            tbl('posts', [
                col('id', 'bigint', ['pk' => true, 'increment' => true]),
                col('user_id', 'bigint', ['not_null' => true]),
                col('title', 'varchar(120)', ['not_null' => true]),
            ], ['indexes' => [['name' => null, 'columns' => ['user_id', 'title'], 'unique' => false, 'pk' => false]]]),
        ],
        'refs' => [[...ref('posts.user_id', 'users.id'), 'on_delete' => 'cascade']],
        ...$override,
    ];
}

function fakeDiagram(array $diagram): void
{
    Http::fake([DIFF_API.'/diagrams/d1/schema' => Http::response($diagram)]);
}

it('says so when the database matches, with exit code 0', function () {
    fakeDiagram(matchingDiagram());

    $this->artisan('schemasketch:diff')
        ->expectsOutputToContain('The database matches the diagram "Shop"')
        ->assertExitCode(DiffCommand::IN_STEP);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/diagrams/d1/schema'));
});

it('lists differences, with exit code 1', function () {
    $diagram = matchingDiagram();
    $diagram['tables'][] = tbl('invoices', [col('id', 'bigint', ['pk' => true])]);
    $diagram['tables'][0]['columns'][] = col('nickname', 'varchar(50)');
    fakeDiagram($diagram);

    $this->artisan('schemasketch:diff')
        ->expectsOutputToContain('In the diagram only | invoices')
        ->expectsOutputToContain('column nickname')
        ->expectsOutputToContain('2 difference(s)')
        ->assertExitCode(DiffCommand::DIFFERENT);
});

it('warns about changes that would drop something', function () {
    $diagram = matchingDiagram();
    array_pop($diagram['tables'][0]['columns']); // the diagram has no "active"
    fakeDiagram($diagram);

    $this->artisan('schemasketch:diff')
        ->expectsOutputToContain('In the database only')
        ->expectsOutputToContain('would drop or narrow something')
        ->assertExitCode(DiffCommand::DIFFERENT);
});

it('prints JSON for scripts', function () {
    $diagram = matchingDiagram();
    $diagram['tables'][1]['columns'][2]['type'] = 'int';
    fakeDiagram($diagram);

    expect(Artisan::call('schemasketch:diff', ['--json' => true]))->toBe(DiffCommand::DIFFERENT);

    $json = json_decode(Artisan::output(), true);
    expect($json['in_step'])->toBeFalse()
        ->and($json['diagram'])->toBe(['id' => 'd1', 'name' => 'Shop', 'version' => 4])
        ->and($json['changes'][0])->toMatchArray(['change' => 'changed', 'object' => 'column', 'table' => 'posts', 'name' => 'title']);
});

it('leaves out framework tables', function () {
    Schema::create('jobs', fn (Blueprint $t) => $t->id());
    fakeDiagram(matchingDiagram());

    $this->artisan('schemasketch:diff')->assertExitCode(DiffCommand::IN_STEP);
});

it('works with a read-only token: it never writes', function () {
    fakeDiagram(matchingDiagram());

    $this->artisan('schemasketch:diff')->assertExitCode(DiffCommand::IN_STEP);

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
});

it('exits 2 when it cannot check', function (Closure $setUp, string $message) {
    $setUp();

    $this->artisan('schemasketch:diff')
        ->expectsOutputToContain($message)
        ->assertExitCode(DiffCommand::CANNOT_CHECK);
})->with([
    'no diagram' => [fn () => config(['schemasketch.diagram' => null]), 'SCHEMASKETCH_DIAGRAM'],
    'no token' => [fn () => config(['schemasketch.token' => null]), 'SCHEMASKETCH_TOKEN'],
    'expired token' => [fn () => Http::fake([DIFF_API.'/*' => Http::response([], 401)]), 'invalid or has expired'],
    'no access' => [fn () => Http::fake([DIFF_API.'/*' => Http::response(['message' => "You don't have access to this diagram."], 403)]), "don't have access"],
    'service down' => [fn () => Http::fake([DIFF_API.'/*' => Http::response([], 503)]), 'Try again'],
    'broken DBML' => [fn () => fakeDiagram(matchingDiagram(['syntax_errors' => ['Table x missing closing }']])), 'Table x missing closing }'],
]);
