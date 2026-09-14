<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;

final class ObjectManagerProcessCacheClearTest extends TestCase
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

    public function testCacheClearPreservesSuspendedRequestAndAclDenial(): void
    {
        $processInstance = new \stdClass();
        ObjectManager::setInstance(\stdClass::class, $processInstance);
        $request = new Request();
        $request->setRouter([
            'module' => 'Weline_Product',
            'router' => 'weline_product',
            'class' => [
                'area' => 'FrontendRestController',
                'name' => 'Weline\\Product\\Api\\Rest\\V1\\Products',
            ],
        ]);
        $acl = new Acl('products', 'Products', '');
        $events = $this->createMock(EventsManager::class);
        $events->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (string $eventName, mixed &$acl) use ($events): EventsManager {
                self::assertSame('Weline_Framework_Acl::dispatch', $eventName);
                self::assertSame('weline_product', $acl->getData('router'));
                $acl->setResult('permission-denied');
                return $events;
            }
        );
        $fiber = new \Fiber(static function () use ($request, $events, $acl): string {
            ObjectManager::setInstance(Request::class, $request);
            ObjectManager::setInstance(EventsManager::class, $events);
            $origin = ObjectManager::getOriginInstance(ProcessCacheClearOrigin::class);
            \Fiber::suspend();

            self::assertSame($request, ObjectManager::getInstance(Request::class));
            self::assertSame($origin, ObjectManager::getOriginInstance(ProcessCacheClearOrigin::class));
            return $acl->execute();
        });
        $fiber->start();

        ObjectManager::clearProcessInstances();

        self::assertNull(ObjectManager::_getInstance(\stdClass::class));
        $fiber->resume();
        self::assertSame('permission-denied', $fiber->getReturn());
    }
}

final class ProcessCacheClearOrigin
{
}

