<?php

declare(strict_types=1);

namespace Weline\Catalog\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Catalog\Taglib\CategorySelect;

final class CategorySelectContractTest extends TestCase
{
    public function testTagNameAndSelfCloseContract(): void
    {
        self::assertSame('catalog:category:select', CategorySelect::name());
        self::assertTrue(CategorySelect::tag_self_close());
        self::assertTrue(CategorySelect::tag_self_close_with_attrs());
        self::assertArrayHasKey('id', CategorySelect::attr());
        self::assertArrayHasKey('value', CategorySelect::attr());
        self::assertArrayHasKey('options', CategorySelect::attr());
        self::assertArrayHasKey('scope', CategorySelect::attr());
        self::assertArrayHasKey('allow-create', CategorySelect::attr());
        self::assertArrayHasKey('website-id', CategorySelect::attr());
    }

    public function testCallbackEmitsTreeMultiSelectAndQuickCreate(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/Taglib/CategorySelect.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString('catalog:category:select', $source);
        self::assertStringContainsString('data-component="catalog-category-select"', $source);
        self::assertStringContainsString('data-catalog-category-select-value', $source);
        self::assertStringContainsString('aria-multiselectable="true"', $source);
        self::assertStringContainsString('role="tree"', $source);
        self::assertStringContainsString('weline-catalog-category-select-toggle', $source);
        self::assertStringContainsString('weline-catalog-category-select-create', $source);
        self::assertStringContainsString('catalog_category_admin', $source);
        self::assertStringContainsString('categoryAdminSave', $source);
        self::assertStringContainsString('window.WelineCatalogCategorySelect', $source);
        self::assertStringContainsString('buildTree', $source);
        self::assertStringContainsString('setOptions:', $source);
        self::assertStringContainsString('selectWithAncestors', $source);
        self::assertStringContainsString('ancestorsOf', $source);
        self::assertStringContainsString('handleViewportChange', $source);
        self::assertStringContainsString('onDropdownWheel', $source);
        self::assertStringContainsString('dropdown.contains(ev.target)', $source);
        self::assertStringContainsString('color-mix(in srgb,var(--backend-color-primary', $source);
        self::assertStringNotContainsString('backend-color-hover-bg,#f1f5f9', $source);
        self::assertStringContainsString('setValue: function(v, opts)', $source);
        self::assertStringContainsString('select: function(v, opts)', $source);
        self::assertStringContainsString('applySelectionFromValues', $source);
        self::assertStringContainsString('ensureListScrollable', $source);
        self::assertStringContainsString('scrollContainer: list', $source);
        self::assertStringContainsString('getValue: function(){ return selected.join(","); }', $source);
        self::assertStringContainsString('syncHidden({ silent: true })', $source);
        self::assertStringContainsString('hydrateFromHidden', $source);
        self::assertStringContainsString('// 初始化：先按 hidden 水合，再静默对齐；禁止空 selected 覆盖已有 value。', $source);
        self::assertStringContainsString('weline:catalog-category-select-ready', $source);
        self::assertStringContainsString('catalog-category-select标签使用指南.md', $source);
        self::assertStringNotContainsString('window.addEventListener("scroll", function(){ if (open) close(); }, true);', $source);
        self::assertStringNotContainsString('@static(', $source);
    }
}
