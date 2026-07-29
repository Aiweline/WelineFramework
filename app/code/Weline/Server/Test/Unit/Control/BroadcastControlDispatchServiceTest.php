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

            public function cacheClearMany(array $instanceNames, float $timeout = 5.0): array
            {
                $results = [];
                foreach ($instanceNames as $name) {
                    $results[$name] = $this->cacheClear($name, $timeout);
                }

                return $results;
            }
        };

        $manager = new class extends ServerInstanceManager {
            public function listPersistedInstanceNames(): array
            {
                return ['alpha', 'beta', 'stale-master'];
            }

            public function hasInstance(string $name): bool
            {
                return true;
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
        $this->assertArrayHasKey('stale-master', $result['skipped_by_instance']);
        $this->assertStringContainsString('Master', $result['skipped_by_instance']['stale-master']);
        $this->assertSame('ok', $result['results_by_instance']['alpha']['message'] ?? null);
        $this->assertSame('dispatcher offline', $result['results_by_instance']['beta']['message'] ?? null);
        $this->assertStringContainsString('beta: dispatcher offline', $result['message']);
        $this->assertStringContainsString('跳过', $result['message']);
        $this->assertStringContainsString('stale-master', $result['message']);
    }

    public function testBroadcastSilentlySkipsTrulyAbsentInstances(): void
    {
        $gateway = new class extends IpcControlGateway {
            public array $instances = [];

            public function cacheClear(string $instanceName, float $timeout = 3.0): array
            {
                $this->instances[] = $instanceName;
                return ['success' => true, 'message' => 'ok', 'data' => []];
            }
        };

        $manager = new class extends ServerInstanceManager {
            public function listPersistedInstanceNames(): array
            {
                return ['alpha', 'ghost-entry'];
            }

            public function hasInstance(string $name): bool
            {
                return $name === 'alpha';
            }

            public function isInstanceIpcControllable(string $name): bool
            {
                return $name === 'alpha';
            }
        };

        $service = new BroadcastControlDispatchService($gateway, $manager);
        $result = $service->cacheClear();

        $this->assertSame(['alpha'], $gateway->instances);
        $this->assertTrue($result['success']);
        $this->assertSame([], $result['failed_by_instance']);
        $this->assertSame([], $result['skipped_by_instance']);
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
        $this->assertSame([], $result['skipped_by_instance']);
        $this->assertSame([], $result['results_by_instance']);
        $this->assertStringContainsString('default', $result['message']);
    }

    public function testReloadAsyncUsesBatchGatewayPathWhenMultipleTargets(): void
    {
        $gateway = new class extends IpcControlGateway {
            public array $serialCalls = [];
            public array $batchCalls = [];

            public function reloadAsync(string $instanceName, string $reloadType, float $timeout = 5.0): array
            {
                $this->serialCalls[] = $instanceName;
                return ['success' => true, 'message' => 'legacy serial', 'data' => []];
            }

            public function reloadAsyncMany(array $instanceNames, string $reloadType, float $timeout = 5.0): array
            {
                $this->batchCalls[] = [$instanceNames, $reloadType, $timeout];
                $out = [];
                foreach ($instanceNames as $name) {
                    $out[$name] = [
                        'success' => $name !== 'slow',
                        'message' => $name === 'slow' ? 'timed_out' : 'Reload initiated',
                        'data' => [],
                    ];
                }

                return $out;
            }
        };

        $manager = new class extends ServerInstanceManager {
            public function listPersistedInstanceNames(): array
            {
                return ['alpha', 'beta', 'slow'];
            }

            public function hasInstance(string $name): bool
            {
                return true;
            }

            public function isInstanceIpcControllable(string $name): bool
            {
                return true;
            }
        };

        $service = new BroadcastControlDispatchService($gateway, $manager);
        $result = $service->reloadAsync(null, 'code', 4.25);

        $this->assertSame([], $gateway->serialCalls);
        $this->assertCount(1, $gateway->batchCalls);
        $this->assertSame(['alpha', 'beta', 'slow'], $gateway->batchCalls[0][0]);
        $this->assertSame('code', $gateway->batchCalls[0][1]);
        $this->assertSame(4.25, $gateway->batchCalls[0][2]);
        $this->assertFalse($result['success']);
        $this->assertSame(['alpha', 'beta', 'slow'], $result['attempted']);
        $this->assertSame(['alpha', 'beta'], $result['succeeded']);
        $this->assertSame(['slow' => 'timed_out'], $result['failed_by_instance']);
        $this->assertSame([], $result['skipped_by_instance']);
    }

    public function testSingleTargetStaysOnSerialPathEvenWhenBatchDispatcherProvided(): void
    {
        $gateway = new class extends IpcControlGateway {
            public array $serialCalls = [];
            public int $batchInvocations = 0;

            public function cacheClear(string $instanceName, float $timeout = 3.0): array
            {
                $this->serialCalls[] = $instanceName;
                return ['success' => true, 'message' => 'ok', 'data' => []];
            }

            public function cacheClearMany(array $instanceNames, float $timeout = 5.0): array
            {
                $this->batchInvocations++;
                $out = [];
                foreach ($instanceNames as $name) {
                    $out[$name] = ['success' => true, 'message' => 'batch-ok', 'data' => []];
                }

                return $out;
            }
        };

        $manager = new class extends ServerInstanceManager {
            public function listPersistedInstanceNames(): array
            {
                return ['solo'];
            }

            public function hasInstance(string $name): bool
            {
                return true;
            }

            public function isInstanceIpcControllable(string $name): bool
            {
                return true;
            }
        };

        $service = new BroadcastControlDispatchService($gateway, $manager);
        $result = $service->cacheClear();

        $this->assertSame(['solo'], $gateway->serialCalls);
        $this->assertSame(0, $gateway->batchInvocations);
        $this->assertTrue($result['success']);
    }

    public function testBatchDispatcherFallsBackToPerInstanceFailureWhenItThrows(): void
    {
        $gateway = new class extends IpcControlGateway {
            public function cacheClearMany(array $instanceNames, float $timeout = 5.0): array
            {
                throw new \RuntimeException('batch crash');
            }
        };

        $manager = new class extends ServerInstanceManager {
            public function listPersistedInstanceNames(): array
            {
                return ['alpha', 'beta'];
            }

            public function hasInstance(string $name): bool
            {
                return true;
            }

            public function isInstanceIpcControllable(string $name): bool
            {
                return true;
            }
        };

        $service = new BroadcastControlDispatchService($gateway, $manager);
        $result = $service->cacheClear();

        $this->assertFalse($result['success']);
        $this->assertSame(['alpha' => 'batch crash', 'beta' => 'batch crash'], $result['failed_by_instance']);
        $this->assertSame([], $result['skipped_by_instance']);
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

    public function testSkippedPersistedInstancesDoNotFlipBroadcastSuccessWhenControllableTargetsSucceed(): void
    {
        $gateway = new class extends IpcControlGateway {
            public array $instances = [];

            public function cacheClear(string $instanceName, float $timeout = 3.0): array
            {
                $this->instances[] = $instanceName;
                return ['success' => true, 'message' => 'ok', 'data' => []];
            }
        };

        $manager = new class extends ServerInstanceManager {
            public function listPersistedInstanceNames(): array
            {
                return ['alpha', 'stale-master'];
            }

            public function hasInstance(string $name): bool
            {
                return true;
            }

            public function isInstanceIpcControllable(string $name): bool
            {
                return $name === 'alpha';
            }
        };

        $service = new BroadcastControlDispatchService($gateway, $manager);
        $result = $service->cacheClear();

        $this->assertSame(['alpha'], $gateway->instances);
        $this->assertTrue($result['success']);
        $this->assertSame(['alpha'], $result['attempted']);
        $this->assertSame(['alpha'], $result['succeeded']);
        $this->assertSame([], $result['failed_by_instance']);
        $this->assertArrayHasKey('stale-master', $result['skipped_by_instance']);
        $this->assertStringContainsString('可控 WLS 实例', $result['message']);
        $this->assertStringContainsString('跳过', $result['message']);
    }
}
