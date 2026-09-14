<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/** Source contract: hang paymentContext exposes order_summary for balance/deposit panels. */
final class B2BHangPaymentOrderSummaryContractTest extends TestCase
{
    public function testPaymentContextBuildsOrderSummaryFromOrderFacade(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/B2BHangPaymentService.php',
        );
        self::assertStringContainsString("'order_summary'", $src);
        self::assertStringContainsString('buildOrderSummary', $src);
        self::assertStringContainsString('emptyOrderSummary', $src);
        self::assertStringContainsString("'can_pay'", $src);
        self::assertStringContainsString("'view_state'", $src);
        self::assertStringContainsString("'completed'", $src);
        self::assertStringContainsString('本单尾款已结清，无需再支付', $src);
        self::assertStringContainsString('挂单已完成', $src);
        self::assertStringContainsString('requireHangOwnedByCustomer', $src);
        self::assertStringContainsString('isHangPurposePayable', $src);
        self::assertStringContainsString('hang_status_label', $src);
        self::assertStringContainsString('goods_subtotal_minor', $src);
        self::assertStringContainsString('payable_minor', $src);
        self::assertStringContainsString('image_url', $src);
        self::assertStringContainsString('resolveProductImageUrls', $src);
        self::assertStringContainsString('presentStorefrontImageUrl', $src);
        self::assertStringContainsString('StorefrontProductMediaUrlResolver', $src);
        self::assertStringContainsString('MediaRepository', $src);
        self::assertStringContainsString("\$read->items", $src);
        self::assertStringContainsString('displayNumber', $src);
    }
}
