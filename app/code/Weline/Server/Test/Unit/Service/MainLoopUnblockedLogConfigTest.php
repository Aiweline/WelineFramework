<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\MainLoopUnblockedLogConfig;

class MainLoopUnblockedLogConfigTest extends TestCase
{
    public function testResolveFallsBackToDefaultWhenNothingConfigured(): void
    {
        self::assertSame(
            MainLoopUnblockedLogConfig::DEFAULT_LOG_EVERY,
            MainLoopUnblockedLogConfig::resolve([], ['worker'])
        );
    }

    public function testResolveUsesGlobalLoopValue(): void
    {
        self::assertSame(
            4096,
            MainLoopUnblockedLogConfig::resolve([
                'loop' => [
                    'main_loop_unblocked_log_every' => 4096,
                ],
            ], ['dispatcher'])
        );
    }

    public function testResolveAppliesScopeOverridesInOrder(): void
    {
        self::assertSame(
            256,
            MainLoopUnblockedLogConfig::resolve([
                'loop' => [
                    'main_loop_unblocked_log_every' => 4096,
                ],
                'worker' => [
                    'main_loop_unblocked_log_every' => 1024,
                ],
                'worker_ssl' => [
                    'main_loop_unblocked_log_every' => 256,
                ],
            ], ['worker', 'worker_ssl'])
        );
    }

    public function testResolveAllowsZeroToDisableLogging(): void
    {
        self::assertSame(
            0,
            MainLoopUnblockedLogConfig::resolve([
                'worker' => [
                    'main_loop_unblocked_log_every' => 0,
                ],
            ], ['worker'])
        );
    }

    public function testResolveIntervalUsesGlobalAndScopeOverrides(): void
    {
        self::assertSame(
            7.5,
            MainLoopUnblockedLogConfig::resolveInterval([
                'loop' => [
                    'main_loop_unblocked_log_interval_sec' => 30,
                ],
                'worker_ssl' => [
                    'main_loop_unblocked_log_interval_sec' => 7.5,
                ],
            ], ['worker', 'worker_ssl'])
        );
    }

    public function testResolveIntervalAllowsZeroToDisableTimeHeartbeat(): void
    {
        self::assertSame(
            0.0,
            MainLoopUnblockedLogConfig::resolveInterval([
                'dispatcher' => [
                    'main_loop_unblocked_log_interval_sec' => 0,
                ],
            ], ['dispatcher'])
        );
    }

    public function testResolveIgnoresInvalidValuesAndKeepsPreviousScope(): void
    {
        self::assertSame(
            3000,
            MainLoopUnblockedLogConfig::resolve([
                'loop' => [
                    'main_loop_unblocked_log_every' => 3000,
                ],
                'worker' => [
                    'main_loop_unblocked_log_every' => 'invalid',
                ],
            ], ['worker'])
        );
    }

    public function testShouldEmitOnlyAtConfiguredCadence(): void
    {
        self::assertFalse(MainLoopUnblockedLogConfig::shouldEmit(0, 100));
        self::assertFalse(MainLoopUnblockedLogConfig::shouldEmit(99, 100));
        self::assertTrue(MainLoopUnblockedLogConfig::shouldEmit(100, 100));
        self::assertFalse(MainLoopUnblockedLogConfig::shouldEmit(100, 0));
    }

    public function testShouldEmitByIntervalOnlyAfterConfiguredSeconds(): void
    {
        self::assertTrue(MainLoopUnblockedLogConfig::shouldEmitByInterval(30.0, 0.0, 30.0));
        self::assertFalse(MainLoopUnblockedLogConfig::shouldEmitByInterval(35.0, 10.0, 30.0));
        self::assertTrue(MainLoopUnblockedLogConfig::shouldEmitByInterval(40.0, 10.0, 30.0));
        self::assertFalse(MainLoopUnblockedLogConfig::shouldEmitByInterval(40.0, 10.0, 0.0));
    }
}
