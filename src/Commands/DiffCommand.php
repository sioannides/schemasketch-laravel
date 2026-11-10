<?php

namespace SchemaSketch\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SchemaSketch\Laravel\Api\ApiException;
use SchemaSketch\Laravel\Api\Client;
use SchemaSketch\Laravel\Inspect\DatabaseSchema;
use SchemaSketch\Laravel\Schema\Differ;
use SchemaSketch\Laravel\Support\Terminal;
use Symfony\Component\Console\Output\OutputInterface;
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
        {--json : Print the differences as JSON}
        {--markdown : Print the differences as a Markdown table (for pull request comments)}';

    protected $description = 'Compare a SchemaSketch diagram with your database (exit code 0 = in step, 1 = different, 2 = could not check)';

    private const LABELS = [
        'added' => 'In the diagram only',
        'removed' => 'In the database only',
        'changed' => 'Different',
        'renamed' => 'Renamed',
    ];

    public function handle(): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            return $this->cannotCheck('Use either --json or --markdown, not both.');
        }

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
            $this->output->writeln(Terminal::json(json_encode([
                'in_step' => ! $changes,
                'diagram' => ['id' => $diagramId, 'name' => $name, 'version' => $diagram['version'] ?? null],
                'driver' => $driver,
                'changes' => $changes,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), OutputInterface::OUTPUT_RAW);

            return $changes ? self::DIFFERENT : self::IN_STEP;
        }

        if ($this->option('markdown')) {
            return $this->markdown($changes, $name);
        }

        if (! $changes) {
            $this->info('The database matches the diagram "'.Terminal::text($name).'".');

            return self::IN_STEP;
        }

        $this->table(['', 'Table', 'What', 'Details'], array_map(fn ($c) => $this->row($c), $changes));

        $destructive = count(array_filter($changes, fn ($c) => $c['destructive']));
        $this->line(sprintf('%d difference(s) between the diagram "%s" and the database.', count($changes), Terminal::text($name)));
        if ($destructive) {
            $this->line("<fg=red>{$destructive} in red would drop or narrow something if the database were made to match the diagram.</>");
        }

        return self::DIFFERENT;
    }

    /**
     * Markdown for a pull request comment: a heading, a table with risky rows
     * marked, and a summary. Names are escaped so a "|" or HTML in a table or
     * column name can't break the table or end up as markup in the comment.
     */
    private function markdown(array $changes, string $name): int
    {
        $name = $this->md($name);
        if (! $changes) {
            $this->output->writeln("✅ The database matches the diagram \"{$name}\".", OutputInterface::OUTPUT_RAW);

            return self::IN_STEP;
        }

        $lines = [
            sprintf('### SchemaSketch: %d difference(s) between the diagram "%s" and the database', count($changes), $name),
            '',
            '| | Change | Table | What | Details |',
            '| --- | --- | --- | --- | --- |',
        ];
        foreach ($changes as $change) {
            $cells = [$change['destructive'] ? '⚠️' : '', ...array_map(fn ($c) => $this->md($c), $this->cells($change))];
            $lines[] = '|'.implode('|', array_map(fn ($c) => $c === '' ? ' ' : " {$c} ", $cells)).'|';
        }

        $destructive = count(array_filter($changes, fn ($c) => $c['destructive']));
        if ($destructive) {
            $lines[] = '';
            $lines[] = sprintf('⚠️ %d %s would drop or narrow something if the database were made to match the diagram.', $destructive, $destructive === 1 ? 'change' : 'changes');
        }

        $this->output->writeln(implode("\n", $lines), OutputInterface::OUTPUT_RAW);

        return self::DIFFERENT;
    }

    private function md(string $text): string
    {
        $text = Terminal::clean(str_replace(["\r\n", "\n", "\r"], ' ', $text));

        return str_replace(['\\', '|', '&', '<', '>'], ['\\\\', '\\|', '&amp;', '&lt;', '&gt;'], $text);
    }

    /** Change label, table, what and details as plain text. */
    private function cells(array $change): array
    {
        $what = $change['object'] === 'table' ? 'table' : "{$change['object']} {$change['name']}";
        $details = implode('; ', array_map(
            fn ($d) => "{$d['field']}: ".$this->value($d['database']).' in the database, '.$this->value($d['diagram']).' in the diagram',
            $change['details'],
        ));

        return [self::LABELS[$change['change']], $change['table'], $what, $details];
    }

    private function row(array $change): array
    {
        $row = array_map(fn ($cell) => Terminal::text($cell), $this->cells($change));

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
        $this->error(Terminal::text($message));
        foreach ($lines as $line) {
            $this->line('  - '.Terminal::text($line));
        }

        return self::CANNOT_CHECK;
    }
}
