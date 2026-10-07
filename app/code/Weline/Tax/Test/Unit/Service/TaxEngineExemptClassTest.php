<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Tax\Service\TaxEngine;

final class TaxEngineExemptClassTest extends TestCase
{
    public function testExemptLineIsZeroEvenWithoutJurisdictionRule(): void
    {
        $engine = TaxEngine::forTesting();
        // JP| has no seeded exempt rule in forTesting — short-circuit must still zero.
        $result = $engine->calculate([
            'website_id' => 0,
            'store_id' => 0,
            'currency' => 'USD',
            'jurisdiction_key' => 'JP|',
            'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
            'lines' => [
                [
                    'line_id' => 'e1',
                    'tax_class_code' => TaxEngine::CLASS_EXEMPT,
                    'taxable_amount_minor' => 10000,
                ],
            ],
        ]);
        self::assertSame(0, $result['tax_amount_minor']);
        self::assertSame(0, $result['lines'][0]['tax_amount_minor']);
        self::assertSame(0, $result['lines'][0]['rate_bps']);
        self::assertSame(TaxEngine::CLASS_EXEMPT, $result['lines'][0]['tax_class_code']);
    }

    public function testMixedCartOnlyChargesStandardLine(): void
    {
        $engine = TaxEngine::forTesting();
        $result = $engine->calculate([
            'website_id' => 0,
            'store_id' => 0,
            'currency' => 'USD',
            'jurisdiction_key' => 'US|CA',
            'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
            'lines' => [
                [
                    'line_id' => 's1',
                    'tax_class_code' => 'standard',
                    'taxable_amount_minor' => 10000,
                ],
                [
                    'line_id' => 'e1',
                    'tax_class_code' => TaxEngine::CLASS_EXEMPT,
                    'taxable_amount_minor' => 10000,
                ],
            ],
        ]);
        // US|CA standard 7.25% of 10000 = 725
        self::assertSame(725, $result['tax_amount_minor']);
        $byId = [];
        foreach ($result['lines'] as $line) {
            $byId[$line['line_id']] = $line;
        }
        self::assertSame(725, $byId['s1']['tax_amount_minor']);
        self::assertSame(0, $byId['e1']['tax_amount_minor']);
    }
}
