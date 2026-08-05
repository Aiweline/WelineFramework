<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Cms\Service\PageLocaleService;

final class PageLocaleServicePolicyTest extends TestCase
{
    #[DataProvider('localeNormalizationProvider')]
    public function testLocaleNormalization(string $input, string $expected): void
    {
        self::assertSame($expected, (new PageLocaleService())->normalizeLocaleCode($input));
    }

    public static function localeNormalizationProvider(): array
    {
        return [
            'language and region' => ['en-us', 'en_US'],
            'language script and region' => ['zh-hans-cn', 'zh_Hans_CN'],
            'already normalized' => ['ru_RU', 'ru_RU'],
        ];
    }

    public function testInvalidLocaleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PageLocaleService())->normalizeLocaleCode('../../en');
    }

    public function testEnglishWinsAsSourceWhenWebsiteSupportsIt(): void
    {
        self::assertSame(
            'en_US',
            (new PageLocaleService())->determineSourceLocale(['zh_Hans_CN', 'en_US'], 'zh_Hans_CN'),
        );
    }

    public function testWebsiteDefaultWinsWhenEnglishIsUnavailable(): void
    {
        self::assertSame(
            'de_DE',
            (new PageLocaleService())->determineSourceLocale(['fr_FR', 'de_DE'], 'de_DE'),
        );
    }

    #[DataProvider('titleFallbackProvider')]
    public function testTitleFallbackOrder(
        string $localizedTitle,
        string $sourceTitle,
        string $legacyTitle,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            (new PageLocaleService())->resolveTitleValue($localizedTitle, $sourceTitle, $legacyTitle),
        );
    }

    public static function titleFallbackProvider(): array
    {
        return [
            'requested locale wins' => [' Локализованный заголовок ', 'Source title', 'Legacy title', 'Локализованный заголовок'],
            'source locale fallback' => ['', ' Source title ', 'Legacy title', 'Source title'],
            'legacy title fallback' => ['', '', ' Legacy title ', 'Legacy title'],
        ];
    }
}
