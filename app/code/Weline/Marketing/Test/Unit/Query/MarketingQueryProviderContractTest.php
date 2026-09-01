<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Extends\Module\Weline_Framework\Query\MarketingAdminQueryProvider;

final class MarketingQueryProviderContractTest extends TestCase
{
    public function testMarketingAdminProviderAclSource(): void
    {
        self::assertSame(
            'Weline_Marketing::commerce:marketing:rules',
            MarketingAdminQueryProvider::ACL_SOURCE,
        );
    }

    public function testMarketingFrontendProviderFileDeclaresOperations(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/MarketingQueryProvider.php';
        $source = (string)file_get_contents($path);

        foreach (['validateCoupon', 'quoteDiscount', 'getCoupon', 'applyCoupon', 'removeCoupon'] as $operation) {
            self::assertStringContainsString("'{$operation}'", $source);
        }
        self::assertStringContainsString("'coupon_code'", $source);
        self::assertStringContainsString("'lines'", $source);
        self::assertStringContainsString("return 'marketing';", $source);
    }
}
