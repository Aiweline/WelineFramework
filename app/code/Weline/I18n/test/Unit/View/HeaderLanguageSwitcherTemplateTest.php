<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderLanguageSwitcherTemplateTest extends TestCase
{
    public function testLanguageSwitcherUsesWebsiteDisplayNameAndLocaleSelfLanguageName(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-language-switcher.phtml';

        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('$websiteDisplayLocale', $content);
        self::assertStringContainsString('getLocaleName($langCode, $websiteDisplayLocale)', $content);
        self::assertStringContainsString('getLocaleLanguageSelfName($langCode)', $content);
        self::assertStringContainsString('<span class="weline-choice-name"><?= $escape($langName) ?></span>', $content);
        self::assertStringContainsString('<span class="weline-choice-meta"><?= $escape($langCode) ?></span>', $content);
        self::assertStringContainsString('data-native="<?= $escape($langNative) ?>"', $content);
        self::assertStringContainsString('$langNative !== \'\' && $langNative !== $langName', $content);
        self::assertStringContainsString('<span class="weline-choice-native" aria-hidden="true"><?= $escape($langNative) ?></span>', $content);
    }

    public function testLanguageSwitcherDelegatesFlagResolutionToI18nModel(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-language-switcher.phtml';

        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('getCountryFlagWithLocal($localeCode, 24, 18)', $content);
        self::assertStringNotContainsString('$countryCodeFromLocale', $content);
        self::assertStringNotContainsString('$i18n->getCountryFlag($countryCode', $content);
        self::assertStringContainsString('<span class="weline-choice-flag-fallback"><?= $escape($lang[\'short\'] ?? \'\') ?></span>', $content);
    }

    public function testLanguageSwitcherFiltersByWebsiteAndAllowsCustomLocales(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-language-switcher.phtml';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('getWebsiteLanguageCodes', $content);
        self::assertStringContainsString("'allowed_locales'", $content);
        self::assertStringContainsString('RequestContext::getWelineWebsiteId()', $content);
        self::assertStringContainsString('$filterLanguagesByCodes', $content);
    }

    public function testLanguageSwitcherEmitsFrameworkPathHrefsNotQueryParams(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-language-switcher.phtml';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('LocalizedUrlBuilderInterface', $content);
        self::assertStringContainsString('$buildLanguageHref', $content);
        self::assertStringContainsString('href="<?= $escape($langHref) ?>"', $content);
        self::assertStringContainsString('data-pb-i18n-path-href="2"', $content);
        self::assertStringNotContainsString('<a href="#"', $content);
    }

    public function testLanguageSwitcherChromeLabelsBypassExclusivePhraseViaWidgetI18n(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-language-switcher.phtml';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('WidgetI18n::label', $content);
        self::assertStringContainsString("\$i18nChrome('语言')", $content);
        self::assertStringContainsString("\$i18nChrome('切换语言')", $content);
        self::assertStringNotContainsString("__('语言')", $content);
        self::assertStringNotContainsString("__('切换语言')", $content);
    }
}
