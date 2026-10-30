<?php

namespace SchemaSketch\Laravel\Schema;

// Laravel names indexes and foreign keys "{table}_{columns}_{type}"
// ("posts_user_id_foreign"). A name that follows that pattern says nothing,
// so pull and push leave it out and the output reads like hand-written code.
class IndexNames
{
    public static function isDefault(?string $name, string $table, array $columns, string $type): bool
    {
        if ($name === null) {
            return true;
        }
        // Each database names its own primary keys ("PRIMARY", "posts_pkey").
        if ($type === 'primary') {
            return true;
        }

        return strtolower($name) === str_replace(['-', '.'], '_', strtolower($table.'_'.implode('_', $columns).'_'.$type));
    }

    /** The name, or null if Laravel would generate it anyway. */
    public static function custom(?string $name, string $table, array $columns, string $type): ?string
    {
        return self::isDefault($name, $table, $columns, $type) ? null : $name;
    }
}
