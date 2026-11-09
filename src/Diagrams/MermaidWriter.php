<?php

namespace SchemaSketch\Laravel\Diagrams;

// Schema → Mermaid erDiagram, which GitHub and GitLab draw inside Markdown.
class MermaidWriter extends DiagramWriter
{
    public function write(array $schema): string
    {
        $fks = $this->foreignKeys($schema);
        $lines = ['erDiagram'];

        foreach ($this->tables($schema) as $table) {
            if (! $table['columns']) {
                $lines[] = '    '.$this->name($table['name']);

                continue;
            }
            $lines[] = '    '.$this->name($table['name']).' {';
            foreach ($table['columns'] as $col) {
                $keys = array_filter([
                    $col['pk'] ? 'PK' : null,
                    isset($fks[$table['name'].'.'.$col['name']]) ? 'FK' : null,
                    $col['unique'] && ! $col['pk'] ? 'UK' : null,
                ]);
                [$type, $comment] = $this->splitPrecision($col['type']);
                $lines[] = '        '.$type.' '.$this->name($col['name']).($keys ? ' '.implode(', ', $keys) : '')
                    .($comment !== null ? ' "'.$comment.'"' : '');
            }
            $lines[] = '    }';
        }

        foreach ($this->relationships($schema) as $rel) {
            $lines[] = '    '.$this->name($rel['left']).' '.$rel['leftCard'].($rel['dashed'] ? '..' : '--').$rel['rightCard']
                .' '.$this->name($rel['right']).' : "'.$this->name($rel['label']).'"';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Mermaid can't parse a comma inside a type, so decimal(8,2) becomes the
     * type "decimal" with "(8,2)" as the column's comment.
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitPrecision(string $type): array
    {
        if (! preg_match('/^([^(]*)(\(.*,.*\))\s*$/', trim($type), $m)) {
            return [$this->type($type), null];
        }

        return [$this->type($m[1]), preg_replace('/[^0-9, ()]/', '', $m[2])];
    }
}
