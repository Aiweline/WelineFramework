<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;

/**
 * 页脚「合作信息」扩展槽迁入 footer-container；默认仅供应商合作硬链。
 */
final class FooterPartnerLinksSlotContractTest extends TestCase
{
    public function testFooterContainerExposesPartnerExtrasSlot(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/footer/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('<w:slot id="footer-partner-links"', $src);
        self::assertStringContainsString(
            'accept="footer-publish-link,footer-promote-link,layout-footer-partner-links"',
            $src
        );
        self::assertStringContainsString("\$groupKey === 'partner'", $src);
    }

    public function testPartialNoLongerHostsPartnerSlot(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/footer/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringNotContainsString('<w:slot id="footer-partner-links"', $src);
    }

    public function testDefaultHelperKeepsSupplierOnly(): void
    {
        $items = FooterDefaultLinksHelper::defaultLinkItems();
        $partner = array_values(array_filter(
            $items,
            static fn (array $row): bool => ($row['group_key'] ?? '') === 'partner'
        ));
        self::assertCount(1, $partner);
        self::assertSame('供应商合作', $partner[0]['label'] ?? null);
        self::assertSame('/inquiry/suppliers', $partner[0]['url'] ?? null);

        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Helper/FooterDefaultLinksHelper.php');
        self::assertStringContainsString('footer-partner-links', $src);
        self::assertStringNotContainsString("['label' => '我要开店'", $src);
        self::assertStringNotContainsString("['label' => '加入联盟'", $src);
        self::assertStringNotContainsString("['label' => '我要推广'", $src);
        self::assertStringNotContainsString("['label' => '自行出版'", $src);
    }
}
