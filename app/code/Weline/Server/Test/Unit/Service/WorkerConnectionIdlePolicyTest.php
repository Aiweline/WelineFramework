<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\ClientTcpKeepAliveTuner;
use Weline\Server\Service\WorkerConnectionIdlePolicy;

final class WorkerConnectionIdlePolicyTest extends TestCase
{
    public function testEcommerceDefaultsAreStorefrontOriented(): void
    {
        self::assertSame(45, WorkerConnectionIdlePolicy::DEFAULT_KEEP_ALIVE_SEC);
        self::assertSame(20, WorkerConnectionIdlePolicy::DEFAULT_WRITE_STALL_SEC);
        self::assertSame(3, WorkerConnectionIdlePolicy::DEFAULT_TIMEOUT_CHECK_INTERVAL_SEC);
        self::assertSame(15, ClientTcpKeepAliveTuner::IDLE_SEC);
        self::assertSame(5, ClientTcpKeepAliveTuner::INTERVAL_SEC);
        self::assertSame(3, ClientTcpKeepAliveTuner::PROBES);
    }

    public function testIdleConnectionClosesAfterKeepAliveBudget(): void
    {
        self::assertSame(
            WorkerConnectionIdlePolicy::ACTION_CLOSE_IDLE,
            WorkerConnectionIdlePolicy::decide(45.0, 45.0, 20.0, false, false),
        );
        self::assertSame(
            WorkerConnectionIdlePolicy::ACTION_KEEP,
            WorkerConnectionIdlePolicy::decide(44.0, 45.0, 20.0, false, false),
        );
    }

    public function testBufferedResponseUsesWriteStallBudgetNotTripleKeepAlive(): void
    {
        self::assertSame(
            WorkerConnectionIdlePolicy::ACTION_KEEP,
            WorkerConnectionIdlePolicy::decide(19.0, 45.0, 20.0, true, false),
        );
        self::assertSame(
            WorkerConnectionIdlePolicy::ACTION_CLOSE_STALL,
            WorkerConnectionIdlePolicy::decide(20.0, 45.0, 20.0, true, false),
        );
    }

    public function testActiveRequestWithoutResponseUsesStallBudget(): void
    {
        self::assertSame(
            WorkerConnectionIdlePolicy::ACTION_KEEP,
            WorkerConnectionIdlePolicy::decide(44.0, 45.0, 20.0, false, true),
        );
        self::assertSame(
            WorkerConnectionIdlePolicy::ACTION_CLOSE_STALL,
            WorkerConnectionIdlePolicy::decide(45.0, 45.0, 20.0, false, true),
        );
    }

    public function testWriteStallUsesProgressIdleNotControlFrameActivity(): void
    {
        // PING/empty-read can keep activityIdle low while response bytes stall.
        self::assertSame(
            WorkerConnectionIdlePolicy::ACTION_KEEP,
            WorkerConnectionIdlePolicy::decide(2.0, 45.0, 20.0, true, false, 19.0),
        );
        self::assertSame(
            WorkerConnectionIdlePolicy::ACTION_CLOSE_STALL,
            WorkerConnectionIdlePolicy::decide(2.0, 45.0, 20.0, true, false, 20.0),
        );
        self::assertSame(
            WorkerConnectionIdlePolicy::ACTION_CLOSE_STALL,
            WorkerConnectionIdlePolicy::decide(2.0, 45.0, 20.0, false, true, 45.0),
        );
    }

    public function testKeepAlivePlanPrefersPlatformIdleOption(): void
    {
        $plan = ClientTcpKeepAliveTuner::plan();
        self::assertTrue($plan['so_keepalive']);
        self::assertSame(ClientTcpKeepAliveTuner::IDLE_SEC, $plan['idle_sec']);
        if (\defined('TCP_KEEPIDLE')) {
            self::assertSame('TCP_KEEPIDLE', $plan['idle_option']);
        } elseif (\defined('TCP_KEEPALIVE')) {
            self::assertSame('TCP_KEEPALIVE', $plan['idle_option']);
        } else {
            self::assertNull($plan['idle_option']);
        }
    }
}
