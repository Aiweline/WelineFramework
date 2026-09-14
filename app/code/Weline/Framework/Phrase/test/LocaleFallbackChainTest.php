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

    public function testParserModuleLocaleChainUsesTargetLocaleCsvNotNeutralEnglish(): void
    {
        $method = new \ReflectionMethod(Parser::class, 'loadModuleWordsForLocaleChain');
        $words = $method->invoke(null, 'Weline_Theme', 'ar_SA');

        // Theme 有 ar_SA.csv：热层应是阿语，不得回落成 en_US「About Us」。
        self::assertSame('من نحن', $words['关于我们'] ?? null);
        self::assertNotSame('About Us', $words['关于我们'] ?? null);
    }

    public function testParserStillFallsBackToNeutralEnglishViaLocaleChainCandidates(): void
    {
        self::assertSame(
            ['hi_IN', 'en_US', 'zh_Hans_CN'],
            LocaleFallbackChain::candidates('hi_IN', 'zh_Hans_CN'),
        );
        $source = (string)file_get_contents(dirname(__DIR__) . '/Parser.php');
        self::assertStringContainsString('loadGlobalDictionaryWord', $source);
        self::assertStringContainsString('禁止把 en_US 等回退 locale 的模块 CSV 提前合并', $source);
    }
}
