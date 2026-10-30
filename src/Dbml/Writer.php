<?php

namespace SchemaSketch\Laravel\Dbml;

use SchemaSketch\Laravel\Schema\IndexNames;

// Schema (the schema-document shape) → DBML text that SchemaSketch's parser
// reads back to the same schema.
class Writer
{
    private const REF_OPERATORS = ['many_to_one' => '>', 'one_to_many' => '<', 'one_to_one' => '-', 'many_to_many' => '<>'];

    public function write(array $schema): string
    {
        $blocks = [];

        foreach ($schema['enums'] ?? [] as $enum) {
            $values = array_map(fn ($v) => '  '.$this->name($v), $enum['values']);
            $blocks[] = 'Enum '.$this->name($enum['name'])." {\n".implode("\n", $values)."\n}";
        }

        foreach ($schema['tables'] ?? [] as $table) {
            $blocks[] = $this->table($table);
        }

        $refs = [];
        foreach ($schema['refs'] ?? [] as $ref) {
            $source = $ref['source'] ?? null;
            $line = ($source && $source !== 'foreign key' && $source !== 'diagram') ? "// inferred from {$source}\n" : '';
            $settings = array_filter([
                ! empty($ref['on_delete']) ? "delete: {$ref['on_delete']}" : null,
                ! empty($ref['on_update']) ? "update: {$ref['on_update']}" : null,
            ]);
            $refs[] = $line.'Ref: '.$this->name($ref['from']['table']).'.'.$this->name($ref['from']['column'])
                .' '.(self::REF_OPERATORS[$ref['kind']] ?? '>').' '
                .$this->name($ref['to']['table']).'.'.$this->name($ref['to']['column'])
                .($settings ? ' ['.implode(', ', $settings).']' : '');
        }
        if ($refs) {
            $blocks[] = implode("\n", $refs);
        }

        foreach ($schema['table_groups'] ?? [] as $group) {
            $tables = array_map(fn ($t) => '  '.$this->name($t), $group['tables']);
            $color = ! empty($group['color']) ? " [color: {$group['color']}]" : '';
            $blocks[] = 'TableGroup '.$this->name($group['name']).$color." {\n".implode("\n", $tables)."\n}";
        }

        return $blocks ? implode("\n\n", $blocks)."\n" : '';
    }

    private function table(array $table): string
    {
        $settings = ! empty($table['color']) ? " [headercolor: {$table['color']}]" : '';
        $alias = ! empty($table['alias']) ? ' as '.$this->name($table['alias']) : '';
        $lines = ['Table '.$this->name($table['name']).$alias.$settings.' {'];

        foreach ($table['columns'] as $col) {
            $flags = array_filter([
                $col['pk'] ? 'pk' : null,
                $col['increment'] ? 'increment' : null,
                $col['not_null'] ? 'not null' : null,
                $col['unique'] && ! $col['pk'] ? 'unique' : null,
                $col['default'] ? 'default: '.$this->defaultValue($col['default'], $col['type']) : null,
                $col['note'] ? 'note: '.$this->string($col['note']) : null,
            ]);
            $lines[] = '  '.$this->name($col['name']).' '.$col['type'].($flags ? ' ['.implode(', ', $flags).']' : '');
        }

        if (! empty($table['note'])) {
            $lines[] = '  Note: '.$this->string($table['note']);
        }

        if (! empty($table['indexes'])) {
            $lines[] = '';
            $lines[] = '  indexes {';
            foreach ($table['indexes'] as $index) {
                $cols = count($index['columns']) === 1
                    ? $this->name($index['columns'][0])
                    : '('.implode(', ', array_map(fn ($c) => $this->name($c), $index['columns'])).')';
                $flags = array_filter([
                    $index['pk'] ? 'pk' : null,
                    $index['unique'] ? 'unique' : null,
                    ($name = IndexNames::custom($index['name'] ?? null, $table['name'], $index['columns'], $index['pk'] ? 'primary' : ($index['unique'] ? 'unique' : 'index')))
                        ? 'name: '.$this->string($name) : null,
                ]);
                $lines[] = '    '.$cols.($flags ? ' ['.implode(', ', $flags).']' : '');
            }
            $lines[] = '  }';
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function defaultValue(array $default, string $type = ''): string
    {
        $value = (string) $default['value'];

        // MySQL keeps booleans as tinyint(1), so their defaults come back as 0/1.
        if (! $default['expression'] && in_array(strtolower($type), ['boolean', 'bool'], true) && in_array($value, ['0', '1'], true)) {
            return $value === '1' ? 'true' : 'false';
        }

        if ($default['expression']) {
            return '`'.$value.'`';
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) || in_array(strtolower($value), ['true', 'false', 'null'], true)) {
            return $value;
        }

        return $this->string($value);
    }

    private function string(string $text): string
    {
        return "'".str_replace(['\\', "'", "\n"], ['\\\\', "\\'", '\\n'], $text)."'";
    }

    // Plain identifiers stay bare; anything else is double-quoted.
    private function name(string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) ? $name : '"'.str_replace('"', '\\"', $name).'"';
    }
}
