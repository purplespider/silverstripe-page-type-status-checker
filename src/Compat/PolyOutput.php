<?php

namespace PurpleSpider\PageTypeTester\Compat;

use SilverStripe\Control\Director;

/**
 * Minimal stand-in for SilverStripe\PolyExecution\PolyOutput, which exists only
 * in Silverstripe CMS 6. This mirrors the small slice of that API the task uses
 * so the task body can stay identical to the CMS 6 (main) branch.
 *
 * CMS 5's BuildTask simply echoes its output, choosing HTML or plain text based
 * on whether it is running under the CLI, so that is what this reproduces.
 */
class PolyOutput
{
    public const FORMAT_ANSI = 'ansi';
    public const FORMAT_HTML = 'html';

    /**
     * Symfony Console style tags used by the task, mapped to raw ANSI codes.
     */
    private const ANSI_TAGS = [
        '<info>' => "\033[32m",
        '<comment>' => "\033[33m",
        '<fg=green>' => "\033[32m",
        '<fg=red>' => "\033[31m",
        '<fg=yellow>' => "\033[33m",
        '<fg=red;options=bold>' => "\033[1;31m",
        '<options=bold>' => "\033[1m",
    ];

    private string $format;

    public function __construct(?string $format = null)
    {
        $this->format = $format ?? (Director::is_cli() ? self::FORMAT_ANSI : self::FORMAT_HTML);
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Write only when rendering for a terminal. No-op in HTML mode.
     */
    public function writeForAnsi(string|iterable $messages, bool $newline = false): void
    {
        if ($this->format !== self::FORMAT_ANSI) {
            return;
        }
        $this->doWrite($messages, $newline, true);
    }

    /**
     * Write only when rendering for a browser. No-op in ANSI mode.
     */
    public function writeForHtml(string|iterable $messages, bool $newline = false): void
    {
        if ($this->format !== self::FORMAT_HTML) {
            return;
        }
        $this->doWrite($messages, $newline, false);
    }

    public function writeln(string|iterable $messages): void
    {
        $this->doWrite($messages, true, $this->format === self::FORMAT_ANSI);
    }

    private function doWrite(string|iterable $messages, bool $newline, bool $ansi): void
    {
        if (is_string($messages)) {
            $messages = [$messages];
        }
        foreach ($messages as $message) {
            echo $ansi ? $this->formatAnsi((string) $message) : (string) $message;
            if ($newline) {
                echo $ansi ? PHP_EOL : '<br>' . PHP_EOL;
            }
        }
    }

    /**
     * Translate the Symfony Console tags the task uses into ANSI escapes, and
     * strip them entirely when output is not going to a terminal (e.g. piped to
     * a file), so redirected CLI output stays clean.
     */
    private function formatAnsi(string $message): string
    {
        $useColour = function_exists('stream_isatty') && @stream_isatty(STDOUT);

        foreach (self::ANSI_TAGS as $tag => $code) {
            $message = str_replace($tag, $useColour ? $code : '', $message);
        }

        // Any remaining closing or unrecognised tag resets / is dropped.
        $message = preg_replace('#</[a-z]*>#i', $useColour ? "\033[0m" : '', $message);
        $message = preg_replace('#<(?:fg|bg|options)=[^>]*>#i', '', $message);

        return $message;
    }
}
