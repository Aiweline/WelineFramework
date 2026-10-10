<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\I18n\Taglib\LanguageSwitcher;

final class LanguageSwitcherChromeI18nTest extends TestCase
{
    protected function setUp(): void
    {
        LanguageSwitcher::clearProcessCaches();
    }

    public function testChromeCopyUsesI18nModuleCsvForEnglishDisplayLocale(): void
    {
        $translate = new ReflectionMethod(LanguageSwitcher::class, 'translateChrome');
        $translate->setAccessible(true);

        self::assertSame(
            'Search country, language, or code...',
            $translate->invoke(null, '搜索国家、语言或代码...', 'en_US'),
        );
        self::assertSame(
            'Apply to support additional languages',
            $translate->invoke(null, '申请支持其他语言', 'en_US'),
        );
        self::assertSame(
            'No matching languages',
            $translate->invoke(null, '没有匹配的语言', 'en_US'),
        );
        self::assertSame(
            'Switch Language',
            $translate->invoke(null, '切换语言', 'en_US'),
        );
    }

    public function testChromeCopyKeepsChineseForZhDisplayLocale(): void
    {
        $translate = new ReflectionMethod(LanguageSwitcher::class, 'translateChrome');
        $translate->setAccessible(true);

        self::assertSame(
            '搜索国家、语言或代码...',
            $translate->invoke(null, '搜索国家、语言或代码...', 'zh_Hans_CN'),
        );
        self::assertSame(
            '申请支持其他语言',
            $translate->invoke(null, '申请支持其他语言', 'zh_Hans_CN'),
        );
    }

    public function testTranslateChromeDoesNotSingleWordPrefetch(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(LanguageSwitcher::class))->getFileName()
        );
        self::assertStringNotContainsString('Parser::prefetchWords([$source]', $source);
        self::assertStringContainsString('Parser::prefetchWords($chromeSources', $source);
    }
}
