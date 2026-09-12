<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Storefront-facing category visibility helpers.
 *
 * Dropship shell nests taxonomy under /sourcing/{provider}/… — those two
 * organizational levels must not appear as customer-facing crumbs/menu labels
 * (they telegraph reseller/sourcing identity).
 */
final class StorefrontCategoryPublicFilter
{
    /**
     * True for Dropship shell root (`sourcing`) or provider branch (`sourcing/cj`).
     */
    public static function isShellOrganizationPath(string $path): bool
    {
        $path = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($path === 'sourcing') {
            return true;
        }

        return (bool)preg_match('#^sourcing/[a-z0-9_-]+$#', $path);
    }

    /**
     * Legacy/blunt reseller labels that must never show to shoppers.
     */
    public static function isResellerBrandLabel(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        return (bool)preg_match('/货源商城|CJ货源|来源\\s*CJ|来源\\s*DROPSHIP|DROPSHIP/iu', $name);
    }

    /**
     * @param array<string, mixed> $row category row with path/name
     */
    public static function shouldHideFromCustomers(array $row): bool
    {
        $path = (string)($row['path'] ?? '');
        $name = (string)($row['name'] ?? '');

        return self::isShellOrganizationPath($path) || self::isResellerBrandLabel($name);
    }
}
