<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\HeaderCommerceData;

final class HeaderCommerceDataHanfuDefaultsTest extends TestCase
{
    public function testFallbackHotWordsDescribeTheDefaultHanfuStorefront(): void
    {
        self::assertSame(
            ['马面裙', '明制汉服', '宋制汉服', '齐胸襦裙', '汉服配饰'],
            HeaderCommerceData::defaultHotWords(),
        );
    }

    public function testDefaultHeaderKeepsEditorSlotsAndUsesInkPaletteTokens(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml',
        );

        self::assertIsString($template);
        self::assertStringContainsString('@param.logoText {default="云裳 Hanfu Atelier"', $template);
        self::assertStringContainsString("\$defaultLogoUrl = '';", $template);
        self::assertStringContainsString('logo-wordmark__cn', $template);
        self::assertStringContainsString("'primary' => 'var(--color-primary)'", $template);
        self::assertStringContainsString("'bgDark' => 'var(--weline-chrome-bg-dark)'", $template);
        self::assertStringContainsString('搜索汉服、形制与配饰...', $template);
        self::assertStringContainsString('<w:slot id="logo"', $template);
        self::assertStringContainsString('<w:slot id="search"', $template);
        self::assertStringContainsString('<w:slot id="top-bar"', $template);
        self::assertStringContainsString('<w:slot id="top-bar-rights"', $template);
    }

    public function testEditorFallbackNavigationUsesHanfuInformationArchitecture(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml',
        );

        self::assertStringContainsString("'text' => '女士汉服'", $template);
        self::assertStringContainsString("'text' => '男士汉服'", $template);
        self::assertStringContainsString("'text' => '儿童汉服'", $template);
        self::assertStringContainsString("'text' => '汉服配饰'", $template);
        self::assertStringContainsString("'text' => '按场景选购'", $template);
        self::assertStringContainsString("'/search?q=' . rawurlencode(\$query)", $template);
        self::assertStringNotContainsString("'电子产品'", $template);
        self::assertStringNotContainsString("'家居用品'", $template);
        self::assertStringContainsString('HeaderCommerceData::resolveCategoryNavItems()', $template);
        self::assertStringContainsString('elseif ($editorPreviewLight)', $template);
    }

}
