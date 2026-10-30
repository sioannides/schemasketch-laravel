<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

// push --models --factories, for real: the generated migration runs, the
// generated models and factories load, records are created through the
// factories, and every kind of relationship returns what it should. Runs on
// SQLite here and on MySQL and PostgreSQL in CI.
const MODELS_API = 'https://schemasketch.test/api';

beforeEach(function () {
    // The migration's file name has the time in it; keep it still.
    $this->freezeTime();
    $this->dir = sys_get_temp_dir().'/schemasketch-models-'.uniqid();
    // A fresh namespace per test, since PHP can't load a class twice.
    $this->ns = 'Gen'.bin2hex(random_bytes(4));
    config([
        'schemasketch.api_url' => MODELS_API, 'schemasketch.token' => 'tok', 'schemasketch.diagram' => 'd1',
        'schemasketch.models_path' => "{$this->dir}/Models", 'schemasketch.models_namespace' => "{$this->ns}\\Models",
        'schemasketch.factories_path' => "{$this->dir}/factories", 'schemasketch.factories_namespace' => "{$this->ns}\\Factories",
    ]);

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('email')->unique();
    });
    // A model the app already has, with a method of its own.
    File::ensureDirectoryExists("{$this->dir}/Models");
    file_put_contents("{$this->dir}/Models/User.php", <<<PHP
<?php

namespace {$this->ns}\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected \$guarded = [];

    public \$timestamps = false;

    public function greeting(): string
    {
        return 'hi';
    }
}

PHP);

    Http::fake([MODELS_API.'/diagrams/d1/schema' => Http::response([
        'version' => 3, 'name' => 'Blog', 'syntax_errors' => [], 'warnings' => [], 'table_groups' => [], 'enums' => [],
        'tables' => [
            tbl('users', [col('id', 'bigint', ['pk' => true, 'increment' => true]), col('email', 'varchar(255)', ['not_null' => true, 'unique' => true])]),
            tbl('posts', [
                col('id', 'bigint', ['pk' => true, 'increment' => true]), col('user_id', 'bigint', ['not_null' => true]),
                col('title', 'varchar(160)', ['not_null' => true]), col('published', 'boolean', ['not_null' => true, 'default' => ['value' => 'false', 'expression' => false]]),
                col('created_at', 'timestamp'), col('updated_at', 'timestamp'),
            ]),
            tbl('tags', [col('id', 'bigint', ['pk' => true, 'increment' => true]), col('name', 'varchar(50)', ['not_null' => true, 'unique' => true])]),
            tbl('post_tag', [col('post_id', 'bigint', ['not_null' => true]), col('tag_id', 'bigint', ['not_null' => true])],
                ['indexes' => [['name' => null, 'columns' => ['post_id', 'tag_id'], 'unique' => false, 'pk' => true]]]),
            tbl('profiles', [col('id', 'bigint', ['pk' => true, 'increment' => true]), col('user_id', 'bigint', ['unique' => true]), col('bio', 'text')]),
        ],
        'refs' => [
            [...ref('posts.user_id', 'users.id'), 'on_delete' => 'cascade'],
            ref('post_tag.post_id', 'posts.id'), ref('post_tag.tag_id', 'tags.id'), ref('profiles.user_id', 'users.id'),
        ],
    ])]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('writes models and factories that work against the migrated database', function () {
    $this->artisan('schemasketch:push', ['--path' => "{$this->dir}/migrations", '--factories' => true, '--force' => true])
        ->expectsOutputToContain('Update ')
        ->expectsOutputToContain('Wrote 7 model and factory file(s).')
        ->assertSuccessful();

    // The files: new models (no model for the pivot), factories, and the
    // existing User model with only relationships added.
    expect(collect(File::files("{$this->dir}/Models"))->map->getFilename()->sort()->values()->all())
        ->toBe(['Post.php', 'Profile.php', 'Tag.php', 'User.php'])
        ->and(collect(File::files("{$this->dir}/factories"))->map->getFilename()->sort()->values()->all())
        ->toBe(['PostFactory.php', 'ProfileFactory.php', 'TagFactory.php']);
    $user = file_get_contents("{$this->dir}/Models/User.php");
    expect($user)->toContain("return 'hi';")->toContain('public function posts(): HasMany')
        ->toContain('public function profile(): HasOne')->toContain('protected $guarded = [];');

    expect(Artisan::call('migrate', ['--path' => "{$this->dir}/migrations", '--realpath' => true]))->toBe(0);
    foreach ([...File::files("{$this->dir}/Models"), ...File::files("{$this->dir}/factories")] as $file) {
        require_once $file->getPathname();
    }
    $User = "{$this->ns}\\Models\\User";
    $Post = "{$this->ns}\\Models\\Post";
    $Tag = "{$this->ns}\\Models\\Tag";
    $Profile = "{$this->ns}\\Models\\Profile";

    $author = $User::create(['email' => 'author@example.test']);
    // User has no factory, so the Post factory reuses an existing user.
    $post = $Post::factory()->create();
    $tag = $Tag::factory()->create();
    $post->tags()->attach($tag);
    $Profile::factory()->create(['user_id' => $author->id]);

    expect($post->user->is($author))->toBeTrue()                     // belongsTo
        ->and($author->posts()->count())->toBe(1)                  // hasMany (added to the existing model)
        ->and($author->profile)->toBeInstanceOf($Profile)          // hasOne (unique column)
        ->and($tag->posts()->first()->is($post))->toBeTrue()       // belongsToMany through the pivot
        ->and($post->published)->toBeBool()                        // cast
        ->and($author->greeting())->toBe('hi');                    // your own code untouched
});

it('creates models without factories when only --models is given', function () {
    $this->artisan('schemasketch:push', ['--path' => "{$this->dir}/migrations", '--models' => true, '--force' => true])->assertSuccessful();

    expect(File::isDirectory("{$this->dir}/factories"))->toBeFalse()
        ->and(file_get_contents("{$this->dir}/Models/Post.php"))->not->toContain('HasFactory');
});

it('asks before writing model files, and keeps the migration if you say no', function () {
    $this->artisan('schemasketch:push', ['--path' => "{$this->dir}/migrations", '--models' => true])
        ->expectsConfirmation('Write this migration to '.$this->dir.'/migrations/'.now()->format('Y_m_d_His').'_schemasketch_sync.php?', 'yes')
        ->expectsConfirmation('Write these 4 model and factory file(s)?', 'no')
        ->expectsOutputToContain('The migration was written')
        ->assertSuccessful();

    expect(glob("{$this->dir}/migrations/*.php"))->toHaveCount(1)
        ->and(File::exists("{$this->dir}/Models/Post.php"))->toBeFalse()
        ->and(file_get_contents("{$this->dir}/Models/User.php"))->not->toContain('posts()');
});
