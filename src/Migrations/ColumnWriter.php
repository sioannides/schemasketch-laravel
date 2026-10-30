<?php

namespace SchemaSketch\Laravel\Migrations;

use SchemaSketch\Laravel\Schema\TypeFamilies;

// One column of a schema document → one Blueprint call, e.g.
// "$table->string('email', 120)->unique()". The type table follows the main
// app's Laravel exporter (frontend/laravel-export.js), with two differences
// that matter when the migration runs against a real database: a foreign
// key column pointing at an auto-increment key is unsigned (as id() is on
// MySQL), and an unknown type is flagged instead of silently made a string.
class ColumnWriter
{
    /** Set when a default needed DB::raw(), so the migration imports DB. */
    public bool $usesDb = false;

    /** Types this writer didn't know, for the command to mention. */
    public array $unknownTypes = [];

    private const METHODS = [
        'int' => 'integer', 'integer' => 'integer', 'int4' => 'integer', 'serial' => 'integer',
        'bigint' => 'bigInteger', 'int8' => 'bigInteger', 'bigserial' => 'bigInteger',
        'smallint' => 'smallInteger', 'int2' => 'smallInteger', 'smallserial' => 'smallInteger',
        'tinyint' => 'tinyInteger', 'mediumint' => 'mediumInteger',
        'bool' => 'boolean', 'boolean' => 'boolean',
        'varchar' => 'string', 'string' => 'string', 'nvarchar' => 'string', 'character varying' => 'string',
        'char' => 'char', 'nchar' => 'char', 'bpchar' => 'char',
        'text' => 'text', 'citext' => 'text', 'clob' => 'text',
        'tinytext' => 'tinyText', 'mediumtext' => 'mediumText', 'longtext' => 'longText',
        'decimal' => 'decimal', 'numeric' => 'decimal', 'money' => 'decimal',
        'float' => 'float', 'float4' => 'float', 'real' => 'float',
        'double' => 'double', 'float8' => 'double', 'double precision' => 'double',
        'date' => 'date', 'datetime' => 'dateTime', 'datetime2' => 'dateTime',
        'timestamp' => 'timestamp', 'timestamptz' => 'timestampTz',
        'time' => 'time', 'timetz' => 'timeTz', 'year' => 'year',
        'json' => 'json', 'jsonb' => 'jsonb', 'uuid' => 'uuid',
        'binary' => 'binary', 'varbinary' => 'binary', 'blob' => 'binary', 'bytea' => 'binary',
        'tinyblob' => 'binary', 'mediumblob' => 'binary', 'longblob' => 'binary',
    ];

    private const INCREMENTS = [
        'integer' => 'increments', 'bigInteger' => 'bigIncrements', 'smallInteger' => 'smallIncrements',
        'tinyInteger' => 'tinyIncrements', 'mediumInteger' => 'mediumIncrements',
    ];

    private const UNSIGNED = [
        'integer' => 'unsignedInteger', 'bigInteger' => 'unsignedBigInteger', 'smallInteger' => 'unsignedSmallInteger',
        'tinyInteger' => 'unsignedTinyInteger', 'mediumInteger' => 'unsignedMediumInteger',
    ];

    /** @param array<string, list<string>> $enums enum name => values */
    public function __construct(private array $enums = []) {}

    /**
     * @param  bool  $unsigned  the column points at an auto-increment key
     * @param  bool  $change  for ->change(): unique and primary are left out, since they're indexes handled apart
     */
    public function write(array $col, bool $unsigned = false, bool $change = false): string
    {
        $name = self::quote($col['name']);
        $comment = $col['note'] ?? null ? '->comment('.self::quote($col['note']).')' : '';
        $type = strtolower(trim($col['type']));
        $method = $type === 'tinyint(1)' ? 'boolean' : (self::METHODS[TypeFamilies::base($type)] ?? null);

        if (! $change && $col['pk'] && $col['increment'] && $col['name'] === 'id' && TypeFamilies::family($type) === 'integer') {
            return "\$table->id(){$comment};";
        }
        if (! $change && $col['increment'] && isset(self::INCREMENTS[$method])) {
            return '$table->'.self::INCREMENTS[$method]."({$name}){$comment};";
        }

        $call = $this->typeCall($col, $type, $method, $name, $unsigned);
        if ($col['increment']) {
            $call .= '->autoIncrement()';
        }
        if (! $col['not_null'] && ! $col['pk']) {
            $call .= '->nullable()';
        }
        if ($col['default'] ?? null) {
            $call .= $this->default($col['default'], $method, $type);
        }
        if (! $change && $col['unique'] && ! $col['pk']) {
            $call .= '->unique()';
        }
        if (! $change && $col['pk'] && ! $col['increment']) {
            $call .= '->primary()';
        }

        return $call.$comment.($change ? '->change()' : '').';';
    }

    private function typeCall(array $col, string $type, ?string $method, string $name, bool $unsigned): string
    {
        if (isset($this->enums[$col['type']])) {
            $values = implode(', ', array_map(self::quote(...), $this->enums[$col['type']]));

            return "\$table->enum({$name}, [{$values}])";
        }

        if ($method === null) {
            $this->unknownTypes[] = $col['type'];

            return "\$table->string({$name}) /* SchemaSketch type \"".str_replace('*/', '', $col['type']).'": change if needed */';
        }

        $size = TypeFamilies::size($type);
        $args = $size === null ? [] : explode(',', $size);

        if ($unsigned && isset(self::UNSIGNED[$method])) {
            $method = self::UNSIGNED[$method];
        }

        return match ($method) {
            'string', 'char' => "\$table->{$method}({$name}".($args ? ", {$args[0]}" : '').')',
            'decimal' => "\$table->decimal({$name}".(count($args) >= 1 ? ', '.implode(', ', array_slice($args, 0, 2)) : '').')',
            default => "\$table->{$method}({$name})",
        };
    }

    private function default(array $default, ?string $method, string $type): string
    {
        $value = (string) $default['value'];

        if ($default['expression']) {
            if (preg_match('/^(now\(\)|current_timestamp(\(\))?)$/i', $value)) {
                return '->useCurrent()';
            }
            $this->usesDb = true;

            return '->default(DB::raw('.self::quote($value).'))';
        }

        if ($method === 'boolean') {
            return in_array(strtolower($value), ['1', 'true'], true) ? '->default(true)' : '->default(false)';
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) && in_array(TypeFamilies::family($type), ['integer', 'decimal'], true)) {
            return "->default({$value})";
        }

        return '->default('.self::quote($value).')';
    }

    /** A PHP single-quoted string literal. */
    public static function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
