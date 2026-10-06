<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;

/** Filters default_injection must drop editor list-filters placeholders in derived PHTML. */
final class LayoutRelationCompilerFilterPlaceholderContractTest extends TestCase
{
    public function testCompileDropsListFiltersPlaceholderWhenInjectionPresent(): void
    {
        $source = <<<'HTML'
<aside>
<w:slot id="list-filters" name="筛选器区域" multiple="true" position="sidebar">
<div class="products-layout__placeholder slot-placeholder" data-placeholder="list-filters">
<span class="products-layout__placeholder-text">筛选器区域 - 由 Filters 部件默认注入</span>
</div>
</w:slot>
</aside>
HTML;
        $nodes = [[
            'node_uid' => 'filters-1',
            'widget_module' => 'Weline_Filters',
            'widget_type' => 'sidebar',
            'widget_code' => 'category-filters',
            'slot_id' => 'list-filters',
            'source' => 'default_injection',
            'is_active' => true,
            'sort_order' => 0,
            'config' => [],
        ]];
        $out = (new LayoutRelationCompiler())->compile($source, $nodes);
        self::assertStringContainsString('renderResolved', $out);
        self::assertStringContainsString('category-filters', $out);
        self::assertStringNotContainsString('data-placeholder="list-filters"', $out);
        self::assertStringNotContainsString('筛选器区域 - 由 Filters 部件默认注入', $out);
    }
}
