<?php

declare(strict_types=1);

namespace Weline\Faq\Controller;

use Weline\Framework\Router\RouterInterface;
use Weline\Faq\Api\Uri\FaqNamespace;

/** Owns public storefront /faq and /faq/{slug}. */
final class Router implements RouterInterface
{
    private const INDEX_ROUTE = 'faq/frontend';
    private const VIEW_ROUTE = 'faq/frontend/view';

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($normalizedPath === FaqNamespace::PREFIX) {
            $path = self::INDEX_ROUTE;
            $rule['module'] = 'Weline_Faq';

            return;
        }

        if (preg_match('#^faq/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$#D', $normalizedPath, $matches) !== 1) {
            return;
        }

        $path = self::VIEW_ROUTE;
        $rule['module'] = 'Weline_Faq';
        \Weline\Framework\Context::current()->set('input.query.slug', (string)$matches[1]);
    }
}
