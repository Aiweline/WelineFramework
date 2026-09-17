<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\SlotRendererService;

/** Execute the production slot pipeline; replace only I/O and final HTML rendering. */
final class SlotDictionaryPrefetchTest extends TestCase
{
    protected function setUp(): void
    {
        SlotPrefetchParserProbe::$calls = [];
        SlotPrefetchParserProbe::$timeline = [];
        SlotPrefetchTraceProbe::$phases = [];
    }

    public function testFilteredModulesArePrefetchedBeforeRenderWithoutReloadingLayout(): void
    {
        $flow = $this->flow();
        $flow->layout = ['content' => ['widgets' => [
            ['widget_module' => 'Weline_Product', 'widget_code' => 'second', 'slot_id' => 'main', 'sort_order' => 2],
            ['widget_module' => 'Weline_Product', 'widget_code' => 'first', 'slot_id' => 'main', 'sort_order' => 1],
            ['widget_module' => 'Weline_Blog', 'widget_code' => 'unused', 'slot_id' => 'missing'],
        ]]];
        $flow->layoutScoped = ['child' => [['widget_module' => 'Weline_Cart', 'widget_code' => 'child']]];
        $flow->sharedChrome = ['footer' => [['widget_module' => ' Weline_Theme ', 'widget_code' => 'footer']]];
        $html = '<main data-wslot="main"></main><footer data-wslot="footer"></footer>';

        $result = $flow->run($html);

        self::assertSame([['Weline_Cart', 'Weline_Product', 'Weline_Theme']], SlotPrefetchParserProbe::$calls);
        self::assertSame(['prefetch', 'render'], SlotPrefetchParserProbe::$timeline);
        self::assertSame(1, $flow->layoutReads);
        self::assertSame(['first', 'second', 'child', 'footer'], $flow->renderedCodes);
        self::assertSame($html . '|first,second,child,footer', $result);
        self::assertSame([], $flow->pageRenderContext);
        self::assertSame([
            'modules' => 3,
            'module_set_hash' => hash('sha256', 'Weline_Cart|Weline_Product|Weline_Theme'),
        ], SlotPrefetchTraceProbe::$phases['theme.slots.dictionary_prefetch']);
    }

    public function testNoSlotAndEmptyFilteredLayoutKeepExistingFastPaths(): void
    {
        $flow = $this->flow();
        self::assertSame('<p>plain</p>', $flow->run('<p>plain</p>'));
        self::assertSame(0, $flow->layoutReads);
        $flow->layout = ['content' => ['widgets' => [
            ['widget_module' => 'Weline_Blog', 'widget_code' => 'unused', 'slot_id' => 'missing'],
        ]]];
        $html = '<main data-wslot="main"></main>';
        self::assertSame($html, $flow->run($html));
        self::assertSame(1, $flow->layoutReads);
        self::assertSame([], SlotPrefetchParserProbe::$calls);
        self::assertSame([], $flow->renderedCodes);
    }

    private function flow(): SlotPrefetchFlowDependencies
    {
        $class = __NAMESPACE__ . '\\SlotPrefetchExtractedPipeline';
        if (!class_exists($class, false)) {
            $reflection = new \ReflectionClass(SlotRendererService::class);
            $lines = file($reflection->getFileName());
            $methods = '';
            foreach (['doProcessSlots', 'filterWidgetsForHtmlSlots', 'organizeWidgetsBySlot', 'prefetchSlotDictionaryModules'] as $name) {
                if (!$reflection->hasMethod($name)) {
                    continue;
                }
                $method = $reflection->getMethod($name);
                $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
                $methods .= str_replace('\\Weline\\Framework\\Phrase\\Parser', '\\' . SlotPrefetchParserProbe::class, $source) . "\n";
            }
            eval('namespace ' . __NAMESPACE__ . '; use Weline\\Theme\\Model\\ThemeLayout; use ' . SlotPrefetchTraceProbe::class . ' as RequestLifecycleTrace; final class SlotPrefetchExtractedPipeline extends SlotPrefetchFlowDependencies {' . $methods . ' public function run(string $html): string { return $this->doProcessSlots($html, 1, "products"); }}');
        }
        return new $class();
    }
}

final class SlotPrefetchParserProbe
{
    public static array $calls = [];
    public static array $timeline = [];
    public static function prefetchGlobalDictionaryModules(array $modules): void
    {
        self::$calls[] = $modules;
        self::$timeline[] = 'prefetch';
    }
}

final class SlotPrefetchTraceProbe
{
    public static array $phases = [];
    public static function measurePhase(string $name, callable $operation, array $meta = []): mixed
    {
        self::$phases[$name] = $meta;
        return $operation();
    }
}

/** Boundaries replaced here perform I/O, resource discovery, or rendering, never scheduling. */
abstract class SlotPrefetchFlowDependencies
{
    public array $layout = [];
    public array $layoutScoped = [];
    public array $sharedChrome = [];
    public int $layoutReads = 0;
    public array $renderedCodes = [];
    public array $pageRenderContext = [];
    protected array $filledSlotIdsThisRun = [];
    protected array $unavailableWidgets = [];
    abstract public function run(string $html): string;
    protected function traceCall(string $name, callable $operation): mixed { return $operation(); }
    protected function getLayoutData(...$args): array { ++$this->layoutReads; return $this->layout; }
    protected function mergeLayoutScopedSlotWidgets(array $widgets, ...$args): array { return array_replace($widgets, $this->layoutScoped); }
    protected function mergeSharedChromeSlotWidgets(array $widgets, ...$args): array { return array_replace($widgets, $this->sharedChrome); }
    protected function extractSlotIdsFromHtml(string $html): array
    {
        preg_match_all('/data-wslot="([^"]+)"/', $html, $matches);
        return array_fill_keys($matches[1], true);
    }
    protected function expandSlotIdsWithContainerChildSlots(array $widgets, array $ids): array
    {
        if (isset($ids['main'], $widgets['child'])) { $ids['child'] = true; }
        return $ids;
    }
    protected function htmlHasLayoutScopedSlots(string $html): bool { return false; }
    protected function stripEmptyTemplateWidgetShells(string $html): string { return $html; }
    protected function shouldInspectWidgetHtml(): bool { return false; }
    protected function appendWidgetHealthToastBridge(string $html): string { return $html; }
    protected function stampFinalWidgetHtmlHealth(string $html): string { return $html; }
    protected function capturePageRenderContext(): void { $this->pageRenderContext = ['captured' => true]; }
    protected function withRenderTheme(int $id, string $area, callable $operation): mixed { return $operation(); }
    protected function processSlotsWithBoundaries(string $html, array $widgets, ...$args): string
    {
        SlotPrefetchParserProbe::$timeline[] = 'render';
        foreach ($widgets as $rows) { foreach ($rows as $row) { $this->renderedCodes[] = $row['widget_code']; } }
        return $html . '|' . implode(',', $this->renderedCodes);
    }
}

function w_log_debug(...$args): void {}
