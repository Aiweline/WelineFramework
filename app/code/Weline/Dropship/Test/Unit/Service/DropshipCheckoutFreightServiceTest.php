<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Service\DropshipCheckoutFreightService;
use Weline\Dropship\Service\DropshipFreightAggregator;
use Weline\Dropship\Service\DropshipPricingService;

final class DropshipCheckoutFreightServiceTest extends TestCase
{
    public function testOverlayMethodsNoopWhenNoDropshipLines(): void
    {
        $svc = $this->serviceWithResolved([]);
        $methods = [
            ['code' => 'SEED', 'amount_minor' => 1200, 'amount' => 12.0],
        ];
        $out = $svc->overlayMethods(
            $methods,
            [['offer_id' => 1, 'requires_shipping' => true, 'qty' => 1]],
            ['country_code' => 'US'],
            ['website_id' => 1],
            'CNY',
        );
        self::assertSame(1200, $out[0]['amount_minor']);
    }

    public function testOverlayMethodsReplacesWhenAllDropship(): void
    {
        $svc = $this->serviceWithResolved([
            [
                'line_key' => 'offer-9-0',
                'offer_id' => 9,
                'provider_code' => 'fake',
                'external_sku' => 'VID1',
                'external_spu' => 'SPU1',
                'external_vid' => 'VID1',
                'remote_country' => 'CN',
                'qty' => 1,
                'split_key' => '',
            ],
        ], [
            'segments' => [
                ['code' => 'a', 'amount_minor' => 800, 'currency' => 'USD', 'provider_code' => 'fake'],
                ['code' => 'b', 'amount_minor' => 500, 'currency' => 'USD', 'provider_code' => 'fake'],
            ],
            'total_minor' => 500,
            'currency' => 'USD',
        ]);

        $out = $svc->overlayMethods(
            [['code' => 'SEED', 'amount_minor' => 1200, 'amount' => 12.0]],
            [['offer_id' => 9, 'requires_shipping' => true, 'qty' => 1]],
            ['country_code' => 'US'],
            ['website_id' => 1],
            'USD',
        );
        self::assertSame(500, $out[0]['amount_minor']);
        self::assertTrue($out[0]['dropship_freight']['applied'] ?? false);
    }

    public function testOverlayAmountFallsBackToLocalWhenQuoteEmpty(): void
    {
        $svc = $this->serviceWithResolved([
            [
                'line_key' => 'offer-9-0',
                'offer_id' => 9,
                'provider_code' => 'fake',
                'external_sku' => 'VID1',
                'external_spu' => 'SPU1',
                'external_vid' => 'VID1',
                'remote_country' => 'CN',
                'qty' => 1,
                'split_key' => '',
            ],
        ], [
            'segments' => [],
            'total_minor' => 0,
            'currency' => 'USD',
        ], \Weline\Dropship\Service\DropshipFreightPolicy::ON_FAILURE_FALLBACK_LOCAL);

        $result = $svc->overlayAmount(
            1200,
            [['offer_id' => 9, 'requires_shipping' => true, 'qty' => 1]],
            ['country_code' => 'US'],
            ['website_id' => 1],
            'USD',
        );
        self::assertFalse($result['applied']);
        self::assertTrue($result['degraded'] ?? false);
        self::assertNull($result['error']);
        self::assertSame(1200, $result['amount_minor']);
    }

    public function testOverlayAmountBlocksWhenProviderPolicySaysBlock(): void
    {
        $svc = $this->serviceWithResolved([
            [
                'line_key' => 'offer-9-0',
                'offer_id' => 9,
                'provider_code' => 'cj',
                'external_sku' => 'VID1',
                'external_spu' => 'SPU1',
                'external_vid' => 'VID1',
                'remote_country' => 'CN',
                'qty' => 1,
                'split_key' => '',
            ],
        ], [
            'segments' => [],
            'total_minor' => 0,
            'currency' => 'USD',
        ], \Weline\Dropship\Service\DropshipFreightPolicy::ON_FAILURE_BLOCK_CHECKOUT);

        $result = $svc->overlayAmount(
            1200,
            [['offer_id' => 9, 'requires_shipping' => true, 'qty' => 1]],
            ['country_code' => 'US'],
            ['website_id' => 1],
            'USD',
        );
        self::assertTrue($result['applied']);
        self::assertSame(DropshipCheckoutFreightService::ERROR_FREIGHT_UNAVAILABLE, $result['error']);
        self::assertSame(
            \Weline\Dropship\Service\DropshipFreightPolicy::ON_FAILURE_BLOCK_CHECKOUT,
            $result['failure_mode'] ?? null,
        );
    }

    public function testOverlayAmountReplacesDropshipPackages(): void
    {
        $svc = $this->serviceWithResolved([
            [
                'line_key' => 'offer-9-0',
                'offer_id' => 9,
                'provider_code' => 'fake',
                'external_sku' => 'VID1',
                'external_spu' => 'SPU1',
                'external_vid' => 'VID1',
                'remote_country' => 'CN',
                'qty' => 1,
                'split_key' => 'wh:2',
            ],
        ], [
            'segments' => [
                ['code' => 'a', 'amount_minor' => 300, 'currency' => 'USD', 'provider_code' => 'fake'],
            ],
            'total_minor' => 300,
            'currency' => 'USD',
        ]);

        $result = $svc->overlayAmount(
            1500,
            [
                ['offer_id' => 1, 'requires_shipping' => true, 'qty' => 1, 'split_key' => 'wh:1'],
                ['offer_id' => 9, 'requires_shipping' => true, 'qty' => 1, 'split_key' => 'wh:2'],
            ],
            ['country_code' => 'US'],
            ['website_id' => 1],
            'USD',
            [
                ['split_key' => 'wh:1', 'amount_minor' => 1000, 'lines' => [['offer_id' => 1]]],
                ['split_key' => 'wh:2', 'amount_minor' => 500, 'lines' => [['offer_id' => 9]]],
            ],
        );
        self::assertNull($result['error']);
        self::assertSame(1300, $result['amount_minor']);
        self::assertSame(300, $result['split_packages'][1]['amount_minor']);
    }

    /**
     * @param list<array<string,mixed>> $resolved
     * @param array<string,mixed> $agg
     */
    private function serviceWithResolved(
        array $resolved,
        array $agg = [],
        string $onFailure = \Weline\Dropship\Service\DropshipFreightPolicy::ON_FAILURE_FALLBACK_LOCAL,
    ): DropshipCheckoutFreightService {
        $aggregator = new class ($agg) extends DropshipFreightAggregator {
            /** @param array<string,mixed> $fixed */
            public function __construct(private array $fixed)
            {
            }

            public function quoteForProviders(array $providerCodes, array $request): array
            {
                return $this->fixed !== [] ? $this->fixed : [
                    'segments' => [],
                    'total_minor' => 0,
                    'currency' => 'USD',
                ];
            }
        };

        return new class ($resolved, $aggregator, $onFailure) extends DropshipCheckoutFreightService {
            /** @param list<array<string,mixed>> $resolved */
            public function __construct(
                private array $resolved,
                DropshipFreightAggregator $aggregator,
                private string $onFailure,
            ) {
                parent::__construct($aggregator, null, new DropshipPricingService(), null);
            }

            public function resolveFreightLines(array $lines): array
            {
                return $this->resolved;
            }

            public function resolveOnFailureMode(array $resolved): string
            {
                return $this->onFailure;
            }
        };
    }
}
