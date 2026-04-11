<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\WlsFiberContext;

final class WlsFiberContextObjectManagerIsolationTest extends TestCase
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

    public function testRestoreKeepsCurrentFiberObjectManagerBucket(): void
    {
        $context = null;

        $fiberA = new \Fiber(static function () use (&$context): ?string {
            $instance = new \stdClass();
            $instance->owner = 'fiber-a';
            ObjectManager::setInstance(\stdClass::class, $instance);
            $context = WlsFiberContext::capture();

            \Fiber::suspend();

            $context->restore();

            return ObjectManager::_getInstance(\stdClass::class)?->owner;
        });

        $fiberB = new \Fiber(static function (): ?string {
            $instance = new \stdClass();
            $instance->owner = 'fiber-b';
            ObjectManager::setInstance(\stdClass::class, $instance);

            return ObjectManager::_getInstance(\stdClass::class)?->owner;
        });

        self::assertNull($fiberA->start());
        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        self::assertSame('fiber-b', $fiberB->getReturn());

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        self::assertSame('fiber-a', $fiberA->getReturn());
    }
}
