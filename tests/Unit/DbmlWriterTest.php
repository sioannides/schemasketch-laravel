<?php

use SchemaSketch\Laravel\Dbml\Writer;

it('writes a schema as DBML SchemaSketch can read', function () {
    $dbml = (new Writer)->write([
        'enums' => [['id' => null, 'name' => 'posts_status', 'values' => ['draft', 'published']]],
        'tables' => [[
            'id' => null, 'name' => 'order items', 'alias' => null, 'note' => "Line's items", 'color' => '#3498db',
            'columns' => [
                ['id' => null, 'name' => 'id', 'type' => 'bigint', 'pk' => true, 'not_null' => true, 'unique' => false, 'increment' => true, 'default' => null, 'note' => null],
                ['id' => null, 'name' => 'unit price', 'type' => 'decimal(8,2)', 'pk' => false, 'not_null' => true, 'unique' => false, 'increment' => false, 'default' => ['value' => '0', 'expression' => false], 'note' => null],
                ['id' => null, 'name' => 'status', 'type' => 'posts_status', 'pk' => false, 'not_null' => false, 'unique' => false, 'increment' => false, 'default' => ['value' => 'draft', 'expression' => false], 'note' => 'Workflow state'],
                ['id' => null, 'name' => 'created_at', 'type' => 'timestamp', 'pk' => false, 'not_null' => false, 'unique' => false, 'increment' => false, 'default' => ['value' => 'CURRENT_TIMESTAMP', 'expression' => true], 'note' => null],
            ],
            'indexes' => [['name' => 'items_status_created', 'columns' => ['status', 'created_at'], 'unique' => true, 'pk' => false]],
        ]],
        'refs' => [[
            'id' => null, 'from' => ['table' => 'order items', 'column' => 'id'], 'to' => ['table' => 'users', 'column' => 'id'],
            'kind' => 'many_to_one', 'on_delete' => 'cascade', 'on_update' => null, 'source' => 'App\Models\Item::user()',
        ]],
        'table_groups' => [['id' => null, 'name' => 'shop', 'color' => '#ff0000', 'tables' => ['order items']]],
    ]);

    expect($dbml)->toBe(<<<'DBML'
Enum posts_status {
  draft
  published
}

Table "order items" [headercolor: #3498db] {
  id bigint [pk, increment, not null]
  "unit price" decimal(8,2) [not null, default: 0]
  status posts_status [default: 'draft', note: 'Workflow state']
  created_at timestamp [default: `CURRENT_TIMESTAMP`]
  Note: 'Line\'s items'

  indexes {
    (status, created_at) [unique, name: 'items_status_created']
  }
}

// inferred from App\Models\Item::user()
Ref: "order items".id > users.id [delete: cascade]

TableGroup shop [color: #ff0000] {
  "order items"
}

DBML);
});

it('writes every relationship kind', function (string $kind, string $op) {
    $dbml = (new Writer)->write(['tables' => [], 'refs' => [[
        'from' => ['table' => 'a', 'column' => 'b_id'], 'to' => ['table' => 'b', 'column' => 'id'], 'kind' => $kind,
    ]]]);
    expect($dbml)->toContain("Ref: a.b_id {$op} b.id");
})->with([['many_to_one', '>'], ['one_to_many', '<'], ['one_to_one', '-'], ['many_to_many', '<>']]);
