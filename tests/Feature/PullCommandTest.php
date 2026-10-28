<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

const API = 'https://schemasketch.test/api';

beforeEach(function () {
    config([
        'schemasketch.api_url' => API,
        'schemasketch.token' => 'tok',
        'schemasketch.diagram' => null,
        'schemasketch.models_path' => __DIR__.'/../Fixtures/Models',
    ]);
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('email');
    });
    Schema::create('posts', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
    });
});

function me(string $access = 'read-write'): array
{
    return [API.'/me' => Http::response(['email' => 'a@b.c', 'token_access' => $access])];
}

it('prints DBML on --dry-run and uploads nothing', function () {
    Http::fake();

    $this->artisan('schemasketch:pull', ['--dry-run' => true])
        ->expectsOutputToContain('Table users {')
        ->expectsOutputToContain('Ref: posts.user_id > users.id') // from the models
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('skips model relationships with --no-models', function () {
    Http::fake();

    $this->artisan('schemasketch:pull', ['--dry-run' => true, '--no-models' => true])
        ->doesntExpectOutputToContain('Ref:')
        ->assertSuccessful();
});

it('saves DBML to a file with --output', function () {
    Http::fake();
    $file = sys_get_temp_dir().'/schemasketch-pull-test.dbml';

    $this->artisan('schemasketch:pull', ['--output' => $file])->assertSuccessful();

    expect(file_get_contents($file))->toContain('Table posts {');
    unlink($file);
    Http::assertNothingSent();
});

it('creates a new diagram when none is linked', function () {
    Http::fake([...me(), API.'/diagrams' => Http::response(['id' => 'new-id', 'name' => 'Shop', 'version' => 1], 201)]);

    $this->artisan('schemasketch:pull', ['--name' => 'Shop'])
        ->expectsOutputToContain('https://schemasketch.test/app?diagram=new-id')
        ->expectsOutputToContain('SCHEMASKETCH_DIAGRAM=new-id')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['name'] === 'Shop' && str_contains($r['dbml_source'], 'Table users'));
});

it('merges into a linked diagram, after asking', function () {
    config(['schemasketch.diagram' => 'd1']);
    Http::fake([
        ...me(),
        API.'/diagrams/d1/schema' => Http::response([
            'version' => 7, 'name' => 'Shop', 'syntax_errors' => [],
            'tables' => [['id' => null, 'name' => 'invoices', 'alias' => null, 'note' => null, 'color' => null, 'indexes' => [],
                'columns' => [['id' => null, 'name' => 'id', 'type' => 'int', 'pk' => true, 'not_null' => false, 'unique' => false, 'increment' => false, 'default' => null, 'note' => null]]]],
            'refs' => [], 'enums' => [], 'table_groups' => [],
        ]),
        API.'/diagrams/d1' => Http::response(['id' => 'd1', 'version' => 8]),
    ]);

    $this->artisan('schemasketch:pull')
        ->expectsOutputToContain('Added from the database: posts, users')
        ->expectsOutputToContain('Kept from the diagram: invoices')
        ->expectsConfirmation('Update the diagram "Shop"?', 'yes')
        ->expectsOutputToContain('Diagram updated')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
        && $r['base_version'] === 7
        && str_contains($r['dbml_source'], 'Table invoices')
        && str_contains($r['dbml_source'], 'Table users'));
});

it('changes nothing if you say no', function () {
    config(['schemasketch.diagram' => 'd1']);
    Http::fake([...me(), API.'/diagrams/d1/schema' => Http::response(['version' => 1, 'name' => 'Shop', 'syntax_errors' => [], 'tables' => [], 'refs' => [], 'enums' => [], 'table_groups' => []])]);

    $this->artisan('schemasketch:pull')
        ->expectsConfirmation('Update the diagram "Shop"?', 'no')
        ->assertFailed();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
});

it('refuses a read-only token before doing anything', function () {
    Http::fake(me('read-only'));

    $this->artisan('schemasketch:pull')
        ->expectsOutputToContain('read-only')
        ->assertFailed();

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
});

it('stops if someone saved the diagram in between', function () {
    config(['schemasketch.diagram' => 'd1']);
    Http::fake([
        ...me(),
        API.'/diagrams/d1/schema' => Http::response(['version' => 3, 'name' => 'Shop', 'syntax_errors' => [], 'tables' => [], 'refs' => [], 'enums' => [], 'table_groups' => []]),
        API.'/diagrams/d1' => Http::response(['message' => 'changed', 'current' => ['version' => 4]], 409),
    ]);

    $this->artisan('schemasketch:pull', ['--force' => true])
        ->expectsOutputToContain('changed in SchemaSketch while this ran')
        ->assertFailed();
});

it('refuses to merge into a diagram whose DBML has errors', function () {
    config(['schemasketch.diagram' => 'd1']);
    Http::fake([...me(), API.'/diagrams/d1/schema' => Http::response(['version' => 3, 'name' => 'Shop', 'syntax_errors' => ['Table x missing closing }'], 'tables' => [], 'refs' => [], 'enums' => [], 'table_groups' => []])]);

    $this->artisan('schemasketch:pull', ['--force' => true])
        ->expectsOutputToContain('Table x missing closing }')
        ->assertFailed();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
});

it('explains a missing token', function () {
    config(['schemasketch.token' => null]);

    $this->artisan('schemasketch:pull')
        ->expectsOutputToContain('SCHEMASKETCH_TOKEN')
        ->assertFailed();
});

it('explains an expired token', function () {
    Http::fake([API.'/me' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('schemasketch:pull')
        ->expectsOutputToContain('invalid or has expired')
        ->assertFailed();
});
