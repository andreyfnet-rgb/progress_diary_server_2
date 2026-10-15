<?php

declare(strict_types=1);

namespace Gdpd\Infrastructure;

/**
 * Thin wrapper around an INI config file (config.ini next to this
 * project's public/index.php on the real server; a separate file for
 * local dev/tests). Unlike the legacy prop.ini reader this doesn't need to
 * match Delphi's TIniFile behaviour exactly -- this is a new config format
 * for the new backend, not a wire contract with anything else.
 */
final class Config
{
    /** @var array<string, array<string, string>> */
    private array $sections;

    public function __construct(string $path)
    {
        if (!file_exists($path)) {
            $this->sections = [];
            return;
        }

        // A file that exists but doesn't parse must fail loudly: falling
        // back to empty settings made every page fail on a DB connect with
        // no database name and no password, which says nothing about the
        // real cause (an unquoted password containing ! ^ ~ & etc).
        $parsed = @parse_ini_file($path, true);
        if ($parsed === false) {
            $reason = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException("Cannot parse {$path}: {$reason}. Values with special characters must be in double quotes.");
        }
        $this->sections = $parsed;
    }

    public function get(string $section, string $key, string $default = ''): string
    {
        $value = $this->sections[$section][$key] ?? null;
        return $value === null ? $default : (string) $value;
    }

    /** @return list<string> */
    public function sectionNames(): array
    {
        return array_map('strval', array_keys($this->sections));
    }
}
