<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Smtp\Service\MailTemplateResolver;
use Weline\Smtp\Service\MailTemplateSeedCopyCatalog;

/**
 * UC-1：非中文缺行优先 en_US，禁止直接回落 zh_Hans_CN。
 */
final class MailTemplateResolverFallbackContractTest extends TestCase
{
    public function testNonChineseChainInsertsEnUsBeforeSiteDefaultZh(): void
    {
        $chain = MailTemplateResolver::localeFallbackChain('de_DE', 'zh_Hans_CN');
        self::assertSame(
            ['de_DE', 'en_US', 'zh_Hans_CN'],
            $chain,
            'de_DE must try en_US before site-default zh'
        );
        $enPos = array_search('en_US', $chain, true);
        $zhPos = array_search('zh_Hans_CN', $chain, true);
        self::assertIsInt($enPos);
        self::assertIsInt($zhPos);
        self::assertLessThan($zhPos, $enPos);
    }

    public function testEnGbChainDoesNotSkipEnUs(): void
    {
        $chain = MailTemplateResolver::localeFallbackChain('en_GB', 'zh_Hans_CN');
        self::assertSame(['en_GB', 'en_US', 'zh_Hans_CN'], $chain);
    }

    public function testEnUsTargetDoesNotDuplicateEnUs(): void
    {
        $chain = MailTemplateResolver::localeFallbackChain('en_US', 'zh_Hans_CN');
        self::assertSame(['en_US', 'zh_Hans_CN'], $chain);
    }

    public function testChineseTargetDoesNotForceEnUs(): void
    {
        $chain = MailTemplateResolver::localeFallbackChain('zh_Hans_CN', 'zh_Hans_CN');
        self::assertSame(['zh_Hans_CN'], $chain);

        $chain2 = MailTemplateResolver::localeFallbackChain('zh_Hant_TW', 'zh_Hans_CN');
        self::assertSame(['zh_Hant_TW', 'zh_Hans_CN'], $chain2);
        self::assertNotContains(MailTemplateSeedCopyCatalog::LOCALE_EN, $chain2);
    }

    public function testSiteDefaultOtherThanZhStillEndsWithZhForNonChinese(): void
    {
        $chain = MailTemplateResolver::localeFallbackChain('it_IT', 'fr_FR');
        self::assertSame(['it_IT', 'en_US', 'fr_FR', 'zh_Hans_CN'], $chain);
    }

    public function testResolveUsesLocaleFallbackChainHelper(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/MailTemplateResolver.php');
        self::assertStringContainsString('localeFallbackChain', $src);
        self::assertStringContainsString('isChineseLocale', $src);
        self::assertDoesNotMatchRegularExpression(
            '/\$locales\s*=\s*\[\s*\$locale\s*\];\s*if\s*\(\s*\$siteDefault/',
            $src,
            'old locale→siteDefault-only chain must be removed'
        );
    }
}
