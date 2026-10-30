<?php

namespace SchemaSketch\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SchemaSketch\Laravel\Api\ApiException;
use SchemaSketch\Laravel\Api\Client;
use SchemaSketch\Laravel\Inspect\DatabaseSchema;
use SchemaSketch\Laravel\Migrations\Writer;
use SchemaSketch\Laravel\Schema\Differ;
use Throwable;

class PushCommand extends Command
{
    protected $signature = 'schemasketch:push
        {--diagram= : The diagram to read (defaults to SCHEMASKETCH_DIAGRAM)}
        {--connection= : The database connection to compare with (defaults to your default connection)}
        {--path= : Where to write the migration (defaults to database/migrations)}
        {--force : Write without asking, even if the migration drops something}';

    protected $description = 'Turn the differences between a SchemaSketch diagram and your database into one new migration (never runs it)';

    public function handle(): int
    {
        $diagramId = $this->option('diagram') ?: config('schemasketch.diagram');
        if (! $diagramId) {
            $this->error('No diagram to read. Pass --diagram=ID or set SCHEMASKETCH_DIAGRAM in your .env.');

            return self::FAILURE;
        }

        try {
            $diagram = Client::fromConfig()->schema($diagramId);
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // A migration from a half-read or invalid schema would be wrong.
        $problems = [
            ...($diagram['syntax_errors'] ?? []),
            ...array_column(array_filter($diagram['warnings'] ?? [], fn ($w) => ($w['severity'] ?? null) === 'error'), 'message'),
        ];
        if ($problems) {
            $this->error('The diagram has errors, so no migration was made. Fix these in SchemaSketch first:');
            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        $connection = $this->option('connection');
        try {
            $database = (new DatabaseSchema)->read($connection, []);
            $driver = DB::connection($connection)->getDriverName();
        } catch (Throwable $e) {
            $this->error("Couldn't read the database: {$e->getMessage()}");

            return self::FAILURE;
        }

        $ignore = config('schemasketch.ignore_tables', []);
        $differ = new Differ($driver);
        $changes = $differ->diff($diagram, $database, $ignore);
        $name = $diagram['name'] ?? $diagramId;

        if (! $changes) {
            $this->info("The database already matches the diagram \"{$name}\". No migration needed.");

            return self::SUCCESS;
        }

        $writer = new Writer($differ);
        $migration = $writer->write($diagram, $database, $changes, $ignore);
        $file = rtrim($this->option('path') ?: database_path('migrations'), '/\\').'/'.date('Y_m_d_His').'_schemasketch_sync.php';

        foreach (explode("\n", rtrim($migration)) as $line) {
            $this->line($line);
        }
        $this->newLine();
        foreach ($writer->notes as $note) {
            $this->warn($note);
        }

        $destructive = array_values(array_filter($changes, fn ($c) => $c['destructive']));
        if (! $this->option('force')) {
            if (! $this->confirm('Write this migration to '.$this->relative($file).'?', true)) {
                $this->line('Nothing written.');

                return self::FAILURE;
            }
            if ($destructive) {
                $this->line('<fg=red>This migration drops or narrows:</>');
                foreach ($destructive as $c) {
                    $this->line("<fg=red>  - {$c['table']}".($c['object'] === 'table' ? ' (the whole table)' : ": {$c['object']} {$c['name']}").'</>');
                }
                if (! $this->confirm('Data in these will be lost when the migration runs. Write it anyway?', false)) {
                    $this->line('Nothing written.');

                    return self::FAILURE;
                }
            }
        }

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, $migration);

        $migrate = $this->option('path') ? 'php artisan migrate --path='.$this->relative(dirname($file)) : 'php artisan migrate';
        $this->info('Wrote '.$this->relative($file).". Review it, then run: {$migrate}");

        return self::SUCCESS;
    }

    private function relative(string $file): string
    {
        $base = rtrim(base_path(), '/\\').DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
