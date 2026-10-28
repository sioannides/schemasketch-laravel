<?php

use SchemaSketch\Laravel\Schema\DbmlType;

it('turns database type names into DBML types', function (string $db, string $dbml) {
    expect(DbmlType::fromDatabase($db))->toBe($dbml);
})->with([
    ['integer', 'integer'],
    ['int(11)', 'int'],
    ['bigint unsigned', 'bigint'],
    ['bigint(20) unsigned', 'bigint'],
    ['tinyint(1)', 'boolean'],
    ['varchar(255)', 'varchar(255)'],
    ['character varying(255)', 'varchar(255)'],
    ['character varying', 'varchar'],
    ['character(36)', 'char(36)'],
    ['timestamp(0) without time zone', 'timestamp'],
    ['timestamp with time zone', 'timestamptz'],
    ['time without time zone', 'time'],
    ['double precision', 'double'],
    ['decimal(8,2)', 'decimal(8,2)'],
    ['numeric', 'numeric'],
    ['NUMERIC(10, 2)', 'numeric(10,2)'],
    ['jsonb', 'jsonb'],
]);
