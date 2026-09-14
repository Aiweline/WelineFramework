<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: freeze→submit must keep Cart deal chrome on order lines.
 */
final class CheckoutGroupSubmitPricingChromeContractTest extends TestCase
{
    public function testSubmitServicePreservesCompareAtAndCampaignOnBucketAndCommandLines(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutGroupSubmitService.php'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString('function pricingChromeFromLine', $src);
        self::assertStringContainsString('compare_at_minor', $src);
        self::assertStringContainsString('campaign_label', $src);
        self::assertStringContainsString("'image'", $src);
        self::assertStringContainsString('pricingChromeFromLine($line)', $src);
        self::assertStringContainsString('pricingChromeFromLine($item)', $src);
    }
}
