<?php

use SchemaSketch\Laravel\Diagrams\MarkdownBlock;
use SchemaSketch\Laravel\Diagrams\MermaidWriter;
use SchemaSketch\Laravel\Diagrams\PlantUmlWriter;

function sampleSchema(): array
{
    return [
        'enums' => [],
        'tables' => [
            ['name' => 'users', 'columns' => [col('id', 'bigint', ['pk' => true, 'not_null' => true]), col('email', 'varchar(255)', ['unique' => true, 'not_null' => true])]],
            ['name' => 'posts', 'columns' => [
                col('id', 'bigint', ['pk' => true, 'not_null' => true]),
                col('user_id', 'bigint', ['not_null' => true]),
                col('editor_id', 'bigint', ['not_null' => false]),
                col('published at', 'timestamp without time zone', ['not_null' => true]),
            ]],
        ],
        'refs' => [
            ['kind' => 'many_to_one', 'from' => ['table' => 'posts', 'column' => 'user_id'], 'to' => ['table' => 'users', 'column' => 'id'], 'source' => 'foreign key'],
            ['kind' => 'many_to_one', 'from' => ['table' => 'posts', 'column' => 'editor_id'], 'to' => ['table' => 'users', 'column' => 'id'], 'source' => 'App\\Models\\Post::editor()'],
        ],
    ];
}

it('writes a Mermaid erDiagram with keys, foreign keys and relationship lines', function () {
    $out = (new MermaidWriter)->write(sampleSchema());

    expect($out)
        ->toStartWith("erDiagram\n")
        ->toContain("    users {\n        bigint id PK\n        varchar(255) email UK\n    }")
        ->toContain('        bigint user_id FK')
        ->toContain('        timestamp_without_time_zone published_at')
        ->toContain('    users ||--o{ posts : "user_id"')          // foreign key: solid
        ->toContain('    users |o..o{ posts : "editor_id"');       // nullable, from a model only: dashed
});

it('writes PlantUML entities with keys and relationship lines', function () {
    $out = (new PlantUmlWriter)->write(sampleSchema());

    expect($out)
        ->toStartWith("@startuml\n")
        ->toEndWith("@enduml\n")
        ->toContain("entity posts {\n  * id : bigint\n  --\n  user_id : bigint <<FK>>")
        ->toContain('  email : varchar(255) <<unique>>')
        ->toContain('users ||--o{ posts : user_id')
        ->toContain('users |o..o{ posts : editor_id');
});

it('lists tables and relationships in a stable order whatever the database returned', function () {
    $schema = sampleSchema();
    $reversed = $schema;
    $reversed['tables'] = array_reverse($schema['tables']);
    $reversed['refs'] = array_reverse($schema['refs']);

    expect((new MermaidWriter)->write($reversed))->toBe((new MermaidWriter)->write($schema))
        ->and((new PlantUmlWriter)->write($reversed))->toBe((new PlantUmlWriter)->write($schema));
});

it('never lets a name break out of the diagram or the Markdown block', function () {
    $evil = '```<img src=x onerror=alert(1)>"--> x';
    $schema = ['tables' => [['name' => $evil, 'columns' => [col($evil, $evil."\n}")]]], 'refs' => []];

    foreach ([new MermaidWriter, new PlantUmlWriter] as $writer) {
        $out = $writer->write($schema);
        expect($out)->not->toContain('`')->not->toContain('<img')->not->toContain('-->')
            ->and(substr_count($out, '}'))->toBe(1);
    }
});

it('replaces only the marked block in a Markdown file', function () {
    $doc = "# App\n\nIntro.\n\n<!-- schemasketch:start -->\nold\n<!-- schemasketch:end -->\n\nMore text.\n";

    $new = MarkdownBlock::replace($doc, 'mermaid', "erDiagram\n");

    expect($new)->toBe("# App\n\nIntro.\n\n<!-- schemasketch:start -->\n```mermaid\nerDiagram\n```\n<!-- schemasketch:end -->\n\nMore text.\n")
        ->and(MarkdownBlock::replace($new, 'mermaid', "erDiagram\n"))->toBe($new); // idempotent
});

it('appends the block when the markers are missing', function () {
    expect(MarkdownBlock::replace("# App\n", 'plantuml', "@startuml\n@enduml\n"))
        ->toBe("# App\n\n<!-- schemasketch:start -->\n```plantuml\n@startuml\n@enduml\n```\n<!-- schemasketch:end -->\n");
});

it('keeps Windows line endings in a Markdown file that uses them', function () {
    $doc = "# App\r\n\r\n<!-- schemasketch:start -->\r\nold\r\n<!-- schemasketch:end -->\r\n";

    expect(MarkdownBlock::replace($doc, 'mermaid', "erDiagram\n    a {\n    }\n"))
        ->toBe("# App\r\n\r\n<!-- schemasketch:start -->\r\n```mermaid\r\nerDiagram\r\n    a {\r\n    }\r\n```\r\n<!-- schemasketch:end -->\r\n");
});

it('refuses a file with a start marker but no end marker, or more than one block', function (string $doc) {
    MarkdownBlock::replace($doc, 'mermaid', "erDiagram\n");
})->with([
    'no end' => "<!-- schemasketch:start -->\nold\n",
    'two blocks' => "<!-- schemasketch:start -->\n<!-- schemasketch:end -->\n<!-- schemasketch:start -->\n<!-- schemasketch:end -->\n",
])->throws(InvalidArgumentException::class);

it('keeps precision like decimal(8,2) in a form each format accepts', function () {
    $schema = ['tables' => [['name' => 'orders', 'columns' => [col('total', 'decimal(8,2)', ['not_null' => true])]]], 'refs' => []];

    // Mermaid can't parse a comma in a type, so the precision goes into the column's comment.
    expect((new MermaidWriter)->write($schema))->toContain('        decimal total "(8,2)"')
        ->and((new PlantUmlWriter)->write($schema))->toContain('  total : decimal(8,2)');
});

it('does not mark the columns of a many-to-many relationship as foreign keys', function () {
    $schema = [
        'tables' => [
            ['name' => 'users', 'columns' => [col('id', 'bigint', ['pk' => true, 'not_null' => true])]],
            ['name' => 'roles', 'columns' => [col('id', 'bigint', ['pk' => true, 'not_null' => true])]],
        ],
        'refs' => [['kind' => 'many_to_many', 'from' => ['table' => 'users', 'column' => 'id'], 'to' => ['table' => 'roles', 'column' => 'id'], 'source' => 'App\\Models\\User::roles()']],
    ];

    expect((new MermaidWriter)->write($schema))->toContain('        bigint id PK')->not->toContain('FK')
        ->toContain('    users }o..o{ roles : "id"');
});
