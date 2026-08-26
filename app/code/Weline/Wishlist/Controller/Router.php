<?php

declare(strict_types=1);

namespace Weline\Wishlist\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns the Wishlist module's public storefront wishlist route. */
final class Router implements RouterInterface
{
    private const INDEX_ROUTE = 'weline_wishlist/frontend';

    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($normalizedPath !== 'wishlist') {
            return;
        }

        $path = self::INDEX_ROUTE;
        $rule['module'] = 'Weline_Wishlist';
    }
}
