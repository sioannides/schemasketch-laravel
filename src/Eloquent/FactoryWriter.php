<?php

namespace SchemaSketch\Laravel\Eloquent;

use SchemaSketch\Laravel\Schema\TypeFamilies;

// A model factory for one table: fake data chosen by column name first
// (email, name, slug…) and type second, so `Model::factory()->create()`
// works straight away.
class FactoryWriter
{
    private const SKIP = ['created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];

    private const BY_NAME = [
        'email' => 'fake()->safeEmail()',
        'name' => 'fake()->name()',
        'first_name' => 'fake()->firstName()',
        'last_name' => 'fake()->lastName()',
        'username' => 'fake()->userName()',
        'title' => 'fake()->sentence(4)',
        'slug' => 'fake()->slug()',
        'phone' => 'fake()->phoneNumber()',
        'address' => 'fake()->streetAddress()',
        'city' => 'fake()->city()',
        'country' => 'fake()->country()',
        'url' => 'fake()->url()',
        'website' => 'fake()->url()',
        'description' => 'fake()->paragraph()',
        'body' => 'fake()->paragraphs(3, true)',
        'password' => "Hash::make('password')",
    ];

    public function __construct(private string $namespace = 'Database\\Factories', private string $modelNamespace = 'App\\Models') {}

    /**
     * @param  list<array>  $foreignKeys  this table's foreign keys, in Differ::foreignKey() shape
     * @param  list<string>  $factoryModels  models that have (or are getting) a factory
     */
    public function write(array $table, array $foreignKeys = [], array $factoryModels = [], array $enums = []): string
    {
        $model = Naming::model($table['name']);
        $fkByColumn = collect($foreignKeys)->keyBy(fn ($fk) => $fk['from']['column']);
        $usesHash = false;
        $related = [];
        $lines = [];

        foreach ($table['columns'] as $col) {
            if (in_array($col['name'], self::SKIP, true) || ($col['pk'] && ($col['increment'] || TypeFamilies::family($col['type']) === 'uuid'))) {
                continue;
            }

            if ($fk = $fkByColumn->get($col['name'])) {
                $other = Naming::model($fk['to']['table']);
                $related[] = $other;
                if (in_array($other, $factoryModels, true)) {
                    $value = "{$other}::factory()";
                } elseif (! $col['not_null']) {
                    $value = 'null';
                } else {
                    // No factory for that model: use a row that already exists.
                    $value = "{$other}::query()->inRandomOrder()->value('{$fk['to']['column']}')";
                }
            } else {
                $value = $this->fake($col, $enums);
                $usesHash = $usesHash || str_starts_with($value, 'Hash::');
            }
            $lines[] = "            '{$col['name']}' => {$value},";
        }

        $imports = ["{$this->modelNamespace}\\{$model}", 'Illuminate\\Database\\Eloquent\\Factories\\Factory'];
        foreach (array_unique($related) as $other) {
            $imports[] = "{$this->modelNamespace}\\{$other}";
        }
        if ($usesHash) {
            $imports[] = 'Illuminate\\Support\\Facades\\Hash';
        }
        $imports = array_unique($imports);
        sort($imports);
        $uses = implode("\n", array_map(fn ($i) => "use {$i};", $imports));
        $body = $lines ? implode("\n", $lines) : '            //';

        return <<<PHP
<?php

namespace {$this->namespace};

{$uses}

/**
 * @extends Factory<{$model}>
 */
class {$model}Factory extends Factory
{
    protected \$model = {$model}::class;

    public function definition(): array
    {
        return [
{$body}
        ];
    }
}

PHP;
    }

    private function fake(array $col, array $enums): string
    {
        $name = strtolower($col['name']);
        $type = strtolower($col['type']);
        $size = TypeFamilies::size($type);
        $unique = $col['unique'] ? 'fake()->unique()->' : 'fake()->';

        if (isset($enums[$col['type']])) {
            $values = implode(', ', array_map(fn ($v) => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $v)."'", $enums[$col['type']]));

            return "fake()->randomElement([{$values}])";
        }

        $family = TypeFamilies::family($type);
        if (isset(self::BY_NAME[$name]) && in_array($family, ['text', 'enum'], true)) {
            return str_replace('fake()->', $unique, self::BY_NAME[$name]);
        }

        return match ($family) {
            'boolean' => 'fake()->boolean()',
            'integer' => $unique.'numberBetween(1, 1000)',
            'decimal' => $unique.'randomFloat('.(int) (explode(',', (string) $size)[1] ?? 2).', 0, 1000)',
            'datetime' => match (TypeFamilies::base($type)) {
                'date' => 'fake()->date()',
                'time', 'timetz' => 'fake()->time()',
                'year' => 'fake()->year()',
                default => 'fake()->dateTime()',
            },
            'json' => '[]',
            'uuid' => $unique.'uuid()',
            'text' => match (true) {
                in_array(TypeFamilies::base($type), ['text', 'mediumtext', 'longtext', 'tinytext', 'citext', 'clob'], true) => 'fake()->paragraph()',
                $size !== null && (int) $size < 5 => $unique."lexify('".str_repeat('?', max(1, (int) $size))."')",
                default => $unique.'text('.min(50, (int) ($size ?? 50)).')',
            },
            default => $col['not_null'] ? "''" : 'null',
        };
    }
}
