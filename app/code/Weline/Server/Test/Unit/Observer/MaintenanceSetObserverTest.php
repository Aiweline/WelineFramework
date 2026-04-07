<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Observer\MaintenanceSetObserver;
use Weline\Server\Service\Control\IpcControlGateway;

final class MaintenanceSetObserverTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::removeInstance(IpcControlGateway::class);
        \putenv('WLS_INSTANCE');
        parent::tearDown();
    }

    public function testObserverUsesAsyncMaintenanceGatewayAndMarksHandled(): void
    {
        $gateway = new class extends IpcControlGateway {
            public array $calls = [];

            public function setMaintenanceMode(string $instanceName, bool $enabled, float $timeout = 6.0): array
            {
                $this->calls[] = [$instanceName, $enabled, $timeout];

                return ['success' => true, 'message' => 'queued', 'data' => ['async' => true]];
            }
        };

        ObjectManager::setInstance(IpcControlGateway::class, $gateway);
        \putenv('WLS_INSTANCE=blue');

        $observer = new MaintenanceSetObserver();
        $event = new Event(['data' => ['value' => true]]);
        $observer->execute($event);

        self::assertTrue((bool) $event->getData('handled'));
        self::assertSame([['blue', true, 6.0]], $gateway->calls);
    }
}
