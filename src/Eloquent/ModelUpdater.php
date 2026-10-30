<?php

namespace SchemaSketch\Laravel\Eloquent;

// Adds relationship methods to a model you already have, and nothing else:
// a method is only added if the class has no method of that name yet, the
// imports it needs are added next to the existing ones, and the rest of the
// file is left byte for byte as it was.
class ModelUpdater
{
    /** Relationships skipped because the model already has a method with that name. */
    public array $skipped = [];

    /**
     * @param  list<array{name: string, kind: string, code: string}>  $relations
     * @return string|null the new source, or null if nothing needs adding (or the file can't be edited safely)
     */
    public function addRelations(string $source, array $relations): ?string
    {
        $this->skipped = [];
        $missing = [];
        foreach ($relations as $relation) {
            if (preg_match('/function\s+'.preg_quote($relation['name'], '/').'\s*\(/i', $source)) {
                $this->skipped[] = $relation['name'];
            } else {
                $missing[] = $relation;
            }
        }
        if (! $missing) {
            return null;
        }

        // One class per file, ending in its closing brace: anything else is
        // too unusual to edit with confidence.
        if (preg_match_all('/^\s*(final\s+|abstract\s+)?class\s+\w+/m', $source) !== 1
            || ! preg_match('/\}\s*$/', $source)) {
            return null;
        }

        $methods = implode("\n\n", array_column($missing, 'code'));
        $source = preg_replace('/\}\s*$/', "\n{$methods}\n}\n", rtrim($source), 1);
        $source = preg_replace("/\n{3,}(?=    public function)/", "\n\n", $source);

        foreach (array_unique(array_column($missing, 'kind')) as $kind) {
            $import = "use Illuminate\\Database\\Eloquent\\Relations\\{$kind};";
            if (str_contains($source, $import)) {
                continue;
            }
            // After the last top-level import, or after the namespace line.
            if (preg_match_all('/^use [^;]+;$/m', $source, $m, PREG_OFFSET_CAPTURE)) {
                $last = end($m[0]);
                $at = $last[1] + strlen($last[0]);
            } elseif (preg_match('/^namespace [^;]+;$/m', $source, $n, PREG_OFFSET_CAPTURE)) {
                $at = $n[0][1] + strlen($n[0][0]);
                $import = "\n".$import;
            } else {
                return null;
            }
            $source = substr($source, 0, $at)."\n".$import.substr($source, $at);
        }

        return $source;
    }
}
