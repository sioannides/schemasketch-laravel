<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use SchemaSketch\Laravel\Commands\DiffCommand;
use SchemaSketch\Laravel\Inspect\DatabaseSchema;

// The generated migration is run for real (SQLite here, MySQL and
// PostgreSQL in CI): after up() the database must match the diagram, and
// after down() it must match what it was before.
const PUSH_API = 'https://schemasketch.test/api';

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/schemasketch-push-'.uniqid();
    config(['schemasketch.api_url' => PUSH_API, 'schemasketch.token' => 'tok', 'schemasketch.diagram' => 'd1']);

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('email')->unique();
        $t->string('legacy_code')->nullable();
    });
    Schema::create('posts', function (Blueprint $t) {
        $t->id();
        $t->foreignId('user_id')->constrained();
        $t->string('title', 120);
        $t->index(['user_id', 'title']);
    });
});

afterEach(fn () => File::deleteDirectory($this->dir));

/** The diagram the database should end up matching. */
function targetDiagram(): array
{
    return [
        'version' => 9, 'name' => 'Shop', 'syntax_errors' => [], 'warnings' => [], 'table_groups' => [],
        'enums' => [['id' => null, 'name' => 'post_status', 'values' => ['draft', 'published']]],
        'tables' => [
            tbl('users', [
                col('id', 'bigint', ['pk' => true, 'increment' => true]),
                col('email', 'varchar(255)', ['not_null' => true, 'unique' => true]),
                col('nickname', 'varchar(50)'),
                col('active', 'boolean', ['not_null' => true, 'default' => ['value' => 'true', 'expression' => false]]),
            ]),
            tbl('posts', [
                col('id', 'bigint', ['pk' => true, 'increment' => true]),
                col('user_id', 'bigint', ['not_null' => true]),
                col('title', 'text', ['not_null' => true]),
                col('status', 'post_status', ['not_null' => true, 'default' => ['value' => 'draft', 'expression' => false]]),
            ], ['indexes' => [['name' => null, 'columns' => ['status'], 'unique' => false, 'pk' => false]]]),
            tbl('comments', [
                col('id', 'bigint', ['pk' => true, 'increment' => true]),
                col('post_id', 'bigint', ['not_null' => true]),
                col('body', 'text', ['not_null' => true]),
                col('created_at', 'timestamp'),
                col('updated_at', 'timestamp'),
            ]),
        ],
        'refs' => [
            [...ref('posts.user_id', 'users.id'), 'on_delete' => 'cascade'],
            ref('comments.post_id', 'posts.id'),
        ],
    ];
}

// Http::fake keeps the first response registered for a URL, so the fake
// reads whichever diagram was served last.
function serveDiagram(array $diagram): void
{
    if (! app()->bound('test.diagram')) {
        Http::fake([PUSH_API.'/diagrams/d1/schema' => fn () => Http::response(app('test.diagram'))]);
    }
    app()->instance('test.diagram', $diagram);
}

function writtenMigration(string $dir): string
{
    $files = glob("{$dir}/*_schemasketch_sync.php");
    expect($files)->toHaveCount(1);

    return file_get_contents($files[0]);
}

it('writes a migration that makes the database match, and rolls back cleanly', function () {
    $before = (new DatabaseSchema)->read(null);
    serveDiagram(targetDiagram());

    $this->artisan('schemasketch:push', ['--path' => $this->dir, '--force' => true])
        ->expectsOutputToContain('php artisan migrate')
        ->assertSuccessful();

    $php = writtenMigration($this->dir);
    expect($php)
        ->toContain("Schema::create('comments'")
        ->toContain('$table->timestamps();')
        ->toContain("\$table->enum('status', ['draft', 'published'])->default('draft');")
        ->toContain("\$table->dropColumn('legacy_code');")
        ->toContain('->cascadeOnDelete()');

    // up(): now the diagram and the database agree.
    expect(Artisan::call('migrate', ['--path' => $this->dir, '--realpath' => true]))->toBe(0);
    expect(Artisan::call('schemasketch:diff'))->toBe(DiffCommand::IN_STEP, Artisan::output());

    // down(): back to the database as it was.
    expect(Artisan::call('migrate:rollback', ['--path' => $this->dir, '--realpath' => true]))->toBe(0);
    serveDiagram(['version' => 1, 'name' => 'Before', 'syntax_errors' => [], ...$before]);
    expect(Artisan::call('schemasketch:diff'))->toBe(DiffCommand::IN_STEP, Artisan::output());
});

it('says when there is nothing to do', function () {
    serveDiagram(['version' => 1, 'name' => 'Shop', 'syntax_errors' => [], ...(new DatabaseSchema)->read(null)]);

    $this->artisan('schemasketch:push', ['--path' => $this->dir])
        ->expectsOutputToContain('already matches')
        ->assertSuccessful();

    expect(glob("{$this->dir}/*.php"))->toBe([]);
});

it('asks before writing, and writes nothing on no', function () {
    serveDiagram(targetDiagram());

    $this->artisan('schemasketch:push', ['--path' => $this->dir])
        ->expectsConfirmation('Write this migration to '.$this->dir.'/'.date('Y_m_d_His').'_schemasketch_sync.php?', 'no')
        ->expectsOutputToContain('Nothing written')
        ->assertFailed();

    expect(glob("{$this->dir}/*.php"))->toBe([]);
});

it('asks a second time when the migration drops something', function () {
    serveDiagram(targetDiagram());

    $this->artisan('schemasketch:push', ['--path' => $this->dir])
        ->expectsQuestion('Write this migration to '.$this->dir.'/'.date('Y_m_d_His').'_schemasketch_sync.php?', true)
        ->expectsOutputToContain('users: column legacy_code')
        ->expectsConfirmation('Data in these will be lost when the migration runs. Write it anyway?', 'no')
        ->assertFailed();

    expect(glob("{$this->dir}/*.php"))->toBe([]);
});

it('refuses a diagram with errors', function (array $problem, string $message) {
    serveDiagram([...targetDiagram(), ...$problem]);

    $this->artisan('schemasketch:push', ['--path' => $this->dir, '--force' => true])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(glob("{$this->dir}/*.php"))->toBe([]);
})->with([
    'syntax error' => [['syntax_errors' => ['Table x missing closing }']], 'Table x missing closing }'],
    'validator error' => [['warnings' => [['severity' => 'error', 'category' => 'Ref', 'message' => 'Ref to missing column posts.nope']]], 'posts.nope'],
]);

it('works with a read-only token: it only reads', function () {
    serveDiagram(targetDiagram());

    $this->artisan('schemasketch:push', ['--path' => $this->dir, '--force' => true])->assertSuccessful();

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
});

it('never runs the migration itself', function () {
    serveDiagram(targetDiagram());

    $this->artisan('schemasketch:push', ['--path' => $this->dir, '--force' => true])->assertSuccessful();

    expect(Schema::hasTable('comments'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'legacy_code'))->toBeTrue();
});
