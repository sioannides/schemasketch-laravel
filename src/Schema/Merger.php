<?php

namespace SchemaSketch\Laravel\Schema;

// Combines a diagram with the database it describes (used by `pull` on a
// linked diagram). The database decides each table's columns and indexes;
// the diagram keeps what only it has: planned tables, notes, colors, enums,
// table groups, and relationships whose columns still exist.
class Merger
{
    /** Table names, by what happened to them. */
    public array $summary = ['added' => [], 'updated' => [], 'kept' => []];

    public function merge(array $diagram, array $database): array
    {
        $dbTables = collect($database['tables'])->keyBy('name');
        $diagramNames = array_column($diagram['tables'] ?? [], 'name');
        $this->summary = ['added' => [], 'updated' => [], 'kept' => []];

        $tables = [];
        // Diagram order first, so the diagram doesn't get reshuffled...
        foreach ($diagram['tables'] ?? [] as $table) {
            if ($fromDb = $dbTables->get($table['name'])) {
                $tables[] = [
                    ...$fromDb,
                    'alias' => $fromDb['alias'] ?? $table['alias'] ?? null,
                    'note' => $fromDb['note'] ?: $table['note'],
                    'color' => $fromDb['color'] ?: $table['color'],
                ];
                $this->summary['updated'][] = $table['name'];
            } else {
                $tables[] = $table;
                $this->summary['kept'][] = $table['name'];
            }
        }
        // ...then tables the diagram didn't have yet.
        foreach ($database['tables'] as $table) {
            if (! in_array($table['name'], $diagramNames, true)) {
                $tables[] = $table;
                $this->summary['added'][] = $table['name'];
            }
        }

        $columns = [];
        foreach ($tables as $table) {
            foreach ($table['columns'] as $col) {
                $columns["{$table['name']}.{$col['name']}"] = true;
            }
        }
        $exists = fn ($end) => isset($columns["{$end['table']}.{$end['column']}"]);
        $key = fn ($r) => "{$r['from']['table']}.{$r['from']['column']}>{$r['to']['table']}.{$r['to']['column']}";

        $refs = [];
        foreach ([...$database['refs'], ...array_map(fn ($r) => ['source' => 'diagram', ...$r], $diagram['refs'] ?? [])] as $ref) {
            if ($exists($ref['from']) && $exists($ref['to']) && ! isset($refs[$key($ref)])) {
                $refs[$key($ref)] = $ref;
            }
        }

        $enumNames = array_column($database['enums'] ?? [], 'name');
        $tableNames = array_column($tables, 'name');

        return [
            'tables' => $tables,
            'refs' => array_values($refs),
            'enums' => [
                ...($database['enums'] ?? []),
                ...array_filter($diagram['enums'] ?? [], fn ($e) => ! in_array($e['name'], $enumNames, true)),
            ],
            'table_groups' => array_values(array_filter(array_map(
                fn ($g) => [...$g, 'tables' => array_values(array_intersect($g['tables'], $tableNames))],
                $diagram['table_groups'] ?? [],
            ), fn ($g) => $g['tables'] !== [])),
        ];
    }
}
