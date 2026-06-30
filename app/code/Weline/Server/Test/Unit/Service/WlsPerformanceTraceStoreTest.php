<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\WlsPerformanceTraceStore;

final class WlsPerformanceTraceStoreTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'wls-panel-store-' . \bin2hex(\random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    public function testRecordsAndFetchesRecentRequestFromFileStore(): void
    {
        $store = $this->store(['max_recent' => 5]);

        self::assertTrue($store->record($this->telemetry('req-12345678'), [
            'request_id' => 'req-12345678',
            'method' => 'GET',
            'uri' => '/catalog/product/view',
            'host' => 'demo.weline.test',
            'status' => 200,
            'total_ms' => 128.45,
            'session_start_ms' => 7.2,
            'router_start_call_ms' => 19.5,
            'fpc_hit' => true,
            'fpc_source' => 'runtime',
            'worker_id' => '2',
            'worker_port' => '9512',
            'trace_top' => [
                [
                    'name' => 'wls.worker.handle',
                    'duration_ms' => 128.45,
                    'category' => 'wls',
                    'meta' => [
                        'operation' => 'handle',
                        'table' => '"public"."m_session"',
                        'query_type' => 'select',
                        'rows' => 1,
                        'token' => 'secret-token',
                    ],
                ],
            ],
        ]));

        $detail = $store->getDetail('req-12345678');
        self::assertSame('req-12345678', $detail['request_id'] ?? null);
        self::assertSame(128.45, $detail['total_ms'] ?? null);
        self::assertTrue((bool)($detail['fpc']['hit'] ?? false));

        $requests = $store->requests(10);
        self::assertCount(1, $requests);
        self::assertSame('req-12345678', $requests[0]['request_id'] ?? null);
        self::assertSame(7.2, $requests[0]['session_ms'] ?? null);

        $spans = $detail['trace']['spans'] ?? [];
        self::assertIsArray($spans);
        $spanMeta = $spans[0]['meta'] ?? [];
        self::assertSame('read', $spanMeta['operation'] ?? null);
        self::assertSame('checkout', $spanMeta['namespace'] ?? null);
        self::assertArrayNotHasKey('token', $spanMeta);
        self::assertArrayNotHasKey('password', $spanMeta);
        self::assertArrayNotHasKey('table', $spanMeta);
        self::assertArrayNotHasKey('query_type', $spanMeta);
        self::assertArrayNotHasKey('rows', $spanMeta);

        $timingTraceTop = $detail['timing']['trace_top'] ?? [];
        self::assertIsArray($timingTraceTop);
        $timingMeta = $timingTraceTop[0]['meta'] ?? [];
        self::assertSame('handle', $timingMeta['operation'] ?? null);
        self::assertArrayNotHasKey('table', $timingMeta);
        self::assertArrayNotHasKey('query_type', $timingMeta);
        self::assertArrayNotHasKey('rows', $timingMeta);
        self::assertArrayNotHasKey('token', $timingMeta);
    }

    public function testRingBufferAndSlowOnlyFiltering(): void
    {
        $store = $this->store([
            'max_recent' => 2,
            'slow_request_threshold_ms' => 100,
        ]);

        $store->record($this->telemetry('req-10000001'), ['request_id' => 'req-10000001', 'uri' => '/one', 'total_ms' => 40]);
        $store->record($this->telemetry('req-10000002'), ['request_id' => 'req-10000002', 'uri' => '/two', 'total_ms' => 140]);
        $store->record($this->telemetry('req-10000003'), ['request_id' => 'req-10000003', 'uri' => '/three', 'total_ms' => 220]);

        $requests = $store->requests(10);
        self::assertCount(2, $requests);
        self::assertSame('req-10000003', $requests[0]['request_id'] ?? null);
        self::assertSame('req-10000002', $requests[1]['request_id'] ?? null);

        $slow = $store->requests(10, 0, true);
        self::assertCount(2, $slow);
    }

    public function testExpiredFilePayloadIsNotReturned(): void
    {
        $store = $this->store(['ttl' => 60]);
        $store->record($this->telemetry('req-20000001'), ['request_id' => 'req-20000001', 'uri' => '/expired', 'total_ms' => 12]);

        foreach ((array)\glob($this->baseDir . \DIRECTORY_SEPARATOR . 'request' . \DIRECTORY_SEPARATOR . '*' . \DIRECTORY_SEPARATOR . '*.json') as $file) {
            $payload = \json_decode((string)\file_get_contents($file), true);
            if (\is_array($payload)) {
                $payload['expires_at'] = \time() - 1;
                \file_put_contents($file, \json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
        }

        self::assertSame([], $store->getDetail('req-20000001'));
    }

    public function testServicesAggregateSessionAndMemorySpans(): void
    {
        $store = $this->store();
        $store->record([
            'request' => ['request_id' => 'req-30000001', 'uri' => '/service'],
            'runtime' => ['instance' => 'default'],
            'trace' => [
                'spans' => [
                    ['name' => 'wls.session.connect', 'duration_ms' => 8.5, 'category' => 'wls', 'meta' => ['operation' => 'connect', 'service' => 'session']],
                    ['name' => 'wls.memory.get', 'duration_ms' => 3.25, 'category' => 'wls', 'meta' => ['operation' => 'get', 'service' => 'memory', 'namespace' => 'runtime']],
                ],
            ],
        ], ['request_id' => 'req-30000001', 'total_ms' => 20]);

        $services = $store->services('default');
        self::assertSame(1, $services['services']['session']['sample_count'] ?? null);
        self::assertSame(1, $services['services']['memory']['sample_count'] ?? null);
        self::assertSame(8.5, $services['services']['session']['max_ms'] ?? null);
        self::assertSame(3.25, $services['services']['memory']['max_ms'] ?? null);
    }

    private function store(array $config = []): WlsPerformanceTraceStore
    {
        return new WlsPerformanceTraceStore($config + [
            'force_file' => true,
            'base_dir' => $this->baseDir,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function telemetry(string $requestId): array
    {
        return [
            'request' => [
                'request_id' => $requestId,
                'uri' => '/',
                'method' => 'GET',
                'host' => 'demo.weline.test',
                'status' => 200,
            ],
            'runtime' => ['mode' => 'wls', 'instance' => 'default'],
            'summary' => ['total_duration_ms' => 12.5],
            'trace' => [
                'spans' => [
                    [
                        'name' => 'wls.session.read',
                        'duration_ms' => 4.4,
                        'category' => 'wls',
                        'meta' => [
                            'operation' => 'read',
                            'namespace' => 'checkout',
                            'token' => 'secret-token',
                            'password' => 'secret-password',
                            'table' => '"public"."m_session"',
                            'query_type' => 'select',
                            'rows' => 1,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function removeDirectory(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
        @\rmdir($path);
    }
}
