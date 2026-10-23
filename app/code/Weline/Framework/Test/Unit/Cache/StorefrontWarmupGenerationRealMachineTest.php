<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;

/**
 * Runs the real-machine warmup generation use case in a normal app process.
 * PHPUnit's own bootstrap forces the sandbox database, which has no authority clock.
 */
final class StorefrontWarmupGenerationRealMachineTest extends TestCase
{
    public function testStaleAuthorityClockRestartsLiveWarmupSlice(): void
    {
        $script = __DIR__ . '/storefront-warmup-generation-uc.php';
        $evidencePath = \sys_get_temp_dir() . '/storefront-warmup-generation-uc-' . \getmypid() . '.json';
        if (\is_file($evidencePath)) {
            \unlink($evidencePath);
        }

        $command = \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($script) . ' ' . \escapeshellarg($evidencePath);
        $output = [];
        $exitCode = 0;
        \exec($command . ' 2>&1', $output, $exitCode);
        $text = \implode("\n", $output);

        self::assertSame(0, $exitCode, $text);
        self::assertFileExists($evidencePath);
        $evidence = \json_decode((string)\file_get_contents($evidencePath), true);
        self::assertIsArray($evidence);
        self::assertTrue($evidence['generation_changed']);
        self::assertSame('generation_changed', $evidence['reason']);
        self::assertGreaterThan($evidence['planted_clock'], $evidence['ledger_clock_after']);
        self::assertNotSame('done', $evidence['phase_after']);
        self::assertGreaterThan(0, $evidence['attempts']);
        self::assertNotSame(99999, $evidence['ok_after']);

        \unlink($evidencePath);
    }
}
