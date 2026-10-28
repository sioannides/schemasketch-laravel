<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SchemaSketch\Laravel\Inspect\DatabaseSchema;
use SchemaSketch\Laravel\Inspect\ModelRelations;

beforeEach(function () {
    // No foreign keys at all: every relationship must come from the models.
    Schema::create('users', fn (Blueprint $t) => $t->id());
    Schema::create('posts', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
    });
    Schema::create('roles', fn (Blueprint $t) => $t->id());
    Schema::create('role_user', function (Blueprint $t) {
        $t->unsignedBigInteger('role_id');
        $t->unsignedBigInteger('user_id');
    });
});

function inferred(): ModelRelations
{
    $relations = new ModelRelations(__DIR__.'/../Fixtures/Models');
    $relations->addTo($schema = (new DatabaseSchema)->read(null, []));

    return $relations;
}

it('adds relationships the database has no foreign key for', function () {
    $relations = new ModelRelations(__DIR__.'/../Fixtures/Models');
    $schema = $relations->addTo((new DatabaseSchema)->read(null, []));

    $refs = collect($schema['refs'])->map(fn ($r) => "{$r['from']['table']}.{$r['from']['column']} {$r['kind']} {$r['to']['table']}.{$r['to']['column']}")->sort()->values()->all();

    expect($refs)->toBe([
        'posts.user_id many_to_one users.id',
        'role_user.role_id many_to_one roles.id',
        'role_user.user_id many_to_one users.id',
    ]);
});

it('notes which model method each relationship came from, once per pair', function () {
    $schema = (new ModelRelations(__DIR__.'/../Fixtures/Models'))->addTo((new DatabaseSchema)->read(null, []));
    $post = collect($schema['refs'])->first(fn ($r) => $r['from']['table'] === 'posts');

    // Post::user() and User::posts() describe the same link; it appears once.
    expect(collect($schema['refs'])->where('from.table', 'posts')->count())->toBe(1)
        ->and($post['source'])->toMatch('/Fixtures\\\\Models\\\\(User::posts|Post::user)\(\)$/');
});

it('reports what it skipped and why', function () {
    $relations = inferred();

    expect($relations->skipped)->toContain('SchemaSketch\Laravel\Tests\Fixtures\Models\User::images(): polymorphic relation')
        ->and(collect($relations->skipped)->first(fn ($s) => str_contains($s, 'User::broken()')))->toContain('side effect');
});

it('does not duplicate a foreign key the database already has', function () {
    Schema::drop('posts');
    Schema::create('posts', function (Blueprint $t) {
        $t->id();
        $t->foreignId('user_id')->constrained();
    });
    $schema = (new ModelRelations(__DIR__.'/../Fixtures/Models'))->addTo((new DatabaseSchema)->read(null, []));

    $postRefs = collect($schema['refs'])->where('from.table', 'posts')->values();
    expect($postRefs)->toHaveCount(1)->and($postRefs[0]['source'])->toBe('foreign key');
});

it('finds nothing in a folder that does not exist', function () {
    $schema = (new ModelRelations('/no/such/folder'))->addTo(['tables' => [], 'refs' => []]);
    expect($schema['refs'])->toBe([]);
});
