<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class OrderPaidObserverSuppressTobHangContractTest extends TestCase
{
    public function testObserverSourceSuppressesTobHangPaidMail(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Observer/OrderPaidObserver.php');
        self::assertStringContainsString('suppress_customer_paid_mail', $src);
        self::assertStringContainsString("orderType !== 'tob'", $src);
        self::assertStringContainsString('hang_status', $src);
    }
}
