<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 3) . '/bin/worker_runtime_common.php';

final class FiberAdmissionQueueContractTest extends TestCase
{
    public function testResolveDefaultsPreferQueueNotUnlimited(): void
    {
        $cfg = wlsResolveFiberAdmissionConfig([]);
        self::assertSame(12, $cfg['max_active']);
        self::assertSame(8000, $cfg['queue_wait_ms']);
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

    public function testWaitMsClampedToTenSeconds(): void
    {
        $cfg = wlsResolveFiberAdmissionConfig([
            'fiber' => ['admission_queue_wait_ms' => 60000],
        ]);
        self::assertSame(10000, $cfg['queue_wait_ms']);
    }
}
