<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

final class CompiledExtensionRegistry
{
    /** @var array<string, array> Thin mirror of CompiledPhpRegistry payloads (SoftSurvivor dual-register). */
    private static array $registries = [];

    public static function hooks(): array
    {
        return self::load('hooks.php')['hooks'] ?? [];
    }

    public static function tags(): array
    {
        return self::load('taglibs.php')['tags'] ?? [];
    }

    public static function hookExists(string $name): bool
    {
        return isset(self::hooks()[$name]);
    }

    public static function hookHasSpec(string $name): bool
    {
        return (bool)(self::hooks()[$name]['has_spec'] ?? false);
    }

    public static function count(string $file, ?string $key = null): int
    {
        $registry = self::load($file);
        $rows = $key === null ? $registry : ($registry[$key] ?? []);
        return is_array($rows) ? count($rows) : 0;
    }

    private static function load(string $file): array
    {
        if (isset(self::$registries[$file])) {
            return self::$registries[$file];
        }
        $path = BP . 'generated' . DIRECTORY_SEPARATOR . $file;
        $registry = CompiledPhpRegistry::load($path) ?? [];

        return self::$registries[$file] = $registry;
    }
}
