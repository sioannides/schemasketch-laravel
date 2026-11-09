<?php

namespace SchemaSketch\Laravel\Commands\Concerns;

use SchemaSketch\Laravel\Inspect\DatabaseSchema;
use SchemaSketch\Laravel\Inspect\ModelRelations;
use SchemaSketch\Laravel\Support\Terminal;

// Reads the local database (and, unless --no-models, relationships from the
// Eloquent models) into the schema-document shape. Shared by pull and export;
// the command needs --connection and --no-models options. Schema only, never data.
trait ReadsDatabase
{
    private function readDatabase(): array
    {
        $reader = new DatabaseSchema;
        $schema = $reader->read($this->option('connection'), config('schemasketch.ignore_tables', []));
        $skipped = $reader->skipped;

        if (! $this->option('no-models')) {
            $relations = new ModelRelations(config('schemasketch.models_path') ?: app_path('Models'));
            $schema = $relations->addTo($schema);
            $skipped = [...$skipped, ...$relations->skipped];
        }

        if ($skipped && $this->output->isVerbose()) {
            $this->warn('Left out:');
            foreach ($skipped as $reason) {
                $this->line('  - '.Terminal::text($reason));
            }
        } elseif ($skipped) {
            $this->line(count($skipped).' relationship(s) left out (run with -v to see which and why).');
        }

        return $schema;
    }
}
