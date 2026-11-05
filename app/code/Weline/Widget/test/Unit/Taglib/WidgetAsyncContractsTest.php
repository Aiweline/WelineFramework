<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Widget\Service\WidgetTemplateParser;
use Weline\Widget\Taglib\Widget;

/**
 * 异步部件契约：元数据规范化 + 框架统一占位结构。
 *
 * @widget 契约（部件只声明，占位/加载由 Weline_Widget 统一实现）：
 *   async / async_cache / async_module / async_module_defer
 */
final class WidgetAsyncContractsTest extends TestCase
{
    public function testAsyncMetadataNormalizesAndRequiresModule(): void
    {
        $parser = $this->parser();

        $full = $this->invokeParser($parser, "@widget.async {true}\n@widget.async_cache {300}\n@widget.async_module {demo}");
        self::assertSame(true, $full['async']);
        self::assertSame(300, $full['async_cache']);
        self::assertSame('demo', $full['async_module']);
        self::assertSame(true, $full['async_module_defer'], 'defer 缺省必须是 true');

        $deferOff = $this->invokeParser($parser, "@widget.async {true}\n@widget.async_module {demo}\n@widget.async_module_defer {false}");
        self::assertSame(false, $deferOff['async_module_defer']);

        $off = $this->invokeParser($parser, "@widget.async {false}\n@widget.async_module {demo}");
        self::assertArrayNotHasKey('async', $off, 'async=false 不得留下任何异步键');

        $half = $this->invokeParser($parser, '@widget.async {true}');
        self::assertArrayNotHasKey('async', $half, '只写 async 不写 module 必须按同步处理');

        $nonCache = $this->invokeParser($parser, "@widget.async {true}\n@widget.async_module {demo}\n@widget.async_cache {0}");
        self::assertSame(0, $nonCache['async_cache']);
    }

    public function testStubIsFrameworkOwnedAndCarriesFragmentContract(): void
    {
        $stub = Widget::renderAsyncStub(
            'content',
            'app-download',
            'Weline_App',
            ['enabled' => true],
            'appDownloadFloat',
            300,
            true,
        );

        self::assertStringContainsString('data-widget-async="1"', $stub);
        self::assertStringContainsString('data-widget-code="app-download"', $stub);
        self::assertStringContainsString('data-widget-async-module="appDownloadFloat"', $stub);
        self::assertStringContainsString('data-widget-async-defer="1"', $stub);
        self::assertStringContainsString('data-widget-async-cache="300"', $stub);
        self::assertStringContainsString('data-widget-fragment="/widget/frontend/async?', $stub);
        self::assertStringContainsString('data-weline-load="widgetAsync"', $stub);
        self::assertStringContainsString('data-weline-load-when="idle"', $stub);
        self::assertStringNotContainsString('widget-wrapper', $stub, '占位不得带部件包装，避免空容器占位');
    }

    public function testDeferFlagAndAsyncDetection(): void
    {
        $deferOff = Widget::renderAsyncStub('content', 'store-music', 'Weline_StoreMusic', [], 'storeMusicFloat', 0, false);
        self::assertStringContainsString('data-widget-async-defer="0"', $deferOff);
        self::assertStringContainsString('data-widget-async-cache="0"', $deferOff);

        self::assertTrue(Widget::isAsyncWidget(['async' => true, 'async_module' => 'demo']));
        self::assertFalse(Widget::isAsyncWidget(['async' => true, 'async_module' => '']));
        self::assertFalse(Widget::isAsyncWidget(['async' => false, 'async_module' => 'demo']));
        self::assertFalse(Widget::isAsyncWidget([]));
    }

    public function testEditorAndPreviewRequestsForceSyncSsr(): void
    {
        self::assertFalse(Widget::asyncStubSuppressed());

        $_GET['editor_mode'] = '1';
        try {
            self::assertTrue(Widget::asyncStubSuppressed(), '编辑器模式必须回退同步 SSR');
        } finally {
            unset($_GET['editor_mode']);
        }

        $_GET['preview_mode'] = 'live';
        try {
            self::assertTrue(Widget::asyncStubSuppressed(), '预览模式必须回退同步 SSR');
        } finally {
            unset($_GET['preview_mode']);
        }
    }

    public function testRuntimeInlineEmitsStubForAsyncWidgetAndKeepsSyncWidgetSsr(): void
    {
        $stub = Widget::renderRuntimeInline(['type' => 'content', 'name' => 'app-download']);
        self::assertStringContainsString('data-widget-async="1"', $stub);
        self::assertStringContainsString('data-widget-code="app-download"', $stub);
        self::assertStringContainsString('data-widget-async-module="appDownloadFloat"', $stub);
        self::assertStringNotContainsString('weline-app-download', $stub, '异步部件首屏不得下发部件结构');
        self::assertMatchesRegularExpression(
            '~<link\b[^>]*app-download\.css~i',
            $stub,
            '异步部件的样式必须随占位一起下发，否则延后注入时无样式'
        );

        $sync = Widget::renderRuntimeInline(['type' => 'footer', 'name' => 'footer-copyright']);
        self::assertStringNotContainsString('data-widget-async="1"', $sync, '未声明 async 的部件必须保持同步 SSR');
    }

    private function parser(): WidgetTemplateParser
    {
        return (new \ReflectionClass(WidgetTemplateParser::class))->newInstanceWithoutConstructor();
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeParser(WidgetTemplateParser $parser, string $annotations): array
    {
        $reflection = new \ReflectionClass($parser);
        $parseWidgetMeta = $reflection->getMethod('parseWidgetMeta');
        $parseWidgetMeta->setAccessible(true);
        $normalize = $reflection->getMethod('normalizeAsyncMeta');
        $normalize->setAccessible(true);

        return $normalize->invoke($parser, $parseWidgetMeta->invoke($parser, $annotations));
    }
}
