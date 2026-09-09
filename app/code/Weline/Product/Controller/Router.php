<?php

declare(strict_types=1);

namespace Weline\Product\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns the Product module's short public catalog routes. */
final class Router implements RouterInterface
{
    private const CATALOG_ROUTE = 'weline_product/frontend/catalog';
    private const CATEGORY_ROUTE = 'weline_product/frontend/category';
    private const DETAIL_ROUTE = 'weline_product/frontend/detail';
    private const DOWNLOAD_ROUTE = 'weline_product/frontend/download';
    private const BEST_SELLERS_ROUTE = 'weline_product/frontend/best-sellers';
    private const NEW_ARRIVALS_ROUTE = 'weline_product/frontend/new-arrivals';

    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if (in_array($normalizedPath, [
            'new-arrivals/rss.xml',
            'newarrivals/rss.xml',
            'new_arrivals/rss.xml',
        ], true)) {
            $path = 'weline_product/frontend/new-arrivals-rss';
            $rule['module'] = 'Weline_Product';

            return;
        }
        if (in_array($normalizedPath, ['new-arrivals', 'newarrivals', 'new_arrivals'], true)) {
            $path = self::NEW_ARRIVALS_ROUTE;
            $rule['module'] = 'Weline_Product';

            return;
        }
        if (preg_match(
            '#^product-download/([a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12})$#D',
            $normalizedPath,
            $matches,
        ) === 1) {
            $path = self::DOWNLOAD_ROUTE;
            $rule['module'] = 'Weline_Product';
            \Weline\Framework\Context::current()->set(
                'input.query.entitlement_uuid',
                (string)$matches[1],
            );
            return;
        }

        if (in_array($normalizedPath, ['products', 'product-list'], true)) {
            $path = self::CATALOG_ROUTE;
            $rule['module'] = 'Weline_Product';
            return;
        }

        if (in_array($normalizedPath, ['best-sellers', 'bestsellers', 'best_sellers'], true)) {
            $path = self::BEST_SELLERS_ROUTE;
            $rule['module'] = 'Weline_Product';
            return;
        }

        if (preg_match('#^category/(.+)$#D', $normalizedPath, $matches) === 1) {
            $categoryPath = trim((string)$matches[1], '/');
            if ($categoryPath === '') {
                return;
            }

            $path = self::CATEGORY_ROUTE;
            $rule['module'] = 'Weline_Product';
            \Weline\Framework\Context::current()->set('input.query.path', $categoryPath);

            return;
        }

        if ($normalizedPath === 'category' || $normalizedPath === 'categories') {
            $path = self::CATALOG_ROUTE;
            $rule['module'] = 'Weline_Product';

            return;
        }

        if (preg_match('#^product/([1-9][0-9]*)$#D', $normalizedPath, $matches) === 1) {
            $productId = (int)$matches[1];
            if ($productId <= 0) {
                return;
            }

            $path = self::DETAIL_ROUTE;
            $rule['module'] = 'Weline_Product';
            \Weline\Framework\Context::current()->set('input.query.id', $productId);

            return;
        }

        if (preg_match('#^product/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$#D', $normalizedPath, $matches) === 1) {
            $slug = (string)$matches[1];
            if ($slug === '') {
                return;
            }

            $path = self::DETAIL_ROUTE;
            $rule['module'] = 'Weline_Product';
            \Weline\Framework\Context::current()->set('input.query.slug', $slug);

            return;
        }

        // /product?id=… or /product?slug=… — claim before Theme shell can steal bare "product".
        if ($normalizedPath === 'product') {
            [$querySlug, $queryProductId] = self::queryIdentity();
            if ($queryProductId <= 0 && $querySlug === '') {
                return;
            }

            $path = self::DETAIL_ROUTE;
            $rule['module'] = 'Weline_Product';
            if ($queryProductId > 0) {
                \Weline\Framework\Context::current()->set('input.query.id', $queryProductId);
            }
            if ($querySlug !== '') {
                \Weline\Framework\Context::current()->set('input.query.slug', $querySlug);
            }
        }
    }

    /**
     * @return array{0:string,1:int}
     */
    private static function queryIdentity(): array
    {
        $slug = '';
        $productId = 0;

        try {
            $ctx = \Weline\Framework\Context::current();
            $slug = strtolower(trim((string)($ctx->query('slug') ?? '')));
            $productId = (int)($ctx->query('id') ?? 0);
        } catch (\Throwable) {
        }

        if ($slug === '' && isset($_GET['slug']) && is_scalar($_GET['slug'])) {
            $slug = strtolower(trim((string)$_GET['slug']));
        }
        if ($productId <= 0 && isset($_GET['id']) && is_scalar($_GET['id'])) {
            $productId = (int)$_GET['id'];
        }

        if ($slug === '' || $productId <= 0) {
            try {
                /** @var \Weline\Framework\Http\Request $request */
                $request = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \Weline\Framework\Http\Request::class
                );
                if ($productId <= 0) {
                    $productId = (int)$request->getParam('id', 0);
                }
                if ($slug === '') {
                    $slug = strtolower(trim((string)$request->getParam('slug', '')));
                }
            } catch (\Throwable) {
            }
        }

        return [$slug, max(0, $productId)];
    }
}
