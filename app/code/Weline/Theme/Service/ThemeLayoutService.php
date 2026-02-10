<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Widget\Service\WidgetRegistry;

/**
 * 主题布局服务
 * 管理主题的部件布局配置
 */
class ThemeLayoutService
{
    private ThemeLayout $themeLayout;
    private WelineTheme $welineTheme;
    private WidgetRegistry $widgetRegistry;

    public function __construct(
        ThemeLayout $themeLayout,
        WelineTheme $welineTheme,
        WidgetRegistry $widgetRegistry
    ) {
        $this->themeLayout = $themeLayout;
        $this->welineTheme = $welineTheme;
        $this->widgetRegistry = $widgetRegistry;
    }

    /**
     * 获取主题布局配置
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param string $status 状态：draft=草稿，published=已发布（默认读取已发布）
     * @return array 按区域分组的部件配置
     */
    public function getLayout(int $themeId, string $pageType = ThemeLayout::PAGE_TYPE_DEFAULT, string $status = ThemeLayout::STATUS_PUBLISHED): array
    {
        // 按区域分组
        $groupedLayout = [];
        foreach (ThemeLayout::getAreas() as $areaCode => $areaLabel) {
            $groupedLayout[$areaCode] = [
                'label' => $areaLabel,
                'widgets' => [],
            ];
        }

        try {
            // 使用 fetchArray() 获取原始数组数据，避免返回对象导致的问题
            $layouts = $this->themeLayout->reset()
                ->where(ThemeLayout::fields_THEME_ID, $themeId)
                ->where(ThemeLayout::fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::fields_IS_ACTIVE, 1)
                ->where(ThemeLayout::fields_STATUS, $status)
                ->order(ThemeLayout::fields_AREA)
                ->order(ThemeLayout::fields_SORT_ORDER)
                ->select()
                ->fetchArray();

            // 确保 layouts 是数组
            if (!is_array($layouts)) {
                return $groupedLayout;
            }

            foreach ($layouts as $layout) {
                // 确保 layout 是数组
                if (!is_array($layout)) {
                    continue;
                }
                
                $area = $layout[ThemeLayout::fields_AREA] ?? '';
                if (isset($groupedLayout[$area])) {
                    $config = $layout[ThemeLayout::fields_CONFIG] ?? '{}';
                    $groupedLayout[$area]['widgets'][] = [
                        'layout_id' => $layout[ThemeLayout::fields_ID] ?? 0,
                        'widget_code' => $layout[ThemeLayout::fields_WIDGET_CODE] ?? '',
                        'widget_module' => $layout[ThemeLayout::fields_WIDGET_MODULE] ?? '',
                        'widget_type' => $layout[ThemeLayout::fields_WIDGET_TYPE] ?? '',
                        'slot_id' => $layout[ThemeLayout::fields_SLOT_ID] ?? null,
                        'config' => is_string($config) ? json_decode($config, true) : $config,
                        'sort_order' => $layout[ThemeLayout::fields_SORT_ORDER] ?? 0,
                        'status' => $layout[ThemeLayout::fields_STATUS] ?? $status,
                    ];
                }
            }
        } catch (\Exception $e) {
            // 表可能不存在，返回空布局
        }

        return $groupedLayout;
    }

    /**
     * 获取草稿布局配置（后台编辑用）
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @return array 按区域分组的部件配置
     */
    public function getDraftLayout(int $themeId, string $pageType = ThemeLayout::PAGE_TYPE_DEFAULT): array
    {
        return $this->getLayout($themeId, $pageType, ThemeLayout::STATUS_DRAFT);
    }

    /**
     * 获取已发布布局配置（前端显示用）
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @return array 按区域分组的部件配置
     */
    public function getPublishedLayout(int $themeId, string $pageType = ThemeLayout::PAGE_TYPE_DEFAULT): array
    {
        return $this->getLayout($themeId, $pageType, ThemeLayout::STATUS_PUBLISHED);
    }

    /**
     * 获取完整布局数据（包含部件元信息）
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param string $status 状态：draft=草稿，published=已发布（默认读取已发布）
     * @return array
     */
    public function getFullLayout(int $themeId, string $pageType = ThemeLayout::PAGE_TYPE_DEFAULT, string $status = ThemeLayout::STATUS_PUBLISHED): array
    {
        $layout = $this->getLayout($themeId, $pageType, $status);
        $widgetRegistry = $this->widgetRegistry->getRegistry();

        // 为每个部件添加元信息，并按 slot_id 组织到 slots 子数组
        foreach ($layout as $area => &$areaData) {
            // 初始化 slots 数组（用于有 slot_id 的部件）
            $areaData['slots'] = [];
            
            foreach ($areaData['widgets'] as &$widget) {
                // 添加部件元信息
                $widgetKey = $widget['widget_module'] . '/' . $widget['widget_type'] . '/' . $widget['widget_code'];
                if (isset($widgetRegistry[$widgetKey])) {
                    $widget['meta'] = $widgetRegistry[$widgetKey];
                } else {
                    // 尝试其他匹配方式（注册表是嵌套结构：type -> code -> widget_data）
                    $found = false;
                    foreach ($widgetRegistry as $type => $typeWidgets) {
                        if (!is_array($typeWidgets)) {
                            continue;
                        }
                        foreach ($typeWidgets as $code => $meta) {
                            if (!is_array($meta)) {
                                continue;
                            }
                            if (isset($meta['code']) && isset($meta['module'])
                                && $meta['code'] === $widget['widget_code'] 
                                && $meta['module'] === $widget['widget_module']) {
                                $widget['meta'] = $meta;
                                $found = true;
                                break 2;
                            }
                        }
                    }
                }
                
                // 如果部件有 slot_id，也添加到 slots 数组中
                // 这样模板可以通过 $layout['header']['slots']['logo'] 访问
                $slotId = $widget['slot_id'] ?? null;
                if ($slotId) {
                    if (!isset($areaData['slots'][$slotId])) {
                        $areaData['slots'][$slotId] = [];
                    }
                    $areaData['slots'][$slotId][] = $widget;
                }
            }
        }

        return $layout;
    }

    /**
     * 获取完整草稿布局数据（后台编辑用）
     */
    public function getFullDraftLayout(int $themeId, string $pageType = ThemeLayout::PAGE_TYPE_DEFAULT): array
    {
        return $this->getFullLayout($themeId, $pageType, ThemeLayout::STATUS_DRAFT);
    }

    /**
     * 保存单个部件配置（默认保存为草稿状态）
     * 
     * @param array $data 部件数据
     *  - theme_id: 主题ID
     *  - page_type: 页面类型
     *  - area: 区域
     *  - widget_code: 部件代码
     *  - widget_module: 部件模块
     *  - slot_id: 插槽ID（可选）
     *  - exclusive: 是否独占插槽（可选，默认false）
     *  - config: 部件配置
     *  - status: 状态（可选，默认draft）
     */
    public function saveWidget(array $data): int
    {
        $layoutId = $data['layout_id'] ?? 0;
        $slotId = $data['slot_id'] ?? null;
        $exclusive = (bool)($data['exclusive'] ?? false);
        $status = $data['status'] ?? ThemeLayout::STATUS_DRAFT;
        $sortOrder = (int)($data['sort_order'] ?? 0);

        // 如果是独占插槽，先删除该插槽/区域中相同类型的部件（仅限同状态）
        if ($exclusive && !$layoutId) {
            $this->removeExclusiveWidgets(
                (int)$data['theme_id'],
                $data['page_type'] ?? ThemeLayout::PAGE_TYPE_DEFAULT,
                $data['area'],
                $slotId,
                $data['widget_code'],
                $status
            );
        }

        // 非独占插入：将插入位置及之后的部件 sort_order +1，为新部件腾出位置
        if (!$exclusive && !$layoutId) {
            $this->shiftSortOrder(
                (int)$data['theme_id'],
                $data['page_type'] ?? ThemeLayout::PAGE_TYPE_DEFAULT,
                $data['area'],
                $slotId,
                $sortOrder,
                $status
            );
        }

        if ($layoutId) {
            // 更新现有部件
            $this->themeLayout->clearQuery()->load($layoutId);
        } else {
            // 新建部件 —— WLS 单例下必须彻底清除模型数据（包括主键），否则 save() 会执行 UPDATE 覆盖旧记录
            $this->themeLayout->clearQuery()->clearData();
        }

        $this->themeLayout
            ->setThemeId((int)$data['theme_id'])
            ->setPageType($data['page_type'] ?? ThemeLayout::PAGE_TYPE_DEFAULT)
            ->setArea($data['area'])
            ->setSlotId($slotId)
            ->setWidgetCode($data['widget_code'])
            ->setWidgetModule($data['widget_module'])
            ->setWidgetType($data['widget_type'] ?? '')
            ->setWidgetConfig($data['config'] ?? [])
            ->setSortOrder($sortOrder)
            ->setIsActive((bool)($data['is_active'] ?? true))
            ->setStatus($status)
            ->save();

        return $this->themeLayout->getLayoutId();
    }

    /**
     * 将指定位置及之后的部件 sort_order +1，为新插入腾出位置
     */
    private function shiftSortOrder(int $themeId, string $pageType, string $area, ?string $slotId, int $fromSortOrder, string $status): void
    {
        try {
            $query = $this->themeLayout->clearQuery()
                ->where(ThemeLayout::fields_THEME_ID, $themeId)
                ->where(ThemeLayout::fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::fields_AREA, $area)
                ->where(ThemeLayout::fields_STATUS, $status)
                ->where(ThemeLayout::fields_SORT_ORDER, $fromSortOrder, '>=');

            if ($slotId !== null && $slotId !== '') {
                $query->where(ThemeLayout::fields_SLOT_ID, $slotId);
            }

            $widgets = $query->select()->fetch();
            if (empty($widgets)) {
                return;
            }

            foreach ($widgets as $widget) {
                $id = (int)($widget[ThemeLayout::fields_ID] ?? 0);
                $currentOrder = (int)($widget[ThemeLayout::fields_SORT_ORDER] ?? 0);
                if ($id > 0) {
                    $this->themeLayout->clearQuery()->load($id);
                    $this->themeLayout->setSortOrder($currentOrder + 1)->save();
                }
            }
        } catch (\Throwable $e) {
            // sort_order 调整失败不阻塞保存
        }
    }

    /**
     * 删除独占插槽中的现有部件（仅限同状态）
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param string $area 区域
     * @param string|null $slotId 插槽ID
     * @param string $widgetCode 新部件代码（用于判断是否同类型）
     * @param string $status 状态
     */
    private function removeExclusiveWidgets(int $themeId, string $pageType, string $area, ?string $slotId, string $widgetCode, string $status = ThemeLayout::STATUS_DRAFT): void
    {
        try {
            $query = $this->themeLayout->clearQuery()
                ->where(ThemeLayout::fields_THEME_ID, $themeId)
                ->where(ThemeLayout::fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::fields_STATUS, $status);

            // 如果有插槽ID，按插槽删除（不限制 area，因为旧数据的 area 可能不一致）
            // 否则按区域+部件代码删除
            if ($slotId) {
                $query->where(ThemeLayout::fields_SLOT_ID, $slotId);
            } else {
                // 删除同类型的部件（独占整个区域）
                $query->where(ThemeLayout::fields_AREA, $area);
                $query->where(ThemeLayout::fields_WIDGET_CODE, $widgetCode);
            }

            $existingWidgets = $query->select()->fetch();
            
            // 如果按 slotId 没找到，尝试按 area = slotId 查找（兼容旧数据）
            if ($slotId && (!is_array($existingWidgets) || count($existingWidgets) === 0)) {
                $existingWidgets = $this->themeLayout->reset()
                    ->where(ThemeLayout::fields_THEME_ID, $themeId)
                    ->where(ThemeLayout::fields_PAGE_TYPE, $pageType)
                    ->where(ThemeLayout::fields_STATUS, $status)
                    ->where(ThemeLayout::fields_AREA, $slotId)  // 旧数据可能把 slotId 存在 area 字段
                    ->select()->fetch();
            }

            if (is_array($existingWidgets)) {
                foreach ($existingWidgets as $widget) {
                    if (is_array($widget) && isset($widget[ThemeLayout::fields_ID])) {
                        $this->deleteWidget((int)$widget[ThemeLayout::fields_ID]);
                    }
                }
            }
        } catch (\Exception $e) {
            // 忽略错误，可能是表不存在
        }
    }

    /**
     * 清理孤儿部件：删除同一 slot_id 下的重复记录（只保留最新一条）
     * 
     * 孤儿场景：
     * 1. 同一独占插槽被多次写入（并发/bug），数据库中出现重复
     * 2. slot_id 为 NULL 的旧数据不再匹配任何插槽
     * 
     * @param int $themeId 主题ID
     * @param string|null $pageType 页面类型（null=所有）
     * @return int 清理的记录数
     */
    public function cleanOrphanWidgets(int $themeId, ?string $pageType = null): int
    {
        $cleaned = 0;
        
        try {
            $pageTypes = $pageType ? [$pageType] : array_keys(ThemeLayout::getPageTypes());
            
            foreach ($pageTypes as $type) {
                foreach ([ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED] as $status) {
                    $layout = $this->getLayout($themeId, $type, $status);
                    
                    // 按 slot_id 分组，找出独占插槽中的重复记录
                    foreach ($layout as $area => $areaData) {
                        $slotWidgets = []; // slot_id => [layout_ids...]
                        
                        foreach ($areaData['widgets'] as $widget) {
                            $slotId = $widget['slot_id'] ?? '';
                            if (empty($slotId)) {
                                continue; // 跳过无 slot_id 的记录（可能是旧数据）
                            }
                            
                            $slotWidgets[$slotId][] = $widget['layout_id'];
                        }
                        
                        // 如果同一 slot_id 有多条记录，只保留最后一条（layout_id 最大的）
                        foreach ($slotWidgets as $slotId => $layoutIds) {
                            if (count($layoutIds) <= 1) {
                                continue;
                            }
                            
                            // 排序，保留最大的 layout_id
                            sort($layoutIds);
                            array_pop($layoutIds); // 移除最后一个（保留）
                            
                            // 删除多余的
                            foreach ($layoutIds as $removeId) {
                                $this->deleteWidget($removeId);
                                $cleaned++;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // 清理失败不影响发布
        }
        
        return $cleaned;
    }

    /**
     * 批量保存布局（默认保存为草稿）
     */
    public function saveLayout(int $themeId, string $pageType, array $layoutData, string $status = ThemeLayout::STATUS_DRAFT): bool
    {
        // 先删除该页面该状态的所有布局
        $this->themeLayout->reset()
            ->where(ThemeLayout::fields_THEME_ID, $themeId)
            ->where(ThemeLayout::fields_PAGE_TYPE, $pageType)
            ->where(ThemeLayout::fields_STATUS, $status)
            ->delete()
            ->fetch();

        // 保存新布局
        foreach ($layoutData as $area => $widgets) {
            foreach ($widgets as $index => $widget) {
                $this->saveWidget([
                    'theme_id' => $themeId,
                    'page_type' => $pageType,
                    'area' => $area,
                    'widget_code' => $widget['widget_code'],
                    'widget_module' => $widget['widget_module'],
                    'widget_type' => $widget['widget_type'] ?? '',
                    'slot_id' => $widget['slot_id'] ?? null,
                    'config' => $widget['config'] ?? [],
                    'sort_order' => $index,
                    'is_active' => $widget['is_active'] ?? true,
                    'status' => $status,
                ]);
            }
        }

        return true;
    }

    /**
     * 发布布局：将草稿状态的布局复制为已发布状态
     * 
     * 如果没有草稿数据，会先尝试从已发布数据复制，
     * 确保发布操作不会导致空数据。
     * 
     * @param int $themeId 主题ID
     * @param string|null $pageType 页面类型，null则发布所有页面类型
     * @return bool
     */
    public function publishLayout(int $themeId, ?string $pageType = null): bool
    {
        try {
            // 获取需要发布的页面类型列表
            if ($pageType) {
                $pageTypes = [$pageType];
            } else {
                $pageTypes = array_keys(ThemeLayout::getPageTypes());
            }

            foreach ($pageTypes as $type) {
                // 1. 获取草稿布局
                $draftLayout = $this->getLayout($themeId, $type, ThemeLayout::STATUS_DRAFT);
                
                // 检查草稿是否有数据
                $hasDraftWidgets = false;
                foreach ($draftLayout as $area => $areaData) {
                    if (!empty($areaData['widgets'])) {
                        $hasDraftWidgets = true;
                        break;
                    }
                }
                
                // 如果没有草稿数据，保持已发布数据不变
                if (!$hasDraftWidgets) {
                    continue;
                }

                // 2. 删除旧的已发布记录（全量替换，避免残留）
                $this->themeLayout->reset()
                    ->where(ThemeLayout::fields_THEME_ID, $themeId)
                    ->where(ThemeLayout::fields_PAGE_TYPE, $type)
                    ->where(ThemeLayout::fields_STATUS, ThemeLayout::STATUS_PUBLISHED)
                    ->delete()
                    ->fetch();

                // 3. 去重：按 slot_id 分组，独占插槽只保留最后一条
                $slotSeen = []; // slot_id => true（用于独占插槽去重）
                
                foreach ($draftLayout as $area => $areaData) {
                    foreach ($areaData['widgets'] as $widget) {
                        $slotId = $widget['slot_id'] ?? null;
                        
                        // 独占插槽去重：同一 slot_id 只发布一个部件
                        if ($slotId) {
                            $slotKey = $area . '::' . $slotId;
                            if (isset($slotSeen[$slotKey])) {
                                // 同一独占插槽已有部件，跳过冗余记录
                                continue;
                            }
                            $slotSeen[$slotKey] = true;
                        }
                        
                        $this->saveWidget([
                            'theme_id' => $themeId,
                            'page_type' => $type,
                            'area' => $area,
                            'widget_code' => $widget['widget_code'],
                            'widget_module' => $widget['widget_module'],
                            'widget_type' => $widget['widget_type'] ?? '',
                            'slot_id' => $slotId,
                            'config' => $widget['config'] ?? [],
                            'sort_order' => $widget['sort_order'] ?? 0,
                            'is_active' => true,
                            'status' => ThemeLayout::STATUS_PUBLISHED,
                        ]);
                    }
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查主题是否有草稿（未发布的修改）
     */
    public function hasDraft(int $themeId, ?string $pageType = null): bool
    {
        try {
            $query = $this->themeLayout->reset()
                ->where(ThemeLayout::fields_THEME_ID, $themeId)
                ->where(ThemeLayout::fields_STATUS, ThemeLayout::STATUS_DRAFT);

            if ($pageType) {
                $query->where(ThemeLayout::fields_PAGE_TYPE, $pageType);
            }

            // 使用 fetchArray() 替代 fetchOriginal()，与其他方法保持一致
            $result = $query->select()->fetchArray();
            
            // 检查结果是否为有效数组
            $count = is_array($result) ? count($result) : 0;
            
            return $count > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 撤销草稿：删除所有草稿，恢复到已发布状态
     */
    public function discardDraft(int $themeId, ?string $pageType = null): bool
    {
        try {
            $query = $this->themeLayout->reset()
                ->where(ThemeLayout::fields_THEME_ID, $themeId)
                ->where(ThemeLayout::fields_STATUS, ThemeLayout::STATUS_DRAFT);

            if ($pageType) {
                $query->where(ThemeLayout::fields_PAGE_TYPE, $pageType);
            }

            $query->delete()->fetch();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 初始化草稿：从已发布状态复制到草稿状态
     * 用于首次编辑时，将线上数据复制为草稿进行编辑
     */
    public function initDraftFromPublished(int $themeId, ?string $pageType = null): bool
    {
        try {
            $pageTypes = $pageType ? [$pageType] : array_keys(ThemeLayout::getPageTypes());

            foreach ($pageTypes as $type) {
                // 检查是否已有草稿
                if ($this->hasDraft($themeId, $type)) {
                    continue; // 已有草稿，跳过
                }

                // 获取已发布布局
                $publishedLayout = $this->getLayout($themeId, $type, ThemeLayout::STATUS_PUBLISHED);

                // 复制为草稿
                foreach ($publishedLayout as $area => $areaData) {
                    foreach ($areaData['widgets'] as $widget) {
                        $this->saveWidget([
                            'theme_id' => $themeId,
                            'page_type' => $type,
                            'area' => $area,
                            'widget_code' => $widget['widget_code'],
                            'widget_module' => $widget['widget_module'],
                            'widget_type' => $widget['widget_type'] ?? '',
                            'slot_id' => $widget['slot_id'] ?? null,
                            'config' => $widget['config'] ?? [],
                            'sort_order' => $widget['sort_order'] ?? 0,
                            'is_active' => true,
                            'status' => ThemeLayout::STATUS_DRAFT,
                        ]);
                    }
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 更新部件配置
     */
    public function updateWidgetConfig(int $layoutId, array $config): bool
    {
        $this->themeLayout->reset()->load($layoutId);
        if (!$this->themeLayout->getLayoutId()) {
            return false;
        }

        $this->themeLayout->setWidgetConfig($config)->save();
        return true;
    }

    /**
     * 删除部件
     */
    public function deleteWidget(int $layoutId): bool
    {
        $this->themeLayout->clearQuery()->load($layoutId);
        $loadedId = $this->themeLayout->getLayoutId();

        if (!$loadedId) {
            return false;
        }

        // 使用明确的 WHERE 条件删除，不依赖模型内部状态
        $this->themeLayout->clearQuery()
            ->where('layout_id', $layoutId)
            ->delete()
            ->fetch();

        // 验证删除结果
        $checkAfter = $this->themeLayout->clearQuery()
            ->where('layout_id', $layoutId)
            ->select()
            ->fetchArray();

        return empty($checkAfter);
    }

    /**
     * 根据布局ID获取部件数据
     */
    public function getWidgetByLayoutId(int $layoutId): ?array
    {
        $this->themeLayout->reset()->load($layoutId);
        if (!$this->themeLayout->getLayoutId()) {
            return null;
        }

        $widgetModule = $this->themeLayout->getWidgetModule();
        $widgetCode = $this->themeLayout->getWidgetCode();
        $config = $this->themeLayout->getWidgetConfig();

        // 解析 JSON 配置
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }

        return [
            'layout_id' => $layoutId,
            'widget_module' => $widgetModule,
            'widget_code' => $widgetCode,
            'config' => $config,
            'area' => $this->themeLayout->getArea(),
            'sort_order' => $this->themeLayout->getSortOrder(),
        ];
    }

    /**
     * 更新部件排序
     */
    public function updateSortOrder(array $sortData): bool
    {
        foreach ($sortData as $layoutId => $sortOrder) {
            $this->themeLayout->reset()->load($layoutId);
            if ($this->themeLayout->getLayoutId()) {
                $this->themeLayout->setSortOrder((int)$sortOrder)->save();
            }
        }
        return true;
    }

    /**
     * 移动部件到新区域
     */
    public function moveWidget(int $layoutId, string $newArea, int $newSortOrder): bool
    {
        $this->themeLayout->reset()->load($layoutId);
        if (!$this->themeLayout->getLayoutId()) {
            return false;
        }

        $this->themeLayout
            ->setArea($newArea)
            ->setSortOrder($newSortOrder)
            ->save();

        return true;
    }

    /**
     * 交换两个部件的排序顺序
     */
    public function swapWidgetOrder(int $layoutId1, int $layoutId2): bool
    {
        // 加载第一个部件
        $layout1 = clone $this->themeLayout;
        $layout1->reset()->load($layoutId1);
        if (!$layout1->getLayoutId()) {
            return false;
        }

        // 加载第二个部件
        $layout2 = clone $this->themeLayout;
        $layout2->reset()->load($layoutId2);
        if (!$layout2->getLayoutId()) {
            return false;
        }

        // 交换排序值
        $sortOrder1 = $layout1->getSortOrder();
        $sortOrder2 = $layout2->getSortOrder();

        $layout1->setSortOrder($sortOrder2)->save();
        $layout2->setSortOrder($sortOrder1)->save();

        return true;
    }

    /**
     * 获取插槽内的部件列表（按排序）
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param string $slotId 插槽ID
     * @param string $status 状态
     * @return array
     */
    public function getSlotWidgets(int $themeId, string $pageType, string $slotId, string $status = 'draft'): array
    {
        $layouts = $this->themeLayout->reset()
            ->where(ThemeLayout::fields_THEME_ID, $themeId)
            ->where(ThemeLayout::fields_PAGE_TYPE, $pageType)
            ->where(ThemeLayout::fields_STATUS, $status)
            ->where(ThemeLayout::fields_SLOT_ID, $slotId)
            ->order(ThemeLayout::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray();

        return $layouts ?: [];
    }

    /**
     * 批量更新插槽内部件排序
     * 
     * @param array $layoutIds 按顺序排列的布局ID数组
     * @return bool
     */
    public function updateSlotWidgetsOrder(array $layoutIds): bool
    {
        foreach ($layoutIds as $sortOrder => $layoutId) {
            $this->themeLayout->reset()->load($layoutId);
            if ($this->themeLayout->getLayoutId()) {
                $this->themeLayout->setSortOrder((int)$sortOrder)->save();
            }
        }
        return true;
    }

    /**
     * 复制布局到另一个页面类型
     */
    public function copyLayout(int $themeId, string $fromPageType, string $toPageType, string $status = ThemeLayout::STATUS_DRAFT): bool
    {
        $sourceLayout = $this->getLayout($themeId, $fromPageType, $status);

        // 转换格式
        $layoutData = [];
        foreach ($sourceLayout as $area => $areaData) {
            $layoutData[$area] = $areaData['widgets'];
        }

        return $this->saveLayout($themeId, $toPageType, $layoutData, $status);
    }

    /**
     * 独占区域定义
     * header 和 footer 是独占区域，选中整个区域时应显示独占大部件
     * content 不是独占区域，可以放置多个部件
     */
    public const EXCLUSIVE_AREAS = ['header', 'footer'];
    
    /**
     * 子 slot 到父区域的映射
     * 用于判断一个 slot 是顶层区域还是子 slot
     */
    public const SUB_SLOTS_MAP = [
        // Header 区域的子 slots
        'logo' => 'header',
        'search' => 'header',
        'navigation' => 'header',
        'user-area' => 'header',
        'account' => 'header',
        'cart' => 'header',
        'wishlist' => 'header',
        'language' => 'header',
        'currency' => 'header',
        // Footer 区域的子 slots
        'copyright' => 'footer',
        'links' => 'footer',
        'social' => 'footer',
        'newsletter' => 'footer',
        'payment' => 'footer',
    ];
    
    /**
     * 获取可用的部件列表（按类型分组）
     * 
     * @param string|null $pageType 页面类型，用于过滤部件。null 则不过滤
     * @param array|null $filterOptions 筛选选项：
     *   - slot_id: string|null 当前选中的 slot ID
     *   - slot_level: string 'top'(顶层区域) 或 'sub'(子 slot)
     *   - area: string|null 区域代码 (header/content/footer)
     *   - show_exclusive_only: bool 是否只显示独占部件
     * @return array
     */
    public function getAvailableWidgets(?string $pageType = null, ?array $filterOptions = null): array
    {
        $widgets = $this->widgetRegistry->getRegistry();

        // WidgetRegistry 返回的结构是 $result[$type][$name] = $config
        // 需要遍历两层：类型 -> 部件名称
        $grouped = [];
        
        foreach ($widgets as $type => $typeWidgets) {
            // $type 是类型（如 'header', 'footer'）
            // $typeWidgets 是该类型下的所有部件数组
            if (!is_array($typeWidgets)) {
                continue;
            }
            
            // 遍历该类型下的所有部件
            foreach ($typeWidgets as $widgetName => $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                
                // 确保部件数据包含 type 字段
                if (!isset($widget['type'])) {
                    $widget['type'] = $type;
                }
                // 确保部件数据包含 code 字段
                if (!isset($widget['code'])) {
                    $widget['code'] = $widgetName;
                }
                
                // 从原始配置中提取字段
                // WidgetScanner 将原始配置存储在 'config' 子数组中
                $originalConfig = $widget['config'] ?? [];
                
                // 提取 page_layouts 字段（布局目录名）
                $pageLayouts = $widget['page_layouts'] ?? $originalConfig['page_layouts'] ?? ['*'];
                $widget['page_layouts'] = $pageLayouts;
                
                // 如果指定了布局类型，检查部件是否适用
                if ($pageType !== null) {
                    if (!$this->isWidgetAllowedForLayout($pageLayouts, $pageType)) {
                        continue; // 跳过不适用于当前布局的部件
                    }
                }
                
                // 确保 position 字段存在（从原始配置中提取）
                if (!isset($widget['position']) && isset($originalConfig['position'])) {
                    $widget['position'] = $originalConfig['position'];
                }
                // 如果仍然没有 position，根据 type 设置默认值
                if (!isset($widget['position']) || empty($widget['position'])) {
                    $widget['position'] = $this->getDefaultPositionByType($type);
                }
                
                // 确保 compatible 字段存在
                if (!isset($widget['compatible'])) {
                    $widget['compatible'] = $originalConfig['compatible'] ?? false;
                }
                
                // 提取 exclusive 字段（独占部件）
                if (!isset($widget['exclusive'])) {
                    $widget['exclusive'] = $originalConfig['exclusive'] ?? false;
                }
                
                // 提取 is_container 字段
                if (!isset($widget['is_container'])) {
                    $widget['is_container'] = $originalConfig['is_container'] ?? false;
                }
                
                // 提取 slot 字段
                if (!isset($widget['slot'])) {
                    $widget['slot'] = $originalConfig['slot'] ?? null;
                }
                
                // 提取 slots 字段（容器部件的内部插槽定义）
                if (!isset($widget['slots'])) {
                    $widget['slots'] = $originalConfig['slots'] ?? [];
                }
                
                // 翻译 params 中的 label、description、placeholder 和 options
                if (!empty($widget['params']) && is_array($widget['params'])) {
                    $translatedParams = [];
                    foreach ($widget['params'] as $paramKey => $paramConfig) {
                        if (!is_array($paramConfig)) {
                            $translatedParams[$paramKey] = $paramConfig;
                            continue;
                        }
                        
                        // 翻译 label
                        if (!empty($paramConfig['label'])) {
                            $paramConfig['label'] = __($paramConfig['label']);
                        }
                        
                        // 翻译 description
                        if (!empty($paramConfig['description'])) {
                            $paramConfig['description'] = __($paramConfig['description']);
                        }
                        
                        // 翻译 placeholder
                        if (!empty($paramConfig['placeholder'])) {
                            $paramConfig['placeholder'] = __($paramConfig['placeholder']);
                        }
                        
                        // 翻译 options（用于 select 类型）
                        if (!empty($paramConfig['options']) && is_array($paramConfig['options'])) {
                            $translatedOptions = [];
                            foreach ($paramConfig['options'] as $optionValue => $optionLabel) {
                                $translatedOptions[$optionValue] = __($optionLabel);
                            }
                            $paramConfig['options'] = $translatedOptions;
                        }
                        
                        $translatedParams[$paramKey] = $paramConfig;
                    }
                    $widget['params'] = $translatedParams;
                }
                
                // 应用筛选选项（如果提供）
                if ($filterOptions !== null) {
                    if (!$this->matchesSlotFilter($widget, $filterOptions)) {
                        continue; // 跳过不匹配筛选条件的部件
                    }
                }
                
                // 添加到分组
                if (!isset($grouped[$type])) {
                    $grouped[$type] = [
                        'label' => $this->getTypeLabel($type),
                        'widgets' => [],
                    ];
                }
                
                $grouped[$type]['widgets'][] = $widget;
            }
        }

        return $grouped;
    }
    
    /**
     * 检查部件是否匹配 slot 筛选条件
     * 
     * 筛选逻辑：
     * 1. 顶层独占区域（header/footer）：只显示 exclusive=true 的大部件
     * 2. 子 slot（logo/search/navigation 等）：显示匹配该 slot 的小部件
     * 3. content 区域：显示所有适用于 content 位置的部件（非独占）
     * 
     * @param array $widget 部件配置
     * @param array $filterOptions 筛选选项
     * @return bool
     */
    private function matchesSlotFilter(array $widget, array $filterOptions): bool
    {
        $slotId = $filterOptions['slot_id'] ?? null;
        $area = $filterOptions['area'] ?? null;
        $showExclusiveOnly = $filterOptions['show_exclusive_only'] ?? false;
        
        // 如果没有指定 slot 或 area，返回所有部件
        if (!$slotId && !$area) {
            return true;
        }
        
        $widgetExclusive = $widget['exclusive'] ?? false;
        $widgetSlot = $widget['slot'] ?? null;
        $widgetPositions = $widget['position'] ?? [];
        $widgetType = $widget['type'] ?? '';
        
        // 确保 positions 是数组
        if (!is_array($widgetPositions)) {
            $widgetPositions = [$widgetPositions];
        }
        
        // 检查是否是子 slot
        $isSubSlot = isset(self::SUB_SLOTS_MAP[$slotId]);
        $parentArea = $isSubSlot ? self::SUB_SLOTS_MAP[$slotId] : null;
        
        // 检查当前区域是否是独占区域
        $isExclusiveArea = in_array($area, self::EXCLUSIVE_AREAS);
        
        // 情况1：选中的是子 slot（如 logo、search、navigation）
        if ($isSubSlot) {
            // 子 slot 内只显示匹配该 slot 的小部件
            // 部件的 slot 属性必须与选中的 slot 匹配
            if ($widgetSlot === $slotId) {
                return true;
            }
            // 或者部件 position 包含该 slot
            if (in_array($slotId, $widgetPositions)) {
                return true;
            }
            return false;
        }
        
        // 情况2：选中的是顶层独占区域（header/footer）
        if ($isExclusiveArea && ($area === $slotId || $slotId === null)) {
            // 独占区域只显示 exclusive=true 的大部件
            if ($showExclusiveOnly) {
                return $widgetExclusive === true;
            }
            // 否则显示所有 position 包含该区域的部件
            // 但独占大部件应优先显示
            if (in_array($area, $widgetPositions) || in_array('*', $widgetPositions)) {
                return true;
            }
            return false;
        }
        
        // 情况3：选中的是 content 区域（非独占）
        if ($area === 'content' || $slotId === 'content') {
            // content 区域显示所有 position 包含 content 的部件
            // 但排除 header 和 footer 类型的部件
            if ($widgetType === 'header' || $widgetType === 'footer') {
                return false;
            }
            if (in_array('content', $widgetPositions) || in_array('*', $widgetPositions)) {
                return true;
            }
            return false;
        }
        
        // 默认：根据 position 匹配
        if ($area && (in_array($area, $widgetPositions) || in_array('*', $widgetPositions))) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取指定 slot 的推荐部件
     * 
     * @param string $slotId slot ID
     * @param string|null $area 区域代码
     * @param string|null $pageType 页面类型
     * @return array 包含 exclusive_widgets 和 regular_widgets 两个数组
     */
    public function getWidgetsForSlot(string $slotId, ?string $area = null, ?string $pageType = null): array
    {
        // 判断是否是子 slot
        $isSubSlot = isset(self::SUB_SLOTS_MAP[$slotId]);
        $parentArea = $isSubSlot ? self::SUB_SLOTS_MAP[$slotId] : null;
        $effectiveArea = $area ?? $parentArea ?? $slotId;
        
        // 检查是否是独占区域
        $isExclusiveArea = in_array($effectiveArea, self::EXCLUSIVE_AREAS);
        
        // 获取所有部件
        $allWidgets = $this->getAvailableWidgets($pageType);
        
        $exclusiveWidgets = [];  // 独占大部件
        $regularWidgets = [];     // 普通小部件
        $matchedWidgets = [];     // 精确匹配的部件
        
        foreach ($allWidgets as $type => $group) {
            foreach ($group['widgets'] as $widget) {
                $widgetExclusive = $widget['exclusive'] ?? false;
                $widgetSlot = $widget['slot'] ?? null;
                $widgetPositions = $widget['position'] ?? [];
                $widgetType = $widget['type'] ?? '';
                
                if (!is_array($widgetPositions)) {
                    $widgetPositions = [$widgetPositions];
                }
                
                // 子 slot 筛选
                if ($isSubSlot) {
                    if ($widgetSlot === $slotId || in_array($slotId, $widgetPositions)) {
                        $matchedWidgets[] = $widget;
                    }
                    continue;
                }
                
                // 顶层区域筛选
                $positionMatches = in_array($effectiveArea, $widgetPositions) || in_array('*', $widgetPositions);
                
                // 排除不兼容类型
                if ($effectiveArea === 'content' && ($widgetType === 'header' || $widgetType === 'footer')) {
                    continue;
                }
                if ($effectiveArea === 'header' && $widgetType === 'footer') {
                    continue;
                }
                if ($effectiveArea === 'footer' && $widgetType === 'header') {
                    continue;
                }
                
                if (!$positionMatches) {
                    continue;
                }
                
                // 分类：独占 vs 普通
                if ($widgetExclusive) {
                    $exclusiveWidgets[] = $widget;
                } else {
                    $regularWidgets[] = $widget;
                }
            }
        }
        
        return [
            'slot_id' => $slotId,
            'area' => $effectiveArea,
            'is_sub_slot' => $isSubSlot,
            'is_exclusive_area' => $isExclusiveArea,
            'exclusive_widgets' => $exclusiveWidgets,  // 独占大部件（用于替换整个区域）
            'regular_widgets' => $regularWidgets,       // 普通部件（可多个排列）
            'matched_widgets' => $matchedWidgets,       // 精确匹配子 slot 的部件
        ];
    }

    /**
     * 检查部件是否适用于指定的布局
     * 
     * @param array $widgetLayouts 部件支持的布局目录名列表
     * @param string $layoutName 当前布局目录名
     * @return bool
     */
    private function isWidgetAllowedForLayout(array $widgetLayouts, string $layoutName): bool
    {
        // * 表示所有布局都可用
        if (in_array('*', $widgetLayouts)) {
            return true;
        }
        
        // 检查是否包含当前布局
        if (in_array($layoutName, $widgetLayouts)) {
            return true;
        }
        
        // default 布局的部件在所有页面都可用
        if (in_array('default', $widgetLayouts)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 根据部件类型获取默认允许的位置
     */
    private function getDefaultPositionByType(string $type): array
    {
        $typePositionMap = [
            'header' => ['header'],
            'footer' => ['footer'],
            'sidebar' => ['sidebar', 'left_sidebar', 'right_sidebar'],
            'banner' => ['banner', 'content'],
            'carousel' => ['banner', 'content'],
            'slider' => ['banner', 'content'],
            'product' => ['content', 'sidebar'],
            'category' => ['content', 'sidebar'],
            'navigation' => ['header'],
            'search' => ['header'],
            'breadcrumb' => ['content'],
            'pagination' => ['content'],
            'social' => ['footer', 'sidebar'],
            'newsletter' => ['footer', 'sidebar', 'content'],
            'testimonial' => ['content'],
            'faq' => ['content'],
            'video' => ['content', 'banner'],
            'content' => ['content', 'banner', 'sidebar'],
        ];
        
        return $typePositionMap[$type] ?? ['content']; // 默认允许在内容区
    }

    /**
     * 获取类型标签
     */
    private function getTypeLabel(string $type): string
    {
        $labels = [
            'header' => __('头部部件'),
            'footer' => __('底部部件'),
            'sidebar' => __('侧栏部件'),
            'content' => __('内容部件'),
            'banner' => __('横幅部件'),
            'carousel' => __('轮播部件'),
            'product' => __('产品部件'),
            'category' => __('分类部件'),
            'navigation' => __('导航部件'),
            'search' => __('搜索部件'),
            'social' => __('社交部件'),
            'newsletter' => __('订阅部件'),
            'testimonial' => __('评价部件'),
            'faq' => __('FAQ部件'),
            'breadcrumb' => __('面包屑部件'),
            'pagination' => __('分页部件'),
            'video' => __('视频部件'),
            'other' => __('其他部件'),
        ];

        return $labels[$type] ?? ucfirst($type);
    }
}
