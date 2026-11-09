<?php

namespace SchemaSketch\Laravel\Diagrams;

use InvalidArgumentException;

// Puts a diagram inside a Markdown file between two HTML comments, so a
// hand-written README keeps everything else. Same input, same output: running
// it again with an unchanged diagram leaves the file byte-for-byte the same.
class MarkdownBlock
{
    public const START = '<!-- schemasketch:start -->';

    public const END = '<!-- schemasketch:end -->';

    public static function replace(string $doc, string $fence, string $body): string
    {
        $eol = str_contains($doc, "\r\n") ? "\r\n" : "\n";
        $bodyLines = explode("\n", rtrim(str_replace("\r\n", "\n", $body), "\n"));
        $block = implode($eol, [self::START, '```'.$fence, ...$bodyLines, '```', self::END]);

        $starts = substr_count($doc, self::START);
        $ends = substr_count($doc, self::END);

        if ($starts === 0 && $ends === 0) {
            if ($doc === '') {
                return $block.$eol;
            }

            return (str_ends_with($doc, $eol) ? $doc : $doc.$eol).$eol.$block.$eol;
        }

        $from = strpos($doc, self::START);
        $to = strpos($doc, self::END);
        if ($starts !== 1 || $ends !== 1 || $to < $from) {
            throw new InvalidArgumentException('it needs exactly one '.self::START.' followed by one '.self::END.'. Fix the markers, then run export again.');
        }

        return substr($doc, 0, $from).$block.substr($doc, $to + strlen(self::END));
    }
}
