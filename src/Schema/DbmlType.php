<?php

namespace SchemaSketch\Laravel\Schema;

// Database type names → the DBML type a person would write. Each driver
// spells types its own way ("character varying(255)", "bigint unsigned",
// "int(11)"); the diagram should read like hand-written DBML.
class DbmlType
{
    private const RENAMES = [
        '/^character varying/' => 'varchar',
        '/^character(?=\(|$)/' => 'char',
        '/^double precision$/' => 'double',
        '/^timestamp(\(\d+\))? with time zone$/' => 'timestamptz',
        '/^timestamp(\(\d+\))? without time zone$/' => 'timestamp',
        '/^time(\(\d+\))? with time zone$/' => 'timetz',
        '/^time(\(\d+\))? without time zone$/' => 'time',
    ];

    public static function fromDatabase(string $type): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/\s+(unsigned|zerofill)\b/', '', $type);

        if ($type === 'tinyint(1)') {
            return 'boolean'; // MySQL's boolean
        }

        foreach (self::RENAMES as $pattern => $replacement) {
            $type = preg_replace($pattern, $replacement, $type);
        }

        // MySQL integer display widths ("int(11)") carry no meaning.
        $type = preg_replace('/^((?:tiny|small|medium|big)?int)\(\d+\)$/', '$1', $type);
        // "numeric(10, 2)" → "numeric(10,2)"
        $type = preg_replace('/\s*,\s*/', ',', $type);

        return $type;
    }
}
