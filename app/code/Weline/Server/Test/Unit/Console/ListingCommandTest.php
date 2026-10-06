<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

if (!\function_exists(__NAMESPACE__ . '\__')) {
    function __(string $text, array $args = []): string
    {
        return $text;
    }
}

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Listing;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Runtime\RuntimeSelection;

/**
 * server:listing must not report a live Master as stopped when IPC brief times out.
 */
final class ListingCommandTest extends TestCase
{
    public function testMasterRuntimeStateFallsBackToManagedPidWhenIpcIsUnavailable(): void
    {
        $listing = new class extends Listing {
            public function __construct()
            {
            }

            /**
             * @param array<int, array{pid: int, exists: bool, name?: string, command?: string, memory?: string, cpu?: string, start_time?: string}> $processInfoMap
             * @return array{running: bool, ipc_ok: bool, source: string, message: string}
             */
            public function state(ServerInstanceInfo $info, array $processInfoMap): array
            {
                return $this->resolveMasterRuntimeState($info, $processInfoMap);
            }

            public function running(ServerInstanceInfo $info, array $processInfoMap): bool
            {
                return $this->isInstanceRunning($info, $processInfoMap);
            }
        };

        $info = new ServerInstanceInfo(
            name: 'wls-main-validate',
            masterPid: 63112,
            controlPort: 0,
            host: '127.0.0.1',
            port: 19987,
            sslEnabled: false,
            runtimeSelection: self::runtimeSelection(),
            workerCount: 4,
            workerBasePort: 19987,
            httpRedirectPort: 0,
            startedAt: '2026-10-05 01:06:58',
            startedTimestamp: 1759602418,
            services: [],
        );

        $processInfoMap = [
            63112 => ['pid' => 63112, 'exists' => true],
        ];

        $state = $listing->state($info, $processInfoMap);

        self::assertTrue($state['running']);
        self::assertFalse($state['ipc_ok']);
        self::assertSame('pid', $state['source']);
        self::assertNotSame('', $state['message']);
        self::assertTrue($listing->running($info, $processInfoMap));
    }

    public function testMasterWithoutPidIsNotReportedRunning(): void
    {
        $listing = new class extends Listing {
            public function __construct()
            {
            }

            public function running(ServerInstanceInfo $info, array $processInfoMap): bool
            {
                return $this->isInstanceRunning($info, $processInfoMap);
            }
        };

        $info = new ServerInstanceInfo(
            name: 'ghost',
            masterPid: 0,
            controlPort: 46294,
            host: '127.0.0.1',
            port: 19987,
            sslEnabled: false,
            runtimeSelection: self::runtimeSelection(),
            workerCount: 1,
            workerBasePort: 19987,
            httpRedirectPort: 0,
            startedAt: '2026-10-05 01:06:58',
            startedTimestamp: 1759602418,
            services: [],
        );

        self::assertFalse($listing->running($info, []));
    }

    public function testManagedNginxPortSummarySeparatesWorkerAndPublicListen(): void
    {
        $listing = new class extends Listing {
            public function __construct()
            {
            }

            public function ports(string $edgeFace, ServerInstanceInfo $info, array $raw): array
            {
                return $this->resolvePortPresentation(
                    $edgeFace,
                    $info,
                    $raw,
                    $info->workerCount
                );
            }

            public function topology(ServerInstanceInfo $info, array $raw): array
            {
                return $this->resolveTopologyPresentation($info, $raw);
            }

            /**
             * @return array{edge_pid:?int,edge_owner:string,edge_owner_self:bool}
             */
            public function identity(string $edgeFace, string $instanceName, array $raw): array
            {
                return $this->resolveEdgeIdentity($edgeFace, $instanceName, $raw);
            }

            protected function managedNginxSnapshot(): ?array
            {
                return [
                    'runtime_owner_active' => true,
                    'owner_instance' => 'default',
                    'listen_http' => 80,
                    'listen_https' => 443,
                    'pid' => 4242,
                    'running' => true,
                ];
            }
        };

        $info = new ServerInstanceInfo(
            name: 'default',
            masterPid: 11388,
            controlPort: 35862,
            host: '127.0.0.1',
            port: 9555,
            sslEnabled: false,
            runtimeSelection: self::runtimeSelection('direct'),
            workerCount: 4,
            workerBasePort: 9555,
            httpRedirectPort: 0,
            startedAt: '2026-10-06 01:17:16',
            startedTimestamp: 1759703836,
            services: [],
        );

        $ports = $listing->ports('managed_nginx', $info, []);
        self::assertSame(9555, $ports['worker_port']);
        self::assertSame(80, $ports['edge_http_port']);
        self::assertSame(443, $ports['edge_https_port']);
        self::assertSame('Worker:9555(4) nG:80/443', $ports['port_summary']);

        $topology = $listing->topology($info, []);
        self::assertSame('direct', $topology['topology']);
        self::assertSame('直连', $topology['topology_label']);

        $identity = $listing->identity('managed_nginx', 'default', []);
        self::assertSame(4242, $identity['edge_pid']);
        self::assertSame('default', $identity['edge_owner']);
        self::assertTrue($identity['edge_owner_self']);
    }

    public function testDispatcherTopologyLabelAndGatewayPorts(): void
    {
        $listing = new class extends Listing {
            public function __construct()
            {
            }

            public function ports(string $edgeFace, ServerInstanceInfo $info, array $raw): array
            {
                return $this->resolvePortPresentation(
                    $edgeFace,
                    $info,
                    $raw,
                    $info->workerCount
                );
            }

            public function topology(ServerInstanceInfo $info, array $raw): array
            {
                return $this->resolveTopologyPresentation($info, $raw);
            }
        };

        $info = new ServerInstanceInfo(
            name: 'gw',
            masterPid: 1,
            controlPort: 1,
            host: '127.0.0.1',
            port: 9555,
            sslEnabled: false,
            runtimeSelection: self::runtimeSelection('dispatcher'),
            workerCount: 2,
            workerBasePort: 9555,
            httpRedirectPort: 0,
            startedAt: '2026-10-06 01:17:16',
            startedTimestamp: 1759703836,
            services: [],
        );

        $topology = $listing->topology($info, []);
        self::assertSame('dispatcher', $topology['topology']);
        self::assertSame('派遣器', $topology['topology_label']);

        $ports = $listing->ports('gateway', $info, [
            'gateway' => [
                'public_http' => 8080,
                'public_https' => 8443,
            ],
        ]);
        self::assertSame('Worker:9555(2) GW:8080/8443', $ports['port_summary']);
    }

    public function testMergedRuntimeAndProcColumns(): void
    {
        $listing = new class extends Listing {
            public function __construct()
            {
            }

            public function runtime(array $status, string $type): string
            {
                return $this->formatRuntimeSummaryColumn($status, $type);
            }

            /** @return string[] */
            public function runtimeLines(array $status, string $type): array
            {
                return $this->formatRuntimeSummaryLines($status, $type);
            }

            /** @return string[] */
            public function kindLines(array $status, string $type): array
            {
                return $this->formatKindSummaryLines($status, $type);
            }

            public function proc(array $status): string
            {
                return $this->formatProcSummaryColumn($status);
            }

            /** @return string[] */
            public function procLines(array $status): array
            {
                return $this->formatProcSummaryLines($status);
            }
        };

        self::assertSame(
            ['[WLS]'],
            $listing->kindLines([
                'edge_face_label' => '[nG]',
            ], 'weline')
        );
        self::assertSame(
            ['直连', 'nG@default', 'Worker:9555(4)', 'nG:80/443'],
            $listing->runtimeLines([
                'topology_label' => '直连',
                'edge_face' => 'managed_nginx',
                'edge_owner' => 'default',
                'port_summary' => 'Worker:9555(4) nG:80/443',
                'port_lines' => ['Worker:9555(4)', 'nG:80/443'],
            ], 'weline')
        );
        self::assertSame(
            '直连 · nG@default · Worker:9555(4) · nG:80/443',
            $listing->runtime([
                'topology_label' => '直连',
                'edge_face' => 'managed_nginx',
                'edge_owner' => 'default',
                'port_summary' => 'Worker:9555(4) nG:80/443',
                'port_lines' => ['Worker:9555(4)', 'nG:80/443'],
            ], 'weline')
        );
        self::assertSame(
            ['PID:46450 · 8m', 'nG:4242'],
            $listing->procLines([
                'pid' => 46450,
                'running_time' => '8m',
                'edge_face' => 'managed_nginx',
                'edge_pid' => 4242,
            ])
        );
        self::assertSame(
            'PID:46450 · 8m',
            $listing->proc([
                'pid' => 46450,
                'running_time' => '8m',
            ])
        );
    }

    private static function runtimeSelection(string $effectiveTopology = 'direct'): RuntimeSelection
    {
        $listenerMode = $effectiveTopology === 'dispatcher'
            ? 'single'
            : (PHP_OS_FAMILY === 'Windows' ? 'worker_ports' : 'shared_fd');

        return RuntimeSelection::fromArray([
            'requested_topology' => $effectiveTopology,
            'effective_topology' => $effectiveTopology,
            'topology_source' => 'unit-test',
            'os_family' => PHP_OS_FAMILY,
            'event_loop_driver' => 'select',
            'ssl_engine' => 'stream',
            'listener_mode' => $listenerMode,
            'policy_compatible' => true,
            'reason_codes' => ['unit_test'],
            'reason' => 'unit test runtime selection',
        ]);
    }
}
