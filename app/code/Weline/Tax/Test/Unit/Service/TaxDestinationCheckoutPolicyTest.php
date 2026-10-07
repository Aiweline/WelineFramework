<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Tax\Service\CheckoutTaxAdvisor;
use Weline\Tax\Service\TaxDestinationCheckoutPolicy;

final class TaxDestinationCheckoutPolicyTest extends TestCase
{
    public function testDomesticInclusive(): void
    {
        $p = (new TaxDestinationCheckoutPolicy())->resolve('CN', 'CN', true);
        self::assertFalse($p['charge_sales_tax']);
        self::assertFalse($p['charge_customs_duty']);
        self::assertFalse($p['charge_import_vat']);
        self::assertSame(TaxDestinationCheckoutPolicy::PROFILE_DOMESTIC_INCLUSIVE, $p['profile']);
    }

    public function testEuImportAtBorder(): void
    {
        $p = (new TaxDestinationCheckoutPolicy())->resolve('DE', 'CN', true);
        self::assertFalse($p['charge_sales_tax']);
        self::assertTrue($p['charge_customs_duty']);
        self::assertTrue($p['charge_import_vat']);
        self::assertSame(TaxDestinationCheckoutPolicy::PROFILE_IMPORT_AT_BORDER, $p['profile']);
    }

    public function testUsDutyOnlyByDefault(): void
    {
        $p = (new TaxDestinationCheckoutPolicy())->resolve('US', 'CN', true);
        self::assertFalse($p['charge_sales_tax']);
        self::assertTrue($p['charge_customs_duty']);
        self::assertFalse($p['charge_import_vat']);
    }

    public function testCollectAllowlistEnablesSalesTax(): void
    {
        $p = (new TaxDestinationCheckoutPolicy())->resolve('US', 'CN', true, 'US,AU');
        self::assertTrue($p['charge_sales_tax']);
        self::assertTrue($p['charge_customs_duty']);
        self::assertFalse($p['charge_import_vat']);
        self::assertSame(TaxDestinationCheckoutPolicy::PROFILE_COLLECT_DEST_TAX, $p['profile']);
    }
}
