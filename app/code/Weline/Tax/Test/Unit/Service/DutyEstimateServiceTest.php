<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Tax\Service\DutyEstimateService;

final class DutyEstimateServiceTest extends TestCase
{
    public function testDduCrossBorderUsChargesDuty(): void
    {
        $svc = new DutyEstimateService();
        $out = $svc->estimate([
            'goods_subtotal_minor' => 21000,
            'shipping_amount_minor' => 0,
            'destination_country' => 'US',
            'origin_country' => 'CN',
            'duty_notice' => DutyEstimateService::NOTICE_DDU,
            'currency' => 'CNY',
        ]);

        self::assertTrue($out['cross_border']);
        self::assertSame(DutyEstimateService::REASON_ESTIMATED, $out['reason']);
        self::assertSame(1680, $out['duty_amount_minor']); // 21000 * 800bps half-up
        self::assertSame(0, $out['import_tax_amount_minor']);
        self::assertSame(1680, $out['charged_minor']);
        self::assertNotSame([], $out['lines']);
    }

    public function testDduDomesticZero(): void
    {
        $svc = new DutyEstimateService();
        $out = $svc->estimate([
            'goods_subtotal_minor' => 21000,
            'shipping_amount_minor' => 0,
            'destination_country' => 'CN',
            'origin_country' => 'CN',
            'duty_notice' => DutyEstimateService::NOTICE_DDU,
            'currency' => 'CNY',
        ]);

        self::assertSame(0, $out['charged_minor']);
        self::assertSame(DutyEstimateService::REASON_DOMESTIC, $out['reason']);
    }

    public function testDapNotCollectedAtCheckout(): void
    {
        $svc = new DutyEstimateService();
        $out = $svc->estimate([
            'goods_subtotal_minor' => 21000,
            'destination_country' => 'DE',
            'origin_country' => 'CN',
            'duty_notice' => DutyEstimateService::NOTICE_DAP,
        ]);

        self::assertSame(0, $out['charged_minor']);
        self::assertSame(DutyEstimateService::REASON_DAP_AT_DELIVERY, $out['reason']);
    }

    public function testDeIncludesVatOnCifPlusDuty(): void
    {
        $svc = new DutyEstimateService();
        $out = $svc->estimate([
            'goods_subtotal_minor' => 10000,
            'shipping_amount_minor' => 0,
            'destination_country' => 'DE',
            'origin_country' => 'CN',
            'duty_notice' => DutyEstimateService::NOTICE_DDU,
            'currency' => 'CNY',
        ]);

        // duty 12% of 10000 = 1200; vat 19% of 11200 = 2128
        self::assertSame(1200, $out['duty_amount_minor']);
        self::assertSame(2128, $out['import_tax_amount_minor']);
        self::assertSame(3328, $out['charged_minor']);
    }
}
