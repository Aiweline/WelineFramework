<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\WlsPanelGatewaySettingsService;

final class WlsPanelGatewaySettingsServiceTest extends TestCase
{
    public function testApplyRoutesRequiresExplicitTargetWhenMultipleGatewaysAreReady(): void
    {
        $gateway = $this->createRecordingGateway();
        $service = $this->createGatewaySettingsService(['gateway-a', 'gateway-b'], $gateway);

        $result = $service->applyRoutes([]);

        self::assertFalse($result['success']);
        self::assertStringContainsString('Gateway', (string)($result['message'] ?? ''));
        self::assertSame(['routes' => 1, 'gateways' => 2], $result['data'] ?? []);
        self::assertSame([], $gateway->proxyApplyCalls);
    }

    public function testApplyRoutesOnlySendsProxyApplyToExplicitSelectedGateway(): void
    {
        $gateway = $this->createRecordingGateway();
        $service = $this->createGatewaySettingsService(['gateway-a', 'gateway-b'], $gateway);

        $result = $service->applyRoutes(['gateway_instance' => 'gateway-b']);

        self::assertTrue($result['success']);
        self::assertSame('gateway-b', $result['selected_instance'] ?? null);
        self::assertSame(1, $result['route_count'] ?? null);
        self::assertCount(1, $gateway->proxyApplyCalls);
        self::assertSame('gateway-b', $gateway->proxyApplyCalls[0]['instance']);
        self::assertSame($this->fixtureRoutes(), $gateway->proxyApplyCalls[0]['routes']);
    }

    private function createGatewaySettingsService(array $instanceNames, IpcControlGateway $gateway): WlsPanelGatewaySettingsService
    {
        $manager = new class($instanceNames) extends ServerInstanceManager {
            /**
             * @param array<int, string> $instanceNames
             */
            public function __construct(private readonly array $instanceNames)
            {
            }

            public function getAllPersistedInstanceInfo(): array
            {
                $instances = [];
                foreach ($this->instanceNames as $index => $name) {
                    $instances[$name] = new ServerInstanceInfo(
                        name: $name,
                        masterPid: 1000 + $index,
                        controlPort: 9700 + $index,
                        host: '127.0.0.1',
                        port: 9600 + $index,
                        sslEnabled: false,
                        dispatcherEnabled: true,
                        workerCount: 1,
                        workerBasePort: 19600 + $index,
                        httpRedirectPort: 0,
                        startedAt: '2026-06-19 00:00:00',
                        startedTimestamp: 1781800000 + $index,
                        services: []
                    );
                }

                return $instances;
            }

            public function getRawInstanceData(string $name): ?array
            {
                if (!\in_array($name, $this->instanceNames, true)) {
                    return null;
                }

                return [
                    'gateway' => [
                        'enabled' => true,
                        'listen' => '127.0.0.1:' . (9800 + \array_search($name, $this->instanceNames, true)),
                    ],
                    'runtime' => [
                        'topology' => 'dispatcher',
                    ],
                ];
            }

            public function getMasterIpcStatusResult(string $name, float $timeout = 1.5): array
            {
                unset($timeout);
                if (!\in_array($name, $this->instanceNames, true)) {
                    return ['success' => false, 'message' => 'missing', 'data' => []];
                }

                return [
                    'success' => true,
                    'message' => 'ready',
                    'data' => [
                        'running' => true,
                        'services' => [
                            'gateway' => [
                                'instances' => [
                                    [
                                        'state' => 'ready',
                                        'port' => 9800 + \array_search($name, $this->instanceNames, true),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        return new class($manager, $gateway, $this->fixtureRoutes()) extends WlsPanelGatewaySettingsService {
            /**
             * @param array<int, array<string, mixed>> $routes
             */
            public function __construct(
                ServerInstanceManager $instanceManager,
                IpcControlGateway $ipcControlGateway,
                private readonly array $routes
            ) {
                parent::__construct($instanceManager, $ipcControlGateway);
            }

            protected function buildActiveRoutes(): array
            {
                return $this->routes;
            }
        };
    }

    private function createRecordingGateway(): IpcControlGateway
    {
        return new class extends IpcControlGateway {
            /**
             * @var array<int, array{instance:string,routes:array<int, array<string, mixed>>,timeout:float}>
             */
            public array $proxyApplyCalls = [];

            public function proxyApply(string $instanceName = 'default', array $routes = [], float $timeout = 5.0): array
            {
                $this->proxyApplyCalls[] = [
                    'instance' => $instanceName,
                    'routes' => $routes,
                    'timeout' => $timeout,
                ];

                return [
                    'success' => true,
                    'message' => 'applied to ' . $instanceName,
                    'data' => [],
                ];
            }
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fixtureRoutes(): array
    {
        return [
            [
                'domain' => 'wls-target.example.test',
                'backend_host' => '127.0.0.1',
                'backend_port' => 9960,
                'backend_ssl' => false,
                'priority' => 100,
            ],
        ];
    }
}
