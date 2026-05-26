<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\WlsRuntime;

final class WlsRuntimeObjectManagerFiberCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        Runtime::setMode('wls');
        ObjectManager::clearInstances();
    }

    protected function tearDown(): void
    {
        ObjectManager::clearInstances();
        Runtime::resetModeCache();
    }

    public function testResetClearsCurrentFiberObjectManagerBucket(): void
    {
        $runtime = new WlsRuntime();
        $fiber = new \Fiber(static function () use ($runtime): ?WlsRuntimeObjectManagerFiberCleanupTestDouble {
            $instance = new WlsRuntimeObjectManagerFiberCleanupTestDouble();
            $instance->owner = 'request';
            ObjectManager::setInstance(WlsRuntimeObjectManagerFiberCleanupTestDouble::class, $instance);

            $runtime->reset();

            return ObjectManager::_getInstance(WlsRuntimeObjectManagerFiberCleanupTestDouble::class);
        });

        self::assertNull($fiber->start());
        self::assertTrue($fiber->isTerminated());
        self::assertNull($fiber->getReturn());
    }

    public function testResetDoesNotClearSuspendedPeerFiberObjectManagerBucket(): void
    {
        $runtime = new WlsRuntime();
        $fiberA = new \Fiber(static function (): ?string {
            $instance = new WlsRuntimeObjectManagerFiberCleanupTestDouble();
            $instance->owner = 'fiber-a';
            ObjectManager::setInstance(WlsRuntimeObjectManagerFiberCleanupTestDouble::class, $instance);

            \Fiber::suspend();

            return ObjectManager::_getInstance(WlsRuntimeObjectManagerFiberCleanupTestDouble::class)?->owner;
        });

        $fiberB = new \Fiber(static function () use ($runtime): ?WlsRuntimeObjectManagerFiberCleanupTestDouble {
            $instance = new WlsRuntimeObjectManagerFiberCleanupTestDouble();
            $instance->owner = 'fiber-b';
            ObjectManager::setInstance(WlsRuntimeObjectManagerFiberCleanupTestDouble::class, $instance);

            $runtime->reset();

            return ObjectManager::_getInstance(WlsRuntimeObjectManagerFiberCleanupTestDouble::class);
        });

        self::assertNull($fiberA->start());
        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        self::assertNull($fiberB->getReturn());

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        self::assertSame('fiber-a', $fiberA->getReturn());
    }
}

final class WlsRuntimeObjectManagerFiberCleanupTestDouble
{
    public ?string $owner = null;

    public function __construct()
    {
    }
}
