<?php

declare(strict_types=1);

namespace Weline\Customer\Controller;

use Weline\Framework\Http\Request;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;

/** Owns public storefront /guide/social-login routes + legacy account aliases. */
final class Router implements RouterInterface
{
    private const INDEX_ROUTE = 'customer/frontend/guide/social-login';
    private const VIEW_ROUTE = 'customer/frontend/guide/social-login/view';
    private const POLICY_ROUTE = 'customer/frontend/guide/social-login/policy';

    /** Magento-style register alias → canonical customer/account/register (QA-13). */
    private const REGISTER_ALIASES = [
        'customer/account/create',
    ];

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));

        if (in_array($normalizedPath, self::REGISTER_ALIASES, true)) {
            $rule['module'] = 'Weline_Customer';
            throw new ResponseTerminateException(301, '', [
                'Location' => self::buildRegisterLocation(),
            ]);
        }

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

    private static function buildRegisterLocation(): string
    {
        try {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            $built = (string)$url->getUrl('customer/account/register');
            if ($built !== '') {
                return $built;
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $base = rtrim((string)$request->getOriginBaseUrl(), '/');
            if ($base !== '') {
                return $base . '/customer/account/register';
            }
        } catch (\Throwable) {
            // keep relative fallback
        }

        return '/customer/account/register';
    }
}
