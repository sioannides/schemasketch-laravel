<?php

namespace SchemaSketch\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SchemaSketch\Laravel\Api\ApiException;
use SchemaSketch\Laravel\Api\Client;
use SchemaSketch\Laravel\Inspect\DatabaseSchema;
use SchemaSketch\Laravel\Schema\Differ;
use SchemaSketch\Laravel\Support\Terminal;
use Throwable;

// One look at whether the package is set up: token, linked diagram, database,
// and whether the two are in step. Only reads; a read-only token is enough.
// Exit codes match diff: 0 in step, 1 different, 2 something isn't set up.
class StatusCommand extends Command
{
    protected $signature = 'schemasketch:status
        {--diagram= : The diagram to check (defaults to SCHEMASKETCH_DIAGRAM)}
        {--connection= : The database connection to read (defaults to your default connection)}';

    protected $description = 'Check the token, the linked diagram and the database (exit code 0 = in step, 1 = different, 2 = not set up)';

    private bool $hasProblem = false;

    public function handle(): int
    {
        $client = Client::fromConfig();
        $this->line('<options=bold>SchemaSketch status</>');
        $this->show('·', 'API', Terminal::text((string) config('schemasketch.api_url')));

        $tokenWorks = $this->checkToken($client);
        $diagram = $this->checkDiagram($client, $tokenWorks);
        $database = $this->checkDatabase();
        $differences = $this->checkInStep($diagram, $database);

        return match (true) {
            $this->hasProblem => DiffCommand::CANNOT_CHECK,
            $differences > 0 => DiffCommand::DIFFERENT,
            default => DiffCommand::IN_STEP,
        };
    }

    private function checkToken(Client $client): bool
    {
        if (! $client->hasToken()) {
            $this->problem('Token', 'Not set. Create one in SchemaSketch ("Connect AI agent") and set SCHEMASKETCH_TOKEN in your .env.');

            return false;
        }

        try {
            $me = $client->me();
        } catch (ApiException $e) {
            $this->problem('Token', $e->getMessage());

            return false;
        }

        $readOnly = ($me['token_access'] ?? 'read-write') === 'read-only';
        $this->show('✓', 'Token', Terminal::text($me['email'] ?? 'works').', '.($readOnly ? 'read-only' : 'read & edit'));
        if ($readOnly) {
            $this->note('Fine for status, diff and export; pull and push need a "Read & edit" token.');
        }

        return true;
    }

    /** The linked diagram's schema, or null when it isn't linked, reachable or valid. */
    private function checkDiagram(Client $client, bool $tokenWorks): ?array
    {
        $id = $this->option('diagram') ?: config('schemasketch.diagram');
        if (! $id) {
            $this->problem('Diagram', 'No diagram linked. Run php artisan schemasketch:pull to create one, then add the SCHEMASKETCH_DIAGRAM line it prints to your .env.');

            return null;
        }
        if (! $tokenWorks) {
            $this->show('–', 'Diagram', Terminal::text($id).' (not checked: needs a working token)');

            return null;
        }

        try {
            $diagram = $client->schema($id);
        } catch (ApiException $e) {
            $this->problem('Diagram', Terminal::text($id).': '.$e->getMessage());

            return null;
        }

        if (! empty($diagram['syntax_errors'])) {
            $this->problem('Diagram', "The diagram's DBML has errors. Fix these in SchemaSketch:");
            foreach ($diagram['syntax_errors'] as $error) {
                $this->note('- '.Terminal::text($error));
            }

            return null;
        }

        $name = Terminal::text($diagram['name'] ?? $id);
        $version = isset($diagram['version']) ? ' (version '.(int) $diagram['version'].')' : '';
        $this->show('✓', 'Diagram', "\"{$name}\"{$version}");
        $this->note(Terminal::text($client->diagramUrl($id)));

        return $diagram;
    }

    /** The database schema as diff reads it, or null when it can't be read. */
    private function checkDatabase(): ?array
    {
        $connection = $this->option('connection');

        try {
            $reader = new DatabaseSchema;
            $database = $reader->read($connection, []);
            $tables = count($reader->read($connection, config('schemasketch.ignore_tables', []))['tables'] ?? []);
            $driver = DB::connection($connection)->getDriverName();
        } catch (Throwable $e) {
            $this->problem('Database', "Couldn't read the database: ".$e->getMessage());

            return null;
        }

        $name = Terminal::text($connection ?: config('database.default'));
        $this->show('✓', 'Database', "{$name} ({$driver}), {$tables} ".($tables === 1 ? 'table' : 'tables'));

        return ['schema' => $database, 'driver' => $driver];
    }

    /** Number of differences, or 0 when it couldn't be checked. */
    private function checkInStep(?array $diagram, ?array $database): int
    {
        if ($diagram === null || $database === null) {
            $this->show('–', 'In step', 'not checked');

            return 0;
        }

        $changes = (new Differ($database['driver']))->diff($diagram, $database['schema'], config('schemasketch.ignore_tables', []));
        if (! $changes) {
            $this->show('✓', 'In step', 'The database matches the diagram.');

            return 0;
        }

        $this->show('<fg=yellow>!</>', 'In step', count($changes).' difference(s). See them with: php artisan schemasketch:diff');

        return count($changes);
    }

    private function show(string $mark, string $label, string $text): void
    {
        $mark = $mark === '✓' ? '<fg=green>✓</>' : $mark;
        $this->line(sprintf('  %s %-9s %s', $mark, $label, $text));
    }

    private function note(string $text): void
    {
        $this->line('              '.$text);
    }

    private function problem(string $label, string $message): void
    {
        $this->hasProblem = true;
        $this->show('<fg=red>✗</>', $label, Terminal::text($message));
    }
}
