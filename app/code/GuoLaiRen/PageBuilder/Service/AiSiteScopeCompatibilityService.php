<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use Weline\Framework\Manager\ObjectManager;

class AiSiteScopeCompatibilityService
{
    public const PAGE_TYPES_USER_CUSTOMIZED_KEY = 'page_types_user_customized';
    public const SELECTED_SKILL_CODES_KEY = 'selected_skill_codes';
    public const WORKSPACE_STATUS_PREPARING = 'preparing';
    public const WORKSPACE_STATUS_BUILDING = 'building';
    public const WORKSPACE_STATUS_EDITING = 'editing';
    public const WORKSPACE_STATUS_CAN_PUBLISH = 'can_publish';
    public const WORKSPACE_STATUS_PUBLISHING = 'publishing';
    public const WORKSPACE_STATUS_PUBLISHED = 'published';
    public const WORKSPACE_STATUS_FAILED = 'failed';
    public function __construct(
        private readonly LayoutConfigNormalizer $layoutConfigNormalizer,
        private readonly ?AiSiteHtmlBlocksBuildService $aiSiteHtmlBlocksBuildService = null,
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function normalizeScope(array $scope): array
    {
        $normalized = $scope;
        $normalized[self::PAGE_TYPES_USER_CUSTOMIZED_KEY] = $this->normalizePageTypesUserCustomized(
            $scope[self::PAGE_TYPES_USER_CUSTOMIZED_KEY] ?? null
        ) ? 1 : 0;
        $normalized[self::SELECTED_SKILL_CODES_KEY] = $this->normalizeSelectedSkillCodes(
            $scope[self::SELECTED_SKILL_CODES_KEY] ?? []
        );
        $normalized['page_types'] = $this->resolveScopedPageTypes($scope);
        $normalized['page_type_layouts'] = $this->normalizePageTypeLayouts(
            $scope['page_type_layouts'] ?? [],
            $normalized['page_types']
        );
        $normalized['pagebuilder_pages_by_type'] = $this->normalizePagebuilderPagesByType(
            $scope['pagebuilder_pages_by_type'] ?? []
        );
        $normalized['virtual_pages_by_type'] = $this->normalizeVirtualPagesByType(
            $scope['virtual_pages_by_type'] ?? [],
            $normalized['page_types']
        );
        $normalized['draft_website_id'] = \max(
            (int)($scope['draft_website_id'] ?? 0),
            (int)($scope['website_id'] ?? 0),
            (int)($scope['selected_website_id'] ?? 0)
        );
        $normalized['workspace_status'] = $this->normalizeWorkspaceStatus((string)($scope['workspace_status'] ?? ''));
        if (!\is_array($normalized['active_operation'] ?? null)) {
            $normalized['active_operation'] = [];
        }
        if (!\is_array($normalized['build_summary'] ?? null)) {
            $normalized['build_summary'] = [];
        }

        $selection = $this->resolvePreviewSelection(
            $normalized['pagebuilder_pages_by_type'],
            (int)($scope['preview_page_id'] ?? 0),
            (string)($scope['preview_page_type'] ?? '')
        );
        $normalized['preview_page_id'] = $selection['preview_page_id'];
        $normalized['preview_page_type'] = $this->resolvePreviewPageType(
            $normalized['virtual_pages_by_type'],
            (string)($scope['preview_page_type'] ?? $selection['preview_page_type'])
        );
        $normalized['preview_page_options'] = $this->buildPreviewPageOptions($normalized['pagebuilder_pages_by_type']);

        if (!\is_array($normalized['website_profile'] ?? null)) {
            $normalized['website_profile'] = [];
        }

        $normalized['workspace_track'] = $this->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $normalized['extra_page_types_panel_open'] = ((int)($scope['extra_page_types_panel_open'] ?? 0) === 1) ? 1 : 0;

        return $normalized;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function hasConfirmedStageOnePlanForBuildPlan(array $scope): bool
    {
        $confirmedBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        if ($confirmedBlueprint === []) {
            return false;
        }

        $confirmedSignature = \trim((string)($scope['execution_blueprint_confirmed_signature'] ?? ''));
        if ($confirmedSignature === '') {
            $confirmedSignature = \trim((string)($confirmedBlueprint['signature'] ?? ''));
        }

        if ((int)($scope['plan_confirmed'] ?? 0) === 1) {
            return true;
        }

        $confirmedAt = \trim((string)($scope['execution_blueprint_confirmed_at'] ?? $scope['plan_confirmed_at'] ?? ''));
        if ($confirmedAt === '' && $confirmedSignature === '') {
            return false;
        }

        $draftBlueprint = \is_array($scope['execution_blueprint_draft'] ?? null) ? $scope['execution_blueprint_draft'] : [];
        return $this->stageOneDraftMatchesConfirmedBlueprint($draftBlueprint, $confirmedBlueprint, $confirmedSignature);
    }

    /**
     * Backend-owned existence check for a persisted stage-one plan.
     *
     * @param array<string, mixed> $scope
     */
    public function hasPersistedStageOnePlan(array $scope): bool
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $executionBlueprintDraft = \is_array($scope['execution_blueprint_draft'] ?? null) ? $scope['execution_blueprint_draft'] : [];
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];

        return $this->isUsableStageOnePlanJson($planJson)
            || $executionBlueprintDraft !== []
            || $executionBlueprint !== [];
    }

    /**
     * A markdown note or legacy/test artifact is not enough to skip stage-one
     * generation. The queue may only treat a persisted plan as reusable when
     * the strong-contract sections needed by downstream page planning exist.
     *
     * @param array<string, mixed> $planJson
     */
    public function isUsableStageOnePlanJson(array $planJson): bool
    {
        if ($planJson === []) {
            return false;
        }

        $expansion = \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [];
        $themeDesign = \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [];
        $colorScheme = \is_array($themeDesign['color_scheme'] ?? null) ? $themeDesign['color_scheme'] : [];
        $typography = \is_array($themeDesign['typography_spacing_radius'] ?? null) ? $themeDesign['typography_spacing_radius'] : [];
        $sharedComponents = \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [];

        foreach (['original_brief', 'expanded_brief', 'planning_summary', 'site_goal'] as $field) {
            if (\trim((string)($expansion[$field] ?? '')) === '') {
                return false;
            }
        }
        if (!\is_array($expansion['page_strategy'] ?? null) || $expansion['page_strategy'] === []) {
            return false;
        }
        foreach (['theme_purpose', 'selection_reason'] as $field) {
            if (\trim((string)($themeDesign[$field] ?? '')) === '') {
                return false;
            }
        }
        foreach (['primary', 'accent'] as $field) {
            if (\trim((string)($colorScheme[$field] ?? '')) === '') {
                return false;
            }
        }
        foreach (['font_family', 'spacing_scale'] as $field) {
            if (\trim((string)($typography[$field] ?? '')) === '') {
                return false;
            }
        }
        if (!\is_array($themeDesign['visual_keywords'] ?? null) || $themeDesign['visual_keywords'] === []) {
            return false;
        }
        foreach (['header', 'footer'] as $component) {
            $componentPlan = \is_array($sharedComponents[$component] ?? null) ? $sharedComponents[$component] : [];
            if (
                \trim((string)($componentPlan['goal'] ?? '')) === ''
                || \trim((string)($componentPlan['implementation_detail'] ?? $componentPlan['implementation_note'] ?? '')) === ''
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function normalizeConfirmedPlanFlag(array $scope): array
    {
        if ($this->hasConfirmedStageOnePlanForBuildPlan($scope)) {
            $scope['plan_confirmed'] = 1;
            $confirmedAt = \trim((string)($scope['execution_blueprint_confirmed_at'] ?? ''));
            if ($confirmedAt !== '' && \trim((string)($scope['plan_confirmed_at'] ?? '')) === '') {
                $scope['plan_confirmed_at'] = $confirmedAt;
            }
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $draftBlueprint
     * @param array<string, mixed> $confirmedBlueprint
     */
    private function stageOneDraftMatchesConfirmedBlueprint(
        array $draftBlueprint,
        array $confirmedBlueprint,
        string $confirmedSignature
    ): bool {
        if ($draftBlueprint === []) {
            return true;
        }

        $draftSignature = \trim((string)($draftBlueprint['signature'] ?? ''));
        if ($draftSignature !== '' && $confirmedSignature !== '') {
            return $draftSignature === $confirmedSignature;
        }

        return $draftBlueprint == $confirmedBlueprint;
    }

    public const WORKSPACE_TRACK_VIRTUAL_THEME = 'virtual_theme';
    public const WORKSPACE_TRACK_HTML_BLOCKS = 'html_blocks';

    public function normalizeWorkspaceTrack(string $raw): string
    {
        return self::WORKSPACE_TRACK_VIRTUAL_THEME;
    }

    public function normalizeStage(string $stage): string
    {
        return match (\trim($stage)) {
            'plan' => 'plan',
            'visual_edit' => 'visual_edit',
            'publish' => 'publish',
            default => 'plan',
        };
    }

    public function normalizeWorkspaceStatus(string $status): string
    {
        return match (\trim($status)) {
            self::WORKSPACE_STATUS_BUILDING => self::WORKSPACE_STATUS_BUILDING,
            self::WORKSPACE_STATUS_EDITING => self::WORKSPACE_STATUS_EDITING,
            self::WORKSPACE_STATUS_CAN_PUBLISH => self::WORKSPACE_STATUS_CAN_PUBLISH,
            self::WORKSPACE_STATUS_PUBLISHING => self::WORKSPACE_STATUS_PUBLISHING,
            self::WORKSPACE_STATUS_PUBLISHED => self::WORKSPACE_STATUS_PUBLISHED,
            self::WORKSPACE_STATUS_FAILED => self::WORKSPACE_STATUS_FAILED,
            default => self::WORKSPACE_STATUS_PREPARING,
        };
    }

    /**
     * @return list<string>
     */
    public function resolveScopedPageTypes(array $scope): array
    {
        $rawPageTypes = $scope['page_types'] ?? $scope['recommended_pages'] ?? [];
        $providedPageTypes = $this->extractAllowedPageTypes($rawPageTypes);
        $pageTypesUserCustomized = $this->normalizePageTypesUserCustomized($scope[self::PAGE_TYPES_USER_CUSTOMIZED_KEY] ?? null);

        if ($providedPageTypes === []) {
            return $pageTypesUserCustomized ? [Page::TYPE_HOME] : $this->defaultPageTypes();
        }

        if (!$pageTypesUserCustomized && $this->matchesLegacyDefaultPageTypes($providedPageTypes)) {
            return $this->defaultPageTypes();
        }

        return $this->normalizePageTypes($providedPageTypes);
    }

    public function normalizePageTypesUserCustomized(mixed $raw): bool
    {
        if (\is_bool($raw)) {
            return $raw;
        }

        if (\is_int($raw) || \is_float($raw)) {
            return (int)$raw === 1;
        }

        if (\is_string($raw)) {
            return \in_array(\strtolower(\trim($raw)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function normalizePageTypes(mixed $raw): array
    {
        $pageTypes = $this->extractAllowedPageTypes($raw);

        if ($pageTypes === []) {
            $pageTypes = $this->defaultPageTypes();
        }

        if (!\in_array(Page::TYPE_HOME, $pageTypes, true)) {
            \array_unshift($pageTypes, Page::TYPE_HOME);
        }

        return \array_values(\array_unique($pageTypes));
    }

    /**
     * @return list<string>
     */
    private function defaultPageTypes(): array
    {
        return \array_keys(Page::getPageTypes());
    }

    /**
     * @return list<string>
     */
    private function legacyDefaultPageTypes(): array
    {
        return [
            Page::TYPE_HOME,
            Page::TYPE_ABOUT,
            Page::TYPE_CONTACT,
        ];
    }

    /**
     * @return list<string>
     */
    private function extractAllowedPageTypes(mixed $raw): array
    {
        if (\is_array($raw)) {
            $items = $raw;
        } elseif (\is_string($raw) && \trim($raw) !== '') {
            $decoded = \json_decode($raw, true);
            $items = \is_array($decoded) ? $decoded : (\preg_split('/[\s,]+/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        } else {
            $items = [];
        }

        $allowed = \array_keys(Page::getPageTypes());
        $pageTypes = [];
        foreach ($items as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $pageType = \trim((string)$item);
            if ($pageType === '' || !\in_array($pageType, $allowed, true) || \in_array($pageType, $pageTypes, true)) {
                continue;
            }
            $pageTypes[] = $pageType;
        }

        return $pageTypes;
    }

    /**
     * @return list<string>
     */
    public function normalizeSelectedSkillCodes(mixed $raw): array
    {
        if (\is_array($raw)) {
            $items = $raw;
        } elseif (\is_string($raw) && \trim($raw) !== '') {
            $decoded = \json_decode($raw, true);
            $items = \is_array($decoded) ? $decoded : \preg_split('/[\s,;]+/', $raw);
            if (!\is_array($items)) {
                $items = [];
            }
        } else {
            $items = [];
        }

        $codes = [];
        foreach ($items as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $code = \trim((string)$item);
            if ($code === '' || !\preg_match('/^[A-Za-z0-9_.-]+$/', $code) || \in_array($code, $codes, true)) {
                continue;
            }
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * @param list<string> $pageTypes
     */
    private function matchesLegacyDefaultPageTypes(array $pageTypes): bool
    {
        return $this->samePageTypeSet($pageTypes, $this->legacyDefaultPageTypes());
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function samePageTypeSet(array $left, array $right): bool
    {
        \sort($left);
        \sort($right);

        return $left === $right;
    }

    /**
     * @param mixed $raw
     * @param list<string> $pageTypes
     * @return array<string, array<string, mixed>>
     */
    public function normalizePageTypeLayouts(mixed $raw, array $pageTypes): array
    {
        if (\is_array($raw)) {
            $layouts = $raw;
        } elseif (\is_string($raw) && \trim($raw) !== '') {
            $decoded = \json_decode($raw, true);
            $layouts = \is_array($decoded) ? $decoded : [];
        } else {
            $layouts = [];
        }

        $normalized = [];
        foreach ($pageTypes as $pageType) {
            $normalized[$pageType] = $this->normalizeLayoutConfig($layouts[$pageType] ?? [], $pageType);
        }

        foreach ($layouts as $pageType => $layout) {
            if (!\is_string($pageType) || isset($normalized[$pageType])) {
                continue;
            }
            $normalized[$pageType] = $this->normalizeLayoutConfig($layout, $pageType);
        }

        return $normalized;
    }

    /**
     * @param mixed $raw
     * @return array<string, array{page_id:int,website_id:int,type:string,name:string,title:string,handle:string}>
     */
    public function normalizePagebuilderPagesByType(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        if (\is_array($raw['pagebuilder_pages_by_type'] ?? null)) {
            $raw = $raw['pagebuilder_pages_by_type'];
        }

        $pages = [];
        foreach ($raw as $pageType => $pageData) {
            if (!\is_string($pageType) || !\is_array($pageData)) {
                continue;
            }

            $resolvedType = \trim((string)($pageData['type'] ?? $pageData['page_type'] ?? $pageType));
            if ($resolvedType === '') {
                continue;
            }

            $pageId = (int)($pageData['page_id'] ?? $pageData['id'] ?? 0);
            if ($pageId <= 0) {
                continue;
            }

            $pages[$resolvedType] = [
                'page_id' => $pageId,
                'website_id' => (int)($pageData['website_id'] ?? 0),
                'type' => $resolvedType,
                'name' => \trim((string)($pageData['name'] ?? '')),
                'title' => \trim((string)($pageData['title'] ?? '')),
                'handle' => \trim((string)($pageData['handle'] ?? '')),
            ];
        }

        return $pages;
    }

    /**
     * @param mixed $raw
     * @param list<string> $pageTypes
     * @return array<string, array<string, mixed>>
     */
    public function normalizeVirtualPagesByType(mixed $raw, array $pageTypes = []): array
    {
        $rows = \is_array($raw) ? $raw : [];
        $normalized = [];

        foreach ($pageTypes as $pageType) {
            $normalized[$pageType] = $this->normalizeVirtualPageRecord($rows[$pageType] ?? [], $pageType);
        }

        foreach ($rows as $pageType => $row) {
            if (!\is_string($pageType) || isset($normalized[$pageType])) {
                continue;
            }
            $normalized[$pageType] = $this->normalizeVirtualPageRecord($row, $pageType);
        }

        return $normalized;
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $scope
     * @return array<string, array<string, mixed>>
     */
    public function buildVirtualPagesByType(
        array $pageTypes,
        array $scope = [],
        bool $allowAiPlaceholderGeneration = true
    ): array
    {
        $existing = $this->normalizeVirtualPagesByType($scope['virtual_pages_by_type'] ?? [], $pageTypes);
        $layouts = $this->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $siteTitle = \trim((string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? ''));
        $contentLocale = $this->resolveContentLocale($scope, $websiteProfile);
        $blocksBuilder = $this->aiSiteHtmlBlocksBuildService ?? ObjectManager::getInstance(AiSiteHtmlBlocksBuildService::class);

        foreach ($pageTypes as $pageType) {
            $record = $existing[$pageType] ?? $this->normalizeVirtualPageRecord([], $pageType);
            $defaultLabel = (string)(Page::getPageTypes()[$pageType] ?? $pageType);
            $localizedLabel = $this->localizePageTypeLabel($pageType, $contentLocale);
            $currentTitle = \trim((string)($record['title'] ?? ''));
            if ($currentTitle === '' || ($currentTitle === $defaultLabel && $localizedLabel !== '' && $localizedLabel !== $defaultLabel)) {
                $label = $localizedLabel !== '' ? $localizedLabel : $defaultLabel;
                $record['title'] = $pageType === Page::TYPE_HOME
                    ? ($siteTitle !== '' ? $siteTitle : $label)
                    : $label;
            }
            if (\trim((string)($record['handle'] ?? '')) === '') {
                $record['handle'] = Page::getDefaultHandleForType($pageType);
            }
            if (\trim((string)($record['locale'] ?? '')) === '') {
                $record['locale'] = (string)($websiteProfile['default_locale'] ?? 'en_US');
            }
            if (\trim((string)($record['style_code'] ?? '')) === '') {
                $record['style_code'] = 'default';
            }
            if (!\is_array($record['style_settings'] ?? null)) {
                $record['style_settings'] = [];
            }
            $shouldHydrateLegacyBlocks = $this->shouldHydrateLegacyBlocks($record, $layouts[$pageType] ?? [], $pageType);
            $placeholderBlocks = [];
            if ($shouldHydrateLegacyBlocks && $allowAiPlaceholderGeneration) {
                $placeholderBlocks = $this->buildWorkspacePlaceholderBlocks($blocksBuilder, $pageType, $websiteProfile, $scope);
            }
            if ($shouldHydrateLegacyBlocks) {
                $record['blocks'] = $placeholderBlocks;
            } else {
                $record['blocks'] = $this->hydrateEditableBlockMetadata(
                    \is_array($record['blocks'] ?? null) ? $record['blocks'] : [],
                    $placeholderBlocks
                );
            }
            $existing[$pageType] = $record;
        }

        return $existing;
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */    private function buildWorkspacePlaceholderBlocks(
        AiSiteHtmlBlocksBuildService $blocksBuilder,
        string $pageType,
        array $websiteProfile,
        array $scope
    ): array {
        return $blocksBuilder->buildPlaceholderBlocksForPageType($pageType, $websiteProfile, $scope);
    }

    /**
     * 判断 HTML 区块 workspace_track 是否满足"每个 page type 都已具备 blocks"的完整性条件。
     * 控制器 publish checklist / task plan auto-dispatch 会用该判断决定是否允许跨阶段推进。
     *
     * @param array<string, array<string, mixed>> $virtualPagesByType
     * @param list<string> $pageTypes
     */
    public function htmlTrackHasCompleteBlocks(array $virtualPagesByType, array $pageTypes): bool
    {
        if ($pageTypes === []) {
            return false;
        }
        foreach ($pageTypes as $pageType) {
            $record = $virtualPagesByType[$pageType] ?? null;
            if (!\is_array($record)) {
                return false;
            }
            $blocks = $record['blocks'] ?? null;
            if (!\is_array($blocks) || $blocks === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, array{page_id:int,website_id:int,type:string,name:string,title:string,handle:string}> $pagesByType
     * @return list<array{value:int,page_id:int,type:string,label:string,title:string,handle:string}>
     */
    public function buildPreviewPageOptions(array $pagesByType): array
    {
        $rows = [];
        foreach ($pagesByType as $pageType => $pageData) {
            $pageId = (int)($pageData['page_id'] ?? 0);
            if ($pageId <= 0) {
                continue;
            }

            $title = \trim((string)($pageData['title'] ?? $pageData['name'] ?? ''));
            $handle = \trim((string)($pageData['handle'] ?? ''));
            $label = $title !== '' ? $title : $pageType;
            if ($handle !== '') {
                $label .= ' (' . $handle . ')';
            }

            $rows[] = [
                'value' => $pageId,
                'page_id' => $pageId,
                'type' => (string)($pageData['type'] ?? $pageType),
                'title' => $title,
                'handle' => $handle,
                'label' => $label,
            ];
        }

        \usort($rows, static function (array $left, array $right): int {
            if ($left['type'] === Page::TYPE_HOME && $right['type'] !== Page::TYPE_HOME) {
                return -1;
            }
            if ($left['type'] !== Page::TYPE_HOME && $right['type'] === Page::TYPE_HOME) {
                return 1;
            }

            return $left['page_id'] <=> $right['page_id'];
        });

        return $rows;
    }

    /**
     * @param array<string, array{page_id:int,website_id:int,type:string,name:string,title:string,handle:string}> $pagesByType
     * @return array{preview_page_id:int,preview_page_type:string}
     */
    public function resolvePreviewSelection(array $pagesByType, int $previewPageId = 0, string $previewPageType = ''): array
    {
        $previewPageType = \trim($previewPageType);

        if ($previewPageId > 0) {
            foreach ($pagesByType as $pageType => $pageData) {
                if ((int)($pageData['page_id'] ?? 0) !== $previewPageId) {
                    continue;
                }

                return [
                    'preview_page_id' => $previewPageId,
                    'preview_page_type' => (string)($pageData['type'] ?? $pageType),
                ];
            }
        }

        if ($previewPageType !== '' && isset($pagesByType[$previewPageType])) {
            return [
                'preview_page_id' => (int)($pagesByType[$previewPageType]['page_id'] ?? 0),
                'preview_page_type' => $previewPageType,
            ];
        }

        if (isset($pagesByType[Page::TYPE_HOME])) {
            return [
                'preview_page_id' => (int)($pagesByType[Page::TYPE_HOME]['page_id'] ?? 0),
                'preview_page_type' => Page::TYPE_HOME,
            ];
        }

        foreach ($pagesByType as $pageType => $pageData) {
            return [
                'preview_page_id' => (int)($pageData['page_id'] ?? 0),
                'preview_page_type' => (string)($pageData['type'] ?? $pageType),
            ];
        }

        return [
            'preview_page_id' => 0,
            'preview_page_type' => '',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $virtualPagesByType
     */
    public function resolvePreviewPageType(array $virtualPagesByType, string $requestedPageType = ''): string
    {
        $requestedPageType = \trim($requestedPageType);
        if ($requestedPageType !== '' && isset($virtualPagesByType[$requestedPageType])) {
            return $requestedPageType;
        }

        if (isset($virtualPagesByType[Page::TYPE_HOME])) {
            return Page::TYPE_HOME;
        }

        foreach ($virtualPagesByType as $pageType => $row) {
            if (\is_string($pageType) && $pageType !== '') {
                return $pageType;
            }
        }

        return '';
    }

    /**
     * @param mixed $raw
     * @return array{version:string,page_id:int,use_original_template:bool,header:array{component:string,config:array<string,mixed>},content:list<array<string,mixed>>,footer:array{component:string,config:array<string,mixed>}}
     */
    public function normalizeLayoutConfig(mixed $raw, string $pageType = ''): array
    {
        if (!\is_array($raw) || $raw === []) {
            return $this->emptyLayoutConfig();
        }

        if (isset($raw['regions']) || isset($raw['components'])) {
            return $this->normalizeLegacyRegionsLayout($raw, $pageType);
        }

        $layout = $raw['layout_config'] ?? $raw;
        if (!\is_array($layout)) {
            return $this->emptyLayoutConfig();
        }

        $normalized = $this->layoutConfigNormalizer->normalize($layout);

        return $this->toExportLayout(
            $normalized,
            (int)($raw['page_id'] ?? 0),
            (bool)($raw['use_original_template'] ?? false)
        );
    }

    /**
     * @param array<string, mixed> $layout
     * @return array{version:string,page_id:int,use_original_template:bool,header:array{component:string,config:array<string,mixed>},content:list<array<string,mixed>>,footer:array{component:string,config:array<string,mixed>}}
     */
    private function normalizeLegacyRegionsLayout(array $layout, string $pageType = ''): array
    {
        $regions = \is_array($layout['regions'] ?? null) ? $layout['regions'] : [];
        $components = \is_array($layout['components'] ?? null) ? $layout['components'] : [];

        $header = $this->normalizeRegionComponent($regions['header'] ?? []);
        $footer = $this->normalizeRegionComponent($regions['footer'] ?? []);
        $content = [];

        foreach ($components as $index => $component) {
            if (!\is_array($component)) {
                continue;
            }

            $code = $this->layoutConfigNormalizer->normalizeComponentCode(
                (string)($component['code'] ?? $component['component'] ?? '')
            );
            if ($code === '') {
                continue;
            }

            $region = \strtolower(\trim((string)($component['region'] ?? 'content')));
            $config = \is_array($component['config'] ?? null) ? $component['config'] : [];
            if ($config === []) {
                $title = \trim((string)($component['title'] ?? ''));
                $description = \trim((string)($component['description'] ?? ''));
                $config = \array_filter([
                    'title' => $title,
                    'description' => $description,
                    'page_type' => $pageType,
                ], static fn(mixed $value): bool => $value !== '');
            }

            if ($region === 'header' && $header['component'] === '') {
                $header = ['component' => $code, 'config' => $config];
                continue;
            }

            if ($region === 'footer' && $footer['component'] === '') {
                $footer = ['component' => $code, 'config' => $config];
                continue;
            }

            $content[] = [
                'code' => $code,
                'enabled' => !\array_key_exists('enabled', $component) || (bool)$component['enabled'],
                'config' => $config,
                'instance_id' => (string)($component['instance_id'] ?? $component['id'] ?? ''),
                'sort_order' => (int)($component['sort_order'] ?? (($index + 1) * 10)),
            ];
        }

        return [
            'version' => '1.0',
            'page_id' => 0,
            'use_original_template' => false,
            'header' => $header,
            'content' => $content,
            'footer' => $footer,
        ];
    }

    /**
     * @param array<string, mixed> $region
     * @return array{component:string,config:array<string,mixed>}
     */
    private function normalizeRegionComponent(array $region): array
    {
        $component = $this->layoutConfigNormalizer->normalizeComponentCode(
            (string)($region['component'] ?? $region['code'] ?? '')
        );
        $config = \is_array($region['config'] ?? null) ? $region['config'] : [];

        return [
            'component' => $component,
            'config' => $config,
        ];
    }

    /**
     * @param mixed $row
     * @return array<string, mixed>
     */
    private function normalizeVirtualPageRecord(mixed $row, string $pageType): array
    {
        $data = \is_array($row) ? $row : [];
        $resolvedType = \trim((string)($data['page_type'] ?? $data['type'] ?? $pageType));
        $label = (string)(Page::getPageTypes()[$resolvedType] ?? $resolvedType);

        $blocks = $data['blocks'] ?? null;
        if (!\is_array($blocks) && isset($data['ai_layout']['blocks']) && \is_array($data['ai_layout']['blocks'])) {
            $blocks = $data['ai_layout']['blocks'];
        }
        $blocks = \is_array($blocks) ? $this->normalizeBlocksList($blocks) : [];

        return [
            'page_type' => $resolvedType,
            'title' => \trim((string)($data['title'] ?? $data['name'] ?? $label)),
            'handle' => \trim((string)($data['handle'] ?? '')),
            'locale' => \trim((string)($data['locale'] ?? '')),
            'style_code' => \trim((string)($data['style_code'] ?? $data['style'] ?? 'default')),
            'style_settings' => \is_array($data['style_settings'] ?? null) ? $data['style_settings'] : [],
            'meta_title' => \trim((string)($data['meta_title'] ?? '')),
            'meta_description' => \trim((string)($data['meta_description'] ?? '')),
            'meta_keywords' => \trim((string)($data['meta_keywords'] ?? '')),
            'ai_description' => \trim((string)($data['ai_description'] ?? '')),
            'virtual_preview_url' => \trim((string)($data['virtual_preview_url'] ?? '')),
            'virtual_edit_url' => \trim((string)($data['virtual_edit_url'] ?? '')),
            'preview_full_url' => \trim((string)($data['preview_full_url'] ?? '')),
            'last_generated_at' => \trim((string)($data['last_generated_at'] ?? '')),
            'materialized_page_id' => (int)($data['materialized_page_id'] ?? 0),
            'section_refinements' => $this->normalizeStringMap($data['section_refinements'] ?? []),
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $layout
     */
    private function shouldHydrateLegacyBlocks(array $record, array $layout, string $pageType): bool
    {
        $blocks = \is_array($record['blocks'] ?? null) ? $record['blocks'] : [];
        if ($blocks !== []) {
            return false;
        }

        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        if ($content === []) {
            return true;
        }

        if (\count($content) !== 1) {
            return false;
        }

        $code = \trim((string)($content[0]['code'] ?? $content[0]['component'] ?? ''));
        if ($code === '') {
            return true;
        }

        $slugPageType = \str_replace('_', '-', $pageType);
        $genericCodes = [
            'content/' . $slugPageType,
            'content/' . $pageType,
            'content/ai-generated-section',
            'ai-generated-section',
            'content-ai-generated-section',
        ];
        if (\in_array($code, $genericCodes, true)) {
            return true;
        }

        if (\preg_match('#^content/[^/]+$#', $code) === 1) {
            foreach (['-hero', '-highlights', '-details', '-cta', '-story', '-values', '-channels', '-process', '-coverage', '-rights', '-topics', '-structure', '-modules', '-steps'] as $marker) {
                if (\str_contains($code, $marker)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $existingBlocks
     * @param list<array<string,mixed>> $defaultBlocks
     * @return list<array<string,mixed>>
     */
    private function hydrateEditableBlockMetadata(array $existingBlocks, array $defaultBlocks): array
    {
        if ($defaultBlocks === []) {
            $blocksBuilder = $this->aiSiteHtmlBlocksBuildService ?? ObjectManager::getInstance(AiSiteHtmlBlocksBuildService::class);

            return \array_values(\array_map(
                static fn(array $block): array => $blocksBuilder->hydrateGeneratedBlockMetadata($block),
                \array_values(\array_filter($existingBlocks, static fn(mixed $block): bool => \is_array($block)))
            ));
        }

        $defaultById = [];
        foreach ($defaultBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockId = \trim((string)($block['block_id'] ?? ''));
            if ($blockId === '') {
                continue;
            }
            $defaultById[$blockId] = $block;
        }

        $hydrated = [];
        $blocksBuilder = $this->aiSiteHtmlBlocksBuildService ?? ObjectManager::getInstance(AiSiteHtmlBlocksBuildService::class);
        foreach ($existingBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockId = \trim((string)($block['block_id'] ?? ''));
            $defaultBlock = ($blockId !== '' && isset($defaultById[$blockId])) ? $defaultById[$blockId] : null;
            if (\is_array($defaultBlock)) {
                $block = \array_replace($defaultBlock, $block);
                if (!\is_array($block['config'] ?? null) || $block['config'] === []) {
                    $block['config'] = \is_array($defaultBlock['config'] ?? null) ? $defaultBlock['config'] : [];
                }
                if (!\is_array($block['field_schema'] ?? null) || $block['field_schema'] === []) {
                    $block['field_schema'] = \is_array($defaultBlock['field_schema'] ?? null) ? $defaultBlock['field_schema'] : [];
                }
            }
            $hydrated[] = $blocksBuilder->hydrateGeneratedBlockMetadata($block);
        }

        $existingIds = \array_values(\array_filter(\array_map(static function (array $block): string {
            return \trim((string)($block['block_id'] ?? ''));
        }, $hydrated), static fn(string $id): bool => $id !== ''));

        foreach ($defaultBlocks as $defaultBlock) {
            if (!\is_array($defaultBlock)) {
                continue;
            }
            $blockId = \trim((string)($defaultBlock['block_id'] ?? ''));
            if ($blockId === '' || \in_array($blockId, $existingIds, true)) {
                continue;
            }
            $hydrated[] = $defaultBlock;
        }

        return $hydrated;
    }

    /**
     * @param list<mixed> $raw
     * @return list<array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}>
     */
    private function normalizeBlocksList(array $raw): array
    {
        $out = [];
        foreach ($raw as $b) {
            if (!\is_array($b)) {
                continue;
            }
            if (AiSiteHtmlBlocksBuildService::isSharedLayoutBlock($b)) {
                continue;
            }
            $bid = \trim((string)($b['block_id'] ?? ''));
            if ($bid === '') {
                $bid = 'blk_' . \bin2hex(\random_bytes(4));
            }
            $normalized = [
                'block_id' => $bid,
                'type' => \trim((string)($b['type'] ?? 'section')),
                'html' => (string)($b['html'] ?? ''),
                'config' => \is_array($b['config'] ?? null) ? $b['config'] : [],
                'field_schema' => \is_array($b['field_schema'] ?? null) ? $b['field_schema'] : [],
            ];
            foreach (['component_code', 'section_code', 'component', 'code', 'block_code', 'block_key', 'task_key'] as $lookupKey) {
                if (!isset($b[$lookupKey]) || (!\is_scalar($b[$lookupKey]) && !(\is_object($b[$lookupKey]) && \method_exists($b[$lookupKey], '__toString')))) {
                    continue;
                }
                $lookupValue = \trim((string)$b[$lookupKey]);
                if ($lookupValue !== '') {
                    $normalized[$lookupKey] = $lookupValue;
                }
            }
            foreach ($b as $key => $value) {
                if (!\is_string($key) || !\str_starts_with($key, '_pb_server_')) {
                    continue;
                }
                $normalized[$key] = $value;
            }
            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeStringMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (!\is_scalar($key) || !\is_scalar($value)) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text === '') {
                continue;
            }
            $out[(string)$key] = $text;
        }

        return $out;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $normalized
     * @return array{version:string,page_id:int,use_original_template:bool,header:array{component:string,config:array<string,mixed>},content:list<array<string,mixed>>,footer:array{component:string,config:array<string,mixed>}}
     */
    private function toExportLayout(array $normalized, int $pageId = 0, bool $useOriginalTemplate = false): array
    {
        $header = ['component' => '', 'config' => []];
        if (!empty($normalized['header'][0]['code'])) {
            $header = [
                'component' => (string)$normalized['header'][0]['code'],
                'config' => \is_array($normalized['header'][0]['config'] ?? null) ? $normalized['header'][0]['config'] : [],
            ];
        }

        $footer = ['component' => '', 'config' => []];
        if (!empty($normalized['footer'][0]['code'])) {
            $footer = [
                'component' => (string)$normalized['footer'][0]['code'],
                'config' => \is_array($normalized['footer'][0]['config'] ?? null) ? $normalized['footer'][0]['config'] : [],
            ];
        }

        $content = [];
        foreach ($normalized['content'] ?? [] as $component) {
            if (!\is_array($component) || ($component['code'] ?? '') === '') {
                continue;
            }
            $content[] = [
                'code' => (string)$component['code'],
                'enabled' => !\array_key_exists('enabled', $component) || (bool)$component['enabled'],
                'config' => \is_array($component['config'] ?? null) ? $component['config'] : [],
                'instance_id' => (string)($component['instance_id'] ?? ''),
                'sort_order' => (int)($component['sort_order'] ?? 0),
                'style_code' => (string)($component['style_code'] ?? ''),
            ];
        }

        return [
            'version' => '1.0',
            'page_id' => $pageId,
            'use_original_template' => $useOriginalTemplate,
            'header' => $header,
            'content' => $content,
            'footer' => $footer,
        ];
    }

    /**
     * @return array{version:string,page_id:int,use_original_template:bool,header:array{component:string,config:array<string,mixed>},content:list<array<string,mixed>>,footer:array{component:string,config:array<string,mixed>}}
     */
    private function emptyLayoutConfig(): array
    {
        return [
            'version' => '1.0',
            'page_id' => 0,
            'use_original_template' => false,
            'header' => ['component' => '', 'config' => []],
            'content' => [],
            'footer' => ['component' => '', 'config' => []],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function resolveContentLocale(array $scope, array $websiteProfile): string
    {
        return \trim((string)(
            $scope['content_locale']
                ?? $websiteProfile['content_locale']
                ?? $scope['default_locale']
                ?? $scope['default_language']
                ?? $websiteProfile['default_locale']
                ?? ''
        ));
    }

    private function localizePageTypeLabel(string $pageType, string $locale): string
    {
        $isZh = $this->isChineseLocale($locale);
        $isJa = $this->isJapaneseLocale($locale);
        $isKo = $this->isKoreanLocale($locale);

        return match ($pageType) {
            Page::TYPE_HOME => $isZh ? '首页' : ($isJa ? 'ホーム' : ($isKo ? '홈' : 'Home')),
            Page::TYPE_ABOUT => $isZh ? '关于我们' : ($isJa ? '私たちについて' : ($isKo ? '회사 소개' : 'About')),
            Page::TYPE_CONTACT => $isZh ? '联系我们' : ($isJa ? 'お問い合わせ' : ($isKo ? '문의하기' : 'Contact')),
            Page::TYPE_BLOG_LIST, Page::TYPE_BLOG => $isZh ? '博客' : ($isJa ? 'ブログ' : ($isKo ? '블로그' : 'Blog')),
            Page::TYPE_PRIVACY_POLICY => $isZh ? '隐私政策' : ($isJa ? 'プライバシーポリシー' : ($isKo ? '개인정보처리방침' : 'Privacy Policy')),
            Page::TYPE_TERMS_OF_SERVICE => $isZh ? '服务条款' : ($isJa ? '利用規約' : ($isKo ? '이용약관' : 'Terms of Service')),
            Page::TYPE_REFUND_POLICY => $isZh ? '退款政策' : ($isJa ? '返金ポリシー' : ($isKo ? '환불 정책' : 'Refund Policy')),
            Page::TYPE_SHIPPING_POLICY => $isZh ? '配送政策' : ($isJa ? '配送ポリシー' : ($isKo ? '배송 정책' : 'Shipping Policy')),
            Page::TYPE_COOKIE_POLICY => $isZh ? 'Cookie 政策' : ($isJa ? 'Cookie ポリシー' : ($isKo ? '쿠키 정책' : 'Cookie Policy')),
            default => '',
        };
    }

    private function isChineseLocale(string $locale): bool
    {
        return \preg_match('/^(zh|zh[_-]hans|zh[_-]cn|zh[_-]sg)/i', $locale) === 1;
    }

    private function isJapaneseLocale(string $locale): bool
    {
        return \preg_match('/^ja(?:[_-]|$)/i', $locale) === 1;
    }

    private function isKoreanLocale(string $locale): bool
    {
        return \preg_match('/^ko(?:[_-]|$)/i', $locale) === 1;
    }
}
