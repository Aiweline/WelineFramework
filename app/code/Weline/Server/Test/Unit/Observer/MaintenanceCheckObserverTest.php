<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Log\Error\ErrorContext;
use Weline\Server\Observer\MaintenanceCheckObserver;
use Weline\Server\Service\Control\IpcControlGateway;

final class MaintenanceCheckObserverTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorContext::reset();
        ObjectManager::removeInstance(IpcControlGateway::class);
        parent::tearDown();
    }

    public function testMaintenanceWorkerShortCircuitsToTrueWithoutIpcLookup(): void
    {
        $gateway = new class extends IpcControlGateway {
            public int $statusCalls = 0;

            public function getStatus(string $instanceName = 'default', float $timeout = 4.0): array
            {
                $this->statusCalls++;

                return ['success' => true, 'data' => ['maintenance_mode' => false]];
            }
        };

        ObjectManager::setInstance(IpcControlGateway::class, $gateway);
        ErrorContext::setProcessTag('MaintenanceSSL#1:16999@default');
        ErrorContext::setContext(['is_maintenance' => true]);

        $observer = new MaintenanceCheckObserver();
        $event = new Event(['data' => ['result' => false]]);
        $observer->execute($event);

        self::assertTrue((bool) $event->getData('result'));
        self::assertSame(0, $gateway->statusCalls);
    }

    public function testNonMaintenanceWorkerStillUsesIpcStatus(): void
    {
        $gateway = new class extends IpcControlGateway {
            public int $statusCalls = 0;

            public function getStatus(string $instanceName = 'default', float $timeout = 4.0): array
            {
                $this->statusCalls++;

                return ['success' => true, 'data' => ['maintenance_mode' => true]];
            }
        };

        ObjectManager::setInstance(IpcControlGateway::class, $gateway);
        ErrorContext::setProcessTag('WorkerSSL#1:16899@default');
        ErrorContext::setContext(['is_maintenance' => false]);

        $observer = new MaintenanceCheckObserver();
        $event = new Event(['data' => ['result' => false]]);
        $observer->execute($event);

        self::assertTrue((bool) $event->getData('result'));
        self::assertSame(1, $gateway->statusCalls);
    }

    public function testMaintenanceWorkerRuntimeFlagIsWiredIntoWorkerEntriesAndInterceptor(): void
    {
        $workerSource = (string) \file_get_contents(BP . 'app/code/Weline/Server/bin/worker.php');
        $workerSslSource = (string) \file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');
        $interceptorSource = (string) \file_get_contents(BP . 'app/code/Weline/Maintenance/Observer/MaintenanceInterceptor.php');

        self::assertStringContainsString("define('WLS_MAINTENANCE_WORKER', true)", $workerSource);
        self::assertStringContainsString("define('WLS_MAINTENANCE_WORKER', true)", $workerSslSource);
        self::assertStringContainsString("defined('WLS_MAINTENANCE_WORKER')", $interceptorSource);
        self::assertStringContainsString('Runtime::isCli()', $interceptorSource);
        self::assertStringContainsString("defined('WLS_MODE')", $interceptorSource);
        self::assertStringNotContainsString("PHP_SAPI === 'cli'", $interceptorSource);
        self::assertStringContainsString('sendMaintenanceResponse()', $interceptorSource);
    }
}
