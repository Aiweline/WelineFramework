<?php

declare(strict_types=1);

namespace Weline\Payment\Controller;

use Weline\Framework\Router\RouterInterface;
use Weline\Payment\Service\PaymentBrowserCallbackRoutes;

/** Owns public storefront /guide/payment routes for payment customer guides. */
final class Router implements RouterInterface
{
    // Must match generated frontend_pc key (controller index method, no trailing /index).
    private const INDEX_ROUTE = 'payment/frontend/guide/payment';
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

        if (preg_match('#^payment/frontend/provider/([a-z0-9][a-z0-9_.-]*)/([a-z0-9][a-z0-9_.-]*)$#D', $normalizedPath, $providerMatch) === 1) {
            $path = 'payment/frontend/provider-gateway/dispatch';
            $rule['module'] = 'Weline_Payment';
            \Weline\Framework\Context::current()->set('input.query.method_code', (string) $providerMatch[1]);
            \Weline\Framework\Context::current()->set('input.query.action', (string) $providerMatch[2]);

            return;
        }

        // 旧嵌套 / .cancel 后缀路径已废除
        if ($normalizedPath === 'payment/frontend/callback/return'
            || $normalizedPath === 'payment/frontend/callback/cancel'
            || str_starts_with($normalizedPath, 'payment/frontend/callback/return/')
            || str_starts_with($normalizedPath, 'payment/frontend/callback/cancel/')
            || preg_match('#^payment/frontend/callback/[a-z0-9][a-z0-9_.-]*\.cancel$#D', $normalizedPath) === 1
        ) {
            $path = 'payment/frontend/callback/__removed__';
            $rule['module'] = 'Weline_Payment';

            return;
        }

        // 唯一浏览器入口：callback/{method}（取消靠 query outcome=cancel）
        if (preg_match('#^payment/frontend/callback/([a-z0-9][a-z0-9_.-]*)$#D', $normalizedPath, $returnMatch) === 1) {
            $methodCode = (string) $returnMatch[1];
            if (PaymentBrowserCallbackRoutes::isReservedCallbackSegment($methodCode)) {
                return;
            }
            $path = PaymentBrowserCallbackRoutes::RETURN_DISPATCH;
            $rule['module'] = 'Weline_Payment';
            $rule[PaymentBrowserCallbackRoutes::QUERY_METHOD_CODE] = $methodCode;
            \Weline\Framework\Context::current()->set('input.query.method_code', $methodCode);

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
