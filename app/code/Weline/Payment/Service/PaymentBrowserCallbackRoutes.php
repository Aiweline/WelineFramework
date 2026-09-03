<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * 支付模块浏览器回跳路由（OAuth / Provider return / cancel）。
 *
 * 公网唯一路径：callback/{method_code}；取消用 query outcome=cancel。
 * scope 用 query target_scope。
 */
final class PaymentBrowserCallbackRoutes
{
    /** Router 内部 action：仅由 {@see returnRoute()} 公网路径重写进入，禁止直接登记 */
    public const RETURN_DISPATCH = 'payment/frontend/callback/browser-return-entry';

    public const NOTIFY = 'payment/frontend/callback/notify';

    public const QUERY_TARGET_SCOPE = 'target_scope';

    public const QUERY_METHOD_CODE = 'method_code';

    public const QUERY_TRANSACTION_NO = 'transaction_no';

    public const QUERY_OUTCOME = 'outcome';

    public const OUTCOME_CANCEL = 'cancel';

    /** 取消落地态：已取消（幂等） vs 本次取消成功 */
    public const QUERY_CANCEL_STATE = 'cancel_state';

    public const CANCEL_STATE_ALREADY = 'already';

    public const CANCEL_STATE_DONE = 'done';

    /** @var list<string> */
    private const RESERVED_CALLBACK_SEGMENTS = [
        'notify',
        'browser-return-entry',
        'browser-cancel-entry',
        '__removed__',
        'return',
        'cancel',
    ];

    public static function returnRoute(string $methodCode): string
    {
        return 'payment/frontend/callback/' . self::normalizeMethodCode($methodCode);
    }

    /**
     * 与 {@see returnRoute()} 同路径；取消意图由 Catalog 追加 outcome=cancel。
     */
    public static function cancelRoute(string $methodCode): string
    {
        return self::returnRoute($methodCode);
    }

    public static function isCancelOutcome(array $params): bool
    {
        $outcome = strtolower(trim((string) ($params[self::QUERY_OUTCOME] ?? '')));

        return $outcome === self::OUTCOME_CANCEL;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function resolveCancelState(array $params): string
    {
        $state = strtolower(trim((string) ($params[self::QUERY_CANCEL_STATE] ?? '')));

        return $state === self::CANCEL_STATE_ALREADY
            ? self::CANCEL_STATE_ALREADY
            : self::CANCEL_STATE_DONE;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function isCancelAlready(array $params): bool
    {
        return self::resolveCancelState($params) === self::CANCEL_STATE_ALREADY;
    }

    public static function normalizeMethodCode(string $methodCode): string
    {
        $methodCode = strtolower(trim($methodCode));
        if ($methodCode === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]*$/D', $methodCode)) {
            throw new \InvalidArgumentException('payment_method_code_required');
        }
        if (\in_array($methodCode, self::RESERVED_CALLBACK_SEGMENTS, true)
            || str_contains($methodCode, '.cancel')
        ) {
            throw new \InvalidArgumentException('payment_method_code_reserved');
        }

        return $methodCode;
    }

    public static function isReservedCallbackSegment(string $segment): bool
    {
        $segment = strtolower(trim($segment));

        return $segment !== ''
            && (\in_array($segment, self::RESERVED_CALLBACK_SEGMENTS, true)
                || str_contains($segment, '.cancel'));
    }

    /**
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
