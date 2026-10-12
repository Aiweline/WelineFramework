<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class OrderPaidSideEffectIsolationContractTest extends TestCase
{
    public function test_order_paid_observer_isolates_affiliate_exceptions(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/OrderPaymentStatusChangedObserver.php',
        );
        self::assertStringContainsString('handlePaymentStatusChanged', $src);
        self::assertStringContainsString('catch (\\Throwable', $src);
        self::assertStringContainsString('Affiliate order_paid side-effect failed', $src);
    }
}
