<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayAutoDiscoveryBackoff;

final class GatewayAutoDiscoveryBackoffTest extends TestCase
{
    public function testFailuresUseMonotonicExponentialDelayWithBoundedJitter(): void
    {
        $now = 100.0;
        $jitter = [-200, 0, 200];
        $schedule = new GatewayAutoDiscoveryBackoff(
            static function () use (&$now): float {
                return $now;
            },
            static function (int $minimum, int $maximum) use (&$jitter): int {
                self::assertSame(-200, $minimum);
                self::assertSame(200, $maximum);
                return \array_shift($jitter) ?? 0;
            },
        );

        self::assertTrue($schedule->isDue());
        self::assertSame(104.0, $schedule->recordFailure());
        self::assertFalse($schedule->isDue());
        $now = 104.0;
        self::assertTrue($schedule->isDue());
        self::assertSame(114.0, $schedule->recordFailure());
        $now = 114.0;
        self::assertSame(138.0, $schedule->recordFailure());
        self::assertSame(3, $schedule->observation()['failure_streak']);
    }

    public function testTrustedDiscoveryResetsFailureSchedule(): void
    {
        $now = 50.0;
        $schedule = new GatewayAutoDiscoveryBackoff(
            static function () use (&$now): float {
                return $now;
            },
            static fn(int $minimum, int $maximum): int => 0,
        );

        self::assertSame(55.0, $schedule->recordFailure());
        $now = 51.0;
        $schedule->recordTrustedDiscovery();
        self::assertTrue($schedule->isDue());
        self::assertSame([
            'failure_streak' => 0,
            'next_attempt_at' => 51.0,
        ], $schedule->observation());
        self::assertSame(56.0, $schedule->recordFailure());
    }

    public function testBackoffNeverExceedsFiveMinutes(): void
    {
        $now = 1.0;
        $schedule = new GatewayAutoDiscoveryBackoff(
            static function () use (&$now): float {
                return $now;
            },
            static fn(int $minimum, int $maximum): int => 200,
        );
        for ($attempt = 0; $attempt < 32; $attempt++) {
            $next = $schedule->recordFailure();
            self::assertLessThanOrEqual($now + 300.0, $next);
            $now = $next;
        }
    }
}
