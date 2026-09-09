<?php

declare(strict_types=1);

namespace Weline\Help\Controller;

use Weline\Framework\Router\RouterInterface;
use Weline\Help\Api\Uri\HelpNamespace;

/** Owns public storefront /help and /faq routes. */
final class Router implements RouterInterface
{
    private const INDEX_ROUTE = 'help/frontend';
    private const VIEW_ROUTE = 'help/frontend/view';

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($normalizedPath === HelpNamespace::PREFIX || $normalizedPath === HelpNamespace::FAQ_ALIAS) {
            $path = self::INDEX_ROUTE;
            $rule['module'] = 'Weline_Help';

            return;
        }

        if (preg_match('#^help/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$#D', $normalizedPath, $matches) !== 1) {
            return;
        }

        $path = self::VIEW_ROUTE;
        $rule['module'] = 'Weline_Help';
        \Weline\Framework\Context::current()->set('input.query.slug', (string)$matches[1]);
    }
}
