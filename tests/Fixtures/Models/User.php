<?php

namespace SchemaSketch\Laravel\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class User extends Model
{
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Post::class, 'imageable');
    }

    public function broken(): HasMany
    {
        throw new \RuntimeException('side effect');
    }

    // No declared return type: not inspected.
    public function untyped()
    {
        return $this->hasMany(Post::class);
    }

    public function helper(): string
    {
        return 'not a relation';
    }
}
