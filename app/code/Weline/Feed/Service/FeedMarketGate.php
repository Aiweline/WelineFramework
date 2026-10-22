<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

/**
 * Country intersection that is already explicit. An empty country is not a guess.
 */
final class FeedMarketGate
{
    /** @var list<string> */
    private const SANCTIONED = ['CU', 'IR', 'KP', 'SY', 'CRIMEA'];

    public static function excluded(string $channelCode, string $country): bool
    {
        $country = strtoupper(trim($country));
        if ($country === '') {
            return false;
        }
        if (in_array($country, self::SANCTIONED, true)) {
            return true;
        }
        if ($channelCode === 'naver_shopping') {
            return $country !== 'KR';
        }
        if ($channelCode === 'yandex_market') {
            return !in_array($country, ['RU', 'KZ', 'BY'], true);
        }
        if ($channelCode === 'vk_ads') {
            return $country !== 'RU';
        }
        if (in_array($channelCode, ['google_merchant', 'meta_catalog'], true)) {
            return $country === 'RU';
        }

        return false;
    }

    public static function hidesAuthorized(string $channelCode, string $country): bool
    {
        return self::excluded($channelCode, $country);
    }
}
