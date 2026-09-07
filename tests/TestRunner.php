<?php

declare(strict_types=1);

/**
 * A minimal, dependency-free test runner. PHPUnit would be preferable but
 * cannot be assumed available on the target shared hosting, and this
 * project otherwise has zero Composer dependencies by design -- keeping
 * tests dependency-free too avoids a mismatch between "how we test
 * locally" and "what can actually run on the host" if these ever need to
 * run there (e.g. as a smoke test after deploy).
 */
final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private string $currentGroup = '';

    public function group(string $name): void
    {
        $this->currentGroup = $name;
        echo "\n{$name}\n";
    }

    public function test(string $name, callable $fn): void
    {
        try {
            $fn($this);
            $this->passed++;
            echo "  [OK] {$name}\n";
        } catch (\Throwable $e) {
            $this->failed++;
            echo "  [FAIL] {$name}: {$e->getMessage()}\n";
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $expectedText = var_export($expected, true);
            $actualText = var_export($actual, true);
            throw new \RuntimeException("{$message} expected {$expectedText}, got {$actualText}");
        }
    }

    public function assertTrue(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new \RuntimeException($message !== '' ? $message : 'expected true');
        }
    }

    public function assertThrows(callable $fn, string $message = ''): void
    {
        try {
            $fn();
        } catch (\Throwable) {
            return;
        }
        throw new \RuntimeException($message !== '' ? $message : 'expected an exception to be thrown');
    }

    public function summary(): int
    {
        echo "\n{$this->passed} passed, {$this->failed} failed\n";
        return $this->failed === 0 ? 0 : 1;
    }
}
