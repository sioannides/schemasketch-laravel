<?php

namespace SchemaSketch\Laravel\Eloquent;

use SchemaSketch\Laravel\Schema\Differ;

// Decides which model and factory files `push --models/--factories` writes:
// - a new model for every table this push adds (except plain pivots),
//   with all its relationships;
// - in models you already have, only the relationships this push adds
//   (a new foreign key, a new table pointing at them, a new pivot), and only
//   where no method of that name exists. Relationships you might have left
//   out on purpose are never added.
// - with factories, a factory for every new model.
class Planner
{
    /** Things that need a hand, for the command to show. */
    public array $notes = [];

    public function __construct(
        private string $modelsPath,
        private string $factoriesPath,
        private string $modelsNamespace = 'App\\Models',
        private string $factoriesNamespace = 'Database\\Factories',
    ) {}

    /** @return list<array{path: string, content: string, action: string, summary: string}> */
    public function plan(array $diagram, array $changes, bool $factories, array $ignoreTables = []): array
    {
        $this->notes = [];
        $differ = new Differ;
        $tables = $differ->tables($diagram, $ignoreTables);
        $schema = ['tables' => array_values($tables), 'refs' => $diagram['refs'] ?? []];
        $relations = new Relations($schema);
        $enums = collect($diagram['enums'] ?? [])->mapWithKeys(fn ($e) => [$e['name'] => $e['values']])->all();

        $added = array_column(array_filter($changes, fn ($c) => $c['object'] === 'table' && $c['change'] === 'added'), 'table');
        $newForeignKeys = [];
        foreach ($changes as $c) {
            if ($c['object'] === 'foreign key' && in_array($c['change'], ['added', 'changed'], true) && isset($c['data']['diagram'])) {
                $fk = $c['data']['diagram'];
                $newForeignKeys["{$fk['from']['table']}.{$fk['from']['column']}"] = true;
            }
        }
        $isNew = fn (array $r) => in_array($r['via_table'], $added, true)
            || ($r['via_column'] !== null && isset($newForeignKeys["{$r['via_table']}.{$r['via_column']}"]));

        $newModels = [];
        $files = [];
        $modelWriter = new ModelWriter($this->modelsNamespace, $this->factoriesNamespace);
        $updater = new ModelUpdater;

        foreach ($tables as $name => $table) {
            if (in_array($name, $relations->pivots, true)) {
                continue;
            }
            $class = Naming::model($name);
            $path = "{$this->modelsPath}/{$class}.php";
            $exists = is_file($path);

            if (in_array($name, $added, true) && ! $exists) {
                $newModels[$name] = $class;
                $files[] = [
                    'path' => $path,
                    'content' => $modelWriter->write($table, $relations->for($name), $factories, $enums),
                    'action' => 'create',
                    'summary' => "new model {$class}",
                ];

                continue;
            }

            $wanted = array_values(array_filter($relations->for($name), $isNew));
            if (! $wanted) {
                continue;
            }
            if (! $exists) {
                $this->notes[] = "{$name} has no model at ".$this->shortPath($path).'; add '.implode(', ', array_map(fn ($r) => "{$r['name']}()", $wanted)).' to it by hand.';

                continue;
            }
            $updated = $updater->addRelations(file_get_contents($path), $wanted);
            foreach ($updater->skipped as $method) {
                $this->notes[] = "{$class} already has a {$method}() method, so it was left as it is.";
            }
            if ($updated === null) {
                if (count($updater->skipped) < count($wanted)) {
                    $this->notes[] = "{$class}.php is laid out in a way this can't edit safely; add the new relationships by hand.";
                }

                continue;
            }
            $addedNames = array_diff(array_column($wanted, 'name'), $updater->skipped);
            $files[] = [
                'path' => $path,
                'content' => $updated,
                'action' => 'update',
                'summary' => "{$class}: adds ".implode(', ', array_map(fn ($n) => "{$n}()", $addedNames)),
            ];
        }

        if ($factories) {
            $factoryWriter = new FactoryWriter($this->factoriesNamespace, $this->modelsNamespace);
            // Models that will have a factory: the new ones, plus any existing factory files.
            $withFactory = array_values($newModels);
            foreach (array_keys($tables) as $name) {
                if (is_file("{$this->factoriesPath}/".Naming::model($name).'Factory.php')) {
                    $withFactory[] = Naming::model($name);
                }
            }
            foreach ($newModels as $name => $class) {
                $path = "{$this->factoriesPath}/{$class}Factory.php";
                if (is_file($path)) {
                    continue;
                }
                $foreignKeys = array_values(array_filter(
                    array_map(fn ($r) => $differ->foreignKey($r), $diagram['refs'] ?? []),
                    fn ($fk) => $fk && $fk['from']['table'] === $name,
                ));
                $files[] = [
                    'path' => $path,
                    'content' => $factoryWriter->write($tables[$name], $foreignKeys, array_unique($withFactory), $enums),
                    'action' => 'create',
                    'summary' => "new factory {$class}Factory",
                ];
            }
        }

        return $files;
    }

    private function shortPath(string $path): string
    {
        $base = rtrim(base_path(), '/\\').DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
