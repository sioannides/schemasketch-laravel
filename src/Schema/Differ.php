<?php

namespace SchemaSketch\Laravel\Schema;

// Compares a diagram with a database, both in the schema document shape,
// and lists what differs. Directions follow `push`, which makes the
// database match the diagram:
//   added   = in the diagram, not in the database (push would create it)
//   removed = in the database, not in the diagram (push would drop it)
//   changed = in both, but different
// Matching is by name until SchemaSketch has stable ids.
class Differ
{
    public function __construct(private string $driver = 'sqlite') {}

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * @return list<array{change: string, object: string, table: string, name: string,
     *                    details: list<array{field: string, database: mixed, diagram: mixed}>, destructive: bool,
     *                    data: array{diagram: ?array, database: ?array}|null}>
     *                    `data` holds the index or foreign key itself from each side, for `push`.
     */
    public function diff(array $diagram, array $database, array $ignoreTables = []): array
    {
        $diagramTables = $this->tables($diagram, $ignoreTables);
        $dbTables = $this->tables($database, $ignoreTables);
        $diagramEnums = $this->enums($diagram);
        $dbEnums = $this->enums($database);
        $changes = [];

        foreach ($diagramTables as $name => $table) {
            if (! isset($dbTables[$name])) {
                $changes[] = $this->change('added', 'table', $name, $name);
            }
        }
        foreach ($dbTables as $name => $table) {
            if (! isset($diagramTables[$name])) {
                $changes[] = $this->change('removed', 'table', $name, $name, [], true);
            }
        }

        foreach ($diagramTables as $name => $table) {
            if (! isset($dbTables[$name])) {
                continue;
            }
            $dbTable = $dbTables[$name];
            $fkColumns = $this->foreignKeyColumns($database, $name);

            $changes = [
                ...$changes,
                ...$this->columns($name, $table, $dbTable, $diagramEnums, $dbEnums),
                ...$this->indexes($name, $table, $dbTable, $fkColumns),
            ];
        }

        $skipTables = array_merge(
            array_keys(array_diff_key($diagramTables, $dbTables)),
            array_keys(array_diff_key($dbTables, $diagramTables)),
        );

        return [...$changes, ...$this->foreignKeys($diagram, $database, $skipTables, $ignoreTables)];
    }

    private function columns(string $table, array $diagram, array $database, array $diagramEnums, array $dbEnums): array
    {
        $changes = [];
        $dbColumns = collect($database['columns'])->keyBy('name');
        $diagramColumns = collect($diagram['columns'])->keyBy('name');

        foreach ($diagram['columns'] as $col) {
            if (! $dbColumns->has($col['name'])) {
                $changes[] = $this->change('added', 'column', $table, $col['name']);
            }
        }
        foreach ($database['columns'] as $col) {
            if (! $diagramColumns->has($col['name'])) {
                $changes[] = $this->change('removed', 'column', $table, $col['name'], [], true);
            }
        }

        foreach ($diagram['columns'] as $col) {
            if (! $dbColumns->has($col['name'])) {
                continue;
            }
            [$details, $destructive] = $this->compareColumn(
                $this->column($col, $diagramEnums),
                $this->column($dbColumns[$col['name']], $dbEnums),
            );
            if ($details) {
                $changes[] = $this->change('changed', 'column', $table, $col['name'], $details, $destructive);
            }
        }

        return $changes;
    }

    /** @return array{0: list<array>, 1: bool} the differences, and whether push would lose data */
    private function compareColumn(array $diagram, array $database): array
    {
        $details = [];
        $destructive = false;

        if ($diagram['enum'] !== null && $database['enum'] !== null) {
            if ($diagram['enum'] !== $database['enum']) {
                $details[] = $this->detail('values', $database['enum'], $diagram['enum']);
                $destructive = (bool) array_diff($database['enum'], $diagram['enum']);
            }
        } elseif (! TypeFamilies::same($diagram['family'], $database['family'], $this->driver)) {
            $details[] = $this->detail('type', $database['type'], $diagram['type']);
            $destructive = true;
        } elseif (TypeFamilies::keepsSizes($this->driver) && $diagram['size'] !== null && $database['size'] !== null
            && $diagram['size'] !== $database['size'] && $diagram['family'] === $database['family']) {
            $details[] = $this->detail('type', $database['type'], $diagram['type']);
            $destructive = (float) $diagram['size'] < (float) $database['size'];
        }

        foreach (['pk' => 'primary key', 'not_null' => 'not null', 'unique' => 'unique', 'increment' => 'increment'] as $key => $label) {
            if ($diagram[$key] !== $database[$key]) {
                $details[] = $this->detail($label, $database[$key], $diagram[$key]);
            }
        }

        if (! $this->sameDefault($diagram['default'], $database['default'])) {
            $details[] = $this->detail('default', $database['default']['value'] ?? null, $diagram['default']['value'] ?? null);
        }

        return [$details, $destructive];
    }

    // Indexes are matched by their columns and kind, not their names: a
    // diagram rarely names them, and databases generate names.
    private function indexes(string $table, array $diagram, array $database, array $fkColumns): array
    {
        $key = fn (array $i) => ($i['pk'] ? 'pk' : ($i['unique'] ? 'unique' : 'index')).'('.implode(', ', $i['columns']).')';
        $diagramIndexes = collect($diagram['indexes'])->keyBy($key);
        $dbIndexes = collect($database['indexes'])
            // MySQL adds an index to every foreign key column by itself.
            ->reject(fn ($i) => ! $i['pk'] && ! $i['unique'] && count($i['columns']) === 1
                && in_array($i['columns'][0], $fkColumns, true) && ! $diagramIndexes->has($key($i)))
            ->keyBy($key);

        $changes = [];
        foreach ($diagramIndexes->diffKeys($dbIndexes) as $name => $i) {
            $changes[] = $this->change('added', 'index', $table, $name, data: ['diagram' => $i, 'database' => null]);
        }
        foreach ($dbIndexes->diffKeys($diagramIndexes) as $name => $i) {
            $changes[] = $this->change('removed', 'index', $table, $name, data: ['diagram' => null, 'database' => $i]);
        }

        return $changes;
    }

    // Only real foreign keys count: a diagram's many-to-many ref has no
    // foreign key, and refs inside tables that are wholly added or removed
    // are part of that table's change already.
    private function foreignKeys(array $diagram, array $database, array $skipTables, array $ignoreTables): array
    {
        $keep = fn (array $r) => ! in_array($r['from']['table'], [...$skipTables, ...$ignoreTables], true)
            && ! in_array($r['to']['table'], $ignoreTables, true);
        $diagramRefs = collect($diagram['refs'] ?? [])->map(fn ($r) => $this->foreignKey($r))->filter()->filter($keep)->keyBy('key');
        $dbRefs = collect($database['refs'] ?? [])
            ->filter(fn ($r) => ($r['source'] ?? 'foreign key') === 'foreign key')
            ->map(fn ($r) => $this->foreignKey($r))->filter()->filter($keep)->keyBy('key');

        // A one-to-one ref doesn't say which side holds the foreign key.
        $find = fn ($refs, array $r) => $refs->get($r['key']) ?? ($r['either'] ? $refs->get($r['reverse']) : null);

        $changes = [];
        foreach ($diagramRefs as $ref) {
            $match = $find($dbRefs, $ref);
            if (! $match) {
                $changes[] = $this->change('added', 'foreign key', $ref['from']['table'], $ref['label'], data: ['diagram' => $ref, 'database' => null]);

                continue;
            }
            $details = [];
            foreach (['on_delete' => 'on delete', 'on_update' => 'on update'] as $field => $label) {
                if ($ref[$field] !== $match[$field]) {
                    $details[] = $this->detail($label, $match[$field] ?? 'no action', $ref[$field] ?? 'no action');
                }
            }
            if ($details) {
                $changes[] = $this->change('changed', 'foreign key', $ref['from']['table'], $ref['label'], $details, data: ['diagram' => $ref, 'database' => $match]);
            }
        }
        foreach ($dbRefs as $ref) {
            if (! $find($diagramRefs, $ref) && ! $diagramRefs->first(fn ($d) => $d['either'] && $d['reverse'] === $ref['key'])) {
                $changes[] = $this->change('removed', 'foreign key', $ref['from']['table'], $ref['label'], data: ['diagram' => null, 'database' => $ref]);
            }
        }

        return $changes;
    }

    /** A ref as "the foreign key lives on from.column and points at to.column", or null if it has none. */
    public function foreignKey(array $ref): ?array
    {
        [$from, $to] = match ($ref['kind'] ?? 'many_to_one') {
            'one_to_many' => [$ref['to'], $ref['from']],
            'many_to_many' => [null, null],
            default => [$ref['from'], $ref['to']],
        };
        if (! $from) {
            return null;
        }
        $id = fn ($a, $b) => "{$a['table']}.{$a['column']} > {$b['table']}.{$b['column']}";

        return [
            'key' => $id($from, $to),
            'reverse' => $id($to, $from),
            'either' => ($ref['kind'] ?? null) === 'one_to_one',
            'label' => "{$from['column']} → {$to['table']}.{$to['column']}",
            'name' => $ref['name'] ?? null,
            'from' => $from,
            'to' => $to,
            'on_delete' => $this->action($ref['on_delete'] ?? null),
            'on_update' => $this->action($ref['on_update'] ?? null),
        ];
    }

    /** Tables by name, with one-column unique and pk indexes read as column flags. */
    public function tables(array $schema, array $ignore = []): array
    {
        return collect($schema['tables'] ?? [])
            ->reject(fn ($t) => in_array($t['name'], $ignore, true))
            ->map(fn ($t) => $this->foldSingleColumnIndexes($t))
            ->keyBy('name')
            ->all();
    }

    // DBML can say "unique" on a column or as a one-column index; the
    // database inspection always says it on the column. Same for pk.
    private function foldSingleColumnIndexes(array $table): array
    {
        $indexes = [];
        foreach ($table['indexes'] ?? [] as $index) {
            $column = count($index['columns']) === 1 ? $index['columns'][0] : null;
            if ($column !== null && ($index['pk'] || $index['unique'])) {
                foreach ($table['columns'] as &$col) {
                    if ($col['name'] === $column) {
                        $col[$index['pk'] ? 'pk' : 'unique'] = true;
                    }
                }
                unset($col);

                continue;
            }
            $indexes[] = ['name' => $index['name'] ?? null, 'columns' => $index['columns'], 'unique' => (bool) $index['unique'], 'pk' => (bool) $index['pk']];
        }
        $table['indexes'] = $indexes;

        return $table;
    }

    private function enums(array $schema): array
    {
        return collect($schema['enums'] ?? [])->mapWithKeys(fn ($e) => [$e['name'] => array_values($e['values'])])->all();
    }

    private function column(array $col, array $enums): array
    {
        $enum = $enums[$col['type']] ?? null;
        $pk = (bool) $col['pk'];

        return [
            'type' => $col['type'],
            'family' => $enum !== null ? 'enum' : TypeFamilies::family($col['type']),
            'size' => TypeFamilies::size($col['type']),
            'enum' => $enum,
            'pk' => $pk,
            // A primary key is never null, whether or not the DBML says so.
            'not_null' => $pk || (bool) $col['not_null'],
            'unique' => ! $pk && (bool) $col['unique'],
            'increment' => (bool) $col['increment'],
            'default' => $col['default'] ?? null,
        ];
    }

    private function sameDefault(?array $diagram, ?array $database): bool
    {
        if ($diagram === null || $database === null) {
            return $diagram === $database;
        }
        $normalize = function (array $d): string {
            $v = strtolower(trim((string) $d['value']));
            $v = match ($v) {
                'true' => '1',
                'false' => '0',
                'now()', 'current_timestamp()', 'current_timestamp' => 'current_timestamp',
                default => $v,
            };

            return preg_match('/^-?\d+\.\d*?0*$/', $v) ? rtrim(rtrim($v, '0'), '.') : $v;
        };

        return $normalize($diagram) === $normalize($database);
    }

    private function action(?string $action): ?string
    {
        $action = $action === null ? null : strtolower(trim($action));

        return in_array($action, [null, '', 'no action', 'restrict'], true) ? null : $action;
    }

    private function foreignKeyColumns(array $database, string $table): array
    {
        return collect($database['refs'] ?? [])
            ->filter(fn ($r) => ($r['source'] ?? 'foreign key') === 'foreign key' && $r['from']['table'] === $table)
            ->pluck('from.column')
            ->all();
    }

    private function change(string $change, string $object, string $table, string $name, array $details = [], bool $destructive = false, ?array $data = null): array
    {
        return compact('change', 'object', 'table', 'name', 'details', 'destructive', 'data');
    }

    private function detail(string $field, mixed $database, mixed $diagram): array
    {
        return compact('field', 'database', 'diagram');
    }
}
