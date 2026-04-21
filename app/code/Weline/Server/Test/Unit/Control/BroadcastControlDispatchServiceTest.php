<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Control;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\ServerInstanceManager;

final class BroadcastControlDispatchServiceTest extends TestCase
{
    public function testCacheClearOnlyTargetsIpcControllableInstancesAndAggregatesFailures(): void
    {
        $gateway = new class extends IpcControlGateway {
            public array $instances = [];

            public function cacheClear(string $instanceName, float $timeout = 3.0): array
            {
                $this->instances[] = $instanceName;

                if ($instanceName === 'beta') {
                    return ['success' => false, 'message' => 'dispatcher offline', 'data' => []];
                }

                return ['success' => true, 'message' => 'ok', 'data' => []];
            }
        };

        $manager = new class extends ServerInstanceManager {
            public function listPersistedInstanceNames(): array
            {
                return ['alpha', 'beta', 'stale-master'];
            }

            public function isInstanceIpcControllable(string $name): bool
            {
                return $name !== 'stale-master';
            }
        };

        $service = new BroadcastControlDispatchService($gateway, $manager);
        $result = $service->cacheClear();

        $this->assertSame(['alpha', 'beta'], $gateway->instances);
        $this->assertFalse($result['success']);
        $this->assertSame(['alpha', 'beta'], $result['attempted']);
        $this->assertSame(['alpha'], $result['succeeded']);
        $this->assertSame(['beta' => 'dispatcher offline'], $result['failed_by_instance']);
        $this->assertSame('ok', $result['results_by_instance']['alpha']['message'] ?? null);
        $this->assertSame('dispatcher offline', $result['results_by_instance']['beta']['message'] ?? null);
        $this->assertStringContainsString('beta: dispatcher offline', $result['message']);
    }

    public function testReloadAsyncReportsExplicitInstanceWithoutMasterIpcControl(): void
    {
        $gateway = new class extends IpcControlGateway {
        };

        $manager = new class extends ServerInstanceManager {
            public function hasInstance(string $name): bool
            {
                return true;
            }

            public function isInstanceIpcControllable(string $name): bool
            {
                return false;
            }
        };

        $service = new BroadcastControlDispatchService($gateway, $manager);
        $result = $service->reloadAsync('default', 'code');

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['attempted']);
        $this->assertSame([], $result['succeeded']);
        $this->assertSame(['default' => 'Master 未运行，无法通过 IPC 控制。'], $result['failed_by_instance']);
        $this->assertSame([], $result['results_by_instance']);
        $this->assertStringContainsString('default', $result['message']);
    }

    public function testSetMaintenanceModeDelegatesToAsyncGatewayMethod(): void
    {
        $gateway = new class extends IpcControlGateway {
            public array $calls = [];

            public function setMaintenanceMode(
                string $instanceName,
                bool $enabled,
                float $timeout = 6.0,
                bool $dispatcherOnly = false
            ): array {
                unset($dispatcherOnly);
                $this->calls[] = [$instanceName, $enabled, $timeout];

                return ['success' => true, 'message' => 'ok', 'data' => []];
            }
        };

        $manager = new class extends ServerInstanceManager {
            public function hasInstance(string $name): bool
            {
                return $name === 'blue';
            }

            public function isInstanceIpcControllable(string $name): bool
            {
                return $name === 'blue';
            }
        };

        $service = new BroadcastControlDispatchService($gateway, $manager);
        $enableResult = $service->setMaintenanceMode(true, 'blue', 2.5);
        $disableResult = $service->setMaintenanceMode(false, 'blue', 3.5);

        $this->assertTrue($enableResult['success']);
        $this->assertTrue($disableResult['success']);
        $this->assertSame(['blue', true, 2.5], $gateway->calls[0]);
        $this->assertSame(['blue', false, 3.5], $gateway->calls[1]);
    }
}
