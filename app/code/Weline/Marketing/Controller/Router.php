<?php

declare(strict_types=1);

namespace Weline\Marketing\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns public storefront /marketing/campaign route for campaign hub. */
final class Router implements RouterInterface
{
    private const INDEX_ROUTE = 'marketing/frontend/campaigns/index';

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($normalizedPath !== 'marketing/campaign' && $normalizedPath !== 'campaigns') {
            return;
        }

        $path = self::INDEX_ROUTE;
        $rule['module'] = 'Weline_Marketing';
    }
}
