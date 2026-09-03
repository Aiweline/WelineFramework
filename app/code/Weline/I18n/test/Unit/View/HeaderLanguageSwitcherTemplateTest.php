<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderLanguageSwitcherTemplateTest extends TestCase
{
    public function testHeaderHookDelegatesToStandardSwitcher(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-language-switcher.phtml';

        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('LanguageSwitcher::render', $content);
        self::assertStringNotContainsString('getLocalesWithFlagsDisplaySelf', $content);
        self::assertStringNotContainsString('LanguageSupportRequestService', $content);
        self::assertStringNotContainsString('i18n_language_requests', $content);
        self::assertStringNotContainsString('weline-choice-name', $content);
    }

    public function testDynamicSwitcherHooksAreNotCachedAsReusableHtml(): void
    {
        $policies = (new \Weline\I18n\Api\View\TemplateCachePolicyProvider())->policies();
        $aggregateHooks = (array)($policies['aggregate_hooks'] ?? []);
        $outputFiles = (array)($policies['output_files'] ?? []);

        self::assertArrayNotHasKey('header-language-switcher', $aggregateHooks);
        self::assertArrayNotHasKey('header-currency-switcher', $aggregateHooks);
        self::assertArrayNotHasKey('Weline_I18n::hooks/header-language-switcher.phtml', $outputFiles);
        self::assertArrayNotHasKey('Weline_I18n::hooks/header-currency-switcher.phtml', $outputFiles);
    }

    public function testLanguageSwitcherDoesNotDependOnResettableRenderSequence(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/LanguageSwitcher.php';
        $content = (string) file_get_contents($path);
        $first = \Weline\I18n\Helper\SwitcherInstanceId::create('weline-i18n-switcher');
        $second = \Weline\I18n\Helper\SwitcherInstanceId::create('weline-i18n-switcher');

        self::assertStringContainsString('SwitcherInstanceId::create(', $content);
        self::assertStringNotContainsString('$switcherRenderSeq', $content);
        self::assertStringNotContainsString('$renderSeq', $content);
        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^weline-i18n-switcher-[0-9a-f]{24}$/D', $first);
        self::assertMatchesRegularExpression('/^weline-i18n-switcher-[0-9a-f]{24}$/D', $second);
    }
}
