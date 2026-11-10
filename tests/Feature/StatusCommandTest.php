<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

const STATUS_API = 'https://schemasketch.test/api';

beforeEach(function () {
    config(['schemasketch.api_url' => STATUS_API, 'schemasketch.token' => 'tok', 'schemasketch.diagram' => 'd1']);
    Schema::create('notes', function (Blueprint $t) {
        $t->id();
    });
});

function statusDiagram(array $override = []): array
{
    return [
        'version' => 12, 'name' => 'Notes app', 'syntax_errors' => [], 'enums' => [], 'table_groups' => [], 'refs' => [],
        'tables' => [tbl('notes', [col('id', 'bigint', ['pk' => true, 'increment' => true, 'not_null' => true])])],
        ...$override,
    ];
}

function fakeStatus(string $access = 'read-write', ?array $diagram = null): void
{
    Http::fake([
        STATUS_API.'/me' => Http::response(['email' => 'dev@example.com', 'token_access' => $access]),
        STATUS_API.'/diagrams/d1/schema' => Http::response($diagram ?? statusDiagram()),
    ]);
}

it('reports everything set up and in step, with exit code 0', function () {
    fakeStatus();

    $this->artisan('schemasketch:status')
        // One check per printed line: each line satisfies only one expectation.
        ->expectsOutputToContain('dev@example.com, read & edit')
        ->expectsOutputToContain('"Notes app" (version 12)')
        ->expectsOutputToContain('https://schemasketch.test/app?diagram=d1')
        ->expectsOutputToContain('1 table')
        ->expectsOutputToContain('The database matches the diagram.')
        ->assertExitCode(0);
});

it('only reads: never creates or changes anything', function () {
    fakeStatus();

    $this->artisan('schemasketch:status')->assertExitCode(0);

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
});

it('says when the token is read-only, which is fine for checking', function () {
    fakeStatus('read-only');

    $this->artisan('schemasketch:status')
        ->expectsOutputToContain('read-only')
        ->expectsOutputToContain('pull and push need a "Read & edit" token')
        ->assertExitCode(0);
});

it('counts the differences and points to diff, with exit code 1', function () {
    fakeStatus(diagram: statusDiagram(['tables' => [
        tbl('notes', [col('id', 'bigint', ['pk' => true, 'increment' => true, 'not_null' => true])]),
        tbl('tags', [col('id', 'bigint', ['pk' => true, 'not_null' => true])]),
    ]]));

    $this->artisan('schemasketch:status')
        ->expectsOutputToContain('1 difference(s). See them with: php artisan schemasketch:diff')
        ->assertExitCode(1);
});

it('explains a missing token and checks nothing remote, with exit code 2', function () {
    config(['schemasketch.token' => null]);
    Http::fake();

    $this->artisan('schemasketch:status')
        ->expectsOutputToContain('Create one in SchemaSketch ("Connect AI agent") and set SCHEMASKETCH_TOKEN')
        ->assertExitCode(2);

    Http::assertNothingSent();
});

it('explains an invalid token, with exit code 2', function () {
    Http::fake([STATUS_API.'/me' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('schemasketch:status')
        ->expectsOutputToContain('invalid or has expired')
        ->assertExitCode(2);
});

it('says how to link a diagram when none is set, with exit code 2', function () {
    config(['schemasketch.diagram' => null]);
    fakeStatus();

    $this->artisan('schemasketch:status')
        ->expectsOutputToContain('dev@example.com') // the token is still checked
        ->expectsOutputToContain('No diagram linked. Run php artisan schemasketch:pull')
        ->assertExitCode(2);
});

it('uses --diagram over SCHEMASKETCH_DIAGRAM', function () {
    Http::fake([
        STATUS_API.'/me' => Http::response(['email' => 'dev@example.com', 'token_access' => 'read-write']),
        STATUS_API.'/diagrams/other/schema' => Http::response(statusDiagram(['name' => 'Other'])),
    ]);

    $this->artisan('schemasketch:status', ['--diagram' => 'other'])
        ->expectsOutputToContain('"Other"')
        ->assertExitCode(0);
});

it('reports a diagram it cannot open, with exit code 2', function () {
    Http::fake([
        STATUS_API.'/me' => Http::response(['email' => 'dev@example.com', 'token_access' => 'read-write']),
        STATUS_API.'/diagrams/d1/schema' => Http::response(['message' => 'Not found.'], 404),
    ]);

    $this->artisan('schemasketch:status')
        ->expectsOutputToContain('Diagram')
        ->assertExitCode(2);
});

it('reports DBML errors in the diagram, with exit code 2', function () {
    fakeStatus(diagram: statusDiagram(['syntax_errors' => ['Line 4: expected }']]));

    $this->artisan('schemasketch:status')
        ->expectsOutputToContain('Line 4: expected }')
        ->assertExitCode(2);
});

it('reports a database it cannot read, with exit code 2', function () {
    fakeStatus();

    $this->artisan('schemasketch:status', ['--connection' => 'nope'])
        ->expectsOutputToContain('Couldn\'t read the database')
        ->assertExitCode(2);
});
