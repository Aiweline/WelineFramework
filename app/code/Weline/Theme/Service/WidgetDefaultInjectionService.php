<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;
use Weline\Theme\Model\WelineTheme;

class WidgetDefaultInjectionService
{
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_EXISTING = 'existing';
    public const SOURCE_MANUAL_APPLY = 'manual_apply';
    public const SOURCE_MANUAL_APPLY_ALL = 'manual_apply_all';
    public const SOURCE_USER_DELETED = 'user_deleted';

    private const DASHBOARD_PAGE_TYPE = 'dashboard';
    private const NO_PLACEMENTS_WIDGET_MODULE = 'Weline_Theme';
    private const NO_PLACEMENTS_WIDGET_TYPE = 'layout_state';
    private const NO_PLACEMENTS_WIDGET_CODE = '__no_widget_placements__';

    private const EXCLUSIVE_SLOTS = [
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

    public function __construct(
        private readonly ThemeComponentCatalog $componentCatalog,
        private readonly ThemeLayoutService $layoutService,
        private readonly WelineTheme $welineTheme,
        private readonly ThemeLayout $themeLayout,
        private readonly ThemeLayoutVersion $themeLayoutVersion,
        private readonly ThemeVirtualLayout $themeVirtualLayout,
        private readonly ThemeWidgetDefaultInjection $defaultInjectionRecord,
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getMissingForLayout(
        int $themeId,
        string $pageType,
        array $identity = [],
        string $componentArea = PreviewContextService::AREA_FRONTEND,
        string $keyword = ''
    ): array {
        $theme = $this->loadTheme($themeId);
        if (!$theme) {
            return [];
        }

        $componentArea = $this->normalizeComponentArea($componentArea);
        $identity = $this->normalizeIdentity($identity);
        $status = $this->layoutService->hasDraft($themeId, $pageType, $identity)
            ? ThemeLayout::STATUS_DRAFT
            : ThemeLayout::STATUS_PUBLISHED;
        $items = $this->collectDeclarations($theme, $componentArea, $pageType, $identity);
        $keyword = mb_strtolower(trim($keyword));
        $missing = [];

        foreach ($items as $item) {
            if ($keyword !== '' && !$this->matchesKeyword($item, $keyword)) {
                continue;
            }
            if (!$this->matchesDashboardDefaultViewFilter($item, $identity)) {
                continue;
            }
            if ($this->widgetExists($themeId, $item['page_type'], $item['identity'], $status, $item)) {
                continue;
            }
            $item['status'] = $status;
            $missing[] = $item;
        }

        return $missing;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function applyInjectionByKey(
        int $themeId,
        string $pageType,
        string $injectionKey,
        array $identity = [],
        string $status = ThemeLayout::STATUS_DRAFT,
        string $componentArea = PreviewContextService::AREA_FRONTEND
    ): ?array {
        $theme = $this->loadTheme($themeId);
        if (!$theme) {
            return null;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);
        foreach ($this->collectDeclarations($theme, $componentArea, $pageType, $identity) as $item) {
            if ((string)$item['injection_key'] !== $injectionKey) {
                continue;
            }
            if ($this->widgetExists($themeId, $item['page_type'], $item['identity'], $status, $item)) {
                return null;
            }
            $layoutId = $this->saveInjection($themeId, $item, $status);
            $this->markInitialHandled($themeId, $item, self::SOURCE_MANUAL_APPLY, true);
            $item['layout_id'] = $layoutId;
            $item['status'] = $status;
            return $item;
        }

        return null;
    }

    /**
     * Apply one declared default injection to every known identity of the same layout.
     *
     * @return array{items:list<array<string,mixed>>,current_item:?array<string,mixed>,applied_count:int,skipped_count:int,total_identities:int}
     */
    public function applyInjectionByKeyForAllLayoutIdentities(
        int $themeId,
        string $pageType,
        string $injectionKey,
        array $identity = [],
        string $status = ThemeLayout::STATUS_DRAFT,
        string $componentArea = PreviewContextService::AREA_FRONTEND
    ): array {
        $result = [
            'items' => [],
            'current_item' => null,
            'applied_count' => 0,
            'skipped_count' => 0,
            'total_identities' => 0,
        ];

        $theme = $this->loadTheme($themeId);
        if (!$theme) {
            return $result;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);
        foreach ($this->collectDeclarations($theme, $componentArea, $pageType, $identity) as $item) {
            if ((string)$item['injection_key'] !== $injectionKey) {
                continue;
            }

            foreach ($this->expandItemForAllLayoutIdentities($themeId, $item, $componentArea, $identity) as $expandedItem) {
                $result['total_identities']++;
                if ($this->widgetExists($themeId, $expandedItem['page_type'], $expandedItem['identity'], $status, $expandedItem)) {
                    $result['skipped_count']++;
                    continue;
                }

                $layoutId = $this->saveInjection($themeId, $expandedItem, $status);
                $this->markInitialHandled($themeId, $expandedItem, self::SOURCE_MANUAL_APPLY_ALL, true);
                $expandedItem['layout_id'] = $layoutId;
                $expandedItem['status'] = $status;
                $result['items'][] = $expandedItem;
                $result['applied_count']++;

                if ($result['current_item'] === null && $this->identityMatches($expandedItem['identity'], $identity)) {
                    $result['current_item'] = $expandedItem;
                }
            }

            if ($result['current_item'] === null && !empty($result['items'])) {
                $result['current_item'] = $result['items'][0];
            }

            return $result;
        }

        return $result;
    }

    /**
     * Apply default injections once for widgets that were first recorded in the DB registry ledger.
     *
     * @param list<array<string,mixed>>|array<string,array<string,mixed>> $widgets
     */
    public function applyInstalledWidgetsForAvailableThemes(array $widgets): int
    {
        $widgetFilter = $this->normalizeWidgetIdentityFilter($widgets);
        if ($widgetFilter === []) {
            return 0;
        }

        $this->componentCatalog->clearCache();
        $applied = 0;

        foreach ($this->getAllThemes() as $theme) {
            $themeId = (int)$theme->getId();
            if ($themeId <= 0) {
                continue;
            }

            foreach ([PreviewContextService::AREA_FRONTEND, PreviewContextService::AREA_BACKEND] as $componentArea) {
                foreach ($this->collectDeclarations($theme, $componentArea, null, [], $widgetFilter) as $item) {
                    // Dashboard injections only auto-enter via view-ready + default_view.
                    if ((string)($item['page_type'] ?? '') === self::DASHBOARD_PAGE_TYPE) {
                        continue;
                    }
                    foreach ($this->expandItemForExistingIdentities($themeId, $item, $componentArea) as $expandedItem) {
                        if ($this->hasWidgetDecision($themeId, $expandedItem)) {
                            continue;
                        }

                        $applied += $this->applyInitialItem($themeId, $expandedItem);
                    }
                }
            }
        }

        return $applied;
    }

    public function applyMissingForLayout(
        int $themeId,
        string $pageType,
        array $identity = [],
        string $componentArea = PreviewContextService::AREA_FRONTEND,
        string $status = ThemeLayout::STATUS_DRAFT
    ): int {
        $theme = $this->loadTheme($themeId);
        if (!$theme) {
            return 0;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);
        $applied = 0;

        foreach ($this->collectDeclarations($theme, $componentArea, $pageType, $identity) as $item) {
            if ($this->widgetExists($themeId, $item['page_type'], $item['identity'], $status, $item)) {
                continue;
            }
            $this->saveInjection($themeId, $item, $status);
            $applied++;
        }

        return $applied;
    }

    /**
     * Apply Dashboard default injections that declare default_view matching the ready view code.
     *
     * @param array<string,mixed> $identity
     */
    public function applyDashboardViewDefaultInjections(
        int $themeId,
        string $viewCode,
        array $identity = [],
        string $componentArea = PreviewContextService::AREA_BACKEND
    ): int {
        $viewCode = trim($viewCode);
        if ($themeId <= 0 || $viewCode === '') {
            return 0;
        }

        $theme = $this->loadTheme($themeId);
        if (!$theme) {
            return 0;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);
        if ($this->hasNoWidgetPlacementsMarker($themeId, self::DASHBOARD_PAGE_TYPE, $identity)) {
            return 0;
        }

        $applied = 0;
        foreach ($this->collectDeclarations($theme, $componentArea, self::DASHBOARD_PAGE_TYPE, $identity) as $item) {
            $defaultView = trim((string)($item['default_view'] ?? ''));
            if ($defaultView === '' || $defaultView !== $viewCode) {
                continue;
            }
            if ($this->hasUserDeletedDecision($themeId, $item)) {
                continue;
            }
            if ($this->shouldSkipAutoForExistingDecision($themeId, $item)) {
                continue;
            }

            $applied += $this->applyInitialItem($themeId, $item);
        }

        return $applied;
    }

    /**
     * Keep prior auto/existing/manual decisions when the widget is still present.
     * Stale decisions whose widgets disappeared (without user_deleted) may refill.
     */
    private function shouldSkipAutoForExistingDecision(int $themeId, array $item): bool
    {
        $row = $this->findWidgetDecisionRow($themeId, $item);
        if ($row === null) {
            return false;
        }

        $source = (string)($row[ThemeWidgetDefaultInjection::schema_fields_SOURCE] ?? '');
        if ($source === self::SOURCE_USER_DELETED) {
            return true;
        }

        $identity = $this->normalizeIdentity((array)($item['identity'] ?? []));
        $pageType = (string)($item['page_type'] ?? '');
        if ($this->widgetExists($themeId, $pageType, $identity, ThemeLayout::STATUS_DRAFT, $item)
            || $this->widgetExists($themeId, $pageType, $identity, ThemeLayout::STATUS_PUBLISHED, $item)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Record that a user removed a widget from the layout editor so auto-injection stays suppressed.
     *
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $widget
     */
    public function markUserDeleted(
        int $themeId,
        string $pageType,
        array $identity,
        array $widget,
        string $componentArea = PreviewContextService::AREA_FRONTEND
    ): void {
        if ($themeId <= 0 || trim($pageType) === '') {
            return;
        }

        $module = trim((string)($widget['module'] ?? $widget['widget_module'] ?? ''));
        $type = trim((string)($widget['type'] ?? $widget['widget_type'] ?? ''));
        $code = trim((string)($widget['code'] ?? $widget['widget_code'] ?? ''));
        if ($module === '' || $type === '' || $code === '') {
            return;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);
        $slotId = trim((string)($widget['slot_id'] ?? $widget['slot'] ?? ''));
        $area = trim((string)($widget['area'] ?? ThemeLayout::AREA_CONTENT));
        if ($area === '') {
            $area = ThemeLayout::AREA_CONTENT;
        }

        $item = [
            'module' => $module,
            'type' => $type,
            'code' => $code,
            'page_type' => trim($pageType),
            'layout_option' => $identity['layout_option'],
            'scope' => $identity['scope'],
            'target_type' => $identity['target_type'],
            'target_id' => $identity['target_id'],
            'identity' => $identity,
            'slot_id' => $slotId,
            'area' => $area,
            'sort_order' => max(0, (int)($widget['sort_order'] ?? 0)),
            'component_area' => $componentArea,
        ];
        $item['injection_key'] = $this->buildInjectionKey($item);
        $this->markInitialHandled($themeId, $item, self::SOURCE_USER_DELETED, true);
    }

    private function saveInjection(int $themeId, array $item, string $status): int
    {
        return $this->layoutService->saveWidget([
            'theme_id' => $themeId,
            'page_type' => $item['page_type'],
            'layout_option' => $item['identity']['layout_option'],
            'scope' => $item['identity']['scope'],
            'target_type' => $item['identity']['target_type'],
            'target_id' => $item['identity']['target_id'],
            'area' => $item['area'],
            'slot_id' => $item['slot_id'] !== '' ? $item['slot_id'] : null,
            'widget_code' => $item['code'],
            'widget_module' => $item['module'],
            'widget_type' => $item['type'],
            'config' => $item['config'],
            'sort_order' => $item['sort_order'],
            'exclusive' => $item['exclusive'],
            'is_active' => true,
            'status' => $status,
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function collectDeclarations(
        WelineTheme $theme,
        string $componentArea,
        ?string $pageType,
        array $identity,
        array $widgetFilter = []
    ): array {
        $identityProvided = $identity !== [];
        $identity = $this->normalizeIdentity($identity);
        $items = [];

        foreach ($this->componentCatalog->getDefinitions($componentArea, $theme) as $definition) {
            if ($widgetFilter !== [] && !$this->definitionMatchesWidgetFilter($definition, $widgetFilter)) {
                continue;
            }
            foreach ($this->getDefinitionDefaultInjections($definition) as $rawInjection) {
                $item = $this->normalizeInjection($definition, $rawInjection, $pageType, $identity, $identityProvided);
                if ($item === null) {
                    continue;
                }
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param list<string>|string $statuses
     */
    private function applyInitialItem(
        int $themeId,
        array $item,
        array|string $statuses = [ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED]
    ): int {
        $statuses = $this->normalizeStatuses($statuses);
        if ($statuses === []) {
            return 0;
        }

        $applied = 0;
        try {
            foreach ($statuses as $status) {
                if ($this->widgetExists($themeId, $item['page_type'], $item['identity'], $status, $item)) {
                    continue;
                }
                $this->saveInjection($themeId, $item, $status);
                $applied++;
            }
            $this->markInitialHandled(
                $themeId,
                $item,
                $applied > 0 ? self::SOURCE_AUTO : self::SOURCE_EXISTING
            );
        } catch (\Throwable) {
            return $applied;
        }

        return $applied;
    }

    /**
     * Any prior decision for the same layout identity + widget identity suppresses auto injection.
     */
    private function hasWidgetDecision(int $themeId, array $item): bool
    {
        return $this->findWidgetDecisionRow($themeId, $item) !== null;
    }

    private function hasUserDeletedDecision(int $themeId, array $item): bool
    {
        $row = $this->findWidgetDecisionRow($themeId, $item);
        if ($row === null) {
            return false;
        }

        return (string)($row[ThemeWidgetDefaultInjection::schema_fields_SOURCE] ?? '') === self::SOURCE_USER_DELETED;
    }

    /**
     * Dashboard 视图 identity 下：只展示 default_view 匹配当前视图 code 的声明；
     * 未声明 default_view 的仍可作为「应用默认」候选项。
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $identity
     */
    private function matchesDashboardDefaultViewFilter(array $item, array $identity): bool
    {
        $defaultView = trim((string)($item['default_view'] ?? ''));
        if ($defaultView === '') {
            return true;
        }

        $scope = trim((string)($identity['scope'] ?? ($item['scope'] ?? '')));
        if (!preg_match('/^dashboard_view:(\d+)$/', $scope, $matches)) {
            return true;
        }

        $viewCode = $this->resolveDashboardViewCode((int)$matches[1]);
        if ($viewCode === '') {
            return true;
        }

        return $defaultView === $viewCode;
    }

    private function resolveDashboardViewCode(int $viewId): string
    {
        if ($viewId <= 0 || !class_exists(\Weline\Dashboard\Model\DashboardView::class)) {
            return '';
        }

        try {
            /** @var \Weline\Dashboard\Model\DashboardView $view */
            $view = ObjectManager::getInstance(\Weline\Dashboard\Model\DashboardView::class);
            $view->clearQuery()->clearData()->load($viewId);
            if ((int)$view->getViewId() !== $viewId) {
                return '';
            }

            return trim((string)$view->getCode());
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findWidgetDecisionRow(int $themeId, array $item): ?array
    {
        if ($themeId <= 0) {
            return null;
        }

        $module = trim((string)($item['module'] ?? ''));
        $type = trim((string)($item['type'] ?? ''));
        $code = trim((string)($item['code'] ?? ''));
        $pageType = trim((string)($item['page_type'] ?? ''));
        if ($module === '' || $type === '' || $code === '' || $pageType === '') {
            return null;
        }

        try {
            $identity = $this->normalizeIdentity((array)($item['identity'] ?? []));
            $row = (clone $this->defaultInjectionRecord)->clearQuery()->clearData()
                ->where(ThemeWidgetDefaultInjection::schema_fields_THEME_ID, $themeId)
                ->where(ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA, $this->normalizeComponentArea((string)($item['component_area'] ?? PreviewContextService::AREA_FRONTEND)))
                ->where(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_SCOPE, $identity['scope'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_TARGET_ID, $identity['target_id'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_MODULE, $module)
                ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_TYPE, $type)
                ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_CODE, $code)
                ->find()
                ->fetchArray();

            return is_array($row) && (int)($row[ThemeWidgetDefaultInjection::schema_fields_ID] ?? 0) > 0
                ? $row
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasNoWidgetPlacementsMarker(int $themeId, string $pageType, array $identity): bool
    {
        if ($themeId <= 0 || $pageType === '') {
            return false;
        }

        $identity = $this->normalizeIdentity($identity);
        try {
            foreach ([ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED] as $status) {
                $rows = (clone $this->themeLayout)->reset()
                    ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                    ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                    ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                    ->where(ThemeLayout::schema_fields_SCOPE, $identity['scope'])
                    ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
                    ->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id'])
                    ->where(ThemeLayout::schema_fields_STATUS, $status)
                    ->where(ThemeLayout::schema_fields_WIDGET_MODULE, self::NO_PLACEMENTS_WIDGET_MODULE)
                    ->where(ThemeLayout::schema_fields_WIDGET_TYPE, self::NO_PLACEMENTS_WIDGET_TYPE)
                    ->where(ThemeLayout::schema_fields_WIDGET_CODE, self::NO_PLACEMENTS_WIDGET_CODE)
                    ->where(ThemeLayout::schema_fields_IS_ACTIVE, 1)
                    ->select()
                    ->fetchArray();
                if (is_array($rows) && count($rows) > 0) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function hasInitialHandled(int $themeId, array $item): bool
    {
        if ($themeId <= 0 || empty($item['injection_key'])) {
            return false;
        }

        try {
            $identity = $this->normalizeIdentity((array)($item['identity'] ?? []));
            $row = (clone $this->defaultInjectionRecord)->clearQuery()->clearData()
                ->where(ThemeWidgetDefaultInjection::schema_fields_THEME_ID, $themeId)
                ->where(ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA, $this->normalizeComponentArea((string)($item['component_area'] ?? PreviewContextService::AREA_FRONTEND)))
                ->where(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, (string)($item['page_type'] ?? ''))
                ->where(ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_SCOPE, $identity['scope'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_TARGET_ID, $identity['target_id'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_INJECTION_KEY, (string)$item['injection_key'])
                ->find()
                ->fetchArray();

            return is_array($row) && (int)($row[ThemeWidgetDefaultInjection::schema_fields_ID] ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function markInitialHandled(int $themeId, array $item, string $source = self::SOURCE_AUTO, bool $force = false): void
    {
        if ($themeId <= 0 || empty($item['injection_key'])) {
            return;
        }

        $source = trim($source) !== '' ? trim($source) : self::SOURCE_AUTO;
        $existing = $this->findWidgetDecisionRow($themeId, $item);
        if ($existing !== null && !$force) {
            return;
        }
        if ($existing === null && !$force && $this->hasInitialHandled($themeId, $item)) {
            return;
        }

        try {
            $identity = $this->normalizeIdentity((array)($item['identity'] ?? []));
            $record = clone $this->defaultInjectionRecord;
            $record->clearQuery()->clearData();
            if ($existing !== null) {
                $recordId = (int)($existing[ThemeWidgetDefaultInjection::schema_fields_ID] ?? 0);
                if ($recordId > 0) {
                    $record->load($recordId);
                }
            }

            $record
                ->setData(ThemeWidgetDefaultInjection::schema_fields_THEME_ID, $themeId)
                ->setData(ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA, $this->normalizeComponentArea((string)($item['component_area'] ?? PreviewContextService::AREA_FRONTEND)))
                ->setData(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, (string)($item['page_type'] ?? ''))
                ->setData(ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->setData(ThemeWidgetDefaultInjection::schema_fields_SCOPE, $identity['scope'])
                ->setData(ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->setData(ThemeWidgetDefaultInjection::schema_fields_TARGET_ID, $identity['target_id'])
                ->setData(ThemeWidgetDefaultInjection::schema_fields_INJECTION_KEY, (string)$item['injection_key'])
                ->setData(ThemeWidgetDefaultInjection::schema_fields_WIDGET_MODULE, (string)($item['module'] ?? ''))
                ->setData(ThemeWidgetDefaultInjection::schema_fields_WIDGET_TYPE, (string)($item['type'] ?? ''))
                ->setData(ThemeWidgetDefaultInjection::schema_fields_WIDGET_CODE, (string)($item['code'] ?? ''))
                ->setData(ThemeWidgetDefaultInjection::schema_fields_SLOT_ID, (string)($item['slot_id'] ?? '') ?: null)
                ->setData(ThemeWidgetDefaultInjection::schema_fields_AREA, (string)($item['area'] ?? ThemeLayout::AREA_CONTENT))
                ->setData(ThemeWidgetDefaultInjection::schema_fields_SORT_ORDER, (int)($item['sort_order'] ?? 0))
                ->setData(ThemeWidgetDefaultInjection::schema_fields_SOURCE, $source)
                ->save();
        } catch (\Throwable) {
            // Missing schema or a concurrent insert must not block widget collection.
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function getDefinitionDefaultInjections(ThemeComponentDefinition $definition): array
    {
        $value = $definition->defaultInjections ?: ($definition->meta['default_injections'] ?? []);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value) || $value === []) {
            return [];
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        $items = $isList ? $value : [$value];

        return array_values(array_filter($items, static fn($item): bool => is_array($item)));
    }

    private function normalizeInjection(
        ThemeComponentDefinition $definition,
        array $injection,
        ?string $currentPageType,
        array $currentIdentity,
        bool $currentIdentityProvided = false
    ): ?array {
        $declaredPageType = trim((string)($injection['layout_type'] ?? $injection['page_type'] ?? ''));
        if ($declaredPageType === '') {
            return null;
        }
        if ($currentPageType !== null && $declaredPageType !== '*' && $declaredPageType !== $currentPageType) {
            return null;
        }
        if ($declaredPageType === '*' && $currentPageType === null) {
            return null;
        }

        $pageType = $declaredPageType === '*' ? (string)$currentPageType : $declaredPageType;
        $layoutOption = trim((string)($injection['layout_option'] ?? $currentIdentity['layout_option'] ?? 'default'));
        if ($currentPageType !== null && isset($currentIdentity['layout_option']) && $layoutOption !== (string)$currentIdentity['layout_option']) {
            return null;
        }

        $identitySource = [
            'layout_option' => $layoutOption,
            'scope' => $injection['scope'] ?? ($currentIdentity['scope'] ?? 'default'),
            'target_type' => $injection['target_type'] ?? ($currentIdentity['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL),
            'target_id' => $injection['target_id'] ?? ($currentIdentity['target_id'] ?? 0),
        ];
        if ($currentPageType !== null && $currentIdentityProvided) {
            $identitySource['scope'] = $currentIdentity['scope'];
            $identitySource['target_type'] = $currentIdentity['target_type'];
            $identitySource['target_id'] = $currentIdentity['target_id'];
        }

        $identity = $this->normalizeIdentity($identitySource);
        if ($currentPageType !== null && $currentIdentityProvided && !$this->identityMatches($identity, $currentIdentity)) {
            return null;
        }

        $slotId = trim((string)($injection['slot'] ?? $injection['slot_id'] ?? $definition->slot ?? ''));
        $area = $this->resolveLayoutArea($injection, $definition, $slotId);
        $sortOrder = max(0, (int)($injection['sort_order'] ?? 0));
        $config = $definition->defaultConfig;
        if (isset($injection['config']) && is_array($injection['config'])) {
            $config = array_merge($config, $injection['config']);
        }
        $exclusive = array_key_exists('exclusive', $injection)
            ? (bool)$injection['exclusive']
            : ($definition->exclusive || ($slotId !== '' && in_array($slotId, self::EXCLUSIVE_SLOTS, true)));
        $defaultView = trim((string)($injection['default_view'] ?? ''));

        $item = [
            'module' => $definition->module,
            'type' => $definition->type,
            'code' => $definition->code,
            'name' => $definition->name,
            'description' => $definition->description,
            'widget' => $definition->toWidgetArray(),
            'page_type' => $pageType,
            'layout_type' => $pageType,
            'layout_option' => $identity['layout_option'],
            'scope' => $identity['scope'],
            'target_type' => $identity['target_type'],
            'target_id' => $identity['target_id'],
            'identity' => $identity,
            'slot_id' => $slotId,
            'slot' => $slotId,
            'area' => $area,
            'sort_order' => $sortOrder,
            'required' => (bool)($injection['required'] ?? false),
            'reason' => trim((string)($injection['reason'] ?? '')),
            'config' => $config,
            'exclusive' => $exclusive,
            'default_view' => $defaultView,
            'component_area' => $definition->area,
            'identity_scope_declared' => array_key_exists('scope', $injection),
            'identity_target_id_declared' => array_key_exists('target_id', $injection),
        ];
        $item['injection_key'] = $this->buildInjectionKey($item);

        return $item;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function expandItemForExistingIdentities(int $themeId, array $item, string $componentArea): array
    {
        $identity = $this->normalizeIdentity((array)($item['identity'] ?? []));
        if (!$this->requiresConcreteTarget($identity) && $identity['target_type'] === ThemeVirtualLayout::TARGET_GLOBAL) {
            return [$item];
        }

        $identities = $this->collectExistingLayoutIdentities($themeId, $item, $componentArea);
        if ($identities === []) {
            return [];
        }

        return $this->copyItemForIdentities($item, $identities);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function expandItemForAllLayoutIdentities(int $themeId, array $item, string $componentArea, array $currentIdentity = []): array
    {
        $expanded = [];
        foreach ($this->collectExistingLayoutIdentities($themeId, $item, $componentArea) as $layoutIdentity) {
            $expanded[$this->identityMapKey($layoutIdentity)] = $layoutIdentity;
        }
        if ($currentIdentity !== []) {
            $identity = $this->normalizeIdentity($currentIdentity);
            if (!$this->requiresConcreteTarget($identity) || $this->allowsZeroTargetIdentity($identity)) {
                $expanded[$this->identityMapKey($identity)] = $identity;
            }
        }

        if ($expanded === []) {
            return [$item];
        }

        return $this->copyItemForIdentities($item, array_values($expanded));
    }

    /**
     * @param list<array{layout_option:string,scope:string,target_type:string,target_id:int}> $identities
     * @return list<array<string,mixed>>
     */
    private function copyItemForIdentities(array $item, array $identities): array
    {
        $expanded = [];
        foreach ($identities as $layoutIdentity) {
            $copy = $item;
            $copy['identity'] = $layoutIdentity;
            $copy['layout_option'] = $layoutIdentity['layout_option'];
            $copy['scope'] = $layoutIdentity['scope'];
            $copy['target_type'] = $layoutIdentity['target_type'];
            $copy['target_id'] = $layoutIdentity['target_id'];
            $copy['injection_key'] = $this->buildInjectionKey($copy);
            $expanded[] = $copy;
        }

        return $expanded;
    }

    /**
     * @return list<array{layout_option:string,scope:string,target_type:string,target_id:int}>
     */
    private function collectExistingLayoutIdentities(int $themeId, array $item, string $componentArea): array
    {
        $identity = $this->normalizeIdentity((array)($item['identity'] ?? []));
        $pageType = (string)($item['page_type'] ?? '');
        if ($themeId <= 0 || $pageType === '') {
            return [];
        }

        $found = [];

        try {
            $query = (clone $this->themeLayout)->clearQuery()->clearData()
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option']);
            $this->addIdentityRows($found, $query->select()->fetchArray());
        } catch (\Throwable) {
        }

        try {
            $query = (clone $this->themeLayoutVersion)->clearQuery()->clearData()
                ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayoutVersion::schema_fields_LAYOUT_OPTION, $identity['layout_option']);
            $this->addIdentityRows($found, $query->select()->fetchArray());
        } catch (\Throwable) {
        }

        try {
            $query = (clone $this->themeVirtualLayout)->clearQuery()->clearData()
                ->where(ThemeVirtualLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeVirtualLayout::schema_fields_AREA, $this->normalizeComponentArea($componentArea))
                ->where(ThemeVirtualLayout::schema_fields_LAYOUT_TYPE, $pageType)
                ->where(ThemeVirtualLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeVirtualLayout::schema_fields_IS_ACTIVE, 1);
            $this->addIdentityRows($found, $query->select()->fetchArray());
        } catch (\Throwable) {
        }

        return array_values($found);
    }

    /**
     * @param array<string,array{layout_option:string,scope:string,target_type:string,target_id:int}> $found
     */
    private function addIdentityRows(array &$found, mixed $rows): void
    {
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $identity = $this->normalizeIdentity([
                'layout_option' => $row['layout_option'] ?? 'default',
                'scope' => $row['scope'] ?? 'default',
                'target_type' => $row['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL,
                'target_id' => $row['target_id'] ?? 0,
            ]);
            if ($this->requiresConcreteTarget($identity) && !$this->allowsZeroTargetIdentity($identity)) {
                continue;
            }
            $found[$this->identityMapKey($identity)] = $identity;
        }
    }

    private function identityMapKey(array $identity): string
    {
        $identity = $this->normalizeIdentity($identity);
        return implode('|', [
            $identity['layout_option'],
            $identity['scope'],
            $identity['target_type'],
            (string)$identity['target_id'],
        ]);
    }

    private function widgetExists(int $themeId, string $pageType, array $identity, string $status, array $item): bool
    {
        try {
            $query = (clone $this->themeLayout)->reset()
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeLayout::schema_fields_SCOPE, $identity['scope'])
                ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id'])
                ->where(ThemeLayout::schema_fields_STATUS, $status)
                ->where(ThemeLayout::schema_fields_WIDGET_MODULE, $item['module'])
                ->where(ThemeLayout::schema_fields_WIDGET_TYPE, $item['type'])
                ->where(ThemeLayout::schema_fields_WIDGET_CODE, $item['code'])
                ->where(ThemeLayout::schema_fields_IS_ACTIVE, 1);

            $rows = $query->select()->fetchArray();
            return is_array($rows) && count($rows) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveLayoutArea(array $injection, ThemeComponentDefinition $definition, string $slotId): string
    {
        $area = trim((string)($injection['area'] ?? ''));
        if ($area !== '') {
            return $area;
        }
        if ($slotId !== '') {
            $slotLower = strtolower($slotId);
            if (str_contains($slotLower, 'header') || in_array($slotLower, ['logo', 'search', 'navigation', 'user-area', 'cart'], true)) {
                return ThemeLayout::AREA_HEADER;
            }
            if (str_contains($slotLower, 'footer') || in_array($slotLower, ['copyright', 'links', 'newsletter', 'social'], true)) {
                return ThemeLayout::AREA_FOOTER;
            }
        }

        return (string)($definition->position[0] ?? ThemeLayout::AREA_CONTENT) ?: ThemeLayout::AREA_CONTENT;
    }

    private function buildInjectionKey(array $item): string
    {
        return hash('sha256', implode('|', [
            (string)$item['module'],
            (string)$item['type'],
            (string)$item['code'],
            (string)$item['page_type'],
            (string)$item['layout_option'],
            (string)$item['scope'],
            (string)$item['target_type'],
            (string)$item['target_id'],
            (string)$item['slot_id'],
            (string)$item['area'],
            (string)$item['sort_order'],
        ]));
    }

    private function identityMatches(array $left, array $right): bool
    {
        $right = $this->normalizeIdentity($right);
        return $left['layout_option'] === $right['layout_option']
            && $left['scope'] === $right['scope']
            && $left['target_type'] === $right['target_type']
            && (int)$left['target_id'] === (int)$right['target_id'];
    }

    private function requiresConcreteTarget(array $identity): bool
    {
        $identity = $this->normalizeIdentity($identity);
        return $identity['target_type'] !== ThemeVirtualLayout::TARGET_GLOBAL
            && (int)$identity['target_id'] <= 0;
    }

    private function allowsZeroTargetIdentity(array $identity): bool
    {
        $identity = $this->normalizeIdentity($identity);
        return $identity['target_type'] === 'website'
            && (int)$identity['target_id'] === 0;
    }

    /**
     * @return array{layout_option:string,scope:string,target_type:string,target_id:int}
     */
    private function normalizeIdentity(array $identity): array
    {
        $layoutOption = trim((string)($identity['layout_option'] ?? 'default'));
        $scope = trim((string)($identity['scope'] ?? 'default'));
        $targetType = trim((string)($identity['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL));

        return [
            'layout_option' => $layoutOption !== '' ? $layoutOption : 'default',
            'scope' => $scope !== '' ? $scope : 'default',
            'target_type' => $targetType !== '' ? $targetType : ThemeVirtualLayout::TARGET_GLOBAL,
            'target_id' => max(0, (int)($identity['target_id'] ?? 0)),
        ];
    }

    private function normalizeComponentArea(string $area): string
    {
        return $area === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;
    }

    /**
     * @param list<array<string,mixed>>|array<string,array<string,mixed>> $widgets
     * @return array<string,true>
     */
    private function normalizeWidgetIdentityFilter(array $widgets): array
    {
        $filter = [];
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $module = trim((string)($widget['module'] ?? $widget['widget_module'] ?? ''));
            $type = trim((string)($widget['type'] ?? $widget['widget_type'] ?? ''));
            $code = trim((string)($widget['code'] ?? $widget['widget_code'] ?? ''));
            if ($module === '' || $type === '' || $code === '') {
                continue;
            }
            $area = $this->normalizeComponentArea((string)($widget['area'] ?? $widget['widget_area'] ?? ''));
            $filter[$this->widgetIdentityKey($module, $type, $code, $area)] = true;
            $filter[$this->widgetIdentityKey($module, $type, $code, '')] = true;
        }

        return $filter;
    }

    /**
     * @param array<string,true> $filter
     */
    private function definitionMatchesWidgetFilter(ThemeComponentDefinition $definition, array $filter): bool
    {
        return isset($filter[$this->widgetIdentityKey($definition->module, $definition->type, $definition->code, $definition->area)])
            || isset($filter[$this->widgetIdentityKey($definition->module, $definition->type, $definition->code, '')]);
    }

    private function widgetIdentityKey(string $module, string $type, string $code, string $area = ''): string
    {
        return implode("\x1F", [
            trim($area),
            trim($module),
            trim($type),
            trim($code),
        ]);
    }

    /**
     * @param list<string>|string $statuses
     * @return list<string>
     */
    private function normalizeStatuses(array|string $statuses): array
    {
        $statuses = is_array($statuses) ? $statuses : [$statuses];
        $result = [];
        foreach ($statuses as $status) {
            $status = $status === ThemeLayout::STATUS_PUBLISHED
                ? ThemeLayout::STATUS_PUBLISHED
                : ThemeLayout::STATUS_DRAFT;
            $result[$status] = $status;
        }

        return array_values($result);
    }

    private function matchesKeyword(array $item, string $keyword): bool
    {
        $haystack = mb_strtolower(implode(' ', [
            (string)($item['module'] ?? ''),
            (string)($item['type'] ?? ''),
            (string)($item['code'] ?? ''),
            (string)($item['name'] ?? ''),
            (string)($item['description'] ?? ''),
            (string)($item['reason'] ?? ''),
            (string)($item['slot_id'] ?? ''),
            (string)($item['area'] ?? ''),
        ]));

        return mb_strpos($haystack, $keyword) !== false;
    }

    private function loadTheme(int $themeId): ?WelineTheme
    {
        if ($themeId <= 0) {
            return null;
        }

        try {
            $theme = clone $this->welineTheme;
            $theme->clearData()->clearQuery()->load($themeId);
            return $theme->getId() ? $theme : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return WelineTheme[]
     */
    private function getAllThemes(): array
    {
        $themes = [];

        try {
            $themeModel = clone $this->welineTheme;
            $themeModel->clearData()->clearQuery();
            $rows = $themeModel->select()->fetchArray();
            foreach ((array)$rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $theme = clone $this->welineTheme;
                $theme->clearData()->setData($row);
                if ($theme->getId()) {
                    $themes[] = $theme;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $themes;
    }
}
