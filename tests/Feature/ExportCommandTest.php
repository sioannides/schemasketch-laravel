<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config([
        'schemasketch.token' => null, // export never needs one
        'schemasketch.models_path' => __DIR__.'/../Fixtures/Models',
    ]);
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('email');
    });
    Schema::create('posts', function (Blueprint $t) {
        $t->id();
        $t->foreignId('user_id')->constrained();
    });
    $this->dir = sys_get_temp_dir().'/schemasketch-export-'.uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

it('prints a Mermaid diagram of the database without calling SchemaSketch', function () {
    Http::fake();

    $this->artisan('schemasketch:export', ['format' => 'mermaid'])
        ->expectsOutputToContain('erDiagram')
        ->expectsOutputToContain('users ||--o{ posts : "user_id"')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('prints PlantUML', function () {
    $this->artisan('schemasketch:export', ['format' => 'plantuml'])
        ->expectsOutputToContain('@startuml')
        ->expectsOutputToContain('entity posts {')
        ->assertSuccessful();
});

it('rejects an unknown format', function () {
    $this->artisan('schemasketch:export', ['format' => 'sql'])
        ->expectsOutputToContain('Use mermaid or plantuml.')
        ->assertFailed();
});

it('updates only the marked block in a README and leaves the rest alone', function () {
    $readme = $this->dir.'/README.md';
    file_put_contents($readme, "# My app\n\n<!-- schemasketch:start -->\nold\n<!-- schemasketch:end -->\n\nThe end.\n");

    $this->artisan('schemasketch:export', ['format' => 'mermaid', '--output' => $readme])->assertSuccessful();

    $content = file_get_contents($readme);
    expect($content)->toStartWith("# My app\n\n<!-- schemasketch:start -->\n```mermaid\nerDiagram\n")
        ->toContain('users ||--o{ posts')
        ->toEndWith("```\n<!-- schemasketch:end -->\n\nThe end.\n");
});

it('writes a whole .mmd or .puml file', function (string $format, string $file, string $first) {
    $path = $this->dir.'/'.$file;

    $this->artisan('schemasketch:export', ['format' => $format, '--output' => $path])->assertSuccessful();

    expect(file_get_contents($path))->toStartWith($first);
})->with([
    ['mermaid', 'database.mmd', "erDiagram\n"],
    ['mermaid', 'database.mermaid', "erDiagram\n"],
    ['plantuml', 'database.puml', "@startuml\n"],
]);

it('refuses a file type that does not match the format', function () {
    $this->artisan('schemasketch:export', ['format' => 'mermaid', '--output' => $this->dir.'/database.puml'])
        ->expectsOutputToContain('.md, .mmd or .mermaid')
        ->assertFailed();

    expect(file_exists($this->dir.'/database.puml'))->toBeFalse();
});

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

it('leaves model-only relationships out with --no-models', function () {
    Schema::create('comments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('post_id'); // no foreign key
    });

    $this->artisan('schemasketch:export', ['format' => 'mermaid', '--no-models' => true])
        ->doesntExpectOutputToContain('..')
        ->assertSuccessful();
});
