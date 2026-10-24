<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\HeaderCommerceData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeDesignDataResolver;

final class HeaderCommerceDataThemeDefaultsTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::removeInstance(ThemeDesignDataResolver::class);
        parent::tearDown();
    }

    public function testHotWordsComeFromTheBoundThemeDataPack(): void
    {
        $this->bindPack(['hot_words' => ['主题热词一', ' 主题热词二 ', '']]);

        self::assertSame(['主题热词一', '主题热词二'], HeaderCommerceData::defaultHotWords());
    }

    public function testThemeWithoutPackFallsBackToNeutralWords(): void
    {
        $this->bindPack([]);

        self::assertSame(HeaderCommerceData::neutralDefaultHotWords(), HeaderCommerceData::defaultHotWords());
        self::assertNotEmpty(HeaderCommerceData::neutralDefaultHotWords());
    }

    public function testHelperCarriesNoSiteOrThemeSpecificCopy(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Helper/HeaderCommerceData.php');

        foreach (['hanfu', 'daocharms', 'grocery', '马面裙', '明制汉服', '黑曜石', '平安扣'] as $needle) {
            self::assertStringNotContainsStringIgnoringCase($needle, $source, $needle);
        }
    }

    public function testDefaultHeaderKeepsEditorSlotsAndUsesInkPaletteTokens(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml',
        );

        self::assertIsString($template);
        self::assertStringContainsString('@param.logoText {default=""', $template);
        self::assertStringContainsString("\$defaultLogoUrl = '';", $template);
        self::assertStringContainsString('logo-wordmark__cn', $template);
        self::assertStringContainsString("'primary' => 'var(--color-primary)'", $template);
        self::assertStringContainsString("'bgDark' => 'var(--weline-chrome-bg-dark)'", $template);
        self::assertStringContainsString('搜索商品、分类与关键词...', $template);
        self::assertStringContainsString('HeaderDefaultNavItems', $template);
        self::assertStringContainsString('<w:slot id="logo"', $template);
        self::assertStringContainsString('<w:slot id="search"', $template);
        self::assertStringContainsString('<w:slot id="top-bar"', $template);
        self::assertStringContainsString('<w:slot id="top-bar-rights"', $template);
    }

    /**
     * @param array<string,mixed> $pack
     */
    private function bindPack(array $pack): void
    {
        $resolver = new class($pack) extends ThemeDesignDataResolver {
            /** @param array<string,mixed> $pack */
            public function __construct(private readonly array $pack)
            {
            }

            public function load(string $key, ?WelineTheme $theme = null): array
            {
                return $key === 'storefront-defaults' ? $this->pack : [];
            }
        };
        ObjectManager::setInstance(ThemeDesignDataResolver::class, $resolver);
    }
}
