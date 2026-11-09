<?php

namespace SchemaSketch\Laravel\Diagrams;

// Shared by the Mermaid and PlantUML writers: both take the schema-document
// shape (as read by `pull`), sort it so the output only changes when the
// schema does, and reduce every name to characters that can't break the
// diagram syntax or the Markdown block around it.
abstract class DiagramWriter
{
    abstract public function write(array $schema): string;

    /** Tables sorted by name. */
    protected function tables(array $schema): array
    {
        $tables = $schema['tables'] ?? [];
        usort($tables, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $tables;
    }

    /**
     * Relationships, sorted, with both ends' cardinality worked out, so each
     * writer only decides its own punctuation. "left" is the "one" side.
     *
     * @return list<array{left: string, right: string, label: string, dashed: bool, leftCard: string, rightCard: string}>
     */
    protected function relationships(array $schema): array
    {
        $nullable = [];
        foreach ($schema['tables'] ?? [] as $table) {
            foreach ($table['columns'] as $col) {
                $nullable[$table['name']][$col['name']] = ! $col['not_null'];
            }
        }

        $lines = [];
        foreach ($schema['refs'] ?? [] as $ref) {
            [$from, $to] = [$ref['from'], $ref['to']];
            // Lines from a real foreign key are solid; ones only Eloquent knows about are dashed.
            $dashed = ! in_array($ref['source'] ?? 'foreign key', ['foreign key', 'diagram'], true);
            $optional = fn (array $end) => $nullable[$end['table']][$end['column']] ?? false;

            $lines[] = match ($ref['kind']) {
                // Mermaid and PlantUML both state the "one" side first.
                'many_to_one' => ['left' => $to['table'], 'right' => $from['table'], 'label' => $from['column'],
                    'leftCard' => $optional($from) ? '|o' : '||', 'rightCard' => 'o{'],
                'one_to_many' => ['left' => $from['table'], 'right' => $to['table'], 'label' => $to['column'],
                    'leftCard' => $optional($to) ? '|o' : '||', 'rightCard' => 'o{'],
                'one_to_one' => ['left' => $from['table'], 'right' => $to['table'], 'label' => $from['column'],
                    'leftCard' => '||', 'rightCard' => '||'],
                default => ['left' => $from['table'], 'right' => $to['table'], 'label' => $from['column'],
                    'leftCard' => '}o', 'rightCard' => 'o{'],
            } + ['dashed' => $dashed];
        }

        usort($lines, fn ($a, $b) => [$a['left'], $a['right'], $a['label']] <=> [$b['left'], $b['right'], $b['label']]);

        return $lines;
    }

    /** "table.column" pairs that hold a foreign key. */
    protected function foreignKeys(array $schema): array
    {
        $keys = [];
        foreach ($schema['refs'] ?? [] as $ref) {
            if ($ref['kind'] === 'many_to_many') {
                continue; // a pivot relationship; neither end column is a foreign key
            }
            $end = $ref['kind'] === 'one_to_many' ? $ref['to'] : $ref['from'];
            $keys[$end['table'].'.'.$end['column']] = true;
        }

        return $keys;
    }

    /** Table and column names: letters, digits and underscores only. */
    protected function name(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?: '_';
    }

    /**
     * Types keep their length or precision in brackets, e.g. varchar(255).
     * $extra lists further characters the format allows (PlantUML takes commas).
     */
    protected function type(string $type, string $extra = ''): string
    {
        return preg_replace('/[^A-Za-z0-9_()\[\]'.preg_quote($extra, '/').']/', '_', trim($type)) ?: '_';
    }
}
