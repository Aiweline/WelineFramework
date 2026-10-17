<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllWidgetTaglibContractsPass().
 */
final class WidgetTaglibContractsTest extends TestCase
{
    public function testAllWidgetTaglibContractsPass(): void
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
            'WidgetRuntimeInline::testAllWidgetsCompileToRuntimeInlineShell' => function () {
            $this->assert_WidgetRuntimeInline_testAllWidgetsCompileToRuntimeInlineShell();
        },
            'WidgetRuntimeInline::testCompilePathDoesNotCallRenderWidget' => function () {
            $this->assert_WidgetRuntimeInline_testCompilePathDoesNotCallRenderWidget();
        },
            'WidgetRuntimeInline::testDeferredInlineRemainsThinAlias' => function () {
            $this->assert_WidgetRuntimeInline_testDeferredInlineRemainsThinAlias();
        },
        ];
    }

private function widgetTaglibSource_WidgetRuntimeInline(): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Taglib/Widget.php');
}

private function assert_WidgetRuntimeInline_testAllWidgetsCompileToRuntimeInlineShell(): void
{
    $src = $this->widgetTaglibSource_WidgetRuntimeInline();
    self::assertStringContainsString('function renderRuntimeInline(', $src);
    self::assertStringContainsString('Widget::renderRuntimeInline(', $src);
    self::assertStringContainsString('固化/Taglib 编译只写入 slot↔widget 关系壳', $src);
    self::assertStringNotContainsString('function shouldDeferRequestHydration(', $src);
    self::assertStringNotContainsString("\$code === 'product-info'", $src);
}

private function assert_WidgetRuntimeInline_testCompilePathDoesNotCallRenderWidget(): void
{
    $src = $this->widgetTaglibSource_WidgetRuntimeInline();
    $callbackStart = strpos($src, 'public static function callback(): callable');
    $runtimeStart = strpos($src, 'public static function renderRuntimeInline(');
    self::assertNotFalse($callbackStart);
    self::assertNotFalse($runtimeStart);
    self::assertGreaterThan($callbackStart, $runtimeStart);
    $callbackBody = substr($src, $callbackStart, $runtimeStart - $callbackStart);
    self::assertStringNotContainsString('self::renderWidget(', $callbackBody);
    self::assertStringContainsString('renderRuntimeInline(', $callbackBody);
}

private function assert_WidgetRuntimeInline_testDeferredInlineRemainsThinAlias(): void
{
    $src = $this->widgetTaglibSource_WidgetRuntimeInline();
    self::assertStringContainsString('function renderDeferredInline(', $src);
    self::assertMatchesRegularExpression('/function renderDeferredInline\(array \$spec\): string\s*\{\s*return self::renderRuntimeInline\(\$spec\);/s', $src);
}
}
