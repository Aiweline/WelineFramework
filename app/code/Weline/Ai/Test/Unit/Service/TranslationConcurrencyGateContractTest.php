<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class TranslationConcurrencyGateContractTest extends TestCase
{
    public function testGateAndTranslationServiceContracts(): void
    {
        $root = dirname(__DIR__, 3);
        $gate = (string)file_get_contents($root . '/Service/TranslationConcurrencyGate.php');
        $service = (string)file_get_contents($root . '/Service/TranslationService.php');

        self::assertStringContainsString('class TranslationConcurrencyGate', $gate);
        self::assertStringContainsString('AI_TRANSLATION_BUSY', $gate);
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $gate);
        self::assertStringContainsString('DEFAULT_WAIT_SECONDS = 0', $gate);
        self::assertStringContainsString('LANE_DICTIONARY', $gate);
        self::assertStringContainsString('LANE_LOCAL_MODEL', $gate);
        self::assertStringContainsString('LANE_META', $gate);
        self::assertStringContainsString('LANE_FILE_ASSET', $gate);
        self::assertStringContainsString('LANE_DOCUMENT', $gate);
        self::assertStringContainsString("\$lane . '-translate.lock'", $gate);
        self::assertStringContainsString('machine-translate.lock', $gate);
        self::assertStringContainsString('depthByLane', $gate);
        self::assertStringContainsString('STALE_HOLD_SECONDS = 960', $gate);
        self::assertStringContainsString('disconnectStaleHolder', $gate);
        self::assertStringContainsString('posix_kill', $gate);
        self::assertStringContainsString('SchedulerSystem::sleep', $gate);
        // Dead/stale holder: retry flock instead of throwing BUSY after disconnect.
        self::assertStringContainsString('Retry', $gate);
        self::assertStringContainsString('fake occupancy', $gate);
        self::assertStringNotContainsString('已断开僵死占用进程，请稍后重试', $gate);
        self::assertStringNotContainsString("\nsleep(", $gate);
        self::assertStringNotContainsString('\\sleep(', $gate);
        self::assertStringContainsString('TranslationConcurrencyGate', $service);
        self::assertStringContainsString('concurrencyGate->acquire', $service);
        self::assertStringContainsString('timeout_seconds', $service);
        self::assertStringContainsString('low_speed_time', $service);
        self::assertStringContainsString('connect_timeout', $service);
        self::assertStringContainsString('isBusyMarker', $gate);
        self::assertStringContainsString('function isBusy', $gate);
        self::assertStringContainsString('TranslationBusyException', $gate);
        self::assertStringContainsString('isBusy(', $service);
        self::assertStringContainsString('REQUEST_TIMEOUT_SECONDS = 900', $service);
        self::assertStringContainsString('REQUEST_LOW_SPEED_SECONDS = 900', $service);
        self::assertStringContainsString('REQUEST_CONNECT_TIMEOUT_SECONDS = 5', $service);
        self::assertStringContainsString('concurrencyLane', $service);
        self::assertStringContainsString('Drop blank strings before locking', $service);
        self::assertLessThanOrEqual(
            960,
            900 + 60,
            'Request timeout + grace must stay within STALE_HOLD_SECONDS'
        );
    }
}
