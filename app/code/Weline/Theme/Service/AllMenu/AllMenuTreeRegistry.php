<?php

declare(strict_types=1);

namespace Weline\Theme\Service\AllMenu;

/**
 * Request-scoped registry so all-menu widget can publish menu_tree for the drawer.
 */
final class AllMenuTreeRegistry
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $tree = null;

    private static bool $published = false;

    /**
     * @param list<array<string, mixed>> $tree
     */
    public static function publish(array $tree): void
    {
        self::$tree = $tree;
        self::$published = true;
    }

    public static function hasPublished(): bool
    {
        return self::$published;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public static function get(): ?array
    {
        return self::$tree;
    }

    public static function reset(): void
    {
        self::$tree = null;
        self::$published = false;
    }
}
