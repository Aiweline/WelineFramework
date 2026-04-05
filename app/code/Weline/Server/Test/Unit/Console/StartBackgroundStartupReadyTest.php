<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

final class StartBackgroundStartupReadyTest extends TestCase
{
    public function testWaitForBackgroundStartupReadyRequiresRunningPhase(): void
    {
        $file = $this->createTempInstanceFile(['startup_phase' => 'bootstrapping']);
        $start = new Start();

        $result = $this->invokeProtected($start, 'waitForBackgroundStartupReady', $file, 20, 10);

        self::assertFalse($result['ready']);
        self::assertSame('bootstrapping', $result['data']['startup_phase']);
    }

    public function testWaitForBackgroundStartupReadyTreatsRunningPhaseAsComplete(): void
    {
        $file = $this->createTempInstanceFile(['startup_phase' => 'running']);
        $start = new Start();

        $result = $this->invokeProtected($start, 'waitForBackgroundStartupReady', $file, 20, 10);

        self::assertTrue($result['ready']);
        self::assertSame('running', $result['data']['startup_phase']);
    }

    private function createTempInstanceFile(array $data): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'wls-start-');
        self::assertNotFalse($file);
        \file_put_contents($file, \json_encode($data, JSON_THROW_ON_ERROR));
        $this->addToAssertionCount(1);

        $this->registerFileCleanup($file);

        return $file;
    }

    private function registerFileCleanup(string $file): void
    {
        \register_shutdown_function(static function () use ($file): void {
            if (\is_file($file)) {
                @\unlink($file);
            }
        });
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
