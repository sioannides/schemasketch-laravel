<?php

use SchemaSketch\Laravel\Eloquent\FactoryWriter;
use SchemaSketch\Laravel\Eloquent\ModelUpdater;
use SchemaSketch\Laravel\Eloquent\ModelWriter;
use SchemaSketch\Laravel\Eloquent\Naming;
use SchemaSketch\Laravel\Eloquent\Relations;

function blogSchema(): array
{
    return [
        'tables' => [
            tbl('users', [col('id', 'bigint', ['pk' => true, 'increment' => true]), col('email', 'varchar', ['not_null' => true, 'unique' => true])]),
            tbl('posts', [
                col('id', 'bigint', ['pk' => true, 'increment' => true]), col('user_id', 'bigint', ['not_null' => true]),
                col('title', 'varchar(120)', ['not_null' => true]), col('price', 'decimal(8,2)'), col('published', 'boolean'),
                col('meta', 'json'), col('status', 'post_status', ['not_null' => true]),
                col('created_at', 'timestamp'), col('updated_at', 'timestamp'), col('deleted_at', 'timestamp'),
            ]),
            tbl('profiles', [col('id', 'bigint', ['pk' => true, 'increment' => true]), col('user_id', 'bigint', ['unique' => true])]),
            tbl('tags', [col('id', 'uuid', ['pk' => true]), col('name', 'varchar')]),
            tbl('post_tag', [col('post_id', 'bigint'), col('tag_id', 'uuid')], ['indexes' => [['name' => null, 'columns' => ['post_id', 'tag_id'], 'unique' => false, 'pk' => true]]]),
        ],
        'refs' => [ref('posts.user_id', 'users.id'), ref('profiles.user_id', 'users.id'), ref('post_tag.post_id', 'posts.id'), ref('post_tag.tag_id', 'tags.id')],
        'enums' => [['id' => null, 'name' => 'post_status', 'values' => ['draft', 'live']]],
    ];
}

it('follows Laravel naming', function () {
    expect(Naming::model('order_items'))->toBe('OrderItem')
        ->and(Naming::model('categories'))->toBe('Category')
        ->and(Naming::belongsTo('author_user_id'))->toBe('authorUser')
        ->and(Naming::hasMany('order_items'))->toBe('orderItems')
        ->and(Naming::hasOne('profiles'))->toBe('profile');
});

it('finds relationships on both sides, hasOne for unique columns, belongsToMany through pivots', function () {
    $r = new Relations(blogSchema());
    $names = fn ($t) => array_column($r->for($t), 'kind', 'name');

    expect($r->pivots)->toBe(['post_tag'])
        ->and($names('users'))->toBe(['posts' => 'HasMany', 'profile' => 'HasOne'])
        ->and($names('posts'))->toBe(['user' => 'BelongsTo', 'tags' => 'BelongsToMany'])
        ->and($names('tags'))->toBe(['posts' => 'BelongsToMany'])
        ->and($r->for('post_tag'))->toBe([]);
});

it('writes a model with fillable, casts, traits and relationships', function () {
    $schema = blogSchema();
    $posts = $schema['tables'][1];
    $php = (new ModelWriter)->write($posts, (new Relations($schema))->for('posts'), withFactory: true, enums: ['post_status' => ['draft', 'live']]);

    expect($php)
        ->toContain('namespace App\Models;')
        ->toContain('class Post extends Model')
        ->toContain('use HasFactory, SoftDeletes;')
        ->toContain("'user_id',\n        'title',")
        ->not->toContain("'created_at',")
        ->toContain("'price' => 'decimal:2'")
        ->toContain("'published' => 'boolean'")
        ->toContain("'meta' => 'array'")
        ->not->toContain("'status' =>") // enums aren't cast
        ->toContain("return \$this->belongsTo(User::class, 'user_id', 'id');")
        ->toContain("return \$this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');")
        ->toContain('use Illuminate\Database\Eloquent\Relations\BelongsTo;')
        ->not->toContain('$timestamps = false');
});

it('knows UUID keys, custom table names and missing timestamps', function () {
    $tags = (new ModelWriter)->write(blogSchema()['tables'][3], []);
    $people = (new ModelWriter)->write(tbl('person_records', [col('code', 'varchar', ['pk' => true])]), []);

    expect($tags)->toContain('use HasUuids;')->toContain('public $timestamps = false;')
        ->and($people)->toContain("protected \$primaryKey = 'code';")->toContain('public $incrementing = false;')
        ->toContain("protected \$keyType = 'string';");
});

it('adds only missing relationships to an existing model, with imports', function () {
    $source = "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass User extends Model\n{\n    use HasFactory;\n\n    public function profile()\n    {\n        return 'mine';\n    }\n}\n";
    $updater = new ModelUpdater;
    $result = $updater->addRelations($source, (new Relations(blogSchema()))->for('users'));

    expect($updater->skipped)->toBe(['profile'])
        ->and($result)->toContain("use Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\Relations\\HasMany;")
        ->toContain('public function posts(): HasMany')
        ->toContain("return 'mine';")
        ->not->toContain('Relations\\HasOne')
        ->and(substr_count($result, 'function profile'))->toBe(1)
        ->and($result)->toEndWith("    }\n}\n");
});

it('changes nothing when every relationship exists, and refuses odd files', function () {
    $rel = (new Relations(blogSchema()))->for('tags');
    $has = "<?php\nclass Tag extends Model\n{\n    public function posts() {}\n}\n";
    $two = "<?php\nclass Tag extends Model\n{\n}\nclass Other {}\n";

    expect((new ModelUpdater)->addRelations($has, $rel))->toBeNull()
        ->and((new ModelUpdater)->addRelations($two, $rel))->toBeNull();
});

it('writes a factory with sensible fake data', function () {
    $schema = blogSchema();
    $fks = [['from' => ['table' => 'posts', 'column' => 'user_id'], 'to' => ['table' => 'users', 'column' => 'id']]];
    $php = (new FactoryWriter)->write($schema['tables'][1], $fks, ['User'], ['post_status' => ['draft', 'live']]);
    $noFactory = (new FactoryWriter)->write($schema['tables'][1], $fks, [], ['post_status' => ['draft', 'live']]);
    $users = (new FactoryWriter)->write($schema['tables'][0]);

    expect($php)
        ->toContain('class PostFactory extends Factory')
        ->toContain('protected $model = Post::class;')
        ->toContain("'user_id' => User::factory(),")
        ->toContain("'title' => fake()->sentence(4),")
        ->toContain("'status' => fake()->randomElement(['draft', 'live']),")
        ->toContain("'published' => fake()->boolean(),")
        ->toContain("'price' => fake()->randomFloat(2, 0, 1000),")
        ->not->toContain("'id' =>")->not->toContain("'created_at' =>")
        ->and($noFactory)->toContain("'user_id' => User::query()->inRandomOrder()->value('id'),")
        ->and($users)->toContain("'email' => fake()->unique()->safeEmail(),");
});
