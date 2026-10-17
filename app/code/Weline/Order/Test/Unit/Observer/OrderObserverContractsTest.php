<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllOrderObserverContractsPass().
 */
final class OrderObserverContractsTest extends TestCase
{
    public function testAllOrderObserverContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'OrderPaidObserverSuppressTobHang::testObserverSourceSuppressesTobHangPaidMail' => function () {
            $this->assert_OrderPaidObserverSuppressTobHang_testObserverSourceSuppressesTobHangPaidMail();
        },
        ];
    }

private function assert_OrderPaidObserverSuppressTobHang_testObserverSourceSuppressesTobHangPaidMail(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Observer/OrderPaidObserver.php');
    self::assertStringContainsString('suppress_customer_paid_mail', $src);
    self::assertStringContainsString("orderType !== 'tob'", $src);
    self::assertStringContainsString('hang_status', $src);
}
}
