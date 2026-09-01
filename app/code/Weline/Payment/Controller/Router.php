<?php

declare(strict_types=1);

namespace Weline\Payment\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns public storefront /guide/payment routes for payment customer guides. */
final class Router implements RouterInterface
{
    private const INDEX_ROUTE = 'payment/frontend/guide/payment/index';
    private const VIEW_ROUTE = 'payment/frontend/guide/payment/view';
    private const POLICY_ROUTE = 'payment/frontend/guide/payment/policy';
    private const AGREEMENT_ROUTE = 'payment/frontend/guide/payment/agreement';

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));

        // Public silent DevRelay API: /payment/dev-relay/* → frontend controller table.
        if (preg_match('#^payment/dev-relay(?:/(.*))?$#D', $normalizedPath, $relayMatch) === 1) {
            $suffix = trim((string) ($relayMatch[1] ?? ''), '/');
            $path = $suffix === ''
                ? 'payment/frontend/dev-relay'
                : 'payment/frontend/dev-relay/' . $suffix;
            $rule['module'] = 'Weline_Payment';

            return;
        }

        if ($normalizedPath === 'guide/payment') {
            $path = self::INDEX_ROUTE;
            $rule['module'] = 'Weline_Payment';

            return;
        }

        if (preg_match('#^guide/payment/([a-z0-9][a-z0-9_.-]*)/policy$#D', $normalizedPath, $matches) === 1) {
            $path = self::POLICY_ROUTE;
            $rule['module'] = 'Weline_Payment';
            \Weline\Framework\Context::current()->set('input.query.method_code', (string) $matches[1]);

            return;
        }

        if (preg_match('#^guide/payment/([a-z0-9][a-z0-9_.-]*)/agreement$#D', $normalizedPath, $matches) === 1) {
            $path = self::AGREEMENT_ROUTE;
            $rule['module'] = 'Weline_Payment';
            \Weline\Framework\Context::current()->set('input.query.method_code', (string) $matches[1]);

            return;
        }

        if (preg_match('#^guide/payment/([a-z0-9][a-z0-9_.-]*)$#D', $normalizedPath, $matches) !== 1) {
            return;
        }

        $path = self::VIEW_ROUTE;
        $rule['module'] = 'Weline_Payment';
        \Weline\Framework\Context::current()->set('input.query.method_code', (string) $matches[1]);
    }
}
