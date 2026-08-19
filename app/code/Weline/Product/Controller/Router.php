<?php

declare(strict_types=1);

namespace Weline\Product\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns the Product module's short public catalog routes. */
final class Router implements RouterInterface
{
    private const CATALOG_ROUTE = 'weline_product/frontend/catalog';
    private const DETAIL_ROUTE = 'weline_product/frontend/detail';

    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if (in_array($normalizedPath, ['products', 'product-list'], true)) {
            $path = self::CATALOG_ROUTE;
            $rule['module'] = 'Weline_Product';
            return;
        }

        if (preg_match('#^product/([1-9][0-9]*)$#D', $normalizedPath, $matches) !== 1) {
            return;
        }

        $productId = (int)$matches[1];
        if ($productId <= 0) {
            return;
        }

        $path = self::DETAIL_ROUTE;
        $rule['module'] = 'Weline_Product';
        \Weline\Framework\Context::current()->set('input.query.id', $productId);
    }
}
