<?php

use SchemaSketch\Laravel\Schema\Merger;

$diagram = [
    'tables' => [
        tbl('users', [col('id'), col('nickname', 'varchar')], ['note' => 'People', 'color' => '#ff0000']),
        tbl('invoices', [col('id'), col('user_id')]), // planned, not in the database yet
    ],
    'refs' => [ref('invoices.user_id', 'users.id'), ref('users.nickname', 'users.id')],
    'enums' => [['id' => null, 'name' => 'plan', 'values' => ['free', 'pro']]],
    'table_groups' => [['id' => null, 'name' => 'billing', 'color' => null, 'tables' => ['invoices', 'users', 'gone']]],
];

$database = [
    'tables' => [
        tbl('posts', [col('id'), col('user_id')]),
        tbl('users', [col('id'), col('email', 'varchar')]),
    ],
    'refs' => [ref('posts.user_id', 'users.id', 'foreign key')],
    'enums' => [],
    'table_groups' => [],
];

it('takes table structure from the database', function () use ($diagram, $database) {
    $merged = (new Merger)->merge($diagram, $database);
    $users = collect($merged['tables'])->firstWhere('name', 'users');

    expect(array_column($users['columns'], 'name'))->toBe(['id', 'email']);
});

it('keeps the diagram\'s notes and colors, planned tables, enums and groups', function () use ($diagram, $database) {
    $merged = (new Merger)->merge($diagram, $database);
    $users = collect($merged['tables'])->firstWhere('name', 'users');

    expect($users['note'])->toBe('People')
        ->and($users['color'])->toBe('#ff0000')
        ->and(array_column($merged['tables'], 'name'))->toBe(['users', 'invoices', 'posts'])
        ->and($merged['enums'][0]['name'])->toBe('plan')
        ->and($merged['table_groups'][0]['tables'])->toBe(['invoices', 'users']);
});

it('keeps diagram relationships whose columns still exist, and adds the database\'s', function () use ($diagram, $database) {
    $refs = collect((new Merger)->merge($diagram, $database)['refs'])
        ->map(fn ($r) => "{$r['from']['table']}.{$r['from']['column']}>{$r['to']['table']}.{$r['to']['column']}")
        ->all();

    // users.nickname is gone from the database, so its relationship goes too.
    expect($refs)->toBe(['posts.user_id>users.id', 'invoices.user_id>users.id']);
});

it('summarises what changes', function () use ($diagram, $database) {
    $merger = new Merger;
    $merger->merge($diagram, $database);

    expect($merger->summary)->toBe([
        'added' => ['posts'],
        'updated' => ['users'],
        'kept' => ['invoices'],
    ]);
});

it('with no diagram yet, is just the database', function () use ($database) {
    $merged = (new Merger)->merge(['tables' => [], 'refs' => [], 'enums' => [], 'table_groups' => []], $database);

    expect(array_column($merged['tables'], 'name'))->toBe(['posts', 'users']);
});
