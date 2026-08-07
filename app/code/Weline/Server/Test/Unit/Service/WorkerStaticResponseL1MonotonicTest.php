<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\WorkerPolicyDecision;
use Weline\Server\Service\WorkerStaticResponseL1;

final class WorkerStaticResponseL1MonotonicTest extends TestCase
{
    protected function setUp(): void
    {
        WorkerStaticResponseL1::clear();
    }

    protected function tearDown(): void
    {
        WorkerStaticResponseL1::clear();
    }

    public function testPublishOwnsAMonotonicDeadlineAndLookupReturnsTheFreshResponse(): void
    {
        $beforeMonotonic = (float)\hrtime(true) / 1_000_000_000;

        WorkerStaticResponseL1::publish(
            '/assets/app.css',
            "HTTP/1.1 200 OK\r\nContent-Length: 3\r\nX-WLS-Static-Cache: MISS\r\nConnection: keep-alive\r\n\r\ncss",
            '/project/pub/assets/app.css',
            '"asset-etag"',
            'Thu, 01 Jan 2026 00:00:00 GMT',
            60,
        );

        $afterMonotonic = (float)\hrtime(true) / 1_000_000_000;
        $entry = $this->onlyEntry();
        $cachedAt = (float)($entry['cached_at'] ?? -1.0);
        $expiresAt = (float)($entry['expires_at'] ?? -1.0);

        self::assertGreaterThanOrEqual($beforeMonotonic, $cachedAt);
        self::assertLessThanOrEqual($afterMonotonic, $cachedAt);
        self::assertEqualsWithDelta($cachedAt + 60.0, $expiresAt, 0.000_001);
        self::assertGreaterThan(1_000_000.0, \abs((float)\time() - $cachedAt));

        $response = WorkerStaticResponseL1::lookup($this->staticGetDecision());
        self::assertIsString($response);
        self::assertStringContainsString('X-WLS-Static-Cache: HIT', $response);
        self::assertStringEndsWith("\r\n\r\ncss", $response);
    }

    /** @return array<string,mixed> */
    private function onlyEntry(): array
    {
        $reflection = new \ReflectionClass(WorkerStaticResponseL1::class);
        $property = $reflection->getProperty('entries');
        $entries = $property->getValue();

        self::assertIsArray($entries);
        self::assertCount(1, $entries);
        $entry = \reset($entries);
        self::assertIsArray($entry);

        return $entry;
    }

    private function staticGetDecision(): WorkerPolicyDecision
    {
        return WorkerPolicyDecision::allow(
            clientIp: '127.0.0.1',
            method: 'GET',
            protocol: 'HTTP/1.1',
            target: '/assets/app.css',
            path: '/assets/app.css',
            headers: ['connection' => 'keep-alive'],
            body: '',
            policyDigest: \str_repeat('a', 64),
            trustedProxy: false,
            cachePolicyFlags: WorkerPolicyDecision::CACHE_STATIC_PROCESS_L1,
        );
    }
}
