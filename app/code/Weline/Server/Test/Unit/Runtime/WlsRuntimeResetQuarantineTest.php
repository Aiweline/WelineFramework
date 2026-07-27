<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestResetException;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Runtime\WlsRuntime;
use Weline\Server\Service\WorkerResponseMemoryGuard;

final class WlsRuntimeResetQuarantineTest extends TestCase
{
    private const FAILURE_CALLBACK = '__wls_runtime_reset_failure_probe__';
    private const AFTER_CALLBACK = '__wls_runtime_reset_after_probe__';

    protected function setUp(): void
    {
        Runtime::setMode('wls');
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
    }

    protected function tearDown(): void
    {
        StateManager::unregisterResetCallback(self::FAILURE_CALLBACK);
        StateManager::unregisterResetCallback(self::AFTER_CALLBACK);
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
        Runtime::resetModeCache();
        parent::tearDown();
    }

    public function testResetAttemptsRemainingStagesAndRequestsWorkerQuarantine(): void
    {
        $afterFailureCalls = 0;
        StateManager::registerResetCallback(
            self::FAILURE_CALLBACK,
            static fn () => throw new \RuntimeException('runtime reset probe failed'),
        );
        StateManager::registerResetCallback(self::AFTER_CALLBACK, static function () use (&$afterFailureCalls): void {
            ++$afterFailureCalls;
        });

        try {
            (new WlsRuntime())->reset();
            self::fail('Expected the WLS reset boundary to fail closed.');
        } catch (RequestResetException $exception) {
            self::assertStringContainsString(self::FAILURE_CALLBACK, $exception->getMessage());
        }

        self::assertSame(1, $afterFailureCalls);
        self::assertSame(
            'request_reset_failure',
            WorkerResponseMemoryGuard::consumeDrainAfterResponseReason(),
        );
    }
}
