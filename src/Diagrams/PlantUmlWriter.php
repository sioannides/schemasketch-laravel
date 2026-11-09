<?php

namespace SchemaSketch\Laravel\Diagrams;

// Schema → PlantUML entity diagram (information engineering notation).
class PlantUmlWriter extends DiagramWriter
{
    public function write(array $schema): string
    {
        $fks = $this->foreignKeys($schema);
        $lines = ['@startuml'];

        foreach ($this->tables($schema) as $table) {
            $lines[] = 'entity '.$this->name($table['name']).' {';
            $pks = array_filter($table['columns'], fn ($c) => $c['pk']);
            $others = array_filter($table['columns'], fn ($c) => ! $c['pk']);

            foreach ($pks as $col) {
                $lines[] = '  * '.$this->name($col['name']).' : '.$this->type($col['type'], ',');
            }
            if ($pks && $others) {
                $lines[] = '  --';
            }
            foreach ($others as $col) {
                $stereotype = $col['unique'] ? ' <<unique>>' : (isset($fks[$table['name'].'.'.$col['name']]) ? ' <<FK>>' : '');
                $lines[] = '  '.$this->name($col['name']).' : '.$this->type($col['type'], ',').$stereotype;
            }
            $lines[] = '}';
            $lines[] = '';
        }

        foreach ($this->relationships($schema) as $rel) {
            $lines[] = $this->name($rel['left']).' '.$rel['leftCard'].($rel['dashed'] ? '..' : '--').$rel['rightCard']
                .' '.$this->name($rel['right']).' : '.$this->name($rel['label']);
        }

        $lines[] = '@enduml';

        return implode("\n", $lines)."\n";
    }
}
