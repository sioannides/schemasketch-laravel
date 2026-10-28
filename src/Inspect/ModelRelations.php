<?php

namespace SchemaSketch\Laravel\Inspect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;
use Throwable;

// Relationships Eloquent knows about but the database has no foreign key for.
// Only methods with a declared relation return type are looked at; each is
// called on a fresh model instance, which builds the relation without
// running a query. Anything that throws is skipped and reported.
class ModelRelations
{
    /** Relations left out, with the reason, for the command to report. */
    public array $skipped = [];

    public function __construct(private string $modelsPath) {}

    public function addTo(array $schema): array
    {
        $columns = [];
        foreach ($schema['tables'] ?? [] as $table) {
            foreach ($table['columns'] as $col) {
                $columns["{$table['name']}.{$col['name']}"] = true;
            }
        }
        $seen = [];
        foreach ($schema['refs'] ?? [] as $ref) {
            $seen[$this->pairKey($ref)] = true;
        }

        foreach ($this->models() as $class) {
            foreach ($this->relationMethods($class) as $method) {
                $source = "{$class}::{$method->getName()}()";
                try {
                    $relation = (new $class)->{$method->getName()}();
                } catch (Throwable $e) {
                    $this->skipped[] = "{$source}: threw ".$e->getMessage();

                    continue;
                }

                foreach ($this->refsFor($relation, $source) as $ref) {
                    $key = $this->pairKey($ref);
                    $from = "{$ref['from']['table']}.{$ref['from']['column']}";
                    $to = "{$ref['to']['table']}.{$ref['to']['column']}";
                    if (isset($seen[$key]) || ! isset($columns[$from], $columns[$to])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $schema['refs'][] = $ref;
                }
            }
        }

        return $schema;
    }

    private function refsFor(Relation $relation, string $source): array
    {
        $ref = fn ($fromTable, $fromColumn, $toTable, $toColumn, $kind = 'many_to_one') => [
            'id' => null,
            'from' => ['table' => $fromTable, 'column' => $fromColumn],
            'to' => ['table' => $toTable, 'column' => $toColumn],
            'kind' => $kind,
            'on_delete' => null,
            'on_update' => null,
            'source' => $source,
        ];

        if ($relation instanceof MorphTo || $relation instanceof MorphOneOrMany || $relation instanceof MorphToMany) {
            $this->skipped[] = "{$source}: polymorphic relation";

            return [];
        }
        if ($relation instanceof BelongsTo) {
            return [$ref($relation->getParent()->getTable(), $relation->getForeignKeyName(), $relation->getRelated()->getTable(), $relation->getOwnerKeyName())];
        }
        if ($relation instanceof HasOneOrMany) {
            return [$ref(
                $relation->getRelated()->getTable(), $relation->getForeignKeyName(),
                $relation->getParent()->getTable(), $relation->getLocalKeyName(),
                $relation instanceof HasOne ? 'one_to_one' : 'many_to_one',
            )];
        }
        if ($relation instanceof BelongsToMany) {
            $pivot = $relation->getTable();

            return [
                $ref($pivot, $relation->getForeignPivotKeyName(), $relation->getParent()->getTable(), $relation->getParentKeyName()),
                $ref($pivot, $relation->getRelatedPivotKeyName(), $relation->getRelated()->getTable(), $relation->getRelatedKeyName()),
            ];
        }

        $this->skipped[] = "{$source}: ".class_basename($relation).' is not supported yet';

        return [];
    }

    // A link and its reverse (Post::user() and User::posts()) are one pair.
    private function pairKey(array $ref): string
    {
        $ends = ["{$ref['from']['table']}.{$ref['from']['column']}", "{$ref['to']['table']}.{$ref['to']['column']}"];
        sort($ends);

        return implode('|', $ends);
    }

    /** @return list<class-string<Model>> */
    private function models(): array
    {
        if (! is_dir($this->modelsPath)) {
            return [];
        }

        $classes = [];
        foreach (Finder::create()->files()->name('*.php')->in($this->modelsPath)->sortByName() as $file) {
            $class = $this->classIn($file->getContents());
            // class_exists autoloads the file; nothing is constructed unless
            // it's a concrete Eloquent model.
            if ($class && class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function classIn(string $code): ?string
    {
        if (! preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $code, $class)) {
            return null;
        }
        preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $code, $namespace);

        return isset($namespace[1]) ? "{$namespace[1]}\\{$class[1]}" : $class[1];
    }

    /** @return list<ReflectionMethod> */
    private function relationMethods(string $class): array
    {
        return array_values(array_filter(
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            function (ReflectionMethod $m) {
                $type = $m->getReturnType();

                return ! $m->isStatic()
                    && $m->getNumberOfRequiredParameters() === 0
                    && $m->getDeclaringClass()->getName() !== Model::class
                    && $type instanceof ReflectionNamedType
                    && is_a($type->getName(), Relation::class, true);
            },
        ));
    }
}
