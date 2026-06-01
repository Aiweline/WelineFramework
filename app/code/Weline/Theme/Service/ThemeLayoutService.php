<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Interface\ThemePlaceableRegistryInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题布局服务
 * 管理主题的部件布局配置
 */
class ThemeLayoutService
{
    private ThemeLayout $themeLayout;
    private WelineTheme $welineTheme;
    private ThemePlaceableRegistryInterface $placeableRegistry;

    public function __construct(
        ThemeLayout $themeLayout,
        WelineTheme $welineTheme,
        mixed $placeableRegistry = null
    ) {
        $this->themeLayout = $themeLayout;
        $this->welineTheme = $welineTheme;
        $this->placeableRegistry = $this->resolvePlaceableRegistry($placeableRegistry);
    }

    private function getEventsManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
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
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::schema_fields_IS_ACTIVE, 1)
                ->where(ThemeLayout::schema_fields_STATUS, $status)
                ->order(ThemeLayout::schema_fields_AREA, 'ASC')
                ->order(ThemeLayout::schema_fields_SORT_ORDER, 'ASC')
                ->order(ThemeLayout::schema_fields_ID, 'ASC')
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
                
                $area = $layout[ThemeLayout::schema_fields_AREA] ?? '';
                if (isset($groupedLayout[$area])) {
                    $config = $layout[ThemeLayout::schema_fields_CONFIG] ?? '{}';
                    $groupedLayout[$area]['widgets'][] = [
                        'layout_id' => $layout[ThemeLayout::schema_fields_ID] ?? 0,
                        'widget_code' => $layout[ThemeLayout::schema_fields_WIDGET_CODE] ?? '',
                        'widget_module' => $layout[ThemeLayout::schema_fields_WIDGET_MODULE] ?? '',
                        'widget_type' => $layout[ThemeLayout::schema_fields_WIDGET_TYPE] ?? '',
                        'slot_id' => $layout[ThemeLayout::schema_fields_SLOT_ID] ?? null,
                        'config' => is_string($config) ? json_decode($config, true) : $config,
                        'sort_order' => $layout[ThemeLayout::schema_fields_SORT_ORDER] ?? 0,
                        'status' => $layout[ThemeLayout::schema_fields_STATUS] ?? $status,
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
        $widgetRegistry = ObjectManager::getInstance(\Weline\Widget\Service\WidgetRegistry::class)->getRegistry();

        // 为每个部件添加元信息，并按 slot_id 组织到 slots 子数组
        foreach ($layout as $area => &$areaData) {
            // 初始化 slots 数组（用于有 slot_id 的部件）
            $areaData['slots'] = [];
            
            foreach ($areaData['widgets'] as &$widget) {
                // 添加部件元信息
                $definition = $this->placeableRegistry->find(
                    (string)($widget['widget_module'] ?? ''),
                    (string)($widget['widget_type'] ?? ''),
                    (string)($widget['widget_code'] ?? ''),
                    null,
                    (string)$area
                );
                if ($definition) {
                    $widget['meta'] = $definition->toWidgetArray();
                }
                $widgetKey = $widget['widget_module'] . '/' . $widget['widget_type'] . '/' . $widget['widget_code'];
                if (!isset($widget['meta']) && isset($widgetRegistry[$widgetKey])) {
                    $widget['meta'] = $widgetRegistry[$widgetKey];
                } elseif (!isset($widget['meta'])) {
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
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::schema_fields_AREA, $area)
                ->where(ThemeLayout::schema_fields_STATUS, $status)
                ->where(ThemeLayout::schema_fields_SORT_ORDER, $fromSortOrder, '>=');

            if ($slotId !== null && $slotId !== '') {
                $query->where(ThemeLayout::schema_fields_SLOT_ID, $slotId);
            }

            $widgets = $query->select()->fetch();
            if (empty($widgets)) {
                return;
            }

            foreach ($widgets as $widget) {
                $id = (int)($widget[ThemeLayout::schema_fields_ID] ?? 0);
                $currentOrder = (int)($widget[ThemeLayout::schema_fields_SORT_ORDER] ?? 0);
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
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::schema_fields_STATUS, $status);

            // 如果有插槽ID，按插槽删除（不限制 area，因为旧数据的 area 可能不一致）
            // 否则按区域+部件代码删除
            if ($slotId) {
                $query->where(ThemeLayout::schema_fields_SLOT_ID, $slotId);
            } else {
                // 删除同类型的部件（独占整个区域）
                $query->where(ThemeLayout::schema_fields_AREA, $area);
                $query->where(ThemeLayout::schema_fields_WIDGET_CODE, $widgetCode);
            }

            $existingWidgets = $query->select()->fetch();
            
            // 如果按 slotId 没找到，尝试按 area = slotId 查找（兼容旧数据）
            if ($slotId && (!is_array($existingWidgets) || count($existingWidgets) === 0)) {
                $existingWidgets = $this->themeLayout->reset()
                    ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                    ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                    ->where(ThemeLayout::schema_fields_STATUS, $status)
                    ->where(ThemeLayout::schema_fields_AREA, $slotId)  // 旧数据可能把 slotId 存在 area 字段
                    ->select()->fetch();
            }

            if (is_array($existingWidgets)) {
                foreach ($existingWidgets as $widget) {
                    if (is_array($widget) && isset($widget[ThemeLayout::schema_fields_ID])) {
                        $this->deleteWidget((int)$widget[ThemeLayout::schema_fields_ID]);
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
            ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
            ->where(ThemeLayout::schema_fields_STATUS, $status)
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
                    ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                    ->where(ThemeLayout::schema_fields_PAGE_TYPE, $type)
                    ->where(ThemeLayout::schema_fields_STATUS, ThemeLayout::STATUS_PUBLISHED)
                    ->delete()
                    ->fetch();

                // 3. 去重：独占插槽按 slot_id；其余按 area+slot+部件身份，避免脏草稿/快照重复发布
                $exclusiveSlotSeen = [];
                $widgetIdentitySeen = [];

                foreach ($draftLayout as $area => $areaData) {
                    foreach ($areaData['widgets'] as $widget) {
                        $slotId = $widget['slot_id'] ?? null;

                        if ($slotId && $this->isExclusivePublishSlot((string)$slotId)) {
                            $slotKey = $area . '::' . $slotId;
                            if (isset($exclusiveSlotSeen[$slotKey])) {
                                continue;
                            }
                            $exclusiveSlotSeen[$slotKey] = true;
                        } else {
                            $identityKey = $area . '::'
                                . ($slotId ?? '') . '::'
                                . ($widget['widget_module'] ?? '') . '::'
                                . ($widget['widget_code'] ?? '');
                            if (isset($widgetIdentitySeen[$identityKey])) {
                                continue;
                            }
                            $widgetIdentitySeen[$identityKey] = true;
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

            $this->purgePublishedLayoutCaches();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 发布布局后清理前端路由/FPC/共享内存中的旧版 slot 与整页缓存，避免 Worker 继续输出重复或过期部件。
     */
    private function purgePublishedLayoutCaches(): void
    {
        if (\class_exists(SlotRendererService::class)) {
            ObjectManager::getInstance(SlotRendererService::class)->clearCache();
        }

        try {
            ObjectManager::getInstance(\Weline\Framework\Router\Cache\RouterCache::class . 'Factory')->flush();
        } catch (\Throwable) {
        }

        try {
            if (\class_exists(\Weline\Framework\Router\FullPageCacheCoordinator::class)) {
                \Weline\Framework\Router\FullPageCacheCoordinator::clearProcessCache();
            }
        } catch (\Throwable) {
        }

        try {
            $cacheManager = ObjectManager::getInstance(\Weline\Framework\Cache\CacheManager::class);
            foreach (['fpc', 'router'] as $pool) {
                if (\method_exists($cacheManager, 'hasPool') && $cacheManager->hasPool($pool)) {
                    $cacheManager->pool($pool)->clear();
                }
            }
        } catch (\Throwable) {
        }

        try {
            ObjectManager::getInstance(\Weline\Server\Service\Control\BroadcastControlDispatchService::class)
                ->cacheClear();
        } catch (\Throwable) {
        }

        try {
            if (\class_exists(\Weline\Server\Service\MemoryStateFacade::class)) {
                $facade = new \Weline\Server\Service\MemoryStateFacade([
                    'consumer_code' => 'theme_layout_publish',
                    'prefer_direct_connect' => true,
                    'pool_size' => 1,
                    'auto_start' => false,
                ]);
                $facade->clearCache('router');
                $facade->clearCache('fpc');
                $facade->clearNamespace('theme_runtime');
                $facade->disconnect();
            }
        } catch (\Throwable) {
        }

        $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads';
        if (\is_dir($payloadDir)) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($payloadDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $item) {
                    if ($item->isDir()) {
                        @\rmdir($item->getPathname());
                    } else {
                        @\unlink($item->getPathname());
                    }
                }
            } catch (\Throwable) {
            }
        }
    }

    /**
     * 检查主题是否有草稿（未发布的修改）
     */
    public function hasDraft(int $themeId, ?string $pageType = null): bool
    {
        try {
            $query = $this->themeLayout->reset()
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_STATUS, ThemeLayout::STATUS_DRAFT);

            if ($pageType) {
                $query->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType);
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
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_STATUS, ThemeLayout::STATUS_DRAFT);

            if ($pageType) {
                $query->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType);
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
        // 不对 clearQuery() 链式调用 load()，避免在 Query 上调用 load() 导致致命错误
        $this->themeLayout->load($layoutId);
        $loadedId = $this->themeLayout->getLayoutId();

        if (!$loadedId) {
            return false;
        }

        // 使用模型已加载状态执行删除（getQuery() 会带表名，delete() 用主键条件，避免 clearQuery 后链式导致表名/条件丢失）
        $this->themeLayout->delete()->fetch();

        // 验证删除结果
        $this->themeLayout->clearQuery();
        $checkAfter = $this->themeLayout->where('layout_id', $layoutId)->select()->fetchArray();

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
            'widget_type' => $this->themeLayout->getWidgetType(),
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
            ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
            ->where(ThemeLayout::schema_fields_STATUS, $status)
            ->where(ThemeLayout::schema_fields_SLOT_ID, $slotId)
            ->order(ThemeLayout::schema_fields_SORT_ORDER, 'ASC')
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
    public function getAvailableWidgets(?string $pageType = null, ?array $filterOptions = null, string $area = 'frontend'): array
    {
        $effectiveArea = (string)($filterOptions['area'] ?? $area);
        if ($effectiveArea !== 'backend') {
            $effectiveArea = 'frontend';
        }

        return $this->placeableRegistry->getAvailableList($pageType, $filterOptions, null, $effectiveArea);
    }
    
    /**
     * 获取指定 slot 的推荐部件
     * 
     * @param string $slotId slot ID
     * @param string|null $area 区域代码
     * @param string|null $pageType 页面类型
     * @return array 包含 exclusive_widgets 和 regular_widgets 两个数组
     */
    public function getWidgetsForSlot(string $slotId, ?string $area = null, ?string $pageType = null, array $acceptCodes = [], array $rejectCodes = []): array
    {
        // 判断是否是子 slot
        $isSubSlot = isset(self::SUB_SLOTS_MAP[$slotId]);
        $parentArea = $isSubSlot ? self::SUB_SLOTS_MAP[$slotId] : null;
        $effectiveArea = $area ?? $parentArea ?? $slotId;
        
        // 检查是否是独占区域
        $isExclusiveArea = in_array($effectiveArea, self::EXCLUSIVE_AREAS);
        
        // 获取所有部件，支持 slot accept/reject 与部件 code/type/slot/position/slots 的协议交叉过滤
        $allWidgets = $this->getAvailableWidgets($pageType, [
            'slot_id' => $slotId,
            'area' => $effectiveArea,
            'accept' => $acceptCodes,
            'reject' => $rejectCodes,
        ]);
        
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

    private function resolvePlaceableRegistry(mixed $placeableRegistry): ThemePlaceableRegistryInterface
    {
        if ($placeableRegistry instanceof ThemePlaceableRegistryInterface) {
            return $placeableRegistry;
        }

        return ObjectManager::getInstance(ThemePlaceableRegistry::class);
    }

    /**
     * 与 ThemeEditor 保存部件、theme-editor.js isExclusiveSlot 的独占插槽列表保持一致。
     */
    private function isExclusivePublishSlot(string $slotId): bool
    {
        static $exclusiveSlots = [
            'header',
            'logo',
            'search',
            'navigation',
            'footer',
            'footer-social',
            'footer-copyright',
            'widget-hero',
            'list-grid',
            'list-pagination',
        ];

        return \in_array($slotId, $exclusiveSlots, true);
    }
}
