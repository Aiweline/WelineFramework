<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * 支付模块统一浏览器回跳路由（OAuth / Provider return）。
 * Webhook 仍使用 payment/frontend/callback/notify。
 *
 * 路径唯一；环境范围用 query `target_scope`（三段 storage_scope）携带，
 * 避免店铺/站点授权时回调地址看不出写入范围。
 */
final class PaymentBrowserCallbackRoutes
{
    public const RETURN = 'payment/frontend/callback/return';

    public const NOTIFY = 'payment/frontend/callback/notify';

    /** Return URL 上标识配置写入范围的 query 键（值为三段 storage_scope）。 */
    public const QUERY_TARGET_SCOPE = 'target_scope';

    /**
     * 在壳统一 Return URL 上附加/覆盖 target_scope。
     *
     * @throws \InvalidArgumentException 非三段 storage_scope
     */
    public static function withTargetScope(string $url, string $storageScope): string
    {
        $url = trim($url);
        $storageScope = strtolower(trim($storageScope));
        if ($url === '' || !self::isStorageScope($storageScope)) {
            throw new \InvalidArgumentException('payment_callback_target_scope_invalid');
        }

        $parts = parse_url($url);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('payment_callback_return_url_invalid');
        }

        $query = [];
        if (isset($parts['query']) && \is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
            if (!\is_array($query)) {
                $query = [];
            }
        }
        $query[self::QUERY_TARGET_SCOPE] = $storageScope;

        $rebuilt = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        $rebuilt .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    public static function isStorageScope(string $storageScope): bool
    {
        return $storageScope !== ''
            && preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+){2}$/D', $storageScope) === 1;
    }
}
