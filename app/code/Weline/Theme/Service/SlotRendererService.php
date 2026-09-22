<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\RuntimeCachePolicy;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\View\Template;
use Weline\Theme\Exception\SlotBoundaryRequiredException;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Helper\FooterDefaultLinksHelper;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Helper\ProductCardAddToCartParams;
use Weline\Theme\Helper\ThemeData;
use Weline\Product\Service\ProductCardRenderer;
use Weline\Theme\Interface\ThemePlaceableRegistryInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Taglib\Slot;
use Weline\Widget\Api\WidgetRegistryInterface;
use Weline\Widget\Api\Rendering\RuntimeTemplateRendererInterface;

/**
 * 插槽渲染服务
 * 
 * 基于属性标记的插槽系统：
 * - 使用 data-wslot 系列属性标记插槽，不添加额外DOM元素
 * - 支持在任何HTML元素上标记为插槽
 * - 不影响原有布局样式
 * 
 * 属性规范：
 * - data-wslot="slot-id"          必需，插槽ID
 * - data-wslot-name="名称"         可选，显示名称（编辑器用）
 * - data-wslot-accept="a,b,c"     可选，接受的部件代码列表
 * - data-wslot-position="header"  可选，位置类型
 * - data-wslot-exclusive="true"   可选，widget替换整个内容
 * - data-wslot-append="true"      可选，widget追加到内容后
 * - data-wslot-prepend="true"     可选，widget插入到内容前
 * - data-wslot-multiple="true"    可选，允许多个widget
 */
class SlotRendererService
{
    private ThemeLayoutService $layoutService;
    private WidgetRegistryInterface $widgetRegistry;
    private ThemePlaceableRegistryInterface $placeableRegistry;

    /**
     * Opaque blocks parked during DOMDocument parse (script/style + widget-wrapper inners).
     * Parking wrapper inners prevents libxml from reparenting <section>/<article> out of the shell.
     *
     * @var array<string, string>
     */
    private array $domOpaqueTokens = [];
    private ThemeComponentRenderer $componentRenderer;
    private Template $template;

    // 缓存
    private array $widgetCache = [];
    private array $layoutCache = [];
    private const PUBLISHED_LAYOUT_CACHE_TTL = 120.0;
    private const WIDGET_OUTPUT_CACHE_TTL = 120.0;
    private const MAX_PUBLISHED_LAYOUT_CACHE_ENTRIES = 128;
    private const MAX_WIDGET_OUTPUT_CACHE_ENTRIES = 128;
    private const CACHEABLE_WIDGET_OUTPUTS = [];
    private static array $publishedLayoutDataCache = [];
    private static array $widgetOutputCache = [];
    private static ?SharedCacheStateInterface $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;
    private static ?\WeakMap $fiberRenderYieldAt = null;
    private const WLS_RENDER_YIELD_MIN_INTERVAL_US = 10000;
    
    // 孤儿部件（找不到对应slot的部件）
    private array $orphanWidgets = [];

    /**
     * 失效部件：槽位仍在，但模块/定义/模板已缺失或渲染失败。
     *
     * @var list<array{
     *   slot_id:string,
     *   layout_id:int,
     *   node_uid:string,
     *   widget_code:string,
     *   widget_module:string,
     *   widget_name:string,
     *   reason:string,
     *   message:string
     * }>
     */
    private array $unavailableWidgets = [];

    /** 当前渲染周期内已填充的 slot_id，同一 slot_id 只填充文档中第一处出现，避免容器部件内层同名插槽被重复填充导致泄露 */
    private array $filledSlotIdsThisRun = [];

    /**
     * 页面级数据快照（如 storefront_offer）。
     * ThemeComponentRenderer / RuntimeTemplateMaterializer 会 unsetData()，
     * 必须在首个部件渲染前捕获并回注到部件 config。
     *
     * @var array<string, mixed>
     */
    private array $pageRenderContext = [];

    /** 当前渲染周期使用的主题（用于部件模板覆盖解析，确保预览显示所选主题而非全局激活主题） */
    private ?WelineTheme $renderTheme = null;

    /** 当前渲染区域：frontend/backend。 */
    private string $renderArea = 'frontend';

    private ?SlotBoundaryScanner $boundaryScanner = null;

    private ?SlotHtmlOpaqueParker $boundaryParker = null;

    /** 已加载主题缓存：theme_id => WelineTheme|null */
    private array $renderThemeCache = [];

    private RuntimeTemplateRendererInterface $runtimeTemplateRenderer;
    private LayoutValueHydrationRegistry $layoutValueHydrators;

    public function __construct(
        ThemeLayoutService $layoutService,
        WidgetRegistryInterface $widgetRegistry,
        mixed $placeableRegistry,
        ThemeComponentRenderer $componentRenderer,
        Template $template,
        RuntimeTemplateRendererInterface $runtimeTemplateRenderer,
        ?LayoutValueHydrationRegistry $layoutValueHydrators = null,
    ) {
        $this->layoutService = $layoutService;
        $this->widgetRegistry = $widgetRegistry;
        $this->placeableRegistry = $this->resolvePlaceableRegistry($placeableRegistry);
        $this->componentRenderer = $componentRenderer;
        $this->template = $template;
        $this->runtimeTemplateRenderer = $runtimeTemplateRenderer;
        $this->layoutValueHydrators = $layoutValueHydrators
            ?? ObjectManager::getInstance(LayoutValueHydrationRegistry::class);
    }

    /**
     * 处理 HTML 中的所有插槽
     * 
     * @param string $html HTML内容
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param string $status 状态：draft=草稿（后台预览），published=已发布（前端显示）
     * @return string 处理后的HTML
     */
    public function processSlots(
        string $html,
        int $themeId,
        string $pageType,
        string $status = ThemeLayout::STATUS_PUBLISHED,
        string $area = 'frontend'
    ): string
    {
        return $this->traceCall(
            'slot_renderer::processSlots',
            fn() => $this->doProcessSlots($html, $themeId, $pageType, $status, $area)
        );
    }

    public function processSlotsWithLayout(
        string $html,
        array $layoutData,
        bool $filterToHtmlSlots = false,
        int $themeId = 0,
        string $area = 'frontend'
    ): string
    {
        return $this->traceCall(
            'slot_renderer::processSlotsWithLayout',
            function () use ($html, $layoutData, $filterToHtmlSlots, $themeId, $area): string {
                if (strpos($html, 'data-wslot') === false && strpos($html, 'widget-slot-area') === false) {
                    return $html;
                }

                $slotWidgets = $this->organizeWidgetsBySlot($layoutData);
                $pageType = trim((string)($layoutData['page_type'] ?? $layoutData['layout_type'] ?? ''));
                $status = trim((string)($layoutData['status'] ?? ''));
                if ($status !== ThemeLayout::STATUS_PUBLISHED && $status !== ThemeLayout::STATUS_DRAFT) {
                    $status = ThemeLayout::STATUS_DRAFT;
                }

                // Align with doProcessSlots: scoped/cms/product layouts omit chrome widgets;
                // fill empty header/footer from the per-scope homepage chrome carrier.
                if ($themeId > 0 && $pageType !== '') {
                    $slotWidgets = $this->mergeLayoutScopedSlotWidgets(
                        $slotWidgets,
                        $html,
                        $themeId,
                        $pageType,
                        $status,
                        $area
                    );
                    $slotWidgets = $this->mergeSharedChromeSlotWidgets(
                        $slotWidgets,
                        $html,
                        $themeId,
                        $pageType,
                        $status,
                        $area
                    );
                }

                if ($filterToHtmlSlots) {
                    $slotWidgets = $this->filterWidgetsForHtmlSlots($slotWidgets, $html);
                }

                if (empty($slotWidgets)) {
                    return $html;
                }

                $this->filledSlotIdsThisRun = [];
                $this->unavailableWidgets = [];
                $this->orphanWidgets = [];
                $this->capturePageRenderContext();
                try {
                    $processed = $this->withRenderTheme(
                        $themeId,
                        $area,
                        fn(): string => $this->processSlotsWithBoundaries($html, $slotWidgets, $filterToHtmlSlots, $pageType)
                    );
                    $processed = $this->stripEmptyTemplateWidgetShells($processed);
                    if ($this->shouldInspectWidgetHtml()) {
                        $processed = $this->repairUnhealthyWidgetWrappers($processed);
                    }

                    $stamped = $this->appendWidgetHealthToastBridge(
                        $this->stampFinalWidgetHtmlHealth($processed)
                    );
                    return $stamped;
                } finally {
                    $this->pageRenderContext = [];
                }
            }
        );
    }

    private function doProcessSlots(
        string $html,
        int $themeId,
        string $pageType,
        string $status = ThemeLayout::STATUS_PUBLISHED,
        string $area = 'frontend'
    ): string
    {
        if ((string)\getenv('WELINE_DIAG_MEMORY') === '1') {
            \error_log('[MemoryProbe] ' . \json_encode([
                'component' => 'SlotRendererService::doProcessSlots',
                'phase' => 'start',
                'content_bytes' => \strlen($html),
                'theme_id' => $themeId,
                'page_type' => $pageType,
                'usage' => \memory_get_usage(true),
                'peak' => \memory_get_peak_usage(true),
            ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }
        // 检查是否包含插槽标记（支持新旧两种方式）
        if (strpos($html, 'data-wslot') === false && strpos($html, 'widget-slot-area') === false) {
            return $html;
        }

        // 获取该主题和页面类型的布局配置
        $layoutData = $this->traceCall(
            'slot_renderer::getLayoutData',
            fn() => $this->getLayoutData($themeId, $pageType, $status, $area)
        );

        // 按插槽 ID 组织部件
        $slotWidgets = $this->traceCall(
            'slot_renderer::organizeWidgetsBySlot',
            fn() => $this->organizeWidgetsBySlot($layoutData)
        );

        $slotWidgets = $this->traceCall(
            'slot_renderer::mergeLayoutScopedSlotWidgets',
            fn() => $this->mergeLayoutScopedSlotWidgets($slotWidgets, $html, $themeId, $pageType, $status, $area)
        );

        // 页头/页脚是全局 chrome（一改全站）：各 pageType 缺槽时合并同一套全局 chrome 部件。
        $slotWidgets = $this->traceCall(
            'slot_renderer::mergeSharedChromeSlotWidgets',
            fn() => $this->mergeSharedChromeSlotWidgets($slotWidgets, $html, $themeId, $pageType, $status, $area)
        );

        if ($status === ThemeLayout::STATUS_PUBLISHED) {
            $slotWidgets = $this->traceCall(
                'slot_renderer::filterWidgetsForHtmlSlots',
                fn() => $this->filterWidgetsForHtmlSlots($slotWidgets, $html)
            );
        }
        
        // 调试日志（开发模式）
        if (defined('DEV') && DEV) {
            $widgetCount = 0;
            foreach ($slotWidgets as $slotId => $widgets) {
                $widgetCount += count($widgets);
            }
            w_log_debug(sprintf(
                '[SlotRenderer] processSlots: themeId=%d, pageType=%s, status=%s, slots=%d, widgets=%d',
                $themeId,
                $pageType,
                $status,
                count($slotWidgets),
                $widgetCount
            ));
            foreach ($slotWidgets as $slotId => $widgets) {
                w_log_debug(sprintf('[SlotRenderer]   Slot "%s": %d widgets', $slotId, count($widgets)));
            }
        }

        if (empty($slotWidgets) && !$this->htmlHasLayoutScopedSlots($html)) {
            $html = $this->stripEmptyTemplateWidgetShells($html);
            if ($this->shouldInspectWidgetHtml()) {
                $html = $this->repairUnhealthyWidgetWrappers($html);
            }

            return $this->appendWidgetHealthToastBridge(
                $this->stampFinalWidgetHtmlHealth($html)
            );
        }

        $this->prefetchSlotDictionaryModules($slotWidgets);

        // Boundaries-only slot fill (legacy DOM engine removed).
        $this->filledSlotIdsThisRun = [];
        $this->unavailableWidgets = [];
        $this->orphanWidgets = [];
        $this->capturePageRenderContext();
        try {
            $html = $this->traceCall(
                'slot_renderer::processSlotsWithBoundaries',
                fn() => $this->withRenderTheme(
                    $themeId,
                    $area,
                    fn(): string => $this->processSlotsWithBoundaries(
                        $html,
                        $slotWidgets,
                        $status === ThemeLayout::STATUS_PUBLISHED,
                        $pageType
                    )
                )
            );
            if ((string)\getenv('WELINE_DIAG_MEMORY') === '1') {
                \error_log('[MemoryProbe] ' . \json_encode([
                    'component' => 'SlotRendererService::doProcessSlots',
                    'phase' => 'after_boundaries',
                    'content_bytes' => \strlen($html),
                    'usage' => \memory_get_usage(true),
                    'peak' => \memory_get_peak_usage(true),
                ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            }

            $html = $this->stripEmptyTemplateWidgetShells($html);
            if ($this->shouldInspectWidgetHtml()) {
                $html = $this->repairUnhealthyWidgetWrappers($html);
            }

            return $this->appendWidgetHealthToastBridge(
                $this->stampFinalWidgetHtmlHealth($html)
            );
        } finally {
            $this->pageRenderContext = [];
        }
    }
    /** Prefetch data only; each template still activates its own translation module. */
    private function prefetchSlotDictionaryModules(array $slotWidgets): void
    {
        // Rolling workers may still have the previous Parser class loaded.
        if (!\method_exists(\Weline\Framework\Phrase\Parser::class, 'prefetchGlobalDictionaryModules')) {
            return;
        }

        $modules = [];
        foreach ($slotWidgets as $widgets) {
            foreach ($widgets as $widget) {
                $module = $widget['widget_module'] ?? null;
                if (\is_string($module) && ($module = \trim($module)) !== '') {
                    $modules[$module] = true;
                }
            }
        }
        if ($modules === []) {
            return;
        }

        $modules = \array_keys($modules);
        \sort($modules, \SORT_STRING);
        RequestLifecycleTrace::measurePhase(
            'theme.slots.dictionary_prefetch',
            static function () use ($modules): void {
                \Weline\Framework\Phrase\Parser::prefetchGlobalDictionaryModules($modules);
            },
            [
                'modules' => \count($modules),
                'module_set_hash' => \hash('sha256', \implode('|', $modules)),
            ],
        );
    }


    /**
     * Theme-preview content path may skip processSlots (wrappers already present).
     * Always drop empty template shells; re-stamp health only in DEV/preview inspect mode.
     */
    public function finalizePreviewWidgetHealth(string $html): string
    {
        if ($html === '') {
            return $html;
        }


        $html = $this->stripEmptyTemplateWidgetShells($html);
        if (!$this->shouldInspectWidgetHtml()) {
            return $html;
        }

        // Re-render empty/shredded wrappers from data-config before stamping health toasts.
        // Repair resets purchase-actions emit flag so the component re-brings its own CSS.
        $html = $this->repairUnhealthyWidgetWrappers($html);

        $html = $this->appendWidgetHealthToastBridge($this->stampFinalWidgetHtmlHealth($html));
        return $html;
    }

    /**
     * Remove empty data-weline-template-widget shells only when a non-empty sibling
     * wrapper with the same data-widget-code already exists (layout fill).
     * Never delete the sole instance of a widget code (would blank the slot).
     */
    private function stripEmptyTemplateWidgetShells(string $html): string
    {
        if ($html === '' || !str_contains($html, 'data-weline-template-widget')) {
            return $html;
        }

        $length = \strlen($html);
        $offset = 0;
        $out = '';

        while ($offset < $length) {
            $open = $this->findNextTemplateWidgetShellOpen($html, $offset);
            if ($open === null) {
                $out .= \substr($html, $offset);
                break;
            }

            $openStart = $open['start'];
            $openTag = $open['tag'];
            $openEnd = $open['end'];
            $out .= \substr($html, $offset, $openStart - $offset);

            $innerEnd = $this->findMatchingDivClose($html, $openEnd);
            if ($innerEnd === null) {
                $out .= \substr($html, $openStart);
                break;
            }

            $inner = \substr($html, $openEnd, $innerEnd - $openEnd);
            $fullEnd = $innerEnd + 6;
            $withoutComments = \trim(\preg_replace('/<!--.*?-->/s', '', $inner) ?? $inner);
            $meaningful = \trim(\strip_tags($withoutComments));
            $code = '';
            if (\preg_match('/\bdata-widget-code\s*=\s*(["\'])([^"\']*)\1/i', $openTag, $codeMatch) === 1) {
                $code = \trim((string)$codeMatch[2]);
            }
            $hasHealthySibling = $code !== '' && $this->htmlHasHealthyWidgetSibling($html, $code, $openStart, $fullEnd);
            if ($meaningful === '' && $hasHealthySibling) {
                $offset = $fullEnd;
                continue;
            }

            $out .= \substr($html, $openStart, $fullEnd - $openStart);
            $offset = $fullEnd;
        }

        return $out;
    }

    /**
     * Quote-aware open for data-weline-template-widget=1 shells (attrs may contain ">").
     *
     * @return array{start:int,end:int,tag:string}|null
     */
    private function findNextTemplateWidgetShellOpen(string $html, int $offset): ?array
    {
        $length = \strlen($html);
        $pos = \max(0, $offset);
        while ($pos < $length) {
            if (\preg_match('/<div\b/i', $html, $match, \PREG_OFFSET_CAPTURE, $pos) !== 1) {
                return null;
            }
            $start = (int)$match[0][1];
            $end = $this->findHtmlTagClose($html, $start + 4);
            if ($end === null) {
                return null;
            }
            $tag = \substr($html, $start, $end - $start);
            if (\preg_match('/\bdata-weline-template-widget\s*=\s*(["\']?)1\1/i', $tag) === 1) {
                return ['start' => $start, 'end' => $end, 'tag' => $tag];
            }
            $pos = $end;
        }

        return null;
    }

    /**
     * True when another .widget-wrapper with the same code has meaningful HTML body.
     */
    private function htmlHasHealthyWidgetSibling(string $html, string $code, int $skipStart, int $skipEnd): bool
    {
        if ($code === '' || !\preg_match_all(
            '/<div\b[^>]*\bwidget-wrapper\b[^>]*\bdata-widget-code\s*=\s*(["\'])'
            . \preg_quote($code, '/')
            . '\1[^>]*>/i',
            $html,
            $matches,
            \PREG_OFFSET_CAPTURE
        )) {
            return false;
        }

        foreach ($matches[0] as $match) {
            $openStart = (int)$match[1];
            $openTag = (string)$match[0];
            $openEnd = $openStart + \strlen($openTag);
            if ($openStart >= $skipStart && $openStart < $skipEnd) {
                continue;
            }
            if (\stripos($openTag, 'data-weline-template-widget') !== false) {
                // Prefer a layout sibling; template-to-template is not "healthy fill".
                continue;
            }
            $innerEnd = $this->findMatchingDivClose($html, $openEnd);
            if ($innerEnd === null) {
                continue;
            }
            $inner = \substr($html, $openEnd, $innerEnd - $openEnd);
            if (\stripos($inner, 'wc-theme_widget_') !== false || \strlen(\trim(\strip_tags($inner))) > 32) {
                return true;
            }
        }

        return false;
    }

    /**
     * 在指定主题上下文中执行渲染回调。
     *
     * 部件模板的覆盖解析依赖 ThemeData 的「当前主题」，预览编辑器渲染的主题
     * 通常不是全局激活主题。此方法在渲染期间临时切换到目标主题，渲染结束后
     * 恢复，确保「看的是哪个主题，渲染的就是哪个主题」。
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withRenderTheme(int $themeId, string $area, callable $callback)
    {
        $area = $area === 'backend' ? 'backend' : 'frontend';
        $theme = $this->resolveRenderTheme($themeId);
        if (!$theme) {
            $previousRenderArea = $this->renderArea;
            $this->renderArea = $area;
            try {
                return $callback();
            } finally {
                $this->renderArea = $previousRenderArea;
            }
        }

        $previousRenderTheme = $this->renderTheme;
        $previousRenderArea = $this->renderArea;
        $previousThemeData = ThemeData::getCurrentTheme();
        $previousArea = ThemeData::getCurrentArea();
        $shouldSwitchThemeData = (int)($previousThemeData?->getId() ?? 0) !== (int)$theme->getId()
            || (string)$previousArea !== $area;

        $this->renderTheme = $theme;
        $this->renderArea = $area;
        if ($shouldSwitchThemeData) {
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
        }

        try {
            return $callback();
        } finally {
            $this->renderTheme = $previousRenderTheme;
            $this->renderArea = $previousRenderArea;
            if ($shouldSwitchThemeData) {
                ThemeData::setCurrentTheme($previousThemeData);
                ThemeData::setCurrentArea($previousArea);
            }
        }
    }

    /**
     * 按 ID 加载渲染主题（带缓存）。0 或无效 ID 返回 null（沿用默认上下文）。
     */
    private function resolveRenderTheme(int $themeId): ?WelineTheme
    {
        if ($themeId <= 0) {
            return null;
        }

        if (array_key_exists($themeId, $this->renderThemeCache)) {
            return $this->renderThemeCache[$themeId];
        }

        $theme = null;
        try {
            $model = clone ObjectManager::getInstance(WelineTheme::class);
            $model->clearData()->clearQuery()->load($themeId);
            if ($model->getId()) {
                $theme = $model;
            }
        } catch (\Throwable) {
            $theme = null;
        }

        $this->renderThemeCache[$themeId] = $theme;
        return $theme;
    }

    /**
     * 处理草稿模式的插槽（后台预览用）
     */
    private function filterWidgetsForHtmlSlots(array $slotWidgets, string $html): array
    {
        if ($slotWidgets === []) {
            return [];
        }

        $slotIds = RequestLifecycleTrace::measurePhase(
            'theme.slots.html_slot_ids',
            fn(): array => $this->extractSlotIdsFromHtml($html),
            ['bytes' => \strlen($html)],
        );
        $slotIds = RequestLifecycleTrace::measurePhase(
            'theme.slots.expand_child_ids',
            fn(): array => $this->expandSlotIdsWithContainerChildSlots($slotWidgets, $slotIds),
            ['slots' => \count($slotIds), 'widget_slots' => \count($slotWidgets)],
        );
        if ($slotIds === []) {
            return [];
        }

        return \array_intersect_key($slotWidgets, $slotIds);
    }

    /**
     * 合并当前布局模板源码中声明的 w:slot（含被 &lt;if&gt;/meta 门控、本轮 HTML 未出现的槽）。
     * 仅用于孤儿检测：避免「相关/搭配」等默认关闭区误报「找不到插槽」。
     *
     * @param array<string, true> $slotIds
     * @param array<string, list<array<string, mixed>>> $slotWidgets
     * @return array<string, true>
     */
    private function expandSlotIdsWithLayoutDeclaredSlots(
        string $pageType,
        array $slotIds,
        array $slotWidgets = [],
    ): array {
        $pageType = \strtolower(\trim(\str_replace('\\', '/', $pageType), '/ '));
        if ($pageType !== '') {
            $slotIds = $this->mergeCatalogLayoutSlotIds($pageType, $slotIds);
        }

        // Editor canvas sometimes resolves pageType as default/homepage while product
        // widgets remain in the workspace. Still honor product layout declarations for
        // gated slots (showRelatedProducts=false) so they are not false orphans.
        $needsProductLayout = false;
        foreach (\array_keys($slotWidgets) as $widgetSlotId) {
            $widgetSlotId = \strtolower(\trim((string)$widgetSlotId));
            if ($widgetSlotId === 'product-related-products'
                || $widgetSlotId === 'product-cross-sell'
                || $widgetSlotId === 'product-bestsellers'
                || \str_starts_with($widgetSlotId, 'product-')
            ) {
                $needsProductLayout = true;
                break;
            }
        }
        if ($needsProductLayout && $pageType !== ThemeLayout::PAGE_TYPE_PRODUCT) {
            $slotIds = $this->mergeCatalogLayoutSlotIds(ThemeLayout::PAGE_TYPE_PRODUCT, $slotIds);
        }

        return $slotIds;
    }

    /**
     * @param array<string, true> $slotIds
     * @return array<string, true>
     */
    private function mergeCatalogLayoutSlotIds(string $pageType, array $slotIds): array
    {
        $pageType = \strtolower(\trim($pageType));
        if ($pageType === '') {
            return $slotIds;
        }

        $area = $this->renderArea === 'backend' ? 'backend' : 'frontend';
        $option = 'default';
        try {
            $identity = $this->currentLayoutIdentity($area);
            $option = \strtolower(\trim((string)($identity['layout_option'] ?? 'default')));
            if ($option === '') {
                $option = 'default';
            }
        } catch (\Throwable) {
            $option = 'default';
        }

        try {
            /** @var ThemeResourceCatalog $catalog */
            $catalog = ObjectManager::getInstance(ThemeResourceCatalog::class);
            $resource = $catalog->getLayoutResource($area, $this->renderTheme, $pageType, $option);
            if ($resource === null && $option !== 'default') {
                $resource = $catalog->getLayoutResource($area, $this->renderTheme, $pageType, 'default');
            }
            if ($resource === null) {
                return $slotIds;
            }

            foreach ($resource['slots'] ?? [] as $slot) {
                $slotId = '';
                if ($slot instanceof \Weline\Theme\Dto\ThemeSlotDefinition) {
                    $slotId = \trim((string)$slot->id);
                } elseif (\is_array($slot)) {
                    $slotId = \trim((string)($slot['id'] ?? ''));
                }
                if ($slotId !== '') {
                    $slotIds[$slotId] = true;
                }
            }
        } catch (\Throwable) {
            // best-effort：目录不可用时回退为仅 HTML 可见槽
        }

        return $slotIds;
    }

    /**
     * 容器部件的子槽在首轮 HTML 中尚不存在（需等父部件渲染后才输出 data-wslot），
     * 但仍应保留其布局部件，供嵌套插槽迭代填充。
     *
     * @param array<string, true> $slotIds
     * @return array<string, true>
     */
    private function expandSlotIdsWithContainerChildSlots(array $slotWidgets, array $slotIds): array
    {
        $renderArea = $this->renderArea === 'backend' ? 'backend' : 'frontend';

        foreach ($slotWidgets as $widgets) {
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }

                $widgetCode = (string)($widget['widget_code'] ?? '');
                // footer-container 的标准扩展槽写在模板字面量 w:slot 中；定义侧 slots 可能滞后，
                // 发布态 filter 仍须保留这些子槽，否则预览有内容、发布后扩展链接整列空白。
                if ($widgetCode === 'footer-container') {
                    foreach (FooterDefaultLinksHelper::standardExtensionSlotIds() as $childSlotId) {
                        $childSlotId = \trim((string)$childSlotId);
                        if ($childSlotId !== '') {
                            $slotIds[$childSlotId] = true;
                        }
                    }
                }

                $definition = $this->placeableRegistry->find(
                    (string)($widget['widget_module'] ?? ''),
                    (string)($widget['widget_type'] ?? ''),
                    $widgetCode,
                    $this->renderTheme,
                    $renderArea,
                );
                if ($definition === null || !$definition->isContainer || $definition->slots === []) {
                    continue;
                }

                foreach (\array_keys($definition->slots) as $childSlotId) {
                    $childSlotId = \trim((string)$childSlotId);
                    if ($childSlotId !== '') {
                        $slotIds[$childSlotId] = true;
                    }
                }
            }
        }

        return $slotIds;
    }

    /**
     * @return array<string, true>
     */
    private function extractSlotIdsFromHtml(string $html): array
    {
        $slotIds = [];

        // Both attribute spellings are read in one pass. This runs for every
        // published page before the boundary renderer and used to scan the
        // complete response twice (which is noticeable on 1MB+ storefront HTML).
        if (\preg_match_all('/\b(data-wslot|data-slot-id)\s*=\s*(["\'])(.*?)\2/is', $html, $matches)) {
            foreach ($matches[3] as $slotId) {
                $slotId = \trim((string)$slotId);
                if ($slotId !== '') {
                    $slotIds[$slotId] = true;
                }
            }
        }

        return $slotIds;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slotWidgets
     * @return array<string, list<array<string, mixed>>>
     */
    private function mergeLayoutScopedSlotWidgets(
        array $slotWidgets,
        string $html,
        int $themeId,
        string $pageType,
        string $status,
        string $area
    ): array {
        $bindings = $this->extractSlotLayoutBindingsFromHtml($html);
        if ($bindings === []) {
            return $slotWidgets;
        }

        foreach ($bindings as $slotId => $layoutType) {
            $layoutType = trim($layoutType);
            if ($slotId === '' || $layoutType === '' || $layoutType === $pageType) {
                continue;
            }

            $externalLayout = $this->getLayoutData($themeId, $layoutType, $status, $area);
            $externalSlotWidgets = $this->organizeWidgetsBySlot($externalLayout);
            if (!empty($externalSlotWidgets[$slotId])) {
                $slotWidgets[$slotId] = $externalSlotWidgets[$slotId];
            }
        }

        return $slotWidgets;
    }

    /**
     * 页头/页脚是全局 chrome：不属于 blog/product 等任一业务布局，编辑一次全站生效。
     *
     * 可视化当前把全局 chrome 持久化在 homepage workspace（存储载体，不是归属）。
     * 其它 pageType 合并该套全局 chrome（含嵌套 header/footer 扩展槽）；
     * 本页本地 chrome 副本不得覆盖全局，且不得整页回退 homepage，以免混入首页内容区部件。
     *
     * @param array<string, list<array<string, mixed>>> $slotWidgets
     * @return array<string, list<array<string, mixed>>>
     */
    private function mergeSharedChromeSlotWidgets(
        array $slotWidgets,
        string $html,
        int $themeId,
        string $pageType,
        string $status,
        string $area
    ): array {
        unset($status);
        if ($themeId < 1) {
            return $slotWidgets;
        }

        $globalChromeSlotWidgets = $this->loadSharedChromeSlotWidgetsFromEntity($themeId, $area);
        if ($globalChromeSlotWidgets === []) {
            return $slotWidgets;
        }

        foreach ($globalChromeSlotWidgets as $slotId => $widgets) {
            if ($widgets === [] || !$this->slotWidgetsBelongToSharedChrome((string)$slotId, $widgets)) {
                continue;
            }
            // Homepage carrier: prefer entity chrome inject for root header/footer to
            // avoid double-fill. If inject left the exclusive shell empty (stale chrome
            // pointer / soft-skip), still merge so storefront is not stuck with
            // weline-footer--shell.
            if ($pageType === ThemeLayout::PAGE_TYPE_HOME
                && \in_array((string)$slotId, SharedChromeService::CHROME_SLOTS, true)
                && $this->htmlHasRenderedChromeRootWidget($html, (string)$slotId)
            ) {
                continue;
            }
            $slotWidgets[$slotId] = $widgets;
        }

        return $slotWidgets;
    }

    /**
     * True when the exclusive chrome root already contains a rendered widget wrapper
     * (not the empty weline-*-shell placeholder left by the layout template).
     */
    private function htmlHasRenderedChromeRootWidget(string $html, string $slotId): bool
    {
        $slotId = \strtolower(\trim($slotId));
        if ($html === '' || ($slotId !== 'header' && $slotId !== 'footer')) {
            return false;
        }
        try {
            $open = SlotBoundaryMarkers::open($slotId);
            $close = SlotBoundaryMarkers::close($slotId);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $openPos = \strpos($html, $open);
        if ($openPos === false) {
            return false;
        }
        $innerStart = $openPos + \strlen($open);
        $closePos = \strpos($html, $close, $innerStart);
        if ($closePos === false) {
            return false;
        }
        $inner = \substr($html, $innerStart, $closePos - $innerStart);
        if ($inner === '' || !\str_contains($inner, 'data-widget-code=')) {
            return false;
        }
        if ($slotId === 'footer' && \str_contains($inner, 'weline-footer--shell')
            && !\str_contains($inner, 'footer-container')
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function loadSharedChromeSlotWidgetsFromEntity(int $themeId, string $area): array
    {
        unset($area);
        try {
            /** @var \Weline\Theme\Service\ThemeScopeVersionService $scopeVersions */
            $scopeVersions = ObjectManager::getInstance(\Weline\Theme\Service\ThemeScopeVersionService::class);
            /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder $slotTree */
            $slotTree = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder::class);
            /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore $configStore */
            $configStore = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore::class);

            $scope = $this->resolveStorageScopeForSharedChrome(
                $this->renderArea === 'backend' ? 'backend' : 'frontend',
            );
            if ($scope === '') {
                return [];
            }

            $version = $scopeVersions->getPublished($themeId, $scope)
                ?? $scopeVersions->getCurrent($themeId, $scope);
            if ($version === null || $version->getVersionId() < 1) {
                return [];
            }

            $nodes = $version->getChromePayload();
            if ($nodes === []) {
                return [];
            }

            $layout = $slotTree->nodesToAreaLayout($nodes);
            $configByUid = $configStore->readChromeConfig($themeId, $scope, $version->getVersionId());
            foreach ($layout as $areaKey => $areaData) {
                if (!\is_array($areaData['widgets'] ?? null)) {
                    continue;
                }
                foreach ($areaData['widgets'] as $index => $widget) {
                    if (!\is_array($widget)) {
                        continue;
                    }
                    $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
                    if ($uid !== '' && isset($configByUid[$uid]) && \is_array($configByUid[$uid])) {
                        $layout[$areaKey]['widgets'][$index] = \array_replace($widget, $configByUid[$uid]);
                    }
                }
            }

            return $this->organizeWidgetsBySlot($layout);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Shared chrome carrier identity: same store/website scope, global target only.
     * Locale is omitted — chrome mounts are structural; translated HTML is presentation-layer.
     *
     * @param array{layout_option?:string,scope?:string,target_type?:string,target_id?:int,locale_code?:string} $identity
     * @return array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code:string}
     */
    private function resolveStorageScopeForSharedChrome(string $area): string
    {
        try {
            $identity = $this->currentLayoutIdentity($area);
            $scope = \trim((string)($identity['scope'] ?? ''));
            if ($scope !== '') {
                return $scope;
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            if (RequestContext::isInitialized()) {
                $scopeIdentity = RequestContext::scopeIdentity();
                if ($scopeIdentity instanceof \Weline\Framework\Runtime\ScopeIdentity) {
                    /** @var \Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface $scopes */
                    $scopes = ObjectManager::getInstance(
                        \Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class,
                    );

                    return $scopes->contextFromIdentity($scopeIdentity)->storageScope;
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        return 'default.default.default';
    }

    private function sharedChromeCarrierIdentity(array $identity): array
    {
        return [
            'layout_option' => trim((string)($identity['layout_option'] ?? 'default')) !== ''
                ? trim((string)$identity['layout_option'])
                : 'default',
            'scope' => (string)($identity['scope'] ?? 'default'),
            'target_type' => ThemeVirtualLayout::TARGET_GLOBAL,
            'target_id' => 0,
            'locale_code' => '',
        ];
    }

    /**
     * @param list<array<string, mixed>> $widgets
     */
    private function slotWidgetsBelongToSharedChrome(string $slotId, array $widgets): bool
    {
        $slotId = strtolower(trim($slotId));
        if ($slotId === 'header' || $slotId === 'footer'
            || str_starts_with($slotId, 'header-')
            || str_starts_with($slotId, 'header_')
            || str_starts_with($slotId, 'footer-')
            || str_starts_with($slotId, 'footer_')
        ) {
            return true;
        }

        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $widgetArea = strtolower(trim((string)($widget['area'] ?? '')));
            if ($widgetArea === 'header' || $widgetArea === 'footer') {
                return true;
            }
        }

        return false;
    }

    private function htmlHasLayoutScopedSlots(string $html): bool
    {
        return $this->extractSlotLayoutBindingsFromHtml($html) !== [];
    }

    /**
     * @return array<string, string> slotId => layoutType
     */
    private function extractSlotLayoutBindingsFromHtml(string $html): array
    {
        $bindings = [];
        if (!preg_match_all('/<[^>]*\bdata-wslot\s*=\s*(["\'])([^"\']+)\1[^>]*>/is', $html, $matches, PREG_SET_ORDER)) {
            return $bindings;
        }

        foreach ($matches as $match) {
            $tag = (string)($match[0] ?? '');
            $slotId = trim((string)($match[2] ?? ''));
            if ($slotId === '' || !preg_match('/\bdata-wslot-layout\s*=\s*(["\'])([^"\']+)\1/i', $tag, $layoutMatch)) {
                continue;
            }
            $layoutType = trim((string)($layoutMatch[2] ?? ''));
            if ($layoutType !== '') {
                $bindings[$slotId] = $layoutType;
            }
        }

        return $bindings;
    }

    public function processDraftSlots(string $html, int $themeId, string $pageType): string
    {
        return $this->processSlots($html, $themeId, $pageType, ThemeLayout::STATUS_DRAFT);
    }

    /**
     * 处理已发布模式的插槽（前端显示用）
     */
    public function processPublishedSlots(string $html, int $themeId, string $pageType): string
    {
        return $this->processSlots($html, $themeId, $pageType, ThemeLayout::STATUS_PUBLISHED);
    }

    /**
     * 使用 compile-time 边界注释处理插槽（字符串引擎，支持嵌套与动态子槽）。
     */
    private function processSlotsWithBoundaries(
        string $html,
        array $slotWidgets,
        bool $allowNarrowFragment = false,
        string $pageType = ''
    ): string {
        $bodyParts = $this->splitHtmlBody($html);
        if ($bodyParts !== null) {
            $bodyParts['body'] = $this->processSlotFragmentWithBoundaries(
                $bodyParts['body'],
                $slotWidgets,
                $allowNarrowFragment,
                $pageType
            );

            return $bodyParts['before'] . $bodyParts['body'] . $bodyParts['after'];
        }

        return $this->processSlotFragmentWithBoundaries($html, $slotWidgets, $allowNarrowFragment, $pageType);
    }

    private function processSlotFragmentWithBoundaries(
        string $html,
        array $slotWidgets,
        bool $allowNarrowFragment = false,
        string $pageType = ''
    ): string {
        if ($allowNarrowFragment) {
            $narrowed = $this->narrowHtmlToSlotFragment($html, \array_keys($slotWidgets));
            if ($narrowed !== null) {
                $narrowed['fragment'] = $this->processSlotFragmentWithBoundaries(
                    $narrowed['fragment'],
                    $slotWidgets,
                    false,
                    $pageType
                );

                return $narrowed['before'] . $narrowed['fragment'] . $narrowed['after'];
            }
        }

        if ($this->shouldRequireSlotBoundaryMarkers($html, $slotWidgets)) {
            $this->assertSlotBoundaryMarkersPresent($html);
        }

        $parker = $this->boundaryParker();
        $scanner = $this->boundaryScanner();
        $html = RequestLifecycleTrace::measurePhase(
            'theme.slots.opaque_park',
            fn(): string => $parker->park($html),
            ['bytes' => \strlen($html)],
        );

        $existingSlotIds = [];
        $maxIterations = 50;
        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $pendingSlotIds = [];
            foreach ($slotWidgets as $slotId => $widgets) {
                if ($widgets !== [] && !isset($this->filledSlotIdsThisRun[$slotId])) {
                    $pendingSlotIds[$slotId] = true;
                }
            }
            if ($pendingSlotIds === []) {
                break;
            }
            $regions = RequestLifecycleTrace::measurePhase(
                'theme.slots.boundary_scan',
                static fn() => $scanner->enumerateRegions($html, null, $pendingSlotIds),
                ['iteration' => $iteration, 'targets' => \count($pendingSlotIds)],
            );
            if ($regions === []) {
                break;
            }

            // Orphan detection only inspects configured slots. Retain each
            // valid target across iterations, including children later removed
            // by a parent replacement; never infer validity from bare markers.
            foreach ($regions as $region) {
                $existingSlotIds[$region['id']] = true;
            }

            $filled = false;
            foreach ($this->selectBoundaryBatches($regions, $slotWidgets) as $batch) {
                $batchReplacements = [];
                $batchSlotIds = [];
                foreach ($batch as $region) {
                    $slotId = $region['id'];
                    if (isset($this->filledSlotIdsThisRun[$slotId])) {
                        continue;
                    }

                    $replacement = RequestLifecycleTrace::measurePhase(
                        'theme.slots.region_fill',
                        fn(): ?array => $this->buildSlotRegionReplacement($html, $region, $slotWidgets[$slotId]),
                        [
                            'slot_id' => $slotId,
                            'depth' => (int)($region['depth'] ?? 0),
                            'widgets' => \count($slotWidgets[$slotId]),
                        ],
                    );
                    if (!\is_array($replacement)) {
                        continue;
                    }

                    $batchReplacements[] = $replacement;
                    $batchSlotIds[] = $slotId;
                }

                if ($batchReplacements !== []) {
                    $html = \count($batchReplacements) === 1
                        ? $this->boundaryScanner()->replaceWrapperInner($html, $batchReplacements[0], $batchReplacements[0]['new_inner'])
                        : RequestLifecycleTrace::measurePhase(
                            'theme.slots.region_batch_replace',
                            fn(): string => $this->boundaryScanner()->replaceWrapperInners($html, $batchReplacements),
                            [
                                'regions' => \count($batchReplacements),
                                'bytes' => \strlen($html),
                            ],
                        );
                    foreach ($batchSlotIds as $slotId) {
                        $this->filledSlotIdsThisRun[$slotId] = true;
                    }
                    $filled = true;
                }

                if ($filled) {
                    break;
                }
            }

            if (!$filled) {
                break;
            }
        }

        // Template widget-wrappers (e.g. mini-cart-icon) are opaque-parked for the
        // boundary pass, which also hides nested layout-scoped slots such as
        // footer-extras. Restore first so fillRemaining can see those markers.
        $html = RequestLifecycleTrace::measurePhase(
            'theme.slots.opaque_restore',
            fn(): string => $parker->restore($html),
            ['bytes' => \strlen($html)],
        );
        $html = RequestLifecycleTrace::measurePhase(
            'theme.slots.fill_unmarked',
            fn(): string => $this->fillRemainingUnmarkedSlotWidgets($html, $slotWidgets),
            ['slots' => \count($slotWidgets)],
        );

        RequestLifecycleTrace::measurePhase(
            'theme.slots.orphan_scan',
            function () use ($slotWidgets, $existingSlotIds, $html, $pageType): void {
                // 布局源码已声明、但被 meta 门控（如 showRelatedProducts=false）未写入 HTML
                // 的插槽仍算「存在」：配置保留、不告孤儿；打开门控后即可填充。
                $this->detectOrphanWidgets(
                    $slotWidgets,
                    $this->expandSlotIdsWithLayoutDeclaredSlots(
                        $pageType,
                        $this->expandSlotIdsWithContainerChildSlots(
                            $slotWidgets,
                            $existingSlotIds + $this->extractSlotIdsFromHtml($html)
                        ),
                        $slotWidgets,
                    ),
                    $pageType
                );
            },
            ['slots' => \count($slotWidgets)],
        );

        // CoW / 二次 processSlots 可能保留已有 tip HTML 而不重跑 doRenderWidget；
        // 从 DOM 回填 unavailableWidgets，保证编辑器诊断面板仍能注入。
        $this->recoverUnavailableWidgetsFromHtml($html);

        return $html;
    }

    /**
     * Select fill batches from one boundary scan. Distinct sibling regions at the
     * same depth can be replaced from right to left so earlier byte offsets stay
     * valid; nested regions are deferred until the next scan because their parent
     * offsets change when an inner region is replaced. Duplicate slot IDs retain
     * the historical first-occurrence behavior and therefore use a single region.
     *
     * @param list<array<string, mixed>> $regions
     * @param array<string, list<array<string, mixed>>> $slotWidgets
     * @return list<list<array<string, mixed>>>
     */
    private function selectBoundaryBatches(array $regions, array $slotWidgets): array
    {
        $counts = [];
        foreach ($regions as $region) {
            $id = trim((string)($region['id'] ?? ''));
            if ($id !== '') {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        $hasDuplicate = false;
        foreach ($counts as $count) {
            if ($count > 1) {
                $hasDuplicate = true;
                break;
            }
        }

        $byDepth = [];
        foreach ($regions as $region) {
            $slotId = trim((string)($region['id'] ?? ''));
            if ($slotId === ''
                || isset($this->filledSlotIdsThisRun[$slotId])
                || !isset($slotWidgets[$slotId])
                || $slotWidgets[$slotId] === []
            ) {
                continue;
            }
            $depth = (int)($region['depth'] ?? 0);
            $byDepth[$depth][] = $region;
        }

        if ($byDepth === []) {
            return [];
        }

        krsort($byDepth, SORT_NUMERIC);
        $batches = [];
        foreach ($byDepth as $batch) {
            if ($hasDuplicate) {
                $batches[] = [$batch[0]];
                break;
            }
            usort(
                $batch,
                static fn(array $a, array $b): int => ((int)($b['region_start'] ?? 0))
                    <=> ((int)($a['region_start'] ?? 0)),
            );
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * Widget-embedded slots may ship as bare data-wslot nodes without compile-time
     * boundary comments, or as nested markers inside template widget-wrappers that
     * were opaque-parked during the boundary pass. After restore, fill any remaining
     * slots by wrapper bounds so layout-scoped widgets (e.g. mini-cart footer-extras)
     * still render on non-mini-cart storefront pages.
     *
     * @param array<string, list<array<string, mixed>>> $slotWidgets
     */
    private function fillRemainingUnmarkedSlotWidgets(string $html, array $slotWidgets): string
    {
        if ($slotWidgets === []) {
            return $html;
        }

        $scanner = $this->boundaryScanner();
        foreach ($slotWidgets as $slotId => $widgets) {
            $slotId = trim((string)$slotId);
            if ($slotId === '' || $widgets === [] || isset($this->filledSlotIdsThisRun[$slotId])) {
                continue;
            }

            $bounds = $scanner->findSlotWrapperBounds($html, $slotId);
            if ($bounds === null) {
                continue;
            }

            $region = [
                'id' => $slotId,
                'inner_start' => $bounds['inner_start'],
                'inner_end' => $bounds['inner_end'],
                'wrapper_open_start' => $bounds['open_start'],
                'wrapper_open_end' => $bounds['open_end'],
            ];

            $nextHtml = RequestLifecycleTrace::measurePhase(
                'theme.slots.region_fill',
                fn(): string => $this->fillSlotRegionString($html, $region, $widgets),
                [
                    'slot_id' => $slotId,
                    'depth' => (int)($region['depth'] ?? 0),
                    'widgets' => \count($widgets),
                    'source' => 'unmarked',
                ],
            );
            if ($nextHtml === $html) {
                continue;
            }

            $html = $nextHtml;
            $this->filledSlotIdsThisRun[$slotId] = true;
        }

        return $html;
    }

    /**
     * @param list<array<string, mixed>> $layoutWidgets
     */
    private function fillSlotRegionString(string $html, array $region, array $layoutWidgets): string
    {
        $replacement = $this->buildSlotRegionReplacement($html, $region, $layoutWidgets);
        if ($replacement === null) {
            return $html;
        }

        return $this->boundaryScanner()->replaceWrapperInner($html, $region, $replacement['new_inner']);
    }

    /**
     * Render a slot against the current source HTML and return only its source
     * offsets plus replacement body. Callers that have several disjoint sibling
     * regions can apply all returned bodies in one source pass.
     *
     * @param list<array<string, mixed>> $layoutWidgets
     * @return array{inner_start:int,inner_end:int,new_inner:string}|null
     */
    private function buildSlotRegionReplacement(string $html, array $region, array $layoutWidgets): ?array
    {
        $slotId = (string) ($region['id'] ?? '');
        if ($slotId === '') {
            return null;
        }

        $wrapperOpenTag = \substr(
            $html,
            (int) $region['wrapper_open_start'],
            (int) $region['wrapper_open_end'] - (int) $region['wrapper_open_start'],
        );
        $inner = \substr(
            $html,
            (int) $region['inner_start'],
            (int) $region['inner_end'] - (int) $region['inner_start'],
        );

        $isExclusive = \str_contains($wrapperOpenTag, 'data-wslot-exclusive="true"');
        $isAppend = \str_contains($wrapperOpenTag, 'data-wslot-append="true"');
        $isPrepend = \str_contains($wrapperOpenTag, 'data-wslot-prepend="true"');

        // Exclusive slots with layout placements: layout owns the slot. CoW would
        // otherwise keep meaningful template shells beside layout rows that lack
        // matching template_ref (common after entity bake), causing duplicate banners.
        if ($isExclusive && $layoutWidgets !== []) {
            $widgetsHtml = $this->traceCall(
                'slot_renderer::renderExclusiveSlot::' . \substr($slotId, 0, 80),
                fn() => $this->renderSlotWidgets($layoutWidgets),
                [
                    'slot_id' => $slotId,
                    'widgets' => \count($layoutWidgets),
                ]
            );
            if ($widgetsHtml === '') {
                return null;
            }

            return [
                'inner_start' => (int)$region['inner_start'],
                'inner_end' => (int)$region['inner_end'],
                'new_inner' => $widgetsHtml,
            ];
        }

        $merger = ObjectManager::getInstance(TemplateInlineWidgetMerger::class);
        $templateWidgets = $merger->extractTemplateWidgetsFromHtml($inner);
        if ($templateWidgets !== []) {
            $plan = $merger->plan($templateWidgets, $layoutWidgets);
            if ($this->isMultipleSlotWrapperTag($wrapperOpenTag)) {
                $hasPureLayoutAddition = false;
                foreach ($plan as $planItem) {
                    if (($planItem['kind'] ?? '') !== 'layout' || !\is_array($planItem['widget'] ?? null)) {
                        continue;
                    }
                    $cfg = $this->cowWidgetConfig($planItem['widget']);
                    if (\trim((string)($cfg[TemplateInlineWidgetMerger::CONFIG_TEMPLATE_REF] ?? '')) === '') {
                        $hasPureLayoutAddition = true;
                        break;
                    }
                }
                // Pure additions must follow interleaved plan order (sort_order vs
                // templates). Surgical splice alone always parked them after the first
                // template shell, contradicting editor "before" hints.
                //
                // Exception: homepage `content` (and similar carriers) nest layout-scoped
                // slots with section wrappers (homepage-section). Full rebuild only
                // concatenates template widget HTML and drops those wrappers → flush edges
                // in editor preview. Keep splice so nested data-wslot shells survive.
                if ($hasPureLayoutAddition && !$this->slotInnerContainsNestedSlots($inner)) {
                    $newInner = $this->traceCall(
                        'slot_renderer::renderCowMergedMultipleSlot::' . \substr($slotId, 0, 80),
                        fn() => RequestLifecycleTrace::measurePhase(
                            'theme.slots.widgets.cow',
                            fn() => $this->renderCowMergedSlotHtml($plan),
                            ['branch' => 'multiple-rebuild', 'widgets' => \count($layoutWidgets)],
                        ),
                        [
                            'slot_id' => $slotId,
                            'templates' => \count($templateWidgets),
                            'widgets' => \count($layoutWidgets),
                        ]
                    );
                    if ($newInner === '') {
                        return null;
                    }

                    return [
                        'inner_start' => (int)$region['inner_start'],
                        'inner_end' => (int)$region['inner_end'],
                        'new_inner' => $newInner,
                    ];
                }

                $newInner = RequestLifecycleTrace::measurePhase(
                    'theme.slots.widgets.cow',
                    fn() => $this->spliceCowMergedMultipleSlotInner($inner, $templateWidgets, $plan, $layoutWidgets),
                    ['branch' => 'multiple', 'widgets' => \count($layoutWidgets)],
                );
                if ($newInner === $inner) {
                    return null;
                }

                return [
                    'inner_start' => (int)$region['inner_start'],
                    'inner_end' => (int)$region['inner_end'],
                    'new_inner' => $newInner,
                ];
            }

            $widgetsHtml = $this->traceCall(
                'slot_renderer::renderCowMergedSlot::' . \substr($slotId, 0, 80),
                fn() => RequestLifecycleTrace::measurePhase(
                    'theme.slots.widgets.cow',
                    fn() => $this->renderCowMergedSlotHtml($plan),
                    ['branch' => 'single', 'widgets' => \count($layoutWidgets)],
                ),
                [
                    'slot_id' => $slotId,
                    'templates' => \count($templateWidgets),
                    'widgets' => \count($layoutWidgets),
                ]
            );
            if ($widgetsHtml === '') {
                return null;
            }
            $isExclusive = true;
            $isAppend = false;
            $isPrepend = false;
        } else {
            $widgetsHtml = $this->traceCall(
                'slot_renderer::renderSlotWidgets::' . \substr($slotId, 0, 80),
                fn() => $this->renderSlotWidgets($layoutWidgets),
                [
                    'slot_id' => $slotId,
                    'widgets' => \count($layoutWidgets),
                ]
            );
            if ($widgetsHtml === '') {
                return null;
            }
        }

        $isAreaContainerSlot = ($slotId === 'footer' || $slotId === 'header') && !$isExclusive;
        $innerWithoutPlaceholders = $this->stripPlaceholderContentFromInner($inner);

        if ($isPrepend) {
            $newInner = $widgetsHtml . $innerWithoutPlaceholders;
        } elseif ($isAppend || $isAreaContainerSlot) {
            $newInner = $innerWithoutPlaceholders . $widgetsHtml;
        } else {
            $newInner = $widgetsHtml;
        }

        return [
            'inner_start' => (int)$region['inner_start'],
            'inner_end' => (int)$region['inner_end'],
            'new_inner' => $newInner,
        ];
    }

    private function stripPlaceholderContentFromInner(string $inner): string
    {
        if ($inner === '' || !\str_contains($inner, 'slot-placeholder')) {
            return $inner;
        }

        return (string) \preg_replace(
            '/<[^>]*\bslot-placeholder\b[^>]*>.*?<\/[^>]+>/is',
            '',
            $inner,
        );
    }

    private function isMultipleSlotWrapperTag(string $wrapperOpenTag): bool
    {
        return (bool)\preg_match('/\bdata-wslot-multiple\s*=\s*(["\']?)true\1/i', $wrapperOpenTag);
    }

    /**
     * True when a multiple slot's inner HTML still carries nested layout-scoped
     * slot markers (e.g. homepage-brands with homepage-section wrappers).
     */
    private function slotInnerContainsNestedSlots(string $inner): bool
    {
        return $inner !== '' && (bool)\preg_match('/\bdata-wslot\s*=/', $inner);
    }

    private function spliceCowMergedMultipleSlotInner(
        string $inner,
        array $templateWidgets,
        array $plan,
        array $layoutWidgets,
    ): string {
        if ($inner === '' || $templateWidgets === []) {
            return $inner;
        }

        $working = $inner;
        $tombstonedRefs = $this->collectCowTombstoneRefs($layoutWidgets);

        foreach ($plan as $item) {
            $kind = (string)($item['kind'] ?? '');
            if ($kind !== 'layout' || !isset($item['widget']) || !\is_array($item['widget'])) {
                continue;
            }

            $widget = $item['widget'];
            $config = $this->cowWidgetConfig($widget);
            $ref = \trim((string)($config[TemplateInlineWidgetMerger::CONFIG_TEMPLATE_REF] ?? ''));
            $rendered = $this->renderWidget($widget) ?: '';
            if ($ref !== '') {
                foreach ($templateWidgets as $templateWidget) {
                    if ((string)($templateWidget['ref'] ?? '') !== $ref) {
                        continue;
                    }
                    $block = (string)($templateWidget['html'] ?? '');
                    if ($block !== '' && \str_contains($working, $block)) {
                        $working = \str_replace($block, $rendered, $working);
                    }
                }
                continue;
            }
            if ($rendered !== '') {
                $working = $this->insertCowLayoutAdditionIntoMultipleSlotInner(
                    $working,
                    $templateWidgets,
                    $widget,
                    $rendered,
                );
            }
        }

        foreach ($templateWidgets as $templateWidget) {
            $ref = (string)($templateWidget['ref'] ?? '');
            $block = (string)($templateWidget['html'] ?? '');
            if ($block === '' || !\str_contains($working, $block)) {
                continue;
            }
            if (!($tombstonedRefs[$ref] ?? false)) {
                continue;
            }
            $working = \str_replace($block, '', $working);
        }

        return $this->hydrateEmptyTemplateWidgetBlocks($working, $templateWidgets);
    }

    /**
     * @param list<array<string,mixed>> $layoutWidgets
     * @return array<string, true>
     */
    private function collectCowTombstoneRefs(array $layoutWidgets): array
    {
        $refs = [];
        foreach ($layoutWidgets as $widget) {
            if (!\is_array($widget)) {
                continue;
            }
            $config = $this->cowWidgetConfig($widget);
            $ref = \trim((string)($config[TemplateInlineWidgetMerger::CONFIG_TEMPLATE_REF] ?? ''));
            if ($ref !== '' && !empty($config[TemplateInlineWidgetMerger::CONFIG_TEMPLATE_DELETED])) {
                $refs[$ref] = true;
            }
        }

        return $refs;
    }

    /**
     * @param list<array{ref:string,html:string,element?:\DOMElement}> $templateWidgets
     */
    private function hydrateEmptyTemplateWidgetBlocks(string $inner, array $templateWidgets): string
    {
        $working = $inner;
        foreach ($templateWidgets as $templateWidget) {
            $block = (string)($templateWidget['html'] ?? '');
            if ($block === '' || !\str_contains($working, $block)) {
                continue;
            }
            if ($this->templateWidgetBlockHasMeaningfulBody($block)) {
                continue;
            }
            $rendered = $this->renderWidget($this->resolveWidgetFromTemplateBlock($block));
            if ($rendered === '') {
                continue;
            }
            $working = \str_replace($block, $rendered, $working);
        }

        return $working;
    }

    private function templateWidgetBlockHasMeaningfulBody(string $block): bool
    {
        // Parked opaque tokens are restorable content, not hollow shells.
        if (\str_contains($block, 'WELINE_SLOT_OPAQUE')) {
            return true;
        }
        $withoutComments = \trim(\preg_replace('/<!--.*?-->/s', '', $block) ?? $block);
        $meaningful = \trim(\strip_tags($withoutComments));

        return $meaningful !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveWidgetFromTemplateBlock(string $block): array
    {
        $pick = static function (string $attr) use ($block): string {
            if (\preg_match('/\b' . \preg_quote($attr, '/') . '\s*=\s*(["\'])([^"\']*)\1/i', $block, $match) !== 1) {
                return '';
            }

            return \trim((string)($match[2] ?? ''));
        };

        $configRaw = $pick('data-config');
        $config = [];
        if ($configRaw !== '') {
            $decoded = \json_decode($configRaw, true);
            $config = \is_array($decoded) ? $decoded : [];
        }

        return [
            'widget_module' => $pick('data-widget-module') ?: 'Weline_Theme',
            'widget_type' => $pick('data-widget-type') ?: 'header',
            'widget_code' => $pick('data-widget-code'),
            'config' => $config,
        ];
    }

    /**
     * @param list<array{ref:string,html:string,element?:\DOMElement}> $templateWidgets
     * @param array<string,mixed> $widget
     */
    private function insertCowLayoutAdditionIntoMultipleSlotInner(
        string $inner,
        array $templateWidgets,
        array $widget,
        string $renderedHtml,
    ): string {
        if ($renderedHtml === '') {
            return $inner;
        }

        $widgetCode = \trim((string)($widget['widget_code'] ?? ''));
        if ($widgetCode !== '') {
            $replaced = $this->replaceExistingSlotWidgetMarkupByCode($inner, $widgetCode, $renderedHtml);
            if ($replaced !== null) {
                return $replaced;
            }
            // wishlist-icon：无既有 markup 时插到第一个模板直嵌部件（账户）之前，保持货币后→收藏→账户顺序
            if ($widgetCode === 'wishlist-icon') {
                return $this->prependCowLayoutAdditionBeforeFirstTemplateWidget(
                    $inner,
                    $templateWidgets,
                    $renderedHtml,
                );
            }
        }

        // sort_order is the visual insert index among template shells + persisted
        // wrappers. Defaulting to "after first template" made "插入到 X 前" land after X
        // when X was a template-only shell (empty reference_layout_id).
        $sortOrder = \max(0, (int)($widget['sort_order'] ?? 0));

        return $this->insertHtmlAtMultipleSlotAnchorIndex(
            $inner,
            $templateWidgets,
            $renderedHtml,
            $sortOrder,
        );
    }

    /**
     * @param list<array{ref:string,html:string,element?:\DOMElement}> $templateWidgets
     * @return list<array{start:int,end:int}>
     */
    private function collectMultipleSlotWidgetAnchors(string $inner, array $templateWidgets): array
    {
        $anchors = [];
        foreach ($templateWidgets as $templateWidget) {
            $block = (string)($templateWidget['html'] ?? '');
            if ($block === '') {
                continue;
            }
            $pos = \strpos($inner, $block);
            if ($pos === false) {
                continue;
            }
            $anchors[] = [
                'start' => $pos,
                'end' => $pos + \strlen($block),
            ];
        }

        if (\preg_match_all(
            '/<div\b(?=[^>]*\bwidget-wrapper\b)(?=[^>]*\bdata-node-uid\s*=\s*(["\'])[a-f0-9]{32}\1)[^>]*>/i',
            $inner,
            $matches,
            \PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches[0] as $match) {
                $openStart = (int)($match[1] ?? -1);
                if ($openStart < 0) {
                    continue;
                }
                $covered = false;
                foreach ($anchors as $anchor) {
                    if ($openStart >= $anchor['start'] && $openStart < $anchor['end']) {
                        $covered = true;
                        break;
                    }
                }
                if ($covered) {
                    continue;
                }
                $end = $this->findMatchingDivEndOffset($inner, $openStart);
                if ($end === null) {
                    continue;
                }
                $anchors[] = [
                    'start' => $openStart,
                    'end' => $end,
                ];
            }
        }

        \usort(
            $anchors,
            static fn(array $left, array $right): int => ((int)$left['start']) <=> ((int)$right['start'])
        );

        return $anchors;
    }

    private function findMatchingDivEndOffset(string $html, int $openStart): ?int
    {
        if (\preg_match('/<div\b[^>]*>/i', $html, $openMatch, 0, $openStart) !== 1) {
            return null;
        }
        $pos = $openStart + \strlen($openMatch[0]);
        $depth = 1;
        $length = \strlen($html);
        while ($depth > 0 && $pos < $length) {
            if (\preg_match('/<\/?div\b[^>]*>/i', $html, $tagMatch, \PREG_OFFSET_CAPTURE, $pos) !== 1) {
                return null;
            }
            $tag = (string)$tagMatch[0][0];
            $tagPos = (int)$tagMatch[0][1];
            $isClose = isset($tag[1]) && $tag[1] === '/';
            $isSelfClosing = \str_ends_with(\rtrim($tag, '>'), '/');
            if ($isClose) {
                --$depth;
            } elseif (!$isSelfClosing) {
                ++$depth;
            }
            $pos = $tagPos + \strlen($tag);
            if ($depth === 0) {
                return $pos;
            }
        }

        return null;
    }

    /**
     * @param list<array{ref:string,html:string,element?:\DOMElement}> $templateWidgets
     */
    private function insertHtmlAtMultipleSlotAnchorIndex(
        string $inner,
        array $templateWidgets,
        string $html,
        int $index,
    ): string {
        if ($html === '') {
            return $inner;
        }

        $anchors = $this->collectMultipleSlotWidgetAnchors($inner, $templateWidgets);
        if ($anchors === []) {
            return $index <= 0 ? ($html . $inner) : ($inner . $html);
        }

        if ($index <= 0) {
            $insertAt = (int)$anchors[0]['start'];
        } elseif ($index >= \count($anchors)) {
            $insertAt = (int)$anchors[\count($anchors) - 1]['end'];
        } else {
            $insertAt = (int)$anchors[$index]['start'];
        }

        return \substr($inner, 0, $insertAt) . $html . \substr($inner, $insertAt);
    }

    /**
     * @param list<array{ref:string,html:string,element?:\DOMElement}> $templateWidgets
     */
    private function prependCowLayoutAdditionBeforeFirstTemplateWidget(
        string $inner,
        array $templateWidgets,
        string $additionsHtml,
    ): string {
        if ($additionsHtml === '') {
            return $inner;
        }

        foreach ($templateWidgets as $templateWidget) {
            $block = (string)($templateWidget['html'] ?? '');
            if ($block === '' || !\str_contains($inner, $block)) {
                continue;
            }
            $insertAt = \strpos($inner, $block);
            if ($insertAt === false) {
                continue;
            }

            return \substr($inner, 0, $insertAt) . $additionsHtml . \substr($inner, $insertAt);
        }

        return $additionsHtml . $inner;
    }

    private function replaceExistingSlotWidgetMarkupByCode(
        string $inner,
        string $widgetCode,
        string $renderedHtml,
    ): ?string {
        $widgetCode = \trim($widgetCode);
        if ($widgetCode === '') {
            return null;
        }

        $scanner = $this->boundaryScanner();
        $candidates = $widgetCode === 'wishlist-icon'
            ? [['section', 'header-wishlist'], ['div', 'widget-wrapper']]
            : [['div', 'widget-wrapper']];
        foreach ($candidates as [$tagName, $className]) {
            foreach ($scanner->scanTags($inner) as $tag) {
                if ($tag['closing'] || $tag['name'] !== $tagName
                    || \preg_match('/(?:^|\s)' . \preg_quote($className, '/') . '(?:\s|$)/', $scanner->attributeValue($tag['html'], 'class') ?? '') !== 1
                    || ($tagName === 'div' && $scanner->attributeValue($tag['html'], 'data-widget-code') !== $widgetCode)
                ) {
                    continue;
                }
                $bounds = $scanner->findElementBounds($inner, $tag['start']);
                if ($bounds !== null) {
                    // HTML is literal data: replacement backreferences must not
                    // reinterpret prices, JavaScript or backslashes.
                    return \substr($inner, 0, $bounds['open_start']) . $renderedHtml
                        . \substr($inner, $bounds['close_end']);
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{ref:string,html:string,element?:\DOMElement}> $templateWidgets
     */
    private function insertCowLayoutAdditionsIntoMultipleSlotInner(
        string $inner,
        array $templateWidgets,
        string $additionsHtml,
    ): string {
        if ($additionsHtml === '') {
            return $inner;
        }

        foreach ($templateWidgets as $templateWidget) {
            $block = (string)($templateWidget['html'] ?? '');
            if ($block === '' || !\str_contains($inner, $block)) {
                continue;
            }
            $insertAt = \strpos($inner, $block);
            if ($insertAt === false) {
                continue;
            }
            $insertAt += \strlen($block);

            return \substr($inner, 0, $insertAt) . $additionsHtml . \substr($inner, $insertAt);
        }

        return $inner . $additionsHtml;
    }

    /**
     * @param array<string,mixed> $widget
     * @return array<string,mixed>
     */
    private function cowWidgetConfig(array $widget): array
    {
        $config = $widget['config'] ?? ($widget['widget_config'] ?? []);
        if (\is_string($config)) {
            $decoded = \json_decode($config, true);
            $config = \is_array($decoded) ? $decoded : [];
        }

        return \is_array($config) ? $config : [];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slotWidgets
     */
    private function shouldRequireSlotBoundaryMarkers(string $html, array $slotWidgets): bool
    {
        if ($slotWidgets === []) {
            return false;
        }

        return \str_contains($html, 'data-wslot') || \str_contains($html, 'widget-slot-area');
    }

    private function assertSlotBoundaryMarkersPresent(string $html): void
    {
        if (SlotBoundaryMarkers::hasMarkers($html)) {
            return;
        }

        throw new SlotBoundaryRequiredException(
            'Layout HTML is missing @weline-slot boundary markers. Recompile templates with setup:upgrade.',
        );
    }

    private function boundaryScanner(): SlotBoundaryScanner
    {
        return $this->boundaryScanner ??= new SlotBoundaryScanner();
    }

    private function boundaryParker(): SlotHtmlOpaqueParker
    {
        return $this->boundaryParker ??= new SlotHtmlOpaqueParker();
    }

    private function splitHtmlBody(string $html): ?array
    {
        if (!preg_match('/^(.*?<body\b[^>]*>)(.*)(<\/body\s*>.*)$/is', $html, $matches)) {
            return null;
        }

        return [
            'before' => $matches[1],
            'body' => $matches[2],
            'after' => $matches[3],
        ];
    }

    /**
     * Park script/style before DOMDocument round-trips used by stampFinal health inspect.
     * Slot fill itself is boundaries-only; this helper is not a Dom slot engine.
     */
    private function parkDomOpaqueBlocks(string $html): string
    {
        if ($html === '' || (!str_contains($html, '<script') && !str_contains($html, '<style'))) {
            return $html;
        }

        $offset = 0;
        $length = strlen($html);
        $out = '';
        while ($offset < $length) {
            if (preg_match('/<(script|style)\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                $out .= substr($html, $offset);
                break;
            }
            $openStart = (int)$match[0][1];
            $openEnd = $openStart + strlen($match[0][0]);
            $closingTag = '</' . $match[1][0] . '>';
            $closeStart = stripos($html, $closingTag, $openEnd);
            if ($closeStart === false) {
                $out .= substr($html, $offset);
                break;
            }

            $end = $closeStart + strlen($closingTag);
            $token = '<!--WELINE_DOM_OPAQUE_' . count($this->domOpaqueTokens) . '_' . bin2hex(random_bytes(4)) . '-->';
            $this->domOpaqueTokens[$token] = substr($html, $openStart, $end - $openStart);
            $out .= substr($html, $offset, $openStart - $offset) . $token;
            $offset = $end;
        }

        return $out;
    }

    private function restoreDomOpaqueBlocks(string $html): string
    {
        if ($this->domOpaqueTokens === [] || $html === '') {
            return $html;
        }

        // Wrapper-inner tokens may embed script/style tokens; expand until stable.
        for ($i = 0; $i < 8; $i++) {
            $next = \strtr($html, $this->domOpaqueTokens);
            if ($next === $html) {
                break;
            }
            $html = $next;
        }

        return $html;
    }

    /**
     * Locate next .widget-wrapper opening tag (quote-aware) for repair / health stamp.
     */
    private function findNextWidgetWrapperOpen(string $html, int $offset): ?array
    {
        $length = \strlen($html);
        $pos = \max(0, $offset);
        while ($pos < $length) {
            if (\preg_match('/<div\b/i', $html, $match, \PREG_OFFSET_CAPTURE, $pos) !== 1) {
                return null;
            }
            $start = (int)$match[0][1];
            $end = $this->findHtmlTagClose($html, $start + 4);
            if ($end === null) {
                return null;
            }
            $tag = \substr($html, $start, $end - $start);
            if (\preg_match('/\bwidget-wrapper\b/i', $tag) === 1) {
                return ['start' => $start, 'end' => $end, 'tag' => $tag];
            }
            $pos = $end;
        }

        return null;
    }

    /**
     * Scan forward from inside an open tag to the closing ">" while respecting quotes.
     */
    private function findHtmlTagClose(string $html, int $from): ?int
    {
        $length = \strlen($html);
        $quote = null;
        for ($i = \max(0, $from); $i < $length; $i++) {
            $ch = $html[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }
            if ($ch === '>') {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * Find the </div> that matches a div opened at $openEnd (index of first inner byte).
     * Skips comments and opaque/raw-text elements (script/style/…) so literal
     * "</div>" inside them cannot close a widget-wrapper early.
     */
    private function findMatchingDivClose(string $html, int $openEnd): ?int
    {
        $length = \strlen($html);
        $depth = 1;
        $cursor = $openEnd;
        while ($cursor < $length && $depth > 0) {
            $lt = \strpos($html, '<', $cursor);
            if ($lt === false) {
                return null;
            }

            // Comments may contain literal </div> tokens; never treat them as structure.
            if (\substr($html, $lt, 4) === '<!--') {
                $commentEnd = \strpos($html, '-->', $lt + 4);
                $cursor = $commentEnd === false ? $length : $commentEnd + 3;
                continue;
            }

            $opaqueEnd = $this->skipOpaqueHtmlElement($html, $lt);
            if ($opaqueEnd !== null) {
                $cursor = $opaqueEnd;
                continue;
            }

            $nextOpen = \stripos($html, '<div', $lt);
            $nextClose = \stripos($html, '</div>', $lt);
            if ($nextClose === false) {
                return null;
            }
            if ($nextOpen !== false && $nextOpen < $nextClose && \preg_match('/<div\b/i', \substr($html, $nextOpen, 10)) === 1) {
                $depth++;
                $cursor = $nextOpen + 4;
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return $nextClose;
            }
            $cursor = $nextClose + 6;
        }

        return null;
    }

    /**
     * When $lt points at an opaque/raw-text open tag, return the byte offset after
     * its matching close (or self-close). Otherwise null.
     */
    private function skipOpaqueHtmlElement(string $html, int $lt): ?int
    {
        if (\preg_match(
            '/\G<(script|style|textarea|title|xmp|iframe|noembed|noframes|noscript)\b/i',
            $html,
            $match,
            0,
            $lt
        ) !== 1) {
            return null;
        }

        $name = \strtolower((string)$match[1]);
        $tagEnd = $this->findHtmlTagClose($html, $lt + 1);
        if ($tagEnd === null) {
            return \strlen($html);
        }

        $openTag = \substr($html, $lt, $tagEnd - $lt);
        if (\str_ends_with(\rtrim(\substr($openTag, 0, -1)), '/')) {
            return $tagEnd;
        }

        $close = \stripos($html, '</' . $name . '>', $tagEnd);
        if ($close === false) {
            return \strlen($html);
        }

        return $close + \strlen('</' . $name . '>');
    }

    /**
     * DEV/preview: re-render empty or shredded .widget-wrapper bodies from data-config.
     * Also drops product-card / product-info siblings promoted out of the wrapper by libxml.
     */
    private function repairUnhealthyWidgetWrappers(string $html): string
    {
        if ($html === '' || !\str_contains($html, 'widget-wrapper')) {
            return $html;
        }

        try {
            /** @var WidgetHtmlHealthInspector $inspector */
            $inspector = ObjectManager::getInstance(WidgetHtmlHealthInspector::class);
        } catch (\Throwable) {
            return $html;
        }

        $length = \strlen($html);
        $offset = 0;
        $out = '';
        $repaired = 0;

        while ($offset < $length) {
            $open = $this->findNextWidgetWrapperOpen($html, $offset);
            if ($open === null) {
                $out .= \substr($html, $offset);
                break;
            }

            $openStart = $open['start'];
            $openTag = $open['tag'];
            $openEnd = $open['end'];
            $out .= \substr($html, $offset, $openStart - $offset);

            $innerEnd = $this->findMatchingDivClose($html, $openEnd);
            if ($innerEnd === null) {
                $out .= $openTag;
                $offset = $openEnd;
                continue;
            }

            $inner = \substr($html, $openEnd, $innerEnd - $openEnd);
            $fullEnd = $innerEnd + 6;
            $meta = [
                'module' => $this->attrFromTag($openTag, 'data-widget-module'),
                'code' => $this->attrFromTag($openTag, 'data-widget-code'),
                'type' => $this->attrFromTag($openTag, 'data-widget-type'),
                'slot_id' => $this->attrFromTag($openTag, 'data-slot-id'),
                'layout_id' => $this->attrFromTag($openTag, 'data-layout-id')
                    ?: $this->attrFromTag($openTag, 'data-node-uid'),
            ];

            $needsRepair = false;
            $isBrokenShell = false;
            try {
                $issues = $inspector->inspect($inner, $meta);
                $issues = $inspector->suppressSatisfiedDualPathEmpty($issues, $meta, $html);
                foreach ($issues as $issue) {
                    $code = (string)($issue['code'] ?? '');
                    if ($code === 'empty_html' || $code === 'broken_widget_shell') {
                        $needsRepair = true;
                    }
                    if ($code === 'broken_widget_shell') {
                        $isBrokenShell = true;
                    }
                    // Truncated fiber captures leave unclosed_tag / tag_mismatch;
                    // re-render instead of stamping a toast-only health tip.
                    if ($code === 'unclosed_tag'
                        || $code === 'unclosed_raw_tag'
                        || $code === 'tag_mismatch'
                        || $code === 'malformed_close_tag'
                        || $code === 'unexpected_close'
                    ) {
                        $needsRepair = true;
                    }
                    if (\str_starts_with($code, 'php_')) {
                        $needsRepair = true;
                    }
                }
            } catch (\Throwable) {
                $needsRepair = false;
            }

            if ($needsRepair && $repaired < 12) {
                $freshInner = $this->renderWidgetInnerFromWrapperOpenTag($openTag);
                if ($freshInner !== '' && $freshInner !== $inner) {
                    $inner = $freshInner;
                    $repaired++;
                    // Drop open-tag health attrs; stampFinal will re-inspect.
                    $openTag = $this->stripHtmlAttributes($openTag, [
                        'data-w-widget-health',
                        'data-w-widget-health-issues',
                    ]);
                } else {
                    $needsRepair = false;
                }
            }

            $out .= $openTag . $inner . '</div>';
            $offset = $fullEnd;

            if ($isBrokenShell && $needsRepair) {
                $stripped = $this->stripPromotedWidgetDebris(\substr($html, $offset));
                $out .= $stripped['kept_prefix'];
                $offset += $stripped['consumed'];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $names
     */
    private function stripHtmlAttributes(string $openTag, array $names): string
    {
        foreach ($names as $name) {
            $pattern = '/\s' . \preg_quote($name, '/') . '=(["\'])(?:.*?)\1/i';
            $openTag = (string)\preg_replace($pattern, '', $openTag);
        }

        return $openTag;
    }

    /**
     * Re-render widget body from wrapper data-* attrs (no outer widget-wrapper).
     */
    private function renderWidgetInnerFromWrapperOpenTag(string $openTag): string
    {
        $module = $this->attrFromTag($openTag, 'data-widget-module');
        $code = $this->attrFromTag($openTag, 'data-widget-code');
        $type = $this->attrFromTag($openTag, 'data-widget-type');
        if ($code === '') {
            return '';
        }

        $configRaw = $this->attrFromTag($openTag, 'data-config');
        if ($configRaw === '') {
            $configRaw = $this->attrFromTag($openTag, 'data-widget-params');
        }
        $config = [];
        if ($configRaw !== '') {
            $decoded = \json_decode(\html_entity_decode($configRaw, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), true);
            if (\is_array($decoded)) {
                $config = $decoded;
            }
        }

        $widget = [
            'widget_module' => $module !== '' ? $module : 'Weline_Theme',
            'widget_code' => $code,
            'widget_type' => $type,
            'layout_id' => '',
            'slot_id' => $this->attrFromTag($openTag, 'data-slot-id'),
            'config' => $config,
        ];

        try {
            // Repair replaces wrapper HTML; re-allow card + purchase-actions CSS emission so
            // styles discarded with the first pass are not skipped by RequestContext flags.
            ProductCardAddToCartParams::resetPurchaseActionsAssetsEmission();
            ProductCardRenderer::resetProductCardCssEmission();
            $html = $this->doRenderWidget($widget);
        } catch (\Throwable) {
            return '';
        }
        if (!\is_string($html) || $html === '') {
            return '';
        }

        // doRenderWidget may wrap when health issues exist; unwrap one outer wrapper.
        $trimmed = \trim($html);
        $open = $this->findNextWidgetWrapperOpen($trimmed, 0);
        if ($open !== null && $open['start'] === 0) {
            $innerEnd = $this->findMatchingDivClose($trimmed, $open['end']);
            if ($innerEnd !== null) {
                return \substr($trimmed, $open['end'], $innerEnd - $open['end']);
            }
        }

        return $html;
    }

    /**
     * Remove product-card / product-info style siblings libxml promoted after a wrapper.
     *
     * @return array{kept_prefix:string,consumed:int}
     */
    private function stripPromotedWidgetDebris(string $tail): array
    {
        $consumed = 0;
        $length = \strlen($tail);
        while ($consumed < $length) {
            if (\preg_match('/^\s+/', \substr($tail, $consumed), $ws) === 1) {
                $consumed += \strlen($ws[0]);
            }
            if ($consumed >= $length) {
                break;
            }
            if (\preg_match(
                '/^<(div|article|header|section)\b[^>]*\b(product-info|product-card|products-wrapper|products-grid|products-container|widget-header|rank-badge)\b[^>]*>/i',
                \substr($tail, $consumed),
                $match
            ) !== 1) {
                break;
            }
            $tag = \strtolower((string)$match[1]);
            $openLen = \strlen($match[0]);
            $closePos = $this->findMatchingNamedClose($tail, $consumed + $openLen, $tag);
            if ($closePos === null) {
                break;
            }
            $consumed = $closePos + \strlen('</' . $tag . '>');
        }

        return [
            'kept_prefix' => '',
            'consumed' => $consumed,
        ];
    }

    private function findMatchingNamedClose(string $html, int $openEnd, string $tag): ?int
    {
        $tag = \strtolower($tag);
        $length = \strlen($html);
        $depth = 1;
        $cursor = $openEnd;
        $openNeedle = '<' . $tag;
        $closeNeedle = '</' . $tag . '>';
        while ($cursor < $length && $depth > 0) {
            $nextOpen = \stripos($html, $openNeedle, $cursor);
            $nextClose = \stripos($html, $closeNeedle, $cursor);
            if ($nextClose === false) {
                return null;
            }
            if ($nextOpen !== false && $nextOpen < $nextClose
                && \preg_match('/<' . \preg_quote($tag, '/') . '\b/i', \substr($html, $nextOpen, \strlen($tag) + 3)) === 1
            ) {
                $depth++;
                $cursor = $nextOpen + \strlen($tag) + 1;
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return $nextClose;
            }
            $cursor = $nextClose + \strlen($closeNeedle);
        }

        return null;
    }

    /**
     * HTML5 optional end tags: empty <li></li> (and peers) lose </tag> under saveHTML,
     * so the next sibling nests inside and header/account menus collapse.
     */
    private function narrowHtmlToSlotFragment(string $html, array $slotIds): ?array
    {
        $minStart = null;
        $maxEnd = null;

        foreach ($slotIds as $slotId) {
            $slotId = (string)$slotId;
            if ($slotId === '') {
                continue;
            }

            $bounds = $this->findSlotElementBounds($html, $slotId);
            if ($bounds === null) {
                return null;
            }

            $minStart = $minStart === null ? $bounds[0] : \min($minStart, $bounds[0]);
            $maxEnd = $maxEnd === null ? $bounds[1] : \max($maxEnd, $bounds[1]);
        }

        if ($minStart === null || $maxEnd === null || $maxEnd <= $minStart) {
            return null;
        }

        $htmlLength = \strlen($html);
        if (($maxEnd - $minStart) >= (int)($htmlLength * 0.9)) {
            return null;
        }

        // Cross-region header slots (notice bar + belt) produce a substring that starts
        // mid-shell; DOMDocument then "repairs" it and drops header-container/header-belt.
        [$minStart, $maxEnd] = $this->expandBoundsToEnclosingWelineHeader($html, $minStart, $maxEnd);

        // Include compile-time <!--@weline-slot:...--> wrappers so narrowed fragments
        // still satisfy assertSlotBoundaryMarkersPresent().
        [$minStart, $maxEnd] = $this->expandBoundsToSlotBoundaryMarkers($html, $minStart, $maxEnd, $slotIds);

        if ($maxEnd <= $minStart) {
            return null;
        }

        if (($maxEnd - $minStart) >= (int)($htmlLength * 0.9)) {
            return null;
        }

        // Header-area widgets + content-area widgets span across </header>. Narrowing that
        // range yields a mid-document fragment; LIBXML_HTML_NOIMPLIED then promotes
        // weline-main-content to a document sibling of the slot-root wrapper and the
        // serializer drops it (category pages lose #category-layout-main).
        if ($this->boundsCrossHtmlHeaderClose($html, $minStart, $maxEnd)) {
            return null;
        }

        return [
            'before' => \substr($html, 0, $minStart),
            'fragment' => \substr($html, $minStart, $maxEnd - $minStart),
            'after' => \substr($html, $maxEnd),
        ];
    }

    /**
     * Expand [minStart, maxEnd) to cover <!--@weline-slot:id--> … <!--@/weline-slot:id-->.
     *
     * @param list<string> $slotIds
     * @return array{0:int,1:int}
     */
    private function expandBoundsToSlotBoundaryMarkers(string $html, int $minStart, int $maxEnd, array $slotIds): array
    {
        foreach ($slotIds as $slotId) {
            $slotId = \trim((string)$slotId);
            if ($slotId === '' || !\preg_match('/^[\w.-]+$/', $slotId)) {
                continue;
            }
            $open = SlotBoundaryMarkers::open($slotId);
            $close = SlotBoundaryMarkers::close($slotId);
            $openPos = \strrpos(\substr($html, 0, $minStart + 1), $open);
            if ($openPos !== false) {
                $minStart = \min($minStart, $openPos);
            }
            $closePos = \strpos($html, $close, \max(0, $maxEnd - \strlen($close)));
            if ($closePos === false) {
                $closePos = \strpos($html, $close, $minStart);
            }
            if ($closePos !== false) {
                $maxEnd = \max($maxEnd, $closePos + \strlen($close));
            }
        }

        return [$minStart, $maxEnd];
    }

    /**
     * True when [minStart, maxEnd) includes a </header> close and continues past it —
     * i.e. the fragment would mix header shell with following page main content.
     */
    private function boundsCrossHtmlHeaderClose(string $html, int $minStart, int $maxEnd): bool
    {
        if ($maxEnd <= $minStart) {
            return false;
        }

        $offset = 0;
        while (\preg_match('/<\/header\s*>/i', $html, $matches, \PREG_OFFSET_CAPTURE, $offset)) {
            $closeStart = (int)$matches[0][1];
            $closeEnd = $closeStart + \strlen($matches[0][0]);
            if ($closeStart >= $maxEnd) {
                break;
            }
            if ($closeStart >= $minStart && $closeEnd <= $maxEnd && $maxEnd > $closeEnd) {
                return true;
            }
            $offset = $closeEnd;
        }

        return false;
    }

    /**
     * LIBXML_HTML_NOIMPLIED may close the slot-root wrapper early and leave following
     * nodes as document siblings. Move those siblings back under the slot-root so
     * serialization does not drop main content.
     */
    private function expandBoundsToEnclosingWelineHeader(string $html, int $minStart, int $maxEnd): array
    {
        $fragment = \substr($html, $minStart, $maxEnd - $minStart);
        if ($fragment === false || $fragment === '') {
            return [$minStart, $maxEnd];
        }

        $crossesHeaderShell = \str_contains($fragment, 'header-container')
            || \str_contains($fragment, 'header-belt')
            || \str_contains($fragment, 'header-site-notice');
        if (!$crossesHeaderShell) {
            return [$minStart, $maxEnd];
        }

        $headerBounds = $this->findEnclosingElementBoundsByClass($html, $minStart, 'header', 'weline-header');
        if ($headerBounds === null) {
            return [$minStart, $maxEnd];
        }

        return [
            \min($minStart, $headerBounds[0]),
            \max($maxEnd, $headerBounds[1]),
        ];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function findEnclosingElementBoundsByClass(
        string $html,
        int $position,
        string $tagName,
        string $classNeedle
    ): ?array {
        $tagPattern = \preg_quote($tagName, '/');
        $classPattern = \preg_quote($classNeedle, '/');
        $pattern = '/<' . $tagPattern . '\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\b'
            . $classPattern . '\b[^"\']*\1[^>]*>/i';

        $candidateStart = null;
        $offset = 0;
        while (\preg_match($pattern, $html, $matches, \PREG_OFFSET_CAPTURE, $offset)) {
            $start = (int)$matches[0][1];
            if ($start > $position) {
                break;
            }
            $candidateStart = $start;
            $offset = $start + \strlen($matches[0][0]);
        }

        if ($candidateStart === null) {
            return null;
        }

        if (!\preg_match($pattern, $html, $openMatches, 0, $candidateStart)) {
            return null;
        }

        $openEnd = $candidateStart + \strlen($openMatches[0]);
        $end = $this->findElementEndByTag($html, $tagName, $openEnd);
        if ($end === null || $end <= $position) {
            return null;
        }

        return [$candidateStart, $end];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function findSlotElementBounds(string $html, string $slotId): ?array
    {
        $quotedSlotId = \preg_quote($slotId, '/');
        $pattern = '/<([a-z][a-z0-9:-]*)(?=[^>]*\b(?:data-wslot|data-slot-id)\s*=\s*(["\'])' . $quotedSlotId . '\2)[^>]*>/i';
        if (!\preg_match($pattern, $html, $matches, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $openTag = $matches[0][0];
        $start = (int)$matches[0][1];
        $tagName = (string)$matches[1][0];
        $openEnd = $start + \strlen($openTag);
        $end = $this->findElementEndByTag($html, $tagName, $openEnd);

        return $end === null ? null : [$start, $end];
    }

    private function findElementEndByTag(string $html, string $tagName, int $offset): ?int
    {
        $tagName = \preg_quote($tagName, '/');
        $pattern = '/<\/?' . $tagName . '\b[^>]*>/i';
        $depth = 1;

        while (\preg_match($pattern, $html, $matches, \PREG_OFFSET_CAPTURE, $offset)) {
            $tag = $matches[0][0];
            $position = (int)$matches[0][1];
            $offset = $position + \strlen($tag);

            if (\str_starts_with($tag, '</')) {
                $depth--;
                if ($depth === 0) {
                    return $offset;
                }
                continue;
            }

            if (!\str_ends_with(\rtrim($tag), '/>')) {
                $depth++;
            }
        }

        return null;
    }

    /**
     * 检测孤儿部件（配置了但找不到对应slot的部件）
     *
     * 这些部件不会被删除，只是无法在当前布局中显示。
     * page_layouts 已声明且不含当前 pageType 的部件（如 mini-cart 专有槽）跳过告警。
     */
    private function detectOrphanWidgets(array $slotWidgets, array $existingSlotIds, string $pageType = ''): void
    {
        $this->orphanWidgets = [];
        
        foreach ($slotWidgets as $slotId => $widgets) {
            // 如果这个 slot ID 在模板中不存在，标记其所有部件为孤儿
            if (!isset($existingSlotIds[$slotId])) {
                foreach ($widgets as $widget) {
                    if (!\is_array($widget) || !$this->widgetBelongsToCurrentPageType($widget, $pageType)) {
                        continue;
                    }
                    $this->orphanWidgets[] = [
                        'slot_id' => $slotId,
                        'layout_id' => (int)($widget['layout_id'] ?? 0),
                        'widget_code' => $widget['widget_code'] ?? '',
                        'widget_module' => $widget['widget_module'] ?? '',
                        'widget_name' => $widget['meta']['name'] ?? $widget['widget_code'] ?? '未知部件',
                        'message' => sprintf(
                            '部件 "%s" 无法在当前布局生效，因为找不到插槽 "%s"',
                            $widget['meta']['name'] ?? $widget['widget_code'] ?? '未知部件',
                            $slotId
                        ),
                    ];
                }
            }
        }
    }

    /**
     * 部件是否属于当前 pageType 作用域（与 ThemePlaceableRegistry::matchesPageType 对齐）。
     * 未知部件或空 pageType 保守视为属于当前页，保留原孤儿告警。
     *
     * @param array<string, mixed> $widget
     */
    private function widgetBelongsToCurrentPageType(array $widget, string $pageType): bool
    {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            return true;
        }

        $module = \trim((string)($widget['widget_module'] ?? ''));
        $type = \trim((string)($widget['widget_type'] ?? ''));
        $code = \trim((string)($widget['widget_code'] ?? ''));
        if ($module === '' || $code === '') {
            return true;
        }
        if ($type === '') {
            $type = 'content';
        }

        try {
            $definition = $this->placeableRegistry->find(
                $module,
                $type,
                $code,
                $this->renderTheme,
                $this->renderArea === 'backend' ? 'backend' : 'frontend',
            );
        } catch (\Throwable) {
            return true;
        }

        if ($definition === null) {
            return true;
        }

        $layouts = $definition->pageLayouts;
        if (
            $layouts === []
            || \in_array('*', $layouts, true)
            || \in_array('default', $layouts, true)
            || \in_array($pageType, $layouts, true)
        ) {
            return true;
        }

        $layout = \strtolower($pageType);
        $layoutCode = 'layout-' . $layout;
        $layoutPrefix = $layoutCode . '-';
        foreach ($definition->supports as $support) {
            $supportCode = \strtolower(\trim((string)$support));
            if ($supportCode === $layoutCode || \str_starts_with($supportCode, $layoutPrefix)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * 获取孤儿部件列表
     * 
     * 返回上次渲染时找不到对应slot的部件信息
     * 这些部件的配置仍然保留在数据库中，不会被自动删除
     * 
     * @return array 孤儿部件列表
     */
    public function getOrphanWidgets(): array
    {
        return $this->orphanWidgets;
    }
    
    /**
     * 检查是否有孤儿部件
     */
    public function hasOrphanWidgets(): bool
    {
        return !empty($this->orphanWidgets);
    }

    /**
     * @return list<array{
     *   slot_id:string,
     *   layout_id:int,
     *   node_uid:string,
     *   widget_code:string,
     *   widget_module:string,
     *   widget_name:string,
     *   reason:string,
     *   message:string
     * }>
     */
    public function getUnavailableWidgets(): array
    {
        return $this->unavailableWidgets;
    }

    public function hasUnavailableWidgets(): bool
    {
        return $this->unavailableWidgets !== [];
    }

    /**
     * 编辑器诊断：从已渲染 HTML 回填失效部件列表（CoW 不重渲时用）。
     */
    public function syncUnavailableWidgetsFromHtml(string $html): void
    {
        $this->recoverUnavailableWidgetsFromHtml($html);
    }

    /**
     * 处理单个插槽元素
     * 同一 slot_id 在整棵 DOM 中只填充第一处出现，避免容器部件（如 content-container）
     * 放入 hero 后，其内部输出的同名 widget-hero 被再次填充导致布局泄露或重复。
     */
    private function removePlaceholderContent(\DOMElement $slot): void
    {
        // 查找并移除占位符元素
        $xpath = new \DOMXPath($slot->ownerDocument);
        $placeholders = $xpath->query(".//*[contains(@class, 'slot-placeholder')]", $slot);
        foreach ($placeholders as $placeholder) {
            $placeholder->parentNode->removeChild($placeholder);
        }
    }

    /**
     * 渲染插槽中的所有部件
     */
    private function renderSlotWidgets(array $widgets): string
    {
        return RequestLifecycleTrace::measurePhase(
            'theme.slots.widgets.regular',
            function () use ($widgets): string {
                $html = '';

                foreach ($widgets as $widget) {
                    $widgetHtml = $this->renderWidget($widget);
                    if ($widgetHtml) {
                        $html .= $widgetHtml;
                    }
                }

                return $html;
            },
            ['widgets' => \count($widgets)],
        );
    }

    /**
     * @param list<array{kind:string,html?:string,widget?:array<string,mixed>}> $plan
     */
    private function renderCowMergedSlotHtml(array $plan): string
    {
        $html = '';
        foreach ($plan as $item) {
            $kind = (string)($item['kind'] ?? '');
            if ($kind === 'template') {
                $html .= (string)($item['html'] ?? '');
                continue;
            }
            if ($kind === 'layout' && isset($item['widget']) && is_array($item['widget'])) {
                $widgetHtml = $this->renderWidget($item['widget']);
                if ($widgetHtml) {
                    $html .= $widgetHtml;
                }
            }
        }

        return $html;
    }

    /**
     * 渲染单个部件
     */
    private function renderWidget(array $widget): string
    {
        $widgetModule = (string)($widget['widget_module'] ?? '');
        $widgetCode = (string)($widget['widget_code'] ?? '');
        $widgetType = (string)($widget['widget_type'] ?? '');

        return $this->traceCall(
            'slot_renderer::renderWidget::' . substr($widgetModule . '::' . $widgetCode, 0, 120),
            function () use ($widget): string {
                return $this->doRenderWidget($widget);
            },
            [
                'module' => $widgetModule,
                'code' => $widgetCode,
                'type' => $widgetType,
                'slot_id' => (string)($widget['slot_id'] ?? ''),
            ]
        );
    }

    private function doRenderWidget(array $widget): string
    {
        // Each widget render owns its own w:slot declaration scope. Container
        // widgets (e.g. product-info → product-purchase-actions) may be
        // re-rendered by CoW/HTML health repair; a request-wide registry would
        // otherwise throw duplicate id on the second pass (often as unknown:0
        // because dynamic attrs go through renderRuntimeTag without a file).
        Slot::clearRegisteredSlots();

        $widgetModule = $widget['widget_module'] ?? '';
        $widgetCode = $widget['widget_code'] ?? '';
        $widgetType = $widget['widget_type'] ?? '';
        $layoutId = $widget['layout_id'] ?? '';
        $config = $widget['config'] ?? [];
        $config = \is_array($config) ? $config : [];
        $renderArea = $this->renderArea === 'backend' ? 'backend' : 'frontend';

        // Uninstalled modules must not abort slot/layout seeding: DEV tip in place, PROD empty.
        $widgetModule = \is_string($widgetModule) ? $widgetModule : '';
        $widgetCode = \is_string($widgetCode) ? $widgetCode : '';
        if ($widgetModule !== '' && !$this->isModuleRegistered($widgetModule)) {
            $codePart = $widgetCode !== '' ? $widgetCode : 'widget';
            $templateRef = str_contains($codePart, '::')
                ? $codePart
                : ($widgetModule . '::templates/frontend/widgets/' . $codePart . '.phtml');

            return $this->renderUnavailableWidgetTip(
                $widget,
                'missing_module',
                (string)__(
                    '异常：你指定的模板文件所在的模块不存在！模块：%{1}，所使用的模板：%{2}',
                    [$widgetModule, $templateRef]
                ),
            );
        }

        $definition = $this->placeableRegistry->find($widgetModule, $widgetType, $widgetCode, $this->renderTheme, $renderArea);
        $config = $this->mergeTranslatedWidgetConfig($widget, $config, $definition);
        $config = $this->hydrateTypedLayoutValues($config, $renderArea, $widget);
        $widgetOutputCacheKey = $this->buildWidgetOutputCacheKey($widget, $config);
        if ($widgetOutputCacheKey !== null) {
            $cachedWidget = self::$widgetOutputCache[$widgetOutputCacheKey] ?? null;
            if (\is_array($cachedWidget)
                && isset($cachedWidget['expires_at'], $cachedWidget['html'])
                && (float)$cachedWidget['expires_at'] >= \microtime(true)
                && \is_string($cachedWidget['html'])) {
                unset(self::$widgetOutputCache[$widgetOutputCacheKey]);
                self::$widgetOutputCache[$widgetOutputCacheKey] = $cachedWidget;
                return $cachedWidget['html'];
            }
            unset(self::$widgetOutputCache[$widgetOutputCacheKey]);
            $runtimeCachedWidget = $this->runtimeCacheGet($widgetOutputCacheKey);
            if (\is_string($runtimeCachedWidget)) {
                $this->rememberProcessWidgetOutput($widgetOutputCacheKey, [
                    'expires_at' => \microtime(true) + $this->widgetOutputCacheTtl(),
                    'html' => $runtimeCachedWidget,
                ]);
                return $runtimeCachedWidget;
            }
        }

        // 检查缓存
        if ($definition) {
            try {
                $renderConfig = $this->appendWidgetRenderContext($config, $widget);
                $renderConfig['_widget_instance_key'] = $this->widgetInstanceKey($widget, $renderConfig);
                $widgetTimingMeta = [
                    'module' => (string)$widgetModule,
                    'code' => (string)$widgetCode,
                    'type' => (string)$widgetType,
                ];
                $html = RequestLifecycleTrace::measurePhase(
                    'theme.slots.widget.component_render',
                    fn(): string => (string)$this->componentRenderer->render($definition, $renderConfig, $this->renderTheme, [
                        'area' => $renderArea,
                        // Editor iframe may keep PDP shells without product identity.
                        // Do not map editor/theme-preview to widget-canvas preview_mode (is-preview).
                        'preview_mode' => !empty($renderConfig['preview_mode']),
                        'editor_mode' => $renderConfig['editor_mode'] ?? false,
                    ]),
                    $widgetTimingMeta,
                );
                $html = RequestLifecycleTrace::measurePhase(
                    'theme.slots.widget.wrapper',
                    fn(): string => $this->maybeWrapWidgetHtml(
                        $html,
                        $widget,
                        $renderConfig,
                        (string)($definition->name ?: $widgetCode)
                    ),
                    $widgetTimingMeta,
                );

                return $this->rememberWidgetOutput($widgetOutputCacheKey, $html);
            } catch (\Throwable $throwable) {
                return $this->renderUnavailableWidgetTip(
                    $widget,
                    $this->classifyUnavailableReason((string)$throwable->getMessage()),
                    (string)$throwable->getMessage(),
                );
            }
        }

        $cacheKey = $renderArea . '::' . $widgetModule . '::' . $widgetCode;
        if (!isset($this->widgetCache[$cacheKey])) {
            $this->widgetCache[$cacheKey] = $this->getWidgetMeta($widgetModule, $widgetCode, $renderArea);
        }

        $widgetMeta = $this->widgetCache[$cacheKey];
        if (!$widgetMeta) {
            return $this->renderUnavailableWidgetTip(
                $widget,
                'missing_definition',
                (string)__('部件已不可用（定义/模板缺失）：%{1}', [(string)$widgetCode]),
            );
        }

        // 合并默认配置
        $defaultConfig = [];
        foreach ($widgetMeta['params'] ?? [] as $key => $param) {
            $defaultConfig[$key] = $param['default'] ?? '';
        }
        $finalConfig = array_merge($defaultConfig, is_array($config) ? $config : []);
        $finalConfig = $this->appendWidgetRenderContext($finalConfig, $widget);
        $finalConfig['_widget_instance_key'] = $this->widgetInstanceKey($widget, $finalConfig);

        $templateContent = (string)($widgetMeta['template_content'] ?? '');
        if (trim($templateContent) !== '') {
            try {
                $html = $this->runtimeTemplateRenderer->renderContent($templateContent, $finalConfig);
                $html = is_string($html) ? $html : '';
                $html = $this->maybeWrapWidgetHtml(
                    $html,
                    $widget,
                    $finalConfig,
                    (string)($widgetMeta['name'] ?? $widgetCode)
                );

                return $this->rememberWidgetOutput($widgetOutputCacheKey, $html);
            } catch (\Throwable $throwable) {
                return $this->renderUnavailableWidgetTip(
                    $widget,
                    $this->classifyUnavailableReason((string)$throwable->getMessage()),
                    (string)$throwable->getMessage(),
                );
            }
        }

        $templatePath = $widgetMeta['template'] ?? '';
        if (!$templatePath) {
            return $this->renderUnavailableWidgetTip(
                $widget,
                'missing_template',
                (string)__('部件已不可用（定义/模板缺失）：%{1}', [(string)$widgetCode]),
            );
        }

        try {
            // 渲染部件模板 - 使用 fetch() 方法，它接受2个参数：fileName 和 data
            $html = $this->template->fetch($templatePath, $finalConfig);
            $html = is_string($html) ? $html : '';
            // 为编辑器模式包装部件，添加识别属性；DEV/预览下附加 HTML 健康检测结果
            $html = $this->maybeWrapWidgetHtml(
                $html,
                $widget,
                $finalConfig,
                (string)($widgetMeta['name'] ?? $widgetCode)
            );

            return $this->rememberWidgetOutput($widgetOutputCacheKey, $html);
        } catch (\Throwable $e) {
            // 渲染失败，返回错误提示（仅开发模式）；不得中断其余槽位/布局播种
            return $this->renderUnavailableWidgetTip(
                $widget,
                $this->classifyUnavailableReason((string)$e->getMessage()),
                (string)__('部件渲染失败: %{1} - %{2}', [(string)$widgetCode, (string)$e->getMessage()]),
            );
        }
    }

    private function isModuleRegistered(string $moduleName): bool
    {
        $moduleName = \trim($moduleName);
        if ($moduleName === '') {
            return false;
        }

        return isset(Env::getInstance()->getModuleList()[$moduleName]);
    }

    private function classifyUnavailableReason(string $message): string
    {
        $normalized = \strtolower($message);
        if (
            \str_contains($normalized, '模板文件不存在')
            || \str_contains($normalized, 'template file')
            || (\str_contains($normalized, 'template') && \str_contains($normalized, 'not exist'))
            || \str_contains($normalized, 'does not exist')
        ) {
            return 'missing_template';
        }
        if (\str_contains($normalized, '模块不存在') || \str_contains($normalized, 'module')) {
            return 'missing_module';
        }

        return 'render_error';
    }

    /**
     * @param array<string, mixed> $widget
     */
    private function recordUnavailableWidget(array $widget, string $reason, string $message): void
    {
        $nodeUid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
        if ($nodeUid !== '' && \preg_match('/^[a-f0-9]{32}$/D', $nodeUid) !== 1) {
            $nodeUid = '';
        }
        $widgetCode = (string)($widget['widget_code'] ?? '');
        $widgetName = (string)($widget['meta']['name'] ?? ($widgetCode !== '' ? $widgetCode : '未知部件'));
        $slotId = (string)($widget['slot_id'] ?? '');

        foreach ($this->unavailableWidgets as $existing) {
            if (
                ($nodeUid !== '' && ($existing['node_uid'] ?? '') === $nodeUid)
                || (
                    $nodeUid === ''
                    && ($existing['slot_id'] ?? '') === $slotId
                    && ($existing['widget_code'] ?? '') === $widgetCode
                    && (int)($existing['layout_id'] ?? 0) === (int)($widget['layout_id'] ?? 0)
                )
            ) {
                return;
            }
        }

        $this->unavailableWidgets[] = [
            'slot_id' => $slotId,
            'layout_id' => (int)($widget['layout_id'] ?? 0),
            'node_uid' => $nodeUid,
            'widget_code' => $widgetCode,
            'widget_module' => (string)($widget['widget_module'] ?? ''),
            'widget_name' => $widgetName,
            'reason' => $reason,
            'message' => $message !== ''
                ? $message
                : (string)__('部件 "%{1}" 已不可用（定义/模板缺失）', [$widgetName]),
        ];
    }

    /**
     * 编辑器预览：可操作占位（带 wrapper + node_uid）；DEV 非预览：简化 tip；PROD：空串。
     *
     * @param array<string, mixed> $widget
     */
    private function renderUnavailableWidgetTip(array $widget, string $reason, string $message): string
    {
        $this->recordUnavailableWidget($widget, $reason, $message);

        $isEditorPreview = $this->isEditorPreviewRequest();
        $isDev = \defined('DEV') && DEV;
        if (!$isEditorPreview && !$isDev) {
            return '';
        }

        $widgetCode = \htmlspecialchars((string)($widget['widget_code'] ?? ''), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = \htmlspecialchars(
            $message !== '' ? $message : (string)__('部件已不可用（定义/模板缺失）'),
            \ENT_QUOTES | \ENT_SUBSTITUTE,
            'UTF-8'
        );
        $title = \htmlspecialchars((string)__('部件已不可用（定义/模板缺失）'), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $removeLabel = \htmlspecialchars((string)__('从当前版本移除'), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        if ($isEditorPreview) {
            $nodeUid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
            if ($nodeUid !== '' && \preg_match('/^[a-f0-9]{32}$/D', $nodeUid) !== 1) {
                $nodeUid = '';
            }
            $nodeUidAttr = \htmlspecialchars($nodeUid, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $reasonAttr = \htmlspecialchars($reason, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $inner = <<<HTML
<div class="widget-unavailable-tip" data-editor-interactive data-unavailable-reason="{$reasonAttr}" style="
    padding:12px 14px;
    border:1px solid #f0ad4e;
    border-radius:6px;
    background:#fff8e6;
    color:#856404;
    font-size:13px;
    line-height:1.45;
    pointer-events:auto;
    position:relative;
    z-index:5;
">
    <div style="font-weight:600;margin-bottom:6px;">{$title}</div>
    <div style="margin-bottom:8px;word-break:break-word;"><code>{$widgetCode}</code> — {$safeMessage}</div>
    <button type="button" data-action="remove-unavailable-widget" data-editor-interactive data-node-uid="{$nodeUidAttr}" style="
        background:#dc3545;
        color:#fff;
        border:none;
        border-radius:4px;
        padding:6px 12px;
        cursor:pointer;
        font-size:12px;
        pointer-events:auto;
        position:relative;
        z-index:6;
    ">{$removeLabel}</button>
</div>
HTML;
            $config = \is_array($widget['config'] ?? null) ? $widget['config'] : [];
            $config = $this->appendWidgetRenderContext($config, $widget);
            $widgetName = (string)($widget['meta']['name'] ?? $widget['widget_code'] ?? 'unavailable');
            $wrapped = $this->maybeWrapWidgetHtml($inner, $widget, $config, $widgetName);
            if (!\str_contains($wrapped, 'class="widget-wrapper"') && !\str_contains($wrapped, "class='widget-wrapper'")) {
                $attrs = $this->buildWidgetWrapperAttrs($widget, $config, null);
                $wrapped = \sprintf(
                    '<div class="widget-wrapper" %s data-widget-name="%s" data-unavailable="1" data-editor-interactive>%s</div>',
                    $attrs,
                    \htmlspecialchars($widgetName, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                    $inner
                );
            } else {
                $wrapped = $this->markUnavailableWidgetWrapper($wrapped);
            }

            return $wrapped;
        }

        return \sprintf(
            '<div class="widget-render-error" style="color:red;padding:10px;border:1px solid red;word-wrap: break-word;">%s</div>',
            $safeMessage
        );
    }

    /**
     * CoW 保留的失效 tip：从 HTML 回填 unavailableWidgets。
     */
    private function recoverUnavailableWidgetsFromHtml(string $html): void
    {
        if ($html === '' || !\str_contains($html, 'widget-unavailable-tip')) {
            return;
        }

        if (\preg_match_all(
            '/<div\b([^>]*\bwidget-wrapper\b[^>]*)>[\s\S]{0,4000}?widget-unavailable-tip/i',
            $html,
            $matches
        )) {
            foreach ($matches[1] as $attrChunk) {
                $openTag = '<div ' . \trim((string)$attrChunk) . '>';
                $nodeUid = \strtolower(\trim($this->attrFromTag($openTag, 'data-node-uid')));
                if ($nodeUid !== '' && \preg_match('/^[a-f0-9]{32}$/D', $nodeUid) !== 1) {
                    $nodeUid = '';
                }
                $this->recordUnavailableWidget([
                    'node_uid' => $nodeUid,
                    'layout_id' => (int)$this->attrFromTag($openTag, 'data-layout-id'),
                    'slot_id' => $this->attrFromTag($openTag, 'data-slot-id'),
                    'widget_code' => $this->attrFromTag($openTag, 'data-widget-code'),
                    'widget_module' => $this->attrFromTag($openTag, 'data-widget-module'),
                    'meta' => ['name' => $this->attrFromTag($openTag, 'data-widget-name')],
                ], 'missing_template', (string)__('部件已不可用（定义/模板缺失）'));
            }
        }

        if ($this->unavailableWidgets !== []) {
            return;
        }

        if (\preg_match_all(
            '/data-action="remove-unavailable-widget"[^>]*data-node-uid="([a-f0-9]{32})"/i',
            $html,
            $tips
        )) {
            foreach ($tips[1] as $uid) {
                $this->recordUnavailableWidget([
                    'node_uid' => \strtolower((string)$uid),
                    'widget_code' => '',
                    'slot_id' => '',
                    'meta' => ['name' => 'unavailable'],
                ], 'missing_template', (string)__('部件已不可用（定义/模板缺失）'));
            }
        }
    }

    private function markUnavailableWidgetWrapper(string $wrappedHtml): string
    {
        if ($wrappedHtml === '' || !\str_starts_with(\ltrim($wrappedHtml), '<')) {
            return $wrappedHtml;
        }
        $gt = \strpos($wrappedHtml, '>');
        if ($gt === false) {
            return $wrappedHtml;
        }
        $openTag = \substr($wrappedHtml, 0, $gt + 1);
        $rest = \substr($wrappedHtml, $gt + 1);
        $openTag = $this->upsertHtmlAttribute($openTag, 'data-unavailable', '1');
        $openTag = $this->upsertHtmlAttribute($openTag, 'data-editor-interactive', '1');

        return $openTag . $rest;
    }

    /**
     * @deprecated Use renderUnavailableWidgetTip for missing/broken widgets.
     */
    private function renderMissingModuleWidgetTip(string $moduleName, string $templateRef): string
    {
        $message = (string)__(
            '异常：你指定的模板文件所在的模块不存在！模块：%{1}，所使用的模板：%{2}',
            [$moduleName, $templateRef]
        );

        return $this->renderWidgetThrowableTip($message);
    }

    private function renderWidgetThrowableTip(string $message): string
    {
        if (!(defined('DEV') && DEV)) {
            return '';
        }

        return sprintf(
            '<div class="widget-render-error" style="color:red;padding:10px;border:1px solid red;word-wrap: break-word;">%s</div>',
            htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    /**
     * DEV / 预览下注入轻量 Toast 桥（禁 alert）到 <head>。
     * 坏 HTML 可能吃掉 body 尾脚本；页头注入才能保证仍能读到 data-w-widget-health*。
     * 主题预览完整引擎也会上报；两侧共用 data-w-widget-health-reported 防重复。
     */
    private function appendWidgetHealthToastBridge(string $html): string
    {
        if ($html === '' || !$this->shouldInspectWidgetHtml()) {
            return $html;
        }
        if (str_contains($html, 'data-w-widget-health-bridge')) {
            return $html;
        }

        $script = <<<'HTML'
<script data-w-widget-health-bridge="1">
(function () {
    function resolveToastUi() {
        try {
            if (window.Weline && window.Weline.UI && window.Weline.UI.toast) return window.Weline.UI;
        } catch (e) {}
        try {
            if (window.parent && window.parent !== window && window.parent.Weline && window.parent.Weline.UI && window.parent.Weline.UI.toast) {
                return window.parent.Weline.UI;
            }
        } catch (e) {}
        return null;
    }
    function ensureLocateStyles() {
        if (document.getElementById('w-widget-health-locate-style')) return;
        var style = document.createElement('style');
        style.id = 'w-widget-health-locate-style';
        style.textContent = [
            'html.w-widget-health-locate-open{overflow:hidden!important;}',
            '.w-widget-health-locate-backdrop{position:fixed;inset:0;z-index:calc(var(--weline-z-toast,1100) + 20);border:0;padding:0;margin:0;cursor:pointer;background:color-mix(in srgb,var(--weline-theme-text,#111) 42%,transparent);}',
            '.w-widget-health-locate-host{outline:3px solid var(--weline-theme-warning,#c9a227)!important;outline-offset:4px!important;position:relative!important;z-index:calc(var(--weline-z-toast,1100) + 19)!important;min-block-size:3rem!important;background:color-mix(in srgb,var(--weline-theme-warning,#c9a227) 8%,var(--weline-theme-surface,#fff))!important;}',
            '.w-widget-health-locate-pop,.w-widget-health-locate-panel{position:fixed!important;inset-block-start:50%!important;inset-inline-start:50%!important;transform:translate(-50%,-50%)!important;z-index:calc(var(--weline-z-toast,1100) + 21)!important;width:min(40rem,calc(100dvw - 2rem))!important;max-block-size:min(80dvh,calc(100dvh - 2rem))!important;overflow:auto!important;margin:0!important;padding:var(--weline-space-4,1rem)!important;display:grid!important;gap:var(--weline-space-3,.75rem)!important;background:var(--weline-theme-surface-raised,#fff)!important;color:var(--weline-theme-text,#111)!important;border:2px solid var(--weline-theme-danger,#b42318)!important;border-radius:var(--weline-radius-md,8px)!important;box-shadow:var(--weline-theme-shadow-md,0 12px 32px rgba(0,0,0,.28))!important;}',
            '.w-widget-health-locate-chrome{display:flex;justify-content:space-between;align-items:center;gap:var(--weline-space-2,.5rem);}',
            '.w-widget-health-locate-title{font-weight:600;margin:0;}',
            '.w-widget-health-locate-meta{font-size:var(--weline-font-size-sm,.875rem);color:var(--weline-theme-text-muted,#666);}',
            '.w-widget-health-locate-issue{display:grid;gap:var(--weline-space-1,.25rem);padding:var(--weline-space-3,.75rem);border:1px solid var(--weline-theme-border,#ddd);border-radius:var(--weline-radius-sm,6px);background:var(--weline-theme-surface,#fff);}',
            '.w-widget-health-locate-detail{margin:0;padding:var(--weline-space-2,.5rem);overflow:auto;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:var(--weline-font-size-sm,.875rem);background:color-mix(in srgb,var(--weline-theme-danger,#b42318) 8%,var(--weline-theme-surface,#fff));border-radius:var(--weline-radius-sm,6px);}'
        ].join('');
        (document.head || document.documentElement).appendChild(style);
    }
    function dismissLocate() {
        document.querySelectorAll('[data-w-widget-health-locate-active="1"]').forEach(function (node) {
            node.classList.remove('w-widget-health-locate-pop');
            node.classList.remove('w-widget-health-locate-host');
            node.removeAttribute('data-w-widget-health-locate-active');
        });
        document.querySelectorAll('[data-w-widget-health-locate-panel],[data-w-widget-health-locate-chrome],.w-widget-health-locate-backdrop').forEach(function (node) {
            node.remove();
        });
        document.documentElement.classList.remove('w-widget-health-locate-open');
        try { document.removeEventListener('keydown', onLocateKeydown, true); } catch (e) {}
    }
    function onLocateKeydown(event) {
        if (event && event.key === 'Escape') dismissLocate();
    }
    function findHealthNode(item) {
        var nodes = document.querySelectorAll('.widget-wrapper[data-w-widget-health]');
        var slot = String((item && item.slot) || '');
        var code = String((item && item.code) || '');
        var matched = null;
        nodes.forEach(function (node) {
            if (!(node instanceof HTMLElement) || matched) return;
            var nodeSlot = String(node.dataset.slotId || '');
            var nodeCode = String(node.dataset.widgetCode || '');
            if (slot && code && nodeSlot === slot && nodeCode === code) matched = node;
            else if (!matched && slot && nodeSlot === slot) matched = node;
            else if (!matched && code && nodeCode === code) matched = node;
        });
        if (!matched && item && item.el instanceof HTMLElement) matched = item.el;
        return matched;
    }
    function resolveLocateHost(node, item) {
        if (!(node instanceof HTMLElement)) return null;
        var slot = String((item && item.slot) || node.dataset.slotId || '').trim();
        if (slot) {
            try {
                var byAttr = document.querySelector('[data-wslot="' + slot.replace(/"/g, '') + '"]');
                if (byAttr instanceof HTMLElement) return byAttr;
            } catch (e) {}
        }
        var closest = node.closest('[data-wslot]');
        if (closest instanceof HTMLElement) return closest;
        if (node.parentElement instanceof HTMLElement) return node.parentElement;
        return node;
    }
    function collectHealthIssues(item, node) {
        var issues = [];
        if (item && Array.isArray(item.issues)) issues = item.issues.slice();
        if (!issues.length && node) {
            try {
                var parsed = JSON.parse(node.getAttribute('data-w-widget-health-issues') || '[]');
                if (Array.isArray(parsed)) issues = parsed;
            } catch (e) {}
        }
        return issues;
    }
    function forceExposeHealthPanel(item, node, host) {
        var issues = collectHealthIssues(item, node);
        var panel = document.createElement('section');
        panel.className = 'w-widget-health-locate-pop w-widget-health-locate-panel';
        panel.setAttribute('data-w-widget-health-locate-panel', '1');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        var titleText = String((item && (item.name || item.code)) || (node && (node.getAttribute('data-widget-name') || node.dataset.widgetCode)) || 'widget');
        var slotText = String((item && item.slot) || (node && node.dataset.slotId) || (host && host.getAttribute('data-wslot')) || '');
        if (slotText) titleText += ' @' + slotText;
        var chrome = document.createElement('div');
        chrome.className = 'w-widget-health-locate-chrome';
        chrome.setAttribute('data-w-widget-health-locate-chrome', '1');
        var title = document.createElement('h2');
        title.className = 'w-widget-health-locate-title';
        title.textContent = '部件异常：' + titleText;
        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'w-button';
        closeBtn.dataset.size = 'sm';
        closeBtn.dataset.tone = 'neutral';
        closeBtn.textContent = '关闭定位';
        closeBtn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            dismissLocate();
        });
        chrome.appendChild(title);
        chrome.appendChild(closeBtn);
        panel.appendChild(chrome);
        var meta = document.createElement('p');
        meta.className = 'w-widget-health-locate-meta';
        meta.textContent = '上层容器：' + (host && host.getAttribute('data-wslot')
            ? ('[data-wslot="' + host.getAttribute('data-wslot') + '"]')
            : (host && host.className ? ('.' + String(host.className).split(/\\s+/).filter(Boolean).join('.')) : 'parent'));
        panel.appendChild(meta);
        if (!issues.length) {
            var empty = document.createElement('p');
            empty.textContent = '未解析到错误明细，已高亮上层容器。';
            panel.appendChild(empty);
        } else {
            issues.forEach(function (issue) {
                var block = document.createElement('div');
                block.className = 'w-widget-health-locate-issue';
                var msg = document.createElement('strong');
                msg.textContent = String((issue && (issue.message || issue.code)) || 'HTML 异常');
                block.appendChild(msg);
                var detail = String((issue && issue.detail) || '').trim();
                if (detail) {
                    var pre = document.createElement('pre');
                    pre.className = 'w-widget-health-locate-detail';
                    pre.textContent = detail;
                    block.appendChild(pre);
                }
                panel.appendChild(block);
            });
        }
        document.body.appendChild(panel);
        return panel;
    }
    function locateHealthWidget(item) {
        ensureLocateStyles();
        dismissLocate();
        var node = findHealthNode(item);
        if (!node) {
            showToast('无法定位异常部件' + ((item && item.slot) ? (' @' + item.slot) : ''), 'warning');
            return;
        }
        var host = resolveLocateHost(node, item) || node;
        var backdrop = document.createElement('button');
        backdrop.type = 'button';
        backdrop.className = 'w-widget-health-locate-backdrop';
        backdrop.setAttribute('aria-label', '关闭部件定位');
        backdrop.addEventListener('click', dismissLocate);
        document.body.appendChild(backdrop);
        host.classList.add('w-widget-health-locate-host');
        host.setAttribute('data-w-widget-health-locate-active', '1');
        forceExposeHealthPanel(item, node, host);
        document.documentElement.classList.add('w-widget-health-locate-open');
        document.addEventListener('keydown', onLocateKeydown, true);
        try { host.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (e) {}
    }
    function buildHealthToastMessage(text, item) {
        var wrap = document.createElement('div');
        wrap.style.display = 'grid';
        wrap.style.gap = '0.5rem';
        var copy = document.createElement('span');
        copy.textContent = text;
        wrap.appendChild(copy);
        if (item) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'w-button';
            btn.dataset.size = 'sm';
            btn.dataset.tone = 'primary';
            btn.textContent = '定位';
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                locateHealthWidget(item);
            });
            wrap.appendChild(btn);
        }
        return wrap;
    }
    function showToast(message, type, item) {
        var tone = type === 'error' ? 'danger' : (['success', 'warning', 'info', 'danger'].indexOf(type) >= 0 ? type : 'info');
        var payload = (item && typeof message === 'string') ? buildHealthToastMessage(message, item) : message;
        var text = typeof message === 'string' ? String(message || '').trim() : '';
        if (!payload || (typeof message === 'string' && !text)) return;
        try {
            var UI = resolveToastUi();
            if (UI && UI.toast && typeof UI.toast.show === 'function') {
                UI.toast.show(payload, { tone: tone, duration: item ? 0 : (type === 'error' ? 8000 : 5000) });
                return;
            }
        } catch (e) {}
        try { console.warn('[WidgetHtmlHealth]', text || message); } catch (e) {}
    }
    function report() {
        if (document.documentElement.dataset.wWidgetHealthReported === '1') return;
        var nodes = document.querySelectorAll('.widget-wrapper[data-w-widget-health]');
        if (!nodes.length) return;
        document.documentElement.dataset.wWidgetHealthReported = '1';
        var findings = [];
        nodes.forEach(function (node) {
            if (!(node instanceof HTMLElement)) return;
            var severity = String(node.dataset.wWidgetHealth || 'warning').toLowerCase();
            var issues = [];
            try {
                var parsed = JSON.parse(node.getAttribute('data-w-widget-health-issues') || '[]');
                if (Array.isArray(parsed)) issues = parsed;
            } catch (e) {
                issues = [{ severity: severity, code: 'parse_error', message: '部件健康数据解析失败' }];
            }
            if (!issues.length) return;
            findings.push({
                severity: severity,
                code: String(node.dataset.widgetCode || ''),
                slot: String(node.dataset.slotId || ''),
                name: String(node.getAttribute('data-widget-name') || node.dataset.widgetCode || 'widget'),
                issues: issues,
                el: node
            });
        });
        if (!findings.length) return;
        var errorCount = findings.filter(function (item) { return item.severity === 'error'; }).length;
        var warningCount = findings.filter(function (item) { return item.severity === 'warning'; }).length;
        var summaryTone = errorCount ? 'error' : (warningCount ? 'warning' : 'info');
        showToast(
            '部件 HTML 健康检测：' + findings.length + ' 个部件异常'
                + (errorCount ? '（错误 ' + errorCount + '）' : '')
                + (warningCount ? '（警告 ' + warningCount + '）' : ''),
            summaryTone,
            findings.length === 1 ? findings[0] : null
        );
        findings.slice(0, 8).forEach(function (item) {
            var first = item.issues[0] || {};
            showToast(
                (item.name || item.code || 'widget') + (item.slot ? ' @' + item.slot : '') + '：' + String(first.message || first.code || 'HTML 异常'),
                item.severity === 'error' ? 'error' : (item.severity === 'warning' ? 'warning' : 'info'),
                item
            );
        });
        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    source: 'weline-theme-preview',
                    type: 'widget-health',
                    summary: '部件 HTML 健康检测：' + findings.length + ' 个部件异常',
                    severity: summaryTone,
                    findings: findings.map(function (item) {
                        return {
                            severity: item.severity,
                            code: item.code,
                            slot: item.slot,
                            name: item.name,
                            issues: item.issues
                        };
                    })
                }, window.location.origin);
            }
        } catch (e) {}
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', report, { once: true });
    } else {
        report();
    }
})();
</script>
HTML;

        if (stripos($html, '</head>') !== false) {
            return (string)str_ireplace('</head>', $script . "\n</head>", $html);
        }
        if (stripos($html, '</body>') !== false) {
            return (string)str_ireplace('</body>', $script . "\n</body>", $html);
        }

        return $script . "\n" . $html;
    }

    /**
     * DOM 序列化后再次扫描 .widget-wrapper 内 HTML，补打 data-w-widget-health*。
     * 渲染前检测看不到 saveHTML 造成的空标签闭合丢失。
     * 深度行走前先 park script/style，避免源码中的 </div> 造成过早闭合误报。
     */
    private function stampFinalWidgetHtmlHealth(string $html): string
    {
        if ($html === '' || !$this->shouldInspectWidgetHtml() || !str_contains($html, 'widget-wrapper')) {
            return $html;
        }

        try {
            /** @var WidgetHtmlHealthInspector $inspector */
            $inspector = ObjectManager::getInstance(WidgetHtmlHealthInspector::class);
        } catch (\Throwable) {
            return $html;
        }

        $priorTokens = $this->domOpaqueTokens;
        $this->domOpaqueTokens = [];
        try {
            $work = $this->parkDomOpaqueBlocks($html);
            $length = \strlen($work);
            $offset = 0;
            $out = '';

            while ($offset < $length) {
                $open = $this->findNextWidgetWrapperOpen($work, $offset);
                if ($open === null) {
                    $out .= \substr($work, $offset);
                    break;
                }

                $openStart = $open['start'];
                $openTag = $open['tag'];
                $openEnd = $open['end'];
                $out .= \substr($work, $offset, $openStart - $offset);

                $innerEnd = $this->findMatchingDivClose($work, $openEnd);
                if ($innerEnd === null) {
                    $out .= $openTag;
                    $offset = $openEnd;
                    continue;
                }

                $innerParked = \substr($work, $openEnd, $innerEnd - $openEnd);
                $inner = $this->restoreDomOpaqueBlocks($innerParked);
                $meta = [
                    'module' => $this->attrFromTag($openTag, 'data-widget-module'),
                    'code' => $this->attrFromTag($openTag, 'data-widget-code'),
                    'type' => $this->attrFromTag($openTag, 'data-widget-type'),
                    'slot_id' => $this->attrFromTag($openTag, 'data-slot-id'),
                    'layout_id' => $this->attrFromTag($openTag, 'data-layout-id')
                        ?: $this->attrFromTag($openTag, 'data-node-uid'),
                ];
                try {
                    $issues = $inspector->inspect($inner, $meta);
                    $issues = $inspector->suppressSatisfiedDualPathEmpty($issues, $meta, $html);
                } catch (\Throwable) {
                    $issues = [];
                }

                // Always refresh: wrap-time stamp may have flagged empty_html before Hook float existed.
                $openTag = $this->stripHtmlAttributes($openTag, [
                    'data-w-widget-health',
                    'data-w-widget-health-issues',
                ]);
                if ($issues !== []) {
                    $severity = $inspector->worstSeverity($issues);
                    $encoded = \json_encode($issues, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
                    $openTag = $this->upsertHtmlAttribute($openTag, 'data-w-widget-health', $severity);
                    if (\is_string($encoded) && $encoded !== '') {
                        $openTag = $this->upsertHtmlAttribute($openTag, 'data-w-widget-health-issues', $encoded);
                    }
                }

                $out .= $openTag . $innerParked . '</div>';
                $offset = $innerEnd + 6;
            }

            return $this->restoreDomOpaqueBlocks($out);
        } finally {
            $this->domOpaqueTokens = $priorTokens;
        }
    }

    private function attrFromTag(string $openTag, string $name): string
    {
        if (\preg_match('/\b' . \preg_quote($name, '/') . '=(["\'])(.*?)\1/i', $openTag, $match) === 1) {
            return (string)$match[2];
        }

        return '';
    }

    private function upsertHtmlAttribute(string $openTag, string $name, string $value): string
    {
        $safe = \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $pattern = '/\s' . \preg_quote($name, '/') . '=(["\'])(?:.*?)\1/i';
        if (\preg_match($pattern, $openTag) === 1) {
            return (string)\preg_replace($pattern, ' ' . $name . '="' . $safe . '"', $openTag, 1);
        }

        return \rtrim(\substr($openTag, 0, -1)) . ' ' . $name . '="' . $safe . '">';
    }

    /**
     * Wrap with .widget-wrapper when the widget has a stable identity (node_uid or
     * numeric layout_id), or when health inspection reported issues (so preview JS
     * can read data-w-widget-health*).
     *
     * @param array<string, mixed> $widget
     * @param array<string, mixed> $config
     */
    private function maybeWrapWidgetHtml(string $innerHtml, array $widget, array $config, string $widgetName): string
    {
        $layoutId = (int)($widget['layout_id'] ?? 0);
        $nodeUid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
        if ($nodeUid !== '' && \preg_match('/^[a-f0-9]{32}$/D', $nodeUid) !== 1) {
            $nodeUid = '';
        }
        $hasIdentity = $nodeUid !== '' || $layoutId > 0;

        $healthPayload = null;
        if ($this->shouldInspectWidgetHtml()) {
            $healthPayload = $this->inspectWidgetHtmlHealth($innerHtml, $widget);
        }

        if (!$hasIdentity && $healthPayload === null) {
            return $innerHtml;
        }

        $wrapperAttrs = $this->buildWidgetWrapperAttrs($widget, $config, $healthPayload);
        return \sprintf(
            '<div class="widget-wrapper" %s data-widget-name="%s">%s</div>',
            $wrapperAttrs,
            \htmlspecialchars($widgetName, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            $innerHtml
        );
    }

    private function shouldInspectWidgetHtml(): bool
    {
        if (\defined('DEV') && DEV) {
            return true;
        }

        return $this->isEditorPreviewRequest();
    }

    /**
     * @param array<string, mixed> $widget
     * @return array{severity:string,issues:list<array{severity:string,code:string,message:string,detail?:string>}}|null
     */
    private function inspectWidgetHtmlHealth(string $html, array $widget): ?array
    {
        try {
            /** @var WidgetHtmlHealthInspector $inspector */
            $inspector = ObjectManager::getInstance(WidgetHtmlHealthInspector::class);
            $issues = $inspector->inspect($html, [
                'module' => (string)($widget['widget_module'] ?? ''),
                'code' => (string)($widget['widget_code'] ?? ''),
                'type' => (string)($widget['widget_type'] ?? ''),
                'slot_id' => (string)($widget['slot_id'] ?? ''),
                'layout_id' => (string)($widget['layout_id'] ?? ''),
            ]);
        } catch (\Throwable) {
            return null;
        }

        if ($issues === []) {
            return null;
        }

        return [
            'severity' => $inspector->worstSeverity($issues),
            'issues' => $issues,
        ];
    }

    /**
     * @param array<string, mixed> $widget
     * @param array<string, mixed> $config
     * @param array{severity:string,issues:list<array<string,mixed>>}|null $healthPayload
     */
    private function buildWidgetWrapperAttrs(array $widget, array $config, ?array $healthPayload = null): string
    {
        $layoutId = (int)($widget['layout_id'] ?? 0);
        $nodeUid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
        if ($nodeUid !== '' && \preg_match('/^[a-f0-9]{32}$/D', $nodeUid) !== 1) {
            $nodeUid = '';
        }
        // Hex identity is node_uid only; keep data-layout-id for numeric legacy keys.
        $attrs = [
            'data-widget-code' => (string)($widget['widget_code'] ?? ''),
            'data-widget-module' => (string)($widget['widget_module'] ?? ''),
            'data-widget-type' => (string)($widget['widget_type'] ?? ''),
            'data-slot-id' => (string)($widget['slot_id'] ?? ''),
            'data-layout-option' => (string)($widget['layout_option'] ?? ''),
            'data-layout-scope' => (string)($widget['scope'] ?? ''),
            'data-target-type' => (string)($widget['target_type'] ?? ''),
            'data-target-id' => (string)($widget['target_id'] ?? ''),
        ];
        if ($nodeUid !== '') {
            $attrs['data-node-uid'] = $nodeUid;
        } elseif ($layoutId > 0) {
            $attrs['data-layout-id'] = (string)$layoutId;
        }

        if (\is_array($healthPayload) && ($healthPayload['issues'] ?? []) !== []) {
            $attrs['data-w-widget-health'] = (string)($healthPayload['severity'] ?? 'warning');
            $encoded = \json_encode(
                $healthPayload['issues'],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
            );
            if (\is_string($encoded) && $encoded !== '') {
                $attrs['data-w-widget-health-issues'] = $encoded;
            }
        }

        $dashboardLayout = $config['dashboard_layout'] ?? [];
        $dashboardLayout = \is_array($dashboardLayout) ? $dashboardLayout : [];
        $slotId = (string)($widget['slot_id'] ?? '');
        $dashboardSlots = [
            'dashboard-summary' => true,
            'dashboard-analysis' => true,
            'dashboard-side' => true,
            'dashboard-detail' => true,
        ];
        if ($this->renderArea === 'backend' && isset($dashboardSlots[$slotId])) {
            $colSpan = (int)($dashboardLayout['colSpan'] ?? $dashboardLayout['col_span'] ?? 3);
            $rowSpan = (int)($dashboardLayout['rowSpan'] ?? $dashboardLayout['row_span'] ?? 1);
            $sortOrder = (int)($dashboardLayout['sortOrder'] ?? $dashboardLayout['sort_order'] ?? 0);
            $colSpan = \max(1, \min(12, $colSpan));
            $rowSpan = \max(1, \min(8, $rowSpan));

            $attrs['data-dashboard-col-span'] = (string)$colSpan;
            $attrs['data-dashboard-row-span'] = (string)$rowSpan;
            if ($sortOrder > 0) {
                $attrs['data-dashboard-sort-order'] = (string)$sortOrder;
            }
        }

        $parts = [];
        foreach ($attrs as $name => $value) {
            if ($value === '') {
                continue;
            }
            $parts[] = \sprintf(
                '%s="%s"',
                $name,
                \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        return \implode(' ', $parts);
    }

    private function appendWidgetRenderContext(array $config, array $widget): array
    {
        $layoutId = (string)($widget['layout_id'] ?? '');
        $nodeUid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
        $slotId = (string)($widget['slot_id'] ?? '');
        $renderArea = $this->renderArea === 'backend' ? 'backend' : 'frontend';

        $config['layout_id'] = $layoutId;
        $config['_layout_id'] = $layoutId;
        if ($nodeUid !== '' && \preg_match('/^[a-f0-9]{32}$/D', $nodeUid) === 1) {
            $config['node_uid'] = $nodeUid;
            $config['_node_uid'] = $nodeUid;
        }
        $config['slot_id'] = $slotId;
        $config['_slot_id'] = $slotId;
        $config['_widget_module'] = (string)($widget['widget_module'] ?? '');
        $config['_widget_code'] = (string)($widget['widget_code'] ?? '');
        $config['_widget_type'] = (string)($widget['widget_type'] ?? '');
        $config['_widget_area'] = $renderArea;
        $isEditor = $this->isEditorPreviewRequest();
        $config['editor_mode'] = $isEditor || !empty($config['editor_mode']);
        // ComponentRenderer unsetData() 会清掉模板上的 preview_mode；必须显式写入 config。
        // preview_mode 布尔 = 部件库小画布压缩（is-preview），与查询串 preview_mode=live（整页布局预览）解耦。
        // 整页店面 editor_mode 画布必须与店面 chrome 保真，禁止强制 preview_mode=true。
        if ($isEditor) {
            $config['preview_mode'] = false;
        } else {
            $config['preview_mode'] = !empty($config['preview_mode']);
        }

        foreach ($this->pageRenderContext as $key => $value) {
            if (!\array_key_exists($key, $config)
                || $config[$key] === null
                || $config[$key] === ''
                || $config[$key] === []
            ) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    /**
     * 在首个部件 unsetData 前冻结页面上下文，供后续部件 config 回注。
     */
    private function capturePageRenderContext(): void
    {
        $keys = [
            'storefront_offer',
            'storefront_offers',
            'storefront_offers_unfiltered',
            'storefront_category',
            'selected_offer_uuid',
            'variant_catalog',
            'page_title',
            'preview_mode',
            'editor_mode',
        ];
        $snapshot = [];
        foreach ($keys as $key) {
            try {
                $value = $this->template->getData($key);
            } catch (\Throwable) {
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $snapshot[$key] = $value;
        }
        $this->pageRenderContext = $snapshot;
    }

    private function isEditorPreviewRequest(): bool
    {
        try {
            $request = $this->template->getRequest();
            // preview_mode=live|version|draft is a layout-source query flag for canvas / preview.
            // Do not treat it as widget-library compact preview_mode.
            // It must NOT imply widget-canvas compact preview (is-preview / flattened mega trees).
            if ((string)$request->getParam('editor_mode', '') === '1'
                || (string)$request->getParam('interaction_mode', '') === 'edit'
            ) {
                return true;
            }
        } catch (\Throwable) {
            // fall through to template flags
        }

        try {
            return (bool)$this->template->getData('editor_mode');
        } catch (\Throwable) {
            return false;
        }
    }

    private function mergeTranslatedWidgetConfig(
        array $widget,
        array $config,
        ?ThemeComponentDefinition $definition = null
    ): array {
        try {
            if (!empty($config['_skip_translation_merge'])) {
                unset($config['_skip_translation_merge']);
                return $config;
            }

            $widgetModule = (string)($widget['widget_module'] ?? '');
            $widgetCode = (string)($widget['widget_code'] ?? '');
            $widgetType = (string)($widget['widget_type'] ?? '');
            $widgetArea = $this->renderArea === 'backend' ? 'backend' : 'frontend';
            $instanceIdentify = $this->resolveWidgetInstanceIdentify($config, $widgetArea);
            $locale = $this->resolveRenderLocale();

            if ($definition) {
                $identify = $instanceIdentify !== '' ? $instanceIdentify : (($widgetModule === 'Weline_Theme'
                    && ($widgetType === 'theme_component' || str_contains($widgetCode, '/')))
                    ? $definition->getMetaIdentify()
                    : ThemeData::getWidgetIdentify($widgetModule, $widgetCode, $widgetArea));

                return $this->mergeTranslatedPathsWithLegacyInstance(
                    $widget,
                    $config,
                    $definition->params ?: $definition->configSchema,
                    $identify,
                    $locale,
                    $widgetArea
                );
            }

            if ($widgetModule === '' || $widgetCode === '') {
                return $config;
            }

            $params = ThemeData::getWidgetParamDefinitions($widgetModule, $widgetCode, $widgetArea);
            if (empty($params)) {
                return $config;
            }

            return $this->mergeTranslatedPathsWithLegacyInstance(
                $widget,
                $config,
                $params,
                $instanceIdentify !== '' ? $instanceIdentify : ThemeData::getWidgetIdentify($widgetModule, $widgetCode, $widgetArea),
                $locale,
                $widgetArea
            );
        } catch (\Throwable) {
            return $config;
        }
    }

    private function mergeTranslatedPathsWithLegacyInstance(
        array $widget,
        array $config,
        array $params,
        string $identify,
        ?string $locale,
        string $area
    ): array {
        $legacyIdentify = $this->legacySlotAreaWidgetInstanceIdentify($widget, $identify, $area);
        if ($legacyIdentify !== '') {
            $config = ThemeData::mergeTranslatedPaths($config, $params, $legacyIdentify, $locale);
        }

        return ThemeData::mergeTranslatedPaths($config, $params, $identify, $locale);
    }

    private function resolveRenderLocale(): ?string
    {
        $locale = '';
        try {
            $locale = trim((string)(RequestContext::locale() ?: ''));
        } catch (\Throwable) {
            $locale = '';
        }
        if ($locale === '') {
            return null;
        }

        return preg_match('/^[a-z]{2,3}_[A-Za-z0-9]+(?:_[A-Za-z0-9]+)?$/', $locale)
            ? $locale
            : null;
    }

    /** @param array<string,mixed> $config */
    private function hydrateTypedLayoutValues(array $config, string $renderArea, array $widget): array
    {
        $locale = trim((string)($widget['locale_code'] ?? ''));
        // Empty/default layout locale = all-language identity.
        // Frontend: keep empty so FileImage hydrator follows each usage.locale_code
        // after mergeTranslatedPaths (per-locale media overlays).
        // Backend: stamp site default (media picker stamp) for typed hydration.
        if ($locale === '' || strcasecmp($locale, 'default') === 0) {
            if ($renderArea === 'backend') {
                $locale = trim((string)Env::default_LANGUAGE_CODE);
                if ($locale === '' || strcasecmp($locale, 'default') === 0) {
                    $locale = $this->resolveRenderLocale() ?? '';
                }
                if ($locale === '' || strcasecmp($locale, 'default') === 0) {
                    throw new \RuntimeException((string)__('类型化布局值解析缺少冻结的 locale_code。'));
                }
            } else {
                $locale = '';
            }
        }
        $scope = null;
        $encodedScope = trim((string)($widget['scope'] ?? ''));
        if ($encodedScope !== '' && !str_contains($encodedScope, ':')) {
            try {
                $scope = ObjectManager::getInstance(ThemeLayoutScopeNormalizer::class)
                    ->identityFromEncodedScope($encodedScope);
            } catch (\Throwable) {
                $scope = null;
            }
        }
        $scope ??= RequestContext::scopeIdentity();
        if ($scope === null) {
            // Ordinary backend chrome intentionally does not freeze ScopeIdentity.
            // Resolve a local Global identity for hydration only — never install it.
            // Frontend entity fill can also run before LayoutIdentity is installed;
            // fall back to Global rather than failing the whole widget as "missing".
            try {
                $scope = ObjectManager::getInstance(ThemeLayoutScopeNormalizer::class)
                    ->identityFromEncodedScope(ThemeContextService::DEFAULT_SCOPE);
            } catch (\Throwable) {
                throw new \RuntimeException((string)__('类型化布局值解析缺少冻结的 ScopeIdentity。'));
            }
        }

        $isAuthorizedPreview = false;
        try {
            $previewContexts = ObjectManager::getInstance(PreviewContextService::class);
            $isAuthorizedPreview = $previewContexts->hasAuthoritativePreviewContext();
        } catch (\Throwable) {
            $previewContexts = null;
            $isAuthorizedPreview = false;
        }

        [$actorId, $roles, $policyRevision] = $this->authoritativeFileAccessClaims(
            $renderArea,
            $isAuthorizedPreview,
        );

        return $this->layoutValueHydrators->hydrate($config, [
            'scope_identity' => $scope,
            'locale_code' => $locale,
            'actor_id' => $actorId,
            'roles' => $roles,
            'purpose' => ($renderArea === 'backend' || $isAuthorizedPreview) ? 'preview' : 'render',
            'policy_revision' => $policyRevision,
        ]);
    }

    /** @return array{0:?int,1:list<string>,2:int} */
    private function authoritativeFileAccessClaims(string $renderArea, bool $authorizedPreview): array
    {
        if ($renderArea !== 'backend' && !$authorizedPreview) {
            return [null, [], 1];
        }

        // A token is an opaque server-side capability. Only claims loaded from
        // its protected cache payload are trusted; URL/request parameters are
        // never considered FileAccessContext facts.
        try {
            $tokenData = ObjectManager::getInstance(PreviewTokenService::class)->getCurrentPreviewData();
            $tokenContext = is_array($tokenData['context'] ?? null) ? $tokenData['context'] : [];
            $actorId = (int)($tokenContext['file_access_actor_id'] ?? 0);
            if ($actorId > 0) {
                // The token retains identity, not an authorization result.
                // Re-load enabled state and the current role for every request
                // so a disabled user or changed role takes effect immediately.
                $user = ObjectManager::getInstance(BackendUserContextProviderInterface::class)->find($actorId);
                if ($user === null || !$user->getIsEnabled() || $user->getId() !== $actorId) {
                    return [null, [], 1];
                }
                return [
                    $actorId,
                    $user->getRoleId() > 0 ? ['backend_role:' . $user->getRoleId()] : [],
                    max(1, (int)($tokenContext['file_access_policy_revision'] ?? 1)),
                ];
            }
        } catch (\Throwable) {
            // Fall through to the current request's authenticated backend user.
        }

        try {
            $user = ObjectManager::getInstance(BackendUserContextProviderInterface::class)->current();
            if ($user !== null && $user->getIsEnabled() && $user->getId() > 0) {
                return [
                    $user->getId(),
                    $user->getRoleId() > 0 ? ['backend_role:' . $user->getRoleId()] : [],
                    1,
                ];
            }
        } catch (\Throwable) {
            // A missing authenticated actor is deliberately represented as null;
            // private FileAsset policy will then fail closed.
        }

        return [null, [], 1];
    }

    private function resolveWidgetInstanceIdentify(array $config, string $area): string
    {
        $instanceId = trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? ''));
        if ($instanceId === '') {
            return '';
        }

        return ThemeData::getWidgetInstanceIdentify($instanceId, $area);
    }

    private function legacySlotAreaWidgetInstanceIdentify(array $widget, string $identify, string $area): string
    {
        $slotArea = strtolower(trim((string)($widget['slot_id'] ?? $widget['area'] ?? '')));
        $area = strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
        if ($slotArea === '' || $slotArea === $area || $slotArea === 'frontend' || $slotArea === 'backend') {
            return '';
        }

        if (!preg_match('/^theme\.(frontend|backend)\.widget_instances\.([^\\s]+)$/', $identify, $matches)) {
            return '';
        }

        $slotArea = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $slotArea) ?: '';
        if ($slotArea === '') {
            return '';
        }

        return 'theme.' . $matches[1] . '.' . $slotArea . '.widget_instances.' . $matches[2];
    }

    /**
     * 获取部件元数据
     */
    private function buildWidgetOutputCacheKey(array $widget, array $config): ?string
    {
        $widgetModule = (string)($widget['widget_module'] ?? '');
        $widgetCode = (string)($widget['widget_code'] ?? '');
        $identity = $widgetModule . '::' . $widgetCode;
        if (!isset(self::CACHEABLE_WIDGET_OUTPUTS[$identity])) {
            return null;
        }

        try {
            // Chrome widget HTML must share across same-locale PDPs. Request::getBaseUrl()
            // and pathInfo embed the product URI and would shard process L1 per URL.
            $context = [
                'identity' => $identity,
                'layout_id' => (string)($widget['layout_id'] ?? ''),
                'slot_id' => (string)($widget['slot_id'] ?? ''),
                'type' => (string)($widget['widget_type'] ?? ''),
                'area' => $this->renderArea === 'backend' ? 'backend' : 'frontend',
                'config' => $config,
                'cache_version' => '20260921-widget-origin-base',
                'environment' => KeyBuilder::environmentContext([
                    'scope' => 'theme-widget-output',
                ], [
                    'area_route' => false,
                    'base_url' => true,
                ]),
            ];
        } catch (\Throwable) {
            return null;
        }

        return 'widget.output.' . \sha1(\json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $identity);
    }

    private function widgetInstanceKey(array $widget, array $config): string
    {
        return \sha1(\json_encode([
            'module' => (string)($widget['widget_module'] ?? ''),
            'code' => (string)($widget['widget_code'] ?? ''),
            'type' => (string)($widget['widget_type'] ?? ''),
            'layout_id' => (string)($widget['layout_id'] ?? ''),
            'slot_id' => (string)($widget['slot_id'] ?? ''),
            'config' => $config,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function rememberWidgetOutput(?string $cacheKey, string $html): string
    {
        if ($cacheKey === null) {
            return $html;
        }

        $this->rememberProcessWidgetOutput($cacheKey, [
            'expires_at' => \microtime(true) + $this->widgetOutputCacheTtl(),
            'html' => $html,
        ]);
        $this->runtimeCacheSet($cacheKey, $html, $this->widgetOutputCacheTtl());

        return $html;
    }

    private function getWidgetMeta(string $module, string $code, string $area): ?array
    {
        $registry = $this->widgetRegistry->getRegistry();

        foreach ($registry as $type => $typeWidgets) {
            if (!is_array($typeWidgets)) {
                continue;
            }
            foreach ($typeWidgets as $widgetCode => $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                if (isset($widget['module']) && isset($widget['code'])
                    && $widget['module'] === $module && $widget['code'] === $code) {
                    $widgetArea = (string)($widget['area'] ?? 'frontend');
                    if ($widgetArea !== '' && $widgetArea !== $area) {
                        continue;
                    }
                    return $widget;
                }
            }
        }

        return null;
    }

    /**
     * 获取布局数据（带缓存和降级逻辑）
     * 
     * 草稿（draft）不缓存：后台编辑器/预览为实时操作，多进程下其他 Worker 可能仍持旧缓存，
     * 导致删除/拖拽后预览不更新，故 draft 始终从 DB 读取。
     * 
     * 优先级：
     * 1. 按指定状态获取数据
     * 2. 如果是已发布状态且没有数据，尝试获取草稿数据
     * 3. 如果仍然没有数据，尝试生成默认布局种子
     * 4. 如果当前页面类型没有数据，尝试获取默认页面类型的数据
     */
    /**
     * @return array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code:string}
     */
    private function currentLayoutIdentity(string $area = 'frontend'): array
    {
        $installed = RequestContext::get(LayoutIdentity::REQUEST_CONTEXT_KEY);
        if ($installed instanceof LayoutIdentity) {
            $array = $installed->toArray();
            // Structure identity never carries request locale.
            $array['locale_code'] = '';
            return $array;
        }

        // Live storefront preview Token: prefer the identity captured at start-preview
        // so draft processSlots matches the visual editor workspace (not RequestContext defaults).
        try {
            /** @var PreviewContextService $previewContexts */
            $previewContexts = ObjectManager::getInstance(PreviewContextService::class);
            if ($previewContexts->hasAuthoritativePreviewContext()
                || ObjectManager::getInstance(PreviewTokenService::class)->isPreviewMode()
            ) {
                $preview = $previewContexts->getCurrentContext();
                $tokenData = ObjectManager::getInstance(PreviewTokenService::class)->getCurrentPreviewData();
                if (\is_array($tokenData['context'] ?? null)) {
                    $preview = \array_replace($preview, $tokenData['context']);
                }
                $normalizer = ObjectManager::getInstance(ThemeLayoutScopeNormalizer::class);
                $normalized = $normalizer->normalize([
                    'scope' => (string)($preview['scope'] ?? PreviewContextService::DEFAULT_SCOPE),
                    'store_mode' => (string)($preview['store_mode'] ?? 'normal'),
                    'locale_code' => '',
                ]);
                $layoutOption = \trim((string)($preview['layout_option'] ?? 'default'));
                if ($layoutOption === '') {
                    $layoutOption = 'default';
                }
                $targetType = \trim((string)(
                    $preview['theme_layout_target_type']
                    ?? $preview['theme_layout_source_target_type']
                    ?? 'global'
                ));
                if ($targetType === '') {
                    $targetType = 'global';
                }
                $targetId = $targetType === 'global'
                    ? 0
                    : \max(0, (int)(
                        $preview['theme_layout_target_id']
                        ?? $preview['theme_layout_source_target_id']
                        ?? 0
                    ));

                return (new LayoutIdentity(
                    $layoutOption,
                    $normalized['scope'],
                    $targetType,
                    $targetId,
                    '',
                ))->toArray();
            }
        } catch (\Throwable) {
            // Fall through to RequestContext ScopeIdentity.
        }

        $area = $area === 'backend' || $this->renderArea === 'backend' ? 'backend' : 'frontend';
        $scope = RequestContext::scopeIdentity();
        $normalizer = ObjectManager::getInstance(ThemeLayoutScopeNormalizer::class);
        if ($scope === null) {
            // Match ControllerFetchFileBefore / ThemeContextService: ordinary backend
            // uses explicit Global layout scope without installing a synthetic request identity.
            if ($area !== 'backend') {
                throw new \RuntimeException((string)__('Theme 布局渲染缺少冻结的 ScopeIdentity。'));
            }
            $normalized = $normalizer->normalize([
                'scope' => ThemeContextService::DEFAULT_SCOPE,
                'locale_code' => '',
            ]);
        } else {
            $normalized = $normalizer->normalize([
                'scope_identity' => $scope,
                'locale_code' => '',
            ]);
        }

        return (new LayoutIdentity(
            'default',
            $normalized['scope'],
            'global',
            0,
            '',
        ))->toArray();
    }

    private function getLayoutData(
        int $themeId,
        string $pageType,
        string $status = ThemeLayout::STATUS_PUBLISHED,
        string $area = 'frontend',
        ?array $identityOverride = null,
    ): array
    {
        $identity = $identityOverride ?? $this->currentLayoutIdentity($area);
        $identity['locale_code'] = '';
        $hasTargetIdentity = $this->hasTargetIdentity($identity);
        // Structure cache key: no locale (mount graph is language-neutral).
        $cacheKey = "{$themeId}:{$pageType}:{$status}:"
            . $identity['layout_option'] . ':'
            . $identity['scope'] . ':'
            . $identity['target_type'] . ':'
            . $identity['target_id'];
        $isDraft = ($status === ThemeLayout::STATUS_DRAFT);
        $cacheablePublished = !$isDraft && !$hasTargetIdentity;

        $layout = null;
        /** @var ThemeRuntimeLayoutResolver $runtimeLayoutResolver */
        $runtimeLayoutResolver = ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class);
        // 草稿和页面级 target 不读缓存，保证编辑器/预览/页面级渲染每次按当前 identify 取数。
        if ($cacheablePublished && isset($this->layoutCache[$cacheKey])) {
            $layout = $this->layoutCache[$cacheKey];
        } elseif ($cacheablePublished) {
            $cached = self::$publishedLayoutDataCache[$cacheKey] ?? null;
            if (\is_array($cached)
                && isset($cached['expires_at'], $cached['data'])
                && (float)$cached['expires_at'] >= \microtime(true)
                && \is_array($cached['data'])) {
                unset(self::$publishedLayoutDataCache[$cacheKey]);
                self::$publishedLayoutDataCache[$cacheKey] = $cached;
                $this->layoutCache[$cacheKey] = $cached['data'];
                $layout = $cached['data'];
            } else {
                unset(self::$publishedLayoutDataCache[$cacheKey]);
                // Cross-worker structure reuse via CachePolicy HotCache (not theme_runtime IPC).
                $hotCache = $this->resolvePublishedLayoutHotCache();
                if ($hotCache instanceof StorefrontScopeHotCache) {
                    $resolved = $hotCache->rememberPolicy(
                        StorefrontThemeCacheCoordinator::publishedLayoutStructurePolicy(),
                        $this->publishedLayoutStructureLogicalKey($themeId, $pageType, $area, $identity),
                        static function () use (
                            $runtimeLayoutResolver,
                            $themeId,
                            $pageType,
                            $status,
                            $area,
                            $identity,
                        ): array {
                            return $runtimeLayoutResolver->resolveLayout(
                                $themeId,
                                $pageType,
                                $status,
                                $area,
                                $identity,
                            );
                        },
                    );
                    if (\is_array($resolved)) {
                        $this->layoutCache[$cacheKey] = $resolved;
                        $this->rememberPublishedLayoutData($cacheKey, $resolved);
                        $layout = $resolved;
                    }
                }
            }
        }

        if (!\is_array($layout)) {
            // 1. Structure-only resolve (published 不读 legacy theme_layout).
            $layout = $runtimeLayoutResolver->resolveLayout($themeId, $pageType, $status, $area, $identity);

            // Hard cutover: no request-time PAGE_TYPE_DEFAULT steal, footer-container
            // repair, ProductPageLayoutNormalizer, or default_injections — bake only.
            // Empty published layouts stay empty until an immutable Release is created.

            // 仅普通已发布布局写入结构缓存；草稿和页面级 target 不缓存。
            // Storefront must not rely on this path (entity SlotFiller). Kept for
            // editor/draft callers that still hit getLayoutData.
            if ($cacheablePublished) {
                $this->layoutCache[$cacheKey] = $layout;
                $this->rememberPublishedLayoutData($cacheKey, $layout);
                $hotCache = $this->resolvePublishedLayoutHotCache();
                if ($hotCache instanceof StorefrontScopeHotCache) {
                    $hotCache->rememberPolicy(
                        StorefrontThemeCacheCoordinator::publishedLayoutStructurePolicy(),
                        $this->publishedLayoutStructureLogicalKey($themeId, $pageType, $area, $identity),
                        static fn(): array => $layout,
                    );
                }
            }
        }

        // Language overlay after structure cache hit/miss — must not write back into structure cache.
        // Deep-copy so in-place overlay cannot mutate the shared structure entry.
        $structure = \json_decode(\json_encode($layout, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '[]', true);
        if (!\is_array($structure)) {
            $structure = $layout;
        }

        return $this->overlayLayoutLocale(
            $runtimeLayoutResolver,
            $structure,
            $themeId,
            $pageType,
            $status,
            $area,
            $identity,
        );
    }

    /**
     * @param array<string,mixed> $layout
     * @param array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code?:string} $identity
     * @return array<string,mixed>
     */
    private function overlayLayoutLocale(
        ThemeRuntimeLayoutResolver $runtimeLayoutResolver,
        array $layout,
        int $themeId,
        string $pageType,
        string $status,
        string $area,
        array $identity,
    ): array {
        $locale = $this->resolveRenderLocale();
        if ($locale === null || $locale === '' || \strcasecmp($locale, 'default') === 0) {
            return $layout;
        }

        try {
            $context = $runtimeLayoutResolver->buildContext($themeId, $pageType, $area, $identity)
                ->withLocale($locale);
            /** @var \Weline\Theme\Service\Scoped\ThemeScopedPreviewResolver $preview */
            $preview = ObjectManager::getInstance(\Weline\Theme\Service\Scoped\ThemeScopedPreviewResolver::class);

            return $preview->applyLayoutLocaleOverlay($layout, $context, $status);
        } catch (\Throwable) {
            return $layout;
        }
    }

    /** @param array<string,mixed> $layout */
    private function rememberPublishedLayoutData(string $cacheKey, array $layout): void
    {
        if (count(self::$publishedLayoutDataCache) >= self::MAX_PUBLISHED_LAYOUT_CACHE_ENTRIES
            && !isset(self::$publishedLayoutDataCache[$cacheKey])
        ) {
            array_shift(self::$publishedLayoutDataCache);
        }
        unset(self::$publishedLayoutDataCache[$cacheKey]);
        self::$publishedLayoutDataCache[$cacheKey] = [
            'expires_at' => microtime(true) + $this->publishedLayoutCacheTtl(),
            'data' => $layout,
        ];
    }
    
    /**
     * @param array{target_type:string,target_id:int} $identity
     */
    private function hasTargetIdentity(array $identity): bool
    {
        $targetType = trim((string)($identity['target_type'] ?? ''));
        $targetId = (int)($identity['target_id'] ?? 0);

        return ($targetType !== '' && $targetType !== 'global') || $targetId > 0;
    }
    
    /**
     * 检查布局中是否有部件
     */
    private function hasWidgetsInLayout(array $layout): bool
    {
        foreach ($layout as $area => $areaData) {
            if (!empty($areaData['widgets'])) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 按插槽 ID 组织部件
     */
    private function organizeWidgetsBySlot(array $layoutData): array
    {
        $slotWidgets = [];

        foreach ($layoutData as $area => $areaData) {
            $widgets = $areaData['widgets'] ?? [];

            foreach ($widgets as $widget) {
                // 优先使用部件定义的 slot_id 属性，其次使用 widget 配置的 slot，最后使用 area
                // 注意：使用 ?: 而不是 ??，因为空字符串也应该被视为无效值
                $slotId = (!empty($widget['slot_id']) ? $widget['slot_id'] : null)
                    ?? (!empty($widget['meta']['config']['slot']) ? $widget['meta']['config']['slot'] : null)
                    ?? (!empty($widget['meta']['slot']) ? $widget['meta']['slot'] : null)
                    ?? $area;
                
                if (!isset($slotWidgets[$slotId])) {
                    $slotWidgets[$slotId] = [];
                }

                $slotWidgets[$slotId][] = $widget;
                
                // 调试日志（开发模式）
                if (defined('DEV') && DEV) {
                    w_log_debug(sprintf(
                        '[SlotRenderer] Organized widget: code=%s, module=%s, slot_id=%s (from db: %s), area=%s',
                        $widget['widget_code'] ?? '',
                        $widget['widget_module'] ?? '',
                        $slotId,
                        $widget['slot_id'] ?? '(null)',
                        $area
                    ));
                }
            }
        }

        // 按排序值排序
        foreach ($slotWidgets as &$widgets) {
            usort($widgets, function ($a, $b) {
                return ($a['sort_order'] ?? 0) - ($b['sort_order'] ?? 0);
            });
        }

        return $slotWidgets;
    }

    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->widgetCache = [];
        $this->layoutCache = [];
        $this->orphanWidgets = [];
        $this->unavailableWidgets = [];
        self::$publishedLayoutDataCache = [];
        self::$widgetOutputCache = [];
        $this->purgeRuntimeCacheNamespace();
        self::$runtimeCache = null;
        self::$runtimeCacheResolved = false;
    }

    /** Clear process L1 arrays only; the shared runtime namespace remains intact. */
    public static function clearProcessMemoryCache(): void
    {
        self::$publishedLayoutDataCache = [];
        self::$widgetOutputCache = [];
    }

    public static function processCacheItemCount(): int
    {
        return count(self::$publishedLayoutDataCache) + count(self::$widgetOutputCache);
    }

    /** @param array{expires_at:float,html:string} $entry */
    private function rememberProcessWidgetOutput(string $cacheKey, array $entry): void
    {
        unset(self::$widgetOutputCache[$cacheKey]);
        while (count(self::$widgetOutputCache) >= self::MAX_WIDGET_OUTPUT_CACHE_ENTRIES) {
            $oldest = array_key_first(self::$widgetOutputCache);
            if ($oldest === null) {
                break;
            }
            unset(self::$widgetOutputCache[$oldest]);
        }
        self::$widgetOutputCache[$cacheKey] = $entry;
    }

    /**
     * 清理 WLS 跨请求共享的 theme_runtime 布局/部件输出缓存。
     * 发布主题后必须调用，否则 Worker 仍可能渲染旧版 slot 布局。
     */
    public function purgeRuntimeCacheNamespace(): void
    {
        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent()) {
            return;
        }

        try {
            $cache = self::runtimeCache();
            if ($cache !== null) {
                $cache->clearNamespace('theme_runtime');
                return;
            }

            // The optional Server module owns the concrete shared-state
            // implementation. Without a provider the process cache above is
            // still cleared and no cross-module class is loaded.
        } catch (\Throwable) {
            // 静默失败，不影响发布主流程
        }
    }

    /**
     * @param array{layout_option?:string,scope?:string,target_type?:string,target_id?:int} $identity
     */
    private function publishedLayoutStructureLogicalKey(
        int $themeId,
        string $pageType,
        string $area,
        array $identity,
    ): string {
        return 'pub_layout|'
            . ($area === 'backend' ? 'backend' : 'frontend') . '|'
            . $themeId . '|'
            . $pageType . '|'
            . \trim((string)($identity['layout_option'] ?? 'default')) . '|'
            . \trim((string)($identity['scope'] ?? '')) . '|'
            . \trim((string)($identity['target_type'] ?? 'global')) . '|'
            . (int)($identity['target_id'] ?? 0);
    }

    private function resolvePublishedLayoutHotCache(): ?StorefrontScopeHotCache
    {
        try {
            $resolved = ObjectManager::getInstance(StorefrontScopeHotCache::class);

            return $resolved instanceof StorefrontScopeHotCache ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Published layout / widget output stay process-local (L1) plus HotCache Policy.
     *
     * Historical theme_runtime SharedState get/set burns ~200ms each under pool
     * pressure — the same regression Partials already escaped. Hot-path reads
     * therefore never call wls.memory theme_runtime; publish still purges the
     * shared namespace via {@see purgeRuntimeCacheNamespace()}. Structure reuse
     * goes through {@see StorefrontThemeCacheCoordinator::publishedLayoutStructurePolicy()}.
     */
    private function runtimeCacheGet(string $key): mixed
    {
        unset($key);

        return null;
    }

    /**
     * @see runtimeCacheGet() — request hot path must not pay theme_runtime IPC.
     */
    private function runtimeCacheSet(string $key, mixed $value, int $ttl): void
    {
        unset($key, $value, $ttl);
    }

    private static function runtimeCache(): ?SharedCacheStateInterface
    {
        if (self::$runtimeCacheResolved) {
            return self::$runtimeCache;
        }
        self::$runtimeCacheResolved = true;

        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent()) {
            return null;
        }

        try {
            $cache = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(SharedCacheStateInterface::class);
            self::$runtimeCache = $cache instanceof SharedCacheStateInterface ? $cache : null;
        } catch (\Throwable) {
            self::$runtimeCache = null;
        }

        return self::$runtimeCache;
    }

    private function publishedLayoutCacheTtl(): int
    {
        return self::cachePolicy()->ttl('theme.slot_layout_ttl', (int)self::PUBLISHED_LAYOUT_CACHE_TTL);
    }

    private function widgetOutputCacheTtl(): int
    {
        return self::cachePolicy()->ttl('theme.widget_output_ttl', (int)self::WIDGET_OUTPUT_CACHE_TTL);
    }

    private static function cachePolicy(): RuntimeCachePolicy
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class);
    }

    private function traceCall(string $name, callable $callback, array $meta = []): mixed
    {
        if (!RequestLifecycleTrace::isEnabled()) {
            self::cooperativeRenderYield();
            try {
                return $callback();
            } finally {
                self::cooperativeRenderYield();
            }
        }

        self::cooperativeRenderYield();
        $start = microtime(true);
        RequestLifecycleTrace::pushCurrentParent($name);
        try {
            return $callback();
        } finally {
            RequestLifecycleTrace::popCurrentParent();
            RequestLifecycleTrace::recordSpan($name, (microtime(true) - $start) * 1000, 'theme', null, $meta);
            self::cooperativeRenderYield();
        }
    }

    private static function cooperativeRenderYield(): void
    {
        if (!Runtime::isPersistent() || !SchedulerSystem::isSchedulerActive()) {
            return;
        }

        $fiber = \Fiber::getCurrent();
        if (!$fiber instanceof \Fiber) {
            return;
        }

        $now = \microtime(true);
        self::$fiberRenderYieldAt ??= new \WeakMap();
        $lastYieldAt = (float)(self::$fiberRenderYieldAt[$fiber] ?? 0.0);
        if ($lastYieldAt <= 0.0) {
            self::$fiberRenderYieldAt[$fiber] = $now;
            return;
        }
        if ($lastYieldAt > 0.0 && (($now - $lastYieldAt) * 1000000) < self::WLS_RENDER_YIELD_MIN_INTERVAL_US) {
            return;
        }

        // Match Template::cooperativeTemplateYield: drain the process-level fiber
        // output handler into *this* capture frame before suspending. Otherwise a
        // peer fiber's flush attributes our pending chunks to the wrong stack
        // (or discards them), truncating widgets mid-HTML → Unclosed tag toasts
        // and cascade layout breakage until a clean re-render.
        if (!\Weline\Framework\Runtime\FiberOutputBuffer::flushBeforeYield()) {
            return;
        }

        self::$fiberRenderYieldAt[$fiber] = $now;
        RequestLifecycleTrace::measurePhase(
            'theme.slots.cooperative_yield',
            static fn() => SchedulerSystem::yield()
        );
    }
    
    /**
     * 提取模板中的所有可用插槽信息
     * 
     * 用于后台编辑器显示可用的插槽位置
     * 
     * @param string $html 模板HTML内容
     * @return array 插槽信息列表
     */
    public function extractSlots(string $html): array
    {
        $slots = [];
        
        // 避免 DOM 解析器的警告
        libxml_use_internal_errors(true);
        
        $doc = new \DOMDocument();
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        $doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        $xpath = new \DOMXPath($doc);
        
        // 查找所有带 data-wslot 属性的元素
        $slotElements = $xpath->query("//*[@data-wslot]");
        foreach ($slotElements as $element) {
            if ($element instanceof \DOMElement) {
                $slotId = $element->getAttribute('data-wslot');
                if (!$slotId) {
                    continue;
                }
                
                $slots[$slotId] = [
                    'id' => $slotId,
                    'name' => $element->getAttribute('data-wslot-name') ?: $slotId,
                    'accept' => array_filter(explode(',', $element->getAttribute('data-wslot-accept') ?: '')),
                    'reject' => array_filter(explode(',', $element->getAttribute('data-wslot-reject') ?: '')),
                    'exclusive' => $element->getAttribute('data-wslot-exclusive') === 'true',
                    'append' => $element->getAttribute('data-wslot-append') === 'true',
                    'prepend' => $element->getAttribute('data-wslot-prepend') === 'true',
                    'multiple' => $element->getAttribute('data-wslot-multiple') === 'true',
                    'position' => $element->getAttribute('data-wslot-position') ?: null,
                    'max' => $element->getAttribute('data-wslot-max') ?: null,
                    'min' => $element->getAttribute('data-wslot-min') ?: null,
                    'required' => $element->getAttribute('data-wslot-required') === 'true',
                ];
            }
        }
        
        // 兼容旧方式
        $oldSlotElements = $xpath->query("//*[contains(@class, 'widget-slot-area')]");
        foreach ($oldSlotElements as $element) {
            if ($element instanceof \DOMElement) {
                $slotId = $element->getAttribute('data-slot-id');
                if (!$slotId || isset($slots[$slotId])) {
                    continue;
                }
                
                $slots[$slotId] = [
                    'id' => $slotId,
                    'name' => $element->getAttribute('data-slot-name') ?: $slotId,
                    'accept' => [],
                    'reject' => [],
                    'exclusive' => false,
                    'append' => false,
                    'prepend' => false,
                    'multiple' => true,
                    'position' => null,
                    'max' => null,
                    'min' => null,
                    'required' => false,
                    'legacy' => true, // 标记为旧方式
                ];
            }
        }
        
        libxml_clear_errors();
        
        return $slots;
    }
    private function resolvePlaceableRegistry(mixed $placeableRegistry): ThemePlaceableRegistryInterface
    {
        if ($placeableRegistry instanceof ThemePlaceableRegistryInterface) {
            return $placeableRegistry;
        }

        return ObjectManager::getInstance(ThemePlaceableRegistry::class);
    }
}
