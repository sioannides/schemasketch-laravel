<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SchemaSketch\Laravel\Inspect\DatabaseSchema;

beforeEach(function () {
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('email')->unique();
        $t->string('role')->default('member');
        $t->boolean('active')->default(true);
        $t->timestamp('created_at')->useCurrent();
    });
    Schema::create('posts', function (Blueprint $t) {
        $t->id();
        $t->foreignId('user_id')->constrained()->cascadeOnDelete();
        $t->decimal('price', 8, 2)->nullable();
        $t->string('title');
        $t->index(['user_id', 'title'], 'posts_user_title');
    });
    Schema::create('migrations', function (Blueprint $t) {
        $t->id();
    });
});

function readSchema(): array
{
    return (new DatabaseSchema)->read(null, config('schemasketch.ignore_tables'));
}

it('reads tables, skipping framework tables', function () {
    expect(array_column(readSchema()['tables'], 'name'))->toBe(['posts', 'users']);
});

it('reads columns with keys, nullability, uniqueness and defaults', function () {
    $users = collect(readSchema()['tables'])->firstWhere('name', 'users');
    $cols = collect($users['columns'])->keyBy('name');

    expect($cols['id'])->toMatchArray(['pk' => true, 'increment' => true, 'not_null' => true])
        ->and($cols['email'])->toMatchArray(['unique' => true, 'not_null' => true, 'default' => null])
        ->and($cols['role']['default'])->toBe(['value' => 'member', 'expression' => false])
        ->and($cols['created_at']['default'])->toBe(['value' => 'CURRENT_TIMESTAMP', 'expression' => true])
        // The single-column unique index became the column's `unique` flag.
        ->and(collect($users['indexes'])->pluck('columns')->all())->toBe([]);
});

it('keeps multi-column indexes', function () {
    $posts = collect(readSchema()['tables'])->firstWhere('name', 'posts');

    expect($posts['indexes'])->toBe([
        ['name' => 'posts_user_title', 'columns' => ['user_id', 'title'], 'unique' => false, 'pk' => false],
    ]);
});

it('reads foreign keys as relationships', function () {
    // The foreign key's name differs by driver (SQLite has none), so it's
    // checked apart from the rest.
    $refs = readSchema()['refs'];
    expect($refs[0]['name'] === null || is_string($refs[0]['name']))->toBeTrue();
    unset($refs[0]['name']);

    expect($refs)->toBe([[
        'id' => null,
        'from' => ['table' => 'posts', 'column' => 'user_id'],
        'to' => ['table' => 'users', 'column' => 'id'],
        'kind' => 'many_to_one',
        'on_delete' => 'cascade',
        'on_update' => null,
        'source' => 'foreign key',
    ]]);
});

it('reads enum columns on every driver, including check-constraint enums', function () {
    Schema::create('tickets', function (Blueprint $t) {
        $t->id();
        $t->enum('status', ['open', 'in review', 'closed'])->default('open');
        $t->string('plain')->nullable();
    });

    $schema = readSchema();
    $tickets = collect($schema['tables'])->firstWhere('name', 'tickets');
    $columns = collect($tickets['columns'])->keyBy('name');

    expect($columns['status']['type'])->toBe('tickets_status')
        ->and(collect($schema['enums'])->firstWhere('name', 'tickets_status')['values'])->toBe(['open', 'in review', 'closed'])
        ->and($columns['plain']['type'])->not->toBe('tickets_plain');
});

it('leaves out the index MySQL adds for each foreign key', function () {
    $posts = collect(readSchema()['tables'])->firstWhere('name', 'posts');

    expect(collect($posts['indexes'])->pluck('columns')->all())->toBe([['user_id', 'title']]);
});
