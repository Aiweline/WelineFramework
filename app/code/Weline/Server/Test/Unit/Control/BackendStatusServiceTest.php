<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Control;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Control\BackendStatusService;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Shared\Client\SharedStateClient;

final class BackendStatusServiceTest extends TestCase
{
    public function testSidecarHealthChecksUseRuntimeTokenFileNameFromMetadata(): void
    {
        $gateway = new class extends IpcControlGateway {
            public function getStatus(string $instanceName = 'default', float $timeout = 4.0): array
            {
                return [
                    'success' => true,
                    'message' => 'ok',
                    'data' => [
                        'services' => [
                            'session_server' => [
                                'display_name' => 'Session Server',
                                'instances' => [
                                    1 => [
                                        'instance_id' => 1,
                                        'pid' => 1001,
                                        'port' => 19970,
                                        'state' => 'ready',
                                        'metadata' => [
                                            'token_file_name' => 'session_server.custom.token',
                                        ],
                                    ],
                                ],
                            ],
                            'memory_server' => [
                                'display_name' => 'Memory Server',
                                'instances' => [
                                    1 => [
                                        'instance_id' => 1,
                                        'pid' => 1002,
                                        'port' => 19971,
                                        'state' => 'ready',
                                        'metadata' => [
                                            'token_file_name' => 'memory_server.custom.token',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        $service = new class($gateway) extends BackendStatusService {
            public array $stateClientCalls = [];

            protected function buildStateClient(int $port, string $tokenFileName): ?SharedStateClient
            {
                $this->stateClientCalls[] = [$port, $tokenFileName];

                return new class extends SharedStateClient {
                    public function __construct()
                    {
                    }

                    public function request(string $cmd, array $params = []): ?array
                    {
                        return ['ok' => true, 'data' => ['client_count' => 1, 'session_count' => 2]];
                    }
                };
            }
        };

        $dto = $service->getStatusDto('blue', true);

        self::assertTrue($dto['success']);
        self::assertSame(
            [
                [19970, 'session_server.custom.token'],
                [19971, 'memory_server.custom.token'],
            ],
            $service->stateClientCalls
        );
    }

    public function testSidecarHealthChecksFallBackToDefaultTokenNames(): void
    {
        $gateway = new class extends IpcControlGateway {
            public function getStatus(string $instanceName = 'default', float $timeout = 4.0): array
            {
                return [
                    'success' => true,
                    'message' => 'ok',
                    'data' => [
                        'services' => [
                            'session_server' => [
                                'display_name' => 'Session Server',
                                'instances' => [
                                    1 => [
                                        'instance_id' => 1,
                                        'pid' => 1001,
                                        'port' => 20970,
                                        'state' => 'ready',
                                        'metadata' => [],
                                    ],
                                ],
                            ],
                            'memory_server' => [
                                'display_name' => 'Memory Server',
                                'instances' => [
                                    1 => [
                                        'instance_id' => 1,
                                        'pid' => 1002,
                                        'port' => 20971,
                                        'state' => 'ready',
                                        'metadata' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        $service = new class($gateway) extends BackendStatusService {
            public array $stateClientCalls = [];

            protected function buildStateClient(int $port, string $tokenFileName): ?SharedStateClient
            {
                $this->stateClientCalls[] = [$port, $tokenFileName];

                return new class extends SharedStateClient {
                    public function __construct()
                    {
                    }

                    public function request(string $cmd, array $params = []): ?array
                    {
                        return ['ok' => true, 'data' => ['client_count' => 3, 'session_count' => 5]];
                    }
                };
            }
        };

        $dto = $service->getStatusDto('blue', true);

        self::assertTrue($dto['success']);
        self::assertSame(
            [
                [20970, 'session_server.token'],
                [20971, 'memory_server.token'],
            ],
            $service->stateClientCalls
        );
    }
}