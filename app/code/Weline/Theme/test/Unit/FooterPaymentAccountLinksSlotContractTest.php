<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;

/**
 * 页脚「支付与账户」扩展槽迁入 footer-container；列内默认硬链为空。
 */
final class FooterPaymentAccountLinksSlotContractTest extends TestCase
{
    public function testFooterContainerExposesPaymentAccountExtrasSlot(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/footer/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('<w:slot id="footer-payment-account-links"', $src);
        self::assertStringContainsString(
            'accept="footer-payment-methods-link,footer-social-login-link,footer-currency-rates-link,footer-campaign-link,layout-footer-payment-account-links"',
            $src
        );
        self::assertStringContainsString("\$groupKey === 'payment'", $src);
    }

    public function testPartialNoLongerHostsPaymentSlot(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/footer/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringNotContainsString('<w:slot id="footer-payment-account-links"', $src);
    }

    public function testDefaultHelperKeepsPaymentAccountEmpty(): void
    {
        foreach (FooterDefaultLinksHelper::defaultLinkItems() as $item) {
            self::assertNotSame('payment', $item['group_key'] ?? null);
        }
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Helper/FooterDefaultLinksHelper.php');
        self::assertStringContainsString('footer-payment-account-links', $src);
        self::assertStringContainsString("'title' => '支付与账户'", $src);
        self::assertStringNotContainsString("['label' => '支付方式'", $src);
        self::assertStringNotContainsString("['label' => '账户充值'", $src);
        self::assertStringNotContainsString("'url' => '/guide/payment'", $src);
    }
}
