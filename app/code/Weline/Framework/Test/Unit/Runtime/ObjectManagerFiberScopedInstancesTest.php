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

    public function testClearCurrentFiberInstancesOnlyClearsCurrentFiberBucket(): void
    {
        $globalInstance = new ObjectManagerFiberScopedTestDouble();
        $globalInstance->owner = 'global';
        ObjectManager::setInstance(ObjectManagerFiberScopedTestDouble::class, $globalInstance);

        $fiberA = new \Fiber(static function (): ?ObjectManagerFiberScopedTestDouble {
            $instance = new ObjectManagerFiberScopedTestDouble();
            $instance->owner = 'fiber-a';
            ObjectManager::setInstance(ObjectManagerFiberScopedTestDouble::class, $instance);

            \Fiber::suspend();

            return ObjectManager::_getInstance(ObjectManagerFiberScopedTestDouble::class);
        });

        $fiberB = new \Fiber(static function (): array {
            $instance = new ObjectManagerFiberScopedTestDouble();
            $instance->owner = 'fiber-b';
            ObjectManager::setInstance(ObjectManagerFiberScopedTestDouble::class, $instance);

            $originBefore = ObjectManager::getOriginInstance(ObjectManagerFiberScopedOriginTestDouble::class);
            $originBefore->owner = 'origin-b';

            ObjectManager::clearCurrentFiberInstances();
            $originAfter = ObjectManager::getOriginInstance(ObjectManagerFiberScopedOriginTestDouble::class);

            return [
                'shared' => ObjectManager::_getInstance(ObjectManagerFiberScopedTestDouble::class),
                'origin_same' => $originBefore === $originAfter,
                'origin_owner' => $originAfter->owner,
            ];
        });

        self::assertNull($fiberA->start());
        self::assertSame('global', ObjectManager::_getInstance(ObjectManagerFiberScopedTestDouble::class)?->owner);

        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        self::assertNull($fiberB->getReturn()['shared']);
        self::assertFalse($fiberB->getReturn()['origin_same']);
        self::assertNull($fiberB->getReturn()['origin_owner']);

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        self::assertSame('fiber-a', $fiberA->getReturn()?->owner);
        self::assertSame('global', ObjectManager::_getInstance(ObjectManagerFiberScopedTestDouble::class)?->owner);
    }

    public function testClearCurrentFiberInstancesDoesNotClearProcessMetadataCaches(): void
    {
        ObjectManager::getReflectionInstance(ObjectManagerFiberScopedTestDouble::class);
        ObjectManager::parserClass(ObjectManagerFiberScopedTestDouble::class);
        ObjectManager::isStaticClass(ObjectManagerFiberScopedTestDouble::class);

        $fiber = new \Fiber(static function (): ?ObjectManagerFiberScopedTestDouble {
            $instance = new ObjectManagerFiberScopedTestDouble();
            $instance->owner = 'fiber';
            ObjectManager::setInstance(ObjectManagerFiberScopedTestDouble::class, $instance);

            ObjectManager::clearCurrentFiberInstances();

            return ObjectManager::_getInstance(ObjectManagerFiberScopedTestDouble::class);
        });

        self::assertNull($fiber->start());
        self::assertTrue($fiber->isTerminated());
        self::assertNull($fiber->getReturn());
        self::assertArrayHasKey(
            ObjectManagerFiberScopedTestDouble::class,
            $this->readObjectManagerStaticProperty('reflections')
        );
        self::assertArrayHasKey(
            ObjectManagerFiberScopedTestDouble::class,
            $this->readObjectManagerStaticProperty('parsedClasses')
        );
        self::assertArrayHasKey(
            ObjectManagerFiberScopedTestDouble::class,
            $this->readObjectManagerStaticProperty('staticClassCache')
        );
    }

    public function testWObjUsesFiberLocalBucketsInWlsMode(): void
    {
        $fiberA = new \Fiber(static function (): string {
            $instance = \w_obj(ObjectManagerFiberScopedTestDouble::class);
            $instance->owner = 'fiber-a';

            \Fiber::suspend();

            return \w_obj(ObjectManagerFiberScopedTestDouble::class)->owner;
        });

        $fiberB = new \Fiber(static function (): string {
            $instance = \w_obj(ObjectManagerFiberScopedTestDouble::class);
            $instance->owner = 'fiber-b';

            return \w_obj(ObjectManagerFiberScopedTestDouble::class)->owner;
        });

        self::assertNull($fiberA->start());
        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        self::assertSame('fiber-b', $fiberB->getReturn());

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        self::assertSame('fiber-a', $fiberA->getReturn());
    }

    private function readObjectManagerStaticProperty(string $property): array
    {
        $reflection = new \ReflectionProperty(ObjectManager::class, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue();
    }
}

final class ObjectManagerFiberScopedTestDouble
{
    public ?string $owner = null;

    public function __construct()
    {
    }
}

final class ObjectManagerFiberScopedOriginTestDouble
{
    public ?string $owner = null;

    public function __construct()
    {
    }
}
