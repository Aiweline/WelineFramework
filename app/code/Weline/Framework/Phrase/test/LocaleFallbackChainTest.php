<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase\test;

use Weline\Framework\Phrase\LocaleFallbackChain;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Test\TestCore;

require_once dirname(__DIR__) . '/LocaleFallbackChain.php';

final class LocaleFallbackChainTest extends TestCore
{
    public function testNonChineseLocaleFallsBackToNeutralEnglishThenWebsiteDefault(): void
    {
        self::assertSame(
            ['ar_SA', 'en_US', 'zh_Hans_CN'],
            LocaleFallbackChain::candidates('ar-SA', 'zh-Hans-CN'),
        );
    }

    public function testExactEnglishAndDuplicateCandidatesAreCollapsed(): void
    {
        self::assertSame(
            ['en_US', 'zh_Hans_CN'],
            LocaleFallbackChain::candidates('en-us', 'zh_Hans_CN'),
        );
        self::assertSame(
            ['ar_SA', 'en_US'],
            LocaleFallbackChain::candidates('ar_SA', 'en-us'),
        );
    }

    public function testChineseTargetKeepsChineseSourceAsItsNeutralFallback(): void
    {
        self::assertSame(
            ['zh_Hans_CN'],
            LocaleFallbackChain::candidates('zh-hans-cn', 'zh_Hans_CN'),
        );
    }

    public function testParserUsesOneFallbackPolicyForEveryDictionaryLayerAndCacheVersion(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/Parser.php');

        self::assertStringContainsString('loadModuleWordsForLocaleChain', $source);
        self::assertStringContainsString('loadLocaleWordsForLocaleChain', $source);
        self::assertStringContainsString('LocaleFallbackChain::candidates', $source);
        self::assertStringContainsString('websiteDefaultLocale', $source);
    }

    public function testParserLoadsMaintainedEnglishModuleCopyForArabicFallback(): void
    {
        $method = new \ReflectionMethod(Parser::class, 'loadModuleWordsForLocaleChain');
        $words = $method->invoke(null, 'Weline_Theme', 'ar_SA');

        self::assertSame('About Us', $words['关于我们'] ?? null);
        self::assertSame('Contact us', $words['联系我们'] ?? null);
        self::assertSame('Contact Us Page Layout', $words['联系我们页面布局'] ?? null);
    }
}
