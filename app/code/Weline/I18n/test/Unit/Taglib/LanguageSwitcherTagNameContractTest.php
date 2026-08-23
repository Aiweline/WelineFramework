<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Taglib\LanguageSelect;
use Weline\I18n\Taglib\LanguageSwitcher;

final class LanguageSwitcherTagNameContractTest extends TestCase
{
    public function testCanonicalTagNameIsI18nSwitcher(): void
    {
        self::assertSame('i18n:switcher', LanguageSwitcher::name());
        self::assertSame('i18n:switcher', LanguageSwitcher::TAG_NAME);
    }

    public function testLegacyAliasIsRemoved(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/Taglib/LanguageSwitcherLegacyAlias.php');
        self::assertFalse(defined(LanguageSwitcher::class . '::LEGACY_TAG_NAME'));
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
