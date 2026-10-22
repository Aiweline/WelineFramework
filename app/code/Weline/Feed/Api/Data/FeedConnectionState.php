<?php

declare(strict_types=1);

namespace Weline\Feed\Api\Data;

/**
 * The only six connection labels. A local file or a copied URL is never 「已授权」.
 */
final class FeedConnectionState
{
    public const APP_UNCONFIGURED = '未配置应用';
    public const AWAITING_AUTHORIZATION = '待授权';
    public const AUTHORIZATION_INCOMPLETE = '授权未完成';
    public const ADDRESS_ONLY = '仅已生成地址（未连接）';
    public const AUTHORIZED = '已授权';
    public const REAUTHORIZATION_REQUIRED = '需重新授权';

    /** @var list<string> */
    public const ALL = [
        self::APP_UNCONFIGURED,
        self::AWAITING_AUTHORIZATION,
        self::AUTHORIZATION_INCOMPLETE,
        self::ADDRESS_ONLY,
        self::AUTHORIZED,
        self::REAUTHORIZATION_REQUIRED,
    ];

    public static function isAllowed(string $state): bool
    {
        return in_array($state, self::ALL, true);
    }
}
