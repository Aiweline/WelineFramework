<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

use Weline\Framework\Runtime\Runtime;

/**
 * Process-resident gate for generated PHP arrays (return-array registries).
 *
 * Invariant: once a path is marked loaded, the process must not include it again
 * under WLS / Runtime::isPersistent() (mtime / forceReload are no-ops). Freshness
 * comes from Worker recycle / server:reload — not in-process re-include.
 *
 * Hard reject: generated/language/** must never enter this registry.
 */
final class CompiledPhpRegistry
{
    /**
     * SoftSurvivor ROLE_REGISTRY bag (also dual-registered with domain statics).
     *
     * @var array<string, array{loaded: bool, payload: ?array, mtime_at_load: ?int}>
     */
    private static array $entries = [];

    /**
     * Load a compiled PHP array file once per process.
     *
     * @param callable(array):bool|null $validate optional shape guard; false → store null
     */
    public static function load(string $path, ?callable $validate = null): ?array
    {
        self::assertNotLanguagePath($path);
        $key = self::normalizeKey($path);

        if (isset(self::$entries[$key]) && self::$entries[$key]['loaded'] === true) {
            return self::$entries[$key]['payload'];
        }

        if (!\is_file($path)) {
            self::$entries[$key] = [
                'loaded' => true,
                'payload' => null,
                'mtime_at_load' => null,
            ];

            return null;
        }

        /** @var mixed $raw */
        $raw = include $path;
        $payload = \is_array($raw) ? $raw : null;
        if ($payload !== null && $validate !== null && !$validate($payload)) {
            $payload = null;
        }

        self::$entries[$key] = [
            'loaded' => true,
            'payload' => $payload,
            'mtime_at_load' => (int) (@\filemtime($path) ?: 0) ?: null,
        ];

        return $payload;
    }

    public static function isLoaded(string $path): bool
    {
        self::assertNotLanguagePath($path);
        $key = self::normalizeKey($path);

        return isset(self::$entries[$key]) && self::$entries[$key]['loaded'] === true;
    }

    /**
     * Return cached payload without include. Null means not loaded or loaded-as-null.
     */
    public static function peek(string $path): ?array
    {
        self::assertNotLanguagePath($path);
        $key = self::normalizeKey($path);
        if (!isset(self::$entries[$key]) || self::$entries[$key]['loaded'] !== true) {
            return null;
        }

        return self::$entries[$key]['payload'];
    }

    /**
     * Drop a path so the next load() may include again.
     * Persistent / WLS: no-op (forbid second include). Tests run in CLI.
     */
    public static function forgetForTests(string $path): void
    {
        if (Runtime::isPersistent()) {
            return;
        }
        self::assertNotLanguagePath($path);
        unset(self::$entries[self::normalizeKey($path)]);
    }

    private static function normalizeKey(string $path): string
    {
        $normalized = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path);
        $real = \realpath($normalized);

        return $real !== false ? $real : $normalized;
    }

    private static function assertNotLanguagePath(string $path): void
    {
        $n = \str_replace('\\', '/', $path);
        $pos = \strpos($n, 'generated/');
        if ($pos === false) {
            return;
        }
        $after = \substr($n, $pos + \strlen('generated/'));
        if ($after === 'language'
            || \str_starts_with($after, 'language/')
            || \str_contains('/' . $after, '/language/')
        ) {
            throw new \RuntimeException(
                'CompiledPhpRegistry refuses generated/language paths; locale bags must not be process-resident REGISTRY.',
            );
        }
    }
}
