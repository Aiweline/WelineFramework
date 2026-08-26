<?php

declare(strict_types=1);

namespace Weline\Compare\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns the Compare module's public storefront compare route. */
final class Router implements RouterInterface
{
    private const INDEX_ROUTE = 'weline_compare/frontend';

    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($normalizedPath !== 'compare') {
            return;
        }

        $path = self::INDEX_ROUTE;
        $rule['module'] = 'Weline_Compare';
    }
}
