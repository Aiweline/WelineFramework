<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;

final class ObjectManagerFiberScopedInstancesTest extends TestCase
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

    public function testSharedInstanceBucketIsFiberLocalInWlsMode(): void
    {
        $fiberA = new \Fiber(static function (): object {
            $instance = new \stdClass();
            $instance->owner = 'fiber-a';
            ObjectManager::setInstance(\stdClass::class, $instance);

            \Fiber::suspend();

            return ObjectManager::_getInstance(\stdClass::class);
        });

        $fiberB = new \Fiber(static function (): object {
            $instance = new \stdClass();
            $instance->owner = 'fiber-b';
            ObjectManager::setInstance(\stdClass::class, $instance);

            return ObjectManager::_getInstance(\stdClass::class);
        });

        self::assertNull($fiberA->start());
        self::assertNull(ObjectManager::_getInstance(\stdClass::class));

        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        self::assertSame('fiber-b', $fiberB->getReturn()->owner);

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        self::assertSame('fiber-a', $fiberA->getReturn()->owner);
    }

    public function testRemoveInstanceOnlyAffectsCurrentFiberBucket(): void
    {
        $fiberA = new \Fiber(static function (): ?object {
            $instance = new \stdClass();
            $instance->owner = 'fiber-a';
            ObjectManager::setInstance(\stdClass::class, $instance);

            \Fiber::suspend();

            return ObjectManager::_getInstance(\stdClass::class);
        });

        $fiberB = new \Fiber(static function (): ?object {
            $instance = new \stdClass();
            $instance->owner = 'fiber-b';
            ObjectManager::setInstance(\stdClass::class, $instance);
            ObjectManager::removeInstance(\stdClass::class);

            return ObjectManager::_getInstance(\stdClass::class);
        });

        self::assertNull($fiberA->start());
        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        self::assertNull($fiberB->getReturn());

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        self::assertSame('fiber-a', $fiberA->getReturn()?->owner);
    }
}
