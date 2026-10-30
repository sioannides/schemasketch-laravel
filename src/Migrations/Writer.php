<?php

namespace SchemaSketch\Laravel\Migrations;

use SchemaSketch\Laravel\Schema\Differ;
use SchemaSketch\Laravel\Schema\IndexNames;

// The differences `diff` found → one migration whose up() makes the
// database match the diagram and whose down() puts it back.
//
// Steps run in an order that never trips over a foreign key:
//   up():   drop foreign keys that go → create tables → alter tables →
//           add foreign keys → drop tables
//   down(): the same in reverse, rebuilt from the database's current
//           definitions, so a rollback restores what was there.
class Writer
{
    /** Things a person has to finish by hand, for the command to show. */
    public array $notes = [];

    private ColumnWriter $up;

    private ColumnWriter $down;

    private array $diagramTables;

    private array $dbTables;

    private array $unsignedUp;

    private array $unsignedDown;

    /** Raw SQL to run around a table's Schema::table block: [table][upBefore|upAfter|downBefore|downAfter]. */
    private array $statements = [];

    private array $upEnums = [];

    private array $downEnums = [];

    public function __construct(private Differ $differ) {}

    public function write(array $diagram, array $database, array $changes, array $ignoreTables = []): string
    {
        $this->notes = [];
        $this->diagramTables = $this->differ->tables($diagram, $ignoreTables);
        $this->dbTables = $this->differ->tables($database, $ignoreTables);
        $this->statements = [];
        $this->upEnums = $this->enums($diagram);
        $this->downEnums = $this->enums($database);
        $this->up = new ColumnWriter($this->upEnums);
        $this->down = new ColumnWriter($this->downEnums);

        $diagramFks = $this->foreignKeys($diagram['refs'] ?? []);
        $dbFks = $this->foreignKeys(array_filter($database['refs'] ?? [], fn ($r) => ($r['source'] ?? 'foreign key') === 'foreign key'));
        $this->unsignedUp = $this->unsignedColumns($diagramFks, $this->diagramTables);
        $this->unsignedDown = $this->unsignedColumns($dbFks, $this->dbTables);

        $of = fn (string $change, string $object) => array_values(array_filter($changes, fn ($c) => $c['change'] === $change && $c['object'] === $object));
        $addedTables = array_column($of('added', 'table'), 'table');
        $removedTables = array_column($of('removed', 'table'), 'table');

        // New tables declare their foreign keys inside Schema::create where
        // the target already exists, as hand-written migrations do. (It also
        // spares SQLite a table rebuild, which loses enum check constraints.)
        [$addedTables, $inlineFks, $laterFks] = $this->creationOrder($addedTables, $diagramFks, $removedTables);

        // Foreign keys to add (up) and drop (up), each also undone in down().
        $addFks = [
            ...array_column($of('added', 'foreign key'), 'data'),
            ...array_column($of('changed', 'foreign key'), 'data'),
            ...array_map(fn ($fk) => ['diagram' => $fk], $laterFks),
        ];
        $dropFks = [
            ...array_column($of('removed', 'foreign key'), 'data'),
            ...array_column($of('changed', 'foreign key'), 'data'),
            ...array_map(fn ($fk) => ['database' => $fk], array_filter($dbFks, fn ($fk) => in_array($fk['from']['table'], $removedTables, true))),
        ];
        $addFks = array_column($addFks, 'diagram');
        $dropFks = array_column($dropFks, 'database');

        $alter = $this->alterations($changes);

        $up = array_filter([
            $this->foreignKeyBlocks($dropFks, drop: true),
            ...array_map(fn ($t) => $this->create($this->diagramTables[$t], $this->up, $this->unsignedUp, $inlineFks[$t] ?? []), $addedTables),
            ...array_map(fn ($t) => $this->withStatements($t, $this->block($t, $alter[$t]['up']), 'up'), array_keys($alter)),
            $this->foreignKeyBlocks($addFks, drop: false),
            ...array_map(fn ($t) => "        Schema::dropIfExists('{$t}');", $removedTables),
        ]);
        $down = array_filter([
            ...array_map(fn ($t) => $this->create($this->dbTables[$t], $this->down, $this->unsignedDown), $removedTables),
            $this->foreignKeyBlocks($addFks, drop: true),
            ...array_map(fn ($t) => $this->withStatements($t, $this->block($t, $alter[$t]['down']), 'down'), array_keys($alter)),
            ...array_map(fn ($t) => "        Schema::dropIfExists('{$t}');", array_reverse($addedTables)),
            $this->foreignKeyBlocks($dropFks, drop: false),
        ]);

        foreach (array_unique([...$this->up->unknownTypes, ...$this->down->unknownTypes]) as $type) {
            $this->notes[] = "Unknown type \"{$type}\" was written as a string column; check it.";
        }
        $imports = "use Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\n"
            .($this->up->usesDb || $this->down->usesDb || $this->statements ? "use Illuminate\\Support\\Facades\\DB;\n" : '')
            ."use Illuminate\\Support\\Facades\\Schema;\n";

        return "<?php\n\n{$imports}\n// Generated by schemasketch:push from a SchemaSketch diagram. Review it before\n// running `php artisan migrate`.\nreturn new class extends Migration\n{\n"
            ."    public function up(): void\n    {\n".implode("\n\n", $up)."\n    }\n\n"
            ."    public function down(): void\n    {\n".implode("\n\n", $down)."\n    }\n};\n";
    }

    /** Per existing table: the Blueprint lines for up() and for down(). */
    private function alterations(array $changes): array
    {
        $alter = [];
        $add = function (string $table, string $step, string $upLine, ?string $downLine) use (&$alter) {
            $alter[$table] ??= ['up' => [], 'down' => [], 'order' => []];
            $alter[$table]['order'][] = [$step, $upLine, $downLine];
        };

        foreach ($changes as $c) {
            $t = $c['table'];
            match ([$c['object'], $c['change']]) {
                ['column', 'added'] => $add($t, '1', $this->up->write($this->column($this->diagramTables, $t, $c['name']), $this->unsignedUp["{$t}.{$c['name']}"] ?? false),
                    "\$table->dropColumn('{$c['name']}');"),
                ['column', 'changed'] => $this->changeColumn($t, $c, $add),
                ['index', 'removed'] => $add($t, '3', $this->dropIndex($c['data']['database'], $t), $this->addIndex($c['data']['database'], $t)),
                ['index', 'added'] => $add($t, '4', $this->addIndex($c['data']['diagram'], $t), $this->dropIndex($c['data']['diagram'], $t)),
                ['column', 'removed'] => $add($t, '5', "\$table->dropColumn('{$c['name']}');",
                    $this->down->write($this->column($this->dbTables, $t, $c['name']), $this->unsignedDown["{$t}.{$c['name']}"] ?? false)),
                default => null,
            };
        }

        // up() runs: add columns, change columns, drop indexes, add indexes,
        // drop columns. down() undoes them in the opposite order.
        foreach ($alter as $table => $entry) {
            usort($entry['order'], fn ($a, $b) => $a[0] <=> $b[0]);
            $alter[$table]['up'] = array_column($entry['order'], 1);
            $alter[$table]['down'] = array_values(array_filter(array_reverse(array_column($entry['order'], 2))));
        }

        return $alter;
    }

    private function changeColumn(string $table, array $change, callable $add): void
    {
        $name = $change['name'];
        $to = $this->column($this->diagramTables, $table, $name);
        $from = $this->column($this->dbTables, $table, $name);
        $fields = array_column($change['details'], 'field');

        if ($this->differ->driver() === 'pgsql' && array_intersect($fields, ['type', 'values'])
            && (isset($this->upEnums[$to['type']]) || isset($this->downEnums[$from['type']]))) {
            $this->changeEnumOnPostgres($table, $to, $from, $add);
        } elseif (array_intersect($fields, ['type', 'values', 'not null', 'default'])) {
            $add($table, '2',
                $this->up->write($to, $this->unsignedUp["{$table}.{$name}"] ?? false, change: true),
                $this->down->write($from, $this->unsignedDown["{$table}.{$name}"] ?? false, change: true));
        }
        if (in_array('unique', $fields, true)) {
            $index = $from['unique_index'] ?? null;
            $to['unique']
                ? $add($table, '4', "\$table->unique('{$name}');", "\$table->dropUnique(['{$name}']);")
                : $add($table, '3', '$table->dropUnique('.($index ? "'{$index}'" : "['{$name}']").');',
                    "\$table->unique('{$name}'".($index ? ", '{$index}'" : '').');');
        }
        foreach (array_intersect($fields, ['primary key', 'increment']) as $field) {
            $this->notes[] = "{$table}.{$name}: the {$field} setting differs. Changing it needs care with existing data, so it isn't in the migration; add it by hand if you want it.";
        }
    }

    /**
     * Orders new tables so each one's foreign key targets exist before it.
     *
     * @return array{0: list<string>, 1: array<string, list<array>>, 2: list<array>}
     *                                                                               the order, foreign keys declared inside each Schema::create, and ones added afterwards
     */
    private function creationOrder(array $added, array $diagramFks, array $removed): array
    {
        $fksOf = fn (string $table) => array_values(array_filter($diagramFks, fn ($fk) => $fk['from']['table'] === $table
            && isset($this->diagramTables[$fk['to']['table']])));
        $existsNow = fn (array $fk) => isset($this->dbTables[$fk['to']['table']]) && ! in_array($fk['to']['table'], $removed, true)
            && collect($this->dbTables[$fk['to']['table']]['columns'])->contains('name', $fk['to']['column']);

        $order = [];
        $inline = [];
        $later = [];
        $remaining = $added;
        while ($remaining) {
            // The first table whose new-table targets are all created already;
            // in a cycle, just the first one (its open links come afterwards).
            $ready = collect($remaining)->first(fn ($t) => collect($fksOf($t))->every(fn ($fk) => $fk['to']['table'] === $t
                || in_array($fk['to']['table'], $order, true) || ! in_array($fk['to']['table'], $added, true))) ?? $remaining[0];

            foreach ($fksOf($ready) as $fk) {
                $target = $fk['to']['table'];
                if ($target === $ready || in_array($target, $order, true) || $existsNow($fk)) {
                    $inline[$ready][] = $fk;
                } else {
                    $later[] = $fk;
                }
            }
            $order[] = $ready;
            $remaining = array_values(array_diff($remaining, [$ready]));
        }

        return [$order, $inline, $later];
    }

    // Laravel can't ->change() a column to or from an enum on PostgreSQL (it
    // writes invalid SQL), because there an enum is a varchar plus a check
    // constraint. So the migration does those two parts itself.
    private function changeEnumOnPostgres(string $table, array $to, array $from, callable $add): void
    {
        $name = $to['name'];
        $constraint = "{$table}_{$name}_check";
        $drop = "DB::statement('alter table \"{$table}\" drop constraint if exists \"{$constraint}\"');";
        $check = fn (array $values) => 'DB::statement('.ColumnWriter::quote(
            "alter table \"{$table}\" add constraint \"{$constraint}\" check (\"{$name}\" in ("
            .implode(', ', array_map(fn ($v) => "'".str_replace("'", "''", $v)."'", $values)).'))'
        ).');';
        $asString = fn (array $col, array $enums) => isset($enums[$col['type']]) ? [...$col, 'type' => 'varchar(255)'] : $col;

        $add($table, '2',
            $this->up->write($asString($to, $this->upEnums), $this->unsignedUp["{$table}.{$name}"] ?? false, change: true),
            $this->down->write($asString($from, $this->downEnums), $this->unsignedDown["{$table}.{$name}"] ?? false, change: true));

        if (isset($this->downEnums[$from['type']])) {
            $this->statements[$table]['upBefore'][] = $drop;
            $this->statements[$table]['downAfter'][] = $check($this->downEnums[$from['type']]);
        }
        if (isset($this->upEnums[$to['type']])) {
            $this->statements[$table]['upAfter'][] = $check($this->upEnums[$to['type']]);
            $this->statements[$table]['downBefore'][] = $drop;
        }
    }

    private function withStatements(string $table, ?string $block, string $direction): ?string
    {
        $lines = fn (string $when) => array_map(fn ($l) => "        {$l}", $this->statements[$table][$direction.$when] ?? []);
        $parts = array_filter([implode("\n", $lines('Before')), $block, implode("\n", $lines('After'))]);

        return $parts ? implode("\n\n", $parts) : null;
    }

    private function create(array $table, ColumnWriter $columns, array $unsigned, array $foreignKeys = []): string
    {
        $lines = [];
        $byName = collect($table['columns'])->keyBy('name');
        $foldable = fn (?array $c) => $c && $c['type'] === 'timestamp' && ! $c['not_null'] && ! $c['default'] && ! $c['pk'] && ! $c['unique'];
        $timestamps = $foldable($byName->get('created_at')) && $foldable($byName->get('updated_at'));

        foreach ($table['columns'] as $col) {
            if ($timestamps && in_array($col['name'], ['created_at', 'updated_at'], true)) {
                if ($col['name'] === 'created_at' || ! $byName->has('created_at')) {
                    $lines[] = '$table->timestamps();';
                }

                continue;
            }
            if ($col['name'] === 'deleted_at' && $foldable($col)) {
                $lines[] = '$table->softDeletes();';

                continue;
            }
            $lines[] = $columns->write($col, $unsigned["{$table['name']}.{$col['name']}"] ?? false);
        }
        foreach ($table['indexes'] as $index) {
            $lines[] = $this->addIndex($index, $table['name']);
        }
        foreach ($foreignKeys as $fk) {
            $lines[] = $this->addForeign($fk);
        }

        return "        Schema::create('{$table['name']}', function (Blueprint \$table) {\n"
            .implode("\n", array_map(fn ($l) => "            {$l}", $lines))."\n        });";
    }

    private function block(string $table, array $lines): ?string
    {
        if (! $lines) {
            return null;
        }

        return "        Schema::table('{$table}', function (Blueprint \$table) {\n"
            .implode("\n", array_map(fn ($l) => "            {$l}", $lines))."\n        });";
    }

    private function foreignKeyBlocks(array $fks, bool $drop): ?string
    {
        $blocks = collect($fks)->filter()->groupBy(fn ($fk) => $fk['from']['table'])
            ->map(fn ($group, $table) => $this->block($table, $group->map(fn ($fk) => $drop ? $this->dropForeign($fk) : $this->addForeign($fk))->all()));

        return $blocks->isEmpty() ? null : $blocks->implode("\n\n");
    }

    private function addForeign(array $fk): string
    {
        $name = IndexNames::custom($fk['name'], $fk['from']['table'], [$fk['from']['column']], 'foreign');
        $line = "\$table->foreign('{$fk['from']['column']}'".($name ? ', '.ColumnWriter::quote($name) : '').')'
            ."->references('{$fk['to']['column']}')->on('{$fk['to']['table']}')";

        return $line.$this->action($fk['on_delete'], 'Delete').$this->action($fk['on_update'], 'Update').';';
    }

    private function dropForeign(array $fk): string
    {
        $name = IndexNames::custom($fk['name'], $fk['from']['table'], [$fk['from']['column']], 'foreign');

        return '$table->dropForeign('.($name ? ColumnWriter::quote($name) : "['{$fk['from']['column']}']").');';
    }

    private function action(?string $action, string $kind): string
    {
        return match ($action) {
            null => '',
            'cascade' => "->cascadeOn{$kind}()",
            'set null' => $kind === 'Delete' ? '->nullOnDelete()' : "->onUpdate('set null')",
            default => "->on{$kind}(".ColumnWriter::quote($action).')',
        };
    }

    private function addIndex(array $index, string $table): string
    {
        $method = $index['pk'] ? 'primary' : ($index['unique'] ? 'unique' : 'index');
        $columns = implode(', ', array_map(ColumnWriter::quote(...), $index['columns']));

        $name = IndexNames::custom($index['name'] ?? null, $table, $index['columns'], $method);

        return "\$table->{$method}([{$columns}]".($name ? ', '.ColumnWriter::quote($name) : '').');';
    }

    private function dropIndex(array $index, string $table): string
    {
        $method = $index['pk'] ? 'dropPrimary' : ($index['unique'] ? 'dropUnique' : 'dropIndex');
        $name = IndexNames::custom($index['name'] ?? null, $table, $index['columns'], $index['pk'] ? 'primary' : ($index['unique'] ? 'unique' : 'index'));
        $target = $name
            ? ColumnWriter::quote($name)
            : '['.implode(', ', array_map(ColumnWriter::quote(...), $index['columns'])).']';

        return "\$table->{$method}({$target});";
    }

    private function column(array $tables, string $table, string $name): array
    {
        return collect($tables[$table]['columns'])->firstWhere('name', $name);
    }

    private function foreignKeys(array $refs): array
    {
        return array_values(array_filter(array_map(fn ($r) => $this->differ->foreignKey($r), $refs)));
    }

    /** "table.column" => true for columns that point at an auto-increment key. */
    private function unsignedColumns(array $fks, array $tables): array
    {
        $unsigned = [];
        foreach ($fks as $fk) {
            $target = collect($tables[$fk['to']['table']]['columns'] ?? [])->firstWhere('name', $fk['to']['column']);
            if ($target && $target['increment']) {
                $unsigned["{$fk['from']['table']}.{$fk['from']['column']}"] = true;
            }
        }

        return $unsigned;
    }

    private function enums(array $schema): array
    {
        return collect($schema['enums'] ?? [])->mapWithKeys(fn ($e) => [$e['name'] => $e['values']])->all();
    }
}
