<?php

use SchemaSketch\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Schema document builders shared by the unit tests.
function col(string $name, string $type = 'int', array $extra = []): array
{
    return ['id' => null, 'name' => $name, 'type' => $type, 'pk' => false, 'not_null' => false, 'unique' => false, 'increment' => false, 'default' => null, 'note' => null, ...$extra];
}

function tbl(string $name, array $columns, array $extra = []): array
{
    return ['id' => null, 'name' => $name, 'alias' => null, 'note' => null, 'color' => null, 'columns' => $columns, 'indexes' => [], ...$extra];
}

function ref(string $from, string $to, string $source = 'diagram'): array
{
    [$ft, $fc] = explode('.', $from);
    [$tt, $tc] = explode('.', $to);

    return ['id' => null, 'from' => ['table' => $ft, 'column' => $fc], 'to' => ['table' => $tt, 'column' => $tc], 'kind' => 'many_to_one', 'on_delete' => null, 'on_update' => null, 'source' => $source];
}
