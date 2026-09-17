<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Api\Data;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\Data\TaxSnapshot;

final class TaxSnapshotBuyerIdentityTest extends TestCase
{
    public function testBuyerFieldsSurviveRoundTrip(): void
    {
        $snap = new TaxSnapshot(
            taxAmountMinor: 100,
            mode: 'engine',
            note: 'server_calculated_tax',
            ruleSchemaVersion: '1',
            ruleSetHash: str_repeat('a', 64),
            engine: 'tax_engine',
            jurisdictionKey: 'DE|',
            currency: 'EUR',
            scopeKey: 'w0.s0',
            websiteId: 1,
            storeId: 1,
            buyerTaxId: 'DE123456789',
            buyerTaxIdType: 'eu_vat',
            buyerTaxCountry: 'DE',
            buyerCompanyName: 'Acme GmbH',
        );
        $again = TaxSnapshot::fromArray($snap->toArray());
        self::assertSame('DE123456789', $again->buyerTaxId);
        self::assertSame('eu_vat', $again->buyerTaxIdType);
        self::assertSame('DE', $again->buyerTaxCountry);
        self::assertSame('Acme GmbH', $again->buyerCompanyName);
        self::assertSame(100, $again->taxAmountMinor);
    }

    public function testNestedLegacyKeyIsDroppedOnFromArray(): void
    {
        $again = TaxSnapshot::fromArray([
            'tax_amount_minor' => 0,
            'mode' => 'stub_zero',
            'buyer_tax_id' => 'DE123456789',
            'buyer_tax_id_type' => 'eu_vat',
            'buyer_tax_country' => 'DE',
            'tax_identity' => ['tax_id' => 'IGNORED'],
            'buyer_tax_identity' => ['vat_id' => 'IGNORED'],
        ]);
        self::assertSame('DE123456789', $again->buyerTaxId);
        self::assertArrayNotHasKey('tax_identity', $again->toArray());
    }

    public function testWithBuyerIdentityPreservesRules(): void
    {
        $base = new TaxSnapshot(
            taxAmountMinor: 50,
            mode: 'engine',
            ruleSetHash: str_repeat('b', 64),
            engine: 'tax_engine',
        );
        $with = $base->withBuyerIdentity('FRXX123456789', 'eu_vat', 'FR', '');
        self::assertSame(50, $with->taxAmountMinor);
        self::assertSame(str_repeat('b', 64), $with->ruleSetHash);
        self::assertSame('FRXX123456789', $with->buyerTaxId);
        self::assertSame('', $with->buyerCompanyName);
    }

    public function testLineSnapshotDefaultsBuyerEmpty(): void
    {
        $line = new TaxSnapshot(taxAmountMinor: 10, currency: 'EUR');
        self::assertSame('', $line->buyerTaxId);
        self::assertSame('', $line->buyerTaxIdType);
    }
}
