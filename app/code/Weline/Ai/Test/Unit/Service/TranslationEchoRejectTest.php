<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\TranslationService;

/**
 * Root-fix guard: cross-language identity echo must be detected at TranslationService.
 */
final class TranslationEchoRejectTest extends TestCase
{
    public function testCrossLanguageIdenticalCopyIsEcho(): void
    {
        self::assertTrue(TranslationService::isUntranslatedEcho(
            '编译后缓存已清理',
            '编译后缓存已清理',
            'zh_Hans_CN',
            'en_US',
        ));
        self::assertTrue(TranslationService::isUntranslatedEcho(
            'Hello',
            'Hello',
            'en_US',
            'ja_JP',
        ));
    }

    public function testSameLanguageTagAllowsIdenticalCopy(): void
    {
        self::assertFalse(TranslationService::isUntranslatedEcho(
            'SKU',
            'SKU',
            'en_US',
            'en_GB',
        ));
        self::assertFalse(TranslationService::isUntranslatedEcho(
            '设置',
            '设置',
            'zh_Hans_CN',
            'zh_Hant_TW',
        ));
    }

    public function testDifferentTextIsNotEcho(): void
    {
        self::assertFalse(TranslationService::isUntranslatedEcho(
            '编译后缓存已清理',
            'Compiled cache cleared',
            'zh_Hans_CN',
            'en_US',
        ));
    }

    public function testLocaleLanguageTag(): void
    {
        self::assertSame('zh', TranslationService::localeLanguageTag('zh_Hans_CN'));
        self::assertSame('en', TranslationService::localeLanguageTag('en-US'));
        self::assertSame('ja', TranslationService::localeLanguageTag('ja_JP'));
        self::assertSame('', TranslationService::localeLanguageTag(''));
    }
}
