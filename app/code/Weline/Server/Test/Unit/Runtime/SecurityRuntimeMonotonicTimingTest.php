<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\AttackSignalService;
use Weline\Server\Security\ConnectionAcceptGate;
use Weline\Server\Security\ConnectionAcceptGatePool;
use Weline\Server\Security\GlobalRateLimiter;
use Weline\Server\Security\WorkerPolicyKernel;

final class SecurityRuntimeMonotonicTimingTest extends TestCase
{
    public function testAdmissionAndReconnectDurationsDoNotUseWallClockMicrotime(): void
    {
        foreach ([
            ConnectionAcceptGate::class,
            ConnectionAcceptGatePool::class,
            GlobalRateLimiter::class,
            WorkerPolicyKernel::class,
        ] as $class) {
            $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

            self::assertStringNotContainsString('\\microtime(true)', $source, $class);
            self::assertStringContainsString('\\hrtime(true)', $source, $class);
        }
    }

    public function testDistributedBanKeepsItsWallClockExpiryDomain(): void
    {
        $instance = 'policy-monotonic-' . \bin2hex(\random_bytes(6));
        $ip = '192.0.2.123';

        self::assertTrue(GlobalRateLimiter::applyBanDelta($instance, $ip, \time() + 60));

        $limiter = new GlobalRateLimiter(null, 1, $instance);
        self::assertTrue($limiter->isBanned($ip));
        self::assertTrue($limiter->clearBans($ip));
    }

    public function testAttackSignalWindowsAndModeDurationUseMonotonicTime(): void
    {
        $source = (string)\file_get_contents(
            (new \ReflectionClass(AttackSignalService::class))->getFileName(),
        );

        self::assertStringContainsString('private static float $attackModeActivatedMonotonic', $source);
        self::assertStringContainsString('private static float $lastCdnNotificationMonotonic', $source);
        self::assertStringNotContainsString('\\time() - self::$attackModeActivatedAt', $source);
        self::assertStringNotContainsString('\\time() - self::$lastCdnNotification', $source);

        AttackSignalService::activateAttackMode(30);
        $wall = new \ReflectionProperty(AttackSignalService::class, 'attackModeActivatedAt');
        $wall->setAccessible(true);
        $wall->setValue(null, PHP_INT_MAX);
        self::assertTrue(AttackSignalService::isUnderAttackMode());

        $monotonic = new \ReflectionProperty(AttackSignalService::class, 'attackModeActivatedMonotonic');
        $monotonic->setAccessible(true);
        $monotonic->setValue(null, (\hrtime(true) / 1_000_000_000) - 31.0);
        self::assertFalse(AttackSignalService::isUnderAttackMode());
        AttackSignalService::deactivateAttackMode();
    }
}
