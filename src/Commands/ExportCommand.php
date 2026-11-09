<?php

namespace SchemaSketch\Laravel\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use SchemaSketch\Laravel\Commands\Concerns\ReadsDatabase;
use SchemaSketch\Laravel\Diagrams\MarkdownBlock;
use SchemaSketch\Laravel\Diagrams\MermaidWriter;
use SchemaSketch\Laravel\Diagrams\PlantUmlWriter;
use SchemaSketch\Laravel\Support\Terminal;
use Symfony\Component\Console\Formatter\OutputFormatter;

// Draws the local database as Mermaid or PlantUML, offline: no token, no
// network. Into a README's marked block, its own file, or the terminal.
class ExportCommand extends Command
{
    use ReadsDatabase;

    private const FORMATS = [
        'mermaid' => ['fence' => 'mermaid', 'files' => ['mmd', 'mermaid'], 'hint' => '.md, .mmd or .mermaid'],
        'plantuml' => ['fence' => 'plantuml', 'files' => ['puml', 'plantuml'], 'hint' => '.md, .puml or .plantuml'],
    ];

    protected $signature = 'schemasketch:export
        {format : mermaid or plantuml}
        {--output= : A Markdown file (updates the marked block) or a .mmd/.mermaid/.puml file}
        {--check : Change nothing; fail if --output is out of date (for CI)}
        {--connection= : The database connection to read (defaults to your default connection)}
        {--no-models : Don\'t add relationships found in your Eloquent models}';

    protected $description = 'Draw your database as a Mermaid or PlantUML diagram (offline, schema only)';

    public function handle(): int
    {
        $format = strtolower((string) $this->argument('format'));
        if (! isset(self::FORMATS[$format])) {
            $this->error('Unknown format "'.Terminal::text($format).'". Use mermaid or plantuml.');

            return self::FAILURE;
        }
        $spec = self::FORMATS[$format];
        $file = $this->option('output');

        if ($this->option('check') && ! $file) {
            $this->error('--check needs --output (the file to compare).');

            return self::FAILURE;
        }

        $extension = $file ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : null;
        $markdown = in_array($extension, ['md', 'markdown'], true);
        if ($file && ! $markdown && ! in_array($extension, $spec['files'], true)) {
            $this->error('Save '.($format === 'mermaid' ? 'Mermaid' : 'PlantUML').' to a '.$spec['hint'].' file.');

            return self::FAILURE;
        }

        $schema = $this->readDatabase();
        $diagram = ($format === 'mermaid' ? new MermaidWriter : new PlantUmlWriter)->write($schema);

        if (! $file) {
            foreach (explode("\n", rtrim(Terminal::clean($diagram), "\n")) as $line) {
                $this->line(OutputFormatter::escape($line));
            }

            return self::SUCCESS;
        }

        $current = is_file($file) ? file_get_contents($file) : null;
        try {
            $new = $markdown ? MarkdownBlock::replace($current ?? '', $spec['fence'], $diagram) : $diagram;
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
        $this->info($added ? "Added the diagram to the end of {$shown}." : "Updated {$shown}.");

        return self::SUCCESS;
    }
}
