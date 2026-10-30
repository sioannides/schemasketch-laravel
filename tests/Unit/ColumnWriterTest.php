<?php

use SchemaSketch\Laravel\Migrations\ColumnWriter;

function blueprint(array $col, bool $unsigned = false, bool $change = false, array $enums = []): string
{
    return (new ColumnWriter($enums))->write($col, $unsigned, $change);
}

it('writes the Blueprint call for each type', function (string $type, string $expected) {
    expect(blueprint(col('c', $type, ['not_null' => true])))->toBe($expected);
})->with([
    ['int', "\$table->integer('c');"],
    ['bigint', "\$table->bigInteger('c');"],
    ['int8', "\$table->bigInteger('c');"],
    ['tinyint(1)', "\$table->boolean('c');"],
    ['varchar(120)', "\$table->string('c', 120);"],
    ['varchar', "\$table->string('c');"],
    ['char(2)', "\$table->char('c', 2);"],
    ['longtext', "\$table->longText('c');"],
    ['decimal(8,2)', "\$table->decimal('c', 8, 2);"],
    ['double', "\$table->double('c');"],
    ['timestamptz', "\$table->timestampTz('c');"],
    ['jsonb', "\$table->jsonb('c');"],
    ['uuid', "\$table->uuid('c');"],
    ['bytea', "\$table->binary('c');"],
]);

it('uses id() and the increments helpers', function () {
    expect(blueprint(col('id', 'int', ['pk' => true, 'increment' => true])))->toBe('$table->id();')
        ->and(blueprint(col('code', 'int', ['pk' => true, 'increment' => true])))->toBe("\$table->increments('code');")
        ->and(blueprint(col('n', 'bigint', ['increment' => true])))->toBe("\$table->bigIncrements('n');");
});

it('makes a column pointing at an auto-increment key unsigned', function () {
    expect(blueprint(col('user_id', 'bigint', ['not_null' => true]), unsigned: true))->toBe("\$table->unsignedBigInteger('user_id');");
});

it('adds modifiers', function () {
    expect(blueprint(col('c', 'varchar')))->toBe("\$table->string('c')->nullable();")
        ->and(blueprint(col('c', 'varchar', ['not_null' => true, 'unique' => true])))->toBe("\$table->string('c')->unique();")
        ->and(blueprint(col('c', 'varchar', ['pk' => true])))->toBe("\$table->string('c')->primary();")
        ->and(blueprint(col('c', 'int', ['not_null' => true, 'note' => "Bob's"])))->toBe("\$table->integer('c')->comment('Bob\\'s');");
});

it('writes defaults by type', function (string $type, array $default, string $expected) {
    expect(blueprint(col('c', $type, ['not_null' => true, 'default' => $default])))->toBe("\$table->{$expected};");
})->with([
    ['boolean', ['value' => 'true', 'expression' => false], "boolean('c')->default(true)"],
    ['boolean', ['value' => '0', 'expression' => false], "boolean('c')->default(false)"],
    ['int', ['value' => '5', 'expression' => false], "integer('c')->default(5)"],
    ['varchar', ['value' => '5', 'expression' => false], "string('c')->default('5')"],
    ['varchar', ['value' => "it's", 'expression' => false], "string('c')->default('it\\'s')"],
    ['timestamp', ['value' => 'now()', 'expression' => true], "timestamp('c')->useCurrent()"],
    ['uuid', ['value' => 'gen_random_uuid()', 'expression' => true], "uuid('c')->default(DB::raw('gen_random_uuid()'))"],
]);

it('remembers when a default needs the DB facade', function () {
    $writer = new ColumnWriter;
    $writer->write(col('c', 'uuid', ['default' => ['value' => 'gen_random_uuid()', 'expression' => true]]));

    expect($writer->usesDb)->toBeTrue();
});

it('writes enums from the schema, escaping values', function () {
    expect(blueprint(col('role', 'role', ['not_null' => true]), enums: ['role' => ['admin', "o'neil"]]))
        ->toBe("\$table->enum('role', ['admin', 'o\\'neil']);");
});

it('flags a type it does not know', function () {
    $writer = new ColumnWriter;

    expect($writer->write(col('area', 'geometry', ['not_null' => true])))->toContain('geometry')
        ->and($writer->unknownTypes)->toBe(['geometry']);
});

it('leaves indexes out of a ->change()', function () {
    expect(blueprint(col('c', 'varchar(50)', ['not_null' => true, 'unique' => true]), change: true))
        ->toBe("\$table->string('c', 50)->change();");
});
