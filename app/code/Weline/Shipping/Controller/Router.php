<?php

declare(strict_types=1);

namespace Weline\Shipping\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns public storefront /guide/shipping and /guide/returns routes. */
final class Router implements RouterInterface
{
    private const SHIPPING_ROUTE = 'shipping/frontend/guide/shipping';
    private const RETURNS_ROUTE = 'shipping/frontend/guide/returns';

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));

        if ($normalizedPath === 'guide/shipping') {
            $path = self::SHIPPING_ROUTE;
            $rule['module'] = 'Weline_Shipping';

            return;
        }

        if ($normalizedPath === 'guide/returns') {
            $path = self::RETURNS_ROUTE;
            $rule['module'] = 'Weline_Shipping';
        }
    }
}
