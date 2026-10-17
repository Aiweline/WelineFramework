<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

use Weline\Framework\Runtime\Runtime;

/**
 * Process-resident gate for generated PHP arrays (return-array registries).
 *
 * Loading policy:
 * - WLS Worker: bootstrap warms MUST-IN paths once (CompiledRegistryWarmProvider).
 * - FPM / CLI: lazy load on first load() — no forced startup hydrate.
 * - Any mode: load() is a no-op include when the path is already marked loaded.
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
     * Write-through: store payload in the process bag without include.
     *
     * Use after publishing a MUST-IN registry (e.g. app/etc/modules.php) so
     * subsequent load()/peek() see the new array and never re-include.
     * Works under WLS / Runtime::isPersistent() (unlike forgetForTests).
     *
     * @param array<string, mixed> $payload
     */
    public static function put(string $path, array $payload): void
    {
        self::assertNotLanguagePath($path);
        $key = self::normalizeKey($path);
        $mtime = \is_file($path) ? ((int) (@\filemtime($path) ?: 0) ?: null) : null;
        self::$entries[$key] = [
            'loaded' => true,
            'payload' => $payload,
            'mtime_at_load' => $mtime,
        ];
    }

    /**
     * Drop a path so the next load() may include again.
     * Persistent / WLS: no-op (forbid second include). Tests run in CLI.
     * Prefer put() after runtime writes — do not forget+re-include hot registries.
     */
    public static function forgetForTests(string $path): void
    {
        if (Runtime::isPersistent()) {
            return;
        }
        self::assertNotLanguagePath($path);
        unset(self::$entries[self::normalizeKey($path)]);
    }

    /**
     * Absolute paths for framework runtime registries that WLS bootstrap should warm.
     * Excludes generated/language/** and compile_manifest.php (MUST-OUT).
     *
     * @return list<string>
     */
    public static function mustInRuntimePaths(): array
    {
        $generated = BP . 'generated' . \DIRECTORY_SEPARATOR;
        $framework = $generated . 'framework' . \DIRECTORY_SEPARATOR;
        $routers = $generated . 'routers' . \DIRECTORY_SEPARATOR;

        $paths = [
            $framework . 'query_providers.php',
            $framework . 'modules.php',
            $framework . 'container.php',
            $framework . 'controller_extra.php',
            $framework . 'fpc_bypass_rules.php',
            $framework . 'fpc_policy_snapshot.php',
            $framework . 'template_cache_policies.php',
            $framework . 'runtime_policy_providers.php',
            $generated . 'hooks.php',
            $generated . 'extends.php',
            $generated . 'plugins.php',
            $generated . 'taglibs.php',
            $generated . 'widgets.php',
            $generated . 'events.php',
            $generated . 'reflection_metadata.php',
            $generated . 'compiled_factories.php',
            $generated . 'reflection_safe_classes.php',
            $generated . 'classmap.php',
            $generated . 'commands.php',
            $generated . 'param_schemas.php',
            $generated . 'psr4_map.php',
            $routers . 'frontend_pc.php',
            $routers . 'frontend_rest_api.php',
            $routers . 'backend_pc.php',
            $routers . 'backend_rest_api.php',
        ];
        // Live module list + dependency map (app/etc) — process-gated for whole lifecycle.
        if (\defined('APP_ETC_PATH')) {
            $paths[] = APP_ETC_PATH . 'modules.php';
            $paths[] = APP_ETC_PATH . 'module_dependencies.php';
        }
        // Database driver map — used across DB open path for the process lifetime.
        $paths[] = $generated . 'database' . \DIRECTORY_SEPARATOR . 'driver.php';

        return $paths;
    }

    /**
     * Warm many registry paths once (WLS bootstrap). Already-loaded paths are skipped
     * (no second include). Missing files mark loaded-as-null.
     *
     * @param list<string>|null $paths null → mustInRuntimePaths()
     * @return array{warmed:int, skipped:int, missing:int, paths:list<string>}
     */
    public static function warmPaths(?array $paths = null): array
    {
        $paths ??= self::mustInRuntimePaths();
        $warmed = 0;
        $skipped = 0;
        $missing = 0;
        foreach ($paths as $path) {
            if (!\is_string($path) || $path === '') {
                continue;
            }
            if (self::isLoaded($path)) {
                $skipped++;
                continue;
            }
            $payload = self::load($path);
            if ($payload === null && !\is_file($path)) {
                $missing++;
            } else {
                $warmed++;
            }
        }

        return [
            'warmed' => $warmed,
            'skipped' => $skipped,
            'missing' => $missing,
            'paths' => \array_values(\array_filter($paths, static fn($p): bool => \is_string($p) && $p !== '')),
        ];
    }

    /**
     * @param list<string> $paths
     * @return list<string> paths not yet marked loaded
     */
    public static function missingLoaded(array $paths): array
    {
        $missing = [];
        foreach ($paths as $path) {
            if (!\is_string($path) || $path === '') {
                continue;
            }
            if (!self::isLoaded($path)) {
                $missing[] = $path;
            }
        }

        return $missing;
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
