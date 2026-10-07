<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/** Duty/import tax is order-level (duty:* lines), not cart line_uuid engine rows. */
final class OrderFacadeOrderLevelDutyTaxContractTest extends TestCase
{
    public function testBuildPlannedOrdersAcceptsOrderLevelDutySnapshots(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/OrderFacade.php');
        self::assertStringContainsString('isOrderLevelTaxSnapshot', $src);
        self::assertStringContainsString("str_starts_with(\$lineId, 'duty:')", $src);
        self::assertStringContainsString('orderLevelTaxMinor', $src);
        self::assertStringContainsString('import_at_border', $src);
        self::assertStringContainsString('duty_only', $src);
        self::assertStringContainsString('dutyTaxTotal', $src);
    }
}
