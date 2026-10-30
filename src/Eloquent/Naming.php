<?php

namespace SchemaSketch\Laravel\Eloquent;

use Illuminate\Support\Str;

// Laravel's own naming conventions, in one place.
class Naming
{
    /** posts → Post, order_items → OrderItem */
    public static function model(string $table): string
    {
        return Str::studly(Str::singular($table));
    }

    /** user_id → user, author_user_id → authorUser */
    public static function belongsTo(string $column): string
    {
        return Str::camel(preg_replace('/_id$/i', '', $column));
    }

    /** comments → comments, order_items → orderItems */
    public static function hasMany(string $table): string
    {
        return Str::camel(Str::plural($table));
    }

    /** profiles → profile */
    public static function hasOne(string $table): string
    {
        return Str::camel(Str::singular($table));
    }
}
