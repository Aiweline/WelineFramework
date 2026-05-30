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
    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function stripDeprecatedScopeArtifactKeys(array $scope): array
    {
        foreach ([
            'execution_blueprint',
            'execution_blueprint_draft',
            'execution_blueprint_confirmed_signature',
            'execution_blueprint_confirmed_at',
            'build_blueprint',
            'build_tasks',
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
                foreach (['execution_blueprint', 'build_blueprint'] as $artifactKey) {
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

        $planWorkbench = \is_array($scope['plan_workbench'] ?? null) ? $scope['plan_workbench'] : [];
        $confirmed = \is_array($planWorkbench['confirmed'] ?? null) ? $planWorkbench['confirmed'] : [];
        if ($confirmed !== []) {
            unset($confirmed['execution_blueprint']);
            $planWorkbench['confirmed'] = $confirmed;
            $scope['plan_workbench'] = $planWorkbench;
        }

        return $scope;
    }

    public function normalizeScope(array $scope): array
    {
        $normalized = $this->stripDeprecatedScopeArtifactKeys($scope);
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
        $explicitContentLocale = $this->resolveExplicitContentLocale($normalized, $normalized['website_profile']);
        if ($explicitContentLocale !== '') {
            $normalized['content_locale'] = $explicitContentLocale;
            $normalized['website_profile']['content_locale'] = $explicitContentLocale;
            $normalized['website_profile'] = $this->sanitizeWebsiteProfileVisitorMetadataForLocale(
                $normalized['website_profile'],
                $explicitContentLocale
            );
            if (\is_array($normalized['plan_json'] ?? null)) {
                $normalized['plan_json']['content_locale'] = $explicitContentLocale;
                if (\is_array($normalized['plan_json']['i18n'] ?? null)) {
                    $normalized['plan_json']['i18n']['content_locale'] = $explicitContentLocale;
                }
            }
            if (\is_array($normalized['build_plan_v2'] ?? null)) {
                if (!\is_array($normalized['build_plan_v2']['i18n'] ?? null)) {
                    $normalized['build_plan_v2']['i18n'] = [];
                }
                $normalized['build_plan_v2']['i18n']['primary_locale'] = $explicitContentLocale;
            }
            if (\is_array($normalized['stage1_contract'] ?? null)) {
                $normalized['stage1_contract']['content_locale'] = $explicitContentLocale;
                $normalized['stage1_contract']['plan_locale'] = $explicitContentLocale;
                if (\is_array($normalized['stage1_contract']['i18n'] ?? null)) {
                    $normalized['stage1_contract']['i18n']['content_locale'] = $explicitContentLocale;
                    $normalized['stage1_contract']['i18n']['primary_locale'] = $explicitContentLocale;
                }
                if (\is_array($normalized['stage1_contract']['shared_prompt_context'] ?? null)) {
                    $normalized['stage1_contract']['shared_prompt_context']['content_locale'] = $explicitContentLocale;
                }
                if (\is_array($normalized['stage1_contract']['shared_components'] ?? null)) {
                    foreach ($normalized['stage1_contract']['shared_components'] as $componentKey => $componentPlan) {
                        if (\is_array($componentPlan)) {
                            $normalized['stage1_contract']['shared_components'][$componentKey]['content_locale'] = $explicitContentLocale;
                        }
                    }
                }
            }
            $normalized['virtual_pages_by_type'] = $this->localizeVirtualPagesForVisitorLocale(
                $normalized['virtual_pages_by_type'],
                $explicitContentLocale,
                $normalized['website_profile'],
                $normalized
            );
            $normalized['locales'] = $this->prependLocaleToList(
                \is_array($normalized['locales'] ?? null) ? $normalized['locales'] : [],
                $explicitContentLocale
            );
        }
        foreach ($normalized['page_type_layouts'] as $pageType => $layout) {
            if (\is_array($layout)) {
                $normalized['page_type_layouts'][$pageType] = $this->localizeSharedLayoutConfigForScope($layout, $normalized, (string)$pageType);
            }
        }
        $routeContractRaw = \is_array($scope['page_route_contract'] ?? null)
            ? $scope['page_route_contract']
            : (\is_array($scope['stage1_contract']['page_route_contract'] ?? null) ? $scope['stage1_contract']['page_route_contract'] : []);
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
        $manifestSource = $this->buildPlanManifestSource($scope);
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

        $policy = new AiSiteScopeManifestPolicy();
        $scope['virtual_page_index'] = $policy->buildVirtualPageIndex(
            \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : []
        );

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function hasConfirmedStageOnePlanForBuildPlan(array $scope): bool
    {
        $buildPlan = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        $buildPlanMeta = \is_array($buildPlan['contract_meta'] ?? null) ? $buildPlan['contract_meta'] : [];
        if ((int)($scope['build_plan_confirmed'] ?? 0) === 1
            || \strtolower(\trim((string)($buildPlanMeta['status'] ?? ''))) === 'confirmed'
        ) {
            return true;
        }

        if ((int)($scope['plan_confirmed'] ?? 0) === 1 && $this->hasPersistedStageOnePlan($scope)) {
            return true;
        }

        return false;
    }

    /**
     * Backend-owned existence check for a persisted stage-one plan.
     *
     * @param array<string, mixed> $scope
     */
    public function hasPersistedStageOnePlan(array $scope): bool
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planStructured = \is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [];

        return $this->isUsableStageOnePlanJson($planJson)
            || $this->isUsableStageOnePlanJson($planStructured);
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
     * Newer stage-one artifacts are validated by the contract validator and may
     * intentionally omit legacy requirement_expansion/theme_detail fields. Treat
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

        foreach (['pages', 'page_plans'] as $key) {
            $pages = \is_array($planJson[$key] ?? null) ? $planJson[$key] : [];
            if ($this->stageOnePlanPagesContainBlocks($pages)) {
                return true;
            }
        }

        return false;
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
            foreach (['blocks', 'block_blueprints', 'ordered_block_keys'] as $key) {
                if (\is_array($page[$key] ?? null) && $page[$key] !== []) {
                    return true;
                }
            }
            if (\is_array($page['page'] ?? null) && $this->stageOnePlanPagesContainBlocks([$page['page']])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function normalizeConfirmedPlanFlag(array $scope): array
    {
        if ($this->hasConfirmedStageOnePlanForBuildPlan($scope)) {
            $scope['plan_confirmed'] = 1;
            $confirmedAt = \trim((string)($scope['build_plan_confirmed_at'] ?? ''));
            if ($confirmedAt !== '' && \trim((string)($scope['plan_confirmed_at'] ?? '')) === '') {
                $scope['plan_confirmed_at'] = $confirmedAt;
            }
        }

        return $scope;
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

    /**
     * Old website-hub drafts may mark the legacy home/about/contact set as
     * customized before the brief-driven pages are inferred. Keep true manual
     * selections intact, but expand that legacy set when the brief asks for
     * product/academy style pages that PageBuilder maps to generic page codes.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function augmentLegacyDefaultPageTypesFromBrief(array $scope): array
    {
        $pageTypes = $this->resolveScopedPageTypes($scope);
        if (!$this->matchesLegacyDefaultPageTypes($pageTypes)) {
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
        $inputVirtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $layouts = $this->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $siteTitle = \trim((string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? ''));
        $contentLocale = $this->resolveContentLocale($scope, $websiteProfile);
        $pageRouteContract = $this->getPageRouteContractService()->normalize(
            \is_array($scope['page_route_contract'] ?? null)
                ? $scope['page_route_contract']
                : (\is_array($scope['stage1_contract']['page_route_contract'] ?? null) ? $scope['stage1_contract']['page_route_contract'] : []),
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

        if (\is_array($layout['header']['config'] ?? null)) {
            $layout['header']['config'] = $this->localizeHeaderLayoutConfig($layout['header']['config'], $locale);
        }
        if (\is_array($layout['footer']['config'] ?? null)) {
            $layout['footer']['config'] = $this->localizeFooterLayoutConfig($layout['footer']['config'], $locale);
        }

        return $layout;
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

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildPlanManifestSource(array $scope): array
    {
        $buildPlan = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if ($buildPlan !== []) {
            $designManifest = \is_array($buildPlan['design_manifest'] ?? null) ? $buildPlan['design_manifest'] : [];
            $sourceTruth = \is_array($buildPlan['source_of_truth'] ?? null) ? $buildPlan['source_of_truth'] : [];
            $requirements = \is_array($sourceTruth['user_requirements'] ?? null) ? $sourceTruth['user_requirements'] : [];

            return [
                'theme_design' => \is_array($designManifest['visual_contract'] ?? null)
                    ? $designManifest['visual_contract']
                    : (\is_array($designManifest['theme_design'] ?? null) ? $designManifest['theme_design'] : []),
                'theme_style' => \is_array($designManifest['theme_style'] ?? null) ? $designManifest['theme_style'] : [],
                'palette' => \is_array($designManifest['palette'] ?? null) ? $designManifest['palette'] : [],
                'site_strategy' => $requirements,
            ];
        }

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
            $scope['plan_workbench']['confirmed']['plan_generated_locale'] ?? null,
            $scope['plan_structured']['plan_generated_locale'] ?? null,
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
        foreach ([
            $scope['ai_content_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $websiteProfile['default_locale'] ?? null,
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
            $scope['build_plan_v2']['site_brief']['summary'] ?? null,
            $scope['build_plan_v2']['i18n']['primary_locale'] ?? null,
            $scope['plan_json']['content_locale'] ?? null,
            $scope['plan_json']['i18n']['content_locale'] ?? null,
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

        if (\preg_match('/\bhi(?:[_-]IN)?\b|Hindi|Hindustani|Devanagari|印地语|印地文|印度语|हिन्दी|हिंदी/iu', $text) === 1) {
            return 'hi_IN';
        }
        if (\preg_match('/\bth(?:[_-]TH)?\b|Thai|泰语|泰文|ภาษาไทย/iu', $text) === 1) {
            return 'th_TH';
        }
        if (\preg_match('/\bzh(?:[_-](?:Hans|CN|SG))?\b|简体中文|中文|Chinese/iu', $text) === 1) {
            return 'zh_Hans_CN';
        }
        if (\preg_match('/\bru(?:[_-]RU)?\b|Russian|俄语|русский/iu', $text) === 1) {
            return 'ru_RU';
        }

        return '';
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
        if ($this->isHindiLocale($locale)) {
            return match ($key) {
                'home' => "\u{0939}\u{094B}\u{092E}",
                'about' => "\u{0939}\u{092E}\u{093E}\u{0930}\u{0947} \u{092C}\u{093E}\u{0930}\u{0947} \u{092E}\u{0947}\u{0902}",
                'contact' => "\u{0938}\u{0902}\u{092A}\u{0930}\u{094D}\u{0915} \u{0915}\u{0930}\u{0947}\u{0902}",
                'blog' => "\u{092C}\u{094D}\u{0932}\u{0949}\u{0917}",
                'privacy_policy' => "\u{0917}\u{094B}\u{092A}\u{0928}\u{0940}\u{092F}\u{0924}\u{093E} \u{0928}\u{0940}\u{0924}\u{093F}",
                'terms_of_service' => "\u{0938}\u{0947}\u{0935}\u{093E} \u{0915}\u{0940} \u{0936}\u{0930}\u{094D}\u{0924}\u{0947}\u{0902}",
                'refund_policy' => "\u{0930}\u{093F}\u{092B}\u{0902}\u{0921} \u{0928}\u{0940}\u{0924}\u{093F}",
                'shipping_policy' => "\u{0936}\u{093F}\u{092A}\u{093F}\u{0902}\u{0917} \u{0928}\u{0940}\u{0924}\u{093F}",
                'cookie_policy' => "\u{0915}\u{0941}\u{0915}\u{0940} \u{0928}\u{0940}\u{0924}\u{093F}",
                'policy_info' => "\u{0928}\u{0940}\u{0924}\u{093F} \u{091C}\u{093E}\u{0928}\u{0915}\u{093E}\u{0930}\u{0940}",
                'featured_pages' => "\u{092E}\u{0941}\u{0916}\u{094D}\u{092F} \u{092A}\u{0947}\u{091C}",
                'all_pages' => "\u{0938}\u{092D}\u{0940} \u{092A}\u{0947}\u{091C}",
                'all_rights_reserved' => "\u{0938}\u{0930}\u{094D}\u{0935}\u{093E}\u{0927}\u{093F}\u{0915}\u{093E}\u{0930} \u{0938}\u{0941}\u{0930}\u{0915}\u{094D}\u{0937}\u{093F}\u{0924}\u{0964}",
                'brand_summary' => "\u{0915}\u{093E}\u{0930}\u{094D}\u{0921} \u{0917}\u{0947}\u{092E} APK \u{0921}\u{093E}\u{0909}\u{0928}\u{0932}\u{094B}\u{0921}, \u{0928}\u{093F}\u{092F}\u{092E} \u{0914}\u{0930} \u{0938}\u{0939}\u{093E}\u{092F}\u{0924}\u{093E} \u{0915}\u{0947} \u{0932}\u{093F}\u{090F} \u{092D}\u{0930}\u{094B}\u{0938}\u{0947}\u{092E}\u{0902}\u{0926} \u{0915}\u{0947}\u{0902}\u{0926}\u{094D}\u{0930}\u{0964}",
                'download_now' => "\u{0905}\u{092D}\u{0940} \u{0921}\u{093E}\u{0909}\u{0928}\u{0932}\u{094B}\u{0921} \u{0915}\u{0930}\u{0947}\u{0902}",
                default => $key,
            };
        }
        $labels = [
            'en' => [
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
                'brand_summary' => 'A curated destination with clear information, trusted support, and simple next steps.',
                'download_now' => 'Download Now',
            ],
            'pt' => [
                'home' => 'Início',
                'about' => 'Sobre',
                'contact' => 'Contato',
                'blog' => 'Blog',
                'blog_category' => 'Categorias',
                'custom_page' => 'Pagina',
                'privacy_policy' => 'Política de Privacidade',
                'terms_of_service' => 'Termos de Serviço',
                'refund_policy' => 'Política de Reembolso',
                'shipping_policy' => 'Política de Envio',
                'cookie_policy' => 'Política de Cookies',
                'policy_info' => 'Informações legais',
                'featured_pages' => 'Páginas principais',
                'all_pages' => 'Todas as páginas',
                'all_rights_reserved' => 'Todos os direitos reservados.',
                'brand_summary' => 'Destino confiável para baixar APK de jogos de cartas, consultar regras e obter suporte.',
                'download_now' => 'Baixar Agora',
            ],
            'th' => [
                'home' => 'หน้าแรก',
                'about' => 'เกี่ยวกับเรา',
                'contact' => 'ติดต่อเรา',
                'blog' => 'บทความ',
                'privacy_policy' => 'นโยบายความเป็นส่วนตัว',
                'terms_of_service' => 'ข้อกำหนดการใช้บริการ',
                'refund_policy' => 'นโยบายการคืนเงิน',
                'shipping_policy' => 'นโยบายการจัดส่ง',
                'cookie_policy' => 'นโยบายคุกกี้',
                'policy_info' => 'ข้อมูลนโยบาย',
                'featured_pages' => 'หน้าสำคัญ',
                'all_pages' => 'ทุกหน้า',
                'all_rights_reserved' => 'สงวนลิขสิทธิ์',
                'brand_summary' => 'เว็บไซต์เกมไพ่ APK ที่รวมข้อมูลดาวน์โหลด กติกา และช่องทางช่วยเหลือสำหรับผู้เล่น',
                'download_now' => 'ดาวน์โหลดตอนนี้',
            ],
            'hi' => [
                'home' => 'होम',
                'about' => 'हमारे बारे में',
                'contact' => 'संपर्क करें',
                'blog' => 'ब्लॉग',
                'privacy_policy' => 'गोपनीयता नीति',
                'terms_of_service' => 'सेवा की शर्तें',
                'refund_policy' => 'रिफंड नीति',
                'shipping_policy' => 'शिपिंग नीति',
                'cookie_policy' => 'कुकी नीति',
                'policy_info' => 'नीति जानकारी',
                'featured_pages' => 'मुख्य पेज',
                'all_pages' => 'सभी पेज',
                'all_rights_reserved' => 'सर्वाधिकार सुरक्षित।',
                'brand_summary' => 'डाउनलोड, नियम और सहायता जानकारी के साथ एक कार्ड गेम APK गाइड',
                'download_now' => 'अभी डाउनलोड करें',
            ],
            'zh' => [
                'home' => '首页',
                'about' => '关于我们',
                'contact' => '联系我们',
                'blog' => '博客',
                'privacy_policy' => '隐私政策',
                'terms_of_service' => '服务条款',
                'refund_policy' => '退款政策',
                'shipping_policy' => '配送政策',
                'cookie_policy' => 'Cookie 政策',
                'policy_info' => '政策信息',
                'featured_pages' => '重点页面',
                'all_pages' => '全部页面',
                'all_rights_reserved' => '保留所有权利。',
                'brand_summary' => '棋牌 APK 下载、规则与支持信息站点。',
                'download_now' => '立即下载',
            ],
            'ru' => [
                'home' => 'Главная',
                'about' => 'О нас',
                'contact' => 'Контакты',
                'blog' => 'Блог',
                'privacy_policy' => 'Политика конфиденциальности',
                'terms_of_service' => 'Условия использования',
                'refund_policy' => 'Политика возврата',
                'shipping_policy' => 'Доставка',
                'cookie_policy' => 'Политика Cookie',
                'policy_info' => 'Правовая информация',
                'featured_pages' => 'Основные разделы',
                'all_pages' => 'Все разделы',
                'all_rights_reserved' => 'Все права защищены.',
                'brand_summary' => 'Сайт APK для карточных игр с информацией о загрузке, правилах и поддержке.',
                'download_now' => 'Скачать сейчас',
            ],
        ];

        $family = $this->localeFamily($locale);
        return (string)($labels[$family][$key] ?? $key);
    }

    private function localizeLinkItemsValue(mixed $value, string $locale): mixed
    {
        if (\is_array($value)) {
            $items = [];
            $seen = [];
            foreach ($value as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $label = (string)($item['label'] ?? $item['text'] ?? $item['name'] ?? '');
                $href = (string)($item['href'] ?? $item['url'] ?? '#');
                $canonical = $this->canonicalNavLabelKey($label, $href, (string)($item['type'] ?? ''));
                if ($canonical !== '') {
                    $localized = $this->localizeBuildText($canonical, $locale);
                    $item['label'] = $localized;
                    $item['text'] = $localized;
                    $item['name'] = $localized;
                }
                $dedupe = \strtolower($href . '|' . (string)($item['label'] ?? $item['text'] ?? $item['name'] ?? ''));
                if (isset($seen[$dedupe])) {
                    continue;
                }
                $seen[$dedupe] = true;
                $items[] = $item;
            }

            return $items;
        }

        $raw = \trim((string)$value);
        if ($raw === '') {
            return $value;
        }

        $lines = [];
        $seen = [];
        foreach (\preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = \trim((string)$line);
            if ($line === '') {
                continue;
            }
            [$label, $href] = \array_pad(\explode('=>', $line, 2), 2, '#');
            $label = \trim((string)$label);
            $href = \trim((string)$href);
            $canonical = $this->canonicalNavLabelKey($label, $href);
            if ($canonical !== '') {
                $label = $this->localizeBuildText($canonical, $locale);
            }
            $dedupe = \strtolower($href . '|' . $label);
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $lines[] = $label . '=>' . ($href !== '' ? $href : '#');
        }

        return \implode("\n", $lines);
    }

    private function canonicalNavLabelKey(string $label, string $href = '', string $type = ''): string
    {
        $typeMap = [
            Page::TYPE_HOME => 'home',
            Page::TYPE_ABOUT => 'about',
            Page::TYPE_CONTACT => 'contact',
            Page::TYPE_BLOG_LIST => 'blog',
            Page::TYPE_BLOG => 'blog',
            Page::TYPE_PRIVACY_POLICY => 'privacy_policy',
            Page::TYPE_TERMS_OF_SERVICE => 'terms_of_service',
            Page::TYPE_REFUND_POLICY => 'refund_policy',
            Page::TYPE_SHIPPING_POLICY => 'shipping_policy',
            Page::TYPE_COOKIE_POLICY => 'cookie_policy',
            'policy_info' => 'policy_info',
        ];
        if (isset($typeMap[$type])) {
            return $typeMap[$type];
        }

        $normalized = \strtolower(\trim(\preg_replace('/[\s\-_]+/u', ' ', $label) ?? $label));
        $labelMap = [
            'home' => 'home',
            'homepage' => 'home',
            '首页' => 'home',
            '主页' => 'home',
            'início' => 'home',
            'inicio' => 'home',
            'about' => 'about',
            'about us' => 'about',
            '关于我们' => 'about',
            'sobre' => 'about',
            'contact' => 'contact',
            'contact us' => 'contact',
            '联系我们' => 'contact',
            'contato' => 'contact',
            'blog' => 'blog',
            'news' => 'blog',
            'blog category' => 'blog_category',
            'blog categories' => 'blog_category',
            "\u{535A}\u{5BA2}\u{5206}\u{7C7B}" => 'blog_category',
            'categorias' => 'blog_category',
            'categorias do blog' => 'blog_category',
            'custom page' => 'custom_page',
            'page' => 'custom_page',
            "\u{81EA}\u{5B9A}\u{4E49}\u{9875}\u{9762}" => 'custom_page',
            'pagina' => 'custom_page',
            'página' => 'custom_page',
            'privacy policy' => 'privacy_policy',
            'privacy' => 'privacy_policy',
            '隐私政策' => 'privacy_policy',
            'política de privacidade' => 'privacy_policy',
            'politica de privacidade' => 'privacy_policy',
            'terms of service' => 'terms_of_service',
            'terms' => 'terms_of_service',
            '服务条款' => 'terms_of_service',
            'termos de serviço' => 'terms_of_service',
            'termos de servico' => 'terms_of_service',
            'refund policy' => 'refund_policy',
            '退款政策' => 'refund_policy',
            'política de reembolso' => 'refund_policy',
            'politica de reembolso' => 'refund_policy',
            'shipping policy' => 'shipping_policy',
            '配送政策' => 'shipping_policy',
            'política de envio' => 'shipping_policy',
            'politica de envio' => 'shipping_policy',
            'cookie policy' => 'cookie_policy',
            'cookie' => 'cookie_policy',
            'cookies' => 'cookie_policy',
            'cookie政策' => 'cookie_policy',
            'política de cookies' => 'cookie_policy',
            'politica de cookies' => 'cookie_policy',
            'policy info' => 'policy_info',
            'policies' => 'policy_info',
        ];
        if (isset($labelMap[$normalized])) {
            return $labelMap[$normalized];
        }

        if (\trim($href) === '') {
            return '';
        }

        $path = \strtolower(\trim((string)(\parse_url($href, PHP_URL_PATH) ?: $href)));
        $path = '/' . \trim($path, '/');
        return match ($path) {
            '/', '/home', '/index' => 'home',
            '/about', '/about-us' => 'about',
            '/contact', '/contact-us' => 'contact',
            '/blog', '/news' => 'blog',
            '/privacy', '/privacy-policy' => 'privacy_policy',
            '/terms', '/terms-of-service' => 'terms_of_service',
            '/refund', '/refund-policy' => 'refund_policy',
            '/shipping', '/shipping-policy' => 'shipping_policy',
            '/cookie', '/cookie-policy' => 'cookie_policy',
            default => '',
        };
    }

    private function localizeGenericPageTitle(string $title, string $locale): string
    {
        if ($this->localeFamily($locale) === 'en' && $this->hasAnyCjkContent($title)) {
            return '';
        }
        $canonical = $this->canonicalNavLabelKey($title);
        if ($canonical === '') {
            return '';
        }

        return $this->localizeBuildText($canonical, $locale);
    }

    private function isGenericFooterBoilerplate(string $value): bool
    {
        $normalized = \strtolower(\trim(\preg_replace('/\s+/u', ' ', $value) ?? $value));
        return \in_array($normalized, [
            'a curated destination with clear information, trusted support, and simple next steps.',
            'featured',
            'featured pages',
            'quick links',
            'policy info',
            'policies',
            'all pages',
            'all rights reserved.',
            'website',
            'site profile',
            'website profile',
            'websiteprofile',
        ], true);
    }

    private function looksLikeEnglishNavOrFooterLabel(string $value): bool
    {
        return \preg_match('/^(?:featured|featured pages|quick links|policy info|policies|all pages|home|about|contact|blog|privacy policy|terms of service|all rights reserved\.?|download now|download apk)$/iu', \trim($value)) === 1;
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
        $label = match ($pageType) {
            Page::TYPE_HOME => $this->localizeBuildText('home', $locale),
            Page::TYPE_ABOUT => $this->localizeBuildText('about', $locale),
            Page::TYPE_CONTACT => $this->localizeBuildText('contact', $locale),
            Page::TYPE_BLOG_LIST, Page::TYPE_BLOG => $this->localizeBuildText('blog', $locale),
            Page::TYPE_BLOG_CATEGORY => $this->localizeBuildText('blog_category', $locale),
            Page::TYPE_CUSTOM => $this->localizeBuildText('custom_page', $locale),
            Page::TYPE_PRIVACY_POLICY => $this->localizeBuildText('privacy_policy', $locale),
            Page::TYPE_TERMS_OF_SERVICE => $this->localizeBuildText('terms_of_service', $locale),
            Page::TYPE_REFUND_POLICY => $this->localizeBuildText('refund_policy', $locale),
            Page::TYPE_SHIPPING_POLICY => $this->localizeBuildText('shipping_policy', $locale),
            Page::TYPE_COOKIE_POLICY => $this->localizeBuildText('cookie_policy', $locale),
            default => '',
        };
        if ($label !== '') {
            return $label;
        }

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
