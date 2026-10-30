<?php

use SchemaSketch\Laravel\Schema\Differ;

function schema(array $tables, array $refs = [], array $enums = []): array
{
    return ['tables' => $tables, 'refs' => $refs, 'enums' => $enums, 'table_groups' => []];
}

function diffOf(array $diagram, array $database, string $driver = 'mysql', array $ignore = []): array
{
    return (new Differ($driver))->diff($diagram, $database, $ignore);
}

/** "added table invoices", "changed column users.email" … for compact assertions. */
function summary(array $changes): array
{
    return array_map(fn ($c) => "{$c['change']} {$c['object']} ".($c['object'] === 'table' ? $c['table'] : "{$c['table']}.{$c['name']}"), $changes);
}

it('finds nothing when both sides match', function () {
    $users = tbl('users', [col('id', 'bigint', ['pk' => true, 'increment' => true]), col('email', 'varchar(255)', ['not_null' => true])]);

    expect(diffOf(schema([$users]), schema([$users])))->toBe([]);
});

it('lists tables and columns on one side only', function () {
    $diagram = schema([tbl('users', [col('id'), col('nickname', 'varchar')]), tbl('invoices', [col('id')])]);
    $database = schema([tbl('users', [col('id'), col('email', 'varchar')]), tbl('posts', [col('id')])]);

    expect(summary(diffOf($diagram, $database)))->toBe([
        'added table invoices',
        'removed table posts',
        'added column users.nickname',
        'removed column users.email',
    ]);
});

it('marks drops as destructive, additions not', function () {
    $changes = collect(diffOf(
        schema([tbl('users', [col('id'), col('nickname')]), tbl('invoices', [col('id')])]),
        schema([tbl('users', [col('id'), col('email')]), tbl('posts', [col('id')])]),
    ))->mapWithKeys(fn ($c) => ["{$c['change']} {$c['name']}" => $c['destructive']]);

    expect($changes->all())->toBe([
        'added invoices' => false,
        'removed posts' => true,
        'added nickname' => false,
        'removed email' => true,
    ]);
});

it('compares type families, not names', function (string $diagramType, string $dbType, string $driver, bool $same) {
    $changes = diffOf(schema([tbl('t', [col('c', $diagramType)])]), schema([tbl('t', [col('c', $dbType)])]), $driver);

    expect($changes === [])->toBe($same);
})->with([
    'int vs bigint' => ['int', 'bigint', 'mysql', true],
    'int8 vs integer' => ['int8', 'integer', 'pgsql', true],
    'decimal vs numeric' => ['decimal(8,2)', 'numeric', 'sqlite', true],
    'string vs varchar' => ['string', 'varchar(255)', 'mysql', true],
    'timestamptz vs timestamp' => ['timestamptz', 'timestamp', 'pgsql', true],
    'json vs jsonb' => ['json', 'jsonb', 'pgsql', true],
    'json vs text on SQLite' => ['json', 'text', 'sqlite', true],
    'json vs text on MySQL' => ['json', 'text', 'mysql', false],
    'uuid vs char(36)' => ['uuid', 'char(36)', 'mysql', true],
    'boolean vs tinyint(1)' => ['boolean', 'tinyint(1)', 'mysql', true],
    'int vs varchar' => ['int', 'varchar', 'mysql', false],
    'unknown types by name' => ['geometry', 'GEOMETRY', 'mysql', true],
]);

it('reports a type change as destructive', function () {
    [$change] = diffOf(schema([tbl('t', [col('c', 'int')])]), schema([tbl('t', [col('c', 'varchar(20)')])]));

    expect($change['details'])->toBe([['field' => 'type', 'database' => 'varchar(20)', 'diagram' => 'int']])
        ->and($change['destructive'])->toBeTrue();
});

it('compares sizes only where the database keeps them', function () {
    $diagram = schema([tbl('t', [col('c', 'varchar(100)')])]);
    $database = schema([tbl('t', [col('c', 'varchar(255)')])]);

    expect(diffOf($diagram, $database, 'sqlite'))->toBe([]);

    [$narrower] = diffOf($diagram, $database, 'mysql');
    expect($narrower['destructive'])->toBeTrue();

    [$wider] = diffOf($database, $diagram, 'pgsql');
    expect($wider['destructive'])->toBeFalse();
});

it('ignores a size the diagram leaves out', function () {
    expect(diffOf(schema([tbl('t', [col('c', 'varchar')])]), schema([tbl('t', [col('c', 'varchar(255)')])])))->toBe([]);
});

it('compares enum columns by their values', function () {
    $diagram = schema([tbl('users', [col('role', 'role')])], [], [['id' => null, 'name' => 'role', 'values' => ['admin', 'member']]]);
    $same = schema([tbl('users', [col('role', 'users_role')])], [], [['id' => null, 'name' => 'users_role', 'values' => ['admin', 'member']]]);
    $more = schema([tbl('users', [col('role', 'users_role')])], [], [['id' => null, 'name' => 'users_role', 'values' => ['admin', 'member', 'guest']]]);

    expect(diffOf($diagram, $same))->toBe([]);

    [$change] = diffOf($diagram, $more);
    expect($change['details'][0])->toBe(['field' => 'values', 'database' => ['admin', 'member', 'guest'], 'diagram' => ['admin', 'member']])
        ->and($change['destructive'])->toBeTrue(); // "guest" would go
});

it('reports a text column the diagram turns into an enum, on every driver', function (string $driver) {
    $diagram = schema([tbl('users', [col('role', 'role')])], [], [['id' => null, 'name' => 'role', 'values' => ['a', 'b']]]);
    $database = schema([tbl('users', [col('role', 'varchar(255)')])]);

    expect(diffOf($diagram, $database, $driver))->not->toBe([]);
})->with(['mysql', 'pgsql', 'sqlite']);

it('reports flag changes', function () {
    [$change] = diffOf(
        schema([tbl('t', [col('c', 'int', ['not_null' => true, 'unique' => true])])]),
        schema([tbl('t', [col('c', 'int')])]),
    );

    expect(array_column($change['details'], 'field'))->toBe(['not null', 'unique'])
        ->and($change['destructive'])->toBeFalse();
});

it('knows a primary key is never null', function () {
    expect(diffOf(
        schema([tbl('t', [col('id', 'int', ['pk' => true])])]),
        schema([tbl('t', [col('id', 'int', ['pk' => true, 'not_null' => true])])]),
    ))->toBe([]);
});

it('compares defaults loosely', function (array $diagram, array $database, bool $same) {
    $changes = diffOf(schema([tbl('t', [col('c', 'int', ['default' => $diagram])])]), schema([tbl('t', [col('c', 'int', ['default' => $database])])]));

    expect($changes === [])->toBe($same);
})->with([
    'true vs 1' => [['value' => 'true', 'expression' => false], ['value' => '1', 'expression' => false], true],
    'now() vs CURRENT_TIMESTAMP' => [['value' => 'now()', 'expression' => true], ['value' => 'CURRENT_TIMESTAMP', 'expression' => true], true],
    '0.50 vs 0.5' => [['value' => '0.50', 'expression' => false], ['value' => '0.5', 'expression' => false], true],
    'different values' => [['value' => 'member', 'expression' => false], ['value' => 'admin', 'expression' => false], false],
]);

it('matches indexes by columns and kind, whatever their names', function () {
    $diagram = schema([tbl('t', [col('a'), col('b')], ['indexes' => [['name' => null, 'columns' => ['a', 'b'], 'unique' => true, 'pk' => false]]])]);
    $database = schema([tbl('t', [col('a'), col('b')], ['indexes' => [['name' => 't_a_b_unique', 'columns' => ['a', 'b'], 'unique' => true, 'pk' => false]]])]);
    $plain = schema([tbl('t', [col('a'), col('b')], ['indexes' => [['name' => 't_a_b_index', 'columns' => ['a', 'b'], 'unique' => false, 'pk' => false]]])]);

    expect(diffOf($diagram, $database))->toBe([])
        ->and(summary(diffOf($diagram, $plain)))->toBe(['added index t.unique(a, b)', 'removed index t.index(a, b)']);
});

it('reads a one-column unique index as a unique column', function () {
    $diagram = schema([tbl('t', [col('email')], ['indexes' => [['name' => null, 'columns' => ['email'], 'unique' => true, 'pk' => false]]])]);
    $database = schema([tbl('t', [col('email', 'int', ['unique' => true])])]);

    expect(diffOf($diagram, $database))->toBe([]);
});

it('ignores the index MySQL adds for a foreign key', function () {
    $diagram = schema([tbl('users', [col('id')]), tbl('posts', [col('id'), col('user_id')])], [ref('posts.user_id', 'users.id')]);
    $database = schema(
        [tbl('users', [col('id')]), tbl('posts', [col('id'), col('user_id')], ['indexes' => [['name' => 'posts_user_id_foreign', 'columns' => ['user_id'], 'unique' => false, 'pk' => false]]])],
        [ref('posts.user_id', 'users.id', 'foreign key')],
    );

    expect(diffOf($diagram, $database))->toBe([]);
});

it('compares foreign keys, whichever way the diagram writes them', function () {
    $tables = [tbl('users', [col('id')]), tbl('posts', [col('id'), col('user_id')])];
    $database = schema($tables, [ref('posts.user_id', 'users.id', 'foreign key')]);
    $reversed = ['id' => null, 'from' => ['table' => 'users', 'column' => 'id'], 'to' => ['table' => 'posts', 'column' => 'user_id'], 'kind' => 'one_to_many', 'on_delete' => null, 'on_update' => null];

    expect(diffOf(schema($tables, [$reversed]), $database))->toBe([])
        ->and(summary(diffOf(schema($tables), $database)))->toBe(['removed foreign key posts.user_id → users.id'])
        ->and(summary(diffOf(schema($tables, [ref('posts.user_id', 'users.id')]), schema($tables))))->toBe(['added foreign key posts.user_id → users.id']);
});

it('reports a changed on delete, and counts restrict as no action', function () {
    $tables = [tbl('users', [col('id')]), tbl('posts', [col('id'), col('user_id')])];
    $cascade = ref('posts.user_id', 'users.id');
    $cascade['on_delete'] = 'cascade';
    $restrict = ref('posts.user_id', 'users.id', 'foreign key');
    $restrict['on_delete'] = 'RESTRICT';

    [$change] = diffOf(schema($tables, [$cascade]), schema($tables, [$restrict]));
    expect($change['details'])->toBe([['field' => 'on delete', 'database' => 'no action', 'diagram' => 'cascade']])
        ->and(diffOf(schema($tables, [ref('posts.user_id', 'users.id')]), schema($tables, [$restrict])))->toBe([]);
});

it('skips refs without a foreign key, and refs inside added tables', function () {
    $manyToMany = ref('posts.id', 'tags.id');
    $manyToMany['kind'] = 'many_to_many';
    $diagram = schema(
        [tbl('users', [col('id')]), tbl('posts', [col('id')]), tbl('tags', [col('id')]), tbl('invoices', [col('id'), col('user_id')])],
        [$manyToMany, ref('invoices.user_id', 'users.id')],
    );
    $database = schema([tbl('users', [col('id')]), tbl('posts', [col('id')]), tbl('tags', [col('id')])]);

    expect(summary(diffOf($diagram, $database)))->toBe(['added table invoices']);
});

it('ignores model-inferred relationships on the database side', function () {
    $tables = [tbl('users', [col('id')]), tbl('posts', [col('id'), col('user_id')])];

    expect(diffOf(schema($tables), schema($tables, [ref('posts.user_id', 'users.id', 'model')])))->toBe([]);
});

it('leaves out ignored tables on both sides', function () {
    expect(diffOf(schema([tbl('users', [col('id')])]), schema([tbl('users', [col('id')]), tbl('migrations', [col('id')])]), 'mysql', ['migrations']))->toBe([]);
});
