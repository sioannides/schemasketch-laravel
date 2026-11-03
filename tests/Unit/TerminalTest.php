<?php

use SchemaSketch\Laravel\Support\Terminal;
use Symfony\Component\Console\Formatter\OutputFormatter;

// Names, notes and API messages come from other people. Printed raw, escape
// sequences in them could clear the screen, fake output or hide text.
it('shows control characters as visible escapes and keeps tabs and newlines', function () {
    expect(Terminal::clean("a\e[2Jb"))->toBe('a\x1B[2Jb')
        ->and(Terminal::clean("bell\x07 cr\r"))->toBe('bell\a cr\r')
        ->and(Terminal::clean("tab\there\nnext"))->toBe("tab\there\nnext")
        ->and(Terminal::clean("csi\u{009B}31m"))->toBe('csi\x9B31m')
        ->and(Terminal::clean("rtl\u{202E}txt"))->toBe('rtl\u{202E}txt')
        ->and(Terminal::clean('café ✓'))->toBe('café ✓');
});

it('stops text from being read as console style tags', function () {
    $shown = (new OutputFormatter(decorated: true))->format(Terminal::text('<fg=red>fake</>'));

    expect($shown)->toBe('<fg=red>fake</>');
});

it('copes with text that is not valid UTF-8', function () {
    expect(Terminal::clean("bad\xC3(\e"))->toContain('\x1B')->not->toContain("\e");
});

it('keeps JSON valid and the original value recoverable', function () {
    $value = "name\u{0085}\u{202E}\x7F";
    $json = Terminal::json(json_encode(['name' => $value], JSON_UNESCAPED_UNICODE));

    expect(preg_match('/[\x{0080}-\x{009F}\x{202E}\x7F]/u', $json))->toBe(0)
        ->and(json_decode($json, true))->toBe(['name' => $value]);
});
