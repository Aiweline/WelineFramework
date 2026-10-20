<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Controller\Core;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;

/**
 * Ch3.2：ObjectManager 类型索引替代 StateManager 整袋 instanceof 扫描。
 */
final class ObjectManagerTagIndexTest extends TestCase
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

    public function testRemoveInstancesByTagOnlyRemovesMatchingType(): void
    {
        $controller = new TagIndexControllerDouble();
        $model = new TagIndexModelDouble();
        $observer = new TagIndexObserverDouble();
        $plain = new \stdClass();
        ObjectManager::setInstance(TagIndexControllerDouble::class, $controller);
        ObjectManager::setInstance(TagIndexModelDouble::class, $model);
        ObjectManager::setInstance(TagIndexObserverDouble::class, $observer);
        ObjectManager::setInstance(\stdClass::class, $plain);

        self::assertSame(1, ObjectManager::removeInstancesByTag(ObjectManager::TAG_CONTROLLER));
        self::assertNull(ObjectManager::_getInstance(TagIndexControllerDouble::class));
        self::assertSame($model, ObjectManager::_getInstance(TagIndexModelDouble::class));
        self::assertSame($observer, ObjectManager::_getInstance(TagIndexObserverDouble::class));
        self::assertSame($plain, ObjectManager::_getInstance(\stdClass::class));
    }

    public function testRemoveInstancesByTagInsideFiberOnlyAffectsCurrentScope(): void
    {
        $globalController = new TagIndexControllerDouble();
        ObjectManager::setInstance(TagIndexControllerDouble::class, $globalController);

        $fiber = new \Fiber(static function (): array {
            $localController = new TagIndexControllerDouble();
            $localPlain = new \stdClass();
            ObjectManager::setInstance(TagIndexControllerDouble::class, $localController);
            ObjectManager::setInstance(\stdClass::class, $localPlain);

            $removed = ObjectManager::removeInstancesByTag(ObjectManager::TAG_CONTROLLER);

            return [
                'removed' => $removed,
                'controller' => ObjectManager::_getInstance(TagIndexControllerDouble::class),
                'plain' => ObjectManager::_getInstance(\stdClass::class),
            ];
        });
        $fiber->start();
        $result = $fiber->getReturn();

        self::assertSame(1, $result['removed']);
        self::assertNull($result['controller']);
        self::assertInstanceOf(\stdClass::class, $result['plain']);
        self::assertSame($globalController, ObjectManager::_getInstance(TagIndexControllerDouble::class));
    }

    public function testClearProcessInstancesDropsStaleTags(): void
    {
        $controller = new TagIndexControllerDouble();
        ObjectManager::setInstance(TagIndexControllerDouble::class, $controller);

        ObjectManager::clearProcessInstances();

        self::assertSame(0, ObjectManager::removeInstancesByTag(ObjectManager::TAG_CONTROLLER));
        self::assertNull(ObjectManager::_getInstance(TagIndexControllerDouble::class));
    }

    public function testRemoveInstanceUntagsClass(): void
    {
        $controller = new TagIndexControllerDouble();
        ObjectManager::setInstance(TagIndexControllerDouble::class, $controller);
        ObjectManager::removeInstance(TagIndexControllerDouble::class);

        self::assertSame(0, ObjectManager::removeInstancesByTag(ObjectManager::TAG_CONTROLLER));
    }
}

final class TagIndexControllerDouble extends Core
{
}

final class TagIndexModelDouble extends AbstractModel
{
}

final class TagIndexObserverDouble implements ObserverInterface
{
    public function execute(Event &$event): void
    {
    }
}
