<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Service\QueueDispatchService;
use Weline\Search\Queue\SearchIndexIncrementalQueue;
use Weline\Search\Service\SearchProjectionQueueAdmission;

final class SearchProjectionQueueAdmissionContractTest extends TestCase
{
    public function testSlotKeyIsStablePerTargetAndScope(): void
    {
        $admission = new SearchProjectionQueueAdmission(
            $this->createMock(QueueDispatchService::class),
        );
        $scope = ScopeIdentity::store(0, 'default', 'store-a', ScopeIdentity::MODE_NORMAL);

        self::assertSame(
            'slot:product:301:store:0:default:store-a:' . ScopeIdentity::MODE_NORMAL,
            $admission->slotKey($scope, 'product', 301),
        );
        self::assertSame(
            $admission->slotKey($scope, 'product', 301),
            $admission->slotKey($scope, 'product', 301),
        );
        self::assertNotSame(
            $admission->slotKey($scope, 'product', 301),
            $admission->slotKey($scope, 'product', 302),
        );
    }

    public function testObserverDelegatesToCoalescedAdmissionService(): void
    {
        $observer = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/ProductSearchProjectionChangedObserver.php',
        );
        $admission = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/SearchProjectionQueueAdmission.php',
        );
        $module = (string)\file_get_contents(\dirname(__DIR__, 3) . '/etc/module.php');

        self::assertStringContainsString('SearchProjectionQueueAdmissionInterface', $observer);
        self::assertStringContainsString('$this->admission->admit(', $observer);
        self::assertStringNotContainsString("search-projection:' . \$change->eventId()", $observer);
        self::assertStringContainsString('IDEMPOTENCY_SCOPE', $admission);
        self::assertStringContainsString("slotKey . ':followup'", $admission);
        self::assertStringContainsString('requeueQueueSafely', $admission);
        self::assertStringContainsString(
            'SearchProjectionQueueAdmissionInterface',
            $module,
        );
        self::assertSame(
            SearchIndexIncrementalQueue::CONTRACT,
            'search.incremental_queue.v1',
        );
    }
}
