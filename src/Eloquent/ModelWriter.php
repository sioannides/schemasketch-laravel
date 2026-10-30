<?php

namespace SchemaSketch\Laravel\Eloquent;

use Illuminate\Support\Str;
use SchemaSketch\Laravel\Schema\TypeFamilies;

// A new Eloquent model for one table: $fillable, casts, soft deletes, UUID
// keys and its relationships. Laravel 11+ style (a casts() method).
class ModelWriter
{
    private const TIMESTAMPS = ['created_at', 'updated_at', 'deleted_at'];

    public function __construct(private string $namespace = 'App\\Models', private string $factoryNamespace = 'Database\\Factories') {}

    /** @param list<array{name: string, kind: string, code: string}> $relations */
    public function write(array $table, array $relations, bool $withFactory = false, array $enums = []): string
    {
        $class = Naming::model($table['name']);
        $columns = collect($table['columns'])->keyBy('name');
        $pk = collect($table['columns'])->where('pk', true)->values();
        $singlePk = $pk->count() === 1 ? $pk[0] : null;

        $traits = [];
        $imports = ['Illuminate\\Database\\Eloquent\\Model'];
        if ($withFactory) {
            $traits[] = 'HasFactory';
            $imports[] = 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory';
        }
        $uuidKey = $singlePk && ! $singlePk['increment'] && TypeFamilies::family($singlePk['type']) === 'uuid';
        if ($uuidKey) {
            $traits[] = 'HasUuids';
            $imports[] = 'Illuminate\\Database\\Eloquent\\Concerns\\HasUuids';
        }
        $deletedAt = $columns->get('deleted_at');
        if ($deletedAt && TypeFamilies::family($deletedAt['type']) === 'datetime') {
            $traits[] = 'SoftDeletes';
            $imports[] = 'Illuminate\\Database\\Eloquent\\SoftDeletes';
        }
        foreach (collect($relations)->pluck('kind')->unique() as $kind) {
            $imports[] = "Illuminate\\Database\\Eloquent\\Relations\\{$kind}";
        }

        $body = [];
        if ($traits) {
            $body[] = '    use '.implode(', ', $traits).';';
        }

        $settings = [];
        if ($table['name'] !== Str::snake(Str::pluralStudly($class))) {
            $settings[] = "    protected \$table = '{$table['name']}';";
        }
        if ($singlePk && $singlePk['name'] !== 'id') {
            $settings[] = "    protected \$primaryKey = '{$singlePk['name']}';";
        }
        if ($singlePk && ! $singlePk['increment'] && ! $uuidKey) {
            $settings[] = '    public $incrementing = false;';
            if (TypeFamilies::family($singlePk['type']) !== 'integer') {
                $settings[] = "    protected \$keyType = 'string';";
            }
        }
        if (! ($columns->has('created_at') && $columns->has('updated_at'))) {
            $settings[] = '    public $timestamps = false;';
        }
        if ($settings) {
            $body[] = implode("\n\n", $settings);
        }

        $fillable = collect($table['columns'])
            ->reject(fn ($c) => $c['pk'] || in_array($c['name'], self::TIMESTAMPS, true))
            ->map(fn ($c) => "'{$c['name']}'");
        if ($fillable->isNotEmpty()) {
            $body[] = "    protected \$fillable = [\n        ".$fillable->implode(",\n        ").",\n    ];";
        }

        $casts = collect($table['columns'])
            ->reject(fn ($c) => in_array($c['name'], self::TIMESTAMPS, true))
            ->mapWithKeys(fn ($c) => [$c['name'] => $this->cast($c, $enums)])
            ->filter();
        if ($casts->isNotEmpty()) {
            $lines = $casts->map(fn ($cast, $name) => "            '{$name}' => '{$cast}',")->implode("\n");
            $body[] = "    protected function casts(): array\n    {\n        return [\n{$lines}\n        ];\n    }";
        }

        // Laravel finds App\Models\Post's factory as Database\Factories\PostFactory
        // by itself; with any other namespaces the model has to name it.
        if ($withFactory && ! ($this->namespace === 'App\\Models' && $this->factoryNamespace === 'Database\\Factories')) {
            $imports[] = "{$this->factoryNamespace}\\{$class}Factory";
            $body[] = "    protected static function newFactory(): {$class}Factory\n    {\n        return {$class}Factory::new();\n    }";
        }

        foreach ($relations as $relation) {
            $body[] = $relation['code'];
        }

        sort($imports);
        $uses = implode("\n", array_map(fn ($i) => "use {$i};", array_unique($imports)));

        return "<?php\n\nnamespace {$this->namespace};\n\n{$uses}\n\nclass {$class} extends Model\n{\n"
            .implode("\n\n", $body)."\n}\n";
    }

    private function cast(array $col, array $enums): ?string
    {
        if (isset($enums[$col['type']])) {
            return null;
        }
        $type = strtolower($col['type']);

        return match (TypeFamilies::family($type)) {
            'boolean' => 'boolean',
            'json' => 'array',
            'decimal' => str_starts_with(TypeFamilies::base($type), 'decimal') || str_starts_with(TypeFamilies::base($type), 'numeric')
                ? 'decimal:'.(explode(',', (string) TypeFamilies::size($type))[1] ?? 2)
                : 'float',
            'datetime' => TypeFamilies::base($type) === 'date' ? 'date' : (in_array(TypeFamilies::base($type), ['time', 'timetz', 'year'], true) ? null : 'datetime'),
            default => null,
        };
    }
}
