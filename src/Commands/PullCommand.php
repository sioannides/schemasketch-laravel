<?php

namespace SchemaSketch\Laravel\Commands;

use Illuminate\Console\Command;
use SchemaSketch\Laravel\Api\ApiException;
use SchemaSketch\Laravel\Api\Client;
use SchemaSketch\Laravel\Commands\Concerns\ReadsDatabase;
use SchemaSketch\Laravel\Dbml\Writer;
use SchemaSketch\Laravel\Schema\Merger;
use SchemaSketch\Laravel\Support\Terminal;

class PullCommand extends Command
{
    use ReadsDatabase;

    protected $signature = 'schemasketch:pull
        {--diagram= : The diagram to update (defaults to SCHEMASKETCH_DIAGRAM)}
        {--name= : Name for a new diagram (defaults to your app name)}
        {--connection= : The database connection to read (defaults to your default connection)}
        {--dry-run : Removed in 0.5: use schemasketch:export dbml}
        {--output= : Removed in 0.5: use schemasketch:export dbml --output=FILE}
        {--no-models : Don\'t add relationships found in your Eloquent models}
        {--force : Don\'t ask before updating a diagram}';

    protected $description = "Send your database's schema to a SchemaSketch diagram (schema only, never data)";

    public function handle(): int
    {
        // pull only sends; files and previews moved to export in 0.5.
        if ($this->option('output') !== null || $this->option('dry-run')) {
            $this->error($this->option('output') !== null ? 'pull no longer writes files.' : 'pull no longer prints DBML.');
            $this->line($this->option('output') !== null
                ? 'To save the diagram as a file, run: php artisan schemasketch:export dbml --output=schema.dbml'
                : 'To see the diagram as DBML, run: php artisan schemasketch:export dbml (it prints when you don\'t give --output)');
            $this->line('Run pull first if the diagram should include your latest database changes. Nothing was changed.');

            return self::FAILURE;
        }

        $client = Client::fromConfig();
        $diagramId = $this->option('diagram') ?: config('schemasketch.diagram');

        try {
            // Uploading needs a read & edit token; check before doing any work.
            if (($client->me()['token_access'] ?? 'read-write') === 'read-only') {
                $this->error('This SchemaSketch token is read-only, so pull can\'t change diagrams. Create a "Read & edit" token in "Connect AI agent".');

                return self::FAILURE;
            }

            $schema = $this->readDatabase();

            $diagram = null;
            if ($diagramId) {
                $diagram = $client->schema($diagramId);
                if (! empty($diagram['syntax_errors'])) {
                    $this->error("The diagram's DBML has errors, so merging into it could lose content. Fix these in SchemaSketch first:");
                    foreach ($diagram['syntax_errors'] as $error) {
                        $this->line('  - '.Terminal::text($error));
                    }

                    return self::FAILURE;
                }
                $merger = new Merger;
                $schema = $merger->merge($diagram, $schema);
            }

            $dbml = (new Writer)->write($schema);

            return $diagram
                ? $this->updateDiagram($client, $diagramId, $diagram, $merger->summary, $dbml)
                : $this->createDiagram($client, $dbml);
        } catch (ApiException $e) {
            $this->error(Terminal::text($e->getMessage()));

            return self::FAILURE;
        }
    }

    private function createDiagram(Client $client, string $dbml): int
    {
        $name = $this->option('name') ?: config('app.name', 'Laravel');
        $created = $client->create($name, $dbml);

        $this->info('Created the diagram "'.Terminal::text($name).'": '.Terminal::text($client->diagramUrl($created['id'])));
        $this->line('Link this app to it by adding to your .env:');
        $this->line('  SCHEMASKETCH_DIAGRAM='.Terminal::text($created['id']));

        return self::SUCCESS;
    }

    private function updateDiagram(Client $client, string $id, array $diagram, array $summary, string $dbml): int
    {
        $name = $diagram['name'] ?? $id;
        $this->line('Updated from the database: '.($summary['updated'] ? Terminal::text(implode(', ', $summary['updated'])) : 'none'));
        $this->line('Added from the database: '.($summary['added'] ? Terminal::text(implode(', ', $summary['added'])) : 'none'));
        $this->line('Kept from the diagram: '.($summary['kept'] ? Terminal::text(implode(', ', $summary['kept'])) : 'none'));
        $this->line("Notes, colors and table groups are kept; the diagram's DBML text is rewritten, so its // comments aren't. The previous version stays in the diagram's History.");

        if (! $this->option('force') && ! $this->confirm('Update the diagram "'.Terminal::text($name).'"?')) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        $client->update($id, $dbml, (int) $diagram['version']);
        $this->info('Diagram updated: '.Terminal::text($client->diagramUrl($id)));

        return self::SUCCESS;
    }
}
