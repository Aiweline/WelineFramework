<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Exception\ExceptionFingerprint;
use Weline\Framework\Exception\ExceptionLogThrottle;

final class ExceptionLogThrottleTest extends TestCase
{
    protected function tearDown(): void
    {
        // Best-effort cleanup of keys created in this process.
        parent::tearDown();
    }

    public function testFloodKeyCollapsesAiConnectWrappers(): void
    {
        $provider = new \RuntimeException(
            'API请求失败: Failed to connect to 127.0.0.1 port 11434 after 0 ms: Could not connect to server'
        );
        $wrapped = new \RuntimeException(
            'AI生成失败: API请求失败: Failed to connect to 127.0.0.1 port 11434 after 1 ms: Could not connect to server',
            0,
            $provider
        );

        self::assertSame(
            ExceptionFingerprint::generateFloodKey($provider),
            ExceptionFingerprint::generateFloodKey($wrapped)
        );
        self::assertSame(
            'connect_failed:127.0.0.1:11434',
            ExceptionFingerprint::normalizeFloodMessage($provider->getMessage())
        );
        self::assertSame(
            'AI_TRANSLATION_BUSY',
            ExceptionFingerprint::normalizeFloodMessage('AI_TRANSLATION_BUSY: busy lane')
        );
    }

    public function testThrottleSuppressesRepeatsWithinWindow(): void
    {
        $e = new \RuntimeException(
            'API请求失败: Failed to connect to 127.0.0.1 port 11434 after 0 ms: Could not connect to server'
        );
        $key = ExceptionFingerprint::generateFloodKey($e);
        ExceptionLogThrottle::resetForTests($key);

        $first = ExceptionLogThrottle::decide($e, 600);
        self::assertTrue($first['allow']);
        self::assertSame(0, $first['suppressed_before']);
        self::assertNull($first['summary']);

        $second = ExceptionLogThrottle::decide($e, 600);
        self::assertFalse($second['allow']);
        self::assertGreaterThanOrEqual(1, $second['suppressed_before']);

        $third = ExceptionLogThrottle::decide($e, 600);
        self::assertFalse($third['allow']);

        ExceptionLogThrottle::resetForTests($key);
    }

    public function testHeartbeatAllowsCompactSummaryEveryHundred(): void
    {
        $e = new \RuntimeException('unique-flood-heartbeat-' . uniqid('x', true));
        $key = ExceptionFingerprint::generateFloodKey($e);
        ExceptionLogThrottle::resetForTests($key);

        self::assertTrue(ExceptionLogThrottle::decide($e, 600)['allow']);

        $sawHeartbeat = false;
        for ($i = 0; $i < ExceptionLogThrottle::HEARTBEAT_EVERY; $i++) {
            $d = ExceptionLogThrottle::decide($e, 600);
            if (!empty($d['summary']) && str_contains((string)$d['summary'], 'still repeating')) {
                $sawHeartbeat = true;
                self::assertTrue($d['allow']);
                break;
            }
        }
        self::assertTrue($sawHeartbeat, 'expected heartbeat summary within HEARTBEAT_EVERY suppressions');

        ExceptionLogThrottle::resetForTests($key);
    }
}
