<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;

require_once \dirname(__DIR__, 3) . '/bin/worker_runtime_common.php';

final class FiberAdmissionQueueContractTest extends TestCase
{
    public function testResolveDefaultsPreferQueueNotUnlimited(): void
    {
        $cfg = wlsResolveFiberAdmissionConfig([]);
        self::assertSame(12, $cfg['max_active']);
        self::assertSame(10000, $cfg['queue_wait_ms']);
        self::assertSame(12, $cfg['queue_depth']);
        self::assertSame(25, $cfg['queue_slice_ms']);
    }

    public function testExplicitZeroMaxActiveKeepsUnlimited(): void
    {
        $cfg = wlsResolveFiberAdmissionConfig(['fiber' => ['max_active' => 0]]);
        self::assertSame(0, $cfg['max_active']);
        self::assertTrue(wlsFiberAdmissionQueueHasRoom(100, 24, 0));
    }

    public function testQueueHasRoomUntilDepth(): void
    {
        self::assertTrue(wlsFiberAdmissionQueueHasRoom(0, 24, 8));
        self::assertTrue(wlsFiberAdmissionQueueHasRoom(23, 24, 8));
        self::assertFalse(wlsFiberAdmissionQueueHasRoom(24, 24, 8));
    }

    public function testActiveCountExcludesWaitersAndSse(): void
    {
        $active = [
            1 => ['is_sse_protocol' => false],
            2 => ['is_sse_protocol' => true],
            3 => ['is_sse_protocol' => false, 'admission_waiting' => true],
            4 => ['is_sse_protocol' => false],
        ];
        $waiters = [4 => true];
        self::assertSame(1, wlsCountActiveFibersForAdmission($active, $waiters));
        self::assertSame(1, wlsCountFiberAdmissionWaiters($waiters));
    }

    public function testUnavailableResponseMarksAdmissionReason(): void
    {
        $resp = wlsFiberAdmissionUnavailableResponse('timeout');
        self::assertStringContainsString('503 Service Unavailable', $resp);
        self::assertStringContainsString('X-WLS-Admission: timeout', $resp);
        self::assertStringContainsString('Retry-After: 2', $resp);

        $full = wlsFiberAdmissionUnavailableResponse('queue_full');
        self::assertStringContainsString('X-WLS-Admission: queue_full', $full);
        self::assertStringContainsString('Retry-After: 1', $full);
    }

    public function testWaitMsIsBoundedToTenSeconds(): void
    {
        $configured = wlsResolveFiberAdmissionConfig([
            'fiber' => ['admission_queue_wait_ms' => 5000],
        ]);
        self::assertSame(5000, $configured['queue_wait_ms']);

        $cfg = wlsResolveFiberAdmissionConfig([
            'fiber' => ['admission_queue_wait_ms' => 45000],
        ]);
        self::assertSame(10000, $cfg['queue_wait_ms']);

        $moduleEnv = require \dirname(__DIR__, 3) . '/etc/env.php';
        self::assertSame(10000, $moduleEnv['wls']['fiber']['admission_queue_wait_ms']);
    }

    public function testWaitingRequestCanFinishFromCacheBeforeAdmissionSlotOpens(): void
    {
        $active = [
            'busy' => ['is_sse_protocol' => false],
            'waiting' => ['is_sse_protocol' => false],
        ];
        $waiters = ['waiting' => true];
        $probes = 0;

        $result = wlsAwaitFiberAdmissionSlot(
            $active,
            $waiters,
            'waiting',
            1,
            100,
            5,
            static function () use (&$probes): bool {
                $probes++;
                return true;
            },
            10,
        );

        self::assertNull($result);
        self::assertSame(1, $probes);
        self::assertSame([], $waiters);
        self::assertFalse($active['waiting']['admission_waiting']);
    }

    public function testWaitingCacheProbesAreSingleFlightWithinWorker(): void
    {
        $nestedRan = false;
        $outer = wlsTryFiberAdmissionCacheProbe(static function () use (&$nestedRan): bool {
            $nested = wlsTryFiberAdmissionCacheProbe(static function () use (&$nestedRan): bool {
                $nestedRan = true;
                return true;
            });
            self::assertFalse($nested);
            return true;
        });

        self::assertTrue($outer);
        self::assertFalse($nestedRan);
        self::assertTrue(wlsTryFiberAdmissionCacheProbe(static fn (): bool => true));
    }

    public function testWaitingCacheProbeReturnsItsIdleDatabaseConnection(): void
    {
        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn('sqlite');
        $config->method('getHostName')->willReturn('local');
        $config->method('getHostPort')->willReturn(0);
        $config->method('getDatabase')->willReturn('wls_admission_probe');
        $config->method('getUsername')->willReturn('test');
        $config->method('getPoolSize')->willReturn(1);

        $lease = null;
        try {
            self::assertFalse(wlsTryFiberAdmissionCacheProbe(static function () use ($config, &$lease): bool {
                $lease = ConnectionPool::acquire($config, static fn (): \PDO => new \PDO('sqlite::memory:'));
                return false;
            }));
            self::assertSame(0, ConnectionPool::getPoolStats($config)['in_use']);
            self::assertSame(1, ConnectionPool::getPoolStats($config)['available']);
        } finally {
            ConnectionPool::closePool();
        }
    }

    public function testInstanceAdmissionPolicyIsLoadedFromEnvironmentBeforeWorkerStarts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wls-fiber-policy-');
        self::assertIsString($path);
        try {
            file_put_contents($path, '<?php return ' . var_export([
                'wls' => [
                    'fiber' => ['max_active' => 0],
                    'servers' => ['soak' => ['fiber' => [
                        'max_active' => 12,
                        'admission_queue_wait_ms' => 45000,
                        'admission_queue_depth' => 256,
                    ]]],
                ],
            ], true) . ';');

            $cfg = wlsResolveFiberAdmissionConfigFromEnvironment($path, 'soak');
            self::assertSame(12, $cfg['max_active']);
            self::assertSame(10000, $cfg['queue_wait_ms']);
            self::assertSame(256, $cfg['queue_depth']);
        } finally {
            @unlink($path);
        }
    }
}
