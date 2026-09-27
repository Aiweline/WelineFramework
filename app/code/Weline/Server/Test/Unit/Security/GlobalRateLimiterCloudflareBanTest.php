<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\CloudflareTrustedProxyCatalog;
use Weline\Server\Security\GlobalRateLimiter;

require_once \dirname(__DIR__, 7) . '/app/bootstrap_phpunit.php';

/**
 * T-BAN-CF: Cloudflare edge CIDRs must never enter / match shared_ban.
 */
final class GlobalRateLimiterCloudflareBanTest extends TestCase
{
    public function testBanAndIsBannedShortCircuitCloudflareEdges(): void
    {
        $instance = 'cf-ban-' . \bin2hex(\random_bytes(4));
        $limiter = new GlobalRateLimiter(null, 1, $instance);
        $edges = ['104.22.83.24', '162.158.202.44', '172.64.0.1'];

        foreach ($edges as $ip) {
            self::assertTrue(
                CloudflareTrustedProxyCatalog::contains($ip),
                "{$ip} must match published CF catalog",
            );
            $limiter->ban($ip, 600);
            self::assertFalse(
                $limiter->isBanned($ip),
                "CF edge {$ip} must not be banned",
            );
            self::assertFalse(
                GlobalRateLimiter::applyBanDelta($instance, $ip, \time() + 600),
                "CF edge {$ip} ban delta must be rejected",
            );
            self::assertTrue(GlobalRateLimiter::isCloudflareEdgeIp($ip));
        }
    }

    public function testNonCloudflareClientBanStillWorks(): void
    {
        $instance = 'cf-ban-' . \bin2hex(\random_bytes(4));
        $limiter = new GlobalRateLimiter(null, 1, $instance);
        $ip = '203.0.113.88';

        $limiter->ban($ip, 600);
        self::assertTrue($limiter->isBanned($ip));
        self::assertTrue($limiter->clearBans($ip));
        self::assertFalse($limiter->isBanned($ip));
        self::assertFalse(GlobalRateLimiter::isCloudflareEdgeIp($ip));
    }
}
