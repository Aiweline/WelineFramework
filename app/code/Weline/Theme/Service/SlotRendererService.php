<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\View\Template;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Theme\Interface\ThemePlaceableRegistryInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Widget\Service\WidgetRegistry;

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
    private WidgetRegistry $widgetRegistry;
    private ThemePlaceableRegistryInterface $placeableRegistry;
    private ThemeComponentRenderer $componentRenderer;
    private Template $template;

    // 缓存
    private array $widgetCache = [];
    private array $layoutCache = [];
    private const PUBLISHED_LAYOUT_CACHE_TTL = 120.0;
    private const WIDGET_OUTPUT_CACHE_TTL = 120.0;
    private const CACHEABLE_WIDGET_OUTPUTS = [
        'Weline_Theme::bestsellers' => true,
        'Weline_Theme::related-products' => true,
        'Weline_Theme::recently-viewed' => true,
    ];
    private static array $publishedLayoutDataCache = [];
    private static array $widgetOutputCache = [];
    private static ?MemoryStateFacade $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;
    
    // 孤儿部件（找不到对应slot的部件）
    private array $orphanWidgets = [];

    /** 当前渲染周期内已填充的 slot_id，同一 slot_id 只填充文档中第一处出现，避免容器部件内层同名插槽被重复填充导致泄露 */
    private array $filledSlotIdsThisRun = [];

    public function __construct(
        ThemeLayoutService $layoutService,
        WidgetRegistry $widgetRegistry,
        mixed $placeableRegistry,
        ThemeComponentRenderer $componentRenderer,
        Template $template
    ) {
        $this->layoutService = $layoutService;
        $this->widgetRegistry = $widgetRegistry;
        $this->placeableRegistry = $this->resolvePlaceableRegistry($placeableRegistry);
        $this->componentRenderer = $componentRenderer;
        $this->template = $template;
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
    public function processSlots(string $html, int $themeId, string $pageType, string $status = ThemeLayout::STATUS_PUBLISHED): string
    {
        return $this->traceCall(
            'slot_renderer::processSlots',
            fn() => $this->doProcessSlots($html, $themeId, $pageType, $status)
        );
    }

    private function doProcessSlots(string $html, int $themeId, string $pageType, string $status = ThemeLayout::STATUS_PUBLISHED): string
    {
        // 检查是否包含插槽标记（支持新旧两种方式）
        if (strpos($html, 'data-wslot') === false && strpos($html, 'widget-slot-area') === false) {
            return $html;
        }

        // 获取该主题和页面类型的布局配置
        $layoutData = $this->traceCall(
            'slot_renderer::getLayoutData',
            fn() => $this->getLayoutData($themeId, $pageType, $status)
        );

        // 按插槽 ID 组织部件
        $slotWidgets = $this->traceCall(
            'slot_renderer::organizeWidgetsBySlot',
            fn() => $this->organizeWidgetsBySlot($layoutData)
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

        if (empty($slotWidgets)) {
            return $html;
        }

        // Use DOM only when there are widgets that can change slot output.
        $this->filledSlotIdsThisRun = [];
        $html = $this->traceCall(
            'slot_renderer::processSlotsWithDom',
            fn() => $this->processSlotsWithDom($html, $slotWidgets, $status === ThemeLayout::STATUS_PUBLISHED)
        );

        return $html;
    }

    /**
     * 处理草稿模式的插槽（后台预览用）
     */
    private function filterWidgetsForHtmlSlots(array $slotWidgets, string $html): array
    {
        if ($slotWidgets === []) {
            return [];
        }

        $slotIds = $this->extractSlotIdsFromHtml($html);
        if ($slotIds === []) {
            return [];
        }

        return \array_intersect_key($slotWidgets, $slotIds);
    }

    /**
     * @return array<string, true>
     */
    private function extractSlotIdsFromHtml(string $html): array
    {
        $slotIds = [];

        if (\preg_match_all('/\bdata-wslot\s*=\s*(["\'])(.*?)\1/is', $html, $matches)) {
            foreach ($matches[2] as $slotId) {
                $slotId = \trim((string)$slotId);
                if ($slotId !== '') {
                    $slotIds[$slotId] = true;
                }
            }
        }

        if (\preg_match_all('/\bdata-slot-id\s*=\s*(["\'])(.*?)\1/is', $html, $matches)) {
            foreach ($matches[2] as $slotId) {
                $slotId = \trim((string)$slotId);
                if ($slotId !== '') {
                    $slotIds[$slotId] = true;
                }
            }
        }

        return $slotIds;
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
     * 使用 DOM 解析处理插槽（支持嵌套：部件输出的 HTML 可包含子插槽，会被迭代填充）
     */
    private function processSlotsWithDom(string $html, array $slotWidgets, bool $allowNarrowFragment = false): string
    {
        $bodyParts = $this->splitHtmlBody($html);
        if ($bodyParts !== null) {
            $bodyParts['body'] = $this->processSlotFragmentWithDom($bodyParts['body'], $slotWidgets, $allowNarrowFragment);
            return $bodyParts['before'] . $bodyParts['body'] . $bodyParts['after'];
        }

        return $this->processSlotFragmentWithDom($html, $slotWidgets, $allowNarrowFragment);
    }

    /**
     * Keep document-level SEO/head markup out of the slot DOM pass.
     */
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
     * Process only the slot-bearing HTML fragment so DOMDocument cannot move
     * invalid head children into body and break SEO output.
     */
    private function processSlotFragmentWithDom(string $html, array $slotWidgets, bool $allowNarrowFragment = false): string
    {
        // 避免 DOM 解析器的警告
        if ($allowNarrowFragment) {
            $narrowed = $this->narrowHtmlToSlotFragment($html, \array_keys($slotWidgets));
            if ($narrowed !== null) {
                $narrowed['fragment'] = $this->processSlotFragmentWithDom($narrowed['fragment'], $slotWidgets, false);
                return $narrowed['before'] . $narrowed['fragment'] . $narrowed['after'];
            }
        }

        libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        // 添加 UTF-8 声明避免编码问题
        $wrappedHtml = '<?xml encoding="UTF-8"><div data-weline-slot-root="1">' . $html . '</div>';
        $doc->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // 收集模板中存在的所有 slot ID
        $existingSlotIds = [];

        // ── 迭代处理嵌套插槽 ──
        // 父部件渲染后可能产生新的 [data-wslot] 子插槽，需要多轮处理
        $maxDepth = 10; // 最大嵌套深度，防止死循环
        for ($depth = 0; $depth < $maxDepth; $depth++) {
            $xpath = new \DOMXPath($doc); // 每轮重建 XPath（DOM 已被修改）

            // 查找所有 未处理 的 [data-wslot] 元素
            $slots = $xpath->query("//*[@data-wslot and not(@data-wslot-processed)]");
            
            // 兼容旧方式：查找 widget-slot-area 类（仅第一轮）
            if ($depth === 0) {
                $oldSlots = $xpath->query("//*[contains(@class, 'widget-slot-area') and not(@data-wslot)]");
                foreach ($oldSlots as $slot) {
                    if ($slot instanceof \DOMElement) {
                        $slotId = $slot->getAttribute('data-slot-id');
                        if ($slotId) {
                            $existingSlotIds[$slotId] = true;
                            $slot->setAttribute('data-wslot', $slotId);
                            // 不标记 processed，让下面的循环处理
                        }
                    }
                }
                // 重新查询（包含刚转换的旧插槽）
                $xpath = new \DOMXPath($doc);
                $slots = $xpath->query("//*[@data-wslot and not(@data-wslot-processed)]");
            }

            if ($slots->length === 0) {
                break; // 没有未处理的插槽了
            }

            foreach ($slots as $slot) {
                if ($slot instanceof \DOMElement) {
                    $slotId = $slot->getAttribute('data-wslot');
                    if ($slotId) {
                        $existingSlotIds[$slotId] = true;
                    }
                    // 标记为已处理，避免下一轮重复处理
                    $slot->setAttribute('data-wslot-processed', 'true');
                    $this->processSlotElement($slot, $slotWidgets, $doc);
                }
            }
        }

        // 检测孤儿部件（配置了但找不到对应slot的部件）
        $this->detectOrphanWidgets($slotWidgets, $existingSlotIds);
        
        // 清理辅助属性 data-wslot-processed（不输出到最终 HTML）
        $xpath = new \DOMXPath($doc);
        $processedSlots = $xpath->query("//*[@data-wslot-processed]");
        foreach ($processedSlots as $slot) {
            if ($slot instanceof \DOMElement) {
                $slot->removeAttribute('data-wslot-processed');
            }
        }

        // 获取处理后的 HTML
        $result = '';
        $root = $doc->documentElement;
        if ($root instanceof \DOMElement && $root->getAttribute('data-weline-slot-root') === '1') {
            foreach ($root->childNodes as $child) {
                $result .= $doc->saveHTML($child);
            }
        } else {
            $result = $doc->saveHTML();
        }

        libxml_clear_errors();

        // 移除 XML 声明
        $result = preg_replace('/<\?xml encoding="UTF-8"\?>/', '', (string)$result);

        return (string)$result;
    }

    /**
     * @param list<string> $slotIds
     * @return array{before: string, fragment: string, after: string}|null
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

        return [
            'before' => \substr($html, 0, $minStart),
            'fragment' => \substr($html, $minStart, $maxEnd - $minStart),
            'after' => \substr($html, $maxEnd),
        ];
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
     * 这些部件不会被删除，只是无法在当前布局中显示
     */
    private function detectOrphanWidgets(array $slotWidgets, array $existingSlotIds): void
    {
        $this->orphanWidgets = [];
        
        foreach ($slotWidgets as $slotId => $widgets) {
            // 如果这个 slot ID 在模板中不存在，标记其所有部件为孤儿
            if (!isset($existingSlotIds[$slotId])) {
                foreach ($widgets as $widget) {
                    $this->orphanWidgets[] = [
                        'slot_id' => $slotId,
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
     * 处理单个插槽元素
     * 同一 slot_id 在整棵 DOM 中只填充第一处出现，避免容器部件（如 content-container）
     * 放入 hero 后，其内部输出的同名 widget-hero 被再次填充导致布局泄露或重复。
     */
    private function processSlotElement(\DOMElement $slot, array $slotWidgets, \DOMDocument $doc): void
    {
        $slotId = $slot->getAttribute('data-wslot');
        if (!$slotId) {
            return;
        }

        // 该 slot_id 已在本轮被占用（文档中第一处），跳过后续同名插槽
        if (isset($this->filledSlotIdsThisRun[$slotId])) {
            return;
        }
        $this->filledSlotIdsThisRun[$slotId] = true;

        // 获取插槽配置
        $isExclusive = $slot->getAttribute('data-wslot-exclusive') === 'true';
        $isAppend = $slot->getAttribute('data-wslot-append') === 'true';
        $isPrepend = $slot->getAttribute('data-wslot-prepend') === 'true';

        // 检查该插槽是否有配置的部件
        if (!isset($slotWidgets[$slotId]) || empty($slotWidgets[$slotId])) {
            // 没有部件配置，保留原有内容（包括占位符）
            // 不再移除占位符，让用户能看到插槽区域
            return;
        }

        // 渲染该插槽的所有部件
        $widgetsHtml = $this->traceCall(
            'slot_renderer::renderSlotWidgets::' . substr($slotId, 0, 80),
            fn() => $this->renderSlotWidgets($slotWidgets[$slotId]),
            [
                'slot_id' => $slotId,
                'widgets' => \count($slotWidgets[$slotId]),
            ]
        );
        if (empty($widgetsHtml)) {
            return;
        }

        // 创建新的内容片段
        $fragment = $doc->createDocumentFragment();
        // 使用临时容器来解析 HTML
        $tempDoc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $tempDoc->loadHTML('<?xml encoding="utf-8"?><div>' . $widgetsHtml . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        // 导入节点
        $tempBody = $tempDoc->getElementsByTagName('div')->item(0);
        if ($tempBody) {
            foreach ($tempBody->childNodes as $child) {
                $imported = $doc->importNode($child, true);
                $fragment->appendChild($imported);
            }
        }

        // 根据配置决定如何插入内容
        //
        // 策略：
        //  - exclusive / 默认（无 append/prepend）：清空默认内容 → 替换为部件
        //  - prepend="true"：保留默认内容，部件插到前面
        //  - append="true"：保留默认内容，部件追加到后面
        //  - footer/header 整块区域插槽：强制追加，避免清空导致整块变白
        //
        // 原则：插槽的默认 HTML 只是"没有部件时的回退内容"，
        //       一旦有部件被分配给该插槽，默认内容就应该被替换掉。
        //       只有显式声明 append/prepend 的插槽才会同时保留默认内容和部件。
        $isAreaContainerSlot = $slotId === 'footer' || $slotId === 'header';
        if ($isPrepend) {
            // 前置模式：保留默认内容，部件插到前面
            if ($slot->firstChild) {
                $slot->insertBefore($fragment, $slot->firstChild);
            } else {
                $slot->appendChild($fragment);
            }
        } elseif ($isAppend || $isAreaContainerSlot) {
            // 追加模式（显式声明 或 整块区域插槽）：保留默认内容，部件追加到后面
            // footer/header 整块标记了 data-wslot 时，不能清空整块，否则整页预览会整块变白
            $this->removePlaceholderContent($slot);
            $slot->appendChild($fragment);
        } else {
            // 替换模式（exclusive 和 默认都走这里）：清空默认内容 → 插入部件
            while ($slot->firstChild) {
                $slot->removeChild($slot->firstChild);
            }
            $slot->appendChild($fragment);
        }
    }

    /**
     * 移除插槽内的占位符内容
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
        $html = '';

        foreach ($widgets as $widget) {
            $widgetHtml = $this->renderWidget($widget);
            if ($widgetHtml) {
                $html .= $widgetHtml;
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
            fn() => $this->doRenderWidget($widget),
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
        $widgetModule = $widget['widget_module'] ?? '';
        $widgetCode = $widget['widget_code'] ?? '';
        $widgetType = $widget['widget_type'] ?? '';
        $layoutId = $widget['layout_id'] ?? '';
        $config = $widget['config'] ?? [];
        $widgetOutputCacheKey = $this->buildWidgetOutputCacheKey($widget, \is_array($config) ? $config : []);
        if ($widgetOutputCacheKey !== null) {
            $cachedWidget = self::$widgetOutputCache[$widgetOutputCacheKey] ?? null;
            if (\is_array($cachedWidget)
                && isset($cachedWidget['expires_at'], $cachedWidget['html'])
                && (float)$cachedWidget['expires_at'] >= \microtime(true)
                && \is_string($cachedWidget['html'])) {
                return $cachedWidget['html'];
            }
            $runtimeCachedWidget = $this->runtimeCacheGet($widgetOutputCacheKey);
            if (\is_string($runtimeCachedWidget)) {
                self::$widgetOutputCache[$widgetOutputCacheKey] = [
                    'expires_at' => \microtime(true) + $this->widgetOutputCacheTtl(),
                    'html' => $runtimeCachedWidget,
                ];
                return $runtimeCachedWidget;
            }
        }

        // 检查缓存
        $definition = $this->placeableRegistry->find($widgetModule, $widgetType, $widgetCode, null, 'frontend');
        if ($definition) {
            try {
                $renderConfig = is_array($config) ? $config : [];
                $renderConfig['_widget_instance_key'] = $this->widgetInstanceKey($widget, $renderConfig);
                $html = $this->componentRenderer->render($definition, $renderConfig, null, [
                    'area' => 'frontend',
                    'preview_mode' => false,
                ]);
                if ($layoutId) {
                    $wrapperAttrs = sprintf(
                        'data-layout-id="%s" data-widget-code="%s" data-widget-module="%s" data-widget-type="%s"',
                        htmlspecialchars((string)$layoutId),
                        htmlspecialchars((string)$widgetCode),
                        htmlspecialchars((string)$widgetModule),
                        htmlspecialchars((string)$widgetType)
                    );
                    $widgetName = htmlspecialchars((string)($definition->name ?: $widgetCode));
                    $html = sprintf(
                        '<div class="widget-wrapper" %s data-widget-name="%s">%s</div>',
                        $wrapperAttrs,
                        $widgetName,
                        $html
                    );
                }

                return $this->rememberWidgetOutput($widgetOutputCacheKey, $html);
            } catch (\Throwable $throwable) {
                if (defined('DEV') && DEV) {
                    return sprintf(
                        '<div class="widget-render-error" style="color:red;padding:10px;border:1px solid red;">%s</div>',
                        htmlspecialchars((string)$throwable->getMessage())
                    );
                }
            }
        }

        $cacheKey = $widgetModule . '::' . $widgetCode;
        if (!isset($this->widgetCache[$cacheKey])) {
            $this->widgetCache[$cacheKey] = $this->getWidgetMeta($widgetModule, $widgetCode);
        }

        $widgetMeta = $this->widgetCache[$cacheKey];
        if (!$widgetMeta) {
            return '';
        }

        $templatePath = $widgetMeta['template'] ?? '';
        if (!$templatePath) {
            return '';
        }

        // 合并默认配置
        $defaultConfig = [];
        foreach ($widgetMeta['params'] ?? [] as $key => $param) {
            $defaultConfig[$key] = $param['default'] ?? '';
        }
        $finalConfig = array_merge($defaultConfig, $config);
        $finalConfig['_widget_instance_key'] = $this->widgetInstanceKey($widget, $finalConfig);

        try {
            // 渲染部件模板 - 使用 fetch() 方法，它接受2个参数：fileName 和 data
            $html = $this->template->fetch($templatePath, $finalConfig);
            $html = is_string($html) ? $html : '';

            // 为编辑器模式包装部件，添加识别属性
            // 这样编辑器可以识别部件并进行配置
            if ($layoutId) {
                $wrapperAttrs = sprintf(
                    'data-layout-id="%s" data-widget-code="%s" data-widget-module="%s" data-widget-type="%s"',
                    htmlspecialchars((string)$layoutId),
                    htmlspecialchars((string)$widgetCode),
                    htmlspecialchars((string)$widgetModule),
                    htmlspecialchars((string)$widgetType)
                );
                $widgetName = htmlspecialchars((string)($widgetMeta['name'] ?? $widgetCode));
                $html = sprintf(
                    '<div class="widget-wrapper" %s data-widget-name="%s">%s</div>',
                    $wrapperAttrs,
                    $widgetName,
                    $html
                );
            }

            return $this->rememberWidgetOutput($widgetOutputCacheKey, $html);
        } catch (\Exception $e) {
            // 渲染失败，返回错误提示（仅开发模式）
            if (defined('DEV') && DEV) {
                return sprintf(
                    '<div class="widget-render-error" style="color:red;padding:10px;border:1px solid red;">部件渲染失败: %s - %s</div>',
                    htmlspecialchars((string)$widgetCode),
                    htmlspecialchars((string)$e->getMessage())
                );
            }
            return '';
        }
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
            $context = [
                'identity' => $identity,
                'layout_id' => (string)($widget['layout_id'] ?? ''),
                'slot_id' => (string)($widget['slot_id'] ?? ''),
                'type' => (string)($widget['widget_type'] ?? ''),
                'config' => $config,
                'lang' => (string)State::getLang(),
                'lang_local' => (string)State::getLangLocal(),
                'currency' => (string)State::getCurrency(),
                'base_url' => (string)$this->template->getRequest()->getBaseUrl(),
                'path' => (string)$this->template->getRequest()->getPathInfo(),
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

        if (\count(self::$widgetOutputCache) > 128) {
            self::$widgetOutputCache = [];
        }
        self::$widgetOutputCache[$cacheKey] = [
            'expires_at' => \microtime(true) + $this->widgetOutputCacheTtl(),
            'html' => $html,
        ];
        $this->runtimeCacheSet($cacheKey, $html, $this->widgetOutputCacheTtl());

        return $html;
    }

    private function getWidgetMeta(string $module, string $code): ?array
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
    private function getLayoutData(int $themeId, string $pageType, string $status = ThemeLayout::STATUS_PUBLISHED): array
    {
        $cacheKey = "{$themeId}:{$pageType}:{$status}";
        $isDraft = ($status === ThemeLayout::STATUS_DRAFT);

        // 草稿不读缓存，保证编辑器/预览每次请求都拿到最新布局（删除、拖拽后立即生效）
        if (!$isDraft && isset($this->layoutCache[$cacheKey])) {
            return $this->layoutCache[$cacheKey];
        }
        if (!$isDraft) {
            $cached = self::$publishedLayoutDataCache[$cacheKey] ?? null;
            if (\is_array($cached)
                && isset($cached['expires_at'], $cached['data'])
                && (float)$cached['expires_at'] >= \microtime(true)
                && \is_array($cached['data'])) {
                $this->layoutCache[$cacheKey] = $cached['data'];
                return $cached['data'];
            }
            $runtimeCachedLayout = $this->runtimeCacheGet('layout.data.' . $cacheKey);
            if (\is_array($runtimeCachedLayout)) {
                $this->layoutCache[$cacheKey] = $runtimeCachedLayout;
                self::$publishedLayoutDataCache[$cacheKey] = [
                    'expires_at' => \microtime(true) + $this->publishedLayoutCacheTtl(),
                    'data' => $runtimeCachedLayout,
                ];
                return $runtimeCachedLayout;
            }
        }

        // 1. 按指定状态获取数据
        $layout = $this->layoutService->getFullLayout($themeId, $pageType, $status);
        
        // 2. 检查是否有部件数据
        $hasWidgets = $this->hasWidgetsInLayout($layout);
        
        // 3. 如果没有数据且是已发布状态，尝试降级到草稿数据
        if (!$hasWidgets && $status === ThemeLayout::STATUS_PUBLISHED) {
            $draftLayout = $this->layoutService->getFullLayout($themeId, $pageType, ThemeLayout::STATUS_DRAFT);
            if ($this->hasWidgetsInLayout($draftLayout)) {
                // 使用草稿数据，并自动发布（后台静默发布）
                $this->autoPublishDraft($themeId, $pageType, $draftLayout);
                $layout = $draftLayout;
                $hasWidgets = true;
            }
        }
        
        // 3.5. 如果仍然没有数据，尝试生成默认布局种子
        // 注意：仅在前端访问（published状态）时自动seed，编辑器模式（draft状态）不自动seed
        // 这样可以尊重用户在编辑器中删除部件的操作，避免被删除的部件自动重新创建
        if (!$hasWidgets && $status === ThemeLayout::STATUS_PUBLISHED) {
            $seeded = $this->seedDefaultLayoutIfNeeded($themeId, $pageType);
            if ($seeded) {
                // 重新获取布局数据
                $layout = $this->layoutService->getFullLayout($themeId, $pageType, ThemeLayout::STATUS_DRAFT);
                if ($this->hasWidgetsInLayout($layout)) {
                    $hasWidgets = true;
                    // 如果是前端请求已发布状态，自动发布
                    if ($status === ThemeLayout::STATUS_PUBLISHED) {
                        $this->autoPublishDraft($themeId, $pageType, $layout);
                    }
                }
            }
        }
        
        // 4. 如果当前页面类型没有数据，尝试获取默认页面类型的数据
        if (!$hasWidgets && $pageType !== ThemeLayout::PAGE_TYPE_DEFAULT) {
            $defaultLayout = $this->layoutService->getFullLayout($themeId, ThemeLayout::PAGE_TYPE_DEFAULT, $status);
            if ($this->hasWidgetsInLayout($defaultLayout)) {
                $layout = $defaultLayout;
            } else if ($status === ThemeLayout::STATUS_PUBLISHED) {
                // 尝试默认页面类型的草稿
                $defaultDraftLayout = $this->layoutService->getFullLayout($themeId, ThemeLayout::PAGE_TYPE_DEFAULT, ThemeLayout::STATUS_DRAFT);
                if ($this->hasWidgetsInLayout($defaultDraftLayout)) {
                    $this->autoPublishDraft($themeId, ThemeLayout::PAGE_TYPE_DEFAULT, $defaultDraftLayout);
                    $layout = $defaultDraftLayout;
                }
            }
        }

        // 仅已发布状态写入缓存；草稿不缓存
        if (!$isDraft) {
            $this->layoutCache[$cacheKey] = $layout;
            self::$publishedLayoutDataCache[$cacheKey] = [
                'expires_at' => \microtime(true) + $this->publishedLayoutCacheTtl(),
                'data' => $layout,
            ];
            $this->runtimeCacheSet('layout.data.' . $cacheKey, $layout, $this->publishedLayoutCacheTtl());
        }

        return $layout;
    }
    
    /**
     * 如果需要，生成默认布局种子
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @return bool 是否生成了新的布局
     */
    private function seedDefaultLayoutIfNeeded(int $themeId, string $pageType): bool
    {
        try {
            /** @var DefaultLayoutSeeder $seeder */
            $seeder = ObjectManager::getInstance(DefaultLayoutSeeder::class);
            return $seeder->seedDefaultLayout($themeId, $pageType, false);
        } catch (\Exception $e) {
            // 静默失败，可能是 seeder 服务不可用
            return false;
        }
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
     * 自动发布草稿数据（前端访问时的静默发布）
     * 
     * 当前端访问发现没有已发布数据但有草稿数据时，自动将草稿发布
     */
    private function autoPublishDraft(int $themeId, string $pageType, array $draftLayout): void
    {
        try {
            // 先获取一次已发布数据（避免循环内 N+1 查询）
            $existingPublished = $this->layoutService->getLayout($themeId, $pageType, ThemeLayout::STATUS_PUBLISHED);
            
            // 静默发布：将草稿数据复制到已发布状态
            foreach ($draftLayout as $area => $areaData) {
                foreach ($areaData['widgets'] ?? [] as $widget) {
                    // 去重：按 widget_code + widget_module + slot_id 三重匹配
                    $alreadyPublished = false;
                    $widgetSlotId = $widget['slot_id'] ?? '';
                    foreach ($existingPublished[$area]['widgets'] ?? [] as $existingWidget) {
                        if ($existingWidget['widget_code'] === $widget['widget_code'] 
                            && $existingWidget['widget_module'] === $widget['widget_module']
                            && ($existingWidget['slot_id'] ?? '') === $widgetSlotId) {
                            $alreadyPublished = true;
                            break;
                        }
                    }
                    
                    if (!$alreadyPublished) {
                        $this->layoutService->saveWidget([
                            'theme_id' => $themeId,
                            'page_type' => $pageType,
                            'area' => $area,
                            'widget_code' => $widget['widget_code'],
                            'widget_module' => $widget['widget_module'],
                            'widget_type' => $widget['widget_type'] ?? '',
                            'slot_id' => $widget['slot_id'] ?? null,
                            'config' => $widget['config'] ?? [],
                            'sort_order' => $widget['sort_order'] ?? 0,
                            'is_active' => true,
                            'status' => ThemeLayout::STATUS_PUBLISHED,
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            // 静默失败，不影响前端渲染
        }
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
        self::$publishedLayoutDataCache = [];
        self::$widgetOutputCache = [];
        self::$runtimeCache = null;
        self::$runtimeCacheResolved = false;
    }

    private function runtimeCacheGet(string $key): mixed
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return null;
        }

        try {
            return $cache->get('theme_runtime', $key);
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
            return null;
        }
    }

    private function runtimeCacheSet(string $key, mixed $value, int $ttl): void
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->set('theme_runtime', $key, $value, \max(1, $ttl));
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
    }

    private static function runtimeCache(): ?MemoryStateFacade
    {
        if (self::$runtimeCacheResolved) {
            return self::$runtimeCache;
        }
        self::$runtimeCacheResolved = true;

        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent() || !\class_exists(MemoryStateFacade::class)) {
            return null;
        }

        try {
            self::$runtimeCache = new MemoryStateFacade(self::cachePolicy()->memoryOptions([
                'consumer_code' => 'theme_runtime_slot',
                'prefer_direct_connect' => true,
                'pool_size' => 1,
                'auto_start' => false,
            ]));
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
            return $callback();
        }

        $start = microtime(true);
        RequestLifecycleTrace::pushCurrentParent($name);
        try {
            return $callback();
        } finally {
            RequestLifecycleTrace::popCurrentParent();
            RequestLifecycleTrace::recordSpan($name, (microtime(true) - $start) * 1000, 'theme', null, $meta);
        }
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
                    'exclusive' => $element->getAttribute('data-wslot-exclusive') === 'true',
                    'append' => $element->getAttribute('data-wslot-append') === 'true',
                    'prepend' => $element->getAttribute('data-wslot-prepend') === 'true',
                    'multiple' => $element->getAttribute('data-wslot-multiple') === 'true',
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
                    'exclusive' => false,
                    'append' => false,
                    'prepend' => false,
                    'multiple' => true,
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
