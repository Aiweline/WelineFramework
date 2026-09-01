<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;

/**
 * 整页脚主部件 footer-container：默认安装、四槽、分组归一化。
 */
final class FooterContainerWidgetContractTest extends TestCase
{
    public function testWidgetDeclaresRequiredFooterInjectionAndSchemas(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/footer/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('@widget.code {footer-container}', $src);
        self::assertStringContainsString('@widget.exclusive {true}', $src);
        self::assertStringContainsString('"slot":"footer"', $src);
        self::assertStringContainsString('"required":true', $src);
        self::assertStringContainsString('type="footer_link_groups"', $src);
        self::assertStringContainsString('type="footer_link_items"', $src);
        self::assertStringContainsString('type="footer_legal_links"', $src);
        self::assertStringContainsString('type="footer_social_items"', $src);
        self::assertStringContainsString('<w:slot id="footer-about-links"', $src);
        self::assertStringContainsString('<w:slot id="footer-partner-links"', $src);
        self::assertStringContainsString('<w:slot id="footer-payment-account-links"', $src);
        self::assertStringContainsString('<w:slot id="footer-help-links"', $src);
        self::assertStringContainsString('footer-section__links', $src);
        self::assertStringContainsString('footer-section__link', $src);
        self::assertStringNotContainsString('<ul class="footer-section__list">', $src);
        self::assertStringNotContainsString('<li>', $src);
    }

    public function testNormalizeSkipsDisabledGroups(): void
    {
        $groups = [
            ['key' => 'about', 'enabled' => true, 'title' => '了解我们'],
            ['key' => 'help', 'enabled' => false, 'title' => '帮助中心'],
        ];
        $items = [
            ['group_key' => 'about', 'label' => '关于我们', 'url' => '/about', 'open_in_new' => false],
            ['group_key' => 'help', 'label' => '帮助', 'url' => '/help', 'open_in_new' => false],
        ];
        $rendered = FooterDefaultLinksHelper::normalizeRenderableGroups($groups, $items);
        self::assertCount(1, $rendered);
        self::assertSame('about', $rendered[0]['key']);
        self::assertNotNull($rendered[0]['slot']);
        self::assertSame('footer-about-links', $rendered[0]['slot']['id'] ?? null);
    }

    public function testCustomGroupHasNoSlot(): void
    {
        $rendered = FooterDefaultLinksHelper::normalizeRenderableGroups(
            [['key' => 'promo', 'enabled' => true, 'title' => '活动专区']],
            [['group_key' => 'promo', 'label' => '大促', 'url' => '/sale', 'open_in_new' => false]]
        );
        self::assertCount(1, $rendered);
        self::assertNull($rendered[0]['slot']);
        self::assertSame('大促', $rendered[0]['items'][0]['label'] ?? null);
    }

    public function testParamSchemasExist(): void
    {
        foreach (['footer_link_groups', 'footer_link_items', 'footer_legal_links', 'footer_social_items'] as $name) {
            $path = dirname(__DIR__, 2) . '/Ui/ParamSchema/' . $name . '.php';
            self::assertFileExists($path);
            $def = include $path;
            self::assertIsArray($def);
            self::assertSame('array', $def['base_type'] ?? null);
            self::assertIsArray($def['item_schema'] ?? null);
        }
    }
}
