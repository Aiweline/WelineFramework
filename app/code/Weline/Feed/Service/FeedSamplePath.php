<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

/**
 * Operator sample URL. It is never a Geo discovery path.
 */
final class FeedSamplePath
{
    public const PREFIX = '/feed/protocol/sample';

    public static function forChannel(string $channelCode): string
    {
        $channelCode = trim($channelCode);
        if ($channelCode === '' || str_contains(strtolower($channelCode), 'geo-feed')) {
            throw new \InvalidArgumentException('feed_sample_channel_invalid');
        }

        return self::PREFIX . '?channel=' . rawurlencode($channelCode);
    }
}
