<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Interface\ThemePlaceableRegistryInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService;
use Weline\Widget\Api\WidgetRegistryInterface;

/**
 * 主题布局服务（绿field 门面）。
 *
 * 权威读写走 ThemeScopedLayoutWriteService / ThemeScopedWorkspace（node_uid）。
 * 下列以数字 layout_id 为键的 API 在 theme_layout DROP 后一律 fail-closed，保留签名供旧调用方编译。
 */
class ThemeLayoutService
{
    private const WIDGET_I18N_INSTANCE_KEY = \Weline\Theme\Helper\ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY;
    private const NO_PLACEMENTS_WIDGET_MODULE = 'Weline_Theme';
    private const NO_PLACEMENTS_WIDGET_TYPE = 'layout_state';
    private const NO_PLACEMENTS_WIDGET_CODE = '__no_widget_placements__';

    private ThemeLayout $themeLayout;
    private WelineTheme $welineTheme;
    private ThemePlaceableRegistryInterface $placeableRegistry;
    private ?ThemeLayoutScopeNormalizer $scopeNormalizer;
    private ?WidgetImageContentContractValidator $imageContentValidator;
    private ?LayoutContentValidationRegistry $contentValidationRegistry;
    private ?WriteIntentTransactionCoordinatorInterface $transactions;

    public function __construct(
        ThemeLayout $themeLayout,
        WelineTheme $welineTheme,
        mixed $placeableRegistry = null,
        ?ThemeLayoutScopeNormalizer $scopeNormalizer = null,
        ?WidgetImageContentContractValidator $imageContentValidator = null,
        ?LayoutContentValidationRegistry $contentValidationRegistry = null,
        ?WriteIntentTransactionCoordinatorInterface $transactions = null,
    ) {
        $this->themeLayout = $themeLayout;
        $this->welineTheme = $welineTheme;
        $this->placeableRegistry = $this->resolvePlaceableRegistry($placeableRegistry);
        $this->scopeNormalizer = $scopeNormalizer;
        $this->imageContentValidator = $imageContentValidator;
        $this->contentValidationRegistry = $contentValidationRegistry;
        $this->transactions = $transactions;
    }

    private function getEventsManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code:string,store_mode?:string,storage_scope?:string}
     */
    private function normalizeLayoutIdentity(array $identity = []): array
    {
        return $this->getLayoutScopeNormalizer()->normalize($identity);
    }

    private function getLayoutScopeNormalizer(): ThemeLayoutScopeNormalizer
    {
        if ($this->scopeNormalizer instanceof ThemeLayoutScopeNormalizer) {
            return $this->scopeNormalizer;
        }

        /** @var ScopeHierarchyInterface $hierarchy */
        $hierarchy = ObjectManager::getInstance(ScopeHierarchyInterface::class);

        return $this->scopeNormalizer = new ThemeLayoutScopeNormalizer($hierarchy);
    }

    private function applyLayoutIdentityFilters(mixed $query, array $identity): mixed
    {
        return $this->applyNormalizedLayoutIdentityFilters(
            $query,
            $this->normalizeLayoutIdentity($identity),
        );
    }

    private function applyNormalizedLayoutIdentityFilters(mixed $query, array $identity): mixed
    {
        return $query
            ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
            ->where(ThemeLayout::schema_fields_SCOPE, $identity['scope'])
            ->where(ThemeLayout::schema_fields_LOCALE_CODE, $identity['locale_code'])
            ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
            ->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id']);
    }

    /** @return list<string> */
    private function localeReadCandidates(string $localeCode): array
    {
        // Structure rows are language-neutral; always address the empty-locale identity.
        unset($localeCode);

        return [''];
    }

    /**
     * 删除某个布局 identify 下的布局行。
     *
     * WLS 长驻进程里 ThemeLayout 是可复用模型对象，链式 delete 在复杂查询后容易受模型状态影响。
     * 这里先按条件取主键，再逐行删除，保证 save/publish 的“全量替换”语义稳定。
     *
     * @param array<string,mixed>|null $identity null 表示不限制 layout identity
     */
    private function deleteLayoutRows(int $themeId, string $pageType, string $status, ?array $identity = null): int
    {
        // Greenfield: theme_layout is dropped. Row deletes are no-ops.
        unset($themeId, $pageType, $status, $identity);

        return 0;
    }

    private function legacyLayoutTableExists(): bool
    {
        try {
            return (bool)$this->themeLayout
                ->getConnection()
                ->getConnector()
                ->tableExist(ThemeLayout::schema_table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasWidgetPlacementsInput(array $layoutData): bool
    {
        foreach ($layoutData as $widgets) {
            if (is_array($widgets) && $widgets !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private function isNoWidgetPlacementsRow(array $row): bool
    {
        return (string)($row[ThemeLayout::schema_fields_WIDGET_MODULE] ?? '') === self::NO_PLACEMENTS_WIDGET_MODULE
            && (string)($row[ThemeLayout::schema_fields_WIDGET_TYPE] ?? '') === self::NO_PLACEMENTS_WIDGET_TYPE
            && (string)($row[ThemeLayout::schema_fields_WIDGET_CODE] ?? '') === self::NO_PLACEMENTS_WIDGET_CODE;
    }

    /**
     * Store an inactive sentinel row so "the user explicitly removed every
     * widget placement" is distinguishable from "this layout has never been
     * configured".
     *
     * The row is ignored by getLayout() because is_active=0, but hasDraft() and
     * preview fallback decisions can still see the placement state. Slots still
     * come from the theme template and keep rendering their own default content.
     *
     * @param array<string,mixed> $identity
     */
    private function markNoWidgetPlacements(int $themeId, string $pageType, string $status, array $identity): void
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        try {
            /** @var ThemeScopedLayoutWriteService $layoutWriter */
            $layoutWriter = ObjectManager::getInstance(ThemeScopedLayoutWriteService::class);
            /** @var ThemeScopedWorkspaceInterface $workspace */
            $workspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
            $context = $this->buildScopedLayoutContext($themeId, $pageType, $identity);
            $layoutWriter->clearDraftNodes($context, 'system:theme-layout-service', '');
            $layoutWriter->addWidget($context, [
                'theme_id' => $themeId,
                'page_type' => $pageType,
                'area' => ThemeLayout::AREA_CONTENT,
                'slot_id' => null,
                'widget_code' => self::NO_PLACEMENTS_WIDGET_CODE,
                'widget_module' => self::NO_PLACEMENTS_WIDGET_MODULE,
                'widget_type' => self::NO_PLACEMENTS_WIDGET_TYPE,
                'config' => ['no_widget_placements' => true],
                'sort_order' => 0,
                'is_active' => false,
                'exclusive' => false,
            ], 'system:theme-layout-service', '');
            if ($status === ThemeLayout::STATUS_PUBLISHED) {
                $state = $workspace->load($context, true);
                $workspace->publish(
                    $context,
                    (int)($state['revision'] ?? 0),
                    isset($state['expected_parent_release_id'])
                        ? (int)$state['expected_parent_release_id']
                        : null,
                    'system:theme-layout-service',
                    '',
                    'layout_marked_no_widget_placements',
                );
            }
        } catch (\Throwable) {
            // fail-closed: scoped marker is best-effort after theme_layout drop
        }
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function deleteNoWidgetPlacementsMarker(int $themeId, string $pageType, string $status, array $identity): void
    {
        // Greenfield: marker lives in scoped draft nodes; cleared by addWidget/clearDraftNodes paths.
        unset($themeId, $pageType, $status, $identity);
    }

    /**
     * @param array<string,mixed> $identity
     */
    public function hasNoWidgetPlacements(int $themeId, string $pageType, string $status, array $identity = []): bool
    {
        try {
            $identity = $this->normalizeLayoutIdentity($identity);
            if (!$this->legacyLayoutTableExists()) {
                // Greenfield: no theme_layout sentinel table; empty vs never-configured
                // is owned by scoped workspace revision, not a marker row.
                return false;
            }
            foreach ($this->getLayoutScopeNormalizer()->readFallbackScopes($identity['scope']) as $scope) {
                foreach ($this->localeReadCandidates($identity['locale_code']) as $localeCode) {
                    $candidate = $identity;
                    $candidate['scope'] = $scope;
                    $candidate['locale_code'] = $localeCode;
                    $query = $this->themeLayout->clearQuery()->clearData()
                        ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                        ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                        ->where(ThemeLayout::schema_fields_STATUS, $status);
                    $rows = $this->applyNormalizedLayoutIdentityFilters($query, $candidate)
                        ->select()->fetchArray();
                    if (!is_array($rows) || $rows === []) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        if (is_array($row)
                            && ($row[ThemeLayout::schema_fields_WIDGET_MODULE] ?? null)
                                === self::NO_PLACEMENTS_WIDGET_MODULE
                            && ($row[ThemeLayout::schema_fields_WIDGET_TYPE] ?? null)
                                === self::NO_PLACEMENTS_WIDGET_TYPE
                            && ($row[ThemeLayout::schema_fields_WIDGET_CODE] ?? null)
                                === self::NO_PLACEMENTS_WIDGET_CODE
                        ) {
                            return true;
                        }
                    }
                    return false;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function hasActiveWidgetPlacementsForLayout(int $themeId, string $pageType, string $status, array $identity): bool
    {
        try {
            foreach ($this->getLayout($themeId, $pageType, $status, $identity) as $areaData) {
                if (is_array($areaData) && !empty($areaData['widgets'])) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 获取主题布局配置
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param string $status 状态：draft=草稿，published=已发布（默认读取已发布）
     * @return array 按区域分组的部件配置
     */
    public function getLayout(
        int $themeId,
        string $pageType = ThemeLayout::PAGE_TYPE_DEFAULT,
        string $status = ThemeLayout::STATUS_PUBLISHED,
        array $identity = [],
        bool $strict = false,
    ): array
    {
        try {
            return $this->getScopedLayout($themeId, $pageType, $status, $identity);
        } catch (\Throwable $scopedError) {
            if ($strict) {
                throw new \RuntimeException((string)__('Theme 布局读取失败。'), 0, $scopedError);
            }
        }

        // Empty skeleton only — legacy theme_layout is not an authority after greenfield.
        $groupedLayout = [];
        foreach (ThemeLayout::getAreas() as $areaCode => $areaLabel) {
            $groupedLayout[$areaCode] = [
                'label' => $areaLabel,
                'widgets' => [],
            ];
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
    public function getDraftLayout(int $themeId, string $pageType = ThemeLayout::PAGE_TYPE_DEFAULT, array $identity = []): array
    {
        return $this->getLayout($themeId, $pageType, ThemeLayout::STATUS_DRAFT, $identity);
    }

    /**
     * 获取已发布布局配置（前端显示用）
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @return array 按区域分组的部件配置
     */
    public function getPublishedLayout(int $themeId, string $pageType = ThemeLayout::PAGE_TYPE_DEFAULT, array $identity = []): array
    {
        return $this->getLayout($themeId, $pageType, ThemeLayout::STATUS_PUBLISHED, $identity);
    }

    /**
     * 获取完整布局数据（包含部件元信息）
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param string $status 状态：draft=草稿，published=已发布（默认读取已发布）
     * @return array
     */
    public function getFullLayout(int $themeId, string $pageType = ThemeLayout::PAGE_TYPE_DEFAULT, string $status = ThemeLayout::STATUS_PUBLISHED, array $identity = []): array
    {
        return $this->decorateLayoutForRender(
            $this->getLayout($themeId, $pageType, $status, $identity),
            $pageType,
        );
    }

    /**
     * Add component/widget registry metadata to an already resolved layout.
     *
     * Scoped Releases use this path so runtime rendering never has to re-read
     * draft or published legacy rows as an authority.
     */
    public function decorateLayoutForRender(array $layout, string $pageType): array
    {
        $widgetRegistry = ObjectManager::getInstance(WidgetRegistryInterface::class)->getRegistry();

        // 为每个部件添加元信息，并按 slot_id 组织到 slots 子数组
        foreach ($layout as $area => &$areaData) {
            // 初始化 slots 数组（用于有 slot_id 的部件）
            $areaData['slots'] = [];
            
            foreach ($areaData['widgets'] as &$widget) {
                $registryArea = $this->resolveWidgetRegistryArea($pageType, (string)$area, $widget);
                // 添加部件元信息
                $definition = $this->placeableRegistry->find(
                    (string)($widget['widget_module'] ?? ''),
                    (string)($widget['widget_type'] ?? ''),
                    (string)($widget['widget_code'] ?? ''),
                    null,
                    $registryArea
                );
                if ($definition) {
                    $widget['meta'] = $definition->toWidgetArray();
                }
                $widgetKey = $widget['widget_module'] . '/' . $widget['widget_type'] . '/' . $widget['widget_code'];
                if (!isset($widget['meta'])
                    && isset($widgetRegistry[$widgetKey])
                    && \is_array($widgetRegistry[$widgetKey])
                    && $this->widgetMetaMatchesArea($widgetRegistry[$widgetKey], $registryArea)) {
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
                                && $meta['module'] === $widget['widget_module']
                                && $this->widgetMetaMatchesArea($meta, $registryArea)) {
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

    private function resolveWidgetRegistryArea(string $pageType, string $layoutArea, array $widget): string
    {
        if ($pageType === ThemeLayout::PAGE_TYPE_DASHBOARD
            || (string)($widget['page_type'] ?? '') === ThemeLayout::PAGE_TYPE_DASHBOARD
            || (string)($widget['target_type'] ?? '') === 'website') {
            return 'backend';
        }

        return $layoutArea === 'backend' ? 'backend' : 'frontend';
    }

    private function widgetMetaMatchesArea(array $meta, string $area): bool
    {
        $widgetArea = (string)($meta['area'] ?? 'frontend');
        return $widgetArea === '' || $widgetArea === $area;
    }

    /**
     * 获取完整草稿布局数据（后台编辑用）
     */
    public function getFullDraftLayout(int $themeId, string $pageType = ThemeLayout::PAGE_TYPE_DEFAULT, array $identity = []): array
    {
        return $this->getFullLayout($themeId, $pageType, ThemeLayout::STATUS_DRAFT, $identity);
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
        return $this->atomicWrite('theme_layout_widget_save', function () use ($data): int {
            $status = (string)($data['status'] ?? ThemeLayout::STATUS_DRAFT);
            $identity = $this->normalizeLayoutIdentity($data);
            $pageType = (string)($data['page_type'] ?? ThemeLayout::PAGE_TYPE_DEFAULT);
            $themeId = (int)($data['theme_id'] ?? 0);
            if ($themeId <= 0) {
                throw new \InvalidArgumentException('theme_id_required');
            }

            $isNoPlacementsMarker = (string)($data['widget_module'] ?? '') === self::NO_PLACEMENTS_WIDGET_MODULE
                && (string)($data['widget_type'] ?? '') === self::NO_PLACEMENTS_WIDGET_TYPE
                && (string)($data['widget_code'] ?? '') === self::NO_PLACEMENTS_WIDGET_CODE;
            $config = is_array($data['config'] ?? null) ? $data['config'] : [];

            if (!$isNoPlacementsMarker) {
                $this->getImageContentValidator()->validate([
                    (string)($data['area'] ?? '') => [[
                        'widget_module' => (string)($data['widget_module'] ?? ''),
                        'widget_type' => (string)($data['widget_type'] ?? ''),
                        'widget_code' => (string)($data['widget_code'] ?? ''),
                        'page_type' => $pageType,
                        'target_type' => (string)($identity['target_type'] ?? $data['target_type'] ?? ''),
                        'config' => $config,
                    ]],
                ], [
                    'phase' => 'save',
                    'page_type' => $pageType,
                    'layout_area' => (string)($data['area'] ?? ''),
                    'target_type' => (string)($identity['target_type'] ?? $data['target_type'] ?? ''),
                ]);
            }

            /** @var ThemeScopedLayoutWriteService $layoutWriter */
            $layoutWriter = ObjectManager::getInstance(ThemeScopedLayoutWriteService::class);
            /** @var ThemeScopedWorkspaceInterface $workspace */
            $workspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
            $context = $this->buildScopedLayoutContext($themeId, $pageType, $identity);

            $nodeUid = \strtolower(\trim((string)($data['node_uid'] ?? '')));
            $layoutId = (int)($data['layout_id'] ?? 0);

            // Legacy numeric layout_id updates are dead after theme_layout drop.
            if ($layoutId > 0 && !\preg_match('/^[a-f0-9]{32}$/D', $nodeUid)) {
                throw new \RuntimeException((string)__('Theme 布局写入已迁移到 scoped node_uid，不再支持 layout_id 更新。'));
            }

            if (\preg_match('/^[a-f0-9]{32}$/D', $nodeUid) === 1) {
                $state = $workspace->load($context, true);
                $nodes = \is_array($state['draft_payload']['nodes'] ?? null) ? $state['draft_payload']['nodes'] : [];
                if (isset($nodes[$nodeUid]) && \is_array($nodes[$nodeUid])) {
                    $existingConfig = \is_array($nodes[$nodeUid]['config'] ?? null)
                        ? $nodes[$nodeUid]['config']
                        : [];
                    $config = $this->withWidgetI18nInstance($config, $existingConfig);
                    $layoutWriter->updateWidgetConfig(
                        $context,
                        $nodeUid,
                        $config,
                        'system:theme-layout-service',
                        '',
                    );
                } else {
                    $data['node_uid'] = $nodeUid;
                    $data['config'] = $this->withWidgetI18nInstance($config, []);
                    $layoutWriter->addWidget($context, $data, 'system:theme-layout-service', '');
                }
            } else {
                $data['config'] = $this->withWidgetI18nInstance($config, []);
                $layoutWriter->addWidget($context, $data, 'system:theme-layout-service', '');
            }

            if ($status === ThemeLayout::STATUS_PUBLISHED) {
                $state = $workspace->load($context, true);
                $workspace->publish(
                    $context,
                    (int)($state['revision'] ?? 0),
                    isset($state['expected_parent_release_id'])
                        ? (int)$state['expected_parent_release_id']
                        : null,
                    'system:theme-layout-service',
                    '',
                    'layout_widget_published_via_theme_layout_service',
                );
                $this->purgePublishedLayoutCaches($themeId);
            }

            // No legacy layout_id after greenfield.
            return 0;
        });
    }

    /**
     * 将指定位置及之后的部件 sort_order +1，为新插入腾出位置
     */
    private function shiftSortOrder(int $themeId, string $pageType, string $area, ?string $slotId, int $fromSortOrder, string $status, array $identity = []): void
    {
        if (!$this->legacyLayoutTableExists()) {
            return;
        }
        try {
            $query = $this->themeLayout->clearQuery()
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::schema_fields_AREA, $area)
                ->where(ThemeLayout::schema_fields_STATUS, $status)
                ->where(ThemeLayout::schema_fields_SORT_ORDER, $fromSortOrder, '>=');
            $query = $this->applyLayoutIdentityFilters($query, $identity);

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
    private function removeExclusiveWidgets(int $themeId, string $pageType, string $area, ?string $slotId, string $widgetCode, string $status = ThemeLayout::STATUS_DRAFT, array $identity = []): void
    {
        if (!$this->legacyLayoutTableExists()) {
            return;
        }
        try {
            $query = $this->themeLayout->clearQuery()
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::schema_fields_STATUS, $status);
            $query = $this->applyLayoutIdentityFilters($query, $identity);

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
                $fallbackQuery = $this->themeLayout->reset()
                    ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                    ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                    ->where(ThemeLayout::schema_fields_STATUS, $status)
                    ->where(ThemeLayout::schema_fields_AREA, $slotId); // 旧数据可能把 slotId 存在 area 字段
                $existingWidgets = $this->applyLayoutIdentityFilters($fallbackQuery, $identity)
                    ->select()
                    ->fetch();
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
     * @deprecated Greenfield: theme_layout dropped. Orphan cleanup is scoped-workspace only.
     *
     * @param array<string,mixed> $identity layout_option/scope/target_type/target_id
     */
    public function cleanOrphanWidgets(int $themeId, ?string $pageType = null, array $identity = []): int
    {
        unset($themeId, $pageType, $identity);

        return 0;
    }

    /**
     * 批量保存布局（默认保存为草稿）
     *
     * @param array<string,mixed> $identity layout_option/scope/target_type/target_id
     */
    public function saveLayout(
        int $themeId,
        string $pageType,
        array $layoutData,
        string $status = ThemeLayout::STATUS_DRAFT,
        array $identity = []
    ): bool {
        try {
            return $this->atomicWrite('theme_layout_replace_rows', function () use (
                $themeId,
                $pageType,
                $layoutData,
                $status,
                $identity,
            ): bool {
                $identity = $this->normalizeLayoutIdentity($identity);
                $this->getImageContentValidator()->validate($layoutData, ['phase' => 'save']);

                /** @var ThemeScopedLayoutWriteService $layoutWriter */
                $layoutWriter = ObjectManager::getInstance(ThemeScopedLayoutWriteService::class);
                /** @var ThemeScopedWorkspaceInterface $workspace */
                $workspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
                $context = $this->buildScopedLayoutContext($themeId, $pageType, $identity);

                if (!$this->hasWidgetPlacementsInput($layoutData)) {
                    $this->markNoWidgetPlacements($themeId, $pageType, $status, $identity);
                } else {
                    $snapshot = [];
                    foreach ($layoutData as $area => $widgets) {
                        if (!\is_array($widgets)) {
                            continue;
                        }
                        $normalizedWidgets = [];
                        foreach ($widgets as $index => $widget) {
                            if (!\is_array($widget)) {
                                continue;
                            }
                            if (!isset($widget['sort_order'])) {
                                $widget['sort_order'] = (int)$index;
                            }
                            unset($widget['layout_id']);
                            $normalizedWidgets[] = $widget;
                        }
                        $snapshot[(string)$area] = ['widgets' => \array_values($normalizedWidgets)];
                    }
                    $layoutWriter->replaceDraftFromSnapshot(
                        $context,
                        $snapshot,
                        'system:theme-layout-service',
                        '',
                        'layout_saved_via_theme_layout_service',
                    );
                    if ($status === ThemeLayout::STATUS_PUBLISHED) {
                        $state = $workspace->load($context, true);
                        $workspace->publish(
                            $context,
                            (int)($state['revision'] ?? 0),
                            isset($state['expected_parent_release_id'])
                                ? (int)$state['expected_parent_release_id']
                                : null,
                            'system:theme-layout-service',
                            '',
                            'layout_published_via_theme_layout_service',
                        );
                        $this->purgePublishedLayoutCaches($themeId);
                    }
                }

                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    private function getImageContentValidator(): WidgetImageContentContractValidator
    {
        return $this->imageContentValidator ??= ObjectManager::getInstance(
            WidgetImageContentContractValidator::class,
        );
    }

    /**
     * Copy draft/published widget rows from one layout identity to another.
     *
     * @param array<string,mixed> $sourceIdentity layout_option/scope/target_type/target_id
     * @param array<string,mixed> $targetIdentity layout_option/scope/target_type/target_id
     * @return array{success:bool,status:string,copied:array<string,int>,source_identity:array<string,mixed>,target_identity:array<string,mixed>}
     */
    public function copyLayoutIdentity(
        int $themeId,
        string $pageType,
        array $sourceIdentity,
        array $targetIdentity
    ): array {
        return $this->copyLayoutIdentityBetweenThemes(
            $themeId,
            $themeId,
            $pageType,
            $sourceIdentity,
            $targetIdentity,
        );
    }

    /**
     * Copy an exact layout identity between independently scoped Themes.
     * When targetDraftOnly is true, the effective source draft/published data
     * becomes a target draft and no published target snapshot is created.
     *
     * @param array<string,mixed> $sourceIdentity
     * @param array<string,mixed> $targetIdentity
     * @return array{success:bool,status:string,copied:array<string,int>,source_identity:array<string,mixed>,target_identity:array<string,mixed>}
     */
    public function copyLayoutIdentityBetweenThemes(
        int $sourceThemeId,
        int $targetThemeId,
        string $pageType,
        array $sourceIdentity,
        array $targetIdentity,
        bool $targetDraftOnly = false,
    ): array {
        $sourceIdentity = $this->normalizeLayoutIdentity($sourceIdentity);
        $targetIdentity = $this->normalizeLayoutIdentity($targetIdentity);

        if ($sourceThemeId <= 0 || $targetThemeId <= 0 || trim($pageType) === '') {
            return [
                'success' => false,
                'status' => 'invalid_theme_or_page_type',
                'copied' => [],
                'source_identity' => $sourceIdentity,
                'target_identity' => $targetIdentity,
            ];
        }

        if ($sourceThemeId === $targetThemeId && $sourceIdentity === $targetIdentity) {
            return [
                'success' => false,
                'status' => 'same_identity',
                'copied' => [],
                'source_identity' => $sourceIdentity,
                'target_identity' => $targetIdentity,
            ];
        }

        try {
            /** @var ThemeScopedLayoutWriteService $layoutWriter */
            $layoutWriter = ObjectManager::getInstance(ThemeScopedLayoutWriteService::class);
            /** @var ThemeScopedWorkspaceInterface $workspace */
            $workspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
            $targetContext = $this->buildScopedLayoutContext($targetThemeId, $pageType, $targetIdentity);
            $copied = [];
            $targetStatuses = $targetDraftOnly
                ? [ThemeLayout::STATUS_DRAFT]
                : [ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED];

            foreach ($targetStatuses as $targetStatus) {
                $sourceStatus = $targetStatus;
                if ($targetDraftOnly
                    && !$this->hasActiveWidgetPlacementsForLayout(
                        $sourceThemeId,
                        $pageType,
                        ThemeLayout::STATUS_DRAFT,
                        $sourceIdentity,
                    )
                    && !$this->hasNoWidgetPlacements(
                        $sourceThemeId,
                        $pageType,
                        ThemeLayout::STATUS_DRAFT,
                        $sourceIdentity,
                    )
                ) {
                    $sourceStatus = ThemeLayout::STATUS_PUBLISHED;
                }

                $layout = $this->getLayout(
                    $sourceThemeId,
                    $pageType,
                    $sourceStatus,
                    $sourceIdentity,
                    true,
                );
                $widgetCount = 0;
                foreach ($layout as $area => &$areaData) {
                    if (!\is_array($areaData['widgets'] ?? null)) {
                        $areaData['widgets'] = [];
                        continue;
                    }
                    foreach ($areaData['widgets'] as $index => $widget) {
                        if (!\is_array($widget)) {
                            unset($areaData['widgets'][$index]);
                            continue;
                        }
                        if ($targetDraftOnly) {
                            $widget = $this->markCopiedFileImagesForReview(
                                $widget,
                                (string)($targetIdentity['locale_code'] ?? ''),
                            );
                        }
                        unset($widget['node_uid'], $widget['layout_id'], $widget['meta']);
                        $areaData['widgets'][$index] = $widget;
                        $widgetCount++;
                    }
                    $areaData['widgets'] = \array_values($areaData['widgets']);
                }
                unset($areaData);

                if ($widgetCount === 0) {
                    $layoutWriter->clearDraftNodes(
                        $targetContext,
                        'system:layout-copy',
                        '',
                    );
                    if ($targetStatus === ThemeLayout::STATUS_PUBLISHED && !$targetDraftOnly) {
                        $state = $workspace->load($targetContext, true);
                        $workspace->publish(
                            $targetContext,
                            (int)($state['revision'] ?? 0),
                            isset($state['expected_parent_release_id'])
                                ? (int)$state['expected_parent_release_id']
                                : null,
                            'system:layout-copy',
                            '',
                            'layout_copy_empty_publish',
                        );
                    }
                    $copied[$targetStatus] = 0;
                    continue;
                }

                $stateAfter = $layoutWriter->replaceDraftFromSnapshot(
                    $targetContext,
                    $layout,
                    'system:layout-copy',
                    '',
                    'layout_copied_from_scope:' . $sourceStatus,
                );
                if ($targetStatus === ThemeLayout::STATUS_PUBLISHED && !$targetDraftOnly) {
                    $workspace->publish(
                        $targetContext,
                        (int)($stateAfter['revision'] ?? 0),
                        isset($stateAfter['expected_parent_release_id'])
                            ? (int)$stateAfter['expected_parent_release_id']
                            : null,
                        'system:layout-copy',
                        '',
                        'layout_copy_publish',
                    );
                }
                $copied[$targetStatus] = $widgetCount;
            }

            return [
                'success' => true,
                'status' => 'copied',
                'copied' => $copied,
                'source_identity' => $sourceIdentity,
                'target_identity' => $targetIdentity,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'copy_failed:' . $e->getMessage(),
                'copied' => [],
                'source_identity' => $sourceIdentity,
                'target_identity' => $targetIdentity,
            ];
        }
    }

    /** @param array<string|int,mixed> $value @return array<string|int,mixed> */
    private function markCopiedFileImagesForReview(array $value, string $targetLocale): array
    {
        if (($value['type'] ?? null) === 'file-image' && is_array($value['usage'] ?? null)) {
            $value['usage']['locale_code'] = trim($targetLocale) !== ''
                ? trim($targetLocale)
                : (string)($value['usage']['locale_code'] ?? '');
            $value['usage']['alt_state'] = 'needs_review';
            return $value;
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->markCopiedFileImagesForReview($child, $targetLocale);
            }
        }
        return $value;
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
    public function publishLayout(
        int $themeId,
        ?string $pageType = null,
        array $identity = [],
        bool $allowEmpty = false,
        array $publicationContext = [],
    ): bool
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        try {
            return $this->atomicWrite('theme_layout_publish_rows', function () use (
                $themeId,
                $pageType,
                $identity,
                $allowEmpty,
                $publicationContext,
            ): bool {
                if ($pageType) {
                    $pageTypes = [$pageType];
                } else {
                    $pageTypes = array_keys(ThemeLayout::getPageTypes());
                }

                /** @var ThemeScopedWorkspaceInterface $workspace */
                $workspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
                /** @var ThemeScopedLayoutWriteService $layoutWriter */
                $layoutWriter = ObjectManager::getInstance(ThemeScopedLayoutWriteService::class);

                foreach ($pageTypes as $type) {
                    $draftLayout = $this->getLayout(
                        $themeId,
                        $type,
                        ThemeLayout::STATUS_DRAFT,
                        $identity,
                        true,
                    );

                    $hasDraftWidgets = false;
                    foreach ($draftLayout as $areaData) {
                        if (!empty($areaData['widgets'])) {
                            $hasDraftWidgets = true;
                            break;
                        }
                    }

                    $this->getContentValidationRegistry()->validate(
                        $draftLayout,
                        array_replace($publicationContext, [
                            'phase' => 'publish',
                            'theme_id' => $themeId,
                            'page_type' => $type,
                            'layout_identity' => $identity,
                        ]),
                    );

                    if (!$hasDraftWidgets && !$allowEmpty) {
                        continue;
                    }

                    $context = $this->buildScopedLayoutContext($themeId, (string)$type, $identity);
                    if (!$hasDraftWidgets && $allowEmpty) {
                        $layoutWriter->clearDraftNodes($context, 'system:theme-layout-service', '');
                    }

                    $state = $workspace->load($context, true);
                    if ((int)($state['revision'] ?? 0) <= 0 && !$allowEmpty) {
                        continue;
                    }

                    $workspace->publish(
                        $context,
                        (int)($state['revision'] ?? 0),
                        isset($state['expected_parent_release_id'])
                            ? (int)$state['expected_parent_release_id']
                            : null,
                        'system:theme-layout-service',
                        '',
                        'layout_published_via_theme_layout_service',
                    );
                }

                $this->purgePublishedLayoutCaches($themeId);

                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    private function getContentValidationRegistry(): LayoutContentValidationRegistry
    {
        return $this->contentValidationRegistry ??= ObjectManager::getInstance(
            LayoutContentValidationRegistry::class,
        );
    }

    /**
     * 发布布局后清理前端路由/FPC/共享内存中的旧版 slot 与整页缓存，避免 Worker 继续输出重复或过期部件。
     */
    private function purgePublishedLayoutCaches(int $themeId): void
    {
        try {
            ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class)
                ->clearNonGlobalCaches($themeId > 0 ? $themeId : null, 'theme_layout_publish');
        } catch (\Throwable) {
        }
    }

    /**
     * 检查主题是否有草稿（未发布的修改）
     */
    public function hasDraft(int $themeId, ?string $pageType = null, array $identity = []): bool
    {
        if ($pageType === null || $pageType === '') {
            return false;
        }
        try {
            $context = $this->buildScopedLayoutContext($themeId, $pageType, $identity);
            /** @var ThemeScopedWorkspaceInterface $workspace */
            $workspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
            $state = $workspace->load($context, true);

            return (int)($state['revision'] ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 撤销草稿：删除所有草稿，恢复到已发布状态
     */
    public function discardDraft(int $themeId, ?string $pageType = null, array $identity = []): bool
    {
        $identity = $identity !== [] ? $this->normalizeLayoutIdentity($identity) : [];
        try {
            /** @var ThemeScopedLayoutWriteService $layoutWriter */
            $layoutWriter = ObjectManager::getInstance(ThemeScopedLayoutWriteService::class);
            $pageTypes = $pageType ? [$pageType] : array_keys(ThemeLayout::getPageTypes());
            foreach ($pageTypes as $type) {
                $context = $this->buildScopedLayoutContext($themeId, (string)$type, $identity);
                $layoutWriter->clearDraftNodes($context, 'system:theme-layout-service', '');
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @deprecated Theme 2.2 greenfield: callers should load ThemeScopedWorkspace directly.
     * Kept as a thin scoped workspace warm-up; does not copy theme_layout rows.
     */
    public function initDraftFromPublished(int $themeId, ?string $pageType = null, array $identity = []): bool
    {
        $identity = $identity !== [] ? $this->normalizeLayoutIdentity($identity) : [];
        try {
            /** @var ThemeScopedWorkspaceInterface $workspace */
            $workspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
            $pageTypes = $pageType ? [$pageType] : array_keys(ThemeLayout::getPageTypes());
            foreach ($pageTypes as $type) {
                if ($this->hasDraft($themeId, (string)$type, $identity)) {
                    continue;
                }
                $context = $this->buildScopedLayoutContext($themeId, (string)$type, $identity);
                // Parent release merge happens inside workspace load — no theme_layout copy.
                $workspace->load($context, true);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @deprecated Greenfield: theme_layout dropped. Patch widget config via ThemeScopedLayoutWriteService + node_uid.
     */
    public function updateWidgetConfig(int $layoutId, array $config): bool
    {
        unset($layoutId, $config);

        return false;
    }

    private function withWidgetI18nInstance(array $config, array $existingConfig = []): array
    {
        $key = self::WIDGET_I18N_INSTANCE_KEY;
        $instance = trim((string)($config[$key] ?? ''));
        if ($instance === '') {
            $instance = trim((string)($existingConfig[$key] ?? ''));
        }
        if ($instance === '') {
            $instance = 'wi_' . bin2hex(random_bytes(8));
        }

        $config[$key] = $instance;
        return $config;
    }

    /**
     * @deprecated Greenfield: theme_layout dropped. Remove nodes via ThemeScopedLayoutWriteService::removeWidget(node_uid).
     */
    public function deleteWidget(int $layoutId): bool
    {
        unset($layoutId);

        return false;
    }

    /**
     * @deprecated Greenfield: theme_layout dropped. Template tombstones live in scoped draft nodes.
     *
     * @param array<string,mixed> $identity
     */
    public function clearTemplateDeletedTombstonesForSlot(
        int $themeId,
        string $pageType,
        array $identity,
        string $slotId,
        string $status = ThemeLayout::STATUS_DRAFT,
    ): int {
        unset($themeId, $pageType, $identity, $slotId, $status);

        return 0;
    }

    /**
     * @deprecated Greenfield: theme_layout dropped. Resolve widgets by node_uid from scoped workspace.
     */
    public function getWidgetByLayoutId(int $layoutId): ?array
    {
        unset($layoutId);

        return null;
    }

    /**
     * @deprecated Greenfield: theme_layout dropped. Reorder via scoped layout patch /update-sort.
     */
    public function updateSortOrder(array $sortData): bool
    {
        return $sortData === [];
    }

    /**
     * @deprecated Greenfield: theme_layout dropped. Move via ThemeScopedLayoutWriteService.
     */
    public function moveWidget(int $layoutId, string $newArea, int $newSortOrder): bool
    {
        unset($layoutId, $newArea, $newSortOrder);

        return false;
    }

    /**
     * @deprecated Greenfield: theme_layout dropped. Swap via scoped node_uid patch.
     */
    public function swapWidgetOrder(int $layoutId1, int $layoutId2): bool
    {
        unset($layoutId1, $layoutId2);

        return false;
    }

    /**
     * @deprecated Greenfield: theme_layout dropped. Read slot widgets from scoped draft/release.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getSlotWidgets(int $themeId, string $pageType, string $slotId, string $status = 'draft', array $identity = []): array
    {
        unset($themeId, $pageType, $slotId, $status, $identity);

        return [];
    }

    /**
     * @deprecated Greenfield: theme_layout dropped. Reorder via scoped layout patch.
     */
    public function updateSlotWidgetsOrder(array $layoutIds): bool
    {
        return $layoutIds === [];
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
        'delivery' => 'header',
        'search' => 'header',
        'navigation' => 'header',
        'category-menu' => 'header',
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
    public function getAvailableWidgets(
        ?string $pageType = null,
        ?array $filterOptions = null,
        string $area = 'frontend',
        ?WelineTheme $theme = null
    ): array
    {
        $effectiveArea = (string)($filterOptions['editor_area'] ?? $filterOptions['registry_area'] ?? $area);
        $effectiveArea = $effectiveArea === 'backend' ? 'backend' : 'frontend';
        if ($filterOptions !== null) {
            $filterOptions['editor_area'] = $effectiveArea;
        }

        return $this->placeableRegistry->getAvailableList($pageType, $filterOptions, $theme, $effectiveArea);
    }
    
    /**
     * 获取指定 slot 的推荐部件
     * 
     * @param string $slotId slot ID
     * @param string|null $area 区域代码
     * @param string|null $pageType 页面类型
     * @return array 包含 exclusive_widgets 和 regular_widgets 两个数组
     */
    public function getWidgetsForSlot(
        string $slotId,
        ?string $area = null,
        ?string $pageType = null,
        array $acceptCodes = [],
        array $rejectCodes = [],
        ?WelineTheme $theme = null,
        string $editorArea = 'frontend',
        array $libraryFilterOptions = []
    ): array
    {
        // 判断是否是子 slot
        $isSubSlot = isset(self::SUB_SLOTS_MAP[$slotId]);
        $parentArea = $isSubSlot ? self::SUB_SLOTS_MAP[$slotId] : null;
        $effectiveArea = $area ?? $parentArea ?? $slotId;
        
        // 检查是否是独占区域
        $isExclusiveArea = in_array($effectiveArea, self::EXCLUSIVE_AREAS);
        
        // 获取所有部件，支持 slot accept/reject 与部件 code/type/slot/position/slots 的协议交叉过滤
        $allWidgets = $this->getAvailableWidgets($pageType, array_merge($libraryFilterOptions, [
            'slot_id' => $slotId,
            'area' => $effectiveArea,
            'accept' => $acceptCodes,
            'reject' => $rejectCodes,
            'editor_area' => $editorArea === 'backend' ? 'backend' : 'frontend',
        ]), $editorArea, $theme);
        
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

    private function loadLayoutForUpdate(int $layoutId): ThemeLayout
    {
        if ($layoutId < 1) {
            throw new \InvalidArgumentException((string)__('Theme 布局节点不存在。'));
        }
        $query = clone $this->themeLayout;
        $query->clearQuery()->clearData()
            ->where(ThemeLayout::schema_fields_ID, $layoutId)
            ->limit(1);
        $dbType = strtolower((string)$query->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        if (in_array($dbType, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true)) {
            $query->additional('FOR UPDATE');
        }
        $items = array_values($query->select()->fetch()->getItems());
        $layout = $items[0] ?? null;
        if (!$layout instanceof ThemeLayout || $layout->getLayoutId() !== $layoutId) {
            throw new \InvalidArgumentException((string)__('Theme 布局节点不存在。'));
        }
        return $layout;
    }

    /** @param array<string,mixed> $identity */
    private function assertLayoutIdentityMatches(
        ThemeLayout $layout,
        int $themeId,
        string $pageType,
        string $status,
        array $identity,
    ): void {
        if ($themeId < 1
            || $layout->getThemeId() !== $themeId
            || !hash_equals($layout->getPageType(), $pageType)
            || !hash_equals($layout->getLayoutOption(), (string)$identity['layout_option'])
            || !hash_equals($layout->getScope(), (string)$identity['scope'])
            || !hash_equals($layout->getLocaleCode(), (string)$identity['locale_code'])
            || !hash_equals($layout->getTargetType(), (string)$identity['target_type'])
            || $layout->getTargetId() !== (int)$identity['target_id']
            || !hash_equals($layout->getStatus(), $status)
        ) {
            throw new \RuntimeException((string)__('Theme 布局节点不属于当前编辑上下文。'));
        }
    }

    /** @template T @param callable():T $operation @return T */
    private function atomicWrite(string $savepoint, callable $operation): mixed
    {
        $transactions = $this->transactions ??= ObjectManager::getInstance(
            WriteIntentTransactionCoordinatorInterface::class,
        );
        $connection = $this->themeLayout->getConnection();
        if ($transactions->isActive($connection)) {
            if (!$transactions->isWriteIntent($connection)) {
                throw new \LogicException((string)__('Theme 布局写入必须位于写意图事务内。'));
            }
            return $transactions->withSavepoint($connection, $savepoint, $operation);
        }
        return $transactions->runWrite($connection, $operation);
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
            'category-menu',
            'footer',
            'footer-social',
            'footer-copyright',
            'widget-hero',
            'list-grid',
            'list-pagination',
        ];

        return \in_array($slotId, $exclusiveSlots, true);
    }

    /** @param array<string,mixed> $identity */
    private function getScopedLayout(
        int $themeId,
        string $pageType,
        string $status,
        array $identity,
    ): array {
        /** @var ThemeRuntimeLayoutResolver $resolver */
        $resolver = ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class);

        return $resolver->resolveLayout(
            $themeId,
            $pageType,
            $status,
            $this->resolveRuntimeLayoutArea($pageType),
            // 空身份由运行时统一读取已安装的 LayoutIdentity；显式身份保留原有规范化。
            $identity === [] ? [] : $this->normalizeLayoutIdentity($identity),
        );
    }

    /** @param array<string,mixed> $identity */
    private function buildScopedLayoutContext(int $themeId, string $pageType, array $identity): ThemeEditorContext
    {
        /** @var ThemeRuntimeLayoutResolver $resolver */
        $resolver = ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class);

        return $resolver->buildContext(
            $themeId,
            $pageType,
            $this->resolveRuntimeLayoutArea($pageType),
            $this->normalizeLayoutIdentity($identity),
        )->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
    }

    private function resolveRuntimeLayoutArea(string $pageType): string
    {
        return $pageType === ThemeLayout::PAGE_TYPE_DASHBOARD ? 'backend' : 'frontend';
    }
}
