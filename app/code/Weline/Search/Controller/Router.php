<?php

declare(strict_types=1);

namespace Weline\Search\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns the Search module's public storefront search route. */
final class Router implements RouterInterface
{
    private const INDEX_ROUTE = 'search/frontend';

    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($normalizedPath !== 'search') {
            return;
        }

        $path = self::INDEX_ROUTE;
        $rule['module'] = 'Weline_Search';
    }
}
