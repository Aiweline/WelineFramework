<?php

declare(strict_types=1);

namespace Weline\Newsletter\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns public storefront /newsletter/subscribe → Frontend Subscribe. */
final class Router implements RouterInterface
{
    private const SUBSCRIBE_ROUTE = 'newsletter/frontend/subscribe';

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = \strtolower(\trim(\str_replace('\\', '/', $path), '/'));
        if ($normalizedPath !== 'newsletter/subscribe' && $normalizedPath !== 'newsletter/frontend/subscribe') {
            return;
        }

        $path = self::SUBSCRIBE_ROUTE;
        $rule['module'] = 'Weline_Newsletter';
    }
}
