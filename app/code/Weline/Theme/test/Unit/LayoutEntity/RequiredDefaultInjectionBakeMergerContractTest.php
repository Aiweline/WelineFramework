<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionBakeMerger;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;

/**
 * Bake merger: required JSON default_injections enter node maps before materialize.
 */
final class RequiredDefaultInjectionBakeMergerContractTest extends TestCase
{
    public function testClassAndMergeApiExist(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionBakeMerger.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('final class RequiredDefaultInjectionBakeMerger', $src);
        self::assertStringContainsString('function mergeIntoNodes', $src);
        self::assertStringContainsString('function involvedExactLayoutTypes', $src);
        self::assertStringContainsString('RequiredDefaultInjectionContract::merge', $src);
        self::assertStringContainsString('uninstalledInjectionsForVersion', $src);
        self::assertStringContainsString('DefaultInjectionPlanRepository', $src);
        self::assertStringContainsString('listDeclarations', $src);
        self::assertStringNotContainsString('ThemeComponentCatalog', $src);
        self::assertTrue(\class_exists(RequiredDefaultInjectionBakeMerger::class));
    }

    public function testContractMergeRelocatesWrongSlotInsteadOfDuplicating(): void
    {
        $declarations = [[
            'module' => 'Weline_Product',
            'type' => 'product',
            'code' => 'recommended-products',
            'default_injections' => [[
                'layout_type' => 'category',
                'slot' => 'category-recommendations',
                'required' => true,
                'sort_order' => 0,
                'area' => 'content',
            ]],
        ]];
        $slots = [
            'content' => [[
                'node_uid' => 'rec-drift',
                'widget_module' => 'Weline_Product',
                'widget_code' => 'recommended-products',
                'slot_id' => 'content',
            ]],
            'category-recommendations' => [[
                'node_uid' => 'rec-correct',
                'widget_module' => 'Weline_Product',
                'widget_code' => 'recommended-products',
                'slot_id' => 'category-recommendations',
            ]],
        ];
        $merged = RequiredDefaultInjectionContract::merge($slots, 'category', $declarations, []);
        self::assertArrayNotHasKey('content', $merged);
        self::assertCount(1, $merged['category-recommendations'] ?? []);
        self::assertSame('rec-correct', $merged['category-recommendations'][0]['node_uid'] ?? '');
    }

    public function testMergeAddsRequiredNodeWhenSlotEmpty(): void
    {
        $declarations = [[
            'module' => 'Weline_Filters',
            'type' => 'content',
            'code' => 'filters',
            'default_injections' => [[
                'layout_type' => 'products',
                'slot' => 'list-filters',
                'required' => true,
                'sort_order' => 10,
                'area' => 'sidebar',
            ]],
        ]];
        $merged = RequiredDefaultInjectionContract::merge([], 'products', $declarations, []);
        self::assertArrayHasKey('list-filters', $merged);
        self::assertSame('filters', $merged['list-filters'][0]['widget_code'] ?? '');
        self::assertSame('Weline_Filters', $merged['list-filters'][0]['widget_module'] ?? '');
    }

    public function testMergeSkipsUserDeletedOmission(): void
    {
        $declarations = [[
            'module' => 'Weline_Filters',
            'type' => 'content',
            'code' => 'filters',
            'default_injections' => [[
                'layout_type' => 'products',
                'slot' => 'list-filters',
                'required' => true,
            ]],
        ]];
        $omissions = [[
            'slot_id' => 'list-filters',
            'widget_module' => 'Weline_Filters',
            'widget_code' => 'filters',
        ]];
        $merged = RequiredDefaultInjectionContract::merge([], 'products', $declarations, $omissions);
        self::assertSame([], $merged['list-filters'] ?? []);
    }

    public function testBakeCoordinatorWiresMergerBeforeMaterialize(): void
    {
        $bake = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php'
        );
        $pageStart = \strpos($bake, 'function bakePageFromNodes');
        self::assertNotFalse($pageStart);
        $pageEnd = \strpos($bake, 'function rebakeAfterInjectionCollect', $pageStart);
        self::assertNotFalse($pageEnd);
        $pageBody = \substr($bake, $pageStart, $pageEnd - $pageStart);
        self::assertStringContainsString('mergeRequiredDefaultsIntoNodes', $pageBody);

        $chromeStart = \strpos($bake, 'function bakeChromeFromNodes');
        self::assertNotFalse($chromeStart);
        $chromeEnd = \strpos($bake, 'function bakePageFromNodes', $chromeStart);
        self::assertNotFalse($chromeEnd);
        $chromeBody = \substr($bake, $chromeStart, $chromeEnd - $chromeStart);
        self::assertStringContainsString('mergeRequiredDefaultsIntoNodes', $chromeBody);
        self::assertStringContainsString("'homepage'", $chromeBody);
    }

    public function testMaterializerWritesPageTypeIntoStructureJson(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityMaterializer.php'
        );
        self::assertStringContainsString("'page_type'", $src);
        self::assertStringContainsString("'slots' => \$slots", $src);
    }
}
