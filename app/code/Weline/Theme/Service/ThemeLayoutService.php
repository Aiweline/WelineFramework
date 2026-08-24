<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Interface\ThemePlaceableRegistryInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Widget\Api\WidgetRegistryInterface;

/**
 * 主题布局服务
 * 管理主题的部件布局配置
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
        return $localeCode === '' ? [''] : [$localeCode, ''];
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
        $query = $this->themeLayout->clearQuery()->clearData()
            ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
            ->where(ThemeLayout::schema_fields_STATUS, $status);
        if ($identity !== null) {
            $query = $this->applyLayoutIdentityFilters($query, $this->normalizeLayoutIdentity($identity));
        }
        $rows = $query->select()->fetchArray();

        $layoutIds = [];
        foreach ((array)$rows as $row) {
            $layoutId = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
            if ($layoutId > 0) {
                $layoutIds[$layoutId] = true;
            }
        }

        foreach (array_keys($layoutIds) as $layoutId) {
            $this->themeLayout->clearQuery()->clearData()->load($layoutId)->delete();
        }
        $this->themeLayout->clearQuery()->clearData();

        return count($layoutIds);
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
        if ($this->hasNoWidgetPlacements($themeId, $pageType, $status, $identity)) {
            return;
        }

        $this->themeLayout->clearQuery()->clearData()
            ->setThemeId($themeId)
            ->setPageType($pageType)
            ->setLayoutOption($identity['layout_option'])
            ->setScope($identity['scope'])
            ->setLocaleCode($identity['locale_code'])
            ->setTargetType($identity['target_type'])
            ->setTargetId($identity['target_id'])
            ->setArea(ThemeLayout::AREA_CONTENT)
            ->setSlotId(null)
            ->setWidgetCode(self::NO_PLACEMENTS_WIDGET_CODE)
            ->setWidgetModule(self::NO_PLACEMENTS_WIDGET_MODULE)
            ->setWidgetType(self::NO_PLACEMENTS_WIDGET_TYPE)
            ->setWidgetConfig(['no_widget_placements' => true])
            ->setSortOrder(0)
            ->setIsActive(false)
            ->setStatus($status)
            ->save();
        $this->themeLayout->clearQuery()->clearData();
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function deleteNoWidgetPlacementsMarker(int $themeId, string $pageType, string $status, array $identity): void
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        $query = $this->themeLayout->clearQuery()->clearData()
            ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
            ->where(ThemeLayout::schema_fields_STATUS, $status)
            ->where(ThemeLayout::schema_fields_WIDGET_MODULE, self::NO_PLACEMENTS_WIDGET_MODULE)
            ->where(ThemeLayout::schema_fields_WIDGET_TYPE, self::NO_PLACEMENTS_WIDGET_TYPE)
            ->where(ThemeLayout::schema_fields_WIDGET_CODE, self::NO_PLACEMENTS_WIDGET_CODE);
        $query = $this->applyLayoutIdentityFilters($query, $identity);
        $rows = $query->select()->fetchArray();

        foreach ((array)$rows as $row) {
            $layoutId = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
            if ($layoutId > 0) {
                $this->themeLayout->clearQuery()->clearData()->load($layoutId)->delete();
            }
        }
        $this->themeLayout->clearQuery()->clearData();
    }

    /**
     * @param array<string,mixed> $identity
     */
    public function hasNoWidgetPlacements(int $themeId, string $pageType, string $status, array $identity = []): bool
    {
        try {
            $identity = $this->normalizeLayoutIdentity($identity);
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
                    // Any row at the nearest exact/legacy identity owns the
                    // layout. Do not inspect a farther neutral or parent row.
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
        // 按区域分组
        $groupedLayout = [];
        foreach (ThemeLayout::getAreas() as $areaCode => $areaLabel) {
            $groupedLayout[$areaCode] = [
                'label' => $areaLabel,
                'widgets' => [],
            ];
        }

        try {
            $normalizedIdentity = $this->normalizeLayoutIdentity($identity);
            $layouts = [];
            foreach ($this->getLayoutScopeNormalizer()->readFallbackScopes($normalizedIdentity['scope']) as $scope) {
                foreach ($this->localeReadCandidates($normalizedIdentity['locale_code']) as $localeCode) {
                    $candidateIdentity = $normalizedIdentity;
                    $candidateIdentity['scope'] = $scope;
                    $candidateIdentity['locale_code'] = $localeCode;
                    $query = $this->themeLayout->reset()
                        ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                        ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                        ->where(ThemeLayout::schema_fields_STATUS, $status);

                    $candidateRows = $this->applyNormalizedLayoutIdentityFilters($query, $candidateIdentity)
                        ->order(ThemeLayout::schema_fields_AREA, 'ASC')
                        ->order(ThemeLayout::schema_fields_SORT_ORDER, 'ASC')
                        ->order(ThemeLayout::schema_fields_ID, 'ASC')
                        ->select()
                        ->fetchArray();
                    if (!is_array($candidateRows) || $candidateRows === []) {
                        continue;
                    }

                    // Any exact rows establish ownership. An all-inactive set
                    // is the explicit empty-layout tombstone and must stop both
                    // legacy-locale and parent-Scope fallback.
                    $layouts = array_values(array_filter(
                        $candidateRows,
                        static fn(mixed $row): bool => is_array($row)
                            && (int)($row[ThemeLayout::schema_fields_IS_ACTIVE] ?? 0) === 1,
                    ));
                    break 2;
                }
            }

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
                        'node_uid' => $layout[ThemeLayout::schema_fields_NODE_UID] ?? null,
                        'area' => $area,
                        'page_type' => $layout[ThemeLayout::schema_fields_PAGE_TYPE] ?? $pageType,
                        'widget_code' => $layout[ThemeLayout::schema_fields_WIDGET_CODE] ?? '',
                        'widget_module' => $layout[ThemeLayout::schema_fields_WIDGET_MODULE] ?? '',
                        'widget_type' => $layout[ThemeLayout::schema_fields_WIDGET_TYPE] ?? '',
                        'slot_id' => $layout[ThemeLayout::schema_fields_SLOT_ID] ?? null,
                        'layout_option' => $layout[ThemeLayout::schema_fields_LAYOUT_OPTION] ?? 'default',
                        'scope' => $layout[ThemeLayout::schema_fields_SCOPE] ?? 'default',
                        'locale_code' => $layout[ThemeLayout::schema_fields_LOCALE_CODE] ?? '',
                        'target_type' => $layout[ThemeLayout::schema_fields_TARGET_TYPE] ?? 'global',
                        'target_id' => (int)($layout[ThemeLayout::schema_fields_TARGET_ID] ?? 0),
                        'config' => is_string($config) ? json_decode($config, true) : $config,
                        'sort_order' => $layout[ThemeLayout::schema_fields_SORT_ORDER] ?? 0,
                        'status' => $layout[ThemeLayout::schema_fields_STATUS] ?? $status,
                    ];
                }
            }
        } catch (\Throwable $throwable) {
            if ($strict) {
                throw new \RuntimeException((string)__('Theme 布局读取失败。'), 0, $throwable);
            }
            // Legacy storefront reads remain tolerant while an optional Theme
            // table is not installed. Mutation/publication boundaries must use
            // strict=true so a database failure is never interpreted as an
            // intentional empty layout.
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
        $layoutId = $data['layout_id'] ?? 0;
        $slotId = $data['slot_id'] ?? null;
        $exclusive = (bool)($data['exclusive'] ?? false);
        $status = $data['status'] ?? ThemeLayout::STATUS_DRAFT;
        $sortOrder = (int)($data['sort_order'] ?? 0);
        $identity = $this->normalizeLayoutIdentity($data);
        $pageType = $data['page_type'] ?? ThemeLayout::PAGE_TYPE_DEFAULT;
        $isNoPlacementsMarker = (string)($data['widget_module'] ?? '') === self::NO_PLACEMENTS_WIDGET_MODULE
            && (string)($data['widget_type'] ?? '') === self::NO_PLACEMENTS_WIDGET_TYPE
            && (string)($data['widget_code'] ?? '') === self::NO_PLACEMENTS_WIDGET_CODE;
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];

        // saveWidget() is a public persistence path used by editor endpoints,
        // seeders, version restores and compatibility callers. Enforce the
        // same typed-image contract before shifting/deleting any durable rows.
        if (!$isNoPlacementsMarker) {
            $this->getImageContentValidator()->validate([
                (string)($data['area'] ?? '') => [[
                    'widget_module' => (string)($data['widget_module'] ?? ''),
                    'widget_type' => (string)($data['widget_type'] ?? ''),
                    'widget_code' => (string)($data['widget_code'] ?? ''),
                    'config' => $config,
                ]],
            ], ['phase' => 'save']);
        }

        // 如果是独占插槽，先删除该插槽/区域中相同类型的部件（仅限同状态）
        if ($exclusive && !$layoutId) {
            $this->removeExclusiveWidgets(
                (int)$data['theme_id'],
                $pageType,
                $data['area'],
                $slotId,
                $data['widget_code'],
                $status,
                $identity
            );
        }

        // 非独占插入：将插入位置及之后的部件 sort_order +1，为新部件腾出位置
        if (!$exclusive && !$layoutId) {
            $this->shiftSortOrder(
                (int)$data['theme_id'],
                $pageType,
                $data['area'],
                $slotId,
                $sortOrder,
                $status,
                $identity
            );
        }

        if (!$layoutId && !$isNoPlacementsMarker) {
            $this->deleteNoWidgetPlacementsMarker((int)$data['theme_id'], (string)$pageType, (string)$status, $identity);
        }

        if ($layoutId) {
            // Lock and seal the persisted target identity. A caller may edit
            // config/placement, but cannot move an arbitrary layout_id across
            // Website/Store/locale/target boundaries.
            $layout = $this->loadLayoutForUpdate((int)$layoutId);
            $this->assertLayoutIdentityMatches(
                $layout,
                (int)($data['theme_id'] ?? 0),
                (string)$pageType,
                (string)$status,
                $identity,
            );
            $existingConfig = $layout->getWidgetConfig();
        } else {
            $layout = clone $this->themeLayout;
            $layout->clearQuery()->clearData();
            $existingConfig = [];
        }

        $config = $this->withWidgetI18nInstance($config, $existingConfig);

        $layout = $layout
            ->setThemeId((int)$data['theme_id'])
            ->setPageType($pageType)
            ->setLayoutOption($identity['layout_option'])
            ->setScope($identity['scope'])
            ->setLocaleCode($identity['locale_code'])
            ->setTargetType($identity['target_type'])
            ->setTargetId($identity['target_id'])
            ->setArea($data['area'])
            ->setSlotId($slotId)
            ->setWidgetCode($data['widget_code'])
            ->setWidgetModule($data['widget_module'])
            ->setWidgetType($data['widget_type'] ?? '')
            ->setWidgetConfig($config)
            ->setSortOrder($sortOrder)
            ->setIsActive((bool)($data['is_active'] ?? true))
            ->setStatus($status);
        $nodeUid = \strtolower(\trim((string)($data['node_uid'] ?? '')));
        if ($nodeUid !== '') {
            $layout->setNodeUid($nodeUid);
        }
        $layout->save();

        return $layout->getLayoutId();
        });
    }

    /**
     * 将指定位置及之后的部件 sort_order +1，为新插入腾出位置
     */
    private function shiftSortOrder(int $themeId, string $pageType, string $area, ?string $slotId, int $fromSortOrder, string $status, array $identity = []): void
    {
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
     * 清理孤儿部件。
     *
     * 只对独占插槽做同 slot 收敛；普通业务插槽允许多个部件共存，比如 Dashboard 的
     * dashboard-summary/dashboard-analysis/dashboard-side/dashboard-detail。
     *
     * @param array<string,mixed> $identity layout_option/scope/target_type/target_id
     */
    public function cleanOrphanWidgets(int $themeId, ?string $pageType = null, array $identity = []): int
    {
        $cleaned = 0;
        $identity = $this->normalizeLayoutIdentity($identity);
        
        try {
            $pageTypes = $pageType ? [$pageType] : array_keys(ThemeLayout::getPageTypes());
            
            foreach ($pageTypes as $type) {
                foreach ([ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED] as $status) {
                    $layout = $this->getLayout($themeId, $type, $status, $identity);
                    
                    foreach ($layout as $area => $areaData) {
                        $slotWidgets = []; // slot_id => [layout_ids...]
                        
                        foreach ($areaData['widgets'] as $widget) {
                            $slotId = $widget['slot_id'] ?? '';
                            if (empty($slotId) || !$this->isExclusivePublishSlot((string)$slotId)) {
                                continue;
                            }
                            
                            $slotWidgets[$slotId][] = $widget['layout_id'];
                        }
                        
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
        return $this->atomicWrite('theme_layout_replace_rows', function () use (
            $themeId,
            $pageType,
            $layoutData,
            $status,
            $identity,
        ): bool {
            $identity = $this->normalizeLayoutIdentity($identity);
            // Reject new URL-backed image fields and dynamic <img src> markup before
            // deleting the previous durable snapshot. Full asset/access/alt checks
            // remain publication-time concerns because a draft may need review.
            $this->getImageContentValidator()->validate($layoutData, ['phase' => 'save']);

            // 先删除该页面该状态的所有布局
            $this->deleteLayoutRows($themeId, $pageType, $status, $identity);

            if (!$this->hasWidgetPlacementsInput($layoutData)) {
                $this->markNoWidgetPlacements($themeId, $pageType, $status, $identity);
                return true;
            }

            // 保存新布局
            foreach ($layoutData as $area => $widgets) {
                foreach ($widgets as $index => $widget) {
                    $this->saveWidget([
                        'theme_id' => $themeId,
                        'page_type' => $pageType,
                        'layout_option' => $identity['layout_option'],
                        'scope' => $identity['scope'],
                        'locale_code' => $identity['locale_code'],
                        'target_type' => $identity['target_type'],
                        'target_id' => $identity['target_id'],
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
        });
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

        return $this->atomicWrite('theme_layout_copy_identity', function () use (
            $sourceThemeId,
            $targetThemeId,
            $pageType,
            $sourceIdentity,
            $targetIdentity,
            $targetDraftOnly,
        ): array {
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
                $layoutData = [];
                foreach ($layout as $area => $areaData) {
                    $widgets = is_array($areaData['widgets'] ?? null) ? $areaData['widgets'] : [];
                    foreach ($widgets as $widget) {
                        if (!is_array($widget)) {
                            continue;
                        }
                        $layoutData[$area][] = [
                            'widget_code' => (string)($widget['widget_code'] ?? ''),
                            'widget_module' => (string)($widget['widget_module'] ?? ''),
                            'widget_type' => (string)($widget['widget_type'] ?? ''),
                            'slot_id' => $widget['slot_id'] ?? null,
                            'config' => is_array($widget['config'] ?? null) ? $widget['config'] : [],
                            'sort_order' => (int)($widget['sort_order'] ?? 0),
                            'is_active' => (bool)($widget['is_active'] ?? true),
                        ];
                    }
                }

                if ($layoutData === []) {
                    if ($this->hasNoWidgetPlacements($sourceThemeId, $pageType, $sourceStatus, $sourceIdentity)) {
                        $this->saveLayout($targetThemeId, $pageType, [], $targetStatus, $targetIdentity);
                    }
                    $copied[$targetStatus] = 0;
                    continue;
                }

                if ($targetDraftOnly) {
                    $layoutData = $this->markCopiedFileImagesForReview(
                        $layoutData,
                        (string)($targetIdentity['locale_code'] ?? ''),
                    );
                }

                $this->saveLayout($targetThemeId, $pageType, $layoutData, $targetStatus, $targetIdentity);
                $copied[$targetStatus] = array_sum(array_map('count', $layoutData));
            }

            return [
                'success' => true,
                'status' => 'copied',
                'copied' => $copied,
                'source_identity' => $sourceIdentity,
                'target_identity' => $targetIdentity,
            ];
        });
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
                // 获取需要发布的页面类型列表
                if ($pageType) {
                    $pageTypes = [$pageType];
                } else {
                    $pageTypes = array_keys(ThemeLayout::getPageTypes());
                }

                foreach ($pageTypes as $type) {
                    // 1. 获取草稿布局
                    $draftLayout = $this->getLayout(
                        $themeId,
                        $type,
                        ThemeLayout::STATUS_DRAFT,
                        $identity,
                        true,
                    );

                    // 检查草稿是否有数据
                    $hasDraftWidgets = false;
                    foreach ($draftLayout as $area => $areaData) {
                        if (!empty($areaData['widgets'])) {
                            $hasDraftWidgets = true;
                            break;
                        }
                    }

                    // Publication validation is enforced at the lowest durable
                    // projection boundary as well as by higher-level workspaces.
                    // A typed file-image therefore cannot be published through a
                    // legacy caller that omitted explicit Scope/locale context.
                    $this->getContentValidationRegistry()->validate(
                        $draftLayout,
                        array_replace($publicationContext, [
                            'phase' => 'publish',
                            'theme_id' => $themeId,
                            'page_type' => $type,
                            'layout_identity' => $identity,
                        ]),
                    );

                    // 如果没有草稿数据，保持已发布数据不变
                    if (!$hasDraftWidgets && !$allowEmpty) {
                        continue;
                    }

                    // 2. 删除旧的已发布记录（全量替换，避免残留）
                    $this->deleteLayoutRows($themeId, $type, ThemeLayout::STATUS_PUBLISHED, $identity);
                    if (!$hasDraftWidgets) {
                        $this->markNoWidgetPlacements($themeId, $type, ThemeLayout::STATUS_PUBLISHED, $identity);
                        continue;
                    }

                    // 3. 去重：独占插槽按 slot_id；其余只去掉完全重复的脏草稿/快照记录。
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
                                    . ($widget['widget_code'] ?? '') . '::'
                                    . ((int)($widget['sort_order'] ?? 0)) . '::'
                                    . sha1(json_encode($widget['config'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
                                if (isset($widgetIdentitySeen[$identityKey])) {
                                    continue;
                                }
                                $widgetIdentitySeen[$identityKey] = true;
                            }

                            $this->saveWidget([
                                'theme_id' => $themeId,
                                'page_type' => $type,
                                'layout_option' => $identity['layout_option'],
                                'scope' => $identity['scope'],
                                'locale_code' => $identity['locale_code'],
                                'target_type' => $identity['target_type'],
                                'target_id' => $identity['target_id'],
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
        try {
            $query = $this->themeLayout->reset()
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_STATUS, ThemeLayout::STATUS_DRAFT);

            if ($pageType) {
                $query->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType);
            }

            if ($identity !== []) {
                $query = $this->applyLayoutIdentityFilters($query, $identity);
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
    public function discardDraft(int $themeId, ?string $pageType = null, array $identity = []): bool
    {
        try {
            $identityFilter = $identity !== [] ? $this->normalizeLayoutIdentity($identity) : null;
            if ($pageType) {
                $this->deleteLayoutRows((int)$themeId, $pageType, ThemeLayout::STATUS_DRAFT, $identityFilter);
            } else {
                foreach (array_keys(ThemeLayout::getPageTypes()) as $type) {
                    $this->deleteLayoutRows((int)$themeId, $type, ThemeLayout::STATUS_DRAFT, $identityFilter);
                }
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 初始化草稿：从已发布状态复制到草稿状态
     * 用于首次编辑时，将线上数据复制为草稿进行编辑
     */
    public function initDraftFromPublished(int $themeId, ?string $pageType = null, array $identity = []): bool
    {
        $identity = $identity !== [] ? $this->normalizeLayoutIdentity($identity) : [];
        try {
            $pageTypes = $pageType ? [$pageType] : array_keys(ThemeLayout::getPageTypes());

            foreach ($pageTypes as $type) {
                // 检查是否已有草稿
                if ($this->hasDraft($themeId, $type, $identity)) {
                    continue; // 已有草稿，跳过
                }

                // 获取已发布布局
                $publishedLayout = $this->getLayout($themeId, $type, ThemeLayout::STATUS_PUBLISHED, $identity);

                // 复制为草稿
                foreach ($publishedLayout as $area => $areaData) {
                    foreach ($areaData['widgets'] as $widget) {
                        $this->saveWidget([
                            'theme_id' => $themeId,
                            'page_type' => $type,
                            'layout_option' => $identity['layout_option'] ?? ($widget['layout_option'] ?? 'default'),
                            'scope' => $identity['scope'] ?? ($widget['scope'] ?? 'default'),
                            'locale_code' => $identity['locale_code'] ?? ($widget['locale_code'] ?? ''),
                            'target_type' => $identity['target_type'] ?? ($widget['target_type'] ?? 'global'),
                            'target_id' => $identity['target_id'] ?? (int)($widget['target_id'] ?? 0),
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

        $config = $this->withWidgetI18nInstance($config, $this->themeLayout->getWidgetConfig());
        $this->themeLayout->setWidgetConfig($config)->save();
        return true;
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
     * 删除部件
     */
    public function deleteWidget(int $layoutId): bool
    {
        $this->themeLayout->clearQuery()->clearData()->load($layoutId);
        $loadedId = $this->themeLayout->getLayoutId();

        if (!$loadedId) {
            return false;
        }
        $themeId = (int)$this->themeLayout->getData(ThemeLayout::schema_fields_THEME_ID);
        $pageType = (string)$this->themeLayout->getData(ThemeLayout::schema_fields_PAGE_TYPE);
        $status = (string)$this->themeLayout->getData(ThemeLayout::schema_fields_STATUS);
        $identity = $this->normalizeLayoutIdentity([
            'layout_option' => $this->themeLayout->getData(ThemeLayout::schema_fields_LAYOUT_OPTION),
            'scope' => $this->themeLayout->getData(ThemeLayout::schema_fields_SCOPE),
            'locale_code' => $this->themeLayout->getData(ThemeLayout::schema_fields_LOCALE_CODE),
            'target_type' => $this->themeLayout->getData(ThemeLayout::schema_fields_TARGET_TYPE),
            'target_id' => $this->themeLayout->getData(ThemeLayout::schema_fields_TARGET_ID),
        ]);
        $wasNoPlacementsMarker = (string)$this->themeLayout->getData(ThemeLayout::schema_fields_WIDGET_MODULE) === self::NO_PLACEMENTS_WIDGET_MODULE
            && (string)$this->themeLayout->getData(ThemeLayout::schema_fields_WIDGET_TYPE) === self::NO_PLACEMENTS_WIDGET_TYPE
            && (string)$this->themeLayout->getData(ThemeLayout::schema_fields_WIDGET_CODE) === self::NO_PLACEMENTS_WIDGET_CODE;

        // 使用模型已加载状态执行删除（getQuery() 会带表名，delete() 用主键条件，避免 clearQuery 后链式导致表名/条件丢失）
        $this->themeLayout->delete()->fetch();

        // 验证删除结果
        $this->themeLayout->clearQuery()->clearData();
        $checkAfter = $this->themeLayout->clearQuery()->clearData()
            ->where(ThemeLayout::schema_fields_ID, $layoutId)
            ->select()
            ->fetchArray();
        $this->themeLayout->clearQuery()->clearData();

        $deleted = empty($checkAfter);
        if ($deleted
            && !$wasNoPlacementsMarker
            && $themeId > 0
            && $pageType !== ''
            && $status !== ''
            && !$this->hasActiveWidgetPlacementsForLayout($themeId, $pageType, $status, $identity)
        ) {
            $this->markNoWidgetPlacements($themeId, $pageType, $status, $identity);
        }

        return $deleted;
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
            'slot_id' => $this->themeLayout->getSlotId(),
            'sort_order' => $this->themeLayout->getSortOrder(),
            'layout_option' => $this->themeLayout->getLayoutOption(),
            'scope' => $this->themeLayout->getScope(),
            'locale_code' => $this->themeLayout->getLocaleCode(),
            'target_type' => $this->themeLayout->getTargetType(),
            'target_id' => $this->themeLayout->getTargetId(),
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
    public function getSlotWidgets(int $themeId, string $pageType, string $slotId, string $status = 'draft', array $identity = []): array
    {
        $query = $this->themeLayout->reset()
            ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
            ->where(ThemeLayout::schema_fields_STATUS, $status)
            ->where(ThemeLayout::schema_fields_SLOT_ID, $slotId);

        $layouts = $this->applyLayoutIdentityFilters($query, $identity)
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
}
