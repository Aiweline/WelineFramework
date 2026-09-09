<?php

declare(strict_types=1);

namespace Weline\Blog\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns the Blog module's public storefront /blog routes. */
final class Router implements RouterInterface
{
    private const INDEX_ROUTE = 'blog/frontend';
    private const VIEW_ROUTE = 'blog/frontend/view';
    private const CATEGORY_ROUTE = 'blog/frontend/category';
    private const RSS_ROUTE = 'blog/frontend/rss';

    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($normalizedPath === 'blog') {
            $path = self::INDEX_ROUTE;
            $rule['module'] = 'Weline_Blog';

            return;
        }

        if ($normalizedPath === 'blog/rss.xml') {
            $path = self::RSS_ROUTE;
            $rule['module'] = 'Weline_Blog';

            return;
        }

        if ($normalizedPath === 'blog/category') {
            $path = self::CATEGORY_ROUTE;
            $rule['module'] = 'Weline_Blog';

            return;
        }

        if (preg_match('#^blog/category/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)/rss\.xml$#D', $normalizedPath, $matches) === 1) {
            $path = self::RSS_ROUTE;
            $rule['module'] = 'Weline_Blog';
            \Weline\Framework\Context::current()->set('input.query.category_slug', (string)$matches[1]);

            return;
        }

        if (preg_match('#^blog/category/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$#D', $normalizedPath, $matches) === 1) {
            $path = self::CATEGORY_ROUTE;
            $rule['module'] = 'Weline_Blog';
            \Weline\Framework\Context::current()->set('input.query.category_slug', (string)$matches[1]);

            return;
        }

        if (preg_match('#^blog/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$#D', $normalizedPath, $matches) !== 1) {
            return;
        }

        $slug = (string)$matches[1];
        if ($slug === 'category') {
            $path = self::CATEGORY_ROUTE;
            $rule['module'] = 'Weline_Blog';

            return;
        }

        $path = self::VIEW_ROUTE;
        $rule['module'] = 'Weline_Blog';
        \Weline\Framework\Context::current()->set('input.query.slug', $slug);
    }
}
