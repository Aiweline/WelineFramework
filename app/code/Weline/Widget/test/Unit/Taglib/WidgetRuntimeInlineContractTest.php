<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

/**
 * Taglib `<w:widget>` compile must emit a runtime relationship shell only.
 * Never bake request-scoped HTML into view/tpl com_*.phtml.
 */
final class WidgetRuntimeInlineContractTest extends TestCase
{
    private function widgetTaglibSource(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 3) . '/Taglib/Widget.php'
        );
    }

    public function testAllWidgetsCompileToRuntimeInlineShell(): void
    {
        $src = $this->widgetTaglibSource();
        self::assertStringContainsString('function renderRuntimeInline(', $src);
        self::assertStringContainsString('Widget::renderRuntimeInline(', $src);
        self::assertStringContainsString('固化/Taglib 编译只写入 slot↔widget 关系壳', $src);
        self::assertStringNotContainsString('function shouldDeferRequestHydration(', $src);
        self::assertStringNotContainsString("\$code === 'product-info'", $src);
    }

    public function testCompilePathDoesNotCallRenderWidget(): void
    {
        $src = $this->widgetTaglibSource();
        $callbackStart = strpos($src, 'public static function callback(): callable');
        $runtimeStart = strpos($src, 'public static function renderRuntimeInline(');
        self::assertNotFalse($callbackStart);
        self::assertNotFalse($runtimeStart);
        self::assertGreaterThan($callbackStart, $runtimeStart);

        $callbackBody = substr($src, $callbackStart, $runtimeStart - $callbackStart);
        self::assertStringNotContainsString('self::renderWidget(', $callbackBody);
        self::assertStringContainsString('renderRuntimeInline(', $callbackBody);
    }

    public function testDeferredInlineRemainsThinAlias(): void
    {
        $src = $this->widgetTaglibSource();
        self::assertStringContainsString('function renderDeferredInline(', $src);
        self::assertMatchesRegularExpression(
            '/function renderDeferredInline\(array \$spec\): string\s*\{\s*return self::renderRuntimeInline\(\$spec\);/s',
            $src
        );
    }
}
