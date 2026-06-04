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
    public const DUPLICATED_STAGE_ONE_STORAGE_KEYS = [
        'theme_context_snapshot',
        'shared_prompt_context',
    ];

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public static function stripDuplicatedStageOneStorageFields(array $scope): array
    {
        foreach (self::DUPLICATED_STAGE_ONE_STORAGE_KEYS as $key) {
            unset($scope[$key]);
        }
        foreach (\array_keys($scope) as $key) {
            if (\is_string($key) && self::isInternalPlanGenerationTransientKey($key)) {
                unset($scope[$key]);
            }
        }

        return $scope;
    }

    private static function isInternalPlanGenerationTransientKey(string $key): bool
    {
        return \str_starts_with($key, '_') && \str_contains($key, 'plan_generation_');
    }

    public function __construct(
        private readonly LayoutConfigNormalizer $layoutConfigNormalizer,
        private readonly ?AiSiteHtmlBlocksBuildService $aiSiteHtmlBlocksBuildService = null,
        private readonly ?AiSitePlanJsonStateService $planJsonStateService = null,
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function stripUnsupportedScopeArtifactKeys(array $scope): array
    {
        foreach ([
            'plan_confirmed',
            'plan_confirmed_at',
            'plan_confirmed_stale_input',
            'plan_confirmed_stale_input_at',
            'plan_projection',
            'task_plan',
            'build_order',
        ] as $key) {
            unset($scope[$key]);
        }

        if (\is_array($scope['_artifact_refs'] ?? null)) {
            foreach ($scope['_artifact_refs'] as $stageCode => $stageRefs) {
                if (!\is_array($stageRefs)) {
                    continue;
                }
                foreach (['plan_projection'] as $artifactKey) {
                    unset($scope['_artifact_refs'][$stageCode][$artifactKey]);
                }
                if ($scope['_artifact_refs'][$stageCode] === []) {
                    unset($scope['_artifact_refs'][$stageCode]);
                }
            }
            if ($scope['_artifact_refs'] === []) {
                unset($scope['_artifact_refs']);
            }
        }

        if (\is_array($scope['plan_json'] ?? null)) {
            $editor = $this->planJsonStateService ?? new AiSitePlanJsonStateService();
            $scope['plan_json'] = $editor->normalizePlanJson($scope['plan_json']);
        }

        return $scope;
    }

    public function normalizeScope(array $scope): array
    {
        $normalized = $this->stripUnsupportedScopeArtifactKeys($scope);
        $normalized[self::PAGE_TYPES_USER_CUSTOMIZED_KEY] = $this->normalizePageTypesUserCustomized(
            $scope[self::PAGE_TYPES_USER_CUSTOMIZED_KEY] ?? null
        ) ? 1 : 0;
        $normalized[self::SELECTED_SKILL_CODES_KEY] = $this->normalizeSelectedSkillCodes(
            $scope[self::SELECTED_SKILL_CODES_KEY] ?? []
        );
        $normalized['design_direction_mode'] = $this->normalizeDesignDirectionMode(
            (string)($scope['design_direction_mode'] ?? 'auto')
        );
        $normalized['design_direction_code'] = $this->normalizeDesignDirectionCode(
            (string)($scope['design_direction_code'] ?? '')
        );
        $normalized['design_direction_custom_id'] = \max(0, (int)($scope['design_direction_custom_id'] ?? 0));
        $normalized['design_direction'] = \is_array($scope['design_direction'] ?? null) ? $scope['design_direction'] : [];
        $normalized['design_direction_snapshot'] = \is_array($scope['design_direction_snapshot'] ?? null) ? $scope['design_direction_snapshot'] : [];
        $normalized['design_direction_version'] = \max(0, (int)($scope['design_direction_version'] ?? 0));
        $normalized['design_direction_hash'] = \trim((string)($scope['design_direction_hash'] ?? ''));
        $normalized['design_direction_match_reason'] = \trim((string)($scope['design_direction_match_reason'] ?? ''));
        $normalized['design_direction_locked'] = $this->normalizeTruthyFlag($scope['design_direction_locked'] ?? 0) ? 1 : 0;
        $normalized['page_types'] = $this->resolveScopedPageTypes($scope);
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

        $selection = $this->resolvePlanJsonPreviewSelection(
            $normalized,
            (int)($scope['preview_page_id'] ?? 0),
            (string)($scope['preview_page_type'] ?? '')
        );
        $normalized['preview_page_id'] = $selection['preview_page_id'];
        $normalized['preview_page_type'] = $selection['preview_page_type'];
        $normalized['preview_page_options'] = $this->buildPlanJsonPreviewPageOptions($normalized);

        if (!\is_array($normalized['website_profile'] ?? null)) {
            $normalized['website_profile'] = [];
        }
        $explicitContentLocale = $this->resolveExplicitContentLocale($normalized, $normalized['website_profile']);
        if ($explicitContentLocale !== '') {
            $normalized['content_locale'] = $explicitContentLocale;
            $normalized['website_profile']['content_locale'] = $explicitContentLocale;
            $normalized = $this->normalizePlanJsonStageOneContractLocale($normalized, $explicitContentLocale);
            $normalized['website_profile'] = $this->sanitizeWebsiteProfileVisitorMetadataForLocale(
                $normalized['website_profile'],
                $explicitContentLocale
            );
            $normalized['locales'] = $this->prependLocaleToList(
                \is_array($normalized['locales'] ?? null) ? $normalized['locales'] : [],
                $explicitContentLocale
            );
        }
        $routeContractRaw = \is_array($scope['page_route_contract'] ?? null) ? $scope['page_route_contract'] : [];
        $normalized['page_route_contract'] = $this->getPageRouteContractService()->normalize(
            $routeContractRaw,
            $normalized['page_types'],
            $normalized,
            $this->resolveContentLocale($normalized, $normalized['website_profile'])
        );

        $normalized['workspace_track'] = $this->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $normalized['extra_page_types_panel_open'] = ((int)($scope['extra_page_types_panel_open'] ?? 0) === 1) ? 1 : 0;
        $normalized = $this->ensureManifestFields($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function ensureManifestFields(array $scope): array
    {
        $manifestSource = $this->PlanJsonManifestSource($scope);
        if (!\is_array($scope['design_tokens'] ?? null) || $scope['design_tokens'] === []) {
            $scope['design_tokens'] = (new AiSiteDesignTokenResolver())->resolveFromBlueprint($manifestSource);
        }

        if (!\is_array($scope['language_contract'] ?? null) || $scope['language_contract'] === []) {
            $locale = $this->resolveContentLocale($scope, \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : []);
            $scope['language_contract'] = (new AiSiteLanguageVoiceResolver())->buildLanguageContractExtension($manifestSource, $locale);
            $scope['language_contract']['source_of_truth_locale'] = $locale;
        }

        if (!\is_array($scope['theme_css_ref'] ?? null) || (string)($scope['theme_css_ref']['hash'] ?? '') === '') {
            $themeCssService = new AiSiteVirtualThemeCssService();
            $generated = $themeCssService->generateThemeCss($manifestSource);
            $scope['theme_css'] = (string)($generated['css'] ?? '');
            $scope['theme_css_ref'] = $themeCssService->buildManifestRef($generated);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function hasConfirmedPlanJsonForBuild(array $scope): bool
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $editor = $this->planJsonStateService ?? new AiSitePlanJsonStateService();

        return $editor->isConfirmed($planJson) && $this->hasPersistedStageOnePlan($scope);
    }

    /**
     * Backend-owned existence check for a persisted stage-one plan.
     *
     * @param array<string, mixed> $scope
     */
    public function hasPersistedStageOnePlan(array $scope): bool
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        if (!empty($scope['fake_mode'])) {
            $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
            if ($this->stageOnePlanPagesContainBlocks($pages)) {
                return true;
            }
        }

        return $this->isUsableStageOnePlanJson($planJson);
    }

    /**
     * A markdown note or test artifact is not enough to skip stage-one
     * generation. The queue may only treat a persisted plan as reusable when
     * the strong-contract sections needed by downstream plan JSON pagening exist.
     *
     * @param array<string, mixed> $planJson
     */
    public function isUsableStageOnePlanJson(array $planJson): bool
    {
        if ($planJson === []) {
            return false;
        }
        if ($this->isUsableStageOneContractPlanJson($planJson)) {
            return true;
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
     * Stage-one artifacts are validated by the contract validator and may
     * intentionally omit requirement_expansion/theme_detail fields. Treat
     * a passed contract report plus concrete page/block plans as reusable while
     * still rejecting light manifests such as {"content_locale":"en_US"}.
     *
     * @param array<string, mixed> $planJson
     */
    private function isUsableStageOneContractPlanJson(array $planJson): bool
    {
        $report = \is_array($planJson['stage1_validation_report'] ?? null)
            ? $planJson['stage1_validation_report']
            : [];
        if (!$this->stageOneValidationReportPassed($report)) {
            return false;
        }

        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];

        return $this->stageOnePlanPagesContainBlocks($pages);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function stageOneValidationReportPassed(array $report): bool
    {
        $passed = $report['passed'] ?? false;
        if ($passed === true || $passed === 1 || $passed === '1') {
            return true;
        }

        return \is_string($passed) && \strtolower(\trim($passed)) === 'true';
    }

    /**
     * @param array<mixed> $pages
     */
    private function stageOnePlanPagesContainBlocks(array $pages): bool
    {
        foreach ($pages as $page) {
            if (!\is_array($page)) {
                continue;
            }
            foreach ($page as $key => $value) {
                if (!\is_string($key) || !$this->isStageOneDynamicBlockKey($key) || !\is_array($value)) {
                    continue;
                }
                if ($value !== []) {
                    return true;
                }
            }
            if (\is_array($page['page'] ?? null) && $this->stageOnePlanPagesContainBlocks([$page['page']])) {
                return true;
            }
        }

        return false;
    }

    private function isStageOneDynamicBlockKey(string $key): bool
    {
        static $metaKeys = [
            'page_key' => true,
            'page_type' => true,
            'type' => true,
            'status' => true,
            'message' => true,
            'error' => true,
            'error_message' => true,
            'updated_at' => true,
            'started_at' => true,
            'finished_at' => true,
            'attempt_no' => true,
            'result_ref' => true,
            'title' => true,
            'label' => true,
            'page_label' => true,
            'page_title' => true,
            'page_goal' => true,
            'page_status' => true,
            'content_locale' => true,
            'shared_context_hash' => true,
            'theme_context_hash' => true,
            'assembly_version' => true,
            'generation_method' => true,
            'page_design_plan' => true,
            'theme_alignment_summary' => true,
            'page_context_hash' => true,
            'blocks' => true,
            'blocks' => true,
            'ordered_block_keys' => true,
            'seo' => true,
            'meta_title' => true,
            'meta_description' => true,
            'meta_keywords' => true,
            'route' => true,
            'route_path' => true,
            'slug' => true,
            'path' => true,
            'layout' => true,
            'style_code' => true,
            'style_settings' => true,
            'design_tokens' => true,
            'theme_css_ref' => true,
            'navigation' => true,
            'menus' => true,
            'links' => true,
            'settings' => true,
            'preview_url' => true,
            'preview_full_url' => true,
            'visual_preview_url' => true,
            'visual_edit_url' => true,
            'virtual_preview_url' => true,
            'virtual_edit_url' => true,
            'sections' => true,
            'section_refinements' => true,
            'ai_description' => true,
            'content' => true,
            'description' => true,
            'summary' => true,
            'html' => true,
            'html_content' => true,
            'fields' => true,
        ];
        $key = \trim($key);

        return $key !== '' && !isset($metaKeys[$key]);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function normalizeConfirmedPlanFlag(array $scope): array
    {
        if (\is_array($scope['plan_json'] ?? null)) {
            $editor = $this->planJsonStateService ?? new AiSitePlanJsonStateService();
            $scope['plan_json'] = $editor->normalizePlanJson($scope['plan_json']);
        }

        return $scope;
    }

    public const WORKSPACE_TRACK_VIRTUAL_THEME = 'virtual_theme';
    public const WORKSPACE_TRACK_html_blocks = 'html_blocks';

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

        return $this->normalizePageTypes($providedPageTypes);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function augmentDefaultPageTypesFromBrief(array $scope): array
    {
        $pageTypes = $this->resolveScopedPageTypes($scope);
        if ($this->normalizePageTypesUserCustomized($scope[self::PAGE_TYPES_USER_CUSTOMIZED_KEY] ?? null)) {
            return $scope;
        }

        $brief = \trim(\implode("\n", \array_filter([
            (string)($scope['user_description'] ?? ''),
            (string)($scope['brief_description'] ?? ''),
            (string)($scope['site_plan']['brief_description'] ?? ''),
        ])));
        $additional = $this->inferAdditionalPageTypesFromBrief($brief);
        if ($additional === []) {
            return $scope;
        }

        $merged = $this->normalizePageTypes(\array_merge($pageTypes, $additional));
        if ($this->samePageTypeSet($merged, $pageTypes)) {
            return $scope;
        }

        $scope['page_types'] = $merged;
        $scope[self::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;

        return $scope;
    }

    /**
     * @return list<string>
     */
    public function inferAdditionalPageTypesFromBrief(string $brief): array
    {
        $pageTypes = [];
        if ($brief === '') {
            return $pageTypes;
        }

        if ($this->matchesAnyTextPattern($brief, [
            '/blog|article|news|academy|learn|education|guide|insight|resource|journal/i',
            '/\x{5b66}\x{9662}|\x{77e5}\x{8bc6}|\x{6559}\x{7a0b}|\x{8d44}\x{8baf}|\x{6587}\x{7ae0}|\x{535a}\x{5ba2}|\x{8bfe}\x{5802}/u',
        ])) {
            $pageTypes[] = Page::TYPE_BLOG_LIST;
        }
        if ($this->matchesAnyTextPattern($brief, [
            '/product|catalog|collection|service|solution|portfolio|case|menu|sku/i',
            '/\x{4ea7}\x{54c1}|\x{7cfb}\x{5217}|\x{670d}\x{52a1}|\x{65b9}\x{6848}|\x{6848}\x{4f8b}|\x{9879}\x{76ee}/u',
        ])) {
            $pageTypes[] = Page::TYPE_CUSTOM;
        }

        return \array_values(\array_unique($pageTypes));
    }

    public function normalizePageTypesUserCustomized(mixed $raw): bool
    {
        return $this->normalizeTruthyFlag($raw);
    }

    private function normalizeTruthyFlag(mixed $raw): bool
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

    private function normalizeDesignDirectionMode(string $mode): string
    {
        $mode = \strtolower(\trim($mode));
        return \in_array($mode, ['auto', 'manual', 'none'], true) ? $mode : 'auto';
    }

    private function normalizeDesignDirectionCode(string $code): string
    {
        $code = \strtolower(\trim($code));
        $code = (string)\preg_replace('/[^a-z0-9_-]+/', '-', $code);
        $code = \trim($code, '-_');

        return \strlen($code) > 96 ? \substr($code, 0, 96) : $code;
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
        return [
            Page::TYPE_HOME,
            Page::TYPE_ABOUT,
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
     * @param list<string> $patterns
     */
    private function matchesAnyTextPattern(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@\preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
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
        $existing = [];
        $inputVirtualPages = [];
        $layouts = [];
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $siteTitle = \trim((string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? ''));
        $contentLocale = $this->resolveContentLocale($scope, $websiteProfile);
        $pageRouteContract = $this->getPageRouteContractService()->normalize(
            \is_array($scope['page_route_contract'] ?? null) ? $scope['page_route_contract'] : [],
            $pageTypes,
            \array_replace($scope, ['website_profile' => $websiteProfile]),
            $contentLocale
        );
        $routesByType = $this->getPageRouteContractService()->routesByType($pageRouteContract);
        $blocksBuilder = $this->aiSiteHtmlBlocksBuildService ?? ObjectManager::getInstance(AiSiteHtmlBlocksBuildService::class);

        foreach ($pageTypes as $pageType) {
            $record = $existing[$pageType] ?? $this->normalizeVirtualPageRecord([], $pageType);
            $hasInputRecord = \is_array($inputVirtualPages[$pageType] ?? null);
            $defaultLabel = (string)(Page::getPageTypes()[$pageType] ?? $pageType);
            $localizedLabel = $this->localizePageTypeLabel($pageType, $contentLocale);
            $currentTitle = \trim((string)($record['title'] ?? ''));
            $localizedCurrentTitle = $this->localizeGenericPageTitle($currentTitle, $contentLocale);
            if (!$hasInputRecord && $pageType === Page::TYPE_HOME && $siteTitle !== '') {
                $record['title'] = $siteTitle;
            } elseif ($localizedCurrentTitle !== '' && $localizedCurrentTitle !== $currentTitle) {
                $record['title'] = $localizedCurrentTitle;
            } elseif ($currentTitle === '' || ($currentTitle === $defaultLabel && $localizedLabel !== '' && $localizedLabel !== $defaultLabel)) {
                $label = $localizedLabel !== '' ? $localizedLabel : $defaultLabel;
                $record['title'] = $pageType === Page::TYPE_HOME
                    ? ($siteTitle !== '' ? $siteTitle : $label)
                    : $label;
            }
            if (\trim((string)($record['handle'] ?? '')) === '') {
                $record['handle'] = Page::getDefaultHandleForType($pageType);
            }
            if (\is_array($routesByType[$pageType] ?? null)) {
                $record['handle'] = (string)($routesByType[$pageType]['handle'] ?? $record['handle']);
                $record['route_path'] = (string)($routesByType[$pageType]['path'] ?? '');
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
            $record = $this->localizeVirtualPageRecordForVisitorLocale(
                $record,
                $contentLocale,
                $websiteProfile,
                $scope
            );
            $placeholderBlocks = $allowAiPlaceholderGeneration
                ? $this->buildWorkspacePlaceholderBlocks($blocksBuilder, $pageType, $websiteProfile, $scope)
                : [];
            $record['blocks'] = $this->hydrateEditableBlockMetadata(
                \is_array($record['blocks'] ?? null) ? $record['blocks'] : [],
                $placeholderBlocks
            );
            $existing[$pageType] = $record;
        }

        return $existing;
    }

    private function getPageRouteContractService(): AiSitePageRouteContractService
    {
        return ObjectManager::getInstance(AiSitePageRouteContractService::class);
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
     * 闂佺鐭囬崘銊у幀闂?publish checklist / task plan auto-dispatch 婵炴潙鍚嬪銊╁极閵堝洦瀚氶柕澶堝劚閻忔煡鏌￠崒銈呭箹闁哥姴鎳愰埀顒冾潐绾板秴危閹间礁瑙﹂柨鏇楀亾闁告ɑ鍨归幏瀣潰鐏炲墽銆婇梻鍌氬暢閸╂牠顢欏畝鍕闁靛鍔庣粻濠氭煏?
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
     * @param array<string,mixed> $scope
     * @return array{preview_page_id:int,preview_page_type:string}
     */
    public function resolvePlanJsonPreviewSelection(array $scope, int $previewPageId = 0, string $previewPageType = ''): array
    {
        $pages = $this->resolvePlanJsonPagesByType($scope);
        $previewPageType = \trim($previewPageType);
        if ($previewPageType !== '' && isset($pages[$previewPageType])) {
            return [
                'preview_page_id' => (int)($pages[$previewPageType]['page_id'] ?? $pages[$previewPageType]['materialized_page_id'] ?? $previewPageId),
                'preview_page_type' => $previewPageType,
            ];
        }
        if ($previewPageId > 0) {
            foreach ($pages as $pageType => $page) {
                if ((int)($page['page_id'] ?? $page['materialized_page_id'] ?? 0) === $previewPageId) {
                    return [
                        'preview_page_id' => $previewPageId,
                        'preview_page_type' => (string)$pageType,
                    ];
                }
            }
        }
        if (isset($pages[Page::TYPE_HOME])) {
            return [
                'preview_page_id' => (int)($pages[Page::TYPE_HOME]['page_id'] ?? $pages[Page::TYPE_HOME]['materialized_page_id'] ?? 0),
                'preview_page_type' => Page::TYPE_HOME,
            ];
        }
        foreach ($pages as $pageType => $page) {
            return [
                'preview_page_id' => (int)($page['page_id'] ?? $page['materialized_page_id'] ?? 0),
                'preview_page_type' => (string)$pageType,
            ];
        }

        return [
            'preview_page_id' => 0,
            'preview_page_type' => '',
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array{value:int,page_id:int,type:string,label:string,title:string,handle:string}>
     */
    public function buildPlanJsonPreviewPageOptions(array $scope): array
    {
        $rows = [];
        foreach ($this->resolvePlanJsonPagesByType($scope) as $pageType => $page) {
            $pageId = (int)($page['page_id'] ?? $page['materialized_page_id'] ?? 0);
            $title = \trim((string)($page['title'] ?? $page['name'] ?? $pageType));
            $handle = \trim((string)($page['handle'] ?? ''));
            $label = $title !== '' ? $title : (string)$pageType;
            if ($handle !== '') {
                $label .= ' (' . $handle . ')';
            }
            $rows[] = [
                'value' => $pageId,
                'page_id' => $pageId,
                'type' => (string)$pageType,
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

            return \strcmp((string)$left['type'], (string)$right['type']);
        });

        return $rows;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,array<string,mixed>>
     */
    private function resolvePlanJsonPagesByType(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $result = [];
        foreach ($pages as $pageType => $page) {
            if (\is_string($pageType) && \is_array($page)) {
                $result[$pageType] = $page;
            }
        }

        return $result;
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
     * @param array<string,mixed> $layout
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function localizeSharedLayoutConfigForScope(array $layout, array $scope, string $pageType = ''): array
    {
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $locale = $this->resolveContentLocale($scope, $websiteProfile);
        if ($locale === '') {
            return $layout;
        }

        $layout = $this->applyPlanJsonSharedComponentsToLayout($layout, $scope);

        if (\is_array($layout['header']['config'] ?? null)) {
            $layout['header']['config'] = $this->localizeHeaderLayoutConfig($layout['header']['config'], $locale);
        }
        if (\is_array($layout['footer']['config'] ?? null)) {
            $layout['footer']['config'] = $this->localizeFooterLayoutConfig($layout['footer']['config'], $locale);
        }

        return $layout;
    }

    /**
     * @param array<string,mixed> $layout
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function applyPlanJsonSharedComponentsToLayout(array $layout, array $scope): array
    {
        $sharedComponents = \is_array($scope['plan_json']['shared_components'] ?? null)
            ? $scope['plan_json']['shared_components']
            : [];
        if ($sharedComponents === []) {
            return $layout;
        }

        foreach (['header', 'footer'] as $region) {
            $component = \is_array($sharedComponents[$region] ?? null) ? $sharedComponents[$region] : [];
            if ($component === []) {
                continue;
            }
            if (!\is_array($layout[$region] ?? null)) {
                $layout[$region] = ['component' => '', 'config' => []];
            }
            $componentCode = $this->resolvePlanJsonSharedComponentCode($region, $component);
            if ($componentCode !== '') {
                $layout[$region]['component'] = $componentCode;
            }
            $planConfig = $this->resolvePlanJsonSharedComponentConfig($component);
            if ($planConfig !== []) {
                $currentConfig = \is_array($layout[$region]['config'] ?? null) ? $layout[$region]['config'] : [];
                $layout[$region]['config'] = $this->applyPlanJsonIdentityToSharedComponentConfig(
                    \array_replace($currentConfig, $planConfig),
                    $scope,
                    $region
                );
            }
        }

        return $layout;
    }

    /**
     * @param array<string,mixed> $component
     */
    private function resolvePlanJsonSharedComponentCode(string $region, array $component): string
    {
        foreach ([$component['code'] ?? null, $component['component_code'] ?? null, $component['section_code'] ?? null] as $candidate) {
            $code = \trim((string)$candidate);
            if ($code !== '') {
                return $code;
            }
        }

        return match ($region) {
            'header' => 'header/ai-site-header',
            'footer' => 'footer/ai-site-footer',
            default => '',
        };
    }

    /**
     * @param array<string,mixed> $component
     * @return array<string,mixed>
     */
    private function resolvePlanJsonSharedComponentConfig(array $component): array
    {
        foreach (['default_config', 'config', 'fields'] as $key) {
            if (\is_array($component[$key] ?? null) && $component[$key] !== []) {
                return $component[$key];
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function applyPlanJsonIdentityToSharedComponentConfig(array $config, array $scope, string $region): array
    {
        $identity = $this->resolvePlanJsonIdentityForSharedComponents($scope);
        $title = \trim((string)($identity['title'] ?? ''));
        $tagline = \trim((string)($identity['tagline'] ?? ''));
        if ($title !== '') {
            foreach ($region === 'header' ? ['logo.text', 'brand.name', 'site_title', 'title'] : ['brand.name', 'logo.text', 'site_title', 'title'] as $key) {
                if (\array_key_exists($key, $config)) {
                    $config[$key] = $title;
                }
            }
        }
        if ($tagline !== '') {
            foreach ($region === 'header' ? ['content.subtitle', 'site_tagline', 'tagline'] : ['brand.description', 'content.subtitle', 'site_tagline', 'tagline'] as $key) {
                if (\array_key_exists($key, $config)) {
                    $config[$key] = $tagline;
                }
            }
        }

        return $config;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array{title:string,tagline:string}
     */
    private function resolvePlanJsonIdentityForSharedComponents(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $siteBrief = \is_array($planJson['site_brief'] ?? null) ? $planJson['site_brief'] : [];
        $sharedPrompt = \is_array($planJson['shared_prompt_context'] ?? null) ? $planJson['shared_prompt_context'] : [];
        $themeContext = \is_array($planJson['theme_context_snapshot'] ?? null) ? $planJson['theme_context_snapshot'] : [];
        $requirementExpansion = \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $home = \is_array($pages['home_page'] ?? null) ? $pages['home_page'] : [];

        $title = $this->safeVisitorTextForLocale($this->pickString(
            $planJson['site_title'] ?? null,
            $planJson['site_name'] ?? null,
            $sharedPrompt['site_display_name'] ?? null,
            $sharedPrompt['site_title'] ?? null,
            $themeContext['site_display_name'] ?? null,
            $themeContext['site_title'] ?? null,
            $siteBrief['site_title'] ?? null,
            $siteBrief['site_name'] ?? null,
            $siteBrief['title'] ?? null,
            $siteBrief['name'] ?? null,
            $home['meta_title'] ?? null,
            $home['page_title'] ?? null,
            $home['title'] ?? null,
            $home['name'] ?? null
        ), $this->resolveContentLocale($scope, \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : []));
        if ($this->isGenericPlanJsonIdentityText($title)) {
            $title = '';
        }
        $tagline = $this->safeVisitorTextForLocale($this->pickString(
            $planJson['site_tagline'] ?? null,
            $planJson['tagline'] ?? null,
            $sharedPrompt['site_tagline'] ?? null,
            $sharedPrompt['tagline'] ?? null,
            $themeContext['site_tagline'] ?? null,
            $themeContext['tagline'] ?? null,
            $siteBrief['site_tagline'] ?? null,
            $siteBrief['tagline'] ?? null,
            $siteBrief['summary'] ?? null,
            $requirementExpansion['site_goal'] ?? null
        ), $this->resolveContentLocale($scope, \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : []));

        return ['title' => $title, 'tagline' => $tagline];
    }

    private function isGenericPlanJsonIdentityText(string $value): bool
    {
        $value = \strtolower(\trim((string)\preg_replace('/\s+/u', ' ', $value)));

        return \in_array($value, ['home', 'home page', 'homepage', 'index', 'landing page'], true);
    }

    private function pickString(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $value = \trim((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function localizeHeaderLayoutConfig(array $config, string $locale): array
    {
        foreach (['navigation.items', 'nav_items'] as $key) {
            if (\array_key_exists($key, $config)) {
                $config[$key] = $this->localizeLinkItemsValue($config[$key], $locale);
            }
        }
        foreach (['cta.text', 'cta.label'] as $key) {
            if (!\array_key_exists($key, $config)) {
                continue;
            }
            $value = \preg_replace('/\s+/u', ' ', \trim((string)$config[$key])) ?? \trim((string)$config[$key]);
            if ($this->looksLikeEnglishNavOrFooterLabel($value) || ($this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($value))) {
                $config[$key] = $this->localizeBuildText('download_now', $locale);
            }
        }

        return $config;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function localizeFooterLayoutConfig(array $config, string $locale): array
    {
        $titleKeys = [
            'links.column1_title' => 'featured_pages',
            'links.column2_title' => 'policy_info',
            'links.column3_title' => 'all_pages',
            'copyright.text' => 'all_rights_reserved',
        ];
        foreach ($titleKeys as $key => $fallbackKey) {
            if (!\array_key_exists($key, $config)) {
                continue;
            }
            $value = \preg_replace('/\s+/u', ' ', \trim((string)$config[$key])) ?? \trim((string)$config[$key]);
            if ($value === '' || $this->isGenericFooterBoilerplate($value) || $this->looksLikeEnglishNavOrFooterLabel($value) || ($this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($value))) {
                $config[$key] = $this->localizeBuildText($fallbackKey, $locale);
            }
        }

        foreach (['links.column1_items', 'links.column2_items', 'links.column3_items', 'nav_items'] as $key) {
            if (\array_key_exists($key, $config)) {
                $config[$key] = $this->localizeLinkItemsValue($config[$key], $locale);
            }
        }

        if (\array_key_exists('brand.description', $config)) {
            $description = \preg_replace('/\s+/u', ' ', \trim((string)$config['brand.description'])) ?? \trim((string)$config['brand.description']);
            if ($description === '' || $this->isGenericFooterBoilerplate($description) || ($this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($description))) {
                $config['brand.description'] = $this->localizeBuildText('brand_summary', $locale);
            }
        }

        return $config;
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
        if (!\is_array($blocks) || $blocks === []) {
            $blocks = $this->extractCanonicalPlanJsonBlocksFromPageRecord($data);
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
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function extractCanonicalPlanJsonBlocksFromPageRecord(array $data): array
    {
        $blocks = [];
        foreach ($data as $key => $node) {
            if (!\is_string($key) || !$this->isStageOneDynamicBlockKey($key) || !\is_array($node)) {
                continue;
            }
            if (\array_key_exists('status', $node) && (int)$node['status'] !== 1) {
                continue;
            }
            $html = \trim((string)($node['html'] ?? $node['html_content'] ?? $node['phtml'] ?? ''));
            if ($html === '') {
                continue;
            }
            $block = $node;
            $block['block_id'] = \trim((string)($block['block_id'] ?? $block['block_key'] ?? $key));
            $block['block_key'] = \trim((string)($block['block_key'] ?? $key));
            $block['html'] = $html;
            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * @param array<string, array<string, mixed>> $virtualPages
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return array<string, array<string, mixed>>
     */
    private function localizeVirtualPagesForVisitorLocale(
        array $virtualPages,
        string $locale,
        array $websiteProfile,
        array $scope
    ): array {
        foreach ($virtualPages as $pageType => $record) {
            if (!\is_array($record)) {
                continue;
            }
            if (\trim((string)($record['page_type'] ?? '')) === '') {
                $record['page_type'] = (string)$pageType;
            }
            $virtualPages[$pageType] = $this->localizeVirtualPageRecordForVisitorLocale(
                $record,
                $locale,
                $websiteProfile,
                $scope
            );
        }

        return $virtualPages;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function localizeVirtualPageRecordForVisitorLocale(
        array $record,
        string $locale,
        array $websiteProfile,
        array $scope
    ): array {
        $locale = \trim($locale);
        if ($locale === '') {
            return $record;
        }

        $pageType = \trim((string)($record['page_type'] ?? ''));
        $siteTitle = $this->safeVisitorTextForLocale(
            (string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? ''),
            $locale
        );
        $title = \trim((string)($record['title'] ?? ''));
        $localizedTitle = $this->localizeGenericPageTitle($title, $locale);
        if ($localizedTitle === '' && $this->isLocaleUnsafeVisitorText($title, $locale)) {
            $localizedTitle = $this->localizePageTypeLabel($pageType, $locale);
        }
        if ($localizedTitle !== '') {
            $record['title'] = $localizedTitle;
            $title = $localizedTitle;
        }

        $metaTitle = \trim((string)($record['meta_title'] ?? ''));
        if ($this->isLocaleUnsafeVisitorText($metaTitle, $locale)) {
            $safeTitle = $this->safeVisitorTextForLocale($title, $locale);
            if ($safeTitle === '') {
                $safeTitle = $this->localizePageTypeLabel($pageType, $locale);
            }
            if ($safeTitle !== '' && $siteTitle !== '' && \stripos($safeTitle, $siteTitle) === false) {
                $safeTitle .= ' | ' . $siteTitle;
            }
            $record['meta_title'] = $safeTitle;
        }

        foreach (['meta_description', 'ai_description'] as $key) {
            if ($this->isLocaleUnsafeVisitorText((string)($record[$key] ?? ''), $locale)) {
                $record[$key] = '';
            }
        }

        if ($this->isLocaleUnsafeVisitorText((string)($record['meta_keywords'] ?? ''), $locale)) {
            $record['meta_keywords'] = $this->filterLocaleSafeKeywords((string)$record['meta_keywords'], $locale);
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    private function sanitizeWebsiteProfileVisitorMetadataForLocale(array $websiteProfile, string $locale): array
    {
        foreach (['site_tagline', 'tagline', 'meta_description'] as $key) {
            if ($this->isLocaleUnsafeVisitorText((string)($websiteProfile[$key] ?? ''), $locale)) {
                $websiteProfile[$key] = '';
            }
        }

        if (\is_array($websiteProfile['seo'] ?? null)) {
            if ($this->isLocaleUnsafeVisitorText((string)($websiteProfile['seo']['meta_title'] ?? ''), $locale)) {
                $safeTitle = $this->safeVisitorTextForLocale((string)($websiteProfile['site_title'] ?? ''), $locale);
                $websiteProfile['seo']['meta_title'] = $safeTitle;
            }
            if ($this->isLocaleUnsafeVisitorText((string)($websiteProfile['seo']['meta_description'] ?? ''), $locale)) {
                $websiteProfile['seo']['meta_description'] = '';
            }
            if ($this->isLocaleUnsafeVisitorText((string)($websiteProfile['seo']['meta_keywords'] ?? ''), $locale)) {
                $websiteProfile['seo']['meta_keywords'] = $this->filterLocaleSafeKeywords(
                    (string)$websiteProfile['seo']['meta_keywords'],
                    $locale
                );
            }
        }

        return $websiteProfile;
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
        $selected = $this->resolveSelectedContentLocale($scope, $websiteProfile);
        if ($selected !== '') {
            return $selected;
        }

        $explicit = \trim((string)(
            $scope['content_locale']
                ?? $websiteProfile['content_locale']
                ?? ''
        ));
        if ($explicit !== '') {
            return $explicit;
        }

        $inferred = $this->inferContentLocaleFromBrief($scope, $websiteProfile);
        if ($inferred !== '') {
            return $inferred;
        }

        return \trim((string)(
            $scope['default_locale']
                ?? $scope['default_language']
                ?? $websiteProfile['default_locale']
                ?? ''
        ));
    }

    /**
     * Preview rendering must follow the locale that actually produced the AI
     * output. Older sessions can keep an English default locale while the
     * generated plan is Chinese; using that stale default re-localizes shared
     * header/footer back to English during preview.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function normalizePreviewContentLocale(array $scope, string $requestedLocale = ''): array
    {
        $locale = $this->resolvePreviewContentLocale($scope, $requestedLocale);
        if ($locale === '') {
            return $scope;
        }

        $scope['content_locale'] = $locale;
        $scope['ai_content_locale'] = $locale;
        if (!\is_array($scope['website_profile'] ?? null)) {
            $scope['website_profile'] = [];
        }
        $scope['website_profile']['content_locale'] = $locale;
        $scope = $this->normalizePlanJsonStageOneContractLocale($scope, $locale);

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function normalizePlanJsonStageOneContractLocale(array $scope, string $locale): array
    {
        if (!\is_array($scope['plan_json']['stage1_validation_contract'] ?? null)) {
            return $scope;
        }

        $scope['plan_json']['stage1_validation_contract']['content_locale'] = $locale;
        $scope['plan_json']['stage1_validation_contract']['plan_locale'] = $locale;
        if (\is_array($scope['plan_json']['stage1_validation_contract']['shared_prompt_context'] ?? null)) {
            $scope['plan_json']['stage1_validation_contract']['shared_prompt_context']['content_locale'] = $locale;
        }
        if (\is_array($scope['plan_json']['stage1_validation_contract']['shared_components'] ?? null)) {
            foreach ($scope['plan_json']['stage1_validation_contract']['shared_components'] as $componentKey => $component) {
                if (\is_array($component)) {
                    $scope['plan_json']['stage1_validation_contract']['shared_components'][$componentKey]['content_locale'] = $locale;
                }
            }
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function PlanJsonManifestSource(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        return [
            'theme_design' => \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [],
            'theme_style' => \is_array($planJson['theme_style'] ?? null) ? $planJson['theme_style'] : [],
            'site_strategy' => \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [],
            'palette' => \is_array($planJson['palette'] ?? null) ? $planJson['palette'] : [],
            'plan_json' => $planJson,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function resolvePreviewContentLocale(array $scope, string $requestedLocale = ''): string
    {
        $requestedLocale = \trim($requestedLocale);
        $generated = $this->resolveGeneratedContentLocale($scope);
        if ($requestedLocale !== '') {
            if ($this->isEnglishLocale($requestedLocale) && $generated !== '' && !$this->isEnglishLocale($generated)) {
                return $generated;
            }

            return $requestedLocale;
        }

        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $current = \trim((string)(
            $scope['content_locale']
                ?? $websiteProfile['content_locale']
                ?? $scope['default_locale']
                ?? $scope['default_language']
                ?? $websiteProfile['default_locale']
                ?? $websiteProfile['default_language']
                ?? ''
        ));
        if (
            $generated !== ''
            && (
                $current === ''
                || ($this->isEnglishLocale($current) && !$this->isEnglishLocale($generated))
            )
        ) {
            return $generated;
        }

        return $current !== '' ? $current : $generated;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveGeneratedContentLocale(array $scope): string
    {
        foreach ([
            $scope['plan_generated_locale'] ?? null,
            $scope['plan_json']['plan_generated_locale'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $locale = \trim((string)$candidate);
            if ($locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    private function isEnglishLocale(string $locale): bool
    {
        return \preg_match('/^en(?:[_-]|$)/i', \trim($locale)) === 1;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function resolveExplicitContentLocale(array $scope, array $websiteProfile): string
    {
        $selected = $this->resolveSelectedContentLocale($scope, $websiteProfile);
        if ($selected !== '') {
            return $selected;
        }

        $explicit = \trim((string)($scope['content_locale'] ?? $websiteProfile['content_locale'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return $this->inferContentLocaleFromBrief($scope, $websiteProfile);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function resolveSelectedContentLocale(array $scope, array $websiteProfile): string
    {
        if ($this->contentLocaleLooksLikeAdminDefaultLeak($scope, $websiteProfile)) {
            return \trim((string)($scope['plan_locale'] ?? ''));
        }

        foreach ([
            $scope['ai_content_locale'] ?? null,
            $scope['selected_content_locale'] ?? null,
            $scope['selected_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $websiteProfile['default_locale'] ?? null,
            $scope['content_locale'] ?? null,
            $websiteProfile['content_locale'] ?? null,
            $scope['plan_locale'] ?? null,
            $scope['default_language'] ?? null,
            $websiteProfile['default_language'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $locale = \trim((string)$candidate);
            if ($locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function inferContentLocaleFromBrief(array $scope, array $websiteProfile): string
    {
        $parts = [];
        foreach ([
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
            $scope['site_description'] ?? null,
            $scope['source_brief'] ?? null,
            $scope['requirements']['expanded_brief'] ?? null,
            $websiteProfile['brief_description'] ?? null,
            $websiteProfile['_ai_profile']['source_brief'] ?? null,
        ] as $candidate) {
            if (\is_scalar($candidate)) {
                $value = \trim((string)$candidate);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        $text = \implode("\n", $parts);
        if ($text === '') {
            return '';
        }

        if (\preg_match('/\bhi(?:[_-]IN)?\b|Hindi|Hindustani|Devanagari|闂佸憡顨嗗妯猴耿鐎靛憡瀚氶柣鈥虫嚇瀹曪繝寮堕幐搴″瑎闂佸搫鍊稿▍澶愭煕濡や焦绶查悗鐟扮－閹风娀鎮洪崨顓熸闁诲繐琚禒褔宕煬鎻掑Υ妞ゃ劍甯掗弳銉╂晜濞堟寧鐣秥闁哥喎澧庢禍鎾Φ閻旈攱娈介柛灞炬磸娴滅偤宕亸浣镐壕/iu', $text) === 1) {
            return 'hi_IN';
        }
        if (\preg_match('/\bth(?:[_-]TH)?\b|Thai|濠电偛顦版刊浠嬵敋椤ｃ垺绻涙径灞筋暭闁哄鍓熼柛鐘虫⒒婵亞鎲伴崱妤佺亙闁崇澹堥々顐﹀窗濞嗗繐顏＄紒妤佽壘閺?iu', $text) === 1) {
            return 'th_TH';
        }
        if (\preg_match('/\bzh(?:[_-](?:Hans|CN|SG))?\b|缂備胶濮崑鎾趁归敐鍡樺矮闁煎灚鍨垮顒勫闯閻斿摜鈻旀い鎾跺枑閻庣晼Chinese/iu', $text) === 1) {
            return 'zh_Hans_CN';
        }
        if (\preg_match('/\bru(?:[_-]RU)?\b|Russian|婵烇絽娲ょ€氼垶顢氶。銏㈡喐婢跺寒妲婚悷婊冾儓椤︽澘鈻旂紒妯烘珳婵?iu', $text) === 1) {
            return 'ru_RU';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function contentLocaleLooksLikeAdminDefaultLeak(array $scope, array $websiteProfile): bool
    {
        if (\trim((string)($scope['ai_content_locale'] ?? '')) !== '') {
            return false;
        }

        $contentLocale = \trim((string)($scope['content_locale'] ?? ''));
        $defaultLocale = \trim((string)($scope['default_locale'] ?? $websiteProfile['default_locale'] ?? ''));

        return $contentLocale !== ''
            && $defaultLocale !== ''
            && \trim((string)($scope['plan_locale'] ?? '')) !== ''
            && $contentLocale === $defaultLocale
            && \trim((string)($scope['plan_locale'] ?? '')) !== $contentLocale;
    }

    /**
     * @param array<int|string, mixed> $locales
     * @return list<string>
     */
    private function prependLocaleToList(array $locales, string $locale): array
    {
        $locale = \trim($locale);
        $result = [];
        if ($locale !== '') {
            $result[] = $locale;
        }
        foreach ($locales as $item) {
            $candidate = \trim((string)$item);
            if ($candidate === '' || \in_array($candidate, $result, true)) {
                continue;
            }
            $result[] = $candidate;
        }

        return $result;
    }

    private function localizeBuildText(string $key, string $locale): string
    {
        $labels = [
            'home' => 'Home',
            'about' => 'About',
            'contact' => 'Contact',
            'blog' => 'Blog',
            'blog_category' => 'Blog Categories',
            'custom_page' => 'Page',
            'privacy_policy' => 'Privacy Policy',
            'terms_of_service' => 'Terms of Service',
            'refund_policy' => 'Refund Policy',
            'shipping_policy' => 'Shipping Policy',
            'cookie_policy' => 'Cookie Policy',
            'policy_info' => 'Policy Info',
            'featured_pages' => 'Featured Pages',
            'all_pages' => 'All Pages',
            'all_rights_reserved' => 'All rights reserved.',
            'brand_summary' => 'A clear destination for downloads, policy information, and support.',
            'download_now' => 'Download Now',
        ];

        return (string)($labels[$key] ?? $key);
    }

    private function localizeGenericPageTitle(string $title, string $locale): string
    {
        $normalized = $this->normalizePageTypeFromLabel($title);
        if ($normalized === '') {
            return '';
        }

        return $this->localizePageTypeLabel($normalized, $locale) ?: $title;
    }

    private function localizeLinkItemsValue(mixed $value, string $locale): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        foreach ($value as $index => $item) {
            if (\is_array($item)) {
                foreach (['label', 'title', 'text'] as $key) {
                    if (\array_key_exists($key, $item) && \is_scalar($item[$key])) {
                        $item[$key] = $this->localizeGenericPageTitle((string)$item[$key], $locale);
                    }
                }
                $value[$index] = $item;
            } elseif (\is_scalar($item)) {
                $value[$index] = $this->localizeGenericPageTitle((string)$item, $locale);
            }
        }

        return $value;
    }

    private function normalizePageTypeFromLabel(string $label): string
    {
        $normalized = \strtolower(\trim(\preg_replace('/\s+/u', ' ', $label) ?? $label));
        $map = [
            'home' => Page::TYPE_HOME,
            'about' => Page::TYPE_ABOUT,
            'about us' => Page::TYPE_ABOUT,
            'contact' => Page::TYPE_CONTACT,
            'contact us' => Page::TYPE_CONTACT,
            'blog' => Page::TYPE_BLOG,
            'privacy' => Page::TYPE_PRIVACY_POLICY,
            'privacy policy' => Page::TYPE_PRIVACY_POLICY,
            'terms' => Page::TYPE_TERMS_OF_SERVICE,
            'terms of service' => Page::TYPE_TERMS_OF_SERVICE,
            'refund policy' => Page::TYPE_REFUND_POLICY,
            'shipping policy' => Page::TYPE_SHIPPING_POLICY,
            'cookie policy' => Page::TYPE_COOKIE_POLICY,
        ];

        return (string)($map[$normalized] ?? '');
    }

    private function looksLikeEnglishNavOrFooterLabel(string $value): bool
    {
        return $this->normalizePageTypeFromLabel($value) !== ''
            || \in_array(\strtolower(\trim($value)), ['featured pages', 'all pages', 'policy info', 'download now'], true);
    }

    private function isGenericFooterBoilerplate(string $value): bool
    {
        $normalized = \strtolower(\trim(\preg_replace('/\s+/u', ' ', $value) ?? $value));

        return \in_array($normalized, ['all rights reserved.', 'all rights reserved', 'copyright'], true);
    }

    private function localeFamily(string $locale): string
    {
        $locale = \trim($locale);
        if ($this->isThaiLocale($locale)) {
            return 'th';
        }
        if ($this->isHindiLocale($locale)) {
            return 'hi';
        }
        if ($this->isChineseLocale($locale)) {
            return 'zh';
        }
        if ($this->isRussianLocale($locale)) {
            return 'ru';
        }
        if ($this->isPortugueseLocale($locale)) {
            return 'pt';
        }

        return 'en';
    }

    private function localizePageTypeLabel(string $pageType, string $locale): string
    {
        $key = match ($pageType) {
            Page::TYPE_HOME => 'home',
            Page::TYPE_ABOUT => 'about',
            Page::TYPE_CONTACT => 'contact',
            Page::TYPE_BLOG_LIST, Page::TYPE_BLOG => 'blog',
            Page::TYPE_BLOG_CATEGORY => 'blog_category',
            Page::TYPE_CUSTOM => 'custom_page',
            Page::TYPE_PRIVACY_POLICY => 'privacy_policy',
            Page::TYPE_TERMS_OF_SERVICE => 'terms_of_service',
            Page::TYPE_REFUND_POLICY => 'refund_policy',
            Page::TYPE_SHIPPING_POLICY => 'shipping_policy',
            Page::TYPE_COOKIE_POLICY => 'cookie_policy',
            default => '',
        };

        return $key !== '' ? $this->localizeBuildText($key, $locale) : '';
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

    private function isRussianLocale(string $locale): bool
    {
        return \preg_match('/^ru(?:[_-]|$)/i', \trim($locale)) === 1;
    }

    private function isThaiLocale(string $locale): bool
    {
        return \preg_match('/^th(?:[_-]|$)/i', \trim($locale)) === 1;
    }

    private function isHindiLocale(string $locale): bool
    {
        return \preg_match('/^(?:hi|hi[_-]in)(?:[_-]|$)/i', \trim($locale)) === 1;
    }

    private function isPortugueseLocale(string $locale): bool
    {
        return \preg_match('/^pt(?:[_-]|$)/i', \trim($locale)) === 1;
    }

    private function isNonCjkLocale(string $locale): bool
    {
        return $locale !== '' && !$this->isChineseLocale($locale) && !$this->isJapaneseLocale($locale) && !$this->isKoreanLocale($locale);
    }

    private function hasAnyCjkContent(string $value): bool
    {
        return \preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $value) === 1;
    }

    private function isLocaleUnsafeVisitorText(string $value, string $locale): bool
    {
        return \trim($value) !== ''
            && $this->isNonCjkLocale($locale)
            && $this->hasAnyCjkContent($value);
    }

    private function safeVisitorTextForLocale(string $value, string $locale): string
    {
        $value = \trim($value);
        return $this->isLocaleUnsafeVisitorText($value, $locale) ? '' : $value;
    }

    private function filterLocaleSafeKeywords(string $value, string $locale): string
    {
        $keywords = \preg_split('/[,;|]+/u', $value) ?: [];
        $safe = [];
        foreach ($keywords as $keyword) {
            $keyword = \trim((string)$keyword);
            if ($keyword === '' || $this->isLocaleUnsafeVisitorText($keyword, $locale)) {
                continue;
            }
            $safe[] = $keyword;
        }

        return \implode(', ', \array_values(\array_unique($safe)));
    }
}

