<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Design theme app/design/Weline/hanfu holds merchant Hanfu overrides.
 */
final class HanfuDesignThemeOverrideContractTest extends TestCase
{
    private function designRoot(): string
    {
        return dirname(__DIR__, 5) . '/design/Weline/hanfu';
    }

    public function testRegisterDeclaresParentDefaultTheme(): void
    {
        $register = (string)file_get_contents($this->designRoot() . '/register.php');
        self::assertStringContainsString("'name' => 'hanfu'", $register);
        self::assertStringContainsString("'parent' => 'Default 默认主题'", $register);
        self::assertStringContainsString("'path' => __DIR__", $register);
    }

    public function testNavDefaultsCarryHanfuCatalogTree(): void
    {
        $nav = (string)file_get_contents(
            $this->designRoot() . '/frontend/partials/header/nav-defaults.phtml'
        );
        self::assertStringContainsString('全部衣裳', $nav);
        self::assertStringContainsString('衣冠札记', $nav);
        self::assertStringContainsString('return [', $nav);
    }

    public function testAboutOverrideKeepsBrandStory(): void
    {
        $about = (string)file_get_contents(
            $this->designRoot() . '/frontend/layouts/about/default.phtml'
        );
        self::assertStringContainsString('为什么选择长安', $about);
    }

    public function testHomepageMetaKeepsHanfuStorefrontLabel(): void
    {
        $home = (string)file_get_contents(
            $this->designRoot() . '/frontend/layouts/homepage/default.phtml'
        );
        self::assertStringContainsString('水墨汉服商城首页', $home);
    }

    public function testHeaderKeepsLanguageCurrencyAndAccountHooks(): void
    {
        $header = (string)file_get_contents(
            $this->designRoot() . '/frontend/partials/header/default.phtml'
        );
        self::assertStringContainsString('header-language-switcher', $header);
        self::assertStringContainsString('<w:i18n:switcher navigation="path" />', $header);
        self::assertStringContainsString('header-currency-switcher', $header);
        self::assertStringContainsString('header-wishlist-icon', $header);
        self::assertStringContainsString('name="account"', $header);
        self::assertStringContainsString('name="mini-cart-icon"', $header);
        self::assertStringContainsString('HeaderDefaultNavItems', $header);
        self::assertStringContainsString('class="mobile-menu-icon"', $header);
        self::assertStringNotContainsString('<w:icon name="menu"', $header);
        self::assertStringNotContainsString('name="all-menu"', $header);
        self::assertStringContainsString('id="delivery"', $header);
        self::assertStringContainsString('id="header-nav-extensions"', $header);
    }

    public function testFullHeaderWidgetKeepsLanguageCurrencySlots(): void
    {
        $header = (string)file_get_contents(
            $this->designRoot() . '/frontend/widgets/header/full-header/default.phtml'
        );
        self::assertStringContainsString('id="language"', $header);
        self::assertStringContainsString('id="currency"', $header);
        self::assertStringContainsString('<w:i18n:switcher navigation="path" />', $header);
        self::assertStringContainsString('header-currency-switcher', $header);
        self::assertStringContainsString('id="delivery"', $header);
        self::assertStringContainsString('id="header-nav-extensions"', $header);
    }

    public function testThemeShellNavDefaultsAreGeneric(): void
    {
        $shell = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/nav-defaults.phtml'
        );
        self::assertStringContainsString('HeaderDefaultNavItems::genericDefaults', $shell);
        self::assertStringNotContainsString('女士汉服', $shell);
        self::assertStringNotContainsString('长安', $shell);
    }
}
