<?php

use SchemaSketch\Laravel\Eloquent\FactoryWriter;
use SchemaSketch\Laravel\Eloquent\ModelWriter;
use SchemaSketch\Laravel\Eloquent\Naming;
use SchemaSketch\Laravel\Eloquent\Relations;
use SchemaSketch\Laravel\Migrations\Writer;
use SchemaSketch\Laravel\Schema\Differ;

// Diagram names come from whoever can edit the diagram, and push writes them
// into PHP files that later run. A name built to break out of a string must
// end up as plain text inside a string, never as code.
const EVIL = "x'); system('id'); //";

/**
 * Places where `system` appears as code rather than inside a string. Parsing
 * with TOKEN_PARSE also throws if the generated file isn't valid PHP.
 */
function injectedCode(string $php): array
{
    return array_values(array_filter(
        token_get_all($php, TOKEN_PARSE),
        fn ($t) => is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'system',
    ));
}

function evilSchema(): array
{
    return [
        'tables' => [
            tbl('users', [col('id', 'bigint', ['pk' => true, 'increment' => true])]),
            tbl(EVIL, [
                col('id', 'bigint', ['pk' => true, 'increment' => true]),
                col(EVIL, 'varchar', ['not_null' => true, 'unique' => true]),
                col('user_id', 'bigint', ['not_null' => true]),
                col('odd', "weird'type */ system('id'); /*"),
            ]),
        ],
        'refs' => [ref(EVIL.'.user_id', 'users.id')],
        'enums' => [],
    ];
}

it('keeps hostile names inside strings in the migration', function () {
    $diagram = evilSchema();
    $database = ['tables' => [tbl('old', [col('id'), col(EVIL)])], 'refs' => [], 'enums' => []];
    $differ = new Differ('sqlite');
    $php = (new Writer($differ))->write($diagram, $database, $differ->diff($diagram, $database), []);

    expect(injectedCode($php))->toBe([])
        ->and($php)->toContain("Schema::create('x\\'); system(\\'id\\'); //'");
});

it('keeps hostile names inside strings in models and factories', function () {
    $schema = evilSchema();
    $table = $schema['tables'][1];
    $relations = new Relations($schema);

    $model = (new ModelWriter)->write($table, $relations->for(EVIL), withFactory: true);
    $factory = (new FactoryWriter)->write($table, [], ['User']);
    $user = (new ModelWriter)->write($schema['tables'][0], $relations->for('users'));

    expect(injectedCode($model))->toBe([])
        ->and(injectedCode($factory))->toBe([])
        ->and(injectedCode($user))->toBe([]);
});

it('turns hostile table names into plain class and method names', function () {
    expect(Naming::model(EVIL))->toMatch('/^[A-Za-z_][A-Za-z0-9_]*$/')
        ->and(Naming::model('../../evil'))->toBe('evil')
        ->and(Naming::hasMany('9lives'))->toBe('_9lives');
});
