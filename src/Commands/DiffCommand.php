<?php

namespace SchemaSketch\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SchemaSketch\Laravel\Api\ApiException;
use SchemaSketch\Laravel\Api\Client;
use SchemaSketch\Laravel\Inspect\DatabaseSchema;
use SchemaSketch\Laravel\Schema\Differ;
use Throwable;

class DiffCommand extends Command
{
    // Exit codes, so CI can tell "different" from "couldn't check".
    public const IN_STEP = 0;

    public const DIFFERENT = 1;

    public const CANNOT_CHECK = 2;

    protected $signature = 'schemasketch:diff
        {--diagram= : The diagram to compare with (defaults to SCHEMASKETCH_DIAGRAM)}
        {--connection= : The database connection to read (defaults to your default connection)}
        {--json : Print the differences as JSON}';

    protected $description = 'Compare a SchemaSketch diagram with your database (exit code 0 = in step, 1 = different, 2 = could not check)';

    private const LABELS = [
        'added' => 'In the diagram only',
        'removed' => 'In the database only',
        'changed' => 'Different',
    ];

    public function handle(): int
    {
        $diagramId = $this->option('diagram') ?: config('schemasketch.diagram');
        if (! $diagramId) {
            return $this->cannotCheck('No diagram to compare with. Pass --diagram=ID or set SCHEMASKETCH_DIAGRAM in your .env.');
        }

        try {
            $diagram = Client::fromConfig()->schema($diagramId);
        } catch (ApiException $e) {
            return $this->cannotCheck($e->getMessage());
        }

        if (! empty($diagram['syntax_errors'])) {
            return $this->cannotCheck("The diagram's DBML has errors, so it can't be compared. Fix these in SchemaSketch first:", $diagram['syntax_errors']);
        }

        $connection = $this->option('connection');
        try {
            $database = (new DatabaseSchema)->read($connection, []);
            $driver = DB::connection($connection)->getDriverName();
        } catch (Throwable $e) {
            return $this->cannotCheck("Couldn't read the database: {$e->getMessage()}");
        }

        $changes = (new Differ($driver))->diff($diagram, $database, config('schemasketch.ignore_tables', []));
        $name = $diagram['name'] ?? $diagramId;

        if ($this->option('json')) {
            $this->line(json_encode([
                'in_step' => ! $changes,
                'diagram' => ['id' => $diagramId, 'name' => $name, 'version' => $diagram['version'] ?? null],
                'driver' => $driver,
                'changes' => $changes,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $changes ? self::DIFFERENT : self::IN_STEP;
        }

        if (! $changes) {
            $this->info("The database matches the diagram \"{$name}\".");

            return self::IN_STEP;
        }

        $this->table(['', 'Table', 'What', 'Details'], array_map(fn ($c) => $this->row($c), $changes));

        $destructive = count(array_filter($changes, fn ($c) => $c['destructive']));
        $this->line(sprintf('%d difference(s) between the diagram "%s" and the database.', count($changes), $name));
        if ($destructive) {
            $this->line("<fg=red>{$destructive} in red would drop or narrow something if the database were made to match the diagram.</>");
        }

        return self::DIFFERENT;
    }

    private function row(array $change): array
    {
        $what = $change['object'] === 'table' ? 'table' : "{$change['object']} {$change['name']}";
        $details = implode('; ', array_map(
            fn ($d) => "{$d['field']}: ".$this->value($d['database']).' in the database, '.$this->value($d['diagram']).' in the diagram',
            $change['details'],
        ));
        $row = [self::LABELS[$change['change']], $change['table'], $what, $details];

        return $change['destructive'] ? array_map(fn ($cell) => $cell === '' ? '' : "<fg=red>{$cell}</>", $row) : $row;
    }

    private function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'none',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => implode(', ', $value),
            default => (string) $value,
        };
    }

    private function cannotCheck(string $message, array $lines = []): int
    {
        $this->error($message);
        foreach ($lines as $line) {
            $this->line("  - {$line}");
        }

        return self::CANNOT_CHECK;
    }
}
