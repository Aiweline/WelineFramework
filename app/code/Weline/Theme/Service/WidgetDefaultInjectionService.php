<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Helper\FooterDefaultLinksHelper;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService;

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
        'category-menu',
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
        private readonly ThemeRuntimeLayoutResolver $runtimeLayoutResolver,
        private readonly ThemeScopedLayoutWriteService $layoutWriter,
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
     * All default_injections declared for the layout identity, including already-applied ones.
     * Used by Theme Editor「应用」so application widgets like Review remain visible after apply.
     *
     * @return list<array<string,mixed>>
     */
    public function getDeclaredForLayout(
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
        $declared = [];

        foreach ($items as $item) {
            if ($keyword !== '' && !$this->matchesKeyword($item, $keyword)) {
                continue;
            }
            if (!$this->matchesDashboardDefaultViewFilter($item, $identity)) {
                continue;
            }
            $applied = $this->widgetExists($themeId, $item['page_type'], $item['identity'], $status, $item);
            $item['status'] = $status;
            $item['injection_status'] = $applied ? 'applied' : 'missing';
            $item['applied'] = $applied;
            $item['install_mode'] = !empty($item['required']) ? 'default' : 'recommend';
            $blocker = null;
            if ($applied) {
                $layoutNode = $this->resolveAppliedLayoutNode(
                    $themeId,
                    (string)$item['page_type'],
                    (array)$item['identity'],
                    $status,
                    $item,
                    $componentArea,
                );
                if ($layoutNode !== null) {
                    $item['node_uid'] = $layoutNode['node_uid'];
                    $item['layout_id'] = $layoutNode['layout_id'];
                    $item['removable'] = true;
                } else {
                    $item['node_uid'] = '';
                    $item['layout_id'] = 0;
                    $item['removable'] = false;
                    $item['is_template_inline'] = $this->widgetExistsAsTemplateInline(
                        $themeId,
                        $item,
                        $componentArea,
                    );
                }
            } else {
                $item['node_uid'] = '';
                $item['layout_id'] = 0;
                $item['removable'] = false;
                $blocker = $this->resolveInstallBlocker($themeId, $item, $status, $componentArea);
            }
            $item['install_blocker'] = $blocker;
            $item['not_applicable'] = is_array($blocker) && (($blocker['code'] ?? '') === 'not_applicable');
            $declared[] = $item;
        }

        usort($declared, static function (array $left, array $right): int {
            $leftMissing = (($left['injection_status'] ?? '') === 'missing') ? 0 : 1;
            $rightMissing = (($right['injection_status'] ?? '') === 'missing') ? 0 : 1;
            if ($leftMissing !== $rightMissing) {
                return $leftMissing <=> $rightMissing;
            }
            $leftRequired = !empty($left['required']) ? 0 : 1;
            $rightRequired = !empty($right['required']) ? 0 : 1;
            if ($leftRequired !== $rightRequired) {
                return $leftRequired <=> $rightRequired;
            }

            return ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0));
        });

        return $declared;
    }

    /**
     * @return array{success:bool,item:?array<string,mixed>,blocker:?array{code:string,message:string},message:string,injection_key:string}
     */
    public function applyInjectionByKeyResult(
        int $themeId,
        string $pageType,
        string $injectionKey,
        array $identity = [],
        string $status = ThemeLayout::STATUS_DRAFT,
        string $componentArea = PreviewContextService::AREA_FRONTEND
    ): array {
        $empty = [
            'success' => false,
            'item' => null,
            'blocker' => null,
            'message' => '',
            'injection_key' => $injectionKey,
        ];
        $theme = $this->loadTheme($themeId);
        if (!$theme) {
            $empty['message'] = 'theme_not_found';
            return $empty;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);
        foreach ($this->collectDeclarations($theme, $componentArea, $pageType, $identity) as $item) {
            if ((string)$item['injection_key'] !== $injectionKey) {
                continue;
            }
            if ($this->widgetExists($themeId, $item['page_type'], $item['identity'], $status, $item)) {
                $empty['message'] = 'already_applied';
                return $empty;
            }
            $blocker = $this->resolveInstallBlocker($themeId, $item, $status, $componentArea);
            if ($blocker !== null) {
                $empty['blocker'] = $blocker;
                $empty['message'] = (string)($blocker['message'] ?? $blocker['code']);
                $empty['item'] = $item;
                return $empty;
            }
            try {
                $layoutId = $this->saveInjection($themeId, $item, $status);
                $this->markInitialHandled($themeId, $item, self::SOURCE_MANUAL_APPLY, true);
                $item['node_uid'] = $layoutId;
                $item['layout_id'] = 0;
                $item['status'] = $status;
                $item['injection_status'] = 'applied';
                $item['applied'] = true;
                $item['install_mode'] = !empty($item['required']) ? 'default' : 'recommend';
                $item['install_blocker'] = null;
                return [
                    'success' => true,
                    'item' => $item,
                    'blocker' => null,
                    'message' => 'applied',
                    'injection_key' => $injectionKey,
                ];
            } catch (\Throwable $e) {
                $empty['blocker'] = [
                    'code' => 'apply_failed',
                    'message' => $e->getMessage(),
                ];
                $empty['message'] = $e->getMessage();
                $empty['item'] = $item;
                return $empty;
            }
        }

        $empty['message'] = 'injection_not_found';
        return $empty;
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
        $result = $this->applyInjectionByKeyResult(
            $themeId,
            $pageType,
            $injectionKey,
            $identity,
            $status,
            $componentArea
        );

        return !empty($result['success']) && is_array($result['item'] ?? null)
            ? $result['item']
            : null;
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

                $nodeUid = $this->saveInjection($themeId, $expandedItem, $status);
                $this->markInitialHandled($themeId, $expandedItem, self::SOURCE_MANUAL_APPLY_ALL, true);
                $expandedItem['node_uid'] = $nodeUid;
                $expandedItem['layout_id'] = 0;
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
                    // REQ-THEME-0014: only required (默认安装) auto-installs on registry create/update.
                    if (empty($item['required'])) {
                        continue;
                    }
                    foreach ($this->expandItemForAllLayoutIdentities($themeId, $item, $componentArea) as $expandedItem) {
                        if ($this->hasUserDeletedDecision($themeId, $expandedItem)) {
                            continue;
                        }
                        if ($this->hasWidgetDecision($themeId, $expandedItem)) {
                            continue;
                        }
                        if ($this->resolveInstallBlocker(
                            $themeId,
                            $expandedItem,
                            ThemeLayout::STATUS_DRAFT,
                            $componentArea
                        ) !== null) {
                            continue;
                        }

                        $applied += $this->applyInitialItem($themeId, $expandedItem);
                    }
                }
            }
        }

        return $applied;
    }

    /**
     * 草稿重置 / 恢复原始后：清除 user_deleted 并只回填默认安装（required）项。
     *
     * @param array<string,mixed> $identity
     * @return array{cleared_user_deleted:int,applied_defaults:int}
     */
    public function restoreDefaultInjectionsAfterDraftReset(
        int $themeId,
        array $identity,
        string $componentArea = PreviewContextService::AREA_FRONTEND,
        ?string $pageType = null,
    ): array {
        $cleared = $this->clearUserDeletedDecisions($themeId, $identity, $componentArea, $pageType);
        $applied = ($pageType === null || trim($pageType) === '')
            ? $this->applyMissingForAllPageTypes($themeId, $identity, $componentArea, ThemeLayout::STATUS_DRAFT, true)
            : $this->applyMissingForLayout($themeId, $pageType, $identity, $componentArea, ThemeLayout::STATUS_DRAFT, true);

        return [
            'cleared_user_deleted' => $cleared,
            'applied_defaults' => $applied,
        ];
    }

    /**
     * 方案 A：当前 identity draft ready 后，仅对「默认安装且无决策」项对账安装。
     *
     * @param array<string,mixed> $identity
     * @return array{applied:int,skipped:int,blockers:list<array<string,mixed>>,items:list<array<string,mixed>>}
     */
    public function reconcileRequiredDefaultsForIdentity(
        int $themeId,
        string $pageType,
        array $identity = [],
        string $componentArea = PreviewContextService::AREA_FRONTEND,
        string $status = ThemeLayout::STATUS_DRAFT,
    ): array {
        $result = [
            'applied' => 0,
            'skipped' => 0,
            'blockers' => [],
            'items' => [],
        ];
        $theme = $this->loadTheme($themeId);
        if (!$theme || trim($pageType) === '') {
            return $result;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);

        $integrity = $this->ensureFooterContainerIntegrityForIdentity(
            $themeId,
            $pageType,
            $identity,
            $componentArea,
            $status,
        );
        $result['applied'] += (int)($integrity['applied'] ?? 0);
        foreach ($integrity['blockers'] ?? [] as $blocker) {
            $result['blockers'][] = $blocker;
        }
        foreach ($integrity['items'] ?? [] as $item) {
            $result['items'][] = $item;
        }

        foreach ($this->collectDeclarations($theme, $componentArea, $pageType, $identity) as $item) {
            if (empty($item['required'])) {
                $result['skipped']++;
                continue;
            }
            if ($this->hasUserDeletedDecision($themeId, $item)) {
                $result['skipped']++;
                continue;
            }
            if ($this->widgetExists($themeId, $pageType, $identity, $status, $item)) {
                $result['skipped']++;
                continue;
            }
            if ($this->hasWidgetDecision($themeId, $item)) {
                $result['skipped']++;
                continue;
            }
            $blocker = $this->resolveInstallBlocker($themeId, $item, $status, $componentArea);
            if ($blocker !== null) {
                if (($blocker['code'] ?? '') !== 'not_applicable') {
                    $item['install_blocker'] = $blocker;
                    $result['blockers'][] = [
                        'injection_key' => (string)($item['injection_key'] ?? ''),
                        'blocker' => $blocker,
                    ];
                }
                $result['skipped']++;
                continue;
            }
            try {
                $nodeUid = $this->saveInjection($themeId, $item, $status);
                $this->markInitialHandled($themeId, $item, self::SOURCE_AUTO, true);
                $item['node_uid'] = $nodeUid;
                $item['status'] = $status;
                $item['injection_status'] = 'applied';
                $result['items'][] = $item;
                $result['applied']++;
            } catch (\Throwable $e) {
                $result['blockers'][] = [
                    'injection_key' => (string)($item['injection_key'] ?? ''),
                    'blocker' => [
                        'code' => 'apply_failed',
                        'message' => $e->getMessage(),
                    ],
                ];
                $result['skipped']++;
            }
        }

        return $result;
    }

    /**
     * 页脚扩展槽部件已配置但 footer-container 缺失时，持久化补齐父容器。
     * 覆盖「决策已记录但布局节点被删」的半安装状态；用户显式删除 footer-container 时不回填。
     *
     * @param array<string,mixed> $identity
     * @return array{applied:int,blockers:list<array<string,mixed>>,items:list<array<string,mixed>>}
     */
    public function ensureFooterContainerIntegrityForIdentity(
        int $themeId,
        string $pageType,
        array $identity = [],
        string $componentArea = PreviewContextService::AREA_FRONTEND,
        string $status = ThemeLayout::STATUS_DRAFT,
    ): array {
        $result = [
            'applied' => 0,
            'blockers' => [],
            'items' => [],
        ];
        $theme = $this->loadTheme($themeId);
        // 全局 chrome 只持久化在 homepage 载体；非载体布局不得补齐本地 footer-container。
        if (!$theme || trim($pageType) === '' || trim($pageType) !== ThemeLayout::PAGE_TYPE_HOME) {
            return $result;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);
        $widgets = [];
        foreach ($this->iterateScopedWidgets($themeId, $pageType, $identity, $status, $componentArea) as $widget) {
            if (is_array($widget)) {
                $widgets[] = $widget;
            }
        }
        if (!FooterDefaultLinksHelper::usesExtensionSlots($widgets)
            || FooterDefaultLinksHelper::hasFooterContainer($widgets)) {
            return $result;
        }

        $footerContainerItem = null;
        foreach ($this->collectDeclarations($theme, $componentArea, $pageType, $identity) as $item) {
            if ((string)($item['module'] ?? '') !== 'Weline_Theme') {
                continue;
            }
            if ((string)($item['code'] ?? '') !== 'footer-container') {
                continue;
            }
            $footerContainerItem = $item;
            break;
        }
        if ($footerContainerItem === null) {
            return $result;
        }
        if ($this->hasUserDeletedDecision($themeId, $footerContainerItem)) {
            return $result;
        }
        if ($this->widgetExists($themeId, $pageType, $identity, $status, $footerContainerItem)) {
            return $result;
        }

        $blocker = $this->resolveInstallBlocker($themeId, $footerContainerItem, $status, $componentArea);
        if ($blocker !== null) {
            if (($blocker['code'] ?? '') !== 'not_applicable') {
                $result['blockers'][] = [
                    'injection_key' => (string)($footerContainerItem['injection_key'] ?? ''),
                    'blocker' => $blocker,
                ];
            }

            return $result;
        }

        try {
            $nodeUid = $this->saveInjection($themeId, $footerContainerItem, $status);
            $this->markInitialHandled($themeId, $footerContainerItem, self::SOURCE_AUTO, true);
            $footerContainerItem['node_uid'] = $nodeUid;
            $footerContainerItem['status'] = $status;
            $footerContainerItem['injection_status'] = 'applied';
            $result['items'][] = $footerContainerItem;
            $result['applied'] = 1;
        } catch (\Throwable $e) {
            $result['blockers'][] = [
                'injection_key' => (string)($footerContainerItem['injection_key'] ?? ''),
                'blocker' => [
                    'code' => 'apply_failed',
                    'message' => $e->getMessage(),
                ],
            ];
        }

        return $result;
    }

    /**
     * 一键安装默认项：required && missing && !user_deleted（不装推荐类）。
     *
     * @param array<string,mixed> $identity
     * @return array{applied:int,skipped:int,blockers:list<array<string,mixed>>,items:list<array<string,mixed>>}
     */
    public function applyRequiredMissingForIdentity(
        int $themeId,
        string $pageType,
        array $identity = [],
        string $componentArea = PreviewContextService::AREA_FRONTEND,
        string $status = ThemeLayout::STATUS_DRAFT,
    ): array {
        $result = [
            'applied' => 0,
            'skipped' => 0,
            'blockers' => [],
            'items' => [],
        ];
        $theme = $this->loadTheme($themeId);
        if (!$theme || trim($pageType) === '') {
            return $result;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);

        foreach ($this->collectDeclarations($theme, $componentArea, $pageType, $identity) as $item) {
            if (empty($item['required'])) {
                $result['skipped']++;
                continue;
            }
            if ($this->hasUserDeletedDecision($themeId, $item)) {
                $result['skipped']++;
                continue;
            }
            if ($this->widgetExists($themeId, $pageType, $identity, $status, $item)) {
                $result['skipped']++;
                continue;
            }
            $blocker = $this->resolveInstallBlocker($themeId, $item, $status, $componentArea);
            if ($blocker !== null) {
                if (($blocker['code'] ?? '') !== 'not_applicable') {
                    $result['blockers'][] = [
                        'injection_key' => (string)($item['injection_key'] ?? ''),
                        'blocker' => $blocker,
                    ];
                }
                $result['skipped']++;
                continue;
            }
            try {
                $nodeUid = $this->saveInjection($themeId, $item, $status);
                $this->markInitialHandled($themeId, $item, self::SOURCE_MANUAL_APPLY, true);
                $item['node_uid'] = $nodeUid;
                $item['status'] = $status;
                $item['injection_status'] = 'applied';
                $result['items'][] = $item;
                $result['applied']++;
            } catch (\Throwable $e) {
                $result['blockers'][] = [
                    'injection_key' => (string)($item['injection_key'] ?? ''),
                    'blocker' => [
                        'code' => 'apply_failed',
                        'message' => $e->getMessage(),
                    ],
                ];
                $result['skipped']++;
            }
        }

        return $result;
    }

    /**
     * 编辑器 slot 工具条「初始化」：清除该 slot 的 user_deleted，并按 default_injections 显式回填。
     *
     * @param array<string,mixed> $identity
     * @return array{cleared_user_deleted:int,applied_defaults:int,items:list<array<string,mixed>>}
     */
    public function initSlotDefaultInjections(
        int $themeId,
        string $pageType,
        array $identity,
        string $slotId,
        string $componentArea = PreviewContextService::AREA_FRONTEND,
        string $status = ThemeLayout::STATUS_DRAFT,
    ): array {
        $result = [
            'cleared_user_deleted' => 0,
            'cleared_template_deleted' => 0,
            'applied_defaults' => 0,
            'items' => [],
        ];
        $slotId = trim($slotId);
        $pageType = trim($pageType);
        if ($themeId <= 0 || $pageType === '' || $slotId === '') {
            return $result;
        }

        $theme = $this->loadTheme($themeId);
        if (!$theme) {
            return $result;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);
        $result['cleared_user_deleted'] = $this->clearUserDeletedDecisionsForSlot(
            $themeId,
            $identity,
            $slotId,
            $componentArea,
            $pageType
        );
        $result['cleared_template_deleted'] = $this->clearTemplateDeletedTombstonesForSlot(
            $themeId,
            $pageType,
            $identity,
            $slotId,
            $componentArea,
        );

        foreach ($this->collectDeclarations($theme, $componentArea, $pageType, $identity) as $item) {
            if (trim((string)($item['slot_id'] ?? '')) !== $slotId) {
                continue;
            }
            // 插槽初始化只回填默认安装（required）项。
            if (empty($item['required'])) {
                continue;
            }
            if ($this->widgetExists($themeId, $pageType, $identity, $status, $item)) {
                continue;
            }
            $blocker = $this->resolveInstallBlocker($themeId, $item, $status, $componentArea);
            if ($blocker !== null) {
                continue;
            }

            try {
                $nodeUid = $this->saveInjection($themeId, $item, $status);
                $this->markInitialHandled($themeId, $item, self::SOURCE_MANUAL_APPLY, true);
                $item['node_uid'] = $nodeUid;
                $item['layout_id'] = 0;
                $item['status'] = $status;
                $result['items'][] = $item;
                $result['applied_defaults']++;
            } catch (\Throwable) {
                continue;
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $identity
     */
    public function clearUserDeletedDecisionsForSlot(
        int $themeId,
        array $identity,
        string $slotId,
        string $componentArea = PreviewContextService::AREA_FRONTEND,
        ?string $pageType = null,
    ): int {
        if ($themeId <= 0) {
            return 0;
        }

        $slotId = trim($slotId);
        if ($slotId === '') {
            return 0;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);
        $pageType = $pageType !== null ? trim($pageType) : null;

        try {
            $query = (clone $this->defaultInjectionRecord)->clearQuery()->clearData()
                ->where(ThemeWidgetDefaultInjection::schema_fields_THEME_ID, $themeId)
                ->where(ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA, $componentArea)
                ->where(ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_SCOPE, $identity['scope'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE, $identity['locale_code'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_TARGET_ID, $identity['target_id'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_SOURCE, self::SOURCE_USER_DELETED)
                ->where(ThemeWidgetDefaultInjection::schema_fields_SLOT_ID, $slotId);
            if ($pageType !== null && $pageType !== '') {
                $query->where(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, $pageType);
            }

            $rows = $query->select()->fetchArray();
            $count = 0;
            foreach (\is_array($rows) ? $rows : [] as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $recordId = (int)($row[ThemeWidgetDefaultInjection::schema_fields_ID] ?? 0);
                if ($recordId <= 0) {
                    continue;
                }
                $record = clone $this->defaultInjectionRecord;
                $record->clearQuery()->clearData()->load($recordId);
                if ($record->getId() > 0) {
                    $record->delete()->fetch();
                    $count++;
                }
            }

            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string,mixed> $identity
     */
    public function clearUserDeletedDecisions(
        int $themeId,
        array $identity,
        string $componentArea = PreviewContextService::AREA_FRONTEND,
        ?string $pageType = null,
    ): int {
        if ($themeId <= 0) {
            return 0;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);
        $pageType = $pageType !== null ? trim($pageType) : null;

        try {
            $query = (clone $this->defaultInjectionRecord)->clearQuery()->clearData()
                ->where(ThemeWidgetDefaultInjection::schema_fields_THEME_ID, $themeId)
                ->where(ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA, $componentArea)
                ->where(ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_SCOPE, $identity['scope'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE, $identity['locale_code'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_TARGET_ID, $identity['target_id'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_SOURCE, self::SOURCE_USER_DELETED);
            if ($pageType !== null && $pageType !== '') {
                $query->where(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, $pageType);
            }

            $rows = $query->select()->fetchArray();
            $count = 0;
            foreach (\is_array($rows) ? $rows : [] as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $recordId = (int)($row[ThemeWidgetDefaultInjection::schema_fields_ID] ?? 0);
                if ($recordId <= 0) {
                    continue;
                }
                $record = clone $this->defaultInjectionRecord;
                $record->clearQuery()->clearData()->load($recordId);
                if ($record->getId() > 0) {
                    $record->delete()->fetch();
                    $count++;
                }
            }

            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function applyMissingForLayout(
        int $themeId,
        string $pageType,
        array $identity = [],
        string $componentArea = PreviewContextService::AREA_FRONTEND,
        string $status = ThemeLayout::STATUS_DRAFT,
        bool $requiredOnly = true,
    ): int {
        $theme = $this->loadTheme($themeId);
        if (!$theme) {
            return 0;
        }

        $identity = $this->normalizeIdentity($identity);
        $componentArea = $this->normalizeComponentArea($componentArea);
        $applied = 0;

        foreach ($this->collectDeclarations($theme, $componentArea, $pageType, $identity) as $item) {
            if ($requiredOnly && empty($item['required'])) {
                continue;
            }
            if (!$this->injectionTargetNeedsFill($themeId, $pageType, $identity, $status, $item, $componentArea)) {
                continue;
            }
            if ($this->resolveInstallBlocker($themeId, $item, $status, $componentArea) !== null) {
                continue;
            }
            $this->saveInjection($themeId, $item, $status);
            $this->markInitialHandled($themeId, $item, self::SOURCE_AUTO, true);
            $applied++;
        }

        return $applied;
    }

    /**
     * 为所有已注册页面类型补齐缺失的默认部件（仅填空 slot，不覆盖已有配置）。
     */
    public function applyMissingForAllPageTypes(
        int $themeId,
        array $identity = [],
        string $componentArea = PreviewContextService::AREA_FRONTEND,
        string $status = ThemeLayout::STATUS_DRAFT,
        bool $requiredOnly = true,
    ): int {
        $applied = 0;
        foreach (array_keys(ThemeLayout::getPageTypes()) as $pageType) {
            $applied += $this->applyMissingForLayout(
                $themeId,
                (string)$pageType,
                $identity,
                $componentArea,
                $status,
                $requiredOnly
            );
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
            if (empty($item['required'])) {
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
            'locale_code' => $identity['locale_code'],
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

    private function saveInjection(int $themeId, array $item, string $status): string
    {
        if ($status === ThemeLayout::STATUS_PUBLISHED) {
            return '';
        }

        $componentArea = $this->normalizeComponentArea((string)($item['component_area'] ?? PreviewContextService::AREA_FRONTEND));
        $editorArea = $componentArea === PreviewContextService::AREA_BACKEND ? 'backend' : 'frontend';
        $context = $this->runtimeLayoutResolver->buildContext(
            $themeId,
            (string)$item['page_type'],
            $editorArea,
            (array)$item['identity'],
        );
        $saved = $this->layoutWriter->addWidget(
            $context,
            [
                'theme_id' => $themeId,
                'page_type' => $item['page_type'],
                'area' => $item['area'],
                'slot_id' => $item['slot_id'] !== '' ? $item['slot_id'] : null,
                'widget_code' => $item['code'],
                'widget_module' => $item['module'],
                'widget_type' => $item['type'],
                'config' => $item['config'] ?? [],
                'sort_order' => $item['sort_order'],
                'exclusive' => (bool)($item['exclusive'] ?? false),
                'is_active' => true,
            ],
            'system:widget-default-injection',
            '',
        );

        return (string)$saved['node_uid'];
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
                ->where(ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE, $identity['locale_code'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_TARGET_ID, $identity['target_id'])
                ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_MODULE, $module)
                ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_TYPE, $type)
                ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_CODE, $code)
                ->where(ThemeWidgetDefaultInjection::schema_fields_SLOT_ID, (string)($item['slot_id'] ?? '') ?: null)
                ->where(ThemeWidgetDefaultInjection::schema_fields_AREA, (string)($item['area'] ?? ThemeLayout::AREA_CONTENT) ?: ThemeLayout::AREA_CONTENT)
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
        foreach ([ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED] as $status) {
            if ($this->runtimeLayoutResolver->hasNoWidgetPlacements(
                $themeId,
                $pageType,
                $status,
                $identity,
                'frontend',
            )) {
                return true;
            }
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
                ->where(ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE, $identity['locale_code'])
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
                ->setData(ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE, $identity['locale_code'])
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
            'locale_code' => $injection['locale_code'] ?? $injection['locale'] ?? ($currentIdentity['locale_code'] ?? ''),
            'target_type' => $injection['target_type'] ?? ($currentIdentity['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL),
            'target_id' => $injection['target_id'] ?? ($currentIdentity['target_id'] ?? 0),
        ];
        if ($currentPageType !== null && $currentIdentityProvided) {
            $identitySource['scope'] = $currentIdentity['scope'];
            $identitySource['locale_code'] = $currentIdentity['locale_code'];
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
            'locale_code' => $identity['locale_code'],
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
            'install_mode' => !empty($injection['required']) ? 'default' : 'recommend',
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
            $copy['locale_code'] = $layoutIdentity['locale_code'];
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

        // Dashboard views are identities in their own right.  A newly created
        // view can exist before a ThemeLayout row has been persisted, so the
        // layout tables alone are not a complete source for the UI's
        // “apply to all identities” action.
        if ($pageType === self::DASHBOARD_PAGE_TYPE) {
            $this->addDashboardViewIdentities($found, $identity);
        }

        return array_values($found);
    }

    /**
     * @param array<string,array{layout_option:string,scope:string,target_type:string,target_id:int}> $found
     * @param array{layout_option:string,scope:string,target_type:string,target_id:int} $identity
     */
    private function addDashboardViewIdentities(array &$found, array $identity): void
    {
        $dashboardViewClass = 'Weline\\Dashboard\\Model\\DashboardView';
        if (!class_exists($dashboardViewClass)) {
            return;
        }

        try {
            $dashboardView = clone ObjectManager::getInstance($dashboardViewClass);
            $query = $dashboardView->clearQuery()->clearData()
                ->where($dashboardViewClass::schema_fields_IS_ACTIVE, 1);
            if ($identity['target_type'] === 'website' && $identity['target_id'] > 0) {
                $query->where($dashboardViewClass::schema_fields_WEBSITE_ID, $identity['target_id']);
            }
            foreach ($query->select()->fetchArray() ?: [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $viewId = (int)($row[$dashboardViewClass::schema_fields_ID] ?? 0);
                if ($viewId <= 0) {
                    continue;
                }
                $websiteId = max(0, (int)($row[$dashboardViewClass::schema_fields_WEBSITE_ID] ?? 0));
                $dashboardIdentity = $this->normalizeIdentity([
                    'layout_option' => $identity['layout_option'],
                    'scope' => 'dashboard_view:' . $viewId,
                    'locale_code' => $identity['locale_code'],
                    'target_type' => 'website',
                    'target_id' => $websiteId,
                ]);
                $found[$this->identityMapKey($dashboardIdentity)] = $dashboardIdentity;
            }
        } catch (\Throwable) {
            // Dashboard is optional for Theme; existing layout identities still work without it.
        }
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
                'locale_code' => $row['locale_code'] ?? '',
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
            $identity['locale_code'],
            $identity['target_type'],
            (string)$identity['target_id'],
        ]);
    }

    private function widgetExists(int $themeId, string $pageType, array $identity, string $status, array $item): bool
    {
        $componentArea = $this->normalizeComponentArea((string)($item['component_area'] ?? PreviewContextService::AREA_FRONTEND));
        foreach ($this->iterateScopedWidgets($themeId, $pageType, $identity, $status, $componentArea) as $widget) {
            if ((string)($widget['widget_module'] ?? '') === (string)$item['module']
                && (string)($widget['widget_type'] ?? '') === (string)$item['type']
                && (string)($widget['widget_code'] ?? '') === (string)$item['code']
                && (bool)($widget['is_active'] ?? true)
            ) {
                return true;
            }
        }

        return $this->widgetExistsAsTemplateInline($themeId, $item, $componentArea);
    }

    /**
     * @param array<string,mixed> $item
     */
    private function widgetExistsAsTemplateInline(int $themeId, array $item, string $componentArea): bool
    {
        $slotId = trim((string)($item['slot_id'] ?? ''));
        $code = trim((string)($item['code'] ?? ''));
        $type = trim((string)($item['type'] ?? ''));
        if ($slotId === '' || $code === '') {
            return false;
        }

        $slotMeta = $this->findThemeSlotMeta($themeId, $slotId, $componentArea);
        if ($slotMeta === null) {
            return false;
        }

        $meta = is_array($slotMeta['meta'] ?? null) ? $slotMeta['meta'] : [];
        $widgets = $slotMeta['template_widgets'] ?? ($meta['template_widgets'] ?? []);
        if (!is_array($widgets)) {
            return false;
        }

        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $widgetCode = trim((string)($widget['code'] ?? $widget['name'] ?? ''));
            $widgetType = trim((string)($widget['type'] ?? ''));
            if ($widgetCode !== $code) {
                continue;
            }
            if ($type !== '' && $widgetType !== '' && $widgetType !== $type) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * 已应用且落库的布局节点（非模板直嵌）返回 node_uid，供应用 Tab 卸载/定位。
     *
     * @param array<string,mixed> $item
     * @return array{node_uid:string,layout_id:int,sort_order:int}|null
     */
    private function resolveAppliedLayoutNode(
        int $themeId,
        string $pageType,
        array $identity,
        string $status,
        array $item,
        string $componentArea,
    ): ?array {
        $slotId = trim((string)($item['slot_id'] ?? ''));
        $componentArea = $this->normalizeComponentArea($componentArea);

        foreach ($this->iterateScopedWidgets($themeId, $pageType, $identity, $status, $componentArea) as $widget) {
            if ((string)($widget['widget_module'] ?? '') !== (string)($item['module'] ?? '')
                || (string)($widget['widget_type'] ?? '') !== (string)($item['type'] ?? '')
                || (string)($widget['widget_code'] ?? '') !== (string)($item['code'] ?? '')
                || !((bool)($widget['is_active'] ?? true))
            ) {
                continue;
            }
            if ($slotId !== '' && (string)($widget['slot_id'] ?? '') !== $slotId) {
                continue;
            }
            $nodeUid = strtolower(trim((string)($widget['node_uid'] ?? '')));
            if ($nodeUid === '' || preg_match('/^[a-f0-9]{32}$/D', $nodeUid) !== 1) {
                continue;
            }

            return [
                'node_uid' => $nodeUid,
                'layout_id' => (int)($widget['layout_id'] ?? 0),
                'sort_order' => (int)($widget['sort_order'] ?? 0),
            ];
        }

        return null;
    }

    private function slotHasWidget(
        int $themeId,
        string $pageType,
        array $identity,
        string $status,
        string $slotId,
        string $area,
        string $componentArea = PreviewContextService::AREA_FRONTEND,
    ): bool {
        if ($slotId === '') {
            return false;
        }

        $componentArea = $this->normalizeComponentArea($componentArea);
        foreach ($this->iterateScopedWidgets($themeId, $pageType, $identity, $status, $componentArea) as $widget) {
            if ((string)($widget['slot_id'] ?? '') !== $slotId) {
                continue;
            }
            if ((string)($widget['area'] ?? ThemeLayout::AREA_CONTENT) !== $area) {
                continue;
            }
            if ((bool)($widget['is_active'] ?? true)) {
                return true;
            }
        }

        return false;
    }

    private function widgetExistsInSlot(
        int $themeId,
        string $pageType,
        array $identity,
        string $status,
        array $item
    ): bool {
        $slotId = trim((string)($item['slot_id'] ?? ''));
        if ($slotId === '') {
            return $this->widgetExists($themeId, $pageType, $identity, $status, $item);
        }

        $area = trim((string)($item['area'] ?? ThemeLayout::AREA_CONTENT));
        $componentArea = $this->normalizeComponentArea((string)($item['component_area'] ?? PreviewContextService::AREA_FRONTEND));
        foreach ($this->iterateScopedWidgets($themeId, $pageType, $identity, $status, $componentArea) as $widget) {
            if ((string)($widget['slot_id'] ?? '') !== $slotId) {
                continue;
            }
            if ((string)($widget['area'] ?? ThemeLayout::AREA_CONTENT) !== $area) {
                continue;
            }
            if ((string)($widget['widget_module'] ?? '') === (string)$item['module']
                && (string)($widget['widget_type'] ?? '') === (string)$item['type']
                && (string)($widget['widget_code'] ?? '') === (string)$item['code']
                && (bool)($widget['is_active'] ?? true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $identity
     * @return \Generator<int,array<string,mixed>>
     */
    private function iterateScopedWidgets(
        int $themeId,
        string $pageType,
        array $identity,
        string $status,
        string $componentArea,
    ): \Generator {
        try {
            $editorArea = $this->normalizeComponentArea($componentArea) === PreviewContextService::AREA_BACKEND
                ? 'backend'
                : 'frontend';
            $layout = $this->runtimeLayoutResolver->resolveLayout(
                $themeId,
                $pageType,
                $status,
                $editorArea,
                $this->normalizeIdentity($identity),
            );
            foreach ($layout as $areaData) {
                if (!is_array($areaData['widgets'] ?? null)) {
                    continue;
                }
                foreach ($areaData['widgets'] as $widget) {
                    if (is_array($widget)) {
                        yield $widget;
                    }
                }
            }
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function clearTemplateDeletedTombstonesForSlot(
        int $themeId,
        string $pageType,
        array $identity,
        string $slotId,
        string $componentArea,
    ): int {
        $editorArea = $this->normalizeComponentArea($componentArea) === PreviewContextService::AREA_BACKEND
            ? 'backend'
            : 'frontend';
        $context = $this->runtimeLayoutResolver->buildContext(
            $themeId,
            $pageType,
            $editorArea,
            $this->normalizeIdentity($identity),
        );

        try {
            return $this->layoutWriter->clearTemplateDeletedTombstonesForSlot(
                $context,
                $slotId,
                'system:widget-default-injection',
                '',
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array{code:string,message:string}|null
     */
    private function resolveInstallBlocker(
        int $themeId,
        array $item,
        string $status,
        string $componentArea = PreviewContextService::AREA_FRONTEND,
    ): ?array {
        $module = trim((string)($item['module'] ?? ''));
        if ($module !== '' && !isset(Env::getInstance()->getModuleList()[$module])) {
            return [
                'code' => 'module_missing',
                'message' => 'widget_module_not_installed',
            ];
        }

        $slotId = trim((string)($item['slot_id'] ?? ''));
        $area = trim((string)($item['area'] ?? ThemeLayout::AREA_CONTENT));
        if ($area === '') {
            $area = ThemeLayout::AREA_CONTENT;
        }
        $componentArea = $this->normalizeComponentArea($componentArea);
        $pageType = (string)($item['page_type'] ?? '');
        $identity = $this->normalizeIdentity((array)($item['identity'] ?? []));

        if ($slotId === '') {
            return null;
        }

        $slotMeta = $this->findThemeSlotMeta($themeId, $slotId, $componentArea);
        if ($slotMeta === null) {
            return [
                'code' => 'not_applicable',
                'message' => 'layout_slot_missing',
            ];
        }

        if ($this->slotBlocksInjectionDueToTemplateInline($themeId, $slotId, $componentArea, $item)) {
            return [
                'code' => 'template_inline',
                'message' => 'slot_has_template_inline_widgets',
            ];
        }

        $accept = $slotMeta['accept'] ?? [];
        if (is_array($accept) && $accept !== [] && !$this->slotAcceptsInjection($accept, $item)) {
            return [
                'code' => 'slot_mismatch',
                'message' => 'slot_does_not_accept_widget',
            ];
        }

        $exclusive = (bool)($item['exclusive'] ?? false)
            || !empty($slotMeta['exclusive'])
            || in_array($slotId, self::EXCLUSIVE_SLOTS, true);
        if ($exclusive && $this->slotHasWidget($themeId, $pageType, $identity, $status, $slotId, $area, $componentArea)) {
            return [
                'code' => 'slot_occupied',
                'message' => 'exclusive_slot_occupied',
            ];
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findThemeSlotMeta(int $themeId, string $slotId, string $componentArea): ?array
    {
        $theme = $this->loadTheme($themeId);
        if (!$theme || $slotId === '') {
            return null;
        }

        try {
            /** @var ThemeResourceCatalog $catalog */
            $catalog = ObjectManager::getInstance(ThemeResourceCatalog::class);
            foreach ($catalog->getSlots($componentArea, $theme) as $slot) {
                if (!is_array($slot)) {
                    continue;
                }
                if ((string)($slot['id'] ?? '') === $slotId) {
                    return $slot;
                }
            }
        } catch (\Throwable) {
            // Keep resolving through the component catalog below.
        }

        try {
            return $this->componentCatalog->findSlot($slotId, $componentArea, $theme);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $accept
     * @param array<string,mixed> $item
     */
    private function slotAcceptsInjection(array $accept, array $item): bool
    {
        $widget = is_array($item['widget'] ?? null) ? $item['widget'] : [];
        $candidates = [
            $item['code'] ?? null,
            $item['type'] ?? null,
            $item['slot'] ?? null,
            $widget['code'] ?? null,
            $widget['type'] ?? null,
            $widget['slot'] ?? null,
        ];

        foreach ([$item['supports'] ?? [], $item['position'] ?? [], $widget['supports'] ?? [], $widget['position'] ?? []] as $list) {
            if (!is_array($list)) {
                $list = [$list];
            }
            foreach ($list as $value) {
                $candidates[] = $value;
            }
        }

        $slots = $widget['slots'] ?? [];
        if (is_array($slots)) {
            foreach ($slots as $key => $slot) {
                if (is_string($key)) {
                    $candidates[] = $key;
                }
                if (is_array($slot)) {
                    $candidates[] = $slot['id'] ?? null;
                    $candidates[] = $slot['code'] ?? null;
                    continue;
                }
                $candidates[] = $slot;
            }
        }

        $normalizedCandidates = [];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) || is_object($candidate)) {
                continue;
            }
            $candidate = strtolower(trim((string)$candidate));
            if ($candidate !== '') {
                $normalizedCandidates[$candidate] = $candidate;
            }
        }
        if ($normalizedCandidates === []) {
            return true;
        }

        foreach ($accept as $token) {
            $token = strtolower(trim((string)$token));
            if ($token === '' || $token === '*') {
                return true;
            }
            if (isset($normalizedCandidates[$token])) {
                return true;
            }
        }

        return false;
    }

    private function injectionTargetNeedsFill(
        int $themeId,
        string $pageType,
        array $identity,
        string $status,
        array $item,
        string $componentArea = PreviewContextService::AREA_FRONTEND,
    ): bool {
        if ($this->hasUserDeletedDecision($themeId, $item)) {
            return false;
        }

        $slotId = trim((string)($item['slot_id'] ?? ''));
        $area = trim((string)($item['area'] ?? ThemeLayout::AREA_CONTENT));
        // Layout region (header/content/footer) is not frontend/backend.
        // Template inline lookup must use the caller component area; dashboard
        // layout regions still resolve against the backend catalog.
        $componentArea = str_starts_with(strtolower($area), 'dashboard')
            ? PreviewContextService::AREA_BACKEND
            : $this->normalizeComponentArea($componentArea);

        // 独占槽：模板内嵌 w:widget 优先于 default_injections；多部件槽仅拦截同 code 直嵌
        if ($slotId !== '' && $this->slotBlocksInjectionDueToTemplateInline($themeId, $slotId, $componentArea, $item)) {
            return false;
        }

        $exclusive = (bool)($item['exclusive'] ?? false);
        if ($slotId !== '') {
            if ($exclusive || in_array($slotId, self::EXCLUSIVE_SLOTS, true)) {
                return !$this->slotHasWidget(
                    $themeId,
                    $pageType,
                    $identity,
                    $status,
                    $slotId,
                    $area,
                    $componentArea
                );
            }

            return !$this->widgetExistsInSlot($themeId, $pageType, $identity, $status, $item);
        }

        return !$this->widgetExists($themeId, $pageType, $identity, $status, $item);
    }

    private function slotHasTemplateInlineWidgets(int $themeId, string $slotId, string $componentArea): bool
    {
        $theme = $this->loadTheme($themeId);
        if (!$theme || $slotId === '') {
            return false;
        }

        try {
            /** @var ThemeResourceCatalog $catalog */
            $catalog = ObjectManager::getInstance(ThemeResourceCatalog::class);
            foreach ($catalog->getSlots($componentArea, $theme) as $slot) {
                if ((string)($slot['id'] ?? '') !== $slotId) {
                    continue;
                }
                if (!empty($slot['has_template_widgets'])) {
                    return true;
                }
                $meta = is_array($slot['meta'] ?? null) ? $slot['meta'] : [];
                if (!empty($meta['has_template_widgets'])) {
                    return true;
                }
                $widgets = $slot['template_widgets'] ?? ($meta['template_widgets'] ?? []);
                if (is_array($widgets) && $widgets !== []) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * 独占槽有模板直嵌 w:widget 时整槽拦截 default_injections；
     * 多部件槽（multiple）仅拦截与直嵌同 code 的声明，其余可并排落库。
     *
     * @param array<string,mixed> $item
     */
    private function slotBlocksInjectionDueToTemplateInline(
        int $themeId,
        string $slotId,
        string $componentArea,
        array $item,
    ): bool {
        if (!$this->slotHasTemplateInlineWidgets($themeId, $slotId, $componentArea)) {
            return false;
        }

        $slotMeta = $this->findThemeSlotMeta($themeId, $slotId, $componentArea);
        if ($slotMeta === null) {
            return true;
        }

        $exclusive = (bool)($item['exclusive'] ?? false)
            || !empty($slotMeta['exclusive'])
            || in_array($slotId, self::EXCLUSIVE_SLOTS, true);
        if ($exclusive) {
            return true;
        }

        if (!empty($slotMeta['multiple'])) {
            return $this->widgetExistsAsTemplateInline($themeId, $item, $componentArea);
        }

        return true;
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
            (string)($item['locale_code'] ?? ''),
            (string)$item['target_type'],
            (string)$item['target_id'],
            (string)$item['slot_id'],
            (string)$item['area'],
            (string)$item['sort_order'],
        ]));
    }

    private function identityMatches(array $left, array $right): bool
    {
        $left = $this->normalizeIdentity($left);
        $right = $this->normalizeIdentity($right);
        return $left['layout_option'] === $right['layout_option']
            && $left['scope'] === $right['scope']
            && $left['locale_code'] === $right['locale_code']
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
        $localeCode = trim((string)($identity['locale_code'] ?? $identity['locale'] ?? ''));
        $targetType = trim((string)($identity['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL));

        return [
            'layout_option' => $layoutOption !== '' ? $layoutOption : 'default',
            'scope' => $scope !== '' ? $scope : 'default',
            'locale_code' => $localeCode === 'default' ? '' : $localeCode,
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
