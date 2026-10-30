<?php

namespace SchemaSketch\Laravel\Schema;

// Databases name the same type differently (SQLite says "numeric" for
// decimal(8,2), PostgreSQL says "int8" for bigint), so diff compares type
// families, not names.
class TypeFamilies
{
    private const FAMILIES = [
        'boolean' => ['bool', 'boolean'],
        'integer' => ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'int2', 'int4', 'int8',
            'serial', 'bigserial', 'smallserial', 'bigincrements', 'increments'],
        'decimal' => ['decimal', 'numeric', 'float', 'float4', 'float8', 'double', 'real', 'money'],
        'text' => ['varchar', 'char', 'string', 'text', 'tinytext', 'mediumtext', 'longtext', 'nvarchar', 'nchar',
            'citext', 'bpchar', 'clob'],
        'datetime' => ['date', 'time', 'timetz', 'datetime', 'datetime2', 'timestamp', 'timestamptz', 'year'],
        'json' => ['json', 'jsonb'],
        'uuid' => ['uuid', 'uniqueidentifier'],
        'binary' => ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bytea'],
    ];

    // SQLite and PostgreSQL store these as plain text columns when Laravel
    // creates them, so a diagram's json/uuid/enum matches a text column there.
    private const STORED_AS_TEXT = [
        'sqlite' => ['json', 'uuid', 'enum'],
        'pgsql' => ['enum'],
    ];

    /** The family of a DBML or database type name: "varchar(100)" → "text". */
    public static function family(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === 'tinyint(1)') {
            return 'boolean';
        }
        if ($type === 'char(36)') {
            return 'uuid';
        }

        $base = self::base($type);
        foreach (self::FAMILIES as $family => $names) {
            if (in_array($base, $names, true)) {
                return $family;
            }
        }

        return $base;
    }

    /** The size in brackets, if any: "varchar(100)" → "100", "decimal(8,2)" → "8,2". */
    public static function size(string $type): ?string
    {
        return preg_match('/\(\s*([\d\s,]+)\s*\)/', $type, $m) ? preg_replace('/\s+/', '', $m[1]) : null;
    }

    public static function base(string $type): string
    {
        return trim(preg_replace('/\(.*$/', '', strtolower(trim($type))));
    }

    /** Whether two families count as the same on this database driver. */
    public static function same(string $a, string $b, string $driver): bool
    {
        if ($a === $b) {
            return true;
        }
        $asText = self::STORED_AS_TEXT[$driver] ?? [];

        return ($a === 'text' && in_array($b, $asText, true)) || ($b === 'text' && in_array($a, $asText, true));
    }

    /** Only MySQL, MariaDB and PostgreSQL keep sizes; SQLite ignores them. */
    public static function keepsSizes(string $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb', 'pgsql'], true);
    }
}
