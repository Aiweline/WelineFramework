<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Widget\Service\DefaultInjectionPlanRepository;

/**
 * Contract: solidify-time plan reads widget_registry_entry only (no scanner / Catalog).
 */
final class DefaultInjectionPlanRepositoryContractTest extends TestCase
{
    public function testRepositoryReadsLedgerNotScanner(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/DefaultInjectionPlanRepository.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('final class DefaultInjectionPlanRepository', $src);
        self::assertStringContainsString('function listDeclarations', $src);
        self::assertStringContainsString('function clearMemo', $src);
        self::assertStringContainsString('WidgetRegistryEntry', $src);
        self::assertStringContainsString('HAS_DEFAULT_INJECTIONS', $src);
        self::assertStringContainsString('IS_ACTIVE', $src);
        self::assertStringContainsString('DEFAULT_INJECTIONS_JSON', $src);
        self::assertStringNotContainsString('WidgetScanner', $src);
        self::assertStringNotContainsString('getRegistry()', $src);
        self::assertStringNotContainsString('ThemeComponentCatalog', $src);
        self::assertTrue(class_exists(DefaultInjectionPlanRepository::class));
    }
}
