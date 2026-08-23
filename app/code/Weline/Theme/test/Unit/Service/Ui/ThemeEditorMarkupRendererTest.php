<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Ui;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Ui\IconRegistry;
use Weline\Theme\Service\Ui\ThemeEditorMarkupRenderer;

final class ThemeEditorMarkupRendererTest extends TestCase
{
    private ThemeEditorMarkupRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new ThemeEditorMarkupRenderer(new IconRegistry());
    }

    public function testSelectorFragmentsEscapeDataAndKeepSelections(): void
    {
        $themes = $this->renderer->renderThemeOptions([[
            'id' => 7,
            'name' => '"><script>alert(1)</script>',
            'is_active' => true,
        ]], 7);
        self::assertStringContainsString('value="7" selected', $themes);
        self::assertStringContainsString('&lt;script&gt;', $themes);
        self::assertStringNotContainsString('<script>', $themes);

        $pageTypes = $this->renderer->renderPageTypeOptions([
            'home" onfocus="alert(1)' => '<Home>',
        ], 'home" onfocus="alert(1)');
        self::assertStringContainsString('&quot; onfocus=&quot;', $pageTypes);
        self::assertStringContainsString('&lt;Home&gt;', $pageTypes);
        self::assertStringContainsString(' selected', $pageTypes);

        $locales = $this->renderer->renderLocaleOptions([[
            'code' => 'zh" onmouseover="alert(1)',
            'name' => '<中文>',
        ]]);
        self::assertStringContainsString('&quot; onmouseover=&quot;', $locales);
        self::assertStringContainsString('&lt;中文&gt;', $locales);
    }

    public function testScopeOptionsEscapeValuesLabelsAndKinds(): void
    {
        $html = $this->renderer->renderScopeOptions([
            [
                'value' => 'shop.default.default" onfocus="alert(1)',
                'label' => '<商城>',
                'kind' => 'website',
            ],
            [
                'value' => 'shop.cn.default',
                'label' => '中国店',
                'kind' => 'unexpected',
            ],
        ], 'shop.default.default" onfocus="alert(1)');

        self::assertStringContainsString('value="shop.default.default&quot; onfocus=&quot;alert(1)" data-scope-kind="website" selected', $html);
        self::assertStringContainsString('data-scope-kind="custom"', $html);
        self::assertStringContainsString('&lt;商城&gt;', $html);
        self::assertStringNotContainsString('<商城>', $html);
    }

    public function testStructureAndWidgetFragmentsUseWelineUiContracts(): void
    {
        $placeholder = $this->renderer->renderStructurePlaceholder('content');
        self::assertStringContainsString('slot-placeholder-large', $placeholder);
        self::assertStringContainsString('class="w-icon"', $placeholder);
        self::assertStringNotContainsString('ri-', $placeholder);

        $library = $this->renderer->renderWidgetLibrary([
            'general' => [
                'label' => '通用<script>',
                'widgets' => [[
                    'code' => 'Weline_Test::demo" ondrop="alert(1)',
                    'module' => 'Weline_Test',
                    'type' => 'content',
                    'name' => '<测试部件>',
                    'description' => 'Demo & details',
                    'position' => ['content'],
                    'page_layouts' => ['homepage'],
                    'is_container' => true,
                    'exclusive' => true,
                    'preview_html' => '<span data-trusted-preview="1">preview</span>',
                ]],
            ],
        ], 'frontend');

        self::assertStringContainsString('class="widget-item draggable widget-container widget-exclusive"', $library);
        self::assertStringContainsString('draggable="true"', $library);
        self::assertStringContainsString('data-widget-position="[&quot;content&quot;]"', $library);
        self::assertStringContainsString('&lt;测试部件&gt;', $library);
        self::assertStringContainsString('&quot; ondrop=&quot;', $library);
        self::assertStringNotContainsString('<测试部件>', $library);
        self::assertStringContainsString('data-trusted-preview="1"', $library);
        self::assertStringNotContainsString('data-bs-', $library);
        self::assertStringNotContainsString('ri-', $library);
    }

    public function testEmptyLibraryRetainsTheAsyncLoadingBoundary(): void
    {
        $html = $this->renderer->renderWidgetLibrary([], 'frontend');

        self::assertStringContainsString('id="widgetListLoading"', $html);
        self::assertStringContainsString('class="w-spinner"', $html);
    }
}
