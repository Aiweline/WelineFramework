<?php

declare(strict_types=1);

namespace Weline\Theme\Service\AllMenu;

/**
 * Request-scoped registry so all-menu widget can publish:
 * - menu_tree for the drawer
 * - optional leading all-products leaf for the horizontal category bar
 */
final class AllMenuTreeRegistry
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $tree = null;

    private static bool $published = false;

    private static bool $allProductsPublished = false;

    private static bool $allProductsEnabled = true;

    private static string $allProductsLabel = '全部商品';

    private static string $allProductsUrl = '/products';

    private static string $allProductsDescription = '浏览全部已发布商品';

    /**
     * @param list<array<string, mixed>> $tree
     */
    public static function publish(array $tree): void
    {
        self::$tree = $tree;
        self::$published = true;
    }

    public static function publishAllProductsNav(
        bool $enabled = true,
        string $label = '全部商品',
        string $url = '/products',
        string $description = '浏览全部已发布商品',
    ): void {
        $label = trim($label);
        $url = trim($url);
        $description = trim($description);
        self::$allProductsEnabled = $enabled;
        self::$allProductsLabel = $label !== '' ? $label : '全部商品';
        self::$allProductsUrl = ($url !== '' && $url !== '#') ? $url : '/products';
        self::$allProductsDescription = $description !== '' ? $description : '浏览全部已发布商品';
        self::$allProductsPublished = true;
    }

    public static function hasPublished(): bool
    {
        return self::$published;
    }

    public static function hasAllProductsNavPublished(): bool
    {
        return self::$allProductsPublished;
    }

    public static function allProductsEnabled(): bool
    {
        return self::$allProductsEnabled;
    }

    public static function allProductsLabel(): string
    {
        return self::$allProductsLabel;
    }

    public static function allProductsUrl(): string
    {
        return self::$allProductsUrl;
    }

    public static function allProductsDescription(): string
    {
        return self::$allProductsDescription;
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
        self::$allProductsPublished = false;
        self::$allProductsEnabled = true;
        self::$allProductsLabel = '全部商品';
        self::$allProductsUrl = '/products';
        self::$allProductsDescription = '浏览全部已发布商品';
    }
}
