<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\GlobalRateLimiter;

require_once \dirname(__DIR__, 7) . '/app/bootstrap_phpunit.php';

/**
 * T-BAN: 127.0.0.0/8 and ::1 must never enter / match shared_ban.
 */
final class GlobalRateLimiterLoopbackBanTest extends TestCase
{
    public function testBanAndIsBannedShortCircuitLoopbackAddresses(): void
    {
        $instance = 'loopback-ban-' . \bin2hex(\random_bytes(4));
        $limiter = new GlobalRateLimiter(null, 1, $instance);

        foreach (['127.0.0.1', '127.0.0.2', '127.255.255.255', '::1'] as $ip) {
            $limiter->ban($ip, 600);
            self::assertFalse(
                $limiter->isBanned($ip),
                "loopback {$ip} must not be banned",
            );
            self::assertFalse(
                GlobalRateLimiter::applyBanDelta($instance, $ip, \time() + 600),
                "loopback {$ip} ban delta must be rejected",
            );
            self::assertTrue(GlobalRateLimiter::isLoopbackIp($ip));
        }
    }

    public function testNonLoopbackBanStillWorks(): void
    {
        $instance = 'loopback-ban-' . \bin2hex(\random_bytes(4));
        $limiter = new GlobalRateLimiter(null, 1, $instance);
        $ip = '203.0.113.77';

        $limiter->ban($ip, 600);
        self::assertTrue($limiter->isBanned($ip));
        self::assertTrue($limiter->clearBans($ip));
        self::assertFalse($limiter->isBanned($ip));
        self::assertFalse(GlobalRateLimiter::isLoopbackIp($ip));
    }
}
