<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use SchemaSketch\Laravel\Api\Client;

it('names the package and the command in every request', function () {
    config(['schemasketch.api_url' => 'https://schemasketch.test/api', 'schemasketch.token' => 'tok', 'schemasketch.diagram' => 'd1']);
    Schema::create('notes', fn (Blueprint $t) => $t->id());
    Http::fake([
        'https://schemasketch.test/api/me' => Http::response(['email' => 'dev@example.com', 'token_access' => 'read-write']),
        'https://schemasketch.test/api/diagrams/d1/schema' => Http::response([
            'version' => 1, 'name' => 'Notes', 'syntax_errors' => [], 'enums' => [], 'table_groups' => [], 'refs' => [],
            'tables' => [tbl('notes', [col('id', 'bigint', ['pk' => true, 'increment' => true, 'not_null' => true])])],
        ]),
    ]);

    $this->artisan('schemasketch:status');

    Http::assertSent(fn (Request $r) => str_starts_with($r->header('User-Agent')[0] ?? '', 'schemasketch-laravel/')
        && $r->header('X-SchemaSketch-Action') === ['schemasketch:status']);
    // Nothing else about the app rides along.
    Http::assertNotSent(fn (Request $r) => $r->hasHeader('X-SchemaSketch-App') || $r->hasHeader('X-Laravel-Version'));
});

it('reports a clean version, or dev for a branch checkout', function () {
    expect(Client::version())->toMatch('/^(\d+\.\d+\.\d+|dev)$/');
    expect(Client::userAgent())->toBe('schemasketch-laravel/'.Client::version());
});

it('drops an action name that is not a plain command name', function () {
    Http::fake(['*' => Http::response(['email' => 'x@example.com'])]);

    (new Client('https://schemasketch.test/api', 'tok', 5, "evil\r\nX-Injected: 1"))->me();

    Http::assertSent(fn (Request $r) => ! $r->hasHeader('X-SchemaSketch-Action') && ! $r->hasHeader('X-Injected'));
});
