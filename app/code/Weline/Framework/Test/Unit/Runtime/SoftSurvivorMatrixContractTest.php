<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\SoftSurvivorCatalog;
use Weline\Server\Service\Memory\WorkerHostPressureApplier;
use Weline\Server\Service\WorkerResponseMemoryGuard;

final class SoftSurvivorMatrixContractTest extends TestCase
{
    public function testRegistrySymbolsAreSoftSurvivors(): void
    {
        self::assertFalse(SoftSurvivorCatalog::isRegistrySoftSurvivor(
            'Weline\\Framework\\Router\\FullPageCacheCoordinator::$processFpcPayloadCache'
        ));
        self::assertSame(
            SoftSurvivorCatalog::ROLE_CONTENT,
            SoftSurvivorCatalog::roleFor(
                'Weline\\Framework\\Router\\FullPageCacheCoordinator::$processFpcPayloadCache'
            )
        );
        self::assertTrue(SoftSurvivorCatalog::isRegistrySoftSurvivor(
            'Weline\\Framework\\Service\\Query\\QueryProviderRegistry::$processCompiledRegistry'
        ));
        self::assertTrue(SoftSurvivorCatalog::isRegistrySoftSurvivor(
            'Weline\\Widget\\Service\\WidgetRegistry::$staticCachedRegistry'
        ));
        self::assertSame(
            SoftSurvivorCatalog::ROLE_CONTENT,
            SoftSurvivorCatalog::roleFor('Weline\\Framework\\Phrase\\DictionaryCacheNamespace::$bagRefs')
        );
    }

    public function testGuardOrchestrationHasNoDomainBagFqcn(): void
    {
        $src = (string)\file_get_contents(
            (string)(new \ReflectionClass(WorkerResponseMemoryGuard::class))->getFileName()
        );
        self::assertStringContainsString('ProcessMemoryReclaimFacade::registry()', $src);
        self::assertStringNotContainsString('NodePool::trimForMemoryPressure()', $src);
        self::assertStringNotContainsString('NodePool::reset()', $src);
        self::assertStringNotContainsString('DictionaryCacheNamespace::processMemoryReclaimable()', $src);
        self::assertStringNotContainsString('Parser::clearWorkerCaches()', $src);
        self::assertStringNotContainsString('TemplateCacheManager::clearProcessMemoryCache()', $src);
        self::assertStringNotContainsString('FullPageCacheCoordinator::clearProcessCache()', $src);
        self::assertStringNotContainsString('MemoryCacheService::purgeAll()', $src);
    }

    public function testApplierHasNoPrivateRegistry(): void
    {
        $src = (string)\file_get_contents(
            (string)(new \ReflectionClass(WorkerHostPressureApplier::class))->getFileName()
        );
        self::assertStringContainsString('compactForHostLevel', $src);
        self::assertStringNotContainsString('new MemoryReclaimableRegistry', $src);
        self::assertStringNotContainsString('processMemoryReclaimable()', $src);
    }
}
