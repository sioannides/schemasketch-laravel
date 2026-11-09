<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const EXPORT_API = 'https://schemasketch.test/api';

const SHOP_DBML = "// The shop\nTable users {\n  id bigint [pk]\n}\n";

beforeEach(function () {
    config([
        'schemasketch.api_url' => EXPORT_API,
        'schemasketch.token' => 'tok',
        'schemasketch.diagram' => 'd1',
    ]);
    Http::fake([
        EXPORT_API.'/diagrams/d1/schema' => Http::response([
            'version' => 3, 'name' => 'Shop', 'syntax_errors' => [], 'enums' => [], 'table_groups' => [],
            'tables' => [
                tbl('users', [col('id', 'bigint', ['pk' => true, 'not_null' => true])]),
                tbl('posts', [col('id', 'bigint', ['pk' => true, 'not_null' => true]), col('user_id', 'bigint', ['not_null' => true])]),
            ],
            'refs' => [ref('posts.user_id', 'users.id')],
        ]),
        EXPORT_API.'/diagrams/d1' => Http::response(['id' => 'd1', 'name' => 'Shop', 'dbml_source' => SHOP_DBML]),
    ]);
    $this->dir = sys_get_temp_dir().'/schemasketch-export-'.uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

it('prints the linked diagram as Mermaid', function () {
    $this->artisan('schemasketch:export', ['format' => 'mermaid'])
        ->expectsOutputToContain('erDiagram')
        ->expectsOutputToContain('users ||--o{ posts : "user_id"')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/diagrams/d1/schema'));
});

it('prints PlantUML', function () {
    $this->artisan('schemasketch:export', ['format' => 'plantuml'])
        ->expectsOutputToContain('@startuml')
        ->expectsOutputToContain('entity posts {')
        ->assertSuccessful();
});

it('saves the diagram\'s own DBML text, comments included', function () {
    $file = $this->dir.'/schema.dbml';

    $this->artisan('schemasketch:export', ['format' => 'dbml', '--output' => $file])->assertSuccessful();

    expect(file_get_contents($file))->toBe(SHOP_DBML);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/diagrams/d1'));
});

it('prints DBML without --output', function () {
    $this->artisan('schemasketch:export', ['format' => 'dbml'])
        ->expectsOutputToContain('Table users {')
        ->assertSuccessful();
});

it('uses --diagram over SCHEMASKETCH_DIAGRAM', function () {
    Http::fake([EXPORT_API.'/diagrams/other' => Http::response(['id' => 'other', 'name' => 'Other', 'dbml_source' => "Table x {\n  id int\n}\n"])]);

    $this->artisan('schemasketch:export', ['format' => 'dbml', '--diagram' => 'other'])
        ->expectsOutputToContain('Table x {')
        ->assertSuccessful();
});

it('explains what to do without a linked diagram', function () {
    config(['schemasketch.diagram' => null]);

    $this->artisan('schemasketch:export', ['format' => 'mermaid'])
        ->expectsOutputToContain('SCHEMASKETCH_DIAGRAM')
        ->assertFailed();
    Http::assertNothingSent();
});

it('explains what to do without a token', function () {
    config(['schemasketch.token' => null]);

    $this->artisan('schemasketch:export', ['format' => 'mermaid'])
        ->expectsOutputToContain('SCHEMASKETCH_TOKEN')
        ->assertFailed();
});

it('refuses to draw a diagram whose DBML has errors', function () {
    Http::fake([EXPORT_API.'/diagrams/broken/schema' => Http::response(['version' => 1, 'name' => 'B', 'syntax_errors' => ['Line 3: expected }'], 'tables' => [], 'refs' => []])]);

    $this->artisan('schemasketch:export', ['format' => 'mermaid', '--diagram' => 'broken'])
        ->expectsOutputToContain('Line 3: expected }')
        ->assertFailed();
});

it('rejects an unknown format', function () {
    $this->artisan('schemasketch:export', ['format' => 'sql'])
        ->expectsOutputToContain('Use dbml, mermaid or plantuml.')
        ->assertFailed();
    Http::assertNothingSent();
});

it('updates only the marked block in a README and leaves the rest alone', function () {
    $readme = $this->dir.'/README.md';
    file_put_contents($readme, "# My app\n\n<!-- schemasketch:start -->\nold\n<!-- schemasketch:end -->\n\nThe end.\n");

    $this->artisan('schemasketch:export', ['format' => 'mermaid', '--output' => $readme])->assertSuccessful();

    expect(file_get_contents($readme))->toStartWith("# My app\n\n<!-- schemasketch:start -->\n```mermaid\nerDiagram\n")
        ->toContain('users ||--o{ posts')
        ->toEndWith("```\n<!-- schemasketch:end -->\n\nThe end.\n");
});

it('writes a whole .mmd or .puml file', function (string $format, string $file, string $first) {
    $path = $this->dir.'/'.$file;

    $this->artisan('schemasketch:export', ['format' => $format, '--output' => $path])->assertSuccessful();

    expect(file_get_contents($path))->toStartWith($first);
})->with([
    ['mermaid', 'schema.mmd', "erDiagram\n"],
    ['mermaid', 'schema.mermaid', "erDiagram\n"],
    ['plantuml', 'schema.puml', "@startuml\n"],
]);

it('refuses a file type that does not match the format', function (string $format, string $file, string $hint) {
    $this->artisan('schemasketch:export', ['format' => $format, '--output' => $this->dir.'/'.$file])
        ->expectsOutputToContain($hint)
        ->assertFailed();

    expect(file_exists($this->dir.'/'.$file))->toBeFalse();
})->with([
    ['mermaid', 'schema.puml', '.md, .mmd or .mermaid'],
    ['dbml', 'README.md', '.dbml'],
]);

it('--check passes when the file is current and fails without writing when it is not', function () {
    $readme = $this->dir.'/README.md';
    file_put_contents($readme, "# My app\n");

    $this->artisan('schemasketch:export', ['format' => 'mermaid', '--output' => $readme, '--check' => true])
        ->expectsOutputToContain('is out of date')
        ->assertExitCode(1);
    expect(file_get_contents($readme))->toBe("# My app\n");

    $this->artisan('schemasketch:export', ['format' => 'mermaid', '--output' => $readme])->assertSuccessful();
    $this->artisan('schemasketch:export', ['format' => 'mermaid', '--output' => $readme, '--check' => true])
        ->expectsOutputToContain('is up to date')
        ->assertSuccessful();
});

it('--check needs --output', function () {
    $this->artisan('schemasketch:export', ['format' => 'mermaid', '--check' => true])
        ->expectsOutputToContain('--check needs --output')
        ->assertFailed();
});

it('reports a broken marker instead of guessing', function () {
    $readme = $this->dir.'/README.md';
    file_put_contents($readme, "<!-- schemasketch:start -->\nno end\n");

    $this->artisan('schemasketch:export', ['format' => 'mermaid', '--output' => $readme])
        ->expectsOutputToContain('schemasketch:end')
        ->assertFailed();
    expect(file_get_contents($readme))->toBe("<!-- schemasketch:start -->\nno end\n");
});
