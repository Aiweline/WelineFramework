<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Tax\Observer\CheckoutShippingMethodsEnrichDutyObserver;
use Weline\Tax\Service\DutyEstimateService;

final class CheckoutShippingMethodsEnrichDutyObserverContractTest extends TestCase
{
    public function testEnrichesMethodWithoutChangingShippingAmount(): void
    {
        $observer = new CheckoutShippingMethodsEnrichDutyObserver();
        $event = new Event('Weline_Checkout::checkout::shipping_methods::enrich', [
            'methods' => [[
                'code' => 'domestic_std',
                'amount_minor' => 0,
                'amount' => 0,
                'duty_notice' => DutyEstimateService::NOTICE_DDU,
            ]],
            'lines' => [[
                'row_total_minor' => 21000,
            ]],
            'address' => ['country_code' => 'US'],
            'scope' => ['origin_country' => 'CN'],
            'currency' => 'CNY',
        ]);

        $observer->execute($event);
        $methods = $event->getData('methods');
        self::assertIsArray($methods);
        self::assertCount(1, $methods);
        self::assertSame(0, (int)$methods[0]['amount_minor']);
        self::assertSame(1680, (int)$methods[0]['tax_amount_minor']);
        self::assertSame(1680, (int)$methods[0]['duty_amount_minor']);
    }
}
