<?php

namespace SchemaSketch\Laravel\Eloquent;

use SchemaSketch\Laravel\Schema\Differ;

// Works out every Eloquent relationship a schema implies, on both sides:
// a foreign key gives belongsTo on the table holding it and hasMany (or
// hasOne, if the column is unique) on the table it points at. A plain pivot
// table (two foreign keys to two different tables, nothing else of note)
// gets no model; the two tables it joins get belongsToMany instead.
// Follows the editor's model export (frontend/laravel-model-export.js).
class Relations
{
    /** @var array<string, list<array{name: string, kind: string, code: string}>> table => relationships */
    public array $byTable = [];

    /** @var list<string> */
    public array $pivots = [];

    public function __construct(array $schema)
    {
        $differ = new Differ;
        $tables = collect($schema['tables'] ?? [])->keyBy('name');
        $fksByTable = [];
        foreach ($schema['refs'] ?? [] as $ref) {
            $fk = $differ->foreignKey($ref);
            if ($fk && $tables->has($fk['from']['table']) && $tables->has($fk['to']['table'])) {
                $fksByTable[$fk['from']['table']][] = $fk;
            }
        }

        foreach ($fksByTable as $table => $fks) {
            if ($this->isPivot($tables[$table], $fks)) {
                $this->pivots[] = $table;
            }
        }

        foreach ($fksByTable as $child => $fks) {
            if (in_array($child, $this->pivots, true)) {
                continue;
            }
            foreach ($fks as $fk) {
                $parent = $fk['to']['table'];
                $column = collect($tables[$child]['columns'])->firstWhere('name', $fk['from']['column']);
                $via = [$child, $fk['from']['column']];
                $this->add($child, Naming::belongsTo($fk['from']['column']), 'BelongsTo',
                    "\$this->belongsTo({$this->modelName($parent)}::class, '{$fk['from']['column']}', '{$fk['to']['column']}')", $via);

                $oneToOne = $column && ($column['unique'] || $column['pk']);
                $this->add($parent, $oneToOne ? Naming::hasOne($child) : Naming::hasMany($child), $oneToOne ? 'HasOne' : 'HasMany',
                    '$this->'.($oneToOne ? 'hasOne' : 'hasMany')."({$this->modelName($child)}::class, '{$fk['from']['column']}', '{$fk['to']['column']}')", $via);
            }
        }

        foreach ($this->pivots as $pivot) {
            [$a, $b] = $fksByTable[$pivot];
            $this->add($a['to']['table'], Naming::hasMany($b['to']['table']), 'BelongsToMany',
                "\$this->belongsToMany({$this->modelName($b['to']['table'])}::class, '{$pivot}', '{$a['from']['column']}', '{$b['from']['column']}')", [$pivot, null]);
            $this->add($b['to']['table'], Naming::hasMany($a['to']['table']), 'BelongsToMany',
                "\$this->belongsToMany({$this->modelName($a['to']['table'])}::class, '{$pivot}', '{$b['from']['column']}', '{$a['from']['column']}')", [$pivot, null]);
        }
    }

    public function for(string $table): array
    {
        return $this->byTable[$table] ?? [];
    }

    /** @param array{0: string, 1: ?string} $via the table holding the foreign key and its column, or [pivot, null] */
    private function add(string $table, string $name, string $kind, string $body, array $via): void
    {
        // Two relationships with the same name on one model (e.g. two foreign
        // keys from the same table): keep the first, the rest need a hand.
        if (collect($this->byTable[$table] ?? [])->contains('name', $name)) {
            return;
        }
        $this->byTable[$table][] = [
            'name' => $name,
            'kind' => $kind,
            'code' => "    public function {$name}(): {$kind}\n    {\n        return {$body};\n    }",
            'via_table' => $via[0],
            'via_column' => $via[1],
        ];
    }

    private function modelName(string $table): string
    {
        return Naming::model($table);
    }

    private function isPivot(array $table, array $fks): bool
    {
        if (count($fks) !== 2 || $fks[0]['to']['table'] === $fks[1]['to']['table']) {
            return false;
        }
        $fkColumns = [$fks[0]['from']['column'], $fks[1]['from']['column']];
        foreach ($table['columns'] as $col) {
            $name = strtolower($col['name']);
            if (in_array($col['name'], $fkColumns, true) || in_array($name, ['created_at', 'updated_at'], true)) {
                continue;
            }
            if ($name === 'id' && $col['pk']) {
                continue;
            }

            return false;
        }

        return true;
    }
}
