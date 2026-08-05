<?php

declare(strict_types=1);

namespace Weline\Acl\Service\Resource;

/**
 * Unified live source_id set for one route-collection / ACL diff round (D-10).
 */
final class LiveSourceSet
{
    /** @var array<string,true> */
    private static array $sourceIds = [];

    /** @var list<string>|null */
    private static ?array $touchedModules = null;

    public static function clear(): void
    {
        self::$sourceIds = [];
        self::$touchedModules = null;
    }

    public static function add(string ...$sourceIds): void
    {
        foreach ($sourceIds as $id) {
            $id = \trim($id);
            if ($id !== '') {
                self::$sourceIds[$id] = true;
            }
        }
    }

    /** @param list<string> $sourceIds */
    public static function addMany(array $sourceIds): void
    {
        self::add(...$sourceIds);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return \array_keys(self::$sourceIds);
    }

    /** @param list<string>|null $modules null = full (all active modules) */
    public static function setTouchedModules(?array $modules): void
    {
        if ($modules === null) {
            self::$touchedModules = null;
            return;
        }
        self::$touchedModules = \array_values(\array_unique(\array_filter(\array_map('strval', $modules))));
    }

    /** @return list<string>|null null means full cleanup across active modules */
    public static function touchedModules(): ?array
    {
        return self::$touchedModules;
    }
}
