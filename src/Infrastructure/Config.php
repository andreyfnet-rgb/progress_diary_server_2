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
        $parsed = file_exists($path) ? parse_ini_file($path, true) : false;
        $this->sections = $parsed !== false ? $parsed : [];
    }

    public function get(string $section, string $key, string $default = ''): string
    {
        $value = $this->sections[$section][$key] ?? null;
        return $value === null ? $default : (string) $value;
    }
}
