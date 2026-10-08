<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ProcessSharedInterface;
use Weline\Framework\Runtime\Runtime;

final class ObjectManagerProcessSharedInstancesTest extends TestCase
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

    public function testProcessSharedInstanceIsSameObjectAcrossConcurrentFibers(): void
    {
        $fiberA = new \Fiber(static function (): object {
            $first = new ObjectManagerProcessSharedTestDouble();
            $first->owner = 'fiber-a';
            ObjectManager::setInstance(ObjectManagerProcessSharedTestDouble::class, $first);
            \Fiber::suspend();

            return ObjectManager::_getInstance(ObjectManagerProcessSharedTestDouble::class);
        });

        $fiberB = new \Fiber(static function (): object {
            // Must observe the process bag written by fiber-a, not a fiber-local copy.
            $shared = ObjectManager::_getInstance(ObjectManagerProcessSharedTestDouble::class);
            self::assertInstanceOf(ObjectManagerProcessSharedTestDouble::class, $shared);
            $shared->owner = 'fiber-b';

            return $shared;
        });

        self::assertNull($fiberA->start());
        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());

        $fromA = $fiberA->getReturn();
        $fromB = $fiberB->getReturn();
        self::assertSame($fromA, $fromB);
        self::assertSame('fiber-b', $fromA->owner);
        self::assertSame(
            $fromA,
            ObjectManager::_getInstance(ObjectManagerProcessSharedTestDouble::class),
        );
    }

    public function testUnmarkedInstanceStaysFiberLocal(): void
    {
        $fiberA = new \Fiber(static function (): string {
            $instance = new ObjectManagerFiberLocalTestDouble();
            $instance->owner = 'fiber-a';
            ObjectManager::setInstance(ObjectManagerFiberLocalTestDouble::class, $instance);
            \Fiber::suspend();

            return ObjectManager::_getInstance(ObjectManagerFiberLocalTestDouble::class)->owner;
        });

        $fiberB = new \Fiber(static function (): string {
            $instance = new ObjectManagerFiberLocalTestDouble();
            $instance->owner = 'fiber-b';
            ObjectManager::setInstance(ObjectManagerFiberLocalTestDouble::class, $instance);

            return ObjectManager::_getInstance(ObjectManagerFiberLocalTestDouble::class)->owner;
        });

        self::assertNull($fiberA->start());
        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        self::assertSame('fiber-b', $fiberB->getReturn());

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        self::assertSame('fiber-a', $fiberA->getReturn());
    }

    public function testClearCurrentFiberInstancesKeepsProcessShared(): void
    {
        $fiber = new \Fiber(static function (): ?ObjectManagerProcessSharedTestDouble {
            $shared = new ObjectManagerProcessSharedTestDouble();
            $shared->owner = 'process';
            ObjectManager::setInstance(ObjectManagerProcessSharedTestDouble::class, $shared);

            $local = new ObjectManagerFiberLocalTestDouble();
            $local->owner = 'local';
            ObjectManager::setInstance(ObjectManagerFiberLocalTestDouble::class, $local);

            ObjectManager::clearCurrentFiberInstances();

            self::assertNull(ObjectManager::_getInstance(ObjectManagerFiberLocalTestDouble::class));

            return ObjectManager::_getInstance(ObjectManagerProcessSharedTestDouble::class);
        });

        self::assertNull($fiber->start());
        self::assertTrue($fiber->isTerminated());
        $kept = $fiber->getReturn();
        self::assertInstanceOf(ObjectManagerProcessSharedTestDouble::class, $kept);
        self::assertSame('process', $kept->owner);
        self::assertSame($kept, ObjectManager::_getInstance(ObjectManagerProcessSharedTestDouble::class));
    }

    public function testHotPathCoordinatorsDeclareProcessShared(): void
    {
        $classes = [
            \Weline\Framework\Cache\RuntimeCachePolicy::class,
            \Weline\Framework\Cache\Namespace\NamespacePath::class,
            \Weline\Framework\Http\Url::class,
            \Weline\Framework\Cache\CacheManager::class,
            \Weline\Framework\Cache\Service\StorefrontScopeHotCache::class,
            \Weline\Framework\Router\FullPageCacheCoordinator::class,
            \Weline\Theme\Service\StorefrontHeaderNavFragmentCache::class,
            \Weline\Theme\Service\StorefrontProductCardFragmentCache::class,
            \Weline\Theme\Service\StorefrontThemeCacheCoordinator::class,
            \Weline\Theme\Service\ThemePageTypeResolver::class,
            \Weline\Theme\Service\ThemePublishedVersionRuntimeResolver::class,
            \Weline\Theme\Helper\ThemePathResolver::class,
            \Weline\Theme\Helper\ThemeChainResolver::class,
            \Weline\Product\Service\Storefront\StorefrontOfferPriceAssembler::class,
            \Weline\Product\Service\Storefront\StorefrontPriceAdjustmentProviderRegistry::class,
            \Weline\Search\Service\SearchProviderRegistry::class,
            // Wave-1a Fiber-bag promotion (audit PASS)
            \Weline\Framework\Plugin\PluginsManager::class,
            \Weline\Framework\Compilation\ServiceProviderRegistry::class,
            \Weline\Framework\Runtime\ModuleRequestResetterRegistry::class,
            \Weline\Framework\App\Localization\LocalizationProviderRegistry::class,
            \Weline\Framework\Http\Fpc\FpcStoreAdapterRegistry::class,
            \Weline\Framework\Controller\Extra\ExtraTypeRegistry::class,
            \Weline\Framework\Controller\Extra\ExtraPolicyResolver::class,
            \Weline\Framework\Cache\StorefrontCacheKeyContextResolver::class,
            \Weline\Framework\Http\Security\CspSourceContributionRegistry::class,
            \Weline\Framework\Service\Query\QueryProviderRegistry::class,
            \Weline\Social\Service\SocialPlatformRegistry::class,
            \Weline\Cms\Service\CmsUriInterceptSkipRegistry::class,
            \Weline\Websites\Api\Runtime\RequestResetter::class,
            \Weline\DataTable\Api\Runtime\RequestResetter::class,
            \Weline\Ai\Api\Runtime\RequestResetter::class,
            \Weline\Theme\Api\Runtime\RequestResetter::class,
            \Weline\Server\Api\Runtime\RequestResetter::class,
            \Weline\Backend\Api\Runtime\RequestResetter::class,
            \Weline\Admin\Api\Runtime\RequestResetter::class,
            \Weline\Meta\Api\Runtime\RequestResetter::class,
            \Weline\Widget\Api\Runtime\RequestResetter::class,
            \Weline\Acl\Api\Runtime\RequestResetter::class,
            \Weline\Frontend\Api\Runtime\RequestResetter::class,
            \Weline\Storage\Service\StorageRequestResetter::class,
        ];
        foreach ($classes as $class) {
            self::assertTrue(
                \is_a($class, ProcessSharedInterface::class, true),
                $class . ' must implement ProcessSharedInterface',
            );
        }
        self::assertTrue(
            \is_a(\Weline\Framework\View\Template::class, \Weline\Framework\Runtime\RequestLocalInterface::class, true),
            'Template must stay request/fiber local',
        );
        // KEEP_FIBER: must not silently gain ProcessShared
        foreach ([
            \Weline\Framework\Event\EventsManager::class,
            \Weline\Framework\Database\DbManager::class,
        ] as $keepFiber) {
            self::assertFalse(
                \is_a($keepFiber, ProcessSharedInterface::class, true),
                $keepFiber . ' must stay Fiber-local until dedicated gate passes',
            );
        }
    }
}

final class ObjectManagerProcessSharedTestDouble implements ProcessSharedInterface
{
    public string $owner = '';
}

final class ObjectManagerFiberLocalTestDouble
{
    public string $owner = '';
}
