<?php

namespace SchemaSketch\Laravel\Commands;

use Illuminate\Console\Command;
use SchemaSketch\Laravel\Api\ApiException;
use SchemaSketch\Laravel\Api\Client;
use SchemaSketch\Laravel\Dbml\Writer;
use SchemaSketch\Laravel\Inspect\DatabaseSchema;
use SchemaSketch\Laravel\Inspect\ModelRelations;
use SchemaSketch\Laravel\Schema\Merger;

class PullCommand extends Command
{
    protected $signature = 'schemasketch:pull
        {--diagram= : The diagram to update (defaults to SCHEMASKETCH_DIAGRAM)}
        {--name= : Name for a new diagram (defaults to your app name)}
        {--connection= : The database connection to read (defaults to your default connection)}
        {--dry-run : Print the DBML instead of uploading it}
        {--output= : Save the DBML to this file instead of uploading it}
        {--no-models : Don\'t add relationships found in your Eloquent models}
        {--force : Don\'t ask before updating a diagram}';

    protected $description = "Send your database's schema to a SchemaSketch diagram (schema only, never data)";

    public function handle(): int
    {
        $client = Client::fromConfig();
        $diagramId = $this->option('diagram') ?: config('schemasketch.diagram');
        $localOnly = $this->option('dry-run') || $this->option('output');

        try {
            // Uploading needs a read & edit token; check before doing any work.
            if (! $localOnly && ($client->me()['token_access'] ?? 'read-write') === 'read-only') {
                $this->error('This SchemaSketch token is read-only, so pull can\'t change diagrams. Create a "Read & edit" token in "Connect AI agent".');

                return self::FAILURE;
            }

            $schema = $this->readDatabase();

            $diagram = null;
            if ($diagramId && ($client->hasToken() || ! $localOnly)) {
                $diagram = $client->schema($diagramId);
                if (! empty($diagram['syntax_errors'])) {
                    $this->error("The diagram's DBML has errors, so merging into it could lose content. Fix these in SchemaSketch first:");
                    foreach ($diagram['syntax_errors'] as $error) {
                        $this->line("  - {$error}");
                    }

                    return self::FAILURE;
                }
                $merger = new Merger;
                $schema = $merger->merge($diagram, $schema);
            }

            $dbml = (new Writer)->write($schema);

            if ($localOnly) {
                if ($file = $this->option('output')) {
                    file_put_contents($file, $dbml);
                    $this->info("Saved the DBML to {$file}.");
                }
                if ($this->option('dry-run')) {
                    foreach (explode("\n", rtrim($dbml, "\n")) as $line) {
                        $this->line($line);
                    }
                }

                return self::SUCCESS;
            }

            return $diagram
                ? $this->updateDiagram($client, $diagramId, $diagram, $merger->summary, $dbml)
                : $this->createDiagram($client, $dbml);
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

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
                $this->line("  - {$reason}");
            }
        } elseif ($skipped) {
            $this->line(count($skipped).' relationship(s) left out (run with -v to see which and why).');
        }

        return $schema;
    }

    private function createDiagram(Client $client, string $dbml): int
    {
        $name = $this->option('name') ?: config('app.name', 'Laravel');
        $created = $client->create($name, $dbml);

        $this->info("Created the diagram \"{$name}\": ".$client->diagramUrl($created['id']));
        $this->line('Link this app to it by adding to your .env:');
        $this->line("  SCHEMASKETCH_DIAGRAM={$created['id']}");

        return self::SUCCESS;
    }

    private function updateDiagram(Client $client, string $id, array $diagram, array $summary, string $dbml): int
    {
        $name = $diagram['name'] ?? $id;
        $this->line('Updated from the database: '.($summary['updated'] ? implode(', ', $summary['updated']) : 'none'));
        $this->line('Added from the database: '.($summary['added'] ? implode(', ', $summary['added']) : 'none'));
        $this->line('Kept from the diagram: '.($summary['kept'] ? implode(', ', $summary['kept']) : 'none'));
        $this->line("Notes, colors and table groups are kept; the diagram's DBML text is rewritten, so its // comments aren't. The previous version stays in the diagram's History.");

        if (! $this->option('force') && ! $this->confirm("Update the diagram \"{$name}\"?")) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        $client->update($id, $dbml, (int) $diagram['version']);
        $this->info('Diagram updated: '.$client->diagramUrl($id));

        return self::SUCCESS;
    }
}
