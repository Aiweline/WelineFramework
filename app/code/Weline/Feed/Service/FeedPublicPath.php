<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

/**
 * Public pull path. It is never the Geo discovery feed.
 */
final class FeedPublicPath
{
    public const PREFIX = '/feed/protocol/document';

    public static function forToken(string $token): string
    {
        $token = trim($token);
        if ($token === '' || self::isForbiddenToken($token)) {
            throw new \InvalidArgumentException('feed_public_token_invalid');
        }

        return self::PREFIX . '?token=' . rawurlencode($token);
    }

    public static function isForbiddenToken(string $token): bool
    {
        $token = strtolower(trim($token));
        if ($token === '') {
            return true;
        }
        if (str_contains($token, '/') || str_contains($token, '\\')) {
            return true;
        }

        return $token === 'geo-feed.xml' || $token === 'geo-feed.json';
    }
}
