<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

/** Contract: order_paid 观察者须读 catalog 快照 / OrderItem，并解码 shipping JSON。 */
final class OrderPaidObserverCatalogLinesContractTest extends TestCase
{
    public function testObserverReadsCatalogSnapshotAndShippingJson(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Observer/OrderPaidObserver.php');
        self::assertStringContainsString('catalog_snapshot_json', $src);
        self::assertStringContainsString('collectRawLines', $src);
        self::assertStringContainsString('OrderItem', $src);
        self::assertStringContainsString('qty_minor', $src);
        self::assertStringContainsString('shipping_snapshot_json', $src);
        self::assertStringContainsString('decodeJsonMap', $src);
        self::assertStringContainsString('extractShippingAddress', $src);
    }
}
