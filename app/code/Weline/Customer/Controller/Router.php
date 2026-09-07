<?php

declare(strict_types=1);

namespace Weline\Customer\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns public storefront /guide/social-login routes. */
final class Router implements RouterInterface
{
    private const INDEX_ROUTE = 'customer/frontend/guide/social-login';
    private const VIEW_ROUTE = 'customer/frontend/guide/social-login/view';
    private const POLICY_ROUTE = 'customer/frontend/guide/social-login/policy';

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));

        if ($normalizedPath === 'guide/social-login') {
            $path = self::INDEX_ROUTE;
            $rule['module'] = 'Weline_Customer';

            return;
        }

        if (preg_match('#^guide/social-login/([a-z0-9][a-z0-9_.-]*)/policy$#D', $normalizedPath, $matches) === 1) {
            $path = self::POLICY_ROUTE;
            $rule['module'] = 'Weline_Customer';
            \Weline\Framework\Context::current()->set('input.query.provider_code', (string) $matches[1]);

            return;
        }

        if (preg_match('#^guide/social-login/([a-z0-9][a-z0-9_.-]*)$#D', $normalizedPath, $matches) !== 1) {
            return;
        }

        $path = self::VIEW_ROUTE;
        $rule['module'] = 'Weline_Customer';
        \Weline\Framework\Context::current()->set('input.query.provider_code', (string) $matches[1]);
    }
}
