<?php

declare(strict_types=1);

namespace Weline\Catalog\Service;

/**
 * Shared storefront-aligned limits for universal category icon + Amazon-style banner.
 */
final class CategoryMediaConstraints
{
    /** Category icon / list image — keep within a few tens of KB. */
    public const IMAGE_MAX_BYTES = 32 * 1024;

    /** Category detail banner — Amazon-style strip, keep under 100 KB. */
    public const BANNER_MAX_BYTES = 100 * 1024;

    public const IMAGE_RECOMMEND_WIDTH = 80;
    public const IMAGE_RECOMMEND_HEIGHT = 80;

    /** Common Amazon category header / storefront strip. */
    public const BANNER_RECOMMEND_WIDTH = 1500;
    public const BANNER_RECOMMEND_HEIGHT = 300;

    public const IMAGE_URL_MAX_LENGTH = 512;
    public const BANNER_URL_MAX_LENGTH = 512;
    public const SUMMARY_MAX_LENGTH = 500;
    public const DESCRIPTION_MAX_LENGTH = 20000;

    public static function assertImageUrl(string $url): string
    {
        return self::assertUrl($url, self::IMAGE_URL_MAX_LENGTH, (string)__('分类图标地址过长'));
    }

    public static function assertBannerUrl(string $url): string
    {
        return self::assertUrl($url, self::BANNER_URL_MAX_LENGTH, (string)__('分类 Banner 地址过长'));
    }

    public static function assertSummary(string $summary): string
    {
        $summary = trim($summary);
        if (mb_strlen($summary) > self::SUMMARY_MAX_LENGTH) {
            throw new \InvalidArgumentException((string)__('分类摘要过长（最多 %{1} 字）', [self::SUMMARY_MAX_LENGTH]));
        }

        return $summary;
    }

    public static function assertDescription(string $description): string
    {
        $description = trim($description);
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw new \InvalidArgumentException((string)__('分类描述过长'));
        }

        return $description;
    }

    public static function assertSelectedBytes(?int $bytes, int $maxBytes, string $label): void
    {
        if ($bytes === null) {
            return;
        }
        if ($bytes < 0 || $bytes > $maxBytes) {
            throw new \InvalidArgumentException((string)__(
                '%{1}文件过大（上限 %{2} KB）',
                [$label, (string)(int)floor($maxBytes / 1024)],
            ));
        }
    }

    private static function assertUrl(string $url, int $maxLength, string $tooLongMessage): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (mb_strlen($url) > $maxLength) {
            throw new \InvalidArgumentException($tooLongMessage);
        }

        return $url;
    }
}
