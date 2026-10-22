<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

/**
 * Operator-facing connection word. A missing application secret is 「未连接」.
 * A local file never promotes that word to 「已授权」.
 */
final class FeedOperatorConnectionStatus
{
    public const DISCONNECTED = '未连接';
    public const AWAITING = '待授权';
    public const INCOMPLETE = '授权未完成';
    public const AUTHORIZED = '已授权';
    public const REAUTH = '需重新授权';

    /**
     * @param array<string, string> $secrets
     */
    public static function resolve(string $channelCode, array $secrets): string
    {
        if (!self::hasApplicationCredentials($channelCode, $secrets)) {
            return self::DISCONNECTED;
        }
        if (trim((string)($secrets['reauthorization_required'] ?? '')) === '1') {
            return self::REAUTH;
        }
        if (self::hasRemoteReceipt($secrets)) {
            return self::AUTHORIZED;
        }
        if (trim((string)($secrets['authorization_attempted'] ?? '')) === '1') {
            return self::INCOMPLETE;
        }

        return self::AWAITING;
    }

    /**
     * @param array<string, string> $secrets
     */
    public static function hasApplicationCredentials(string $channelCode, array $secrets): bool
    {
        $channelCode = trim($channelCode);
        if (in_array($channelCode, ['criteo', 'naver_shopping'], true)) {
            return trim((string)($secrets['api_key'] ?? '')) !== '';
        }
        $clientId = trim((string)($secrets['client_id'] ?? ''));
        $clientSecret = trim((string)($secrets['client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            return false;
        }
        if ($channelCode === 'microsoft_merchant') {
            return trim((string)($secrets['developer_token'] ?? '')) !== '';
        }

        return true;
    }

    /**
     * @param array<string, string> $secrets
     */
    public static function hasRemoteReceipt(array $secrets): bool
    {
        foreach (['remote_account_id', 'catalog_id', 'feed_id'] as $field) {
            if (trim((string)($secrets[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
