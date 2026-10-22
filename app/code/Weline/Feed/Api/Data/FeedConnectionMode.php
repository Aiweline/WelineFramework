<?php

declare(strict_types=1);

namespace Weline\Feed\Api\Data;

/**
 * Connection mode stored on a definition and an account. Not a six-state label.
 */
final class FeedConnectionMode
{
    public const OAUTH = 'oauth';
    public const FEED_URL = 'feed_url';
    public const API_KEY = 'api_key';

    /** @var list<string> */
    public const ALL = [
        self::OAUTH,
        self::FEED_URL,
        self::API_KEY,
    ];

    public static function isKnown(string $mode): bool
    {
        return in_array($mode, self::ALL, true);
    }
}
