<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Taglib\LanguageSelect;
use Weline\I18n\Taglib\LanguageSwitcher;
use Weline\I18n\Taglib\LanguageSwitcherLegacyAlias;

final class LanguageSwitcherTagNameContractTest extends TestCase
{
    public function testCanonicalTagNameIsI18nSwitcher(): void
    {
        self::assertSame('i18n:switcher', LanguageSwitcher::name());
        self::assertSame('i18n:switcher', LanguageSwitcher::TAG_NAME);
    }

    public function testLegacyAliasMapsToSameCallback(): void
    {
        self::assertSame('i18n:language:switcher', LanguageSwitcherLegacyAlias::name());
        self::assertSame(LanguageSwitcher::LEGACY_TAG_NAME, LanguageSwitcherLegacyAlias::name());
        self::assertSame(
            LanguageSwitcher::attr(),
            LanguageSwitcherLegacyAlias::attr(),
        );
        self::assertTrue(LanguageSwitcherLegacyAlias::tag_self_close());
        self::assertTrue(LanguageSwitcherLegacyAlias::tag_self_close_with_attrs());
    }
}

final class LanguageSelectDisplayNameContractTest extends TestCase
{
    public function testBuildDisplayNameDoesNotHardConcatEnglishAndSelfWhenSameLabel(): void
    {
        $name = LanguageSelect::buildDisplayName(
            '中文（简体，中国）',
            'Chinese (Simplified, China)',
            '中文（简体，中国）',
            'zh_Hans_CN',
        );
        self::assertSame('中文（简体，中国）', $name);
        self::assertStringNotContainsString('Chinese (Simplified, China)(', $name);
    }

    public function testBuildTagLabelPrefersShortLocalizedLabel(): void
    {
        $label = LanguageSelect::buildTagLabel(
            '中文（简体，中国）',
            '中文（简体，中国）',
            'Chinese (Simplified, China)',
            'zh_Hans_CN',
        );
        self::assertSame('中文（简体，中国）', $label);
    }
}
