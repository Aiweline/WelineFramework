<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Service\RateCalculationService;
use Weline\Shipping\Service\ShippingCommercePolicyService;
use Weline\Shipping\Service\ShippingIncotermService;
use Weline\Shipping\Service\SplitShipmentShippingService;

final class CommerceEdgesChapter4ContractTest extends TestCase
{
    public function testIncotermDutyNoticeDoesNotChangeSemanticsOfAmount(): void
    {
        $svc = new ShippingIncotermService();
        self::assertSame('ddp', $svc->normalize('DDP'));
        self::assertSame('ddu', $svc->normalize(''));
        self::assertNotSame('', $svc->dutyNotice('ddp'));
        self::assertNotSame($svc->dutyNotice('ddp'), $svc->dutyNotice('ddu'));
        self::assertSame(ShippingIncotermService::NOTICE_DDU, $svc->dutyNotice('ddu'));
        self::assertStringNotContainsString('_', $svc->labelForDutyNoticeCode(ShippingIncotermService::NOTICE_DDU));
        self::assertStringContainsString('关税', $svc->labelForDutyNoticeCode(ShippingIncotermService::NOTICE_DDU));
        self::assertNotSame(
            ShippingIncotermService::NOTICE_DDU,
            $svc->labelForDutyNoticeCode(ShippingIncotermService::NOTICE_DDU),
        );
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/Provider/LocalTemplatePricingService.php',
        );
        self::assertStringContainsString("'incoterm'", $src);
        self::assertStringContainsString("'duty_notice'", $src);
        self::assertStringContainsString('ShippingIncotermService', $src);
    }

    public function testSplitFirstOnlySecondShipmentIsZero(): void
    {
        $om = $this->createMock(ObjectManager::class);
        $commerce = new ShippingCommercePolicyService($om);
        $rates = $this->createMock(RateCalculationService::class);
        $svc = new SplitShipmentShippingService($commerce, $rates);
        $snap = [
            'split_shipment_shipping' => 'first_only',
            'outbound_shipping_minor' => 11500,
        ];
        $second = $svc->quoteSubsequentShipment(2, [['qty_minor' => 1, 'weight_minor' => 1000]], $snap);
        self::assertSame(0, $second['amount_minor']);
        self::assertSame('first_only', $second['strategy']);
        $first = $svc->quoteSubsequentShipment(1, [], $snap);
        self::assertSame(11500, $first['amount_minor']);
    }

    public function testSplitEachShipmentRecalculatesPositive(): void
    {
        $om = $this->createMock(ObjectManager::class);
        $commerce = new ShippingCommercePolicyService($om);
        $rates = $this->createMock(RateCalculationService::class);
        $rates->method('calculateTemplateMinor')->willReturn(4200);
        $tpl = $this->createMock(RateTemplate::class);
        $svc = new SplitShipmentShippingService($commerce, $rates);
        $snap = ['split_shipment_shipping' => 'each_shipment', 'outbound_shipping_minor' => 11500];
        $second = $svc->quoteSubsequentShipment(
            2,
            [['requires_shipping' => true, 'qty_minor' => 1, 'weight_minor' => 2000]],
            $snap,
            $tpl,
        );
        self::assertSame(4200, $second['amount_minor']);
        self::assertGreaterThan(0, $second['amount_minor']);
        self::assertSame('each_shipment', $second['strategy']);
    }

    public function testReturnSellerPaysZeroAndBuyerTemplateWired(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ReturnShippingQuoteService.php',
        );
        self::assertStringContainsString('seller_pays', $src);
        self::assertStringContainsString('SEED_TPL_RETURN_DOMESTIC', $src);
        self::assertStringNotContainsString('freeShipping', $src);
        $seed = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DefaultShippingLaneSeedService.php',
        );
        self::assertStringContainsString('ensureCommerceChapter4Seeds', $seed);
        self::assertStringContainsString('ReturnShippingQuoteService::TPL_DOMESTIC', $seed);
        self::assertStringContainsString('ReturnShippingQuoteService::TPL_INTL', $seed);
    }

    public function testConfigVersionAndModule290(): void
    {
        $mgr = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ShippingServiceManager.php',
        );
        self::assertStringContainsString('commerceConfigFacts', $mgr);
        $module = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/module.php');
        self::assertStringContainsString("'2.9.0'", $module);
        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('ShippingCommercePolicy', $upgrade);
    }

    public function testCodFeeCalculatorIntoGrandTotalContract(): void
    {
        $calcSrc = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Payment/Service/CodFeeCalculator.php',
        );
        self::assertStringContainsString('fromConfig', $calcSrc);
        $moneySrc = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Order/Api/Data/MoneySnapshot.php',
        );
        self::assertStringContainsString('codFeeAmountMinor', $moneySrc);
        self::assertStringContainsString('cod_fee_amount_minor', $moneySrc);
        $facadeSrc = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Order/Service/OrderFacade.php',
        );
        self::assertStringContainsString('resolveCodFeeMinor', $facadeSrc);
        self::assertStringContainsString('split_shipment_shipping', $facadeSrc);

        $om = $this->createMock(ObjectManager::class);
        $calc = new \Weline\Payment\Service\CodFeeCalculator($om);
        self::assertSame(500, $calc->fromConfig(['fee' => 5.00], 10000, 2));
        $baseline = 11500;
        $fee = $calc->fromConfig(['fee' => 5.00], $baseline, 2);
        self::assertSame(500, $fee);
        self::assertSame(12000, $baseline + $fee);
    }

    public function testAdminLaneIncotermEditable(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/ShippingService/index.phtml',
        );
        self::assertStringContainsString('name="incoterm"', $tpl);
        self::assertStringContainsString('saveIncoterm', $tpl);
        $admin = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ShippingConfigurationAdminService.php',
        );
        self::assertStringContainsString('updateShippingServiceIncoterm', $admin);
        self::assertStringContainsString('schema_fields_INCOTERM', $admin);
    }
}
