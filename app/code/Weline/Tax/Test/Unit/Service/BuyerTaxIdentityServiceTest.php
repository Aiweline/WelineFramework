<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Tax\Service\BuyerTaxIdentityService;

final class BuyerTaxIdentityServiceTest extends TestCase
{
    public function testNonEuHidden(): void
    {
        $svc = new BuyerTaxIdentityService();
        $schema = $svc->schemaForAddress(['country_code' => 'CN']);
        self::assertSame('hidden', $schema['visibility']);
        self::assertFalse($schema['visible']);
    }

    public function testEuOptional(): void
    {
        $svc = new BuyerTaxIdentityService();
        $schema = $svc->schemaForAddress(['country_code' => 'DE']);
        self::assertSame('optional', $schema['visibility']);
        self::assertTrue($schema['visible']);
        self::assertSame('eu_vat', $schema['tax_id_type']);
    }

    public function testNormalizeAcceptsLegacyVatId(): void
    {
        $svc = new BuyerTaxIdentityService();
        $n = $svc->normalize(['vat_id' => 'de 123456789']);
        self::assertSame('DE123456789', $n['tax_id']);
        self::assertSame('eu_vat', $n['tax_id_type']);
    }

    public function testMergeWritesFormalBuyerKeys(): void
    {
        $svc = new BuyerTaxIdentityService();
        $tax = $svc->mergeIntoTaxSnapshot(
            ['tax_amount_minor' => 0, 'mode' => 'stub_zero', 'engine' => 'none'],
            ['tax_id' => 'DE123456789', 'country_code' => 'DE'],
        );
        self::assertSame('DE123456789', $tax['buyer_tax_id']);
        self::assertSame('eu_vat', $tax['buyer_tax_id_type']);
        self::assertSame('DE', $tax['buyer_tax_country']);
        self::assertSame(0, $tax['tax_amount_minor']);
    }

    public function testInvalidVatProducesError(): void
    {
        $svc = new BuyerTaxIdentityService();
        $desc = $svc->describeForAddress(['country_code' => 'DE'], ['tax_id' => 'BAD']);
        self::assertContains('buyer_tax_vat_invalid', $desc['errors']);
    }
}
