<?php

namespace SchemaSketch\Laravel\Support;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Renders untrusted values (database metadata, diagram content, API responses,
 * exception messages) safely for an interactive terminal or log.
 */
class Terminal
{
    /**
     * C0 controls except TAB and LF, DEL, C1 controls (U+0080-U+009F, which include
     * the single-byte CSI/OSC introducers) and bidirectional override/isolate characters.
     */
    private const UNSAFE = '/[\x00-\x08\x0B-\x1F\x7F\x{0080}-\x{009F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * Replaces terminal control characters with visible escapes. TAB and LF are kept.
     */
    public static function clean(mixed $value): string
    {
        $text = self::utf8((string) $value);

        return preg_replace_callback(self::UNSAFE, function (array $m): string {
            $code = mb_ord($m[0], 'UTF-8');

            return match ($code) {
                0x07 => '\\a',
                0x08 => '\\b',
                0x0B => '\\v',
                0x0C => '\\f',
                0x0D => '\\r',
                0x1B => '\\x1B',
                default => $code <= 0xFF ? sprintf('\\x%02X', $code) : sprintf('\\u{%04X}', $code),
            };
        }, $text) ?? '';
    }

    /**
     * Like clean(), and additionally stops the text from being read as console style tags
     * (for example <fg=red>) by the output formatter.
     */
    public static function text(mixed $value): string
    {
        return OutputFormatter::escape(self::clean($value));
    }

    /**
     * Makes already JSON-encoded text terminal-safe without invalidating the JSON: the
     * characters that json_encode() leaves raw are rewritten as \uXXXX escapes.
     */
    public static function json(string $json): string
    {
        $json = self::utf8($json);

        return preg_replace_callback(self::UNSAFE, fn (array $m): string => sprintf('\\u%04x', mb_ord($m[0], 'UTF-8')), $json) ?? '';
    }

    private static function utf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
