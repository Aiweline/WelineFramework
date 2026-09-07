<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Helper;

use Weline\Framework\Test\TestCore;
use Weline\Frontend\Helper\FrontendRuntimeI18nDictionary;

final class FrontendRuntimeI18nDictionaryTest extends TestCore
{
    public function testFilterDropsMultilineAndOversizedDocsButKeepsShortUiPhrases(): void
    {
        $long = str_repeat('x', FrontendRuntimeI18nDictionary::MAX_KEY_CHARS + 1);
        $input = [
            '关闭' => '关闭',
            "当 WLS Dispatcher 检测到攻击并发送信号时触发。\nCDN 模块收到此事件后" => "当 WLS Dispatcher 检测到攻击并发送信号时触发。\nCDN 模块收到此事件后",
            $long => $long,
            '请重试' => 'Please retry',
        ];

        $filtered = FrontendRuntimeI18nDictionary::filterForClient($input);

        self::assertSame([
            '关闭' => '关闭',
            '请重试' => 'Please retry',
        ], $filtered);
        self::assertFalse(FrontendRuntimeI18nDictionary::isClientSafePhrase("a\nb"));
        self::assertTrue(FrontendRuntimeI18nDictionary::isClientSafePhrase('关闭'));
    }

    public function testHeaderWiresClientDictionaryFilter(): void
    {
        $path = dirname(__DIR__, 3) . '/view/blocks/header/base.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('FrontendRuntimeI18nDictionary::filterForClient', $src);
    }
}
