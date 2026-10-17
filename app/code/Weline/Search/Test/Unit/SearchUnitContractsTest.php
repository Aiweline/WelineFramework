<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Search\Taglib\Search;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllSearchUnitContractsPass().
 */
final class SearchUnitContractsTest extends TestCase
{
    public function testAllSearchUnitContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'SearchAreaSlot::testProviderInterfaceAndTaglibExposeFrontendBackendAreaSlots' => function () {
            $this->assert_SearchAreaSlot_testProviderInterfaceAndTaglibExposeFrontendBackendAreaSlots();
        },
            'SearchModuleProductOptional::testProductIsOptionalDependency' => function () {
            $this->assert_SearchModuleProductOptional_testProductIsOptionalDependency();
        },
            'SearchTaglib::testSearchTagImplementsCurrentSelfClosingTagContract' => function () {
            $this->assert_SearchTaglib_testSearchTagImplementsCurrentSelfClosingTagContract();
        },
        ];
    }

private function assert_SearchAreaSlot_testProviderInterfaceAndTaglibExposeFrontendBackendAreaSlots(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Search';
    $interface = (string) \file_get_contents($moduleRoot . '/Api/SearchProviderInterface.php');
    $abstract = (string) \file_get_contents($moduleRoot . '/Service/AbstractSearchProvider.php');
    $registry = (string) \file_get_contents($moduleRoot . '/Service/SearchProviderRegistry.php');
    $guard = (string) \file_get_contents($moduleRoot . '/Service/SearchParamGuard.php');
    $taglib = (string) \file_get_contents($moduleRoot . '/Taglib/Search.php');
    self::assertStringContainsString("\$hotLabel = trim((string)__(\$word))", $taglib);
    self::assertStringContainsString('rawurlencode($hotLabel)', $taglib);
    self::assertStringNotContainsString('rawurlencode($word)', $taglib, 'w:search hot links must encode localized label for q');
    $query = (string) \file_get_contents($moduleRoot . '/extends/module/Weline_Framework/Query/SearchQueryProvider.php');
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

private function assert_SearchModuleProductOptional_testProductIsOptionalDependency(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Search' . '/etc/module.php');
    self::assertStringContainsString("'optional' => [", $source);
    self::assertStringContainsString("'Weline_Product' => '*'", $source);
    self::assertStringContainsString("'Weline_Queue' => '*',", $source);
    self::assertStringContainsString("'optional' => [\n        'Weline_Product' => '*',\n    ],", $source);
}

private function assert_SearchTaglib_testSearchTagImplementsCurrentSelfClosingTagContract(): void
{
    self::assertContains(TaglibInterface::class, class_implements(Search::class));
    self::assertTrue(Search::tag_self_close());
    self::assertTrue(Search::tag_self_close_with_attrs());
    self::assertNull(Search::parent());
    self::assertNotSame('', Search::document());
}
}
