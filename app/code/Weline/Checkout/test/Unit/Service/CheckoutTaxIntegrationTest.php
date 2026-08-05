<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutGroupSubmitService;
use Weline\Checkout\Service\CheckoutV2ConflictException;
use Weline\Order\Service\OrderFacade;
use Weline\Order\Service\OrderCompatibilityReader;
use Weline\Shipping\Service\ScopedShippingQuoteService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Tax\Api\TaxConflictException;
use Weline\Tax\Service\CheckoutTaxAdvisor;
use Weline\Tax\Service\TaxEngine;
use Weline\Tax\Service\TaxLkgStore;

/**
 * TEST-P3B-02, TEST-P3B-03 and TEST-P3B-04（TASK-P3B-002）.
 */
final class CheckoutTaxIntegrationTest extends TestCase
{
    private function rates(): array
    {
        return [
            'std' => ['amount_minor' => 1500, 'label' => 'Standard', 'currencies' => ['CNY']],
        ];
    }

    public function testP3b02EngineDownWithoutLkgBlocksCheckout(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive();
        $advisor->engine()->setDown(true);
        $svc = CheckoutGroupSubmitService::forTesting(
            ScopedShippingQuoteService::forTesting($this->rates()),
            taxAdvisor: $advisor,
        );
        try {
            $svc->freezeAndQuote(
                lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 1000, 'requires_shipping' => true]],
                address: ['country' => 'CN'],
                scope: ['website_id' => 0, 'store_id' => 0],
                serviceCode: 'std',
                currency: 'CNY',
            );
            self::fail('must block');
        } catch (CheckoutV2ConflictException $e) {
            self::assertSame(CheckoutGroupSubmitService::ERROR_TAX, $e->errorCode());
        }
    }

    public function testP3b03LegacyNoneSnapshotNeverRecalculated(): void
    {
        $reader = OrderCompatibilityReader::forTesting();
        $reader->seedLegacy('P2-NONE-ORDER', [
            'order_number' => 'P2-NONE-ORDER',
            'currency' => 'CNY',
            'website_id' => 0,
            'store_id' => 0,
            'subtotal' => '10.00',
            'shipping_amount' => '0.00',
            'tax_amount' => '0.00',
            'total_amount' => '10.00',
        ]);

        // Enable tax advisor for new quotes; reading legacy snapshot must stay frozen.
        $advisor = CheckoutTaxAdvisor::forTestingActive();
        self::assertTrue($advisor->isEffectivelyOn(0));
        $frozen = $reader->readUnified('P2-NONE-ORDER');
        self::assertSame(OrderCompatibilityReader::SOURCE_LEGACY, $frozen['source']);
        self::assertSame('none', $frozen['order']['tax']['engine']);
        self::assertSame('legacy_frozen', $frozen['order']['tax']['mode']);
        self::assertSame(0, $frozen['order']['tax']['tax_amount_minor']);
        self::assertSame($frozen, $reader->readUnified('P2-NONE-ORDER'));
    }

    public function testP3b04RuleVersionChangeForcesRequote(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive();
        $svc = CheckoutGroupSubmitService::forTesting(
            ScopedShippingQuoteService::forTesting($this->rates()),
            taxAdvisor: $advisor,
        );
        $frozen = $svc->freezeAndQuote(
            lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 1000, 'requires_shipping' => true, 'tax_class_code' => 'standard']],
            address: ['country' => 'CN'],
            scope: ['website_id' => 0, 'store_id' => 0],
            serviceCode: 'std',
            currency: 'CNY',
        );
        self::assertSame('engine', $frozen['tax']['mode']);
        self::assertGreaterThan(0, $frozen['tax']['tax_amount_minor']);
        $hash = (string) $frozen['tax']['rule_set_hash'];

        // A fresh worker has no previous calculate() state. It must rebuild the
        // exact request facts and still accept the frozen version.
        $freshWorkerAdvisor = new CheckoutTaxAdvisor(
            TaxEngine::forTesting(),
            $advisor->rollout(),
            TaxLkgStore::forTesting(),
        );
        $freshWorkerAdvisor->assertRuleVersion(
            $frozen['tax'],
            $frozen['orders'],
            $frozen['scope'],
            $frozen['address'],
            $frozen['currency'],
            $hash,
        );

        // Bump live rule set after quote.
        $advisor->engine()->seedRule(0, 'standard', 'CN|', 1500, 2);

        try {
            $svc->submit($frozen['quote_token'], 'idem-tax-v', expectedTaxRuleSetHash: $hash);
            self::fail('must requote');
        } catch (CheckoutV2ConflictException $e) {
            self::assertSame(CheckoutTaxAdvisor::ERROR_RULE_VERSION, $e->errorCode());
        }
    }

    public function testModeOffKeepsStubZeroCompat(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingStub();
        $svc = CheckoutGroupSubmitService::forTesting(
            ScopedShippingQuoteService::forTesting($this->rates()),
            taxAdvisor: $advisor,
        );
        $frozen = $svc->freezeAndQuote(
            lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 1000, 'requires_shipping' => true]],
            address: ['country' => 'CN'],
            scope: ['website_id' => 0, 'store_id' => 0],
            serviceCode: 'std',
            currency: 'CNY',
        );
        self::assertSame('none', $frozen['tax']['mode']);
        self::assertSame(0, $frozen['tax']['tax_amount_minor']);
        $result = $svc->submit($frozen['quote_token'], 'idem-stub');
        self::assertSame(0, $result->totals['tax_amount_minor']);
    }

    public function testShadowObservesWithoutChangingCheckoutMoney(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive();
        $advisor->rollout()->setMode(
            CheckoutTaxAdvisor::CAPABILITY,
            CommerceRolloutGateInterface::MODE_SHADOW,
        );
        $svc = CheckoutGroupSubmitService::forTesting(
            ScopedShippingQuoteService::forTesting($this->rates()),
            taxAdvisor: $advisor,
        );
        $frozen = $svc->freezeAndQuote(
            lines: [[
                'line_uuid' => 'shadow-line',
                'name' => 'Shadow',
                'qty_minor' => 1,
                'unit_price_minor' => 1000,
                'requires_shipping' => true,
            ]],
            address: ['country' => 'CN'],
            scope: ['website_id' => 0, 'store_id' => 0],
            serviceCode: 'std',
            currency: 'CNY',
        );

        self::assertSame('none', $frozen['tax']['mode']);
        self::assertSame('mode_shadow_no_write', $frozen['tax']['note']);
        self::assertSame(0, $frozen['tax']['tax_amount_minor']);
    }

    public function testVerifiedExactScopeLkgReplaysOriginalRequest(): void
    {
        $lkg = TaxLkgStore::forTesting();
        $engine = TaxEngine::forTesting($lkg);
        $request = [
            'website_id' => 0,
            'store_id' => 0,
            'currency' => 'CNY',
            'jurisdiction_key' => 'CN|',
            'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
            'lines' => [[
                'line_id' => 'lkg-line',
                'tax_class_code' => 'standard',
                'taxable_amount_minor' => 1000,
            ]],
        ];
        $snapshot = $engine->ruleSetSnapshot($request);
        $lkg->saveVerified(
            $snapshot,
            hash('sha256', 'p3b002-request-window'),
            hash('sha256', 'p3b002-shadow-report'),
            100,
        );
        $gate = CheckoutTaxAdvisor::forTestingActive()->rollout();
        $engine->setDown(true);
        $advisor = new CheckoutTaxAdvisor($engine, $gate, $lkg);
        $tax = $advisor->quoteTax(
            [[
                'items' => [[
                    'line_uuid' => 'lkg-line',
                    'row_total_minor' => 1000,
                    'tax_class_code' => 'standard',
                ]],
            ]],
            ['website_id' => 0, 'store_id' => 0],
            ['country' => 'CN'],
            'CNY',
        );

        self::assertSame(TaxEngine::SOURCE_LKG, $tax['engine']);
        self::assertSame(130, $tax['tax_amount_minor']);
        self::assertSame($snapshot['scope_key'], $tax['scope_key']);
        self::assertSame($snapshot['rule_set_hash'], $tax['rule_set_hash']);

        try {
            $advisor->quoteTax(
                [[
                    'items' => [[
                        'line_uuid' => 'lkg-other-scope',
                        'row_total_minor' => 1000,
                        'tax_class_code' => 'standard',
                    ]],
                ]],
                ['website_id' => 0, 'store_id' => 1],
                ['country' => 'CN'],
                'CNY',
            );
            self::fail('cross-scope LKG must not be reused');
        } catch (TaxConflictException $e) {
            self::assertSame(CheckoutTaxAdvisor::ERROR_BLOCKED, $e->errorCode());
        }
    }

    public function testActiveTaxWritesConservedSnapshotsOnSplitOrdersAndItems(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive();
        $facade = OrderFacade::forTesting();
        $svc = CheckoutGroupSubmitService::forTesting(
            ScopedShippingQuoteService::forTesting($this->rates()),
            orderFacade: $facade,
            taxAdvisor: $advisor,
        );
        $frozen = $svc->freezeAndQuote(
            lines: [
                [
                    'line_uuid' => 'tax-line-a',
                    'name' => 'A',
                    'qty_minor' => 1,
                    'unit_price_minor' => 1000,
                    'requires_shipping' => true,
                    'split_key' => 'a',
                    'tax_class_code' => 'standard',
                ],
                [
                    'line_uuid' => 'tax-line-b',
                    'name' => 'B',
                    'qty_minor' => 1,
                    'unit_price_minor' => 2000,
                    'requires_shipping' => false,
                    'split_key' => 'b',
                    'tax_class_code' => 'reduced',
                ],
            ],
            address: ['country' => 'CN'],
            scope: ['website_id' => 0, 'store_id' => 0],
            serviceCode: 'std',
            currency: 'CNY',
        );
        // 1000 * 13% + 2000 * 9% = 310
        self::assertSame(310, $frozen['tax']['tax_amount_minor']);
        self::assertSame(TaxEngine::SCHEMA_VERSION, $frozen['tax']['rule_schema_version']);
        $result = $svc->submit($frozen['quote_token'], 'idem-engine', expectedTaxRuleSetHash: $frozen['tax']['rule_set_hash']);
        self::assertSame(310, $result->totals['tax_amount_minor']);
        self::assertCount(2, $result->orderUuids);

        $orderTax = 0;
        $itemTax = 0;
        foreach ($result->orderUuids as $orderUuid) {
            $read = $facade->get($orderUuid);
            self::assertSame('engine', $read->tax['mode']);
            self::assertCount(1, $read->tax['lines']);
            $orderTax += (int) $read->tax['tax_amount_minor'];
            foreach ($read->items as $item) {
                self::assertSame(
                    $item['tax_amount_minor'],
                    $item['tax_snapshot']['tax_amount_minor'],
                );
                $itemTax += (int) $item['tax_amount_minor'];
            }
        }
        $group = $facade->getGroup($result->checkoutGroupUuid);
        self::assertCount(2, $group['snapshots']['tax']['lines']);
        self::assertSame(310, $orderTax);
        self::assertSame(310, $itemTax);
        self::assertSame(310, $group['snapshots']['tax']['tax_amount_minor']);
    }
}
