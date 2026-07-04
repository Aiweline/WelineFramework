<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;
use Weline\Theme\Model\WelineTheme;

class WidgetDefaultInjectionService
{
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
            $this->markInitialHandled($themeId, $item, 'manual_apply');
            $item['layout_id'] = $layoutId;
            $item['status'] = $status;
            return $item;
        }

        return null;
    }

    /**
     * Apply default injections once for modules that were just installed/upgraded.
     *
     * @param list<string>|array<string,mixed> $modules
     */
    public function applyAllForAvailableThemes(array $modules = []): int
    {
        $moduleFilter = $this->normalizeModuleFilter($modules);
        if ($moduleFilter === []) {
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
                foreach ($this->collectDeclarations($theme, $componentArea, null, [], $moduleFilter) as $item) {
                    foreach ($this->expandItemForExistingIdentities($themeId, $item, $componentArea) as $expandedItem) {
                        if ($this->hasInitialHandled($themeId, $expandedItem)) {
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
     * Apply default injections once for a concrete layout identity.
     *
     * @param list<string>|array<string,mixed> $modules
     * @param list<string>|string $statuses
     */
    public function applyInitialForLayout(
        int $themeId,
        string $pageType,
        array $identity = [],
        string $componentArea = PreviewContextService::AREA_FRONTEND,
        array $modules = [],
        array|string $statuses = [ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED]
    ): int {
        $moduleFilter = $this->normalizeModuleFilter($modules);
        if ($moduleFilter === []) {
            return 0;
        }

        $theme = $this->loadTheme($themeId);
        if (!$theme) {
            return 0;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);
        $applied = 0;

        foreach ($this->collectDeclarations($theme, $componentArea, $pageType, $identity, $moduleFilter) as $item) {
            if ($this->hasInitialHandled($themeId, $item)) {
                continue;
            }
            $applied += $this->applyInitialItem($themeId, $item, $statuses);
        }

        return $applied;
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
        array $moduleFilter = []
    ): array {
        $identity = $this->normalizeIdentity($identity);
        $items = [];

        foreach ($this->componentCatalog->getDefinitions($componentArea, $theme) as $definition) {
            if ($moduleFilter !== [] && !isset($moduleFilter[$definition->module])) {
                continue;
            }
            foreach ($this->getDefinitionDefaultInjections($definition) as $rawInjection) {
                $item = $this->normalizeInjection($definition, $rawInjection, $pageType, $identity);
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
            $this->markInitialHandled($themeId, $item, $applied > 0 ? 'auto' : 'existing');
        } catch (\Throwable) {
            return $applied;
        }

        return $applied;
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

    private function markInitialHandled(int $themeId, array $item, string $source = 'auto'): void
    {
        if ($themeId <= 0 || empty($item['injection_key']) || $this->hasInitialHandled($themeId, $item)) {
            return;
        }

        try {
            $identity = $this->normalizeIdentity((array)($item['identity'] ?? []));
            $record = clone $this->defaultInjectionRecord;
            $record->clearQuery()->clearData()
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
                ->setData(ThemeWidgetDefaultInjection::schema_fields_SOURCE, trim($source) !== '' ? trim($source) : 'auto')
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
        array $currentIdentity
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

        $identity = $this->normalizeIdentity([
            'layout_option' => $layoutOption,
            'scope' => $injection['scope'] ?? ($currentIdentity['scope'] ?? 'default'),
            'target_type' => $injection['target_type'] ?? ($currentIdentity['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL),
            'target_id' => $injection['target_id'] ?? ($currentIdentity['target_id'] ?? 0),
        ]);
        if ($currentPageType !== null && !$this->identityMatches($identity, $currentIdentity)) {
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
        if (!$this->requiresConcreteTarget($identity)) {
            return [$item];
        }

        $expanded = [];
        foreach ($this->collectExistingLayoutIdentities($themeId, $item, $componentArea) as $layoutIdentity) {
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
                ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type']);
            if (!empty($item['identity_scope_declared'])) {
                $query->where(ThemeLayout::schema_fields_SCOPE, $identity['scope']);
            }
            if (!empty($item['identity_target_id_declared']) && $identity['target_id'] > 0) {
                $query->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id']);
            }
            $this->addIdentityRows($found, $query->select()->fetchArray());
        } catch (\Throwable) {
        }

        try {
            $query = (clone $this->themeLayoutVersion)->clearQuery()->clearData()
                ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayoutVersion::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeLayoutVersion::schema_fields_TARGET_TYPE, $identity['target_type']);
            if (!empty($item['identity_scope_declared'])) {
                $query->where(ThemeLayoutVersion::schema_fields_SCOPE, $identity['scope']);
            }
            if (!empty($item['identity_target_id_declared']) && $identity['target_id'] > 0) {
                $query->where(ThemeLayoutVersion::schema_fields_TARGET_ID, $identity['target_id']);
            }
            $this->addIdentityRows($found, $query->select()->fetchArray());
        } catch (\Throwable) {
        }

        try {
            $query = (clone $this->themeVirtualLayout)->clearQuery()->clearData()
                ->where(ThemeVirtualLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeVirtualLayout::schema_fields_AREA, $this->normalizeComponentArea($componentArea))
                ->where(ThemeVirtualLayout::schema_fields_LAYOUT_TYPE, $pageType)
                ->where(ThemeVirtualLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeVirtualLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeVirtualLayout::schema_fields_IS_ACTIVE, 1);
            if (!empty($item['identity_scope_declared'])) {
                $query->where(ThemeVirtualLayout::schema_fields_SCOPE, $identity['scope']);
            }
            if (!empty($item['identity_target_id_declared']) && $identity['target_id'] > 0) {
                $query->where(ThemeVirtualLayout::schema_fields_TARGET_ID, $identity['target_id']);
            }
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
            if ($this->requiresConcreteTarget($identity)) {
                continue;
            }
            $key = implode('|', [
                $identity['layout_option'],
                $identity['scope'],
                $identity['target_type'],
                (string)$identity['target_id'],
            ]);
            $found[$key] = $identity;
        }
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
     * @param list<string>|array<string,mixed> $modules
     * @return array<string,true>
     */
    private function normalizeModuleFilter(array $modules): array
    {
        $filter = [];
        foreach ($modules as $key => $value) {
            $module = is_string($value) ? $value : (is_string($key) ? $key : '');
            $module = trim($module);
            if ($module !== '') {
                $filter[$module] = true;
            }
        }

        return $filter;
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
