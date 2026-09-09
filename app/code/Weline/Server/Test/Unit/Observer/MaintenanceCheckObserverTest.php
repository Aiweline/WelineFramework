<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\Error\ErrorContext;
use Weline\Server\Observer\MaintenanceCheckObserver;
use Weline\Server\Service\Control\IpcControlGateway;

final class MaintenanceCheckObserverTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorContext::reset();
        SchedulerSystem::disableScheduler();
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
            public float $lastTimeout = 0.0;

            public function getStatus(string $instanceName = 'default', float $timeout = 4.0): array
            {
                $this->statusCalls++;
                $this->lastTimeout = $timeout;

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
        self::assertSame(2.0, $gateway->lastTimeout);
    }

    public function testRequestFiberUsesFailFastStatusTimeout(): void
    {
        $gateway = new class extends IpcControlGateway {
            public int $statusCalls = 0;
            public float $lastTimeout = 0.0;

            public function getStatus(string $instanceName = 'default', float $timeout = 4.0): array
            {
                $this->statusCalls++;
                $this->lastTimeout = $timeout;

                return ['success' => true, 'data' => ['maintenance_mode' => false]];
            }
        };

        ObjectManager::setInstance(IpcControlGateway::class, $gateway);
        ErrorContext::setProcessTag('WorkerSSL#1:16899@default');
        ErrorContext::setContext(['is_maintenance' => false]);
        SchedulerSystem::enableScheduler();

        $observer = new MaintenanceCheckObserver();
        $event = new Event(['data' => ['result' => null]]);
        $fiber = new \Fiber(static function () use ($observer, $event): void {
            $observer->execute($event);
        });
        $fiber->start();

        self::assertFalse((bool) $event->getData('result'));
        self::assertSame(1, $gateway->statusCalls);
        self::assertSame(0.05, $gateway->lastTimeout);
    }

    public function testMaintenanceWorkerRuntimeFlagIsWiredIntoWorkerEntriesAndInterceptor(): void
    {
        $workerSource = (string) \file_get_contents(BP . 'app/code/Weline/Server/bin/worker.php');
        $workerSslSource = (string) \file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');
        $interceptorSource = (string) \file_get_contents(BP . 'app/code/Weline/Maintenance/Observer/MaintenanceInterceptor.php');
        $maintenanceTemplateSource = (string) \file_get_contents(BP . 'app/code/Weline/Maintenance/view/templates/maintenance.phtml');
        $appSource = (string) \file_get_contents(BP . 'app/code/Weline/Framework/App.php');
        $fpcObserverSource = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Router/Observer/CheckFullPageCache.php');

        self::assertStringContainsString("define('WLS_MAINTENANCE_WORKER', true)", $workerSource);
        self::assertStringContainsString("define('WLS_MAINTENANCE_WORKER', true)", $workerSslSource);
        self::assertStringContainsString("defined('WLS_MAINTENANCE_WORKER')", $interceptorSource);
        self::assertStringContainsString('Runtime::isCli()', $interceptorSource);
        self::assertStringContainsString("defined('WLS_MODE')", $interceptorSource);
        self::assertStringNotContainsString("PHP_SAPI === 'cli'", $interceptorSource);
        self::assertStringContainsString('applyParsedRequestUri()', $interceptorSource);
        self::assertStringContainsString('MaintenanceStaticPage', $interceptorSource);
        self::assertStringContainsString('MaintenanceStaticGenerator', $interceptorSource);
        self::assertStringContainsString('MaintenanceStaticPage::publicHtmlUrl', $maintenanceTemplateSource);
        self::assertStringContainsString("defined('WLS_MAINTENANCE_WORKER')", $appSource);
        self::assertStringContainsString("defined('WLS_MAINTENANCE_WORKER')", $fpcObserverSource);
        self::assertStringContainsString("Env::system('maintenance')", $fpcObserverSource);
        self::assertStringContainsString('setRuntimeMaintenanceMode(false)', $workerSource);
        self::assertStringContainsString('setRuntimeMaintenanceMode(false)', $workerSslSource);
        self::assertStringNotContainsString('setRuntimeMaintenanceMode($mEnabled)', $workerSource);
        self::assertStringNotContainsString('setRuntimeMaintenanceMode($mEnabled)', $workerSslSource);

        $policyKernelSource = (string) \file_get_contents(BP . 'app/code/Weline/Server/Security/WorkerPolicyKernel.php');
        $unavailablePageSource = (string) \file_get_contents(BP . 'app/code/Weline/Server/Http/ServiceUnavailablePage.php');
        self::assertStringContainsString('isMaintenanceFrontendApiPath', $policyKernelSource);
        self::assertStringContainsString('/maintenance/frontend/wait-gift', $policyKernelSource);
        self::assertStringContainsString('/maintenance/frontend/recovery-check', $policyKernelSource);
        self::assertStringContainsString('isMaintenanceStaticAssetPath', $policyKernelSource);
        self::assertStringContainsString('PATH_SCAN_STATIC_EXTENSIONS', $policyKernelSource);
        self::assertStringContainsString("'/pub/errors/'", $policyKernelSource);
        self::assertStringContainsString('maintenanceGateSetCookie', $unavailablePageSource);
        self::assertStringContainsString('weline_mw_gate', $unavailablePageSource);

        $kernel = (new \ReflectionClass(\Weline\Server\Security\WorkerPolicyKernel::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(\Weline\Server\Security\WorkerPolicyKernel::class, 'isMaintenanceStaticAssetPath');
        $method->setAccessible(true);
        self::assertTrue($method->invoke($kernel, '/Weline/Theme/view/theme/frontend/assets/images/theme/logo.png'));
        self::assertTrue($method->invoke($kernel, '/static/Weline/Theme/view/theme/frontend/assets/images/theme/logo.png'));
        self::assertTrue($method->invoke($kernel, '/pub/errors/maintenance/zh_Hans_CN.html'));
        self::assertTrue($method->invoke($kernel, '/Weline/Theme/view/statics/ui/weline-ui.js'));
        self::assertFalse($method->invoke($kernel, '/'));
        self::assertFalse($method->invoke($kernel, '/catalog/product/view'));
    }

    public function testWorkerEntriesAvoidBlockingUsleepInLongLivedSlotWait(): void
    {
        $workerSource = (string) \file_get_contents(BP . 'app/code/Weline/Server/bin/worker.php');
        $workerSslSource = (string) \file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');

        self::assertStringContainsString('SchedulerSystem::yieldDelay(50)', $workerSource);
        self::assertStringContainsString('SchedulerSystem::yieldDelay(50)', $workerSslSource);
        self::assertStringNotContainsString('\\usleep(50_000)', $workerSource);
        self::assertStringNotContainsString('\\usleep(50_000)', $workerSslSource);
    }

    public function testSchedulerYieldDelayAdvancesTimeOnMainStack(): void
    {
        SchedulerSystem::disableScheduler();

        $startedAt = \microtime(true);
        SchedulerSystem::yieldDelay(50);
        $elapsedMs = (\microtime(true) - $startedAt) * 1000;

        self::assertGreaterThanOrEqual(20.0, $elapsedMs);
    }

    public function testSchedulerYieldDelayAdvancesTimeInsideFiberWhenSchedulerActive(): void
    {
        SchedulerSystem::enableScheduler();

        $elapsedMs = 0.0;
        $resumed = false;
        $fiber = new \Fiber(static function () use (&$elapsedMs, &$resumed): void {
            $startedAt = \microtime(true);
            SchedulerSystem::yieldDelay(50);
            $resumed = true;
            $elapsedMs = (\microtime(true) - $startedAt) * 1000;
        });

        $fiber->start();

        self::assertTrue($fiber->isSuspended());
        self::assertFalse($resumed);

        $fiber->resume();

        self::assertTrue($resumed);
        self::assertGreaterThanOrEqual(0.0, $elapsedMs);
    }
}
