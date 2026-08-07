<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\TlsSessionCacheClient;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Session\Server\SessionServer;
use Weline\Server\Session\Server\SessionStore;

final class SessionRuntimeMonotonicTimingTest extends TestCase
{
    public function testSessionAndTlsIoDeadlinesUseMonotonicTime(): void
    {
        foreach ([SessionProtocol::class, TlsSessionCacheClient::class] as $class) {
            $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

            self::assertStringNotContainsString('\\microtime(true)', $source, $class);
            self::assertStringContainsString('\\hrtime(true)', $source, $class);
        }
    }

    public function testSessionServerUsesWallClockOnlyThroughItsAuditProjectionHelper(): void
    {
        $source = (string)\file_get_contents(
            (new \ReflectionClass(SessionServer::class))->getFileName(),
        );

        self::assertStringNotContainsString('\\microtime(true)', $source);
        self::assertStringContainsString('private function wallClockNow(): float', $source);
        self::assertStringContainsString("new \\DateTimeImmutable('now')", $source);
        self::assertStringContainsString('return \\hrtime(true) / 1_000_000_000.0;', $source);
        self::assertStringContainsString("'last_lease_refresh_monotonic'", $source);
        self::assertStringNotContainsString("'last_lease_refresh_at'", $source);
        self::assertStringNotContainsString('\\time()', $source);
    }

    public function testSessionStoreKeepsExpiryWallTimeSeparateFromRuntimeDurations(): void
    {
        $source = (string)\file_get_contents(
            (new \ReflectionClass(SessionStore::class))->getFileName(),
        );

        self::assertStringContainsString('private float $lastPersistMonotonic', $source);
        self::assertStringContainsString('private float $nextPersistRetryMonotonic', $source);
        self::assertStringContainsString('private float $startMonotonic', $source);
        self::assertStringNotContainsString('$elapsed = $now - $this->lastPersistTime;', $source);
        self::assertStringNotContainsString('\\time() - $this->startTime', $source);
        self::assertStringContainsString(
            '$this->nextPersistRetryMonotonic = self::monotonicSeconds()',
            $source,
        );
    }
}
