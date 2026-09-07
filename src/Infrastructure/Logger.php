<?php

declare(strict_types=1);

namespace Gdpd\Infrastructure;

/**
 * Ports the legacy Delphi `inlog(FileName, text)` helper (dateunit.pas):
 * one plain-text file per log name under <baseDir>/log/<name>.log, newest
 * entry inserted at the top, capped at ~1001 lines, format
 * "dd.MM.yyyy H:mm:ss<TAB>text" (hour not zero-padded -- confirmed by
 * running the legacy server and inspecting real log-shaped values, see the
 * .NET port's DelphiValueFormatterTests for the same finding applied to
 * datetime fields in general).
 */
final class Logger
{
    private const MAX_LINES = 1001;

    public function __construct(private readonly string $baseDir)
    {
    }

    public function log(string $fileName, string $text): void
    {
        $logDir = rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR . 'log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }

        $path = $logDir . DIRECTORY_SEPARATOR . $fileName . '.log';
        $entry = $this->formatEntry($text);

        $lines = [];
        if (is_file($path)) {
            $existing = @file($path, FILE_IGNORE_NEW_LINES);
            if ($existing !== false) {
                $lines = $existing;
            }
        }

        array_unshift($lines, $entry);
        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, 0, self::MAX_LINES);
        }

        $written = @file_put_contents($path, implode("\n", $lines) . "\n");
        if ($written === false) {
            // Mirrors the Delphi fallback: if the normal log file can't be
            // written, drop a single-entry file with a timestamped name
            // instead of losing the message.
            $fallbackName = $fileName . date('_H-i-s_d-m-Y');
            @file_put_contents($logDir . DIRECTORY_SEPARATOR . $fallbackName . '.log', $entry . "\n");
        }
    }

    private function formatEntry(string $text): string
    {
        $now = new \DateTimeImmutable();
        return $now->format('d.m.Y') . ' ' . (int) $now->format('H') . $now->format(':i:s') . "\t" . $text;
    }
}
