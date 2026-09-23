<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit;

use PHPUnit\Framework\TestCase;

final class SearchAreaSlotContractTest extends TestCase
{
    public function testProviderInterfaceAndTaglibExposeFrontendBackendAreaSlots(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $interface = (string)\file_get_contents($moduleRoot . '/Api/SearchProviderInterface.php');
        $abstract = (string)\file_get_contents($moduleRoot . '/Service/AbstractSearchProvider.php');
        $registry = (string)\file_get_contents($moduleRoot . '/Service/SearchProviderRegistry.php');
        $guard = (string)\file_get_contents($moduleRoot . '/Service/SearchParamGuard.php');
        $taglib = (string)\file_get_contents($moduleRoot . '/Taglib/Search.php');
        self::assertStringContainsString("\$hotLabel = trim((string)__(\$word))", $taglib);
        self::assertStringContainsString('rawurlencode($hotLabel)', $taglib);
        self::assertStringNotContainsString(
            'rawurlencode($word)',
            $taglib,
            'w:search hot links must encode localized label for q'
        );
        $query = (string)\file_get_contents($moduleRoot . '/extends/module/Weline_Framework/Query/SearchQueryProvider.php');

        self::assertStringContainsString('function areas(): array', $interface);
        self::assertStringContainsString("return ['frontend']", $abstract);
        self::assertStringContainsString('filterByArea', $registry);
        self::assertStringContainsString("'area'", $guard);
        self::assertStringContainsString("'backend'", $guard);
        self::assertStringContainsString("'area' => false", $taglib);
        self::assertStringContainsString('navigate-hits', $taglib);
        self::assertStringContainsString('data-search-area', $taglib);
        self::assertStringContainsString('data-navigate-hits', $taglib);
        self::assertStringContainsString("'frontend' => true", $query);
        self::assertStringContainsString('area slots', $query);
        self::assertStringContainsString("'name' => 'area'", $query);
    }
}
