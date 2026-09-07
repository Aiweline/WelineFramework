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
        self::assertStringContainsString('STALE_HOLD_SECONDS = 210', $gate);
        self::assertStringContainsString('disconnectStaleHolder', $gate);
        self::assertStringContainsString('posix_kill', $gate);
        self::assertStringContainsString('SchedulerSystem::sleep', $gate);
        self::assertStringNotContainsString("\nsleep(", $gate);
        self::assertStringNotContainsString('\\sleep(', $gate);
        self::assertStringContainsString('TranslationConcurrencyGate', $service);
        self::assertStringContainsString('concurrencyGate->acquire', $service);
        self::assertStringContainsString('timeout_seconds', $service);
        self::assertStringContainsString('isBusyMarker', $service);
        self::assertStringContainsString('REQUEST_TIMEOUT_SECONDS = 180', $service);
        self::assertLessThanOrEqual(
            210,
            180 + 30,
            'Request timeout + grace must stay within STALE_HOLD_SECONDS'
        );
    }
}
