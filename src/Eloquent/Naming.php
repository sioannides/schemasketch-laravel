<?php

namespace SchemaSketch\Laravel\Eloquent;

use Illuminate\Support\Str;

// Laravel's own naming conventions, in one place.
class Naming
{
    /** posts → Post, order_items → OrderItem */
    public static function model(string $table): string
    {
        return self::identifier(Str::studly(Str::singular($table)));
    }

    /** user_id → user, author_user_id → authorUser */
    public static function belongsTo(string $column): string
    {
        return self::identifier(Str::camel(preg_replace('/_id$/i', '', $column)));
    }

    /** comments → comments, order_items → orderItems */
    public static function hasMany(string $table): string
    {
        return self::identifier(Str::camel(Str::plural($table)));
    }

    /** profiles → profile */
    public static function hasOne(string $table): string
    {
        return self::identifier(Str::camel(Str::singular($table)));
    }

    /** Schema names are untrusted: only characters valid in a PHP identifier may reach generated code or file names. */
    private static function identifier(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_\x80-\xff]/', '', $name);

        return $name === '' || ctype_digit($name[0]) ? '_'.$name : $name;
    }
}
