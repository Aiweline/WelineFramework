<?php

declare(strict_types=1);

namespace Weline\Currency\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns public storefront /currency route for currency policy page. */
final class Router implements RouterInterface
{
    private const INDEX_ROUTE = 'currency/frontend/index/index';

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($normalizedPath !== 'currency') {
            return;
        }

        $path = self::INDEX_ROUTE;
        $rule['module'] = 'Weline_Currency';
    }
}
