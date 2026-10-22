<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

final class FeedCategoryVocabulary
{
    public const GOOGLE = 'google';
    public const YANDEX = 'yandex';
    public const NAVER = 'naver';

    /** @var list<string> */
    public const ALL = [self::GOOGLE, self::YANDEX, self::NAVER];

    public static function forChannel(string $channelCode, string $filenameSuffix = ''): string
    {
        if ($channelCode === 'naver_shopping') {
            return self::NAVER;
        }
        if ($channelCode === 'yandex_market') {
            return self::YANDEX;
        }
        if ($channelCode === 'vk_ads' && strtolower($filenameSuffix) === 'xml') {
            return self::YANDEX;
        }
        if ($channelCode === 'vk_ads') {
            return '';
        }

        return self::GOOGLE;
    }

    public static function required(string $channelCode, string $filenameSuffix = ''): bool
    {
        if ($channelCode === 'yandex_market' || $channelCode === 'naver_shopping') {
            return true;
        }

        return $channelCode === 'vk_ads' && strtolower($filenameSuffix) === 'xml';
    }
}
