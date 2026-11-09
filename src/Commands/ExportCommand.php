<?php

namespace SchemaSketch\Laravel\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use SchemaSketch\Laravel\Api\ApiException;
use SchemaSketch\Laravel\Api\Client;
use SchemaSketch\Laravel\Diagrams\MarkdownBlock;
use SchemaSketch\Laravel\Diagrams\MermaidWriter;
use SchemaSketch\Laravel\Diagrams\PlantUmlWriter;
use SchemaSketch\Laravel\Support\Terminal;
use Symfony\Component\Console\Formatter\OutputFormatter;

// Writes the linked SchemaSketch diagram to local files: its DBML text as it
// is, or a Mermaid / PlantUML drawing of it (whole files, or a marked block
// in a Markdown file). Reads only; a read-only token is enough.
class ExportCommand extends Command
{
    private const FORMATS = [
        'dbml' => ['files' => ['dbml'], 'markdown' => false, 'hint' => '.dbml'],
        'mermaid' => ['files' => ['mmd', 'mermaid'], 'markdown' => true, 'hint' => '.md, .mmd or .mermaid'],
        'plantuml' => ['files' => ['puml', 'plantuml'], 'markdown' => true, 'hint' => '.md, .puml or .plantuml'],
    ];

    protected $signature = 'schemasketch:export
        {format : dbml, mermaid or plantuml}
        {--output= : A .dbml/.mmd/.mermaid/.puml file, or a Markdown file (updates its marked block)}
        {--check : Change nothing; fail if --output is out of date (for CI)}
        {--diagram= : The diagram to export (defaults to SCHEMASKETCH_DIAGRAM)}';

    protected $description = 'Save a SchemaSketch diagram as DBML, Mermaid or PlantUML (prints it without --output)';

    public function handle(): int
    {
        $format = strtolower((string) $this->argument('format'));
        if (! isset(self::FORMATS[$format])) {
            $this->error('Unknown format "'.Terminal::text($format).'". Use dbml, mermaid or plantuml.');

            return self::FAILURE;
        }
        $spec = self::FORMATS[$format];
        $file = $this->option('output');

        if ($this->option('check') && ! $file) {
            $this->error('--check needs --output (the file to compare).');

            return self::FAILURE;
        }

        $extension = $file ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : null;
        $markdown = $spec['markdown'] && in_array($extension, ['md', 'markdown'], true);
        if ($file && ! $markdown && ! in_array($extension, $spec['files'], true)) {
            $this->error('Save '.['dbml' => 'DBML', 'mermaid' => 'Mermaid', 'plantuml' => 'PlantUML'][$format].' to a '.$spec['hint'].' file.');

            return self::FAILURE;
        }

        $diagramId = $this->option('diagram') ?: config('schemasketch.diagram');
        if (! $diagramId) {
            $this->error('No diagram to export. Set SCHEMASKETCH_DIAGRAM in your .env (pull prints it the first time) or pass --diagram=ID.');

            return self::FAILURE;
        }

        try {
            $content = $this->render($format, Client::fromConfig(), $diagramId);
        } catch (ApiException $e) {
            $this->error(Terminal::text($e->getMessage()));

            return self::FAILURE;
        }
        if ($content === null) {
            return self::FAILURE;
        }

        if (! $file) {
            foreach (explode("\n", rtrim(Terminal::clean($content), "\n")) as $line) {
                $this->line(OutputFormatter::escape($line));
            }

            return self::SUCCESS;
        }

        return $this->save($file, $format, $markdown, $content);
    }

    /** The diagram in the chosen format, or null after reporting why it can't be drawn. */
    private function render(string $format, Client $client, string $diagramId): ?string
    {
        if ($format === 'dbml') {
            $dbml = (string) ($client->diagram($diagramId)['dbml_source'] ?? '');

            return $dbml === '' || str_ends_with($dbml, "\n") ? $dbml : $dbml."\n";
        }

        $schema = $client->schema($diagramId);
        if (! empty($schema['syntax_errors'])) {
            $this->error("The diagram's DBML has errors, so it can't be drawn. Fix these in SchemaSketch first:");
            foreach ($schema['syntax_errors'] as $error) {
                $this->line('  - '.Terminal::text($error));
            }

            return null;
        }

        return ($format === 'mermaid' ? new MermaidWriter : new PlantUmlWriter)->write($schema);
    }

    private function save(string $file, string $format, bool $markdown, string $content): int
    {
        $current = is_file($file) ? file_get_contents($file) : null;
        try {
            $new = $markdown ? MarkdownBlock::replace($current ?? '', $format, $content) : $content;
        } catch (InvalidArgumentException $e) {
            $this->error(Terminal::text($file).': '.$e->getMessage());

            return self::FAILURE;
        }

        $shown = Terminal::text($file);
        if ($current === $new) {
            $this->info("{$shown} is up to date.");

            return self::SUCCESS;
        }
        if ($this->option('check')) {
            $this->error("{$shown} is out of date. Run: php artisan schemasketch:export {$format} --output={$shown}");

            return self::FAILURE;
        }

        file_put_contents($file, $new);
        $added = $markdown && ($current === null || ! str_contains($current, MarkdownBlock::START));
        $this->info($added ? "Added the diagram to the end of {$shown}." : "Saved {$shown}.");

        return self::SUCCESS;
    }
}
