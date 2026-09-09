<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 业务不做礼品心愿单 / 礼品卡：顶栏与 main-nav 默认不得硬链 /registry、/gift-cards。
 */
final class HeaderNavNoGiftLinksContractTest extends TestCase
{
    public function testHeaderDefaultOmitsGiftRegistryAndGiftCardLinks(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("@url{'promotion/deals'}", $src);
        self::assertStringContainsString("@url{'faq'}", $src);
        self::assertStringNotContainsString("@url{'registry'}", $src);
        self::assertStringNotContainsString("@url{'gift-cards'}", $src);
        self::assertStringNotContainsString('礼品心愿单', $src);
        self::assertStringNotContainsString('礼品卡', $src);
    }

    public function testMainNavDefaultShortcutsOmitGiftLinks(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/navigation/main-nav/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("getFrontendUrl('promotion/deals')", $src);
        self::assertStringContainsString("getFrontendUrl('faq')", $src);
        self::assertStringNotContainsString("'/promotion/deals'", $src);
        self::assertStringNotContainsString("'/faq'", $src);
        self::assertStringNotContainsString("'/registry'", $src);
        self::assertStringNotContainsString("'/gift-cards'", $src);
        self::assertStringNotContainsString('礼品心愿单', $src);
        self::assertStringNotContainsString('礼品卡', $src);
    }

    public function testFullHeaderPromotionLinkUsesTheRegisteredStorefrontRoute(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/full-header/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("@url{'promotion'}", $src);
        self::assertStringNotContainsString("@url{'promotions'}", $src);
    }

    public function testSidebarDefaultPromotionsUseKnownActivityRoutes(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/sidebar/sidebar-ads/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("getFrontendUrl('promotion/deals')", $src);
        self::assertStringContainsString("getFrontendUrl('promotion/sale')", $src);
        self::assertStringNotContainsString("'/promotion/deals'", $src);
        self::assertStringNotContainsString("'/promotion/sale'", $src);
        self::assertStringNotContainsString("'/promotions/", $src);
    }

    public function testFullHeaderDropdownUsesHanfuDestinationsWithoutPlaceholderLinks(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/full-header/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("@url{'search'|['q' => '明制汉服']}", $src);
        self::assertStringContainsString("@url{'search'|['q' => '宋制汉服']}", $src);
        self::assertStringContainsString("@url{'promotion/deals'}", $src);
        self::assertStringContainsString("@url{'blog'}", $src);
        self::assertStringContainsString("@url{'categories'}", $src);
        self::assertStringContainsString("@url{'products'|['filter' => 'new']}", $src);
        self::assertStringContainsString("@url{'cart'}", $src);
        self::assertStringContainsString('@param logo_text {default="云裳 Hanfu Atelier"', $src);
        self::assertStringContainsString("__('东方衣冠，全球配送')", $src);
        self::assertStringNotContainsString('href="#"', $src);
        self::assertStringNotContainsString('电子产品', $src);
        self::assertStringNotContainsString('免费配送满', $src);
        self::assertStringNotContainsString("@url{'catalog/category'}", $src);
        self::assertStringNotContainsString("@url{'catalog/products'", $src);
        self::assertStringNotContainsString("@url{'checkout/cart'}", $src);
    }
}
