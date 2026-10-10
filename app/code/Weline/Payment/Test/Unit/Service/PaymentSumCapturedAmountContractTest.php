<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Api\PaymentRefundFacadeInterface;
use Weline\Payment\Service\PaymentRefundService;

final class PaymentSumCapturedAmountContractTest extends TestCase
{
    public function testFacadeDeclaresSumAndAbandonOpenIntents(): void
    {
        $iface = new \ReflectionClass(PaymentRefundFacadeInterface::class);
        self::assertTrue($iface->hasMethod('sumCapturedAmountMinor'));
        self::assertTrue($iface->hasMethod('abandonOpenIntentsForPayable'));
        $svc = new \ReflectionClass(PaymentRefundService::class);
        self::assertTrue($svc->hasMethod('sumCapturedAmountMinor'));
        self::assertTrue($svc->hasMethod('abandonOpenIntentsForPayable'));
        self::assertTrue($svc->hasMethod('listCapturedIntents'));
    }
}
