<?php

namespace SchemaSketch\Laravel\Inspect;

use Illuminate\Support\Facades\DB;
use SchemaSketch\Laravel\Schema\DbmlType;

// Reads a database's structure (never its data) through Laravel's schema
// inspection, into the same shape as SchemaSketch's schema document, so
// the rest of the package never cares where a schema came from.
class DatabaseSchema
{
    /** Composite foreign keys DBML can't express yet, for the command to report. */
    public array $skipped = [];

    public function read(?string $connection, array $ignoreTables = []): array
    {
        $db = DB::connection($connection);
        $builder = $db->getSchemaBuilder();
        $tables = collect($builder->getTables($builder->getCurrentSchemaListing()))
            ->pluck('name')
            ->reject(fn ($name) => in_array($name, $ignoreTables, true))
            ->unique()
            ->sort()
            ->values();

        $schema = ['tables' => [], 'refs' => [], 'enums' => [], 'table_groups' => []];

        foreach ($tables as $table) {
            $foreignKeys = $builder->getForeignKeys($table);
            // MySQL backs every foreign key with an index of the same name.
            // Nobody wrote it, so it stays out of the diagram.
            $fkNames = array_filter(array_column($foreignKeys, 'name'));
            $indexes = array_values(array_filter($builder->getIndexes($table), fn ($i) => $i['primary'] || $i['unique']
                || count($i['columns']) !== 1 || ! in_array($i['name'], $fkNames, true)));
            $primary = collect($indexes)->firstWhere('primary', true)['columns'] ?? [];
            // Column => its one-column unique index's name, kept so `push`
            // can drop that index by its real name.
            $uniqueColumns = collect($indexes)
                ->filter(fn ($i) => $i['unique'] && ! $i['primary'] && count($i['columns']) === 1)
                ->mapWithKeys(fn ($i) => [$i['columns'][0] => $i['name']])
                ->all();

            $checkEnums = $this->checkEnums($db, $table);
            $columns = [];
            foreach ($builder->getColumns($table) as $col) {
                $type = $this->columnType($table, $col, $schema['enums'], $checkEnums[$col['name']] ?? null);
                $columns[] = [
                    'id' => null,
                    'name' => $col['name'],
                    'type' => $type,
                    'pk' => count($primary) === 1 && $primary[0] === $col['name'],
                    'not_null' => ! $col['nullable'],
                    'unique' => isset($uniqueColumns[$col['name']]),
                    'unique_index' => $uniqueColumns[$col['name']] ?? null,
                    'increment' => (bool) $col['auto_increment'],
                    'default' => $col['auto_increment'] ? null : $this->parseDefault($col['default']),
                    'note' => $col['comment'] ?: null,
                ];
            }

            $schema['tables'][] = [
                'id' => null,
                'name' => $table,
                'alias' => null,
                'note' => null,
                'color' => null,
                'columns' => $columns,
                'indexes' => collect($indexes)
                    ->reject(fn ($i) => count($i['columns']) === 1 && ($i['primary'] || $i['unique']))
                    ->map(fn ($i) => [
                        'name' => $i['primary'] ? null : $i['name'],
                        'columns' => $i['columns'],
                        'unique' => (bool) $i['unique'] && ! $i['primary'],
                        'pk' => (bool) $i['primary'],
                    ])
                    ->values()
                    ->all(),
            ];

            foreach ($foreignKeys as $fk) {
                if (count($fk['columns']) !== 1 || count($fk['foreign_columns']) !== 1) {
                    $this->skipped[] = "{$table}(".implode(', ', $fk['columns']).") → {$fk['foreign_table']}: composite foreign key";

                    continue;
                }
                $schema['refs'][] = [
                    'id' => null,
                    'name' => $fk['name'] ?? null,
                    'from' => ['table' => $table, 'column' => $fk['columns'][0]],
                    'to' => ['table' => $fk['foreign_table'], 'column' => $fk['foreign_columns'][0]],
                    'kind' => 'many_to_one',
                    'on_delete' => $this->action($fk['on_delete'] ?? null),
                    'on_update' => $this->action($fk['on_update'] ?? null),
                    'source' => 'foreign key',
                ];
            }
        }

        return $schema;
    }

    // Enum columns become a DBML Enum named "<table>_<column>": MySQL's real
    // enum type, or a text column with a check constraint listing its values
    // (how Laravel makes enums on PostgreSQL and SQLite).
    private function columnType(string $table, array $col, array &$enums, ?array $checkValues = null): string
    {
        $values = null;
        if (preg_match("/^enum\((.*)\)$/i", $col['type'], $m)) {
            $values = $this->quotedValues($m[1]);
        } elseif ($checkValues !== null && preg_match('/char|text/i', $col['type'] ?: $col['type_name'])) {
            $values = $checkValues;
        }
        if ($values !== null) {
            $name = "{$table}_{$col['name']}";
            $enums[] = ['id' => null, 'name' => $name, 'values' => $values];

            return $name;
        }

        return DbmlType::fromDatabase($col['type'] ?: $col['type_name']);
    }

    /** Column => allowed values, from "value in (…)" check constraints. */
    private function checkEnums($db, string $table): array
    {
        $found = [];
        try {
            if ($db->getDriverName() === 'pgsql') {
                $defs = array_column($db->select(
                    "select pg_get_constraintdef(oid) as def from pg_constraint where contype = 'c' and conrelid = ?::regclass",
                    ['"'.str_replace('"', '""', $table).'"'],
                ), 'def');
                foreach ($defs as $def) {
                    // CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, …])::text[])))
                    if (preg_match('/^CHECK \(+"?(\w+)"?\)?(?:::[\w ]+)? = ANY \(+ARRAY\[(.*)\]/', $def, $m)) {
                        $found[$m[1]] = $this->quotedValues($m[2]);
                    }
                }
            } elseif ($db->getDriverName() === 'sqlite') {
                $sql = (string) $db->scalar("select sql from sqlite_master where type = 'table' and name = ?", [$table]);
                // "status" varchar check ("status" in ('draft', 'done'))
                preg_match_all("/check\s*\(\s*\"?(\w+)\"?\s+in\s*\(((?:\s*'(?:[^']|'')*'\s*,?)+)\)\s*\)/i", $sql, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $found[$m[1]] = $this->quotedValues($m[2]);
                }
            }
        } catch (\Throwable) {
            // Unusual setups (no access to catalogs): treat columns as plain text.
        }

        return $found;
    }

    private function quotedValues(string $list): array
    {
        preg_match_all("/'((?:[^']|'')*)'/", $list, $values);

        return array_map(fn ($v) => str_replace("''", "'", $v), $values[1]);
    }

    // Raw defaults differ by driver: 'member' (SQLite, MariaDB), member
    // (MySQL), 'member'::character varying (PostgreSQL), CURRENT_TIMESTAMP,
    // now(). Literals become values; functions become expressions.
    private function parseDefault(?string $raw): ?array
    {
        if ($raw === null || strtoupper($raw) === 'NULL') {
            return null;
        }

        $value = preg_replace('/::[\w\s]+(\[\])?$/', '', trim($raw)); // PostgreSQL casts
        $value = preg_replace('/^\((.*)\)$/s', '$1', $value);          // ('x')

        if (preg_match("/^'(.*)'$/s", $value, $m)) {
            return ['value' => str_replace("''", "'", $m[1]), 'expression' => false];
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) || in_array(strtolower($value), ['true', 'false'], true)) {
            return ['value' => $value, 'expression' => false];
        }
        if (str_contains($value, '(') || preg_match('/^(CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME|LOCALTIMESTAMP)\b/i', $value)) {
            return ['value' => $value, 'expression' => true];
        }

        return ['value' => $value, 'expression' => false];
    }

    private function action(?string $action): ?string
    {
        $action = $action === null ? null : strtolower($action);

        return in_array($action, [null, '', 'no action'], true) ? null : $action;
    }
}
