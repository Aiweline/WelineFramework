<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
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
    private Template $template;

    // 缓存
    private array $widgetCache = [];
    private array $layoutCache = [];
    
    // 孤儿部件（找不到对应slot的部件）
    private array $orphanWidgets = [];

    /** 当前渲染周期内已填充的 slot_id，同一 slot_id 只填充文档中第一处出现，避免容器部件内层同名插槽被重复填充导致泄露 */
    private array $filledSlotIdsThisRun = [];

    public function __construct(
        ThemeLayoutService $layoutService,
        WidgetRegistry $widgetRegistry,
        Template $template
    ) {
        $this->layoutService = $layoutService;
        $this->widgetRegistry = $widgetRegistry;
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
        // 检查是否包含插槽标记（支持新旧两种方式）
        if (strpos($html, 'data-wslot') === false && strpos($html, 'widget-slot-area') === false) {
            return $html;
        }

        // 获取该主题和页面类型的布局配置
        $layoutData = $this->getLayoutData($themeId, $pageType, $status);

        // 按插槽 ID 组织部件
        $slotWidgets = $this->organizeWidgetsBySlot($layoutData);
        
        // 调试日志（开发模式）
        if (defined('DEV') && DEV) {
            $widgetCount = 0;
            foreach ($slotWidgets as $slotId => $widgets) {
                $widgetCount += count($widgets);
            }
            error_log(sprintf(
                '[SlotRenderer] processSlots: themeId=%d, pageType=%s, status=%s, slots=%d, widgets=%d',
                $themeId,
                $pageType,
                $status,
                count($slotWidgets),
                $widgetCount
            ));
            foreach ($slotWidgets as $slotId => $widgets) {
                error_log(sprintf('[SlotRenderer]   Slot "%s": %d widgets', $slotId, count($widgets)));
            }
        }

        // 使用 DOM 解析处理插槽（更可靠）
        $this->filledSlotIdsThisRun = [];
        $html = $this->processSlotsWithDom($html, $slotWidgets);

        return $html;
    }

    /**
     * 处理草稿模式的插槽（后台预览用）
     */
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
    private function processSlotsWithDom(string $html, array $slotWidgets): string
    {
        // 避免 DOM 解析器的警告
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        // 添加 UTF-8 声明避免编码问题
        $html = '<?xml encoding="UTF-8">' . $html;
        $doc->loadHTML($html);

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
        $result = $doc->saveHTML();

        libxml_clear_errors();

        // 移除 XML 声明
        $result = preg_replace('/<\?xml encoding="UTF-8"\?>/', '', $result);

        return $result;
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
        $widgetsHtml = $this->renderSlotWidgets($slotWidgets[$slotId]);
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
        $widgetModule = $widget['widget_module'] ?? '';
        $widgetCode = $widget['widget_code'] ?? '';
        $widgetType = $widget['widget_type'] ?? '';
        $layoutId = $widget['layout_id'] ?? '';
        $config = $widget['config'] ?? [];

        // 检查缓存
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

            return $html;
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
     * 优先级：
     * 1. 按指定状态获取数据
     * 2. 如果是已发布状态且没有数据，尝试获取草稿数据
     * 3. 如果仍然没有数据，尝试生成默认布局种子
     * 4. 如果当前页面类型没有数据，尝试获取默认页面类型的数据
     */
    private function getLayoutData(int $themeId, string $pageType, string $status = ThemeLayout::STATUS_PUBLISHED): array
    {
        $cacheKey = "{$themeId}:{$pageType}:{$status}";

        if (!isset($this->layoutCache[$cacheKey])) {
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
            
            $this->layoutCache[$cacheKey] = $layout;
        }

        return $this->layoutCache[$cacheKey];
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
                    error_log(sprintf(
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
}
