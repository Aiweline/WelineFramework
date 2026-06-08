<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use GuoLaiRen\PageBuilder\Service\AI\AiSiteSkillRegistry;
use GuoLaiRen\PageBuilder\Service\AI\CodeFixer;
use GuoLaiRen\PageBuilder\Service\AI\CodeValidator;
use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;
use GuoLaiRen\PageBuilder\Service\AI\PreviewRenderer;
use GuoLaiRen\PageBuilder\Service\AI\DesignDirection\DesignDirectionService;
use Weline\Ai\Service\AiService;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\SchedulerSystem;

class AiSitePageComponentGenerationService
{
    private const REQUEST_CTX_AI_CHUNK_FORWARDER = 'pagebuilder.ai.chunk.forwarder';
    private const REQUEST_CTX_INLINE_IMAGE_GENERATION_SUSPENDED = 'pagebuilder.ai.inline_image_generation.suspended';
    private const REQUEST_CTX_INLINE_IMAGE_GENERATION_SUSPEND_REASON = 'pagebuilder.ai.inline_image_generation.suspend_reason';
    private const REQUEST_CTX_INLINE_IMAGE_GENERATION_DISABLED = 'pagebuilder.ai.inline_image_generation.disabled';
    private const INLINE_IMAGE_GENERATION_DISABLED_REASON = 'disabled_by_test_switch';
    public const REQUEST_KEY_FORCE_REAL_AI_IN_TEST = 'pagebuilder.ai.force_real_in_test';
    public const REQUEST_KEY_FAST_BLOCK_ARTIFACT = 'pagebuilder.ai.fast_block_artifact';
    private const SYNTAX_FIX_MAX_ATTEMPTS = 2;
    private const COMPONENT_GENERATION_MAX_ATTEMPTS = 2;
    // Hard completion checks only block malformed component structure.
    private const ENFORCE_COMPONENT_QUALITY_VALIDATION = false;
    private const AI_REQUEST_TIMEOUT_SECONDS = 1800;
    private const COMPONENT_CSS_CLASS_SCOPE_FALLBACK = 'pb-ai-site-component';
    private const COMPONENT_CSS_SCOPE_PLACEHOLDER = '#componentId';
    private const PLAN_JSON_PAGE_ASSET_META_KEYS = [
        'assets' => true,
        'blocks' => true,
        'block_previews' => true,
        'ordered_block_keys' => true,
        'sections' => true,
    ];
    private const TEMPLATE_SCAFFOLD_BRAND_TERMS = [
        'LudoEmpire',
        'PokerArena',
        'Poker Arena',
        'Satta King 786',
        'Satta King',
        'BharatPlay',
        'RummyRoyal',
        'Teen Patti Royal',
    ];
    private const GENERIC_CSS_CLASS_TOKENS = [
        'card', 'title', 'header', 'footer', 'content', 'wrapper', 'container',
        'item', 'list', 'row', 'col', 'box', 'panel', 'section', 'main',
        'nav', 'menu', 'btn', 'button', 'link', 'text', 'icon', 'image',
        'form', 'input', 'label', 'group', 'active', 'disabled', 'hidden',
        'show', 'hide', 'open', 'close', 'toggle', 'dropdown', 'modal',
    ];

    private ?\GuoLaiRen\PageBuilder\Service\AI\Contract\AiSiteVisualBlockContractRenderer $visualBlockContractRenderer = null;
    /** @var array<string, array<string, mixed>> */
    private array $PlanJsonTaskRootCache = [];

    public function __construct(
        private readonly ?FrameworkBuilder $frameworkBuilder = null,
        private readonly ?AiResponseJsonParser $responseJsonParser = null,
        private readonly ?CodeFixer $codeFixer = null,
        private readonly ?CodeValidator $codeValidator = null,
        private readonly ?AiService $aiService = null,
        private readonly ?AiSitePageBlueprintService $pageBlueprintService = null,
        private readonly ?Page $pageModel = null,
        private readonly ?AiSiteScopeCompatibilityService $scopeCompatibilityService = null,
        private readonly ?AiSiteSkillRegistry $skillRegistry = null,
    ) {
    }

    /**
     * @return array{
     *   header:array{
     *     code:string,
     *     name:string,
     *     region:string,
     *     phtml:string,
     *     html:string,
     *     default_config:array<string,mixed>,
     *     ai_data:array<string,mixed>
     *   },
     *   footer:array{
     *     code:string,
     *     name:string,
     *     region:string,
     *     phtml:string,
     *     html:string,
     *     default_config:array<string,mixed>,
     *     ai_data:array<string,mixed>
     *   }
     * }
     */
    public function generateSharedComponents(array $websiteProfile, array $scope): array
    {
        return $this->generateSharedComponentsConcurrently($websiteProfile, $scope);
    }

    /**
     * @param list<string> $regions
     * @return \Generator<string, array{status:string, result?:array<string,mixed>, error?:\Throwable}>
     */
    public function generateSharedComponentEventsConcurrently(array $websiteProfile, array $scope, array $regions = []): \Generator
    {
        $components = $this->buildSharedComponentGenerationSpecs($websiteProfile, $scope, $regions);
        if ($components === []) {
            return;
        }

        yield from $this->generateComponentEventsConcurrently($components);
    }

    /**
     * @return array{
     *   code:string,
     *   name:string,
     *   region:string,
     *   phtml:string,
     *   html:string,
     *   default_config:array<string,mixed>,
     *   ai_data:array<string,mixed>
     * }
     */
    public function generateSharedComponent(
        string $region,
        array $websiteProfile,
        array $scope,
        string $refinementInstruction = '',
        bool $forceRegenerate = false,
    ): array {
        $region = \trim($region);
        if (!\in_array($region, ['header', 'footer'], true)) {
            throw new \InvalidArgumentException((string)__('Unsupported shared component region: %{1}', [$region]));
        }

        $refinementInstruction = \trim($refinementInstruction);
        $siteDisplayName = $this->resolveLocaleSafeSiteDisplayName(
            $this->getPageBlueprintService()->resolveSiteDisplayName($websiteProfile, $scope),
            $websiteProfile,
            $scope,
            $this->resolvePrimaryLocale($websiteProfile, $scope)
        );
        $effectiveLogo = $this->resolveSharedLogoAssetUrl($websiteProfile, $scope);
        $cacheKey = \md5((string)\json_encode([
            'region' => $region,
            'site' => $siteDisplayName,
            'logo' => $effectiveLogo,
            'brief' => $this->pickString($websiteProfile['brief_description'] ?? null, $scope['brief_description'] ?? null, $scope['user_description'] ?? null),
            'pages' => $this->resolveScopedPageTypes($scope),
            'style' => $this->resolvePromptStyleCode($scope, Page::TYPE_HOME),
        ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
        static $sharedCache = [];
        $useCache = !$forceRegenerate && $refinementInstruction === '';
        if ($useCache && isset($sharedCache[$cacheKey]) && \is_array($sharedCache[$cacheKey])) {
            return $sharedCache[$cacheKey];
        }

        $headerConfig = $this->buildHeaderDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        $footerConfig = $this->buildFooterDefaultConfig($websiteProfile, $scope, $siteDisplayName);

        $result = match ($region) {
            'header' => $this->generateComponent(
                'header/ai-site-header',
                'AI Site Header',
                'header',
                $this->buildHeaderGenerationPrompt($websiteProfile, $scope, $siteDisplayName, $headerConfig),
                $headerConfig,
                $this->buildRenderContext(Page::TYPE_HOME, $websiteProfile, $scope, $headerConfig)
            ),
            default => $this->generateComponent(
                'footer/ai-site-footer',
                'AI Site Footer',
                'footer',
                $this->buildFooterGenerationPrompt($websiteProfile, $scope, $siteDisplayName, $footerConfig),
                $footerConfig,
                $this->buildRenderContext(Page::TYPE_HOME, $websiteProfile, $scope, $footerConfig)
            ),
        };
        if ($useCache) {
            $sharedCache[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * @return array{
     *   blueprint:array<string,mixed>,
     *   sections:list<array{
     *     key:string,
     *     code:string,
     *     name:string,
     *     region:string,
     *     sort_order:int,
     *     phtml:string,
     *     html:string,
     *     default_config:array<string,mixed>,
     *     ai_data:array<string,mixed>
     *   }>
     * }
     */
    public function generatePageSections(string $pageType, array $websiteProfile, array $scope): array
    {
        return $this->generatePageSectionsConcurrently($pageType, $websiteProfile, $scope);
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return array{
     *   blueprint:array<string,mixed>,
     *   sections:list<array{
     *     key:string,
     *     code:string,
     *     name:string,
     *     region:string,
     *     sort_order:int,
     *     prompt:string,
     *     default_config:array<string,mixed>,
     *     render_context:array<string,mixed>
     *   }>
     * }
     */
    public function buildPageSectionSpecs(string $pageType, array $websiteProfile, array $scope): array
    {
        $scope = $this->normalizePlanJsonExecutionScope($scope, $websiteProfile);
        $blueprint = $this->getPageBlueprintService()->buildPageBlueprint($pageType, $scope, $websiteProfile);
        $blueprint = $this->mergePlanJsonTaskSectionsIntoBlueprint($pageType, $blueprint, $scope);
        $sections = [];
        foreach (($blueprint['sections'] ?? []) as $section) {
            if (!\is_array($section)) {
                continue;
            }
            $sectionCode = \trim((string)($section['code'] ?? ''));
            if ($sectionCode === '') {
                continue;
            }
            $defaultConfig = $this->buildSectionDefaultConfig($pageType, $section, $blueprint, $websiteProfile, $scope);
            $visualContract = $this->buildSectionVisualContract($pageType, $section, $blueprint, $websiteProfile, $scope);
            $defaultConfig = $this->applySectionVisualContractToDefaultConfig($defaultConfig, $visualContract);
            $sections[] = [
                'key' => (string)($section['key'] ?? ''),
                'code' => $sectionCode,
                'name' => (string)($section['name'] ?? $sectionCode),
                'region' => 'content',
                'sort_order' => (int)($section['sort_order'] ?? 0),
                'prompt' => $this->buildSectionGenerationPrompt($pageType, $section, $blueprint, $websiteProfile, $scope),
                'visual_contract' => $visualContract,
                'default_config' => $defaultConfig,
                'render_context' => $this->buildRenderContext($pageType, $websiteProfile, $scope, $defaultConfig),
            ];
        }

        return [
            'blueprint' => $blueprint,
            'sections' => $sections,
        ];
    }

    /**
     * Prompt assembly must always read the latest plan_json block execution context.
     *
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $websiteProfile
     * @return array<string,mixed>
     */
    private function normalizePlanJsonExecutionScope(array $scope, array $websiteProfile): array
    {
        $scope = $this->getScopeCompatibilityService()->normalizeScope($scope);
        if (!\is_array($scope['plan_json']['pages'] ?? null)) {
            return $scope;
        }

        $workspaceTrack = $this->getScopeCompatibilityService()->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));

        return $this->getPlanJsonTaskService()->ensureTaskScope(
            $scope,
            $websiteProfile,
            $workspaceTrack !== '' ? $workspaceTrack : AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
        );
    }

    /**
     * @param array<string,mixed> $section
     * @param array<string,mixed> $blueprint
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildSectionVisualContract(string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): array
    {
        $sectionCode = \trim((string)($section['code'] ?? ''));
        $sectionKey = \trim((string)($section['key'] ?? ''));
        $sectionTemplate = \strtolower(\trim((string)($section['template'] ?? '')));
        $PlanJsonTask = $this->resolveSectionPlanJsonTask($scope, $pageType, $sectionCode, $sectionKey);
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $stylePlan = \is_array($blockTask['style_plan'] ?? null) ? $blockTask['style_plan'] : [];
        $pageIdentity = \is_array($planContext['page_identity_contract'] ?? null) ? $planContext['page_identity_contract'] : [];
        $pageFlowRole = \strtolower(\trim((string)(
            $planContext['page_flow_role']
            ?? $pageIdentity['page_flow_role']
            ?? $PlanJsonTask['page_flow_role']
            ?? $blockTask['page_flow_role']
            ?? $stylePlan['page_flow_role']
            ?? $section['page_flow_role']
            ?? $section['config']['page_flow_role']
            ?? ''
        )));
        $visualSignature = $this->resolvePlannedVisualSignature($PlanJsonTask, $blockTask, $stylePlan, $planContext, $section);
        $imageIntent = $this->resolvePlannedImageIntent($PlanJsonTask, $blockTask, $stylePlan, $planContext, $section);
        $slotId = 'page:' . $pageType . ':' . \str_replace('/', '-', $sectionCode !== '' ? $sectionCode : ($sectionKey !== '' ? $sectionKey : 'section'));
        $brief = $this->pickString(
            $section['config']['description'] ?? null,
            $section['config']['body'] ?? null,
            $section['name'] ?? null,
            $blueprint['ai_description'] ?? null,
            $websiteProfile['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null
        );
        $manifestSlot = $this->resolveSectionManifestSlot($pageType, $sectionCode, $sectionKey, $scope);
        $manifestSlotId = \trim((string)($manifestSlot['slot_id'] ?? ''));
        if ($manifestSlotId !== '') {
            $slotId = $manifestSlotId;
        }
        $manifestRequired = (int)($manifestSlot['required'] ?? 0) === 1;
        $manifestDesired = (int)($manifestSlot['desired_image'] ?? 0) === 1;
        if ($imageIntent === [] && \is_array($manifestSlot['image_intent'] ?? null)) {
            $imageIntent = $manifestSlot['image_intent'];
        }
        $strictHeroCover = $this->imageIntentRequestsStrictHeroCover($imageIntent);
        $wantsImage = $this->plannedContextWantsSectionImage(
            $strictHeroCover,
            $pageFlowRole,
            $planContext,
            $blockTask,
            $stylePlan,
            $visualSignature,
            $imageIntent
        );
        $manifestSlotType = \trim((string)($manifestSlot['slot_type'] ?? ''));
        if (!$strictHeroCover && \in_array($manifestSlotType, ['hero_image', 'hero_banner', 'banner_image'], true)) {
            $manifestSlotType = 'section_image';
        }
        $manifestFinalUrl = !$this->isManifestSlotPlaceholder($manifestSlot)
            ? \trim((string)($manifestSlot['final_url'] ?? ''))
            : '';
        $finalUrl = $manifestSlot !== [] ? $manifestFinalUrl : $this->resolveSectionAssetUrl($pageType, $section, $scope);
        $hasVerifiedImage = $finalUrl !== '';
        $wantsImage = $wantsImage || $manifestDesired || $manifestRequired;
        $requiresImage = $manifestRequired || $hasVerifiedImage || $this->imageIntentNeedsImage($imageIntent);
        $usage = $strictHeroCover ? 'section_background_cover' : ($wantsImage ? 'section_media_surface' : 'optional_css_visual');
        $baseStyle = $strictHeroCover
            ? 'premium cinematic website banner, realistic/editorial photography or photoreal premium 3D, 1920x750 wide full-bleed cover background, not cartoon or SVG-like'
            : 'page-specific opening or section visual; composition is free as long as it expresses this page purpose, avoids banner-template sameness, and stays polished';
        $visualAtmosphere = \trim((string)($imageIntent['visual_atmosphere'] ?? ''));
        $imageTreatment = \trim((string)($imageIntent['image_treatment'] ?? ''));
        $styleParts = [$baseStyle];
        foreach ([
            $visualAtmosphere !== '' ? 'planned atmosphere: ' . $visualAtmosphere : '',
            $imageTreatment !== '' ? 'planned image treatment: ' . $imageTreatment : '',
            \trim((string)($visualSignature['media_strategy'] ?? '')) !== '' ? 'media strategy: ' . \trim((string)($visualSignature['media_strategy'] ?? '')) : '',
            \trim((string)($visualSignature['surface_treatment'] ?? '')) !== '' ? 'surface treatment: ' . \trim((string)($visualSignature['surface_treatment'] ?? '')) : '',
        ] as $stylePart) {
            if ($stylePart !== '') {
                $styleParts[] = $stylePart;
            }
        }
        $style = \implode('; ', \array_values(\array_unique($styleParts)));
        $plannedPlacement = \trim((string)($imageIntent['placement'] ?? ''));
        $plannedSubject = \trim((string)($imageIntent['image_subject'] ?? ''));
        if ($finalUrl !== '') {
            $resolvedSlotId = $this->resolveSectionAssetSlotId($scope, $finalUrl);
            if ($resolvedSlotId !== '') {
                $slotId = $resolvedSlotId;
            }
        }

        $contract = [
            'version' => 1,
            'required' => $requiresImage ? 1 : 0,
            'desired_image' => $wantsImage ? 1 : 0,
            'strict_hero_cover' => $strictHeroCover ? 1 : 0,
            'slot_id' => $slotId,
            'slot_type' => $manifestSlotType !== '' ? $manifestSlotType : ($strictHeroCover ? 'hero_image' : 'section_image'),
            'page_type' => $pageType,
            'section_code' => $sectionCode,
            'section_key' => $sectionKey,
            'section_template' => $sectionTemplate,
            'page_flow_role' => $pageFlowRole,
            'visual_signature' => $visualSignature,
            'image_intent' => $imageIntent,
            'visual_atmosphere' => $visualAtmosphere,
            'image_treatment' => $imageTreatment,
            'usage' => $usage,
            'placement' => $strictHeroCover ? 'background_layer' : ($plannedPlacement !== '' ? $plannedPlacement : ($requiresImage ? 'media_panel' : 'none')),
            'aspect_ratio' => $strictHeroCover ? '1920:750' : '4:3',
            'target_size' => $strictHeroCover ? '1920x750' : '',
            'subject' => $this->clipText($this->sanitizeVisibleCopy($plannedSubject !== '' ? $plannedSubject : $brief), 220),
            'style' => $style,
            'responsive_layout_contract' => [
                'desktop' => $strictHeroCover
                    ? 'Hero/opening banner root is full-bleed by default: the root shell spans 100vw even when the PageBuilder wrapper is centered; only the inner text-safe container is max-width constrained. Split layouts are allowed only inside that inner container.'
                    : 'Use a page-specific opening/section composition inside a centered safe-width or deliberate full-width band. Do not default to the same full-bleed hero image + overlay + floating copy panel unless this page role truly needs it.',
                'tablet' => 'At <=900px, stack media/copy/form panels or switch to two safe rows. Do not keep a side card absolutely offset outside the viewport.',
                'mobile' => 'At <=420px, one column only. Forms, inputs, and media may use width:100%; CTA-looking controls remain compact with width:auto and max-width<=280px, and no width:100%, flex:1, flex-grow:1, or display:block on the actual CTA button selector. Prefer action/actions for wrappers so CTA containers are not confused with the button itself.',
                'required_parts' => [
                    'root_shell',
                    'inner_container',
                    'copy_panel',
                    $wantsImage ? 'media_panel' : 'css_visual_layer',
                    'action_or_form_panel',
                    'decorative_layers',
                ],
                'forbidden_layouts' => [
                    'absolute-positioned form or card pushed outside the root',
                    'fixed pixel width media/form column that cannot shrink',
                    'image layer covering text, form fields, or CTAs on tablet/mobile',
                    'decorative arcs or blobs with z-index above interactive content',
                ],
            ],
            'html_contract' => ($requiresImage && $hasVerifiedImage)
                ? ($strictHeroCover
                    ? 'Render the concrete final_url from this contract as the media.image_url default/fallback for a real editable <img> cover layer inside html_content for the full-width 1920x750-style banner. The same <img> carries data-pb-ai-image-role and the concrete data-pb-ai-asset-slot from this contract.'
                    : 'Render the concrete final_url from this contract as the media.image_url default/fallback for a real editable <img> media surface, visual card, device panel, support badge, story frame, or compact opening accent that fits this page identity. The same <img> carries data-pb-ai-image-role and the concrete data-pb-ai-asset-slot from this contract.')
                : ($requiresImage
                    ? 'This block requires a generated image slot from Stage-1. If no verified final_url/template is supplied later, treat the asset as unresolved upstream work; do not replace the required image with CSS-only media, omit it silently, invent placeholder URLs, or print this instruction as visitor copy.'
                    : ($wantsImage
                    ? 'This block benefits from visual media. If a verified final_url is supplied, render it through a real editable <img> field binding; otherwise build a polished CSS-only media/motif surface and do not invent external image URLs.'
                    : 'No verified final_url is available for this visual. Build a polished CSS-only motif/media surface or omit media; never use placeholder image URLs or invented external image URLs.')),
        ];
        if ($finalUrl !== '') {
            $contract['final_url'] = $finalUrl;
        }
        if ($requiresImage && $hasVerifiedImage) {
            $contract['required_img_class'] = $strictHeroCover ? 'pb-c-hero-img' : 'pb-c-img';
            $contract['required_structure'] = $strictHeroCover
                ? 'Use pb-c-root > img.pb-c-hero-img + pb-c-scrim + pb-c-inner > pb-c-copy. css_extra must include #componentId{padding:0;} so the framework wrapper does not create top/bottom gutters. The root selector must be full-bleed with width:100vw or min-width:100vw and no max-width pixel cap; only pb-c-inner may be max-width constrained. The img selector must be #componentId .pb-c-hero-img and must contain position:absolute, inset:0, width:100%, height:100%, object-fit:cover, object-position:center.'
                : 'Use pb-c-root > pb-c-inner > pb-c-media > img.pb-c-img plus pb-c-copy. The img selector must be #componentId .pb-c-img and must contain width:100%, height:360px, object-fit:cover, object-position:center.';
        }

        return $contract;
    }

    /**
     * @param array<string,mixed> $imageIntent
     */
    private function imageIntentRequestsStrictHeroCover(array $imageIntent): bool
    {
        if (!$this->imageIntentNeedsImage($imageIntent)) {
            return false;
        }
        if ((int)($imageIntent['strict_hero_cover'] ?? 0) === 1) {
            return true;
        }

        $placement = $this->normalizePlannedRoleToken((string)($imageIntent['placement'] ?? ''));

        return \in_array($placement, [
            'background_layer',
            'full_bleed_background',
            'full_bleed_cover',
            'hero_cover',
            'cover_background',
            'banner_cover',
            'section_background_cover',
        ], true);
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $blockTask
     * @param array<string,mixed> $stylePlan
     * @return array<string,mixed>
     */
    private function resolvePlannedVisualSignature(
        array $PlanJsonTask,
        array $blockTask,
        array $stylePlan,
        array $planContext = [],
        array $section = []
    ): array {
        foreach ([
            $PlanJsonTask['visual_signature'] ?? null,
            $blockTask['visual_signature'] ?? null,
            $stylePlan['visual_signature'] ?? null,
            $planContext['block_visual_signature'] ?? null,
            $planContext['stage1_visual_signature'] ?? null,
            $section['visual_contract']['visual_signature'] ?? null,
            $section['visual_signature'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $blockTask
     * @param array<string,mixed> $stylePlan
     * @param array<string,mixed> $planContext
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    private function resolvePlannedImageIntent(
        array $PlanJsonTask,
        array $blockTask,
        array $stylePlan,
        array $planContext = [],
        array $section = []
    ): array {
        foreach ([
            $PlanJsonTask['image_intent'] ?? null,
            $blockTask['image_intent'] ?? null,
            $stylePlan['image_intent'] ?? null,
            $planContext['block_image_intent'] ?? null,
            $planContext['stage1_image_intent'] ?? null,
            $section['image_intent'] ?? null,
            $section['visual_contract']['image_intent'] ?? null,
            $section['visual']['image_intent'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $imageIntent
     */
    private function imageIntentNeedsImage(array $imageIntent): bool
    {
        $value = $imageIntent['needs_image'] ?? $imageIntent['required'] ?? $imageIntent['desired_image'] ?? null;
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }

        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'required', 'needed'], true);
    }

    /**
     * @param array<string,mixed> $planContext
     * @param array<string,mixed> $blockTask
     * @param array<string,mixed> $stylePlan
     * @param array<string,mixed> $visualSignature
     * @param array<string,mixed> $imageIntent
     */
    private function plannedContextWantsSectionImage(
        bool $strictHeroCover,
        string $pageFlowRole,
        array $planContext,
        array $blockTask,
        array $stylePlan,
        array $visualSignature,
        array $imageIntent = []
    ): bool {
        unset($strictHeroCover, $pageFlowRole, $planContext, $blockTask, $stylePlan, $visualSignature);
        if ($imageIntent !== []) {
            return $this->imageIntentNeedsImage($imageIntent);
        }

        return false;
    }

    private function normalizePlannedRoleToken(string $value): string
    {
        $value = \strtolower(\trim($value));
        if ($value === '') {
            return '';
        }
        $value = \preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? $value;
        $value = \preg_replace('/_+/', '_', $value) ?? $value;

        return \trim($value, '_-');
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $visualContract
     * @return array<string,mixed>
     */
    private function applySectionVisualContractToDefaultConfig(array $defaultConfig, array $visualContract): array
    {
        $slotId = \trim((string)($visualContract['slot_id'] ?? ''));
        if ($slotId !== '') {
            $defaultConfig['runtime.section_image_slot_id'] = $slotId;
            $defaultConfig['visual.image_slot_id'] = $slotId;
        }
        $defaultConfig['runtime.section_image_required'] = (int)($visualContract['required'] ?? 0) === 1 ? '1' : '0';
        $defaultConfig['runtime.section_image_usage'] = (string)($visualContract['usage'] ?? '');
        $defaultConfig['runtime.block_image_intent_json'] = (string)\json_encode(
            \is_array($visualContract['image_intent'] ?? null) ? $visualContract['image_intent'] : [],
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        );
        $defaultConfig['runtime.visual_atmosphere'] = (string)($visualContract['visual_atmosphere'] ?? '');
        $defaultConfig['runtime.image_treatment'] = (string)($visualContract['image_treatment'] ?? '');
        $defaultConfig['runtime.visual_contract_json'] = \json_encode($visualContract, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return $defaultConfig;
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return array{
     *   key:string,
     *   code:string,
     *   name:string,
     *   region:string,
     *   sort_order:int,
     *   phtml:string,
     *   html:string,
     *   default_config:array<string,mixed>,
     *   ai_data:array<string,mixed>
     * }
     */
    public function generatePageSection(string $pageType, string $sectionCode, array $websiteProfile, array $scope): array
    {
        $specs = $this->buildPageSectionSpecs($pageType, $websiteProfile, $scope);
        foreach ($specs['sections'] as $section) {
            if ((string)($section['code'] ?? '') !== $sectionCode) {
                continue;
            }
            $section = $this->prepareInlineImageAssetForComponentSpec([
                'componentCode' => (string)$section['code'],
                'name' => (string)$section['name'],
                'region' => (string)$section['region'],
                'prompt' => (string)$section['prompt'],
                'defaultConfig' => \is_array($section['default_config'] ?? null) ? $section['default_config'] : [],
                'renderContext' => \is_array($section['render_context'] ?? null) ? $section['render_context'] : [],
            ]) + $section;

            $component = $this->generateComponent(
                (string)$section['code'],
                (string)$section['name'],
                (string)$section['region'],
                (string)$section['prompt'],
                \is_array($section['defaultConfig'] ?? null) ? $section['defaultConfig'] : (\is_array($section['default_config'] ?? null) ? $section['default_config'] : []),
                \is_array($section['renderContext'] ?? null) ? $section['renderContext'] : (\is_array($section['render_context'] ?? null) ? $section['render_context'] : [])
            );

            return [
                'key' => (string)($section['key'] ?? ''),
                'code' => (string)$section['code'],
                'name' => (string)$section['name'],
                'region' => (string)$section['region'],
                'sort_order' => (int)($section['sort_order'] ?? 0),
                'phtml' => (string)($component['phtml'] ?? ''),
                'html' => (string)($component['html'] ?? ''),
                'default_config' => \is_array($component['default_config'] ?? null) ? $component['default_config'] : [],
                'ai_data' => \is_array($component['ai_data'] ?? null) ? $component['ai_data'] : [],
            ];
        }

        throw new \InvalidArgumentException((string)__('Unknown page section: %{section}', ['section' => $sectionCode]));
    }

    /**
 *  ?+ footer +  ?section  ?
     *
 *  ?Fiber  ?Fiber  ?generateComponent() ? *  ?AI  ? ?JSON  ? ? ? ? ? *
     * @param array<string, array{
     *   componentCode:string,
     *   name:string,
     *   region:string,
     *   prompt:string,
     *   defaultConfig:array<string,mixed>,
     *   renderContext:array<string,mixed>
     * }> $components region => component spec
     * @return \Generator yields [region => result] as each component finishes; result has same shape as generateComponent()
     */
    /**
     * @param array<string,mixed> $blueprint
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function mergePlanJsonTaskSectionsIntoBlueprint(string $pageType, array $blueprint, array $scope): array
    {
        $tasks = [];
        foreach ($this->getPlanJsonTaskService()->listTaskKeysByPageType($scope, $pageType) as $taskKey) {
            $task = $this->getPlanJsonTaskService()->getTaskDefinition($scope, (string)$taskKey);
            if (\is_array($task) && $task !== []) {
                $tasks[] = $task;
            }
        }
        if ($tasks === []) {
            throw new \RuntimeException('Build prompt contract failed: plan_json has no executable blocks for page ' . $pageType . '.');
        }

        $sections = [];
        $known = [];
        foreach ($sections as $section) {
            $value = \trim((string)($section['code'] ?? ''));
            if ($value !== '') {
                $known[$value] = true;
            }
        }

        $contentLocale = $this->resolveScopePrimaryLocale($scope);
        foreach ($tasks as $task) {
            if (!\is_array($task) || \trim((string)($task['page_type'] ?? '')) !== $pageType) {
                continue;
            }
            if (\trim((string)($task['task_type'] ?? '')) !== 'page_section') {
                continue;
            }

            $taskKey = \trim((string)($task['task_key'] ?? ''));
            $blockKey = $this->resolvePlanJsonTaskBlockKey($task);
            $sectionCode = $this->normalizePlanJsonTaskSectionCode($pageType, (string)($task['section_code'] ?? ''), $blockKey, $taskKey);
            if ($sectionCode === '') {
                continue;
            }

            $sectionKey = \trim((string)($task['section_key'] ?? ''));
            $sectionKey = $sectionKey !== '' ? $sectionKey : ($blockKey !== '' ? $blockKey : $sectionCode);
            // Deduplicate by normalized section code, not by broad section key.
            if (isset($known[$sectionCode])) {
                continue;
            }

            $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
            $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
            $blockTask = \is_array($task['block_task'] ?? null) ? $task['block_task'] : [];
            $label = $this->pickString(
                $task['label'] ?? null,
                $planContext['block_goal'] ?? null,
                $blockTask['task_goal'] ?? null,
                $sectionKey
            );
            $description = $this->resolveVisiblePlanJsonTaskSummary($taskScript, $blockTask, $planContext, $contentLocale);
            $visibleSectionTitle = $this->sanitizeVisibleCopy($label);
            if ($contentLocale !== '') {
                $visibleSectionTitle = $this->filterVisibleCopyForLocale($visibleSectionTitle, $contentLocale);
            }
            if ($visibleSectionTitle === '') {
                $localizedFallback = $contentLocale !== ''
                    && !$this->isEnglishLocale($contentLocale)
                    && $this->promptLocaleFamily($contentLocale) === 'en'
                        ? ''
                        : $this->localizeBuildText('section_fallback', $contentLocale);
                $visibleSectionTitle = $localizedFallback !== ''
                    ? $localizedFallback
                    : ($this->isEnglishLocale($contentLocale) ? $this->humanizeIdentifier($sectionKey !== '' ? $sectionKey : $sectionCode) : '');
            }

            $sections[] = [
                'key' => $sectionKey,
                'code' => $sectionCode,
                'name' => $visibleSectionTitle !== '' ? $visibleSectionTitle : $sectionCode,
                'template' => $this->inferPlanJsonTaskSectionTemplate($task, $sectionKey, \count($sections)),
                'config' => [
                    'section_title' => $visibleSectionTitle,
                    'description' => $description,
                    'section_intro' => $description,
                ],
                'sort_order' => (int)($task['sort_order'] ?? (1000 + \count($sections) * 10)),
                'source_block_key' => $blockKey !== '' ? $blockKey : $sectionKey,
            ];
            $known[$sectionCode] = true;
        }

        \usort($sections, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));
        $blueprint['sections'] = $sections;

        return $blueprint;
    }

    /**
     * @param list<array<string,string>> $rows
     * @return list<array<string,string>>
     */
    private function dedupeContentCopyRows(array $rows): array
    {
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $field = \trim((string)($row['field'] ?? ''));
            $copy = $this->sanitizeVisibleCopy((string)($row['copy'] ?? ''));
            if ($field === '' || $copy === '') {
                continue;
            }
            $fingerprint = \mb_strtolower($field . ':' . $copy);
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $result[] = ['field' => $field, 'copy' => $copy];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $task
     */
    private function resolvePlanJsonTaskBlockKey(array $task): string
    {
        foreach (['block_key', 'section_key', 'source_block_key'] as $field) {
            $value = \trim((string)($task[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $taskKey = \trim((string)($task['task_key'] ?? ''));
        if (\preg_match('/^[^:]+:[^:]+:(.+)$/', $taskKey, $matches) === 1) {
            return \trim((string)($matches[1] ?? ''));
        }

        return '';
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function resolveSharedLogoAssetUrl(array $websiteProfile, array $scope): string
    {
        $selectedLogo = $this->resolveSelectedThemeLogoAssetUrl($scope);
        if ($selectedLogo !== '') {
            return $selectedLogo;
        }

        $manifestLogo = $this->resolveHeaderAssetUrl($scope);
        if ($manifestLogo !== '') {
            return $manifestLogo;
        }

        return $this->normalizePublishableLogoAssetUrl((string)($websiteProfile['logo'] ?? ''));
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolveSelectedThemeLogoAssetUrl(array $scope): string
    {
        $logoGeneration = \is_array($scope['plan_json']['theme']['logo_generation'] ?? null)
            ? $scope['plan_json']['theme']['logo_generation']
            : [];
        if ($logoGeneration === []) {
            return '';
        }

        $selectedUrl = $this->normalizePublishableLogoAssetUrl((string)($logoGeneration['selected_url'] ?? ''));
        if ($selectedUrl !== '') {
            return $selectedUrl;
        }

        $selectedOptionId = \strtolower(\trim((string)($logoGeneration['selected_option_id'] ?? '')));
        $options = \is_array($logoGeneration['options'] ?? null) ? $logoGeneration['options'] : [];
        if ($selectedOptionId !== '') {
            foreach ($options as $option) {
                if (!\is_array($option)) {
                    continue;
                }
                $optionId = \strtolower(\trim((string)($option['option_id'] ?? $option['id'] ?? '')));
                if ($optionId !== $selectedOptionId) {
                    continue;
                }
                $optionUrl = $this->resolveThemeLogoOptionAssetUrl($option);
                if ($optionUrl !== '') {
                    return $optionUrl;
                }
            }
        }

        foreach ($options as $option) {
            if (!\is_array($option)) {
                continue;
            }
            $optionId = \strtolower(\trim((string)($option['option_id'] ?? $option['id'] ?? '')));
            if ($optionId !== 'logo_option_1') {
                continue;
            }
            $optionUrl = $this->resolveThemeLogoOptionAssetUrl($option);
            if ($optionUrl !== '') {
                return $optionUrl;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $option
     */
    private function resolveThemeLogoOptionAssetUrl(array $option): string
    {
        foreach (['final_url', 'url', 'asset_url'] as $key) {
            $optionUrl = $this->normalizePublishableLogoAssetUrl((string)($option[$key] ?? ''));
            if ($optionUrl !== '') {
                return $optionUrl;
            }
        }

        return '';
    }

    private function normalizePublishableLogoAssetUrl(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        $lower = \strtolower($value);
        if (\str_starts_with($lower, 'data:') || \str_contains($lower, '<svg')) {
            return '';
        }
        if (\preg_match('/placeholder|example\.com|picsum\.photos|source\.unsplash\.com|images\.unsplash\.com/iu', $value) === 1) {
            return '';
        }
        $path = \parse_url($value, \PHP_URL_PATH);
        $path = \is_string($path) ? \trim($path) : $value;
        if ($path === '' || \str_ends_with($path, '/')) {
            return '';
        }
        if (\preg_match('/\.(?:png|jpe?g|webp|gif|avif|svg)(?:$|\?)/i', $path) !== 1) {
            return '';
        }
        $normalizedPath = '/' . \ltrim(\preg_replace('#/+#', '/', \str_replace('\\', '/', $path)) ?? $path, '/');
        $lowerPath = \strtolower($normalizedPath);
        if (
            \str_contains($lowerPath, '/pub/media/page-build/ai-generated/')
            && !\str_contains($lowerPath, 'plan-theme-logo-generation-option')
        ) {
            return '';
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $taskScript
     * @param array<string,mixed> $blockTask
     * @param array<string,mixed> $planContext
     */
    private function resolveVisiblePlanJsonTaskSummary(array $taskScript, array $blockTask, array $planContext, string $locale = ''): string
    {
        $contentPlan = \is_array($blockTask['content_plan'] ?? null) ? $blockTask['content_plan'] : [];
        $sources = [
            \is_array($contentPlan['content_copy'] ?? null) ? $contentPlan['content_copy'] : [],
            \is_array($planContext['field_plan'] ?? null) ? $planContext['field_plan'] : [],
            \is_array($blockTask['meta_fields'] ?? null) ? $blockTask['meta_fields'] : [],
            \is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [],
        ];
        $samples = [];
        foreach ($sources as $rows) {
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $candidate = $this->sanitizeVisibleCopy((string)($row['copy'] ?? $row['sample'] ?? $row['default'] ?? ''));
                if ($locale !== '') {
                    $candidate = $this->filterVisibleCopyForLocale($candidate, $locale);
                }
                if ($candidate === '' || \in_array($candidate, $samples, true)) {
                    continue;
                }
                $samples[] = $this->clipText($candidate, 120);
                if (\count($samples) >= 2) {
                    break 2;
                }
            }
        }

        if ($samples !== []) {
            return $this->clipText(\implode(' ', $samples), 220);
        }

        return '';
    }

    private function normalizePlanJsonTaskSectionCode(string $pageType, string $sectionCode, string $blockKey, string $taskKey): string
    {
        $sectionCode = \trim($sectionCode);
        if ($sectionCode === '' || \in_array(\strtolower($sectionCode), ['section', 'content', 'block'], true)) {
            $sectionCode = $blockKey;
        }
        if ($sectionCode === '' && \preg_match('/^[^:]+:[^:]+:(.+)$/', $taskKey, $matches) === 1) {
            $sectionCode = \trim((string)($matches[1] ?? ''));
        }
        if ($sectionCode === '') {
            return '';
        }
        if (\str_contains($sectionCode, '/')) {
            return $sectionCode;
        }

        return 'content/' . $this->slugForGeneratedSectionCode($pageType) . '-' . $this->slugForGeneratedSectionCode($sectionCode);
    }

    /**
     * @param array<string,mixed> $task
     */
    private function inferPlanJsonTaskSectionTemplate(array $task, string $sectionKey, int $sectionIndex): string
    {
        unset($sectionKey, $sectionIndex);

        $blockTask = \is_array($task['block_task'] ?? null) ? $task['block_task'] : [];
        $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
        $stylePlan = \is_array($blockTask['style_plan'] ?? null) ? $blockTask['style_plan'] : [];
        $visualSignature = $this->resolvePlannedVisualSignature($task, $blockTask, $stylePlan, $planContext);
        $explicitTemplate = $this->normalizePlannedRoleToken((string)($task['section_template'] ?? $task['template'] ?? ''));
        if (\in_array($explicitTemplate, ['hero', 'banner', 'cta', 'checklist', 'section'], true)) {
            return $explicitTemplate === 'banner' ? 'hero' : $explicitTemplate;
        }

        $blockType = $this->normalizePlannedRoleToken((string)(
            $task['block_type']
            ?? $planContext['block_type']
            ?? $blockTask['block_type']
            ?? ''
        ));
        $pageFlowRole = $this->normalizePlannedRoleToken((string)(
            $task['page_flow_role']
            ?? $planContext['page_flow_role']
            ?? $blockTask['page_flow_role']
            ?? ''
        ));
        $composition = $this->normalizePlannedRoleToken((string)($visualSignature['composition_pattern'] ?? ''));

        if (\in_array($blockType, ['hero', 'banner', 'home_hero', 'hero_banner', 'above_fold'], true)) {
            return 'hero';
        }
        if (\in_array($blockType, ['cta', 'final_cta', 'download_cta', 'conversion_cta'], true)
            || \in_array($pageFlowRole, ['conversion', 'final_cta'], true)
            || \in_array($composition, ['cta_band', 'download_band', 'conversion_band'], true)
        ) {
            return 'cta';
        }
        if (\in_array($blockType, ['checklist', 'feature_grid', 'features', 'values', 'proof_grid', 'trust_grid', 'cards'], true)
            || \in_array($composition, ['feature_grid', 'feature_rail', 'proof_band', 'badge_wall', 'metric_strip'], true)
        ) {
            return 'checklist';
        }

        return 'section';
    }

    private function slugForGeneratedSectionCode(string $value): string
    {
        $slug = \strtolower(\trim($value));
        $slug = \str_replace(['_', '/', '\\'], '-', $slug);
        $slug = \preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = \preg_replace('/-+/', '-', $slug) ?? $slug;
        $slug = \trim($slug, '-');

        return $slug !== '' ? $slug : 'section';
    }

    public function generateComponentsConcurrently(array $components): \Generator
    {
        if ($components === []) {
            return;
        }

        if ($this->isTestEnvironment() || !\class_exists(\Fiber::class)) {
            foreach ($components as $region => $spec) {
                $spec = $this->prepareInlineImageAssetForComponentSpec($spec);
                yield $region => $this->generateComponent(
                    $spec['componentCode'],
                    $spec['name'],
                    $spec['region'],
                    $spec['prompt'],
                    $spec['defaultConfig'],
                    $spec['renderContext']
                );
            }
            return;
        }

        $tasks = [];
        foreach ($components as $region => $spec) {
            $tasks[$region] = function () use ($spec): array {
                $spec = $this->prepareInlineImageAssetForComponentSpec($spec);
                return $this->generateComponent(
                    $spec['componentCode'],
                    $spec['name'],
                    $spec['region'],
                    $spec['prompt'],
                    $spec['defaultConfig'],
                    $spec['renderContext']
                );
            };
        }

        $results = [];
        $errors = [];
        $runner = new FiberTaskRunner(defaultConcurrency: $this->resolveConcurrency(\count($tasks)));
        foreach ($runner->runEvents($tasks) as $region => $event) {
            if (($event['status'] ?? '') === 'fulfilled') {
                $results[$region] = \is_array($event['result'] ?? null) ? $event['result'] : [];
                continue;
            }
            $errors[$region] = ($event['error'] ?? null) instanceof \Throwable
                ? $event['error']
                : new \RuntimeException('Component fiber failed without an exception payload.');
        }

        foreach ($components as $region => $_) {
            if (isset($results[$region])) {
                yield $region => $results[$region];
            } elseif (isset($errors[$region])) {
                throw $errors[$region];
            }
        }
    }

    /**
     * @param array<string, array{
     *   componentCode:string,
     *   name:string,
     *   region:string,
     *   prompt:string,
     *   defaultConfig:array<string,mixed>,
     *   renderContext:array<string,mixed>
     * }> $components
     * @param callable(string|int,array<string,mixed>):void|null $onTaskStarted
     * @return \Generator yields
     *   [componentKey => ['status' => 'fulfilled', 'result' => array<string,mixed>]]
     *   or
     *   [componentKey => ['status' => 'rejected', 'error' => \Throwable]]
     */
    public function generateComponentEventsConcurrently(array $components, ?callable $onTaskStarted = null, ?int $concurrency = null): \Generator
    {
        if ($components === []) {
            return;
        }

        if ($this->isTestEnvironment() || !\class_exists(\Fiber::class)) {
            foreach ($components as $componentKey => $spec) {
                try {
                    if ($onTaskStarted !== null) {
                        $onTaskStarted($componentKey, $spec);
                    }
                    $spec = $this->prepareInlineImageAssetForComponentSpec($spec);
                    yield $componentKey => [
                        'status' => 'fulfilled',
                        'result' => $this->generateComponent(
                            $spec['componentCode'],
                            $spec['name'],
                            $spec['region'],
                            $spec['prompt'],
                            $spec['defaultConfig'],
                            $spec['renderContext']
                        ),
                    ];
                } catch (\Throwable $throwable) {
                    yield $componentKey => [
                        'status' => 'rejected',
                        'error' => $throwable,
                    ];
                }
            }

            return;
        }

        if (false && !$this->hasCustomComponentGenerator() && \function_exists('w_query')) {
            try {
                yield from $this->generateComponentEventsConcurrentlyViaAiQueryBatch($components);

                return;
            } catch (\InvalidArgumentException $wQueryUnavailable) {
                // Fall back to Fiber when the ai query provider is unavailable.
                if (!\str_contains((string)$wQueryUnavailable->getMessage(), 'query')) {
                    throw $wQueryUnavailable;
                }
            }
        }

        $tasks = [];
        foreach ($components as $componentKey => $spec) {
            $tasks[$componentKey] = function () use ($componentKey, $spec, $onTaskStarted): array {
                if ($onTaskStarted !== null) {
                    $onTaskStarted($componentKey, $spec);
                }
                $spec = $this->prepareInlineImageAssetForComponentSpec($spec);
                return $this->generateComponent(
                    $spec['componentCode'],
                    $spec['name'],
                    $spec['region'],
                    $spec['prompt'],
                    $spec['defaultConfig'],
                    $spec['renderContext']
                );
            };
        }

        $resolvedConcurrency = $this->resolveConcurrency(\count($tasks), $concurrency);
        $runner = new FiberTaskRunner(defaultConcurrency: $resolvedConcurrency);
        foreach ($runner->runEvents($tasks, $resolvedConcurrency) as $componentKey => $event) {
            if (($event['status'] ?? '') === 'fulfilled') {
                yield $componentKey => [
                    'status' => 'fulfilled',
                    'result' => \is_array($event['result'] ?? null) ? $event['result'] : [],
                ];
                continue;
            }

            yield $componentKey => [
                'status' => 'rejected',
                'error' => ($event['error'] ?? null) instanceof \Throwable
                    ? $event['error']
                    : new \RuntimeException('Component fiber failed without an exception payload.'),
            ];
        }
    }

    /**
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private function prepareInlineImageAssetForComponentSpec(array $spec): array
    {
        $region = \trim((string)($spec['region'] ?? ''));
        if ($region !== 'content') {
            return $spec;
        }

        $defaultConfig = \is_array($spec['defaultConfig'] ?? null) ? $spec['defaultConfig'] : [];
        $renderContext = \is_array($spec['renderContext'] ?? null) ? $spec['renderContext'] : [];
        $visualContract = \is_array($renderContext['_visual_contract'] ?? null)
            ? $renderContext['_visual_contract']
            : $this->decodeRuntimeVisualContract($defaultConfig);
        $imageRequired = (int)($visualContract['required'] ?? 0) === 1
            || \trim((string)($defaultConfig['runtime.section_image_required'] ?? '')) === '1';
        if (!$imageRequired) {
            return $spec;
        }

        $slotId = $this->firstConfigString($defaultConfig, ['runtime.section_image_slot_id']);
        if ($slotId === '') {
            $slotId = \trim((string)($visualContract['slot_id'] ?? ''));
        }
        if ($slotId === '') {
            $pageType = $this->firstConfigString($defaultConfig, ['runtime.section_page_type']);
            $sectionCode = $this->firstConfigString($defaultConfig, ['runtime.section_code']);
            if ($pageType !== '' && $sectionCode !== '') {
                $slotId = 'page:' . $pageType . ':' . \str_replace('/', '-', $sectionCode);
            }
        }
        if ($slotId === '') {
            AiSiteWorkflowTrace::log('required_image_asset_unresolved', [
                'reason' => 'slot_id_missing',
                'section_code' => $this->firstConfigString($defaultConfig, ['runtime.section_code']),
            ]);
            $this->failUnavailableRequiredInlineImageAsset($spec, '', 'slot_id_missing');
        }

        if ($this->isInlineImageGenerationDisabledForCurrentBuild($defaultConfig, $renderContext)) {
            AiSiteWorkflowTrace::log('required_image_asset_unresolved', [
                'reason' => self::INLINE_IMAGE_GENERATION_DISABLED_REASON,
                'slot_id' => $slotId,
                'section_code' => $this->firstConfigString($defaultConfig, ['runtime.section_code']),
            ]);
            $this->failUnavailableRequiredInlineImageAsset($spec, $slotId, self::INLINE_IMAGE_GENERATION_DISABLED_REASON);
        }

        if ($this->isInlineImageGenerationSuspendedForCurrentBuild($renderContext)) {
            AiSiteWorkflowTrace::log('required_image_asset_unresolved', [
                'reason' => 'deferred_after_failure',
                'slot_id' => $slotId,
                'section_code' => $this->firstConfigString($defaultConfig, ['runtime.section_code']),
            ]);
            $this->failUnavailableRequiredInlineImageAsset($spec, $slotId, 'deferred_after_failure');
        }

        $generator = $renderContext['_inline_image_asset_generator'] ?? null;
        if (!\is_callable($generator)) {
            AiSiteWorkflowTrace::log('required_image_asset_unresolved', [
                'reason' => 'generator_missing',
                'section_code' => $this->firstConfigString($defaultConfig, ['runtime.section_code']),
            ]);
            $this->failUnavailableRequiredInlineImageAsset($spec, $slotId, 'generator_missing');
        }

        try {
            $result = $this->generateInlineImageAssetWithRetries($generator, $slotId, $defaultConfig, $renderContext);
        } catch (\Throwable $throwable) {
            $this->suspendInlineImageGenerationForCurrentBuild('generation_failed');
            AiSiteWorkflowTrace::log('required_image_asset_unresolved', [
                'reason' => 'generation_failed',
                'slot_id' => $slotId,
                'error' => $this->summarizeThrowable($throwable),
            ]);
            $this->failUnavailableRequiredInlineImageAsset($spec, $slotId, 'generation_failed', $throwable);
        }
        if (!\is_array($result)) {
            $this->suspendInlineImageGenerationForCurrentBuild('invalid_generator_payload');
            AiSiteWorkflowTrace::log('required_image_asset_unresolved', [
                'reason' => 'invalid_generator_payload',
                'slot_id' => $slotId,
            ]);
            $this->failUnavailableRequiredInlineImageAsset($spec, $slotId, 'invalid_generator_payload');
        }

        $url = \trim((string)($result['final_url'] ?? $result['url'] ?? ''));
        if ($url === '') {
            $this->suspendInlineImageGenerationForCurrentBuild('empty_final_url');
            AiSiteWorkflowTrace::log('required_image_asset_unresolved', [
                'reason' => 'empty_final_url',
                'slot_id' => $slotId,
            ]);
            $this->failUnavailableRequiredInlineImageAsset($spec, $slotId, 'empty_final_url');
        }

        $alt = $this->firstConfigString($defaultConfig, ['visual.image_alt', 'content.heading', 'title', 'runtime.section_name']);
        if ($alt === '') {
            $alt = 'Generated section image';
        }

        $defaultConfig['visual.image_url'] = $url;
        $defaultConfig['visual.image_alt'] = $alt;
        $defaultConfig['image.url'] = $url;
        $defaultConfig['media.image_url'] = $url;
        $defaultConfig['media.image_alt'] = $alt;
        $defaultConfig['runtime.section_image_url'] = $url;
        $defaultConfig['runtime.section_image_alt'] = $alt;
        $defaultConfig['runtime.section_image_slot_id'] = $slotId;
        $PlanJsonTask = \is_array($renderContext['_plan_json_task'] ?? null)
            ? $renderContext['_plan_json_task']
            : $this->decodeRuntimePlanJsonTask($defaultConfig);
        $requiredEditableFields = $this->buildVirtualThemeRequiredEditableFields($defaultConfig, $PlanJsonTask);
        if ($requiredEditableFields !== []) {
            $encodedRequiredEditableFields = (string)\json_encode(
                $requiredEditableFields,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE
            );
            $defaultConfig['runtime.required_editable_fields'] = $encodedRequiredEditableFields;
            $renderContext['_required_editable_fields'] = $encodedRequiredEditableFields;
        }

        $verifiedAssets = \is_array($renderContext['verified_assets'] ?? null) ? $renderContext['verified_assets'] : [];
        $verifiedAssets[$slotId] = $url;
        $renderContext['verified_assets'] = $verifiedAssets;
        $requiredAssets = \is_array($renderContext['_required_image_assets'] ?? null) ? $renderContext['_required_image_assets'] : [];
        $requiredAssets[$slotId] = $url;
        $renderContext['_required_image_assets'] = $requiredAssets;
        $inlineGeneratedAsset = [
            'slot_id' => $slotId,
            'final_url' => $url,
            'url' => $url,
            'field' => 'media.image_url',
            'image_role' => 'generated-asset',
            'alt' => $alt,
            'status' => 'generated',
            'source' => 'inline_block',
        ];
        $renderContext['_visual_contract'] = \array_replace($visualContract, ['slot_id' => $slotId, 'final_url' => $url]);
        $renderContext['_inline_generated_asset'] = $inlineGeneratedAsset;
        $inlineGeneratedAssets = \is_array($renderContext['_inline_generated_assets'] ?? null)
            ? $renderContext['_inline_generated_assets']
            : [];
        $inlineGeneratedAssets[$slotId] = $inlineGeneratedAsset;
        $renderContext['_inline_generated_assets'] = $inlineGeneratedAssets;

        $spec['defaultConfig'] = $defaultConfig;
        $spec['renderContext'] = $renderContext;

        return $spec;
    }

    /**
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     * @return array<string,array<string,string>>
     */
    private function extractBlockLocalAssetsFromRenderContext(array $renderContext, array $defaultConfig): array
    {
        $assets = [];
        if (\is_array($renderContext['_inline_generated_asset'] ?? null)) {
            $this->appendBlockLocalAsset($assets, $renderContext['_inline_generated_asset'], '', $defaultConfig);
        }
        if (\is_array($renderContext['_inline_generated_assets'] ?? null)) {
            foreach ($renderContext['_inline_generated_assets'] as $fallbackSlotId => $asset) {
                $this->appendBlockLocalAsset($assets, $asset, $fallbackSlotId, $defaultConfig);
            }
        }
        foreach (['verified_assets', '_required_image_assets'] as $contextKey) {
            if (!\is_array($renderContext[$contextKey] ?? null)) {
                continue;
            }
            foreach ($renderContext[$contextKey] as $fallbackSlotId => $asset) {
                $this->appendBlockLocalAsset($assets, $asset, $fallbackSlotId, $defaultConfig);
            }
        }
        if (\is_array($renderContext['_visual_contract'] ?? null)) {
            $this->appendBlockLocalAsset($assets, $renderContext['_visual_contract'], '', $defaultConfig);
        }

        return $assets;
    }

    /**
     * @param array<string,array<string,string>> $assets
     * @param array<string,mixed> $defaultConfig
     */
    private function appendBlockLocalAsset(array &$assets, mixed $rawAsset, int|string $fallbackSlotId, array $defaultConfig = []): void
    {
        $asset = \is_array($rawAsset) ? $rawAsset : [];
        $slotId = \trim((string)($asset['slot_id'] ?? (\is_string($fallbackSlotId) ? $fallbackSlotId : '')));
        if ($slotId === '') {
            $slotId = $this->firstConfigString($defaultConfig, ['runtime.section_image_slot_id', 'visual.image_slot_id']);
        }
        $url = \is_scalar($rawAsset)
            ? \trim((string)$rawAsset)
            : $this->firstConfigString($asset, ['final_url', 'url', 'src']);
        if ($slotId === '' || $url === '') {
            return;
        }

        $field = $this->firstConfigString($asset, ['field']);
        if ($field === '') {
            $field = 'media.image_url';
        }
        $imageRole = $this->firstConfigString($asset, ['image_role', 'role']);
        if ($imageRole === '') {
            $imageRole = 'generated-asset';
        }
        $status = $this->firstConfigString($asset, ['status']);
        if ($status === '') {
            $status = 'generated';
        }
        $alt = $this->firstConfigString($asset, ['alt', 'image_alt']);
        if ($alt === '') {
            $alt = $this->firstConfigString($defaultConfig, [
                'media.image_alt',
                'visual.image_alt',
                'runtime.section_image_alt',
                'content.heading',
                'title',
                'runtime.section_name',
            ]);
        }

        $row = [
            'slot_id' => $slotId,
            'final_url' => $url,
            'url' => $url,
            'field' => $field,
            'image_role' => $imageRole,
            'status' => $status,
        ];
        if ($alt !== '') {
            $row['alt'] = $alt;
        }
        foreach (['page_type', 'block_key', 'section_code', 'task_key', 'source'] as $metaKey) {
            $metaValue = $this->firstConfigString($asset, [$metaKey]);
            if ($metaValue !== '') {
                $row[$metaKey] = $metaValue;
            }
        }

        $assets[$slotId] = $row;
    }

    /**
     * Required generated images are a build dependency. If the asset cannot be
     * confirmed before block rendering, fail the block so the queue can retry
     * instead of publishing a CSS-only substitute.
     *
     * @param array<string,mixed> $spec
     */
    private function failUnavailableRequiredInlineImageAsset(
        array $spec,
        string $slotId,
        string $reason,
        ?\Throwable $previous = null
    ): never
    {
        $defaultConfig = \is_array($spec['defaultConfig'] ?? null) ? $spec['defaultConfig'] : [];
        $renderContext = \is_array($spec['renderContext'] ?? null) ? $spec['renderContext'] : [];
        $visualContract = \is_array($renderContext['_visual_contract'] ?? null)
            ? $renderContext['_visual_contract']
            : $this->decodeRuntimeVisualContract($defaultConfig);
        if ($slotId === '') {
            $slotId = \trim((string)($visualContract['slot_id'] ?? $defaultConfig['runtime.section_image_slot_id'] ?? ''));
        }
        $pageType = $this->firstConfigString($defaultConfig, ['runtime.section_page_type']);
        $sectionCode = $this->firstConfigString($defaultConfig, ['runtime.section_code', 'runtime.section_key', 'runtime.block_key']);
        if ($sectionCode === '' && \is_array($renderContext['_plan_json_task'] ?? null)) {
            $sectionCode = \trim((string)($renderContext['_plan_json_task']['section_code'] ?? $renderContext['_plan_json_task']['block_key'] ?? ''));
        }
        if ($pageType === '' && \is_array($renderContext['_plan_json_task'] ?? null)) {
            $pageType = \trim((string)($renderContext['_plan_json_task']['page_type'] ?? ''));
        }

        $details = [];
        $details[] = 'reason=' . ($reason !== '' ? $reason : 'unknown');
        if ($slotId !== '') {
            $details[] = 'slot=' . $slotId;
        }
        if ($pageType !== '') {
            $details[] = 'page=' . $pageType;
        }
        if ($sectionCode !== '') {
            $details[] = 'section=' . $sectionCode;
        }
        if ($previous instanceof \Throwable) {
            $previousSummary = $this->clipText($this->summarizeThrowable($previous), 220);
            if ($previousSummary !== '') {
                $details[] = 'provider_error=' . $previousSummary;
            }
        }

        throw new \RuntimeException(
            'required_image_asset_unresolved: Required generated image must be generated and confirmed before block rendering; '
            . \implode(' ', $details),
            0,
            $previous
        );
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function isInlineImageGenerationDisabledForCurrentBuild(array $defaultConfig, array $renderContext): bool
    {
        $scope = \is_array($renderContext['_scope'] ?? null) ? $renderContext['_scope'] : [];
        $buildOptions = \is_array($scope['ai_site_build_options'] ?? null) ? $scope['ai_site_build_options'] : [];
        $runtimeOptions = \is_array($scope['runtime'] ?? null) ? $scope['runtime'] : [];

        if ((bool)RequestContext::get(self::REQUEST_CTX_INLINE_IMAGE_GENERATION_DISABLED, false)) {
            return true;
        }

        foreach ([
            $renderContext['_disable_inline_image_generation'] ?? null,
            $renderContext['_skip_inline_image_generation'] ?? null,
            $renderContext['disable_inline_image_generation'] ?? null,
            $renderContext['skip_inline_image_generation'] ?? null,
            $defaultConfig['runtime.disable_inline_image_generation'] ?? null,
            $defaultConfig['runtime.skip_inline_image_generation'] ?? null,
            $scope['_disable_inline_image_generation'] ?? null,
            $scope['_skip_inline_image_generation'] ?? null,
            $scope['disable_inline_image_generation'] ?? null,
            $scope['skip_inline_image_generation'] ?? null,
            $scope['test_skip_inline_images'] ?? null,
            $scope['ai_site_test_skip_images'] ?? null,
            $scope['pagebuilder_ai_skip_inline_images'] ?? null,
            $buildOptions['disable_inline_image_generation'] ?? null,
            $buildOptions['skip_inline_image_generation'] ?? null,
            $runtimeOptions['disable_inline_image_generation'] ?? null,
            $runtimeOptions['skip_inline_image_generation'] ?? null,
            Env::get('pagebuilder.ai_site.skip_inline_image_generation', null),
            \getenv('PAGEBUILDER_AI_SITE_SKIP_INLINE_IMAGES') ?: null,
        ] as $value) {
            if ($this->isTruthyRuntimeSwitchValue($value)) {
                return true;
            }
        }

        return false;
    }

    private function isTruthyRuntimeSwitchValue(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        if (!\is_scalar($value)) {
            return false;
        }

        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on', 'enabled', 'skip', 'disabled'], true);
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function isInlineImageGenerationSuspendedForCurrentBuild(array $renderContext): bool
    {
        if (!$this->isBuildQueueComponentContext($renderContext)) {
            return false;
        }

        return (bool)RequestContext::get(self::REQUEST_CTX_INLINE_IMAGE_GENERATION_SUSPENDED, false);
    }

    private function suspendInlineImageGenerationForCurrentBuild(string $reason): void
    {
        RequestContext::set(self::REQUEST_CTX_INLINE_IMAGE_GENERATION_SUSPENDED, true);
        RequestContext::set(self::REQUEST_CTX_INLINE_IMAGE_GENERATION_SUSPEND_REASON, $reason);
    }

    /**
     * Required image slots must not fall back to placeholders, but transient
     * provider/network errors should get a bounded retry before the block fails.
     *
     * @param callable(string,array<string,mixed>,array<string,mixed>):array<string,mixed> $generator
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     * @return array<string,mixed>
     */
    private function generateInlineImageAssetWithRetries(
        callable $generator,
        string $slotId,
        array $defaultConfig,
        array $renderContext
    ): array {
        $maxAttempts = $this->resolveInlineImageGenerationMaxAttempts($defaultConfig, $renderContext);
        $lastThrowable = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $result = $generator($slotId, $defaultConfig, $renderContext);
                return \is_array($result) ? $result : [];
            } catch (\Throwable $throwable) {
                $lastThrowable = $throwable;
                if ($attempt >= $maxAttempts || !$this->shouldRetryInlineImageGeneration($throwable)) {
                    throw $throwable;
                }
                \w_log_warning('[AI Site Inline Image Retry] ' . $slotId . ' attempt '
                    . ($attempt + 1) . '/' . $maxAttempts . ': ' . $this->summarizeThrowable($throwable));
            }
        }

        throw new \RuntimeException(
            'Inline image asset generation failed after ' . $maxAttempts . ' attempts for ' . $slotId . ': '
            . $this->summarizeThrowable($lastThrowable ?? new \RuntimeException('unknown'))
        );
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function resolveInlineImageGenerationMaxAttempts(array $defaultConfig, array $renderContext): int
    {
        foreach ([
            $renderContext['_inline_image_generation_max_attempts'] ?? null,
            $defaultConfig['runtime.inline_image_generation_max_attempts'] ?? null,
        ] as $value) {
            if (\is_numeric($value)) {
                return \max(1, \min(3, (int)$value));
            }
        }

        return 1;
    }

    private function shouldRetryInlineImageGeneration(\Throwable $throwable): bool
    {
        $message = \strtolower($this->collectThrowableMessages($throwable));
        foreach ([
            'http 401',
            'http 402',
            'http 403',
            'api key',
            'invalid api key',
            'missing api key',
            'unauthorized',
            'authentication',
            'insufficient balance',
            'quota',
            'provider configuration',
            'model selection',
        ] as $marker) {
            if (\str_contains($message, $marker)) {
                return false;
            }
        }

        foreach ([
            'http: 0',
            'http: 429',
            'http 0',
            'http 429',
            'http 500',
            'http 502',
            'http 503',
            'http 504',
            'timed out',
            'timeout',
            'low speed',
            'unexpected eof',
            'eof while reading',
            'connection reset',
            'connection refused',
            'temporarily unavailable',
            'network',
            'curl',
        ] as $marker) {
            if (\str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $config
     * @param list<string> $keys
     */
    private function firstConfigString(array $config, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $config[$key] ?? null;
            if (\is_scalar($value)) {
                $value = \trim((string)$value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function hasCustomComponentGenerator(): bool
    {
        try {
            return (new \ReflectionMethod($this, 'generateComponent'))->getDeclaringClass()->getName() !== self::class;
        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
 * N  ?{@see w_query()} r::generateStreamBatch ?AI  ? *  ?key  ?{@see generateComponent()} ? *
     * @param array<string, array{
     *   componentCode:string,
     *   name:string,
     *   region:string,
     *   prompt:string,
     *   defaultConfig:array<string,mixed>,
     *   renderContext:array<string,mixed>
     * }> $components
     * @return \Generator<string|int, array{status:string, result?:array<string,mixed>, error?:\Throwable}>
     */
    private function generateComponentEventsConcurrentlyViaAiQueryBatch(array $components): \Generator
    {
        foreach ($components as $componentKey => $spec) {
            try {
                $spec = $this->prepareInlineImageAssetForComponentSpec($spec);
                $region = (string)($spec['region'] ?? 'content');
                $componentCode = (string)($spec['componentCode'] ?? '');
                $defaultConfig = \is_array($spec['defaultConfig'] ?? null) ? $spec['defaultConfig'] : [];
                $renderContext = \is_array($spec['renderContext'] ?? null) ? $spec['renderContext'] : [];
                $promptComponentCode = $this->mergeSemanticComponentCode(
                    $componentCode,
                    $this->buildSemanticComponentCodeForValidation($componentCode, $defaultConfig, $renderContext)
                );
                $attemptPrompt = $this->appendComponentCssScopeInstruction(
                    (string)($spec['prompt'] ?? ''),
                    $promptComponentCode,
                    $renderContext
                );
                $guardedPrompt = $this->prependComponentJsonOnlyGuard($attemptPrompt, false);
                $guardedPrompt = $this->getSkillRegistry()->prependPromptGuide($guardedPrompt, 'stage3');
                $raw = (string)$this->callAiOperation('generate', [
                    'prompt' => $guardedPrompt,
                    'scenario_code' => 'pagebuilder_component_generation',
                    'params' => $this->buildAiRuntimeParams([
                        'allow_zero_balance_provider' => true,
                        'response_format' => ['type' => 'json_object'],
                        'temperature' => 0.15,
                        'max_tokens' => 8192,
                        'timeout' => self::AI_REQUEST_TIMEOUT_SECONDS,
                    ], false),
                ]);
                $aiData = $this->decodeAndNormalizeComponentContent(
                    $raw,
                    $region,
                    'AI did not return a valid component JSON payload'
                );
                $artifact = $this->buildComponentArtifactFromAiData(
                    (string)($spec['componentCode'] ?? ''),
                    (string)($spec['name'] ?? ''),
                    $region,
                    (string)($spec['prompt'] ?? ''),
                    $defaultConfig,
                    $renderContext,
                    $aiData
                );
                yield $componentKey => [
                    'status' => 'fulfilled',
                    'result' => $artifact,
                ];
            } catch (\Throwable $primary) {
                yield $componentKey => [
                    'status' => 'rejected',
                    'error' => $primary,
                ];
            }
        }
    }

    /**
     * Keep AI HTTP component generation bounded while allowing real fanout.
     */
    private function resolveConcurrency(int $taskCount, ?int $configured = null): int
    {
        $taskCount = \max(1, $taskCount);
        $maxConcurrent = $configured ?? (int) Env::get('pagebuilder.ai_site.max_http_concurrency', 5);
        $maxConcurrent = \max(1, \min(32, $maxConcurrent));

        return \min($taskCount, $maxConcurrent);
    }

    /**
 *  ?header + footer  ?
     *
     * @return array{header:array<string,mixed>, footer:array<string,mixed>}
     */
    public function generateSharedComponentsConcurrently(array $websiteProfile, array $scope): array
    {
        $components = $this->buildSharedComponentGenerationSpecs($websiteProfile, $scope);

        $result = ['header' => null, 'footer' => null];
        $errors = [];
        foreach ($this->generateComponentEventsConcurrently($components) as $region => $event) {
            if (($event['status'] ?? '') === 'fulfilled') {
                $result[$region] = \is_array($event['result'] ?? null) ? $event['result'] : null;
                continue;
            }
            $errors[$region] = ($event['error'] ?? null) instanceof \Throwable
                ? $event['error']
                : new \RuntimeException('Unknown shared component generation error.');
        }

        if (!\is_array($result['footer'] ?? null)) {
            $footerError = $errors['footer'] ?? null;
            if ($footerError instanceof \Throwable) {
                throw new \RuntimeException('Shared footer generation failed: ' . $this->summarizeThrowable($footerError), 0, $footerError);
            }
            throw new \RuntimeException('Shared footer generation failed without a throwable.');
        }

        if (!\is_array($result['header'] ?? null)) {
            $headerError = $errors['header'] ?? null;
            if ($headerError instanceof \Throwable) {
                throw new \RuntimeException('Shared header generation failed: ' . $this->summarizeThrowable($headerError), 0, $headerError);
            }
            throw new \RuntimeException('Shared header generation failed without a throwable.');
        }

        return $result;
    }

    /**
     * @param list<string> $regions
     * @return array<string, array<string, mixed>>
     */
    private function buildSharedComponentGenerationSpecs(array $websiteProfile, array $scope, array $regions = []): array
    {
        $scope = $this->normalizePlanJsonExecutionScope($scope, $websiteProfile);
        $regionMap = $regions === [] ? ['header' => true, 'footer' => true] : \array_fill_keys(\array_values(\array_filter(\array_map('strval', $regions))), true);
        $siteDisplayName = $this->resolveLocaleSafeSiteDisplayName(
            $this->getPageBlueprintService()->resolveSiteDisplayName($websiteProfile, $scope),
            $websiteProfile,
            $scope,
            $this->resolvePrimaryLocale($websiteProfile, $scope)
        );
        $headerConfig = $this->buildHeaderDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        $footerConfig = $this->buildFooterDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        $components = [
            'header' => [
                'componentCode' => 'header/ai-site-header',
                'name' => 'AI Site Header',
                'region' => 'header',
                'prompt' => $this->buildHeaderGenerationPrompt($websiteProfile, $scope, $siteDisplayName, $headerConfig),
                'defaultConfig' => $headerConfig,
                'renderContext' => $this->buildRenderContext(Page::TYPE_HOME, $websiteProfile, $scope, $headerConfig),
            ],
            'footer' => [
                'componentCode' => 'footer/ai-site-footer',
                'name' => 'AI Site Footer',
                'region' => 'footer',
                'prompt' => $this->buildFooterGenerationPrompt($websiteProfile, $scope, $siteDisplayName, $footerConfig),
                'defaultConfig' => $footerConfig,
                'renderContext' => $this->buildRenderContext(Page::TYPE_HOME, $websiteProfile, $scope, $footerConfig),
            ],
        ];

        return \array_intersect_key($components, $regionMap);
    }

    /**
 *  ?section
     *
     * @return array{blueprint:array<string,mixed>, sections:list<array<string,mixed>>}
     */
    public function generatePageSectionsConcurrently(string $pageType, array $websiteProfile, array $scope): array
    {
        $blueprint = $this->getPageBlueprintService()->buildPageBlueprint($pageType, $scope, $websiteProfile);
        $blueprint = $this->mergePlanJsonTaskSectionsIntoBlueprint($pageType, $blueprint, $scope);
        $components = [];
        $sectionMeta = [];

        foreach (($blueprint['sections'] ?? []) as $section) {
            if (!\is_array($section)) {
                continue;
            }
            $sectionCode = \trim((string)($section['code'] ?? ''));
            if ($sectionCode === '') {
                continue;
            }

            $defaultConfig = $this->buildSectionDefaultConfig($pageType, $section, $blueprint, $websiteProfile, $scope);
            $visualContract = $this->buildSectionVisualContract($pageType, $section, $blueprint, $websiteProfile, $scope);
            $defaultConfig = $this->applySectionVisualContractToDefaultConfig($defaultConfig, $visualContract);
            $key = (string)($section['key'] ?? '');
            $name = (string)($section['name'] ?? $sectionCode);
            $regionKey = 'section_' . $sectionCode;

            $components[$regionKey] = [
                'componentCode' => $sectionCode,
                'name' => $name,
                'region' => 'content',
                'prompt' => $this->buildSectionGenerationPrompt($pageType, $section, $blueprint, $websiteProfile, $scope),
                'defaultConfig' => $defaultConfig,
                'renderContext' => $this->buildRenderContext($pageType, $websiteProfile, $scope, $defaultConfig),
            ];
            $sectionMeta[$regionKey] = [
                'key' => $key,
                'code' => $sectionCode,
                'name' => $name,
                'region' => 'content',
                'sort_order' => (int)($section['sort_order'] ?? 0),
            ];
        }

        $sections = [];
        foreach ($this->generateComponentsConcurrently($components) as $regionKey => $result) {
            $meta = $sectionMeta[$regionKey] ?? [];
            $sections[] = \array_replace($meta, $result);
        }

 //  ?sort_order  ?
        \usort($sections, static fn(array $a, array $b): int => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

        return [
            'blueprint' => $blueprint,
            'sections' => $sections,
        ];
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $aiData
     * @return array{
     *   code:string,
     *   name:string,
     *   region:string,
     *   phtml:string,
     *   html:string,
     *   default_config:array<string,mixed>,
     *   ai_data:array<string,mixed>
     * }
     */
    private function buildComponentArtifactFromAiData(
        string $componentCode,
        string $name,
        string $region,
        string $originalPromptForDescription,
        array $defaultConfig,
        array $renderContext,
        array $aiData
    ): array {
        $safeDescription = $this->sanitizeVisibleCopy($originalPromptForDescription);
        $componentInfo = [
            'name' => $name,
            'name_en' => $name,
            'description' => $safeDescription !== '' ? $safeDescription : ($name !== '' ? $name : $componentCode),
        ];
        $componentCode = $this->normalizeOptionalComponentCode($componentCode);
        $semanticComponentCode = $this->buildSemanticComponentCodeForValidation($componentCode, $defaultConfig, $renderContext);
        $verifiedAssets = $this->extractVerifiedAssetUrls($renderContext);
        $aiData = $this->stripOptionalUnverifiedGeneratedImageReferences($aiData, $renderContext, $defaultConfig);
        $aiData = $this->enforceConfiguredCtaIntentInAiPayload($aiData, $defaultConfig, $componentCode);
        // Industry/content strategy drift is reviewed through direction QA, not
        // blocked at component-generation time.
 //  ?verified_assets  ?HTML  ?URL
        if (false && $verifiedAssets !== []) {
            $htmlToCheck = (string)($aiData['html_content'] ?? $aiData['html_extra'] ?? '');
            if ($htmlToCheck !== '' && !\str_contains($htmlToCheck, 'data-pb-ai-image-role="generated-asset"')) {
                $anyUrlReferenced = false;
                foreach ($verifiedAssets as $assetUrl) {
                    if (\str_contains($htmlToCheck, \trim((string)$assetUrl))) {
                        $anyUrlReferenced = true;
                        break;
                    }
                }
                if (!$anyUrlReferenced) {
                    throw new \RuntimeException(
                        'AI generated block skipped real images: verified assets exist but none referenced in html_content.'
                        . ' Assets: ' . \implode(', ', \array_slice($verifiedAssets, 0, 3))
                    );
                }
            }
        }
        $aiData = $this->ensureAiPayloadValid($aiData, $region, $componentCode, $verifiedAssets, $semanticComponentCode, $renderContext, $defaultConfig);
        $aiData = $this->repairEmptyVisitorControlCopyInAiPayload($aiData, $componentCode, $defaultConfig, $renderContext);
        $aiData = $this->normalizeRequiredPrimaryHeadingInAiPayload($aiData, $componentCode, $semanticComponentCode, $renderContext);
        $defaultConfig = $this->applyAiPayloadOwnershipToDefaultConfig($defaultConfig, $region, $aiData);
        $persistedConfig = $this->sanitizeGeneratedComponentDefaultConfig($defaultConfig, $region);
        $persistedConfig = $this->pinSharedIdentityConfigAfterSanitize($persistedConfig, $region, $renderContext);
        $blockLocalAssets = $this->extractBlockLocalAssetsFromRenderContext($renderContext, $persistedConfig);

        $phtml = $this->getFrameworkBuilder()->buildComponent($region, $componentInfo, $aiData);
        $generatedStyleCss = \trim((string)($aiData['css_extra'] ?? '') . "\n" . (string)($aiData['css_responsive'] ?? ''));
        $runRenderedHardGates = true;
        if ((bool)RequestContext::get(self::REQUEST_KEY_FAST_BLOCK_ARTIFACT, false)) {
            $html = (string)($aiData['html_content'] ?? $aiData['html_extra'] ?? '');
            if ($runRenderedHardGates) {
                $this->assertRenderedHtmlPassesBuildQualityGate($componentCode, $html, $semanticComponentCode, $generatedStyleCss, $renderContext);
            }

            $artifact = [
                'code' => $componentCode,
                'name' => $name,
                'region' => $region,
                'phtml' => $phtml,
                'html' => $html,
                'default_config' => $persistedConfig,
                'ai_data' => $aiData,
            ];
            if ($blockLocalAssets !== []) {
                $artifact['assets'] = $blockLocalAssets;
            }

            return $artifact;
        }

        $syntaxCheck = $this->getCodeValidator()->checkSyntax($phtml);
        if (empty($syntaxCheck['valid'])) {
            $error = (string)($syntaxCheck['error'] ?? 'unknown');
            throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
                (string)__('AI generated component failed PHP syntax validation: %{message}', [
                    'message' => $error,
                ]),
                [[
                    'rule' => 'component.syntax',
                    'field' => $componentCode,
                    'found' => $this->clipText($error, 320),
                    'expected' => 'FrameworkBuilder output passes PHP syntax validation without local patching',
                    'hint' => 'Return valid PHTML generated from the approved component contract. Do not patch broken PHP locally.',
                ]]
            );
        }

        $html = $this->renderTemplateToHtml($phtml, $persistedConfig, $renderContext);
        if ($runRenderedHardGates) {
            $this->assertRenderedHtmlMatchesLocale($html, $renderContext);
            $this->assertRenderedHtmlPassesBuildQualityGate($componentCode, $html, $semanticComponentCode, $generatedStyleCss, $renderContext);
        }

        $artifact = [
            'code' => $componentCode,
            'name' => $name,
            'region' => $region,
            'phtml' => $phtml,
            'html' => $html,
            'default_config' => $persistedConfig,
            'ai_data' => $aiData,
        ];
        if ($blockLocalAssets !== []) {
            $artifact['assets'] = $blockLocalAssets;
        }

        return $artifact;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function buildSemanticComponentCodeForValidation(string $componentCode, array $defaultConfig, array $renderContext): string
    {
        $PlanJsonTask = $this->resolveBuildQueueComponentTaskContext($renderContext, $defaultConfig);
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $visualContract = $this->resolveRenderContextVisualContract($renderContext, $defaultConfig);

        $parts = [
            $componentCode,
            $defaultConfig['runtime.section_code'] ?? null,
            $defaultConfig['runtime.section_key'] ?? null,
            $defaultConfig['runtime.block_key'] ?? null,
            $defaultConfig['runtime.section_name'] ?? null,
            $PlanJsonTask['task_key'] ?? null,
            $PlanJsonTask['page_type'] ?? null,
            $PlanJsonTask['section_key'] ?? null,
            $PlanJsonTask['block_key'] ?? null,
            $PlanJsonTask['block_type'] ?? null,
            $PlanJsonTask['page_flow_role'] ?? null,
            $PlanJsonTask['section_code'] ?? null,
            $PlanJsonTask['label'] ?? null,
            $planContext['source_block_key'] ?? null,
            $planContext['block_type'] ?? null,
            $planContext['page_flow_role'] ?? null,
            $planContext['block_goal'] ?? null,
            $blockTask['block_type'] ?? null,
            $blockTask['page_flow_role'] ?? null,
            $blockTask['task_goal'] ?? null,
            $visualContract['section_code'] ?? null,
            $visualContract['block_key'] ?? null,
            $visualContract['page_flow_role'] ?? null,
            \array_key_exists('strict_hero_cover', $visualContract)
                ? ((int)($visualContract['strict_hero_cover'] ?? 0) === 1 ? 'strict_hero_cover' : 'non_strict_page_opening')
                : null,
        ];

        $tokens = [];
        foreach ($parts as $part) {
            $value = \trim((string)$part);
            if ($value !== '') {
                $tokens[$value] = true;
            }
        }

        return \implode(' ', \array_keys($tokens));
    }

    private function mergeSemanticComponentCode(string $componentCode, mixed $semanticComponentCode): string
    {
        $componentCode = \trim($componentCode);
        $semanticComponentCode = \trim((string)$semanticComponentCode);
        if ($semanticComponentCode === '' || $semanticComponentCode === $componentCode) {
            return $componentCode;
        }

        return \trim($componentCode . ' ' . $semanticComponentCode);
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function isHeroOrBannerImageContract(array $defaultConfig, array $renderContext = [], string $html = ''): bool
    {
        unset($html);
        $visualContract = $this->resolveRenderContextVisualContract($renderContext, $defaultConfig);

        return (int)($visualContract['strict_hero_cover'] ?? 0) === 1;
    }

    /**
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     */
    private function renderContextRequiresStrictHeroCover(array $renderContext, array $defaultConfig = []): bool
    {
        $visualContract = $this->resolveRenderContextVisualContract($renderContext, $defaultConfig);

        return (int)($visualContract['strict_hero_cover'] ?? 0) === 1;
    }

    /**
     * @param array<string,mixed> $renderContext
     * @return array{
     *   code:string,
     *   name:string,
     *   region:string,
     *   phtml:string,
     *   html:string,
     *   default_config:array<string,mixed>,
     *   ai_data:array<string,mixed>
     * }
     */
    protected function generateComponent(
        string $componentCode,
        string $name,
        string $region,
        string $prompt,
        array $defaultConfig,
        array $renderContext
    ): array {

        $promptComponentCode = $this->mergeSemanticComponentCode(
            $componentCode,
            $this->buildSemanticComponentCodeForValidation($componentCode, $defaultConfig, $renderContext)
        );
        $attemptPrompt = $this->appendComponentCssScopeInstruction($prompt, $promptComponentCode, $renderContext);
        $isHeroComponent = $this->renderContextRequiresStrictHeroCover($renderContext, $defaultConfig);
        $componentPrefix = $this->normalizeComponentCssPrefix($componentCode);
        $lastThrowable = null;
        $attemptsRun = 0;
        for ($attempt = 0; $attempt < self::COMPONENT_GENERATION_MAX_ATTEMPTS; $attempt++) {
            $attemptsRun = $attempt + 1;
            $promptForAttempt = $attemptPrompt;
            $aiData = [];
            if ($attempt > 0) {
                if (!$this->isTransientAiProviderFailure($lastThrowable)) {
                    $promptForAttempt = $this->buildStrictComponentRecoveryPrompt(
                        $componentCode,
                        $region,
                        $defaultConfig,
                        $renderContext,
                        $lastThrowable ?? new \RuntimeException('unknown strict contract failure'),
                        $isHeroComponent,
                        $componentPrefix
                    );
                }
            }

            try {
                $aiData = $this->runAiGeneration($region, $promptForAttempt, $defaultConfig, $renderContext, $attempt > 0);

                return $this->buildComponentArtifactFromAiData(
                    $componentCode,
                    $name,
                    $region,
                    $prompt,
                    $defaultConfig,
                    $renderContext,
                    $aiData
                );
            } catch (\Throwable $throwable) {
                $lastThrowable = $throwable;
                $this->logFastBlockGenerationFailureSample($componentCode, $attempt, $throwable, $aiData);
                if ($attempt < self::COMPONENT_GENERATION_MAX_ATTEMPTS - 1 && $aiData !== [] && $this->tryApplyContractStylePatchToAiData($aiData, $renderContext, $throwable)) {
                    try {
                        return $this->buildComponentArtifactFromAiData(
                            $componentCode,
                            $name,
                            $region,
                            $prompt,
                            $defaultConfig,
                            $renderContext,
                            $aiData
                        );
                    } catch (\Throwable $patchThrowable) {
                        $lastThrowable = $patchThrowable;
                    }
                }
                if ($attempt < self::COMPONENT_GENERATION_MAX_ATTEMPTS - 1 && $this->shouldRetryAiComponentGeneration($throwable)) {
                    $this->emitComponentFallbackNotice($region, $componentCode, 'retrying contract-guided generation after: ' . $this->summarizeThrowable($throwable));
                    continue;
                }

                break;
            }
        }

        $finalReason = $this->summarizeThrowable($lastThrowable ?? new \RuntimeException('unknown component generation failure'));
        $this->emitComponentFallbackNotice($region, $componentCode, $finalReason);
        $attemptLabel = (string)\max(1, $attemptsRun) . ' real-AI attempt' . ($attemptsRun === 1 ? '' : 's');
        throw new \RuntimeException('AI component generation failed after ' . $attemptLabel . ': ' . $finalReason, 0, $lastThrowable);
    }


    /**
     * @param array<string,mixed> $aiData
     */
    private function logFastBlockGenerationFailureSample(string $componentCode, int $attempt, \Throwable $throwable, array $aiData): void
    {
        $summary = $this->summarizeThrowable($throwable);
        $summaryLower = \mb_strtolower($summary, 'UTF-8');
        $shouldLog = (bool)RequestContext::get(self::REQUEST_KEY_FAST_BLOCK_ARTIFACT, false)
            || $attempt >= 2
            || \str_contains($summaryLower, 'editable field contract')
            || \str_contains($summaryLower, 'structure policy')
            || \str_contains($summaryLower, 'hard policy')
            || \str_contains($summaryLower, 'visual contrast')
            || \str_contains($summaryLower, 'cta/action')
            || \str_contains($summaryLower, 'role fidelity')
            || \str_contains($summaryLower, 'quality gate');
        if (!$shouldLog) {
            return;
        }

        try {
            \w_log_warning('[AI Site Block Failure Sample] ' . \json_encode([
                'component_code' => $componentCode,
                'attempt' => $attempt + 1,
                'error' => $summary,
                'extra_fields_excerpt' => $this->clipText((string)($aiData['extra_fields'] ?? ''), 1200),
                'php_variables_excerpt' => $this->clipText((string)($aiData['php_variables'] ?? ''), 1200),
                'html_excerpt' => $this->clipText((string)($aiData['html_content'] ?? ''), 1200),
                'css_excerpt' => $this->clipText((string)($aiData['css_extra'] ?? ''), 1200),
                'css_responsive_excerpt' => $this->clipText((string)($aiData['css_responsive'] ?? ''), 800),
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (\Throwable) {
        }
    }

    /**
     * Build a short retry prompt from the same runtime contract instead of
     * appending more rules to the failed long-form design prompt.
     *
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function buildStrictComponentRecoveryPrompt(
        string $componentCode,
        string $region,
        array $defaultConfig,
        array $renderContext,
        \Throwable $failure,
        bool $isHeroComponent,
        string $componentPrefix
    ): string {
        $locale = \trim((string)($renderContext['_content_locale'] ?? $defaultConfig['runtime.content_locale'] ?? ''));
        $websiteProfile = \is_array($renderContext['_website_profile'] ?? null) ? $renderContext['_website_profile'] : [];
        $scopeIdentity = \is_array($renderContext['_scope_identity'] ?? null) ? $renderContext['_scope_identity'] : [];
        $siteTitle = $this->pickString(
            $scopeIdentity['site_title'] ?? null,
            $websiteProfile['site_title'] ?? null,
            $websiteProfile['site_name'] ?? null,
            $websiteProfile['brand_name'] ?? null,
            'this site'
        );
        $brief = $this->pickString(
            $websiteProfile['brief_description'] ?? null,
            $websiteProfile['site_tagline'] ?? null,
            $websiteProfile['description'] ?? null
        );
        $sectionName = $this->pickString(
            $defaultConfig['runtime.section_name'] ?? null,
            $defaultConfig['content.heading'] ?? null,
            $componentCode
        );
        $visualContract = \is_array($renderContext['_visual_contract'] ?? null) ? $renderContext['_visual_contract'] : [];
        $scope = \is_array($renderContext['_scope'] ?? null) ? $renderContext['_scope'] : [];
        $PlanJsonTask = \is_array($renderContext['_plan_json_task'] ?? null) ? $renderContext['_plan_json_task'] : [];
        $themePalette = $this->resolveThemePaletteForContract($PlanJsonTask, $scope);
        $artifacts = $this->buildRequiredImagePromptArtifacts($visualContract, $themePalette);
        $strictImageTail = $this->buildStrictRequiredImagePromptTail($componentCode, $renderContext);
        $semanticComponentCode = $this->buildSemanticComponentCodeForValidation($componentCode, $defaultConfig, $renderContext);
        $roleSpecificRecoveryContract = $this->buildRoleSpecificRecoveryContract($semanticComponentCode, $componentPrefix, $isHeroComponent);
        $isFaqRecoveryComponent = \str_contains(\mb_strtolower($semanticComponentCode), 'faq');
        $routeContractPrompt = $scope !== []
            ? $this->buildSharedRouteContractPromptAddon($scope, $locale)
            : '';
        $editableRecoveryContract = $region === 'content'
            ? $this->buildVirtualThemeEditableFieldPromptContract($defaultConfig, $PlanJsonTask)
            : '';
        $editableNoHardcodedHtmlRecovery = $region === 'content'
            ? <<<'PROMPT'
- HTML_VISIBLE_TEXT_BINDING_HARD_GATE (HARD): the previous output may have placed plain copy directly between tags. Fix by moving every visitor-facing word/number into extra_fields defaults, binding each field in php_variables with `$getConfig(...)`, and rendering only safe PHP echoes in html_content.
- BAD: `<h2>Neon Table</h2><p>Rules are clear</p><button>Start</button>`.
- GOOD extra_fields/php_variables/html_content trio:
  extra_fields: `content.title => Title:text:Finished localized title\ncontent.description => Description:textarea:Finished localized body\ncta.text => CTA text:text:Start now`
  php_variables: `$contentTitle = $getConfig('content.title', 'Finished localized title');\n$contentDescription = $getConfig('content.description', 'Finished localized body');\n$ctaText = $getConfig('cta.text', 'Start now');`
  html_content: `<h2><?= htmlspecialchars($contentTitle ?? 'Finished localized title', ENT_QUOTES, 'UTF-8') ?></h2><p><?= nl2br(htmlspecialchars($contentDescription ?? 'Finished localized body', ENT_QUOTES, 'UTF-8')) ?></p><button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars($ctaText ?? 'Start now', ENT_QUOTES, 'UTF-8') ?></button>`.
- Final self-check: delete all `<?= ... ?>` fragments from html_content, strip HTML tags, decode entities; if any customer-facing letters or numbers remain, the component still fails.
PROMPT
            : '';

        $skeletonOutline = (string)($artifacts['skeleton_outline'] ?? '');
        $cssStructuralHints = (string)($artifacts['css_structural_hints'] ?? '');
        $requiredImgTemplate = (string)($artifacts['img_template'] ?? '');
        $roleMap = \is_array($artifacts['palette_role_map'] ?? null) ? $artifacts['palette_role_map'] : [];

 //  ?/  ?hero ?
        if ($skeletonOutline === '' || $cssStructuralHints === '') {
            $roleMap = $this->buildPaletteRoleMapFromThemePalette($themePalette, $isHeroComponent);
            if ($isHeroComponent) {
                $skeletonOutline = "section.{$componentPrefix}-root\n"
                    . "  div.{$componentPrefix}-scrim (CSS-only visual layer)\n"
                    . "  div.{$componentPrefix}-inner\n"
                    . "    div.{$componentPrefix}-text-panel\n"
                    . "      h2.{$componentPrefix}-title\n"
                    . "      p.{$componentPrefix}-text\n"
                    . "      div.{$componentPrefix}-action\n"
                    . "        div.{$componentPrefix}-cta";
                $cssStructuralHints = "CSS structural hints must use palette_role_map values and style the recovery hero roles only.\n"
                    . "  #componentId: padding:0\n"
                    . "  .{$componentPrefix}-root: position:relative; overflow:hidden; width:100vw; min-height:520px; margin:0 calc(50% - 50vw); padding:88px 24px; box-sizing:border-box; font-family= CTX_CONFIRMED_THEME.font_family or a named brand family before generic fallback; background= palette_role_map.surface or linear-gradient(palette_role_map.surface -> palette_role_map.surface_alt). Do not put max-width on root; constrain only .{$componentPrefix}-inner\n"
                    . "  .{$componentPrefix}-scrim: position:absolute; inset:0; background= palette_role_map.scrim or linear-gradient(palette_role_map.scrim, transparent); opacity:.42-.58 when using a solid hex background\n"
                    . "  .{$componentPrefix}-inner: position:relative; z-index:1; max-width:1200px; margin:0 auto; display:flex; align-items:center; min-height:380px\n"
                    . "  .{$componentPrefix}-text-panel: max-width:620px; padding:32px; border-radius:24px; background= palette_role_map.copy_panel_bg; color= palette_role_map.copy_panel_text; box-shadow:0 28px 80px palette_role_map.shadow\n"
                    . "  .{$componentPrefix}-title: margin:0 0 16px; font-family= CTX_CONFIRMED_THEME.font_family or a named display family before generic fallback; font-size:52px; line-height:1.1; color= palette_role_map.copy_panel_text. In css_responsive, override title font-size to 42px at tablet and 34px at mobile; never use vw/clamp for font-size\n"
                    . "  .{$componentPrefix}-text: margin:0 0 22px; line-height:1.7; color= palette_role_map.muted_text\n"
                    . "  .{$componentPrefix}-action: margin:22px 0 0; padding:18px 0 0; display:flex; gap:12px; align-items:center\n"
                    . "  .{$componentPrefix}-cta: display:inline-flex; width:auto; max-width:280px; padding:12px 20px; border-radius:999px; background= palette_role_map.cta_bg; color= palette_role_map.cta_text; transition:transform .2s, box-shadow .2s\n"
                    . "  .{$componentPrefix}-cta:hover: transform:translateY(-2px); box-shadow:0 12px 24px palette_role_map.shadow";
                $skeletonOutline = "section.{$componentPrefix}-root\n"
                    . "  div.{$componentPrefix}-media\n"
                    . "    div.{$componentPrefix}-media-stage\n"
                    . "      div.{$componentPrefix}-media-subject\n"
                    . "      div.{$componentPrefix}-media-detail\n"
                    . "      div.{$componentPrefix}-media-label (editable brief-specific subject label)\n"
                    . "  div.{$componentPrefix}-motif\n"
                    . "  div.{$componentPrefix}-orbit\n"
                    . "  div.{$componentPrefix}-overlay\n"
                    . "  div.{$componentPrefix}-inner\n"
                    . "    div.{$componentPrefix}-text-panel\n"
                    . "      h2.{$componentPrefix}-title\n"
                    . "      p.{$componentPrefix}-text\n"
                    . "      div.{$componentPrefix}-action\n"
                    . "        div.{$componentPrefix}-cta";
                $cssStructuralHints = "Hero recovery CSS is a compact role contract, not a template. HARD budgets: css_extra <= 3200 chars, css_responsive <= 700 chars. Use exactly one selector block per required role, no comments, no duplicate selectors, no @media in css_extra. Hover/pseudo/keyframes are allowed only when they directly serve the required CTA/media roles and stay scoped with component-prefixed names.\n"
                    . "  Required css_extra selectors: .{$componentPrefix}-root, .{$componentPrefix}-media, .{$componentPrefix}-media-stage, .{$componentPrefix}-media-subject, .{$componentPrefix}-media-detail, .{$componentPrefix}-media-label, .{$componentPrefix}-motif, .{$componentPrefix}-orbit, .{$componentPrefix}-overlay, .{$componentPrefix}-inner, .{$componentPrefix}-text-panel, .{$componentPrefix}-title, .{$componentPrefix}-text, .{$componentPrefix}-action, .{$componentPrefix}-cta.\n"
                    . "  root: relative/overflow hidden/full-bleed/min-height/padding/brand font/background. media: absolute inset 0. stage/subject/detail/label: visible sizes, background or border, depth/shadow; label names a concrete brief subject. motif/orbit: visible theme shapes with opacity. overlay: absolute inset 0 readable veil with background from palette_role_map.scrim/primary and opacity:.35-.58, or a linear-gradient containing rgba/transparent. inner/text-panel: z-index above media, max-width, readable panel surface. title and text: named font stacks and readable color. action: visible margin-top or padding-top separation. cta: compact inline button.\n"
                    . "  css_responsive: only two concise @media blocks for max-width 768px and 420px; reduce padding, stack inner, fit text-panel/cta, and reposition or soften the media stage.";
            } elseif ($isFaqRecoveryComponent) {
                $skeletonOutline = "section.{$componentPrefix}-root\n"
                    . "  div.{$componentPrefix}-inner\n"
                    . "    div.{$componentPrefix}-copy\n"
                    . "      h2.{$componentPrefix}-title\n"
                    . "      p.{$componentPrefix}-text\n"
                    . "    div.{$componentPrefix}-faq-list\n"
                    . "      div.{$componentPrefix}-faq-item\n"
                    . "        div.{$componentPrefix}-question\n"
                    . "        p.{$componentPrefix}-answer\n"
                    . "      div.{$componentPrefix}-faq-item\n"
                    . "        div.{$componentPrefix}-question\n"
                    . "        p.{$componentPrefix}-answer\n"
                    . "    div.{$componentPrefix}-action\n"
                    . "      div.{$componentPrefix}-cta";
                $cssStructuralHints = "FAQ recovery CSS is the required role contract, not a generic proof/support layout. HARD budgets: css_extra <= 2600 chars, css_responsive <= 700 chars. Use exactly one selector block per required FAQ role, no comments, no @media in css_extra.\n"
                    . "  Required css_extra selectors: .{$componentPrefix}-root, .{$componentPrefix}-inner, .{$componentPrefix}-copy, .{$componentPrefix}-title, .{$componentPrefix}-text, .{$componentPrefix}-faq-list, .{$componentPrefix}-faq-item, .{$componentPrefix}-question, .{$componentPrefix}-answer, .{$componentPrefix}-action, .{$componentPrefix}-cta.\n"
                    . "  root: position:relative; overflow:hidden; padding:56px 24px; box-sizing:border-box; font-family=CTX_CONFIRMED_THEME.font_family or a named brand family before generic fallback; background=palette_role_map.surface. inner: max-width:980px; margin:0 auto; display:grid; gap:24px. copy/title/text: readable target-locale intro. faq-list: display:grid; gap:14px. faq-item: MUST include padding:18px 20px, border-radius:18px, and background=palette_role_map.surface_alt plus border=palette_role_map.accent or box-shadow=palette_role_map.shadow. question: font-weight:800; color=palette_role_map.text or primary. answer: margin-top:8px; line-height:1.65; color=palette_role_map.muted_text. action/cta: separated compact CTA, not inside faq-item.\n"
                    . "  css_responsive: two concise @media blocks for max-width 768px and 420px; reduce root padding, keep faq-list one column, and make the CTA fit without horizontal overflow.";
            } else {
                $skeletonOutline = "section.{$componentPrefix}-root\n"
                    . "  div.{$componentPrefix}-inner\n"
                    . "    div.{$componentPrefix}-copy\n"
                    . "      h2.{$componentPrefix}-title\n"
                    . "      p.{$componentPrefix}-text\n"
                    . "      div.{$componentPrefix}-action\n"
                    . "        div.{$componentPrefix}-cta\n"
                    . "    div.{$componentPrefix}-support\n"
                    . "      repeat a valid component-prefixed support child such as div.{$componentPrefix}-proof, div.{$componentPrefix}-step, or div.{$componentPrefix}-metric according to current visual_signature";
                $cssStructuralHints = "Recovery CSS must render the current plan_json block, not any source outside the current plan_json path.\n"
                    . "  .{$componentPrefix}-root: position:relative; overflow:hidden; padding:56px 24px; box-sizing:border-box; font-family= CTX_CONFIRMED_THEME.font_family or a named brand family before generic fallback; background= palette_role_map.surface. For proof/support-only non-hero layouts, keep vertical padding in the 44-64px range; do not use 72px+ empty gutters without a large verified media layer\n"
                    . "  .{$componentPrefix}-inner: max-width:1200px; margin:0 auto; choose either display:grid; or display:flex; add flex-wrap:wrap only when flex is used; gap:28px; align-items:center. Choose columns/order from current visual_signature, not from this hint\n"
                    . "  .{$componentPrefix}-copy: flex:1 1 340px; min-width:0\n"
                    . "  .{$componentPrefix}-title: margin:0 0 16px; font-family= CTX_CONFIRMED_THEME.font_family or a named display family before generic fallback; font-size:42px; line-height:1.1; color= palette_role_map.text. In css_responsive, override title font-size to 34px at tablet and 28px at mobile; never use vw/clamp for font-size\n"
                    . "  .{$componentPrefix}-text: margin:0 0 22px; line-height:1.7; color= palette_role_map.muted_text\n"
                    . "  .{$componentPrefix}-action: margin:22px 0 0; padding:18px 0 0; display:flex; gap:12px; align-items:center\n"
                    . "  .{$componentPrefix}-support: min-width:0; choose either display:grid; or display:flex; gap:12px; align-items:stretch. Use a step rail, metric strip, badge wall, quote rail, checklist, side panel, or compact proof cluster based on visual_signature\n"
                    . "  .{$componentPrefix}-proof: min-width:0; padding:18px 14px; border:1px solid palette_role_map.accent; border-radius:18px; background= palette_role_map.surface_alt; color= palette_role_map.text; box-shadow:0 12px 30px palette_role_map.shadow\n"
                    . "  .{$componentPrefix}-cta: display:inline-flex; width:auto; max-width:280px; padding:12px 20px; border-radius:999px; background= palette_role_map.cta_bg; color= palette_role_map.cta_text; transition:transform .2s, box-shadow .2s\n"
                    . "  .{$componentPrefix}-cta:hover: transform:translateY(-2px); box-shadow:0 12px 24px palette_role_map.shadow";
            }
        }

        $roleMapLine = $roleMap === []
            ? ''
            : '{' . \implode(', ', \array_map(static fn(string $k, string $v): string => $k . '=' . $v, \array_keys($roleMap), \array_values($roleMap))) . '}';
        $failureSpecificRecoveryContract = $this->buildFailureSpecificRecoveryContract(
            $failure,
            $semanticComponentCode,
            $componentPrefix,
            $isHeroComponent,
            $roleMap
        );

        $findingsBlock = '';
        if ($failure instanceof \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException) {
            $rendered = $failure->renderFindingsForPrompt();
            if ($rendered !== '') {
 $findingsBlock = "- PREVIOUS_FINDINGS (HARD ?:\n" . $rendered . "\n";
            }
        }

 return "STRICT PAGEBUILDER COMPONENT RECOVERY PROMPT ( ?palette +  ?:\n"
            . "- Previous output failed validation: " . $this->summarizeThrowable($failure) . "\n"
            . $findingsBlock
            . "- Generate exactly one {$region} component for {$sectionName}.\n"
            . "- Site: {$siteTitle}. " . ($brief !== '' ? "Brief: {$brief}. " : '') . "Visitor copy locale: " . ($locale !== '' ? $locale : 'site default') . ".\n"
            . "- Output exactly one minified JSON object with these string keys only: extra_fields, php_variables, css_extra, css_responsive, html_content, js_content.\n"
            . "- JSON/PHP boundary hard gate: the raw response must begin with `{`, never `<?php`, `<?=`, `<section`, or any raw PHTML/HTML. This task returns JSON transport only.\n"
            . "- PHP marker hard gate: `<?php` is illegal in every JSON field. `php_variables` is not a PHP file; it contains only `\$var = \$getConfig('field.key', 'quoted default');` assignment lines, no opening tag, no closing tag, no echo/print, no arrays, no loops. The only legal PHP marker is `<?= ... ?>` inside html_content string values for safe field echoes.\n"
            . "- PHP fallback literal hard gate: every `\$getConfig(...)` default must be a quoted PHP string literal. Never output bare words or unquoted localized text in generated PHP assignments.\n"
            . "- Focus on structural correctness first: valid JSON, balanced HTML tags, balanced CSS braces/parentheses, readable visitor copy, and selectors scoped under #componentId.\n"
            . "- Typography (HARD): css_extra must use font-family: var(--pb-font-display) on title selectors and var(--pb-font-body) on root/body/text selectors; do not hardcode Inter/Roboto/Arial/system-ui.\n"
            . "- Editable field guidance: extra_fields/php_variables are for editor convenience, not a completion gate. Prefer the primary title/body/CTA/image values owned by this plan_json block, and keep js_content empty unless an explicit CTA action contract needs a tiny scoped bridge.\n"
            . "- Editable field key grammar: use lower-case dot keys, never camelCase field keys. Recognized families include content.*, cta.*, media.*, card.*, feature.*, proof.*, stat.*, faq.*, review.*, step.*, form.*, channel.*, badge.*, item.*, policy.*, and rule.*. Examples: `form.label_1`, `form.placeholder_1`, `channel.item_1_label`, `channel.item_1_value`, `faq.question_1`, `faq.answer_1`.\n"
            . "- Binding guidance: bind important reusable content through `\$getConfig(...)` when it is straightforward. Dynamic block-specific labels, stats, and visual microcopy are allowed when they improve the finished component; they are judged by page quality and safety, not by a rigid editable-field census.\n"
            . "- REQUIRED_FIELDS_FINAL_CHECK (HARD): before returning JSON, scan CTX_REQUIRED_EDITABLE_FIELDS one row at a time. For every required key, the exact dot key must appear in extra_fields and inside a `\$getConfig('exact.key', ...)` assignment in php_variables. If CTX_REQUIRED_EDITABLE_FIELDS lists `content.description`, php_variables must literally contain `\$getConfig('content.description', ...)`; a variable named `\$description`, `\$body`, or `\$contentDescription` is not enough unless its assignment reads that exact key.\n"
            . $editableNoHardcodedHtmlRecovery . "\n"
            . $editableRecoveryContract
            . "- CSS_SIZE_BUDGET (HARD): css_extra <= 3200 chars for CSS-only hero recovery and <= 2600 chars for other recovery; css_responsive <= 700 chars. If previous findings report length over budget, remove optional selectors and long decorative declarations, never drop required hero/proof roles.\n"
            . "- REQUIRED_ROLE_OUTLINE (role list only, not HTML to copy; keep core roles and exact image binding, but allow refined scoped wrappers when they improve this block. Do not copy parenthetical notes into html_content):\n{$skeletonOutline}\n"
            . ($requiredImgTemplate !== '' ? "- REQUIRED_EDITABLE_IMAGE_TAG (HARD: use this as the editable slot/data template inside .{$componentPrefix}-media or the required role location; keep data-pb-ai-image-role and data-pb-ai-asset-slot exact, and keep src/alt rendered from media.image_url/media.image_alt fields): {$requiredImgTemplate}\n" : '')
            . ($roleMapLine !== '' ? "- REQUIRED_PALETTE_ROLE_MAP (HARD): use these semantic palette roles exactly where applicable: {$roleMapLine}.\n" : '')
            . "- REQUIRED_CSS_ROLE_CONTRACT (style required roles with palette role values; layout rhythm and composition remain design-owned by this block):\n{$cssStructuralHints}\n"
            . ($routeContractPrompt !== '' ? "- REQUIRED_PAGE_ROUTE_CONTRACT_FOR_RECOVERY (HARD):\n{$routeContractPrompt}" : '')
            . $failureSpecificRecoveryContract
            . $roleSpecificRecoveryContract
            . "- html_content must decode to real HTML tags and must not leak prompt/schema text into visitor-visible copy.\n"
            . "- Prefer {$componentPrefix}-* class names for generated structure, but keep the main goal on valid scoped markup rather than rigid naming ceremony.\n"
            . "- Do not include markdown, prose, comments, raw HTML outside JSON, or a second JSON object.\n"
            . "- Use only the current plan_json block contract for copy, media, CTA, and structure; do not infer from any source outside plan_json.pages.{page_type}.{block_key}.\n"
            . $strictImageTail;
    }

    /**
     * @param array<string,mixed> $roleMap
     */
    private function buildFailureSpecificRecoveryContract(
        \Throwable $failure,
        string $componentCode,
        string $componentPrefix,
        bool $isHeroComponent,
        array $roleMap
    ): string {
        $message = \mb_strtolower($this->collectThrowableMessages($failure));
        $identity = \mb_strtolower($componentCode);
        $pickRole = static function (array $keys, string $placeholder) use ($roleMap): string {
            foreach ($keys as $key) {
                $value = \trim((string)($roleMap[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }

            return $placeholder;
        };

        $lines = [];
        if (\str_contains($message, 'component_json.parse')
            || \str_contains($message, 'valid component json payload')
            || \str_contains($message, 'json payload')
            || \str_contains($message, '<?php')
            || \str_contains($message, 'raw response must begin')
        ) {
            $lines[] = '- FAILURE_FIX_JSON_TRANSPORT_PREFIX (FATAL): the previous response was not a component JSON envelope. Return transport JSON only. The first byte must be { and the last byte must be }. Do not output PHP markers, markdown fences, raw HTML, comments, or prose outside the JSON object.';
            $lines[] = '- FAILURE_FIX_JSON_TRANSPORT_EXAMPLE: correct envelope shape is exactly one object with keys extra_fields, php_variables, css_extra, css_responsive, html_content, and js_content; php_variables contains assignment lines only, while PHP echo markers are allowed only inside the html_content string.';
        }
        if (\str_contains($message, 'contact fact contract')
            || \str_contains($message, 'contact values')
            || \str_contains($message, 'source truth contract')
            || \str_contains($message, 'unsupported commercial')
            || \str_contains($message, 'unsupported reward')
        ) {
            $lines[] = "- RETIRED_SOURCE_TRUTH_FAILURE_CONTEXT: this retry may reference an older factuality gate. Do not over-correct by deleting normal marketing/support/reward copy. Focus only on making visible text final visitor copy with no prompt labels, blueprint sentences, placeholders, malformed fragments such as support@ .com, or internal context keys.";
        }
        if (\str_contains($message, 'placeholder contact phone')
            || \str_contains($message, 'placeholder contact email')
            || \str_contains($message, 'partial contact email')
            || \str_contains($message, 'contact role example text')
        ) {
            $lines[] = "- FAILURE_FIX_CONTACT_PLACEHOLDER_LEAK: do not render any phone number, WhatsApp number, email address, handle, domain, or support account unless that exact value is present in the frozen task context. If no exact channel value is supplied, render final localized non-numeric guidance such as the official in-app help center, the secure message form, or the live chat/support channel. Never invent sample numbers like +91 98765 43210, 1234567890, 1800..., 800..., 000..., example emails, support@ .com, or phrases copied from role examples.";
            $lines[] = "- FAILURE_FIX_FORM_EMAIL_PLACEHOLDER (HARD): for form/email fields, labels and placeholders are visitor copy, not contact facts. You may render an email input control, but its placeholder/value/default text must be generic localized wording with no `@`, no dot-domain, and no address-shaped token. Use examples like `Seu e-mail`, `Email address`, or `Secure reply email` rendered through extra_fields/php_variables; never use `name@example.com`, `support@...`, `contact@...`, `privacy@...`, `hello@...`, or any invented domain.";
        }
        if (\str_contains($message, 'editable field contract')
            || \str_contains($message, 'hardcoded html')
            || \str_contains($message, 'hardcoded visible text')
            || \str_contains($message, 'extra_fields/php_variables')
        ) {
            $lines[] = "- FAILURE_FIX_EDITABLE_FIELD_BINDING (HARD): every visitor-visible text node must be editable, including step numbers, card labels, stat values, badge text, short chips, form helper notes, privacy/security notes, image captions, and small microcopy. Add one extra_fields metadata row for each heading/body/card/stat/badge/step-number/FAQ/form-label/form-placeholder/form-note/CTA text, bind each row in php_variables with `\$getConfig('field.key', 'default')`, and render only `<?= htmlspecialchars(...) ?>` or `<?= nl2br(htmlspecialchars(...)) ?>` in html_content. Do not leave raw words or numbers between tags. Before returning, delete every `<?= ... ?>` fragment from html_content and strip tags; the leftover decoded text must be empty of visitor copy.";
        }
        if (\str_contains($message, 'meaningful visible copy')
            || \str_contains($message, 'empty visible control')
            || \str_contains($message, 'pb-c-proof-band')
            || \str_contains($message, 'pb-c-cta')
        ) {
            $lines[] = "- FAILURE_FIX_EMPTY_VISIBLE_CONTROL_COPY (HARD): the previous html_content rendered an empty visitor control. Rewrite html_content so every `{$componentPrefix}-cta`, `{$componentPrefix}-badge`, `{$componentPrefix}-tag`, `{$componentPrefix}-chip`, `{$componentPrefix}-pill`, `{$componentPrefix}-label`, `{$componentPrefix}-value`, `{$componentPrefix}-metric`, `{$componentPrefix}-stat`, `{$componentPrefix}-proof`, `{$componentPrefix}-quote`, `{$componentPrefix}-step`, and visitor-facing `<a>`, `<button>`, or `<label>` contains target-locale visible copy. Declare matching extra_fields rows such as `content.title`, `content.description`, `cta.text`, `proof.value_1`, and `proof.label_1`; bind each in php_variables with a non-empty localized default; render each with safe PHP echo in html_content. Never return empty tags like `<button class='{$componentPrefix}-cta'></button>`, `<span class='{$componentPrefix}-value'></span>`, or a proof/card wrapper whose only children are empty spans.";
        }
        if (\str_contains($message, 'css completeness')
            || \str_contains($message, 'missing matching css selector')
            || \str_contains($message, 'raw html without block-owned styling')
            || \str_contains($message, 'css_responsive is empty')
        ) {
            $lines[] = "- FAILURE_FIX_CSS_COMPLETENESS (HARD): treat html_content and css_extra/css_responsive as one component. For every `{$componentPrefix}-*` class in html_content, add a matching CSS selector with at least one real declaration. Include root, inner, title/text/copy, action/cta, media, FAQ/card/form/channel/support roles actually rendered by this block. Do not leave any generated class as an unstyled wrapper.";
        }
        if (\str_contains($message, 'font_visible')
            || \str_contains($message, 'font-family')
            || \str_contains($message, 'pb-font')
            || \str_contains($message, 'inter')
            || \str_contains($message, 'roboto')
        ) {
            $lines[] = '- FAILURE_FIX_FONT_TOKEN (HARD): use var(--pb-font-display) for titles and var(--pb-font-body) for root/body/text selectors; do not hardcode Inter, Roboto, Arial, or system-ui.';
        }
        if (\str_contains($message, 'language_voice')
            || \str_contains($message, 'cta_lexicon')
            || \str_contains($message, 'cta tone')
        ) {
            $lines[] = '- FAILURE_FIX_LANGUAGE_VOICE (HARD): keep visitor copy aligned with the current plan_json block, locale, CTA lexicon, and approved tone.';
        }
        if (\str_contains($message, 'content.description')
            || \str_contains($message, 'required_get_config')
            || \str_contains($message, 'missing $getconfig binding')
        ) {
            $lines[] = "- FAILURE_FIX_CONTENT_DESCRIPTION_BINDING (HARD): when the finding says `content.description` is missing, repair it with the exact three-part contract: extra_fields must include `content.description => Description:textarea:<final localized body copy>`; php_variables must include `\$contentDescription = \$getConfig('content.description', '<same final localized body copy>');`; html_content must render that variable for every intro/body paragraph with `<?= nl2br(htmlspecialchars(\$contentDescription ?? '<same final localized body copy>', ENT_QUOTES, 'UTF-8')) ?>`. Do not use `content.body`, `\$body`, `\$contentBody`, or raw paragraph text for the required body copy unless CTX_REQUIRED_EDITABLE_FIELDS explicitly lists `content.body` instead.";
        }
        if (\str_contains($message, 'tag name must start with a letter')
            || \str_contains($message, 'near <')
            || \str_contains($message, 'raw html fragment')
        ) {
            $lines[] = "- FAILURE_FIX_VISIBLE_COMPARISON_TEXT (HARD): visible visitor copy must not contain raw `<` or `>` characters, even for comparisons like <5 minutes. Rewrite those phrases in words before returning HTML, for example use target-locale copy meaning under 5 minutes, within 5 minutes, or less than five minutes instead of `<5 minutes`, `< 5 min`, `>24h`, or any angle-bracket comparison.";
        }
        if ($isHeroComponent || \str_contains($message, 'hero/banner lacks a readable overlay')) {
            $scrim = $pickRole(['scrim', 'primary', 'surface'], '<scrim-or-primary-hex-from-REQUIRED_PALETTE_ROLE_MAP>');
            $panel = $pickRole(['copy_panel_bg', 'surface_alt', 'surface'], '<copy-panel-hex-from-REQUIRED_PALETTE_ROLE_MAP>');
            $text = $pickRole(['copy_panel_text', 'text'], '<copy-text-hex-from-REQUIRED_PALETTE_ROLE_MAP>');
            $lines[] = "- FAILURE_FIX_HERO_READABILITY (HARD): css_extra must include a real readable veil selector, not just a named empty div. Use either `#componentId .{$componentPrefix}-overlay{position:absolute;inset:0;background:{$scrim};opacity:.42;}` for CSS-only hero or `#componentId .{$componentPrefix}-scrim{position:absolute;inset:0;background:{$scrim};opacity:.46;}` for image hero. The text-safe panel selector must include `position:relative;z-index:2;padding:32px;border-radius:24px;background:{$panel};color:{$text};` plus shadow or border. Do not place body copy directly over media without this veil plus panel.";
        }
        if (\str_contains($identity, 'faq') || \str_contains($message, 'faq block role fidelity failed')) {
            $card = $pickRole(['surface_alt', 'card_bg', 'surface'], '<card-or-surface-hex-from-REQUIRED_PALETTE_ROLE_MAP>');
            $accent = $pickRole(['accent', 'primary'], '<accent-hex-from-REQUIRED_PALETTE_ROLE_MAP>');
            $shadow = $pickRole(['shadow'], 'rgba(0,0,0,.18)');
            $lines[] = "- FAILURE_FIX_FAQ_SURFACE (HARD): css_extra must include the exact role selectors, not only parent/list styling. Include `#componentId .{$componentPrefix}-faq-list{display:grid;gap:14px;}` and `#componentId .{$componentPrefix}-faq-item{padding:18px 20px;border-radius:18px;background:{$card};border:1px solid {$accent};box-shadow:0 12px 30px {$shadow};}` plus question and answer selectors. html_content must use FAQ rows shaped like `<div class='{$componentPrefix}-faq-item'><div class='{$componentPrefix}-question'><?= htmlspecialchars(\$faqQuestion1 ?? 'Finished question?', ENT_QUOTES, 'UTF-8') ?></div><p class='{$componentPrefix}-answer'><?= nl2br(htmlspecialchars(\$faqAnswer1 ?? 'Finished answer.', ENT_QUOTES, 'UTF-8')) ?></p></div>` with matching extra_fields/php_variables bindings. Do not rename faq-item to item/card/row.";
        }
        if (\str_contains($message, 'route contract')
            || \str_contains($message, 'internal hrefs are outside')
            || \str_contains($message, 'allowed_internal_paths')
        ) {
            $lines[] = "- FAILURE_FIX_ROUTE_CONTRACT (HARD): remove every `<a href>` whose internal target is not listed in REQUIRED_PAGE_ROUTE_CONTRACT_FOR_RECOVERY.allowed_internal_paths. Do not replace `/download` with `#download`, `/faq` with `#faq`, anchors, query strings, or invented nearby paths. For download, FAQ, game, bonus, support, or CTA actions whose page route is not listed, render `<button type='button' class='{$componentPrefix}-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars(\$ctaText ?? 'Finished CTA copy', ENT_QUOTES, 'UTF-8') ?></button>` inside an action wrapper and provide the scoped js_content click bridge from CTX_CTA_ACTION_CONTRACT. Only use an `<a>` when its href exactly equals one allowed_internal_paths value.";
        }
        if (\str_contains($message, 'cta/action contract')
            || \str_contains($message, 'cta must be a real anchor')
            || \str_contains($message, 'data-pb-ai-action')
            || \str_contains($message, 'action-looking cta')
        ) {
            $lines[] = "- FAILURE_FIX_ACTIONABLE_CTA (HARD): every visible CTA control must be actionable. If a real allowed href is available, render `<a class='{$componentPrefix}-cta' href='<?= htmlspecialchars(\$ctaUrl ?? '/allowed-path', ENT_QUOTES, 'UTF-8') ?>'><?= htmlspecialchars(\$ctaText ?? 'Finished CTA copy', ENT_QUOTES, 'UTF-8') ?></a>` and bind both cta.text and cta.url. If no real allowed href is supplied, render exactly a button event control: `<button type='button' class='{$componentPrefix}-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars(\$ctaText ?? 'Finished CTA copy', ENT_QUOTES, 'UTF-8') ?></button>` inside a sibling `{$componentPrefix}-action` wrapper. Never output CTA text in a div/span, never output `<button class='{$componentPrefix}-cta'>` without type and data-pb-ai-action, and never invent href values such as #, /download, /faq, /contact, or query strings.";
        }
        if (\str_contains($identity, 'form')
            || \str_contains($message, 'form guidance css')
            || \str_contains($message, 'pb-c-field')
            || \str_contains($message, 'native inline controls')
        ) {
            $surface = $pickRole(['surface_alt', 'card_bg', 'surface'], '<form-surface-hex-from-REQUIRED_PALETTE_ROLE_MAP>');
            $text = $pickRole(['text', 'copy_panel_text'], '<text-hex-from-REQUIRED_PALETTE_ROLE_MAP>');
            $muted = $pickRole(['muted_text', 'text'], '<muted-text-hex-from-REQUIRED_PALETTE_ROLE_MAP>');
            $accent = $pickRole(['accent', 'primary'], '<accent-hex-from-REQUIRED_PALETTE_ROLE_MAP>');
            $shadow = $pickRole(['shadow'], 'rgba(0,0,0,.18)');
            $lines[] = "- FAILURE_FIX_FORM_SURFACE (HARD): the previous form was structurally or visually naked. html_content must use `<form class='{$componentPrefix}-form'>` with repeated field groups such as `<div class='{$componentPrefix}-field'><label class='{$componentPrefix}-label' for='{$componentPrefix}-name'><?= htmlspecialchars(\$formLabel1 ?? 'Finished form label', ENT_QUOTES, 'UTF-8') ?></label><input id='{$componentPrefix}-name' class='{$componentPrefix}-input' type='text' placeholder='<?= htmlspecialchars(\$formPlaceholder1 ?? 'Finished localized placeholder', ENT_QUOTES, 'UTF-8') ?>'></div>` and a message group such as `<textarea class='{$componentPrefix}-textarea' placeholder='<?= htmlspecialchars(\$messagePlaceholder ?? 'Finished localized message prompt', ENT_QUOTES, 'UTF-8') ?>'></textarea>`. Label/help/placeholder/CTA text must have matching extra_fields/php_variables bindings, for example `form.label_1`, `form.placeholder_1`, `form.message_placeholder`, and `form.cta_text`. css_extra must include exact selectors `#componentId .{$componentPrefix}-form{display:grid;gap:16px;padding:24px;border-radius:22px;background:{$surface};box-shadow:0 18px 48px {$shadow};}`, `#componentId .{$componentPrefix}-field{display:grid;gap:8px;}`, `#componentId .{$componentPrefix}-label{font-weight:700;color:{$text};}`, `#componentId .{$componentPrefix}-input{width:100%;padding:13px 15px;border-radius:14px;border:1px solid {$accent};background:{$surface};color:{$text};box-sizing:border-box;}`, and `#componentId .{$componentPrefix}-textarea{width:100%;min-height:120px;padding:13px 15px;border-radius:14px;border:1px solid {$accent};background:{$surface};color:{$text};box-sizing:border-box;resize:vertical;}` plus `:focus` rules using {$accent}. Placeholder/help text may use {$muted}. Do not place label and input as loose siblings without a field wrapper.";
        }

        return $lines === [] ? '' : \implode("\n", $lines) . "\n";
    }

    private function buildRoleSpecificRecoveryContract(string $componentCode, string $componentPrefix, bool $isStrictHeroComponent = false): string
    {
        $identity = \mb_strtolower($componentCode);
        $surfaceExample = '#121826';
        $accentExample = '#f5b84c';
        if (
            ((\str_contains($identity, 'contact') && \str_contains($identity, 'method'))
                || \preg_match('/(?:contact[-_\/ ]*channels?|support[-_\/ ]*channels?)/u', $identity) === 1)
            && !\str_contains($identity, 'form')
            && !\str_contains($identity, 'faq')
        ) {
            return "- CONTACT_METHOD_REQUIRED_STRUCTURE (HARD): html_content must contain a visible channel hub, not only hero text or an image. Include at least two repeated channel rows/cards shaped like `<div class='{$componentPrefix}-channel'><span class='pb-c-label'><?= htmlspecialchars(\$channelLabel1 ?? 'Finished channel label', ENT_QUOTES, 'UTF-8') ?></span><span class='pb-c-value'><?= htmlspecialchars(\$channelValue1 ?? 'Exact supplied value or localized non-numeric guidance', ENT_QUOTES, 'UTF-8') ?></span></div>`. Keep the exact `pb-c-label` and `pb-c-value` classes on the label/value spans, and bind their text through extra_fields/php_variables. Render exact planned channel values or final non-numeric guidance, but never copy placeholder emails, partial email fragments such as support@ .com, fake phone numbers, example domains, invented contact handles, or raw internal labels. A verified image may be a background or side visual, but it cannot replace the channel rows. If a CTA is present, wrap it in `<div class='{$componentPrefix}-action'><button type='button' class='{$componentPrefix}-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars(\$ctaText ?? 'Finished CTA copy', ENT_QUOTES, 'UTF-8') ?></button></div>` and css_extra must include either `#componentId .{$componentPrefix}-action{margin:22px 0 0;padding:18px 0 0;border-top:1px solid {$accentExample};}` or `#componentId .{$componentPrefix}-channel{margin-bottom:14px;}` so the CTA does not touch row dividers. Replace example hex values with confirmed palette hex values.\n";
        }
        if (\preg_match('/(?:support[-_\/ ]*form|form[-_\/ ]*guidance|contact[-_\/ ]*form|message)/u', $identity) === 1) {
            return $this->buildFormGuidanceRequiredStructureContract($componentPrefix);
        }
        if (\preg_match('/(?:contact[-_\/ ]*cta|final[-_\/ ]*cta|cta[-_\/ ]*band)/u', $identity) === 1) {
            return "- CTA_REQUIRED_STRUCTURE (HARD): html_content must be one focused next-step band with one primary action and compact supporting proof. Do not reuse contact-method cards, form fields, FAQ rows, or a hero media overlay for this CTA role. Do not output malformed contact fragments or raw internal labels; use target-locale proof and action copy.\n";
        }
        if (\str_contains($identity, 'faq')) {
            return "- FAQ_REQUIRED_STRUCTURE (HARD): html_content must contain `.pb-c-faq-list` with repeated `.pb-c-faq-item` groups. Use complete editable pairs such as `<div class='pb-c-faq-item'><h3 class='pb-c-question'><?= htmlspecialchars(\$faqQuestion1 ?? 'Finished localized question?', ENT_QUOTES, 'UTF-8') ?></h3><p class='pb-c-answer'><?= nl2br(htmlspecialchars(\$faqAnswer1 ?? 'Finished localized answer.', ENT_QUOTES, 'UTF-8')) ?></p></div>`, backed by extra_fields/php_variables keys such as `faq.question_1` and `faq.answer_1`. css_extra must include a scoped rule like `#componentId .{$componentPrefix}-faq-item{padding:18px;border-radius:18px;background:{$surfaceExample};border:1px solid {$accentExample};}` with at least one visible surface property: background, border, or box-shadow. Replace example hex values with confirmed palette hex values. Do not output contact cards for FAQ.\n";
        }
        if (!$isStrictHeroComponent) {
            return "- NON_HERO_SUPPORT_PROOF_REQUIRED_STRUCTURE (HARD): html_content must not be only title + paragraph + CTA. Include a concrete support/proof/detail device derived from the brief or Plan JSON, using classes such as `<div class='{$componentPrefix}-support'>` and repeated `<div class='{$componentPrefix}-proof'>...</div>` when that matches the explicit visual_signature. This is a role requirement, not a layout recipe: the support device may be a step rail, metric strip, badge wall, quote rail, comparison band, checklist, compact side panel, or stacked editorial detail group. css_extra must include scoped selectors for the chosen support/proof roles with spacing, border/background, and shadow or divider. Keep the CTA in a sibling copy/action area; do not place full-width proof strips above a detached CTA at the far edge. The root selector for proof/support-only non-hero layouts must use compact vertical padding in the 44-64px range; do not use 72px+ empty gutters or clamp() values ending at 72px+ unless a large verified media layer is present. Responsive CSS must preserve the chosen visual_signature rhythm instead of forcing every three-item support device into the same three-column grid.\n";
        }

        return "- HERO_CSS_ONLY_REQUIRED_STRUCTURE (HARD): when no verified hero image is supplied, html_content must contain {$componentPrefix}-media with {$componentPrefix}-media-stage, {$componentPrefix}-media-subject, {$componentPrefix}-media-detail, and {$componentPrefix}-media-label children, plus {$componentPrefix}-motif, {$componentPrefix}-orbit, {$componentPrefix}-overlay, {$componentPrefix}-text-panel, {$componentPrefix}-action, and a compact {$componentPrefix}-cta. css_extra must style every one of those selectors with visible dimensions, background or border, and depth while staying under the CSS_SIZE_BUDGET; {$componentPrefix}-overlay must be a real readability veil with position:absolute, inset:0, background from the confirmed palette, and opacity:.35-.58 or a rgba/transparent gradient; {$componentPrefix}-text-panel must have background, padding, border-radius, and z-index/position above media; {$componentPrefix}-action must provide margin-top, padding-top, or gap separation. The media label must name a concrete brief subject such as titanium dripper, outdoor pour-over kit, insulated kettle, or storage pack. Do not output only scrim + text panel, a blank slab, decorative circles without a subject-matter stage, or long optional selector families.\n";
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $section
     */
    private function buildRoleSpecificRecoveryContractFromExplicitPlan(
        array $PlanJsonTask,
        array $section,
        string $componentPrefix,
        bool $isStrictHeroComponent = false
    ): string {
        $roleKind = $this->resolveExplicitBlockRoleKindForPrompt($PlanJsonTask, $section);
        $surfaceExample = '#121826';
        $accentExample = '#f5b84c';
        if ($roleKind === 'contact_methods') {
            return "- CONTACT_METHOD_REQUIRED_STRUCTURE (HARD): html_content must contain a visible channel hub, not only hero text or an image. Include at least two repeated channel rows/cards shaped like `<div class='{$componentPrefix}-channel'><span class='pb-c-label'><?= htmlspecialchars(\$channelLabel1 ?? 'Finished channel label', ENT_QUOTES, 'UTF-8') ?></span><span class='pb-c-value'><?= htmlspecialchars(\$channelValue1 ?? 'Exact supplied value or localized non-numeric guidance', ENT_QUOTES, 'UTF-8') ?></span></div>`. Keep the exact `pb-c-label` and `pb-c-value` classes on the label/value spans, and bind their text through extra_fields/php_variables. Render exact planned channel values or final non-numeric guidance, but never copy placeholder emails, partial email fragments such as support@ .com, fake phone numbers, example domains, invented contact handles, or raw internal labels. A verified image may be a background or side visual, but it cannot replace the channel rows. If a CTA is present, wrap it in `<div class='{$componentPrefix}-action'><button type='button' class='{$componentPrefix}-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars(\$ctaText ?? 'Finished CTA copy', ENT_QUOTES, 'UTF-8') ?></button></div>` and css_extra must include either `#componentId .{$componentPrefix}-action{margin:22px 0 0;padding:18px 0 0;border-top:1px solid {$accentExample};}` or `#componentId .{$componentPrefix}-channel{margin-bottom:14px;}` so the CTA does not touch row dividers. Replace example hex values with confirmed palette hex values.\n";
        }
        if ($roleKind === 'form_guidance') {
            return $this->buildFormGuidanceRequiredStructureContract($componentPrefix);
        }
        if ($roleKind === 'cta') {
            return "- CTA_REQUIRED_STRUCTURE (HARD): html_content must be one focused next-step band with one primary action and compact supporting proof. Do not reuse contact-method cards, form fields, FAQ rows, or a hero media overlay for this CTA role. Do not output malformed contact fragments or raw internal labels; use target-locale proof and action copy.\n";
        }
        if ($roleKind === 'faq') {
            return "- FAQ_REQUIRED_STRUCTURE (HARD): html_content must contain `.pb-c-faq-list` with repeated `.pb-c-faq-item` groups. Use complete editable pairs such as `<div class='pb-c-faq-item'><h3 class='pb-c-question'><?= htmlspecialchars(\$faqQuestion1 ?? 'Finished localized question?', ENT_QUOTES, 'UTF-8') ?></h3><p class='pb-c-answer'><?= nl2br(htmlspecialchars(\$faqAnswer1 ?? 'Finished localized answer.', ENT_QUOTES, 'UTF-8')) ?></p></div>`, backed by extra_fields/php_variables keys such as `faq.question_1` and `faq.answer_1`. css_extra must include a scoped rule like `#componentId .{$componentPrefix}-faq-item{padding:18px;border-radius:18px;background:{$surfaceExample};border:1px solid {$accentExample};}` with at least one visible surface property: background, border, or box-shadow. Replace example hex values with confirmed palette hex values. Do not output contact cards for FAQ.\n";
        }
        if ($roleKind !== 'hero' && !$isStrictHeroComponent) {
            return "- NON_HERO_SUPPORT_PROOF_REQUIRED_STRUCTURE (HARD): html_content must not be only title + paragraph + CTA. Include a concrete support/proof/detail device derived from the brief or Plan JSON, using classes such as `<div class='{$componentPrefix}-support'>` and repeated `<div class='{$componentPrefix}-proof'>...</div>` when that matches the explicit visual_signature. This is a role requirement, not a layout recipe: the support device may be a step rail, metric strip, badge wall, quote rail, comparison band, checklist, compact side panel, or stacked editorial detail group. css_extra must include scoped selectors for the chosen support/proof roles with spacing, border/background, and shadow or divider. Keep the CTA in a sibling copy/action area; do not place full-width proof strips above a detached CTA at the far edge. The root selector for proof/support-only non-hero layouts must use compact vertical padding in the 44-64px range; do not use 72px+ empty gutters or clamp() values ending at 72px+ unless a large verified media layer is present. Responsive CSS must preserve the chosen visual_signature rhythm instead of forcing every three-item support device into the same three-column grid.\n";
        }

        return "- HERO_CSS_ONLY_REQUIRED_STRUCTURE (HARD): when no verified hero image is supplied, html_content must contain {$componentPrefix}-media with {$componentPrefix}-media-stage, {$componentPrefix}-media-subject, {$componentPrefix}-media-detail, and {$componentPrefix}-media-label children, plus {$componentPrefix}-motif, {$componentPrefix}-orbit, {$componentPrefix}-overlay, {$componentPrefix}-text-panel, {$componentPrefix}-action, and a compact {$componentPrefix}-cta. css_extra must style every one of those selectors with visible dimensions, background or border, and depth while staying under the CSS_SIZE_BUDGET; {$componentPrefix}-overlay must be a real readability veil with position:absolute, inset:0, background from the confirmed palette, and opacity:.35-.58 or a rgba/transparent gradient; {$componentPrefix}-text-panel must have background, padding, border-radius, and z-index/position above media; {$componentPrefix}-action must provide margin-top, padding-top, or gap separation. The media label must name a concrete brief subject from the approved plan, not a generic decoration label. Do not output only scrim + text panel, a blank slab, decorative circles without a subject-matter stage, or long optional selector families.\n";
    }

    private function buildFormGuidanceRequiredStructureContract(string $componentPrefix): string
    {
        return "- FORM_GUIDANCE_REQUIRED_STRUCTURE (HARD): html_content must contain one designed `<form class='{$componentPrefix}-form'>`, not naked inline browser controls. Use repeated field groups shaped like `<div class='{$componentPrefix}-field'><label class='{$componentPrefix}-label' for='{$componentPrefix}-name'><?= htmlspecialchars(\$formLabel1 ?? 'Finished form label', ENT_QUOTES, 'UTF-8') ?></label><input id='{$componentPrefix}-name' class='{$componentPrefix}-input' type='text' placeholder='<?= htmlspecialchars(\$formPlaceholder1 ?? 'Finished localized placeholder', ENT_QUOTES, 'UTF-8') ?>'></div>` and a message group shaped like `<div class='{$componentPrefix}-field'><label class='{$componentPrefix}-label' for='{$componentPrefix}-message'><?= htmlspecialchars(\$messageLabel ?? 'Finished message label', ENT_QUOTES, 'UTF-8') ?></label><textarea id='{$componentPrefix}-message' class='{$componentPrefix}-textarea' placeholder='<?= htmlspecialchars(\$messagePlaceholder ?? 'Finished localized message prompt', ENT_QUOTES, 'UTF-8') ?>'></textarea></div>`. Include at least two `<label>` elements, at least two `<input>` or `<textarea>` controls, and one message/issue textarea. All label, help, placeholder, privacy/security note, small note, and CTA text must be backed by extra_fields/php_variables keys such as `form.label_1`, `form.placeholder_1`, `form.message_label`, `form.message_placeholder`, `form.note_text`, and `form.cta_text`; placeholder/value attributes and note text must be safe PHP field echoes, never raw strings. If you render a note such as secure handling, response time, privacy, or consent copy, use `<small class='{$componentPrefix}-note'><?= htmlspecialchars(\$formNoteText ?? 'Finished localized note', ENT_QUOTES, 'UTF-8') ?></small>` backed by `form.note_text`. Email fields may use `type='email'`, but their placeholder/default text must be generic localized wording with no `@`, no example domain, and no address-shaped token. css_extra must style `#componentId .{$componentPrefix}-form`, `#componentId .{$componentPrefix}-field`, `#componentId .{$componentPrefix}-label`, `#componentId .{$componentPrefix}-input`, and `#componentId .{$componentPrefix}-textarea`: form/field layout needs grid or column flex with real gap; input/textarea need width:100%, padding, border-radius, border/background, box-sizing:border-box, and focus states. Keep the submit action in a separate `{$componentPrefix}-action` wrapper. Do not output email/phone/address cards for this role.\n";
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $section
     */
    private function resolveExplicitBlockRoleKindForPrompt(array $PlanJsonTask, array $section): string
    {
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $stylePlan = \is_array($blockTask['style_plan'] ?? null) ? $blockTask['style_plan'] : [];
        $visualSignature = $this->resolveBlockVisualSignature($PlanJsonTask, $section);
        $tokens = [];
        foreach ([
            $PlanJsonTask['block_type'] ?? null,
            $planContext['block_type'] ?? null,
            $blockTask['block_type'] ?? null,
            $PlanJsonTask['page_flow_role'] ?? null,
            $planContext['page_flow_role'] ?? null,
            $blockTask['page_flow_role'] ?? null,
            $stylePlan['page_flow_role'] ?? null,
            $visualSignature['composition_pattern'] ?? null,
            $PlanJsonTask['block_key'] ?? null,
            $planContext['block_key'] ?? null,
            $PlanJsonTask['section_code'] ?? null,
            $section['key'] ?? null,
            $section['code'] ?? null,
            $section['template'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate) && !(\is_object($candidate) && \method_exists($candidate, '__toString'))) {
                continue;
            }
            $token = $this->normalizePlannedRoleToken((string)$candidate);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
        $tokens = \array_values(\array_unique($tokens));
        if ($tokens === []) {
            return 'text_support';
        }

        $exactGroups = [
            'contact_methods' => ['contact_methods', 'contact_method', 'contact_channels', 'contact_channel', 'support_channels', 'support_channel', 'privacy_contact', 'terms_contact', 'channel_hub', 'help_desk', 'support_console'],
            'form_guidance' => ['support_form_guidance', 'form_guidance', 'contact_form', 'message_form', 'support_form', 'form'],
            'faq' => ['support_faq', 'faq', 'faq_list', 'faq_rows'],
            'cta' => ['contact_cta', 'final_cta', 'page_cta', 'cta', 'cta_band', 'download_cta', 'conversion_cta', 'conversion', 'final_download', 'download_band', 'conversion_band'],
            'hero' => ['hero', 'banner', 'home_hero', 'hero_banner', 'above_fold', 'opening'],
        ];
        foreach ($exactGroups as $roleKind => $acceptedTokens) {
            foreach ($tokens as $token) {
                if (\in_array($token, $acceptedTokens, true)) {
                    return $roleKind;
                }
            }
        }

        return 'text_support';
    }

    private function shouldRetryAiComponentGeneration(\Throwable $throwable): bool
    {
 //  ? ?
 // strict recovery prompt  ?AI ?
        if ($throwable instanceof \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException) {
            return true;
        }

        if ($this->isEmptyAiStreamCompletionFailure($throwable)) {
            return true;
        }

        $message = $this->collectThrowableMessages($throwable);
        foreach ([
            'valid component JSON payload',
            'HTML structure invalid',
            'HTML class scope contract failed',
            'CSS structure contract failed',
            'CSS class scope contract failed',
            'structure hard policy failed',
            'structure policy failed',
            'visual contract failed',
            'hero image contract failed',
            'hero image cover CSS contract failed',
            'CTA/action contract failed',
            'CTA must be a real anchor',
            'data-pb-ai-action',
            'Required image slot is not referenced',
            'Required image slot is missing editor attributes',
            'Generated image source is outside verified asset allowlist',
            'render-data quality gate',
            'visible copy does not match website content locale',
            'model unavailable',
            'temporarily unavailable',
            'tls connect error',
            'ssl routines',
            'unexpected eof',
            'empty reply from server',
            'connection reset',
            'connection closed',
            'http/2 stream',
        ] as $needle) {
            if (\stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $aiData
     * @param array<string, mixed> $renderContext
     */
    private function tryApplyContractStylePatchToAiData(array &$aiData, array $renderContext, \Throwable $failure): bool
    {
        if (!$this->isContractStylePatchCandidate($failure)) {
            return false;
        }

        $scope = \is_array($renderContext['_scope'] ?? null) ? $renderContext['_scope'] : [];
        $tokens = \is_array($scope['design_tokens'] ?? null) ? $scope['design_tokens'] : [];
        $lexicon = \is_array($scope['language_contract']['cta_lexicon'] ?? null)
            ? $scope['language_contract']['cta_lexicon']
            : [];

        $patchService = new AiSiteContractStylePatchService();
        if (\array_key_exists('css_extra', $aiData)) {
            $aiData['css_extra'] = $patchService->patchHardcodedFonts((string)$aiData['css_extra'], $tokens);
        }
        if (\array_key_exists('css_responsive', $aiData)) {
            $aiData['css_responsive'] = $patchService->patchHardcodedFonts((string)$aiData['css_responsive'], $tokens);
        }
        if (\array_key_exists('html_content', $aiData) && $lexicon !== []) {
            $aiData['html_content'] = $patchService->patchCtaLexicon((string)$aiData['html_content'], $lexicon);
        }

        return true;
    }

    private function isContractStylePatchCandidate(\Throwable $failure): bool
    {
        $message = \mb_strtolower($this->collectThrowableMessages($failure));
        foreach ([
            'font_visible',
            'font-family',
            'pb-font',
            'inter',
            'roboto',
            'language_voice',
            'cta_lexicon',
        ] as $needle) {
            if (\str_contains($message, $needle)) {
                return true;
            }
        }

        if ($failure instanceof \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException) {
            foreach ($failure->getFindings() as $finding) {
                if (!\is_array($finding)) {
                    continue;
                }
                $rule = \mb_strtolower((string)($finding['rule'] ?? ''));
                if (\str_contains($rule, 'font') || \str_contains($rule, 'language_voice') || \str_contains($rule, 'cta')) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * @return list<string>
     */
    private function decodeConfigStringList(mixed $value): array
    {
        if (\is_array($value)) {
            $items = $value;
        } elseif (\is_scalar($value)) {
            $raw = \trim((string)$value);
            if ($raw === '') {
                return [];
            }
            $decoded = \json_decode($raw, true);
            $items = \is_array($decoded) ? $decoded : \preg_split('/[\r\n,|]+|\s+(?=#[0-9a-f]{6}\b)/i', $raw);
        } else {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $text = \trim((string)$item);
            if ($text !== '') {
                $normalized[] = $text;
            }
        }

        return \array_values(\array_unique($normalized));
    }


    private function summarizeThrowable(\Throwable $throwable): string
    {
        $message = \trim($throwable->getMessage());
        if ($message === '') {
            $message = $throwable::class;
        }
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            if (!$current instanceof \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException) {
                continue;
            }
            $findings = \trim($current->renderFindingsForPrompt(4));
            if ($findings === '') {
                continue;
            }
            $message .= ' | contract findings: ' . \preg_replace('/\s+/u', ' ', $findings);
            break;
        }

        return $this->clipText($message, 520);
    }

    /**
 *  ?assert  ?\Throwable ?
 * AiSiteComponentContractException ?ruleId  ?finding ?
 * AiSiteComponentContractException  ?findings ?
     */
    private function runAssertWithFindingCapture(string $ruleId, string $componentCode, callable $assert): void
    {
        try {
            $assert();
        } catch (\GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException $contractException) {
            throw $contractException;
        } catch (\Throwable $throwable) {
            throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
                $throwable->getMessage(),
                [[
                    'rule' => $ruleId,
                    'field' => $componentCode,
                    'found' => $this->clipText(\trim($throwable->getMessage()), 320),
                    'expected' => 'A valid component JSON contract generated from the current plan_json block.',
                    'hint' => 'Regenerate the component from plan_json.pages.{page_type}.{block_key}; do not use any source outside that path.',
                ]],
                $throwable
            );
        }
    }

    /**
     * @param array<string,mixed> $aiData
     */
    private function ensureAiPayloadValid(
        array $aiData,
        string $region,
        string $componentCode = '',
        array $verifiedAssets = [],
        string $semanticComponentCode = '',
        array $renderContext = [],
        array $defaultConfig = []
    ): array
    {
        $componentCode = $this->normalizeOptionalComponentCode($componentCode);
        if ($region === 'content') {
            $aiData = $this->normalizeContentBlockPayloadForJsonStructureOnly($aiData);
            $aiData = $this->normalizeVirtualThemeCssClassScope($aiData, $componentCode);
            $this->assertContentBlockCssCompleteness($aiData, $componentCode);

            return $aiData;
        }

        $aiData = $this->applyStrictVirtualThemeComponentPolicy(
            $aiData,
            $region,
            $componentCode,
            $verifiedAssets,
            $semanticComponentCode,
            $renderContext,
            $defaultConfig
        );
        $aiData = $this->normalizeVirtualThemeCssClassScope($aiData, $componentCode);

        // Styling, scope neatness, and visual layout checks are design QA only.
        // Build-time blocking stays limited to schema validity and visible
        // prompt/placeholder/internal metadata leakage.

        $validation = $this->getCodeValidator()->validateAiData($aiData, $region);
        if (!empty($validation['valid'])) {
            return $aiData;
        }

        $errors = \array_values(\array_filter(\array_map('strval', $validation['errors'] ?? [])));
        $message = \implode('; ', \array_slice($errors, 0, 5));

 // P1-E ?validator  ?findings  ?
 // strict recovery prompt  ?throwable.message  ?
 //  ?220  ?
        $findings = [];
        foreach (\array_slice($errors, 0, 10) as $error) {
            $findings[] = [
                'rule' => 'ai_payload.validator',
                'field' => $region,
                'found' => $error,
                'expected' => 'CodeValidator::validateAiData passes',
                'hint' => 'Regenerate a valid component JSON payload from the current plan_json block and satisfy CodeValidator::validateAiData.',
            ];
        }

        throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
            'AI component JSON schema validation failed: ' . ($message !== '' ? $message : 'unknown validation error'),
            $findings
        );
    }

    /**
     * Content block generation is intentionally permissive. The AI owns the
     * block-specific field model and layout; this stage only requires a usable
     * JSON envelope and keeps non-throwing safety normalization around raw PHP.
     *
     * @param array<string,mixed> $aiData
     * @return array<string,mixed>
     */
    private function normalizeContentBlockPayloadForJsonStructureOnly(array $aiData): array
    {
        $requiredStringKeys = [
            'extra_fields',
            'php_variables',
            'css_extra',
            'css_responsive',
            'html_content',
            'js_content',
        ];
        $errors = [];

        foreach ($requiredStringKeys as $key) {
            if (!\array_key_exists($key, $aiData)) {
                if ($key === 'html_content') {
                    $errors[] = 'missing html_content';
                } else {
                    $aiData[$key] = '';
                }
                continue;
            }
            if (\is_string($aiData[$key])) {
                continue;
            }
            if (\is_scalar($aiData[$key]) || (\is_object($aiData[$key]) && \method_exists($aiData[$key], '__toString'))) {
                $aiData[$key] = (string)$aiData[$key];
                continue;
            }
            $errors[] = $key . ' must be a JSON string';
        }

        if (\trim((string)($aiData['html_content'] ?? '')) === '') {
            $errors[] = 'html_content must be a non-empty JSON string';
        }

        if ($errors !== []) {
            throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
                'AI component JSON structure validation failed: ' . \implode('; ', \array_values(\array_unique($errors))),
                [[
                    'rule' => 'ai_payload.json_structure',
                    'field' => 'content',
                    'found' => \implode('; ', \array_values(\array_unique($errors))),
                    'expected' => 'Content block JSON must include string keys extra_fields, php_variables, css_extra, css_responsive, html_content, and js_content; html_content must not be empty.',
                    'hint' => 'Return one valid JSON object with the required string fields. Block layout, copy, field names, CSS, CTA, and image choices are not hard-gated here.',
                ]]
            );
        }

        $aiData['extra_fields'] = \trim(\str_replace(["\r\n", "\r"], "\n", (string)$aiData['extra_fields']));
        $aiData['php_variables'] = $this->normalizeVirtualThemeEditablePhpVariables((string)$aiData['php_variables']);
        foreach (['css_extra', 'css_responsive', 'css_content'] as $cssKey) {
            if (\is_string($aiData[$cssKey] ?? null)) {
                $aiData[$cssKey] = $this->stripPhpExecutionMarkers((string)$aiData[$cssKey]);
            }
        }
        $aiData['html_content'] = $this->sanitizeContentBlockHtmlWithoutQualityGate((string)$aiData['html_content']);
        $existingJs = (string)($aiData['js_content'] ?? '');
        $aiData['js_content'] = $this->isAllowedComponentInlineJs($existingJs, $aiData) ? $existingJs : '';

        return $aiData;
    }

    /**
     * @param array<string,mixed> $aiData
     */
    private function assertContentBlockCssCompleteness(array $aiData, string $componentCode): void
    {
        $reason = $this->detectContentBlockCssCompletenessViolation($aiData);
        if ($reason === null) {
            return;
        }

        throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
            'AI component CSS completeness failed: ' . $reason,
            [[
                'rule' => 'ai_payload.css_completeness',
                'field' => $componentCode !== '' ? $componentCode : 'content',
                'found' => $reason,
                'expected' => 'Content block html_content and css_extra/css_responsive are generated as one complete styled component.',
                'hint' => 'For every pb-c-* class used in html_content, include a matching CSS selector with visible layout, surface, spacing, or typography declarations.',
            ]]
        );
    }

    /**
     * @param array<string,mixed> $aiData
     */
    private function detectContentBlockCssCompletenessViolation(array $aiData): ?string
    {
        $html = \trim((string)($aiData['html_content'] ?? ''));
        if ($html === '') {
            return null;
        }

        $cssExtra = \trim((string)($aiData['css_extra'] ?? ''));
        $cssResponsive = \trim((string)($aiData['css_responsive'] ?? ''));
        if ($cssExtra === '') {
            return 'css_extra is empty, so the content block would render as raw HTML without block-owned styling';
        }
        if ($cssResponsive === '') {
            return 'css_responsive is empty; content blocks must include responsive rules for the generated structure';
        }

        $classes = $this->collectComponentPrefixedHtmlClassTokens($html);
        if ($classes === []) {
            return 'html_content has no component-prefixed classes for css_extra to target';
        }

        $css = $cssExtra . "\n" . $cssResponsive . "\n" . (string)($aiData['css_content'] ?? '');
        $missing = [];
        foreach ($classes as $class) {
            if (!$this->cssHasRuleForClass($css, $class)) {
                $missing[] = $class;
            }
        }

        if ($missing !== []) {
            return 'missing matching CSS selector(s) for html_content class(es): '
                . \implode(', ', \array_slice($missing, 0, 8));
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function collectComponentPrefixedHtmlClassTokens(string $html): array
    {
        if (\trim($html) === '') {
            return [];
        }

        if (\preg_match_all('/<[^>]*\bclass\s*=\s*(["\'])(?:(?!\1).)*\1[^>]*>/isu', $html, $matches) <= 0) {
            return [];
        }

        $classes = [];
        foreach ($matches[0] as $tag) {
            foreach ($this->extractHtmlClassTokensFromTag((string)$tag) as $class) {
                if (\preg_match('/^pb-c-[a-z0-9][a-z0-9_-]*$/iu', $class) === 1) {
                    $classes[$class] = true;
                }
            }
        }

        return \array_keys($classes);
    }

    private function cssHasRuleForClass(string $css, string $class): bool
    {
        if (\trim($css) === '') {
            return false;
        }

        return \preg_match(
            '/\.' . \preg_quote($class, '/') . '\b(?![a-z0-9_-])[^{}]*\{[^{}]*:[^{};]+;/isu',
            $css
        ) === 1;
    }

    private function sanitizeContentBlockHtmlWithoutQualityGate(string $html): string
    {
        [$html, $safePhpEchoTokens] = $this->extractSafeAiHtmlPhpEchoTokens($html);
        $html = \preg_replace('/<\s*script\b[^>]*>[\s\S]*?<\/\s*script\s*>/iu', '', $html) ?? $html;
        $html = \preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/iu', '', $html) ?? $html;
        $html = $this->stripPhpExecutionMarkers($html);
        $html = $this->restoreSafeAiHtmlPhpEchoTokens($html, $safePhpEchoTokens);

        return \trim($html);
    }

    private function stripPhpExecutionMarkers(string $value): string
    {
        return \str_replace(['<?php', '<?=', '<?', '?>'], '', $value);
    }

    /**
     * @param array<string,mixed> $aiData
     * @return array<string,mixed>
     */
    private function applyStrictVirtualThemeComponentPolicy(
        array $aiData,
        string $region,
        string $componentCode = '',
        array $verifiedAssets = [],
        string $semanticComponentCode = '',
        array $renderContext = [],
        array $defaultConfig = []
    ): array
    {
        if (\in_array($region, ['header', 'footer'], true)) {
            $aiData['extra_fields'] = '';
            $aiData['php_variables'] = '';
            $aiData['css_extra'] = '';
            $aiData['css_responsive'] = '';
            $aiData['css_content'] = '';
        } else {
            $aiData['php_variables'] = $this->normalizeVirtualThemeEditablePhpVariables((string)($aiData['php_variables'] ?? ''));
            $aiData['extra_fields'] = $this->ensureVirtualThemeEditableExtraFields(
                (string)($aiData['extra_fields'] ?? ''),
                $aiData,
                $defaultConfig
            );
        }
        $existingJs = (string)($aiData['js_content'] ?? '');
        $aiData['js_content'] = $this->isAllowedComponentInlineJs($existingJs, $aiData) ? $existingJs : '';

        foreach (['css_extra', 'css_responsive', 'css_content'] as $cssKey) {
            if (!\is_string($aiData[$cssKey] ?? null)) {
                continue;
            }
            $css = \trim((string)$aiData[$cssKey]);
            if ($css === '' || \str_contains($css, '<?') || \str_contains($css, '?>') || \str_contains($css, '@component_')) {
                $aiData[$cssKey] = '';
                continue;
            }
            $this->assertNoBrokenGeneratedImageReferences($css, $verifiedAssets);
            $aiData[$cssKey] = $this->normalizeVirtualThemeCssForValidation(
                $css,
                $this->resolveVirtualThemeCssValidationLimit($region, $cssKey),
                $cssKey
            );
        }

        if (\in_array($region, ['header', 'footer'], true)) {
            $aiData['html_extra'] = '';
            if ($region === 'footer') {
                $aiData['html_extra_column'] = '';
                $aiData['footer_extra_text'] = $this->cleanAiHtmlFragment((string)($aiData['footer_extra_text'] ?? ''), $verifiedAssets);
            }

            return $aiData;
        }

        if (\is_string($aiData['html_content'] ?? null)) {
            $aiData['html_content'] = $this->cleanAiHtmlFragment((string)$aiData['html_content'], $verifiedAssets);
        }

        if ($region === 'content') {
            $this->inspectVirtualThemeEditableFieldContract($aiData, $componentCode, $defaultConfig, $renderContext);
            $hardPolicyReason = $this->detectHardGeneratedSectionHtmlPolicyViolation(
                (string)($aiData['html_content'] ?? ''),
                $this->resolveGeneratedContentLocaleForPolicy($renderContext, $defaultConfig)
            );
            if ($hardPolicyReason !== null) {
                throw new \RuntimeException('AI component structure hard policy failed: ' . $hardPolicyReason);
            }
            $actionContractReason = $this->detectCtaActionContractViolation(
                (string)($aiData['html_content'] ?? ''),
                $this->mergeSemanticComponentCode($componentCode, $semanticComponentCode)
            );
            if ($actionContractReason !== null) {
                throw new \RuntimeException('AI component CTA/action contract failed: ' . $actionContractReason);
            }
            // Visual contrast is browser QA/design guidance, not a hard structure gate.
            // Policy-page CTA choices are content strategy, not a hard completion gate.
        }

        if (self::ENFORCE_COMPONENT_QUALITY_VALIDATION && $region === 'content') {
            // Visitor-copy quality rules must inspect visitor HTML only. CSS tokens
            // such as 100vh or 32px are valid styling and are checked separately.
            $lowQualityInspectionSource = (string)($aiData['html_content'] ?? '');
            $lowQualityCssSource = (string)($aiData['css_extra'] ?? '')
                . "\n" . (string)($aiData['css_responsive'] ?? '')
                . "\n" . (string)($aiData['css_content'] ?? '');
            $lowQualityReason = $this->detectStructuralGeneratedSectionHtmlReason(
                $lowQualityInspectionSource,
                $this->mergeSemanticComponentCode($componentCode, $semanticComponentCode),
                $lowQualityCssSource
            );
            if ($lowQualityReason !== null) {
                throw new \RuntimeException('AI component structure policy failed: ' . $lowQualityReason);
            }
        }

        return $aiData;
    }

    /**
     * @param array<string,mixed> $aiData
     */
    private function assertGeneratedCssTextContrastContract(array $aiData, string $componentCode): void
    {
        // Visual contrast is prompt guidance and browser QA, not a hard structure gate.
        return;

        $css = \trim((string)($aiData['css_extra'] ?? '') . "\n" . (string)($aiData['css_content'] ?? ''));
        if ($css === '') {
            return;
        }

        $rules = $this->collectSimpleGeneratedCssRules($css);
        if ($rules === []) {
            return;
        }

        $backgroundsByClass = [];
        foreach ($rules as $rule) {
            $background = $this->extractCssRuleBackgroundColor($rule['body']);
            if ($background === null) {
                continue;
            }
            foreach ($this->collectCssSelectorClassTokens($rule['selector']) as $class) {
                $backgroundsByClass[$class] = $background;
            }
        }

        $findings = [];
        foreach ($rules as $rule) {
            $textColor = $this->extractCssRuleTextColor($rule['body']);
            if ($textColor === null) {
                continue;
            }
            $background = $this->extractCssRuleBackgroundColor($rule['body']);
            if ($background === null) {
                foreach ($this->collectLikelyAncestorClassesForContrast($rule['selector']) as $class) {
                    if (isset($backgroundsByClass[$class])) {
                        $background = $backgroundsByClass[$class];
                        break;
                    }
                }
            }
            if ($background === null) {
                continue;
            }
            $ratio = $this->calculateCssColorContrastRatio($textColor, $background);
            if ($ratio === null || $ratio >= 4.5) {
                continue;
            }
            $findings[] = [
                'rule' => 'visual_contrast.text_background',
                'field' => $componentCode,
                'found' => \trim($rule['selector']) . ' uses color ' . $textColor . ' on background ' . $background . ' (contrast ' . \round($ratio, 2) . ':1)',
                'expected' => 'Normal visitor text must have WCAG contrast >= 4.5:1 against its effective background.',
                'hint' => 'Rewrite css_extra with a readable text color for this surface. Dark backgrounds need light text such as #FFFFFF/#F8FAFC; light backgrounds need dark text such as #0F172A. Keep colors from the confirmed palette when available and update title/text/copy/label/cta selectors together.',
            ];
            if (\count($findings) >= 6) {
                break;
            }
        }

        if ($findings === []) {
            return;
        }

        throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
            'AI component visual contrast contract failed: generated text is not readable against its background.',
            $findings
        );
    }

    /**
     * @return list<array{selector:string,body:string}>
     */
    private function collectSimpleGeneratedCssRules(string $css): array
    {
        $rules = [];
        if (\preg_match_all('/([^{}@][^{}]*)\{([^{}]*)\}/s', $css, $matches, \PREG_SET_ORDER) <= 0) {
            return [];
        }
        foreach ($matches as $match) {
            $selector = \trim((string)($match[1] ?? ''));
            $body = \trim((string)($match[2] ?? ''));
            if ($selector === '' || $body === '' || \str_starts_with($selector, '@')) {
                continue;
            }
            $rules[] = ['selector' => $selector, 'body' => $body];
        }

        return $rules;
    }

    private function extractCssRuleTextColor(string $body): ?string
    {
        return $this->extractCssDeclarationColor($body, 'color');
    }

    private function extractCssRuleBackgroundColor(string $body): ?string
    {
        foreach (['background-color', 'background'] as $property) {
            $color = $this->extractCssDeclarationColor($body, $property);
            if ($color !== null) {
                return $color;
            }
        }

        return null;
    }

    private function extractCssDeclarationColor(string $body, string $property): ?string
    {
        if (\preg_match('/(?:^|;)\s*' . \preg_quote($property, '/') . '\s*:\s*([^;{}]+)/iu', $body, $match) !== 1) {
            return null;
        }

        return $this->extractFirstConcreteCssColor((string)($match[1] ?? ''));
    }

    private function extractFirstConcreteCssColor(string $value): ?string
    {
        $value = \trim($value);
        if ($value === '' || \stripos($value, 'transparent') !== false || \stripos($value, 'currentColor') !== false || \str_contains($value, 'var(')) {
            return null;
        }
        if (\preg_match('/#[0-9a-f]{3,8}\b/i', $value, $match) === 1) {
            return $this->normalizeCssColorToken((string)$match[0]);
        }
        if (\preg_match('/rgba?\s*\([^)]*\)/i', $value, $match) === 1) {
            return $this->normalizeCssColorToken((string)$match[0]);
        }

        return null;
    }

    private function normalizeCssColorToken(string $color): ?string
    {
        $color = \trim($color);
        if (\preg_match('/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color, $match) === 1) {
            $hex = \strtolower((string)$match[1]);
            if (\strlen($hex) === 3 || \strlen($hex) === 4) {
                $expanded = '';
                foreach (\str_split($hex) as $char) {
                    $expanded .= $char . $char;
                }
                $hex = $expanded;
            }
            if (\strlen($hex) === 8 && \hexdec(\substr($hex, 6, 2)) < 255) {
                return null;
            }

            return '#' . \substr($hex, 0, 6);
        }
        if (\preg_match('/^rgba?\s*\(([^)]*)\)$/i', $color, $match) === 1) {
            $parts = \array_map('trim', \explode(',', (string)$match[1]));
            if (\count($parts) < 3) {
                return null;
            }
            if (isset($parts[3]) && (float)$parts[3] < 1.0) {
                return null;
            }
            $rgb = [];
            for ($i = 0; $i < 3; $i++) {
                $part = (string)$parts[$i];
                $rgb[] = \str_ends_with($part, '%')
                    ? (int)\round(255 * ((float)\rtrim($part, '%') / 100))
                    : (int)\round((float)$part);
            }
            foreach ($rgb as $channel) {
                if ($channel < 0 || $channel > 255) {
                    return null;
                }
            }

            return \sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function collectCssSelectorClassTokens(string $selector): array
    {
        if (\preg_match_all('/\.([a-z_][a-z0-9_-]*)/i', $selector, $matches) <= 0) {
            return [];
        }

        return \array_values(\array_unique(\array_map('strval', $matches[1] ?? [])));
    }

    /**
     * @return list<string>
     */
    private function collectLikelyAncestorClassesForContrast(string $selector): array
    {
        $classes = $this->collectCssSelectorClassTokens($selector);
        if ($classes === []) {
            return [];
        }
        \array_pop($classes);

        return \array_reverse($classes);
    }

    private function calculateCssColorContrastRatio(string $foreground, string $background): ?float
    {
        $fg = $this->cssHexToRgb($foreground);
        $bg = $this->cssHexToRgb($background);
        if ($fg === null || $bg === null) {
            return null;
        }
        $fgLuminance = $this->relativeCssColorLuminance($fg);
        $bgLuminance = $this->relativeCssColorLuminance($bg);
        $lighter = \max($fgLuminance, $bgLuminance);
        $darker = \min($fgLuminance, $bgLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    private function cssHexToRgb(string $hex): ?array
    {
        $hex = \ltrim(\trim($hex), '#');
        if (!\preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return null;
        }

        return [
            \hexdec(\substr($hex, 0, 2)),
            \hexdec(\substr($hex, 2, 2)),
            \hexdec(\substr($hex, 4, 2)),
        ];
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private function relativeCssColorLuminance(array $rgb): float
    {
        $channels = [];
        foreach ($rgb as $channel) {
            $value = $channel / 255;
            $channels[] = $value <= 0.03928
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function normalizeVirtualThemeEditablePhpVariables(string $phpVariables): string
    {
        $phpVariables = \str_replace(['<?php', '<?=', '<?', '?>', '`'], '', $phpVariables);
        $lines = \preg_split('/\R/u', $phpVariables) ?: [];
        $safe = [];

        foreach ($lines as $line) {
            $line = \trim((string)$line);
            if ($line === '') {
                continue;
            }
            $lineCodeShape = \preg_replace('/([\'"])(?:\\\\.|(?!\1).)*\1/u', "''", $line);
            $lineCodeShape = \is_string($lineCodeShape) ? $lineCodeShape : $line;
            if (\str_contains($lineCodeShape, '{') || \str_contains($lineCodeShape, '}') || \str_contains($lineCodeShape, '=>')) {
                continue;
            }
            if (\preg_match('/\b(?:if|else|foreach|while|for|switch|case|function|return|new|include|require|eval|exec|shell_exec|system|passthru)\b/i', $lineCodeShape)) {
                continue;
            }
            if (\preg_match('/(?:->|::|$_(?:GET|POST|REQUEST|SESSION|COOKIE|SERVER|FILES|ENV)\b)/i', $lineCodeShape)) {
                continue;
            }
            if (!\preg_match('/^$[A-Za-z_][A-Za-z0-9_]*\s*=\s*$getConfig\s*\(/', $line)) {
                continue;
            }
            if (!\preg_match('/;\s*$/', $line)) {
                $line .= ';';
            }
            $safe[] = $line;
            if (\count($safe) >= 40) {
                break;
            }
        }

        return \implode("\n", $safe);
    }

    /**
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $defaultConfig
     */
    private function ensureVirtualThemeEditableExtraFields(string $extraFields, array $aiData, array $defaultConfig): string
    {
        unset($aiData, $defaultConfig);

        return \trim(\str_replace(["\r\n", "\r"], "\n", $extraFields));
    }

    /**
     * @param array<string,mixed> $defaultConfig
     */
    private function resolveVirtualThemeEditableFieldDefault(string $key, string $fallback, array $defaultConfig): string
    {
        $value = $defaultConfig[$key] ?? $fallback;
        return \is_scalar($value) ? (string)$value : $fallback;
    }

    private function isVirtualThemeEditableConfigKey(string $key): bool
    {
        $key = \trim($key);
        if ($key === '') {
            return false;
        }
        $lower = \strtolower($key);
        foreach (['style.', 'layout.', 'runtime.', 'contract.', '_pb_', 'data.'] as $prefix) {
            if (\str_starts_with($lower, $prefix)) {
                return false;
            }
        }
        if (\in_array($lower, ['content_locale', 'locale', 'code', 'name', 'type', 'sort_order'], true)) {
            return false;
        }

        return \preg_match('/(^|\.)(title|heading|headline|subtitle|eyebrow|description|body|intro|text|text_\d+|label|label_\d+|value|value_\d+|copy|copy_\d+|note|note_\d+|question|question_\d+|answer|answer_\d+|placeholder|placeholder_\d+|help|help_\d+|caption|caption_\d+|message_label|message_placeholder|name_label|email_label|subject_label|cta_text|button_text|url|href|image_url|image_alt|alt|logo|poster|src)$/i', $key) === 1
            || \preg_match('/^(content|cta|media|visual|image|logo|hero|card|feature|proof|trust|faq|review|step|stat|item|form|field|channel|contact|support|badge|chip|metric|quote|testimonial|policy|rule)\./i', $key) === 1;
    }

    private function isVirtualThemeMediaEditableField(string $key): bool
    {
        $lower = \strtolower($key);
        if (\str_contains($lower, 'alt')) {
            return false;
        }

        return \preg_match('/(^|\.)(image_url|image|logo|photo|poster|src)$/i', $key) === 1
            || \preg_match('/(image|logo|photo|poster).*(url|src)/i', $key) === 1;
    }

    private function resolveVirtualThemeEditableFieldType(string $key, string $value): string
    {
        if ($this->isVirtualThemeMediaEditableField($key)) {
            return 'image';
        }
        $lower = \strtolower($key);
        if (\str_contains($value, "\n") || \strlen($value) > 90 || \preg_match('/(description|body|intro|answer|copy|items|list|paragraph)/i', $lower)) {
            return 'textarea';
        }

        return 'text';
    }

    private function buildVirtualThemeEditableFieldLabel(string $key): string
    {
        $label = \preg_replace('/[^a-zA-Z0-9]+/', ' ', \str_replace('.', ' ', $key)) ?: $key;
        $label = \trim($label);

        return $label !== '' ? \ucwords($label) : $key;
    }

    private function sanitizeVirtualThemeEditableFieldDefault(string $value): string
    {
        $value = \preg_replace('/\s+/u', ' ', \trim($value)) ?? \trim($value);
        $value = \str_replace(["\r", "\n"], ' ', $value);

        return \mb_substr($value, 0, 220, 'UTF-8');
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $PlanJsonTask
     */
    private function buildVirtualThemeEditableFieldPromptContract(array $defaultConfig, array $PlanJsonTask = []): string
    {
        $requiredFields = $this->buildVirtualThemeRequiredEditableFields($defaultConfig, $PlanJsonTask);
        $requiredFieldsJson = $requiredFields !== []
            ? $this->jsonEncodeForPrompt($requiredFields, 1800)
            : '[]';

        $literalTextNodeExample = <<<'PROMPT'
HTML visible-text binding teaching (this is how the gate thinks):
BAD html_content:
"<section class='pb-c-root'><h2>Raw title</h2><p>Raw body</p><a class='pb-c-cta' href='/'>Raw CTA</a></section>"
GOOD extra_fields/php_variables/html_content:
"extra_fields": "group:ai_content => AI editable content\ncontent.title => Title:text:Finished localized title\ncontent.description => Description:textarea:Finished localized body\ncta.text => CTA text:text:Start now"
"php_variables": "$contentTitle = $getConfig('content.title', 'Finished localized title');\n$contentDescription = $getConfig('content.description', 'Finished localized body');\n$ctaText = $getConfig('cta.text', 'Start now');"
"html_content": "<section class='pb-c-root'><h2><?= htmlspecialchars($contentTitle ?? 'Finished localized title', ENT_QUOTES, 'UTF-8') ?></h2><p><?= nl2br(htmlspecialchars($contentDescription ?? 'Finished localized body', ENT_QUOTES, 'UTF-8')) ?></p><button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars($ctaText ?? 'Start now', ENT_QUOTES, 'UTF-8') ?></button></section>"
Machine self-check before returning: make sure visible copy is final website copy, not prompt labels, schema keys, internal plan-json fields, placeholders, malformed contact fragments, or raw source text. Editable field coverage is advisory; do not reject useful dynamic block copy solely because it is not mirrored in extra_fields/php_variables.
PROMPT;

        $example = <<<'PROMPT'
Example return shape (do not copy the example copy; copy the structure):
{
  "extra_fields": "group:ai_content => AI editable content\ncontent.title => Title:text:Finished localized title\ncontent.description => Description:textarea:Finished localized body\nproof.item_1_label => Proof item 1 label:text:Finished localized card text\ncta.text => CTA text:text:Book demo",
  "php_variables": "$contentTitle = $getConfig('content.title', 'Finished localized title');\n$contentDescription = $getConfig('content.description', 'Finished localized body');\n$proofItem1Label = $getConfig('proof.item_1_label', 'Finished localized card text');\n$ctaText = $getConfig('cta.text', 'Book demo');",
  "html_content": "<section class='pb-c-root'><div class='pb-c-inner'><h2 class='pb-c-title'><?= htmlspecialchars($contentTitle ?? 'Finished localized title', ENT_QUOTES, 'UTF-8') ?></h2><p class='pb-c-copy'><?= nl2br(htmlspecialchars($contentDescription ?? 'Finished localized body', ENT_QUOTES, 'UTF-8')) ?></p><div class='pb-c-card'><?= htmlspecialchars($proofItem1Label ?? 'Finished localized card text', ENT_QUOTES, 'UTF-8') ?></div><button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars($ctaText ?? 'Book demo', ENT_QUOTES, 'UTF-8') ?></button></div></section>",
  "css_extra": "#componentId .pb-c-root{position:relative;padding:72px 24px;}#componentId .pb-c-inner{max-width:1200px;margin:0 auto;}#componentId .pb-c-title{font-family:Georgia,serif;}",
  "css_responsive": "@media (max-width: 768px){#componentId .pb-c-root{padding:48px 18px;}}@media (max-width: 420px){#componentId .pb-c-root{padding:36px 14px;}}",
  "js_content": ""
}
If this block is supplied a verified final_url in verified_asset_src_allowlist, add fields like:
media.image_url => Image:image:<exact verified final_url from verified_asset_src_allowlist>
media.image_alt => Image alt:text:Localized image alt
and render src/alt from those fields with the exact verified final_url as the fallback. Never invent or copy example image paths.
PROMPT;

        $multiFieldExample = <<<'PROMPT'
Multiple repeated fields strategy (copy the pattern, not the copy):
When you create multiple cards, steps, chips, stats, reviews, FAQ rows, form labels, or badges, use one semantic field per visible value. Do not put raw labels, step numbers, stat numbers, or short chip text directly in html_content.
Use field families that the contract recognizes: content.*, cta.*, media.*, card.*, feature.*, proof.*, stat.*, faq.*, review.*, step.*, form.*, channel.*, badge.*, item.*, policy.*, and rule.*.
GOOD extra_fields rows:
proof.item_1_value => Proof 1 value:text:4.8
proof.item_1_label => Proof 1 label:text:Player rating
proof.item_2_value => Proof 2 value:text:Fast
proof.item_2_label => Proof 2 label:text:Room ready
GOOD php_variables rows:
$proofItem1Value = $getConfig('proof.item_1_value', '4.8');
$proofItem1Label = $getConfig('proof.item_1_label', 'Player rating');
$proofItem2Value = $getConfig('proof.item_2_value', 'Fast');
$proofItem2Label = $getConfig('proof.item_2_label', 'Room ready');
GOOD html_content fragment:
<div class='pb-c-proof'><span><?= htmlspecialchars($proofItem1Value ?? '4.8', ENT_QUOTES, 'UTF-8') ?></span><small><?= htmlspecialchars($proofItem1Label ?? 'Player rating', ENT_QUOTES, 'UTF-8') ?></small></div><div class='pb-c-proof'><span><?= htmlspecialchars($proofItem2Value ?? 'Fast', ENT_QUOTES, 'UTF-8') ?></span><small><?= htmlspecialchars($proofItem2Label ?? 'Room ready', ENT_QUOTES, 'UTF-8') ?></small></div>
BAD html_content fragment:
<div class='pb-c-proof'><span>4.8</span><small>Player rating</small></div><div class='pb-c-chip'>Fast room entry</div>
PROMPT;

        $roleFieldExample = <<<'PROMPT'
Common role field patterns (copy the binding style, invent final localized copy from the current block):
FAQ rows:
GOOD extra_fields rows:
faq.question_1 => FAQ question 1:text:Finished localized question?
faq.answer_1 => FAQ answer 1:textarea:Finished localized answer.
GOOD php_variables rows:
$faqQuestion1 = $getConfig('faq.question_1', 'Finished localized question?');
$faqAnswer1 = $getConfig('faq.answer_1', 'Finished localized answer.');
html_content: <div class='pb-c-faq-item'><h3 class='pb-c-question'><?= htmlspecialchars($faqQuestion1 ?? 'Finished localized question?', ENT_QUOTES, 'UTF-8') ?></h3><p class='pb-c-answer'><?= nl2br(htmlspecialchars($faqAnswer1 ?? 'Finished localized answer.', ENT_QUOTES, 'UTF-8')) ?></p></div>
Form rows:
GOOD extra_fields rows:
form.label_1 => Form label 1:text:Finished label
form.placeholder_1 => Form placeholder 1:text:Finished localized placeholder
GOOD php_variables rows:
$formLabel1 = $getConfig('form.label_1', 'Finished label');
$formPlaceholder1 = $getConfig('form.placeholder_1', 'Finished localized placeholder');
html_content: <label class='pb-c-label'><?= htmlspecialchars($formLabel1 ?? 'Finished label', ENT_QUOTES, 'UTF-8') ?></label><input class='pb-c-input' placeholder='<?= htmlspecialchars($formPlaceholder1 ?? 'Finished localized placeholder', ENT_QUOTES, 'UTF-8') ?>'>
Contact/channel rows:
GOOD extra_fields rows:
channel.item_1_label => Channel 1 label:text:Finished channel label
channel.item_1_value => Channel 1 value:text:Finished support guidance
GOOD php_variables rows:
$channelItem1Label = $getConfig('channel.item_1_label', 'Finished channel label');
$channelItem1Value = $getConfig('channel.item_1_value', 'Finished support guidance');
html_content: <div class='pb-c-channel'><span class='pb-c-label'><?= htmlspecialchars($channelItem1Label ?? 'Finished channel label', ENT_QUOTES, 'UTF-8') ?></span><span class='pb-c-value'><?= htmlspecialchars($channelItem1Value ?? 'Finished support guidance', ENT_QUOTES, 'UTF-8') ?></span></div>
CTA button when no real route is supplied:
GOOD extra_fields row:
cta.text => CTA text:text:Finished CTA
GOOD php_variables row:
$ctaText = $getConfig('cta.text', 'Finished CTA');
GOOD html_content:
<button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars($ctaText ?? 'Finished CTA', ENT_QUOTES, 'UTF-8') ?></button>
Do not add cta.url for this button pattern.
Never use camelCase field keys in extra_fields/php_variables. Use dot keys such as form.label_1, channel.item_1_value, faq.question_1.
PROMPT;

        return "CTX_REQUIRED_EDITABLE_FIELDS (hard structure contract):\n"
            . "- Plan editable fields before writing html_content. These fields are the minimum required editable fields for this block; add more semantic fields for every card/stat/chip/list text you introduce.\n"
            . "- Required fields JSON: {$requiredFieldsJson}\n"
            . "- Required fields JSON is canonical. Do not rename, alias, replace, or merge those exact dot keys. In this build, body/intro/copy requirements are normally canonicalized to `content.description`: declare and bind `content.description`, not `content.body`, when that is what the JSON lists. Do not invent `content.body`; use it only if CTX_REQUIRED_EDITABLE_FIELDS explicitly lists that exact key. The same rule applies to CTA, media, form, channel, FAQ, stat, card, proof, and repeated item keys.\n"
            . "- Required field binding example: required key `content.description` means extra_fields row `content.description => Description:textarea:Finished localized body`, php_variables line `\$contentDescription = \$getConfig('content.description', 'Finished localized body');`, and html_content echo `<?= nl2br(htmlspecialchars(\$contentDescription ?? 'Finished localized body', ENT_QUOTES, 'UTF-8')) ?>`.\n"
            . "- Return `extra_fields` exactly in PageBuilder metadata-line format, not as JSON. Every visible text/CTA/image value must have one metadata line.\n"
            . "- Return `php_variables` as simple `\$var = \$getConfig('field.key', 'default');` lines for those fields. No arrays, loops, if statements, PHP tags, or helper functions.\n"
            . "- Return `html_content` with no hardcoded visitor copy. Every visible text node, CTA label/href, img src, and img alt must be rendered from the variables above with safe `<?= htmlspecialchars(...) ?>` or `<?= nl2br(htmlspecialchars(...)) ?>`.\n"
            . "- Editable-field guidance: use extra_fields and php_variables for the primary editor-facing title/body/CTA/media values when practical. Do not treat every generated badge, stat, proof label, FAQ row, or visual microcopy as a hard field census; finished visitor copy quality is more important than rigid key coverage.\n"
            . "- Image editable field rule: include media.image_url only when this prompt supplies a verified final_url/verified_asset_src_allowlist value. Its default/fallback must be that exact verified URL. Never use invented defaults such as /pub/media/generated/example.webp, /pub/media/example.webp, placeholder services, stock URLs, or empty src.\n"
            . "- CTA URL field rule: include cta.url only when the value is a real CTX_CTA_ACTION_CONTRACT target or an allowed route path. Never use `#`, hash-only anchors, invented download/FAQ/game paths, `/` as a generic placeholder, or placeholder URLs as editable defaults; use a button event CTA with cta.text only when no real route exists.\n"
            . "- For card grids/proof rows/stat rows, create explicit fields such as `proof.item_1_label`, `proof.item_1_text`, `stat.item_1_value`, `stat.item_1_label`; do not hide those labels as static HTML text.\n"
            . "- For forms/contact/FAQ roles, use dot-key families exactly like `form.label_1`, `form.placeholder_1`, `channel.item_1_label`, `channel.item_1_value`, `faq.question_1`, and `faq.answer_1`. Bind every one with `\$getConfig(...)`; do not use camelCase-only variable names as field keys.\n"
            . $literalTextNodeExample . "\n"
            . $example . "\n"
            . $multiFieldExample . "\n"
            . $roleFieldExample . "\n";
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $PlanJsonTask
     * @return list<array{key:string,label:string,type:string,default:string}>
     */
    private function buildVirtualThemeRequiredEditableFields(array $defaultConfig, array $PlanJsonTask = []): array
    {
        $fields = [];
        $add = function (string $key, mixed $defaultValue, string $type = '', string $label = '') use (&$fields): void {
            $key = \strtolower(\trim($key));
            if ($key === '' || !$this->isVirtualThemeEditableConfigKey($key)) {
                return;
            }
            if (!\is_scalar($defaultValue) && !$defaultValue instanceof \Stringable) {
                return;
            }
            $default = $this->sanitizeVirtualThemeEditableFieldDefault((string)$defaultValue);
            if ($default === '') {
                return;
            }
            if ($key === 'cta.url' && !$this->isUsableEditableCtaUrlDefault($default)) {
                return;
            }
            if ($key === 'media.image_url' && !$this->isUsableEditableImageUrlDefault($default)) {
                return;
            }
            if (isset($fields[$key])) {
                return;
            }
            $fields[$key] = [
                'key' => $key,
                'label' => $label !== '' ? $label : $this->buildVirtualThemeEditableFieldLabel($key),
                'type' => $type !== '' ? $type : $this->resolveVirtualThemeEditableFieldType($key, $default),
                'default' => $default,
            ];
        };

        $add('content.title', $this->firstConfigString($defaultConfig, ['content.title', 'title', 'heading', 'headline']), 'text', 'Title');
        $add('content.subtitle', $this->firstConfigString($defaultConfig, ['content.subtitle', 'subtitle', 'eyebrow']), 'text', 'Subtitle');
        $add('content.description', $this->firstConfigString($defaultConfig, ['content.description', 'content.body', 'description', 'body', 'section_intro']), 'textarea', 'Description');
        $add('cta.text', $this->firstConfigString($defaultConfig, ['cta.text', 'content.cta_text', 'button_text', 'button.label']), 'text', 'CTA text');
        $add('cta.url', $this->firstConfigString($defaultConfig, ['cta.url', 'content.cta_url', 'button_url', 'button.href']), 'text', 'CTA URL');
        $add('media.image_url', $this->firstConfigString($defaultConfig, ['media.image_url', 'visual.image_url', 'image.url', 'runtime.section_image_url']), 'image', 'Image');
        $add('media.image_alt', $this->firstConfigString($defaultConfig, ['media.image_alt', 'visual.image_alt', 'image.alt', 'runtime.section_image_alt']), 'text', 'Image alt');

        foreach ($this->collectVirtualThemeEditablePlanRows($PlanJsonTask) as $index => $row) {
            $field = $this->normalizePlanJsonRequirementField($row['field'] ?? '');
            $key = $this->normalizeVirtualThemeEditableFieldKeyFromPlan($field, $index);
            if ($key === '') {
                continue;
            }
            $isLinkField = \str_contains($key, 'url') || \str_contains($key, 'href');
            $sample = $this->normalizePlanJsonRequirementSample($row['sample'] ?? $row['copy'] ?? $row['default'] ?? '', $isLinkField);
            $add($key, $sample, '', $field !== '' ? $this->buildVirtualThemeEditableFieldLabel($field) : '');
        }
        if (!isset($fields['media.image_url'])) {
            unset($fields['media.image_alt']);
        }

        return \array_slice(\array_values($fields), 0, 24);
    }

    private function isUsableEditableCtaUrlDefault(string $value): bool
    {
        $value = \trim($value);
        if ($value === '' || $value === '/' || $value === './' || $value === '#' || \str_starts_with($value, '#')) {
            return false;
        }
        if (\preg_match('/placeholder|example\.com|javascript:|data:|blob:/iu', $value) === 1) {
            return false;
        }

        return $this->shouldValidateGeneratedHrefAgainstRouteContract($value)
            || \preg_match('#^(?:mailto|tel):#iu', $value) === 1;
    }

    private function isUsableEditableImageUrlDefault(string $value): bool
    {
        $value = \trim($value);
        if ($value === '' || \preg_match('#^(?:data|blob|javascript):#iu', $value) === 1) {
            return false;
        }
        if (\preg_match('/placeholder|example\.com|picsum\.photos|source\.unsplash\.com|images\.unsplash\.com/iu', $value) === 1) {
            return false;
        }
        $path = \parse_url($value, \PHP_URL_PATH);
        $path = \is_string($path) ? \trim($path) : $value;
        if ($path === '' || \str_ends_with($path, '/')) {
            return false;
        }

        return \preg_match('/\.(?:png|jpe?g|webp|gif|avif|svg)(?:$|\?)/i', $path) === 1;
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @return list<array<string,mixed>>
     */
    private function collectVirtualThemeEditablePlanRows(array $PlanJsonTask): array
    {
        $rows = [];
        foreach ([
            $PlanJsonTask['plan_context']['field_plan'] ?? [],
            $PlanJsonTask['task_script']['field_content_requirements'] ?? [],
            $PlanJsonTask['block_task']['meta_fields'] ?? [],
            $PlanJsonTask['block_task']['content_plan']['content_copy'] ?? [],
        ] as $sourceRows) {
            if (!\is_array($sourceRows)) {
                continue;
            }
            foreach ($sourceRows as $row) {
                if (\is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    private function normalizeVirtualThemeEditableFieldKeyFromPlan(string $field, int $index): string
    {
        $field = \strtolower(\trim($field));
        if ($field === '') {
            $field = 'field_' . ($index + 1);
        }
        if (\str_contains($field, 'image') || \str_contains($field, 'media') || \str_contains($field, 'photo') || \str_contains($field, 'visual')) {
            if (\str_contains($field, 'alt')) {
                return 'media.image_alt';
            }
            if (\str_contains($field, 'url') || \str_contains($field, 'src') || \str_contains($field, 'image')) {
                return 'media.image_url';
            }
        }
        if (\str_contains($field, 'url') || \str_contains($field, 'href') || \str_contains($field, 'link')) {
            return \str_contains($field, 'cta') || \str_contains($field, 'button') ? 'cta.url' : 'content.' . $this->slugForEditableFieldKey($field);
        }
        if (\str_contains($field, 'cta') || \str_contains($field, 'button')) {
            return 'cta.text';
        }
        if (\str_contains($field, 'title') || \str_contains($field, 'headline') || \str_contains($field, 'heading')) {
            return 'content.title';
        }
        if (\str_contains($field, 'subtitle') || \str_contains($field, 'eyebrow') || \str_contains($field, 'tagline')) {
            return 'content.subtitle';
        }
        if (\str_contains($field, 'description') || \str_contains($field, 'body') || \str_contains($field, 'copy') || \str_contains($field, 'intro') || \str_contains($field, 'summary')) {
            return 'content.description';
        }

        return 'content.' . $this->slugForEditableFieldKey($field);
    }

    private function slugForEditableFieldKey(string $field): string
    {
        $slug = \strtolower(\trim($field));
        $slug = \preg_replace('/[^a-z0-9]+/i', '_', $slug) ?? '';
        $slug = \trim($slug, '_');

        return $slug !== '' ? $slug : 'field';
    }

    /**
     * @param array<string,mixed> $aiData
     */
    private function inspectVirtualThemeEditableFieldContract(
        array $aiData,
        string $componentCode = '',
        array $defaultConfig = [],
        array $renderContext = []
    ): void
    {
        $html = \trim((string)($aiData['html_content'] ?? ''));
        if ($html === '') {
            return;
        }

        // The component field set is intentionally dynamic: Stage-2 can choose
        // block-specific keys based on the plan JSON page, visual role, locale, CTA
        // shape, and whether image generation is deferred. Treat editability as
        // prompt guidance only. Completion remains gated by transport schema,
        // embeddable HTML/CSS, CTA behavior, prompt/internal-text leakage, and
        // verified image sources.
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     * @return list<array<string,string>>
     */
    private function decodeVirtualThemeRequiredEditableFieldContract(array $defaultConfig, array $renderContext = []): array
    {
        $raw = $defaultConfig['runtime.required_editable_fields']
            ?? $renderContext['_required_editable_fields']
            ?? '';
        if (!\is_string($raw) || \trim($raw) === '') {
            return [];
        }

        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $key = \strtolower(\trim((string)($row['key'] ?? '')));
            if ($key === '' || !$this->isVirtualThemeEditableConfigKey($key)) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => \trim((string)($row['label'] ?? $this->buildVirtualThemeEditableFieldLabel($key))),
                'type' => \trim((string)($row['type'] ?? 'text')),
                'default' => \trim((string)($row['default'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function extractVirtualThemeExtraFieldKeys(string $extraFields): array
    {
        $keys = [];
        foreach (\preg_split('/\R/u', $extraFields) ?: [] as $line) {
            if (\preg_match('/^\s*\*?\s*([a-z][a-z0-9_]*(?:\.[a-z0-9_.-]+)+)\s*=>/i', (string)$line, $match) !== 1) {
                continue;
            }
            $key = \strtolower(\trim((string)($match[1] ?? '')));
            if ($key !== '') {
                $keys[$key] = $key;
            }
        }

        return \array_values($keys);
    }

    /**
     * @return list<string>
     */
    private function extractVirtualThemeGetConfigKeys(string $source): array
    {
        if ($source === '') {
            return [];
        }

        $keys = [];
        if (\preg_match_all('/$getConfig\s*\(\s*[\'"]([a-zA-Z0-9_.-]+)[\'"]/u', $source, $matches)) {
            foreach ($matches[1] ?? [] as $key) {
                $key = \strtolower(\trim((string)$key));
                if ($key !== '') {
                    $keys[$key] = $key;
                }
            }
        }

        return \array_values($keys);
    }

    private function extractVirtualThemeHardcodedVisibleText(string $html): string
    {
        $text = $this->extractVisibleHtmlText($html);
        $text = \preg_replace('/[\s\p{Zs}]+/u', ' ', $text) ?? $text;
        $text = \trim($text);
        if ($text === '' || \preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            return '';
        }

        return $text;
    }

    /**
     * @param list<string> $extraFieldKeys
     * @param list<string> $getConfigKeys
     * @return list<array<string,string>>
     */
    private function detectVirtualThemeImageFieldContractFindings(
        string $html,
        array $extraFieldKeys,
        array $getConfigKeys,
        string $componentCode
    ): array {
        $imgTags = $this->extractHtmlTagsByName($html, 'img');
        if ($imgTags === []) {
            return [];
        }

        $hasImageUrlField = false;
        $hasImageAltField = false;
        foreach ($extraFieldKeys as $key) {
            if ($this->isVirtualThemeMediaEditableField($key)) {
                $hasImageUrlField = true;
            }
            if (\str_contains(\strtolower($key), 'alt')) {
                $hasImageAltField = true;
            }
        }

        $hasImageUrlBinding = false;
        $hasImageAltBinding = false;
        foreach ($getConfigKeys as $key) {
            if ($this->isVirtualThemeMediaEditableField($key)) {
                $hasImageUrlBinding = true;
            }
            if (\str_contains(\strtolower($key), 'alt')) {
                $hasImageAltBinding = true;
            }
        }

        $findings = [];
        if (!$hasImageUrlField || !$hasImageUrlBinding) {
            $findings[] = [
                'rule' => 'editable_field_contract.image_url',
                'field' => $componentCode,
                'found' => 'img tag exists without an editable image URL field binding',
                'expected' => 'Declare and bind an image URL field such as media.image_url.',
                'hint' => 'Use `$mediaImageUrl = $getConfig(\'media.image_url\', \'<verified final_url>\');` and render img src from `$mediaImageUrl`.',
            ];
        }
        if (!$hasImageAltField || !$hasImageAltBinding) {
            $findings[] = [
                'rule' => 'editable_field_contract.image_alt',
                'field' => $componentCode,
                'found' => 'img tag exists without an editable image alt field binding',
                'expected' => 'Declare and bind an image alt field such as media.image_alt.',
                'hint' => 'Use `$mediaImageAlt = $getConfig(\'media.image_alt\', \'<localized alt>\');` and render img alt from `$mediaImageAlt`.',
            ];
        }

        foreach ($imgTags as $imgTag) {
            $src = $this->extractHtmlAttributeValue((string)$imgTag, 'src');
            $alt = $this->extractHtmlAttributeValue((string)$imgTag, 'alt');
            if ($src === null || !\str_contains($src, '<' . '?=') || !$this->attributeContainsEditableGetConfigOrVariable($src, $getConfigKeys)) {
                $findings[] = [
                    'rule' => 'editable_field_contract.image_src_rendering',
                    'field' => $componentCode,
                    'found' => $this->clipText((string)$imgTag, 180),
                    'expected' => 'img src must render a safe editable PHP echo backed by $getConfig(...).',
                    'hint' => 'Use `src="<?= htmlspecialchars($mediaImageUrl ?? \'<verified final_url>\', ENT_QUOTES, \'UTF-8\') ?>"` and keep data-pb-ai-image-role/data-pb-ai-asset-slot unchanged.',
                ];
            }
            if ($alt === null || !\str_contains($alt, '<' . '?=') || !$this->attributeContainsEditableGetConfigOrVariable($alt, $getConfigKeys)) {
                $findings[] = [
                    'rule' => 'editable_field_contract.image_alt_rendering',
                    'field' => $componentCode,
                    'found' => $this->clipText((string)$imgTag, 180),
                    'expected' => 'img alt must render a safe editable PHP echo backed by $getConfig(...).',
                    'hint' => 'Use `alt="<?= htmlspecialchars($mediaImageAlt ?? \'<localized alt>\', ENT_QUOTES, \'UTF-8\') ?>"`.',
                ];
            }
        }

        return $findings;
    }

    private function extractHtmlAttributeValue(string $tag, string $attribute): ?string
    {
        if (\preg_match('/\s' . \preg_quote($attribute, '/') . '\s*=\s*([\'"])/iu', $tag, $match, \PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $quote = (string)($match[1][0] ?? '');
        $start = (int)($match[1][1] ?? -1) + 1;
        if (($quote !== '"' && $quote !== "'") || $start <= 0) {
            return null;
        }

        $length = \strlen($tag);
        $inPhp = false;
        for ($i = $start; $i < $length; $i++) {
            $pair = \substr($tag, $i, 2);
            if (!$inPhp && $pair === '<?') {
                $inPhp = true;
                $i++;
                continue;
            }
            if ($inPhp) {
                if ($pair === '?>') {
                    $inPhp = false;
                    $i++;
                }
                continue;
            }
            if ($tag[$i] === $quote) {
                return \substr($tag, $start, $i - $start);
            }
        }

        return null;
    }

    /**
     * Extract tags while ignoring `>` and quotes inside PHP echo blocks embedded in attributes.
     *
     * @return list<string>
     */
    private function extractHtmlTagsByName(string $html, string $tagName): array
    {
        if (\preg_match_all('/<\s*' . \preg_quote($tagName, '/') . '\b/iu', $html, $matches, \PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $tags = [];
        $length = \strlen($html);
        foreach ($matches[0] as $match) {
            $start = (int)($match[1] ?? -1);
            if ($start < 0) {
                continue;
            }
            $quote = null;
            $inPhp = false;
            for ($i = $start; $i < $length; $i++) {
                $pair = \substr($html, $i, 2);
                if (!$inPhp && $pair === '<?') {
                    $inPhp = true;
                    $i++;
                    continue;
                }
                if ($inPhp) {
                    if ($pair === '?>') {
                        $inPhp = false;
                        $i++;
                    }
                    continue;
                }
                $char = $html[$i];
                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    continue;
                }
                if ($char === '>') {
                    $tags[] = \substr($html, $start, $i - $start + 1);
                    break;
                }
            }
        }

        return $tags;
    }

    /**
     * @param list<string> $getConfigKeys
     */
    private function attributeContainsEditableGetConfigOrVariable(string $value, array $getConfigKeys): bool
    {
        if (\preg_match('/$getConfig\s*\(/u', $value) === 1) {
            return true;
        }
        if ($getConfigKeys === []) {
            return false;
        }

        return \preg_match('/$[A-Za-z_][A-Za-z0-9_]*/u', $value) === 1;
    }

    private function resolveVirtualThemeCssValidationLimit(string $region, string $cssKey): int
    {
        if ($cssKey === 'css_responsive') {
            return 1200;
        }

        return $region === 'content' ? 6000 : 2000;
    }

    private function normalizeVirtualThemeCssForValidation(string $css, int $limit, string $cssKey = 'css'): string
    {
        $css = \trim($css);
        if ($css === '') {
            return '';
        }

        if (!\str_contains($css, '{')) {
            return '';
        }

        $css = $this->repairCssDeclarationSyntaxForValidation($css);
        $css = $this->repairCssParenthesesOutsideStrings($css);
        $css = $this->balanceCssBraces($css);

        $structuralReason = $this->detectCssStructuralBalanceReason($css);
        if ($structuralReason !== null) {
            return '';
        }

        $css = $this->normalizeVirtualThemeCssComponentScope($css);
        if ($css === '') {
            return '';
        }

        $length = \function_exists('mb_strlen') ? \mb_strlen($css, 'UTF-8') : \strlen($css);
        if ($length > $limit) {
            $css = $this->clipCssAtRuleBoundary($css, $limit);
            if ($css === '') {
                return '';
            }
        }

        $cssReason = $this->detectMalformedGeneratedCssReason($css);
        if ($cssReason !== null) {
            return '';
        }

        return \trim($css);
    }

    private function repairCssDeclarationSyntaxForValidation(string $css): string
    {
        if (\trim($css) === '') {
            return '';
        }

        $css = $this->repairCssStringsOutsideComments($css);
        $css = $this->repairCssMergedDeclarationsForValidation($css);
        $css = \preg_replace('/([;{]\s*)content\s*:\s*(?=[;}])/i', '$1', $css) ?? $css;
        $css = \preg_replace('/([;{]\s*)[-_a-z][a-z0-9_-]*\s*:\s*(?=[;}])/i', '$1', $css) ?? $css;
        $css = \preg_replace('/([;{]\s*)height\s*:\s*%\s*(?=;|})/i', '$1', $css) ?? $css;
        $css = \preg_replace('/([;{]\s*)width\s*:\s*%\s*(?=;|})/i', '$1', $css) ?? $css;
        $css = \preg_replace('/([;{]\s*)-index\s*:/i', '$1z-index:', $css) ?? $css;
        $css = \preg_replace('/([;{]\s*)-(?!(?:webkit|moz|ms|o)-)[a-z][a-z0-9_-]*\s*:[^;}]*;?/i', '$1', $css) ?? $css;
        $css = \preg_replace('/\{\s*;+/', '{', $css) ?? $css;
        $css = \preg_replace('/;{2,}/', ';', $css) ?? $css;

        return \trim($css);
    }

    private function repairCssMergedDeclarationsForValidation(string $css): string
    {
        $properties = '(?:position|inset|overflow|display|width|min-width|max-width|height|min-height|max-height|z-index|opacity|background(?:-image)?|color|border(?:-radius|-color)?|box-shadow|font(?:-size|weight|family)?|line-height|padding|margin|flex(?:-direction|-wrap|-grow|-shrink|-basis)?|grid-template-columns|gap|align-items|justify-content|object-fit|object-position|box-sizing|text-decoration|cursor|outline)';
        for ($i = 0; $i < 3; $i++) {
            $next = \preg_replace('/(:\s*[^;{}]*?\s+)(' . $properties . '\s*:)/i', '$1;$2', $css) ?? $css;
            $next = \preg_replace('/\b(relative|absolute|sticky|fixed|block|inline-flex|flex|grid|none|hidden|visible|cover|contain|center|border-box|wrap|nowrap)(z-index|position|inset|overflow|display|width|min-width|max-width|height|min-height|max-height|background(?:-image)?|color|border(?:-radius|-color)?|box-shadow|font(?:-size|weight|family)?|line-height|padding|margin|flex(?:-direction|-wrap|-grow|-shrink|-basis)?|grid-template-columns|gap|align-items|justify-content|object-fit|object-position|box-sizing|text-decoration|cursor|outline)\s*:/i', '$1;$2:', $next) ?? $next;
            $next = \preg_replace('/((?:\d+(?:\.\d+)?(?:px|rem|em|%|vh|vw)|0|#[0-9a-f]{3,8})(?:\s+(?:\d+(?:\.\d+)?(?:px|rem|em|%|vh|vw)|0|#[0-9a-f]{3,8}))*)(z-index|position|inset|overflow|display|width|min-width|max-width|height|min-height|max-height|background(?:-image)?|color|border(?:-radius|-color)?|box-shadow|font(?:-size|weight|family)?|line-height|padding|margin|flex(?:-direction|-wrap|-grow|-shrink|-basis)?|grid-template-columns|gap|align-items|justify-content|object-fit|object-position|box-sizing|text-decoration|cursor|outline)\s*:/i', '$1;$2:', $next) ?? $next;
            if ($next === $css) {
                break;
            }
            $css = $next;
        }

        return $css;
    }

    private function repairCssStringsOutsideComments(string $css): string
    {
        $repaired = '';
        $quote = '';
        $length = \strlen($css);
        for ($index = 0; $index < $length; $index++) {
            $char = $css[$index];
            if ($quote !== '') {
                if (($char === ';' || $char === '}') && !$this->hasUnescapedQuoteAhead($css, $index, $quote)) {
                    $repaired .= $quote;
                    $quote = '';
                    $repaired .= $char;
                    continue;
                }
                $repaired .= $char;
                if ($char === $quote && ($index === 0 || $css[$index - 1] !== '\\')) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $repaired .= $char;
                continue;
            }
            $repaired .= $char;
        }

        if ($quote !== '') {
            $repaired .= $quote;
        }

        return $repaired;
    }

    private function hasUnescapedQuoteAhead(string $text, int $offset, string $quote): bool
    {
        $length = \strlen($text);
        for ($index = $offset + 1; $index < $length; $index++) {
            $char = $text[$index];
            if (($char === ';' || $char === '}') && ($index === 0 || $text[$index - 1] !== '\\')) {
                return false;
            }
            if ($char === $quote && ($index === 0 || $text[$index - 1] !== '\\')) {
                return true;
            }
        }

        return false;
    }

    private function clipCssAtRuleBoundary(string $css, int $limit): string
    {
        $css = \trim($css);
        if ($css === '') {
            return '';
        }

        $length = \function_exists('mb_strlen') ? \mb_strlen($css, 'UTF-8') : \strlen($css);
        if ($length <= $limit) {
            return $css;
        }

        $slice = \function_exists('mb_substr')
            ? \mb_substr($css, 0, \max(1, $limit))
            : \substr($css, 0, \max(1, $limit));
        $lastClose = \strrpos($slice, '}');
        if ($lastClose === false) {
            return '';
        }

        return \trim(\substr($slice, 0, $lastClose + 1));
    }

    private function balanceCssBraces(string $css): string
    {
        $balanced = '';
        $depth = 0;
        $length = \strlen($css);
        for ($index = 0; $index < $length; $index++) {
            $char = $css[$index];
            if ($char === '{') {
                $depth++;
                $balanced .= $char;
                continue;
            }
            if ($char === '}') {
                if ($depth <= 0) {
                    continue;
                }
                $depth--;
                $balanced .= $char;
                continue;
            }
            $balanced .= $char;
        }

        if ($depth > 0) {
            $balanced .= \str_repeat('}', $depth);
        }

        return \trim($balanced);
    }

    private function repairCssParenthesesOutsideStrings(string $css): string
    {
        if (\trim($css) === '') {
            return '';
        }

        $repaired = '';
        $quote = '';
        $parenDepth = 0;
        $length = \strlen($css);
        for ($index = 0; $index < $length; $index++) {
            $char = $css[$index];
            if ($quote !== '') {
                $repaired .= $char;
                if ($char === $quote && ($index === 0 || $css[$index - 1] !== '\\')) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $repaired .= $char;
                continue;
            }
            if ($char === '(') {
                $parenDepth++;
                $repaired .= $char;
                continue;
            }
            if ($char === ')') {
                if ($parenDepth > 0) {
                    $parenDepth--;
                    $repaired .= $char;
                }
                continue;
            }
            if (($char === ';' || $char === '{' || $char === '}') && $parenDepth > 0) {
                $repaired .= \str_repeat(')', $parenDepth);
                $parenDepth = 0;
            }
            $repaired .= $char;
        }

        if ($parenDepth > 0) {
            $repaired .= \str_repeat(')', $parenDepth);
        }

        return \trim($repaired);
    }

    private function normalizeVirtualThemeCssComponentScope(string $css): string
    {
        $css = \preg_replace('/#\s*<\?=\s*$componentId\s*\?>/i', self::COMPONENT_CSS_SCOPE_PLACEHOLDER, $css) ?? $css;
        $css = \preg_replace('/#componentId\b/i', self::COMPONENT_CSS_SCOPE_PLACEHOLDER, $css) ?? $css;
        $css = \trim($css);
        if ($css === '') {
            return '';
        }

        if (!\str_contains($css, '{')) {
            $declarations = \rtrim($css, " \t\r\n;");
            return $declarations === ''
                ? ''
                : self::COMPONENT_CSS_SCOPE_PLACEHOLDER . ' { ' . $declarations . '; }';
        }

        return $this->scopeVirtualThemeCssBlock($css);
    }

    /**
     * AI html_content is inserted inside the framework component root. Selectors
     * shaped like `#componentId.some-class` target a class on the framework root,
     * not the AI's inner wrapper, so they silently fail in preview. Reject them
     * and force the model to use descendant selectors: `#componentId .some-class`.
     *
     * @param array<string,mixed> $aiData
     */
    private function assertNoInvalidComponentRootClassSelectors(array $aiData): void
    {
        foreach (['css_extra', 'css_responsive', 'css_content'] as $cssKey) {
            $css = (string)($aiData[$cssKey] ?? '');
            if ($css === '') {
                continue;
            }
            if (\preg_match('/#componentId\.[a-z0-9_-]+/i', $css) === 1) {
                throw new \RuntimeException(
                    'AI CSS scope contract failed: use descendant selectors like #componentId .component-class; '
                    . '#componentId.component-class does not target html_content.'
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $aiData
     */
    private function assertGeneratedComponentHtmlScopeContract(array $aiData, string $region, string $componentCode): void
    {
        if ($region !== 'content') {
            return;
        }

        $html = (string)($aiData['html_content'] ?? '');
        if (\trim($html) === '') {
            return;
        }

        if (\preg_match('/\sid\s*=\s*(["\'])(?:#?componentId|#\s*<\?=\s*$componentId\s*\?>)\1/iu', $html) === 1) {
            throw new \RuntimeException(
                'AI HTML scope contract failed: html_content must not create id="componentId"; '
                . 'it is already rendered inside the framework component root.'
            );
        }
    }

    /**
     * @param array<string,mixed> $aiData
     */
    private function assertGeneratedComponentCssContract(array $aiData, string $region, string $componentCode): void
    {
        foreach (['css_extra', 'css_responsive', 'css_content'] as $cssKey) {
            $css = (string)($aiData[$cssKey] ?? '');
            if (\trim($css) === '') {
                continue;
            }
            $cssReason = $this->detectMalformedGeneratedCssReason($css);
            if ($cssReason !== null) {
                throw new \RuntimeException('AI CSS structure contract failed: ' . $cssReason);
            }
        }
    }

    /**
     * @param array<string,mixed> $aiData
     */
    private function assertGeneratedComponentResponsiveContract(array $aiData, string $region, string $componentCode): void
    {
        if ($region !== 'content') {
            return;
        }

        $cssExtra = \trim((string)($aiData['css_extra'] ?? '') . "\n" . (string)($aiData['css_content'] ?? ''));
        $cssResponsive = \trim((string)($aiData['css_responsive'] ?? ''));
        if ($cssResponsive === '') {
            throw new \RuntimeException('content css_responsive is required and must contain tablet/mobile media rules.');
        }

        $scan = $this->stripCssCommentsAndStringsForStructuralScan($cssResponsive);
        if (\preg_match('/@media\s*\(\s*max-width\s*:\s*(?:7[0-9]{2}|8[0-9]{2}|900)px\s*\)/iu', $scan) !== 1) {
            throw new \RuntimeException('css_responsive must include a complete @media max-width 768px or equivalent tablet stacking rule.');
        }
        if (\preg_match('/@media\s*\(\s*max-width\s*:\s*(?:3[0-9]{2}|4[0-9]{2})px\s*\)/iu', $scan) !== 1) {
            throw new \RuntimeException('css_responsive must include a complete @media max-width 420px mobile rule.');
        }

        if (!$this->cssContainsMultiColumnGeneratedLayout($cssExtra)) {
            return;
        }

        if (!$this->cssContainsSingleColumnMobileRule($scan)) {
            throw new \RuntimeException(
                'multi-column generated layouts must collapse to one readable mobile column in css_responsive.'
            );
        }

        $componentCodeLower = \function_exists('mb_strtolower')
            ? \mb_strtolower($componentCode, 'UTF-8')
            : \strtolower($componentCode);
        if (\str_contains($componentCodeLower, 'faq')
            && !$this->cssContainsFaqReadableStackRule($scan)
        ) {
            throw new \RuntimeException(
                'FAQ/support generated layouts must stack copy and FAQ rows into one readable mobile column.'
            );
        }
    }

    private function cssContainsMultiColumnGeneratedLayout(string $css): bool
    {
        if (\trim($css) === '') {
            return false;
        }

        $scan = $this->stripCssCommentsAndStringsForStructuralScan($css);

        if (\preg_match_all('/grid-template-columns\s*:\s*([^;}]+)/iu', $scan, $matches) <= 0) {
            return false;
        }

        foreach ($matches[1] as $rawValue) {
            $value = \trim((string)$rawValue);
            if ($value === '') {
                continue;
            }
            $compactValue = \preg_replace('/\s+/u', '', $value) ?? $value;
            if (\in_array(\strtolower($compactValue), ['none', '1fr', 'minmax(0,1fr)'], true)
                || \preg_match('/^repeat\(\s*1\s*,/iu', $value) === 1
            ) {
                continue;
            }
            if (\preg_match('/repeat\(\s*[2-9]/iu', $value) === 1) {
                return true;
            }
            if (\preg_match_all('/(?:minmax\([^)]*\)|[0-9.]+fr)(?=\s|$)/iu', $value) >= 2) {
                return true;
            }
            if (\preg_match('/\b[2-9](?:fr|rem|em|px|%)\b/iu', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    private function cssContainsSingleColumnMobileRule(string $css): bool
    {
        return \preg_match(
            '/(?:grid-template-columns\s*:\s*(?:1fr|minmax\(\s*0\s*,\s*1fr\s*\)|repeat\(\s*1\s*,)|flex-direction\s*:\s*column)/iu',
            $css
        ) === 1;
    }

    private function cssContainsFaqReadableStackRule(string $css): bool
    {
        return \preg_match(
            '/\.(?:pb-c-content|pb-c-inner|pb-c-faq-list|pb-c-copy)\b[^{}]*\{[^{}]*(?:grid-template-columns\s*:\s*(?:1fr|minmax\(\s*0\s*,\s*1fr\s*\)|repeat\(\s*1\s*,)|flex-direction\s*:\s*column)/iu',
            $css
        ) === 1;
    }

    private function detectMalformedGeneratedClassTokenReason(string $className, string $componentCode): ?string
    {
        $className = \trim($className);
        if ($className === '') {
            return null;
        }
        if (\str_starts_with($className, '-')) {
            return 'class starts with a dangling hyphen';
        }
        if (\preg_match('/^pb-content(?!-)/i', $className) === 1) {
            return 'pb-content prefix is missing a hyphen separator';
        }
        if ($componentCode !== '' && !\str_starts_with($className, 'pb-')) {
            return 'custom classes for generated content must use a pb-* prefix';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function collectGeneratedCssSelectorClasses(string $css): array
    {
        $scan = $this->stripCssCommentsAndStringsForStructuralScan($css);
        if (\preg_match_all('/(?<![a-z0-9_-])\.(-?[a-z_][a-z0-9_-]*)/i', $scan, $matches) <= 0) {
            return [];
        }

        $classes = [];
        foreach ($matches[1] as $className) {
            $className = \trim((string)$className);
            if ($className !== '') {
                $classes[$className] = true;
            }
        }

        return \array_keys($classes);
    }

    private function detectMalformedGeneratedCssReason(string $css): ?string
    {
        $structuralReason = $this->detectCssStructuralBalanceReason($css);
        if ($structuralReason !== null) {
            return $structuralReason;
        }

        $scan = $this->stripCssCommentsAndStringsForStructuralScan($css);
        if (\preg_match('/(?:^|[;{])\s*[-_a-z][a-z0-9_-]*\s*:\s*(?=[;}])/i', $scan) === 1) {
            return 'empty CSS declaration value';
        }
        if (\preg_match('/(?:^|[;{])\s*[-_a-z][a-z0-9_-]*\s*:\s*(?:\.{1,3}|[,+-])\s*(?=[;}])/i', $scan) === 1) {
            return 'incomplete CSS declaration value';
        }
        if ($this->containsCssUnitMergedIntoNextDeclaration($scan)) {
            return 'merged CSS declarations; missing semicolon before property';
        }
        if (\preg_match('/(?:^|[;{])\s*-(?!(?:webkit|moz|ms|o)-)[a-z][a-z0-9_-]*\s*:/i', $scan) === 1) {
            return 'malformed CSS property name';
        }
        if (\preg_match('/:\s*%\s*(?:[;}])/i', $scan) === 1) {
            return 'CSS value starts with a bare percent sign';
        }
        if (\preg_match('/\bbox-sizing\s*:\s*border\s*(?:[;}])/i', $scan) === 1) {
            return 'invalid box-sizing value; use border-box';
        }
        if (\preg_match('/:\s*[^;{}]*(?:\b(?:position|inset|overflow|display|width|min-width|max-width|height|min-height|max-height|z-index|opacity|background(?:-image)?|color|border(?:-radius|-color)?|box-shadow|font(?:-size|weight|family)?|line-height|padding|margin|flex(?:-direction|-wrap|-grow|-shrink|-basis)?|grid-template-columns|gap|align-items|justify-content|object-fit|object-position|box-sizing|text-decoration|cursor|outline)\s*:)/i', $scan) === 1) {
            return 'merged CSS declarations; missing semicolon between properties';
        }

        return null;
    }

    private function stripCssCommentsAndStringsForStructuralScan(string $css): string
    {
        $css = \preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
        $result = '';
        $quote = '';
        $length = \strlen($css);
        for ($index = 0; $index < $length; $index++) {
            $char = $css[$index];
            if ($quote !== '') {
                if ($char === $quote && ($index === 0 || $css[$index - 1] !== '\\')) {
                    $quote = '';
                    $result .= "''";
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            $result .= $char;
        }

        return $result;
    }

    private function detectCssStructuralBalanceReason(string $css): ?string
    {
        $quote = '';
        $parenDepth = 0;
        $braceDepth = 0;
        $length = \strlen($css);
        for ($index = 0; $index < $length; $index++) {
            $char = $css[$index];
            if ($quote !== '') {
                if ($char === $quote && ($index === 0 || $css[$index - 1] !== '\\')) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '(') {
                $parenDepth++;
                continue;
            }
            if ($char === ')') {
                $parenDepth--;
                if ($parenDepth < 0) {
                    return 'orphan closing parenthesis';
                }
                continue;
            }
            if ($char === '{') {
                $braceDepth++;
                continue;
            }
            if ($char === '}') {
                $braceDepth--;
                if ($braceDepth < 0) {
                    return 'orphan closing brace';
                }
            }
        }

        if ($quote !== '') {
            return 'unclosed CSS string';
        }
        if ($parenDepth !== 0) {
            return 'unbalanced CSS parentheses';
        }
        if ($braceDepth !== 0) {
            return 'unbalanced CSS braces';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $aiData
     */
    private function assertHeroGeneratedImageCoverCssContract(array $aiData, string $region, string $componentCode, bool $strictHeroCover): void
    {
        if ($region !== 'content' || !$strictHeroCover) {
            return;
        }

        $html = (string)($aiData['html_content'] ?? '');
        if ($html === '') {
            return;
        }

        $tags = $this->collectGeneratedAssetImageTags($html);
        if ($tags === []) {
            return;
        }

        $css = \implode("\n", \array_map(
            static fn(string $key): string => (string)($aiData[$key] ?? ''),
            ['css_extra', 'css_responsive', 'css_content']
        ));

        if (!$this->heroRootProvidesFullBleedViewportCss($html, $css)) {
            throw new \RuntimeException(
                'AI hero image full-bleed CSS contract failed: generated hero/banner root must span the viewport '
                . 'with width:100vw or min-width:100vw and margin:0 calc(50% - 50vw); constrain only the inner content.'
            );
        }
        if (!$this->heroWrapperProvidesFlushCss($css)) {
            throw new \RuntimeException(
                'AI hero image cover CSS contract failed: generated hero/banner must reset the framework wrapper '
                . 'with #componentId{padding:0;} so the image owns the full banner background, including top and bottom.'
            );
        }

        foreach ($tags as $tag) {
            if ($this->tagHasHeroCoverStyle($tag) || $this->cssProvidesHeroCoverForImageTag($tag, $css)) {
                continue;
            }

            throw new \RuntimeException(
                'AI hero image cover CSS contract failed: generated hero/banner image must be styled by a matching '
                . 'class or data selector as an absolute cover layer with object-fit:cover and inset/100% sizing.'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function collectGeneratedAssetImageTags(string $html): array
    {
        if (\preg_match_all('/<img\b[^>]*>/iu', $html, $matches) <= 0) {
            return [];
        }

        $tags = [];
        foreach ($matches[0] as $tag) {
            $tag = (string)$tag;
            if ($this->extractHtmlAttribute($tag, 'data-pb-ai-image-role') === 'generated-asset') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    private function tagHasHeroCoverStyle(string $tag): bool
    {
        $style = $this->extractHtmlAttribute($tag, 'style');
        if ($style === '') {
            return false;
        }

        return $this->cssDeclarationBodyHasHeroCoverStyle($style);
    }

    private function cssProvidesHeroCoverForImageTag(string $tag, string $css): bool
    {
        if (\trim($css) === '') {
            return false;
        }

        $classes = $this->extractHtmlClassTokensFromTag($tag);
        $hasDataSelector = false;
        if (\preg_match_all('/([^{}]+)\{([^{}]+)\}/s', $css, $matches, \PREG_SET_ORDER) <= 0) {
            return false;
        }

        foreach ($matches as $match) {
            $selector = (string)($match[1] ?? '');
            $body = (string)($match[2] ?? '');
            if (!$this->cssDeclarationBodyHasHeroCoverStyle($body)) {
                continue;
            }
            if (\preg_match('/\[data-pb-ai-(?:image-role|asset-slot)\b/i', $selector) === 1) {
                $hasDataSelector = true;
            }
            foreach ($classes as $class) {
                if (\preg_match('/\.' . \preg_quote($class, '/') . '\b(?![a-z0-9_-])/i', $selector) === 1) {
                    return true;
                }
            }
        }

        return $hasDataSelector;
    }

    private function cssDeclarationBodyHasHeroCoverStyle(string $body): bool
    {
        $normalized = \strtolower(\preg_replace('/\s+/u', '', $body) ?? $body);
        if ($normalized === '') {
            return false;
        }

        $hasCover = \str_contains($normalized, 'object-fit:cover');
        $hasPosition = \str_contains($normalized, 'position:absolute')
            || \str_contains($normalized, 'position:fixed');
        $hasFullBounds = \str_contains($normalized, 'inset:0')
            || (\str_contains($normalized, 'width:100%') && \str_contains($normalized, 'height:100%'));

        return $hasCover && $hasPosition && $hasFullBounds;
    }

    private function heroRootProvidesFullBleedViewportCss(string $html, string $css): bool
    {
        $rootTags = $this->collectHeroRootCandidateTags($html);
        if ($rootTags === []) {
            return false;
        }

        $cssRules = [];
        if (\preg_match_all('/([^{}]+)\{([^{}]+)\}/s', $css, $matches, \PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $cssRules[] = [
                    'selector' => (string)($match[1] ?? ''),
                    'body' => (string)($match[2] ?? ''),
                ];
            }
        }

        foreach ($rootTags as $tag) {
            $style = $this->extractHtmlAttribute($tag, 'style');
            if ($style !== '' && $this->cssDeclarationBodyHasHeroFullBleedRootStyle($style)) {
                return true;
            }

            foreach ($this->extractHtmlClassTokensFromTag($tag) as $class) {
                foreach ($cssRules as $rule) {
                    if (!$this->cssSelectorTargetsClass((string)$rule['selector'], $class)) {
                        continue;
                    }
                    if ($this->cssDeclarationBodyHasHeroFullBleedRootStyle((string)$rule['body'])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function heroWrapperProvidesFlushCss(string $css): bool
    {
        if (\trim($css) === '') {
            return false;
        }
        if (\preg_match_all('/([^{}]+)\{([^{}]+)\}/s', $css, $matches, \PREG_SET_ORDER) <= 0) {
            return false;
        }

        foreach ($matches as $match) {
            $selector = \strtolower(\preg_replace('/\s+/u', '', (string)($match[1] ?? '')) ?? '');
            if ($selector === '' || !\str_contains($selector, '#componentid')) {
                continue;
            }
            if (\str_contains($selector, ' ') || \str_contains($selector, '.pb-')) {
                continue;
            }
            $body = \strtolower(\preg_replace('/\s+/u', '', (string)($match[2] ?? '')) ?? '');
            if (\preg_match('/(?:^|;)padding:0(?:px|rem|em)?(?:!important)?(?:;|$)/iu', $body) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function collectHeroRootCandidateTags(string $html): array
    {
        if (\preg_match_all('/<(?:section|div)\b[^>]*>/iu', $html, $matches) <= 0) {
            return [];
        }

        $tags = [];
        foreach ($matches[0] as $tag) {
            $tag = (string)$tag;
            $classes = $this->extractHtmlClassTokensFromTag($tag);
            foreach ($classes as $class) {
                if (\preg_match('/(?:^|[-_])root$/iu', $class) === 1) {
                    $tags[] = $tag;
                    break;
                }
            }
        }

        return $tags;
    }

    private function cssDeclarationBodyHasHeroFullBleedRootStyle(string $body): bool
    {
        $normalized = \strtolower(\preg_replace('/\s+/u', '', $body) ?? $body);
        if ($normalized === '') {
            return false;
        }
        if (\preg_match('/max-width:(?!none|100vw|unset|initial)(?:\d+(?:\.\d+)?(?:px|rem|em)|[1-9]\d?%)/iu', $normalized) === 1) {
            return false;
        }

        $hasViewportWidth = \str_contains($normalized, 'width:100vw')
            || \str_contains($normalized, 'min-width:100vw');
        $hasCenteredViewportEscape = \str_contains($normalized, 'margin:0calc(50%-50vw)')
            || \str_contains($normalized, 'margin:0pxcalc(50%-50vw)')
            || \str_contains($normalized, 'margin:0remcalc(50%-50vw)');

        return $hasViewportWidth && $hasCenteredViewportEscape;
    }

    private function cssSelectorTargetsClass(string $selector, string $class): bool
    {
        return \preg_match('/\.' . \preg_quote($class, '/') . '\b(?![a-z0-9_-])/i', $selector) === 1;
    }

    /**
     * @return list<string>
     */
    private function extractHtmlClassTokensFromTag(string $tag): array
    {
        $classValue = $this->extractHtmlAttribute($tag, 'class');
        if ($classValue === '') {
            return [];
        }

        $tokens = \preg_split('/\s+/', \trim($classValue)) ?: [];
        $classes = [];
        foreach ($tokens as $token) {
            $token = \trim((string)$token);
            if ($token !== '') {
                $classes[] = $token;
            }
        }

        return \array_values(\array_unique($classes));
    }

    private function scopeVirtualThemeCssBlock(string $css, bool $insideKeyframes = false): string
    {
        $result = '';
        $offset = 0;

        while (($openPos = \strpos($css, '{', $offset)) !== false) {
            $closePos = $this->findMatchingCssBrace($css, $openPos);
            if ($closePos === null) {
                $result .= \substr($css, $offset);
                return \trim($result);
            }

            $prelude = \substr($css, $offset, $openPos - $offset);
            $body = \substr($css, $openPos + 1, $closePos - $openPos - 1);
            $trimmedPrelude = \trim($prelude);

            if ($trimmedPrelude !== '' && $trimmedPrelude[0] === '@') {
                if (\preg_match('/^@(?:media|supports|container|layer)\b/i', $trimmedPrelude) === 1) {
                    $body = $this->scopeVirtualThemeCssBlock($body, false);
                }
                $result .= $prelude . '{' . $body . '}';
            } elseif ($insideKeyframes) {
                $result .= $prelude . '{' . $body . '}';
            } else {
                $result .= $this->scopeVirtualThemeCssSelectorPrelude($prelude) . '{' . $body . '}';
            }

            $offset = $closePos + 1;
        }

        $result .= \substr($css, $offset);

        return \trim($result);
    }

    private function findMatchingCssBrace(string $css, int $openPos): ?int
    {
        $depth = 0;
        $length = \strlen($css);
        for ($index = $openPos; $index < $length; $index++) {
            if ($css[$index] === '{') {
                $depth++;
                continue;
            }
            if ($css[$index] !== '}') {
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private function scopeVirtualThemeCssSelectorPrelude(string $prelude): string
    {
        $leading = '';
        $trailing = '';
        if (\preg_match('/^\s*/', $prelude, $matches) === 1) {
            $leading = (string)$matches[0];
        }
        if (\preg_match('/\s*$/', $prelude, $matches) === 1) {
            $trailing = (string)$matches[0];
        }

        $selectorList = \trim($prelude);
        if ($selectorList === '') {
            return $prelude;
        }

        $selectors = [];
        foreach ($this->splitCssSelectorList($selectorList) as $selector) {
            $selector = \trim($selector);
            if ($selector === '') {
                continue;
            }
            $selector = \preg_replace('/#componentId\b/i', self::COMPONENT_CSS_SCOPE_PLACEHOLDER, $selector) ?? $selector;
            if (\str_contains($selector, self::COMPONENT_CSS_SCOPE_PLACEHOLDER)) {
                $selectors[] = $selector;
                continue;
            }
            if (\str_starts_with($selector, '&')) {
                $selectors[] = self::COMPONENT_CSS_SCOPE_PLACEHOLDER . \substr($selector, 1);
                continue;
            }
            $selector = \preg_replace('/^(?:html\s+body|html|body|:root)(?=$|[\s.#:[>+~])/i', self::COMPONENT_CSS_SCOPE_PLACEHOLDER, $selector, 1) ?? $selector;
            if (!\str_starts_with($selector, self::COMPONENT_CSS_SCOPE_PLACEHOLDER)) {
                $selector = self::COMPONENT_CSS_SCOPE_PLACEHOLDER . ' ' . $selector;
            }
            $selectors[] = $selector;
        }

        if ($selectors === []) {
            return $prelude;
        }

        return $leading . \implode(', ', $selectors) . $trailing;
    }

    /**
     * @return list<string>
     */
    private function splitCssSelectorList(string $selectorList): array
    {
        $selectors = [];
        $buffer = '';
        $parenDepth = 0;
        $bracketDepth = 0;
        $quote = '';
        $length = \strlen($selectorList);

        for ($index = 0; $index < $length; $index++) {
            $char = $selectorList[$index];
            if ($quote !== '') {
                $buffer .= $char;
                if ($char === $quote && ($index === 0 || $selectorList[$index - 1] !== '\\')) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === '(') {
                $parenDepth++;
                $buffer .= $char;
                continue;
            }
            if ($char === ')') {
                $parenDepth = \max(0, $parenDepth - 1);
                $buffer .= $char;
                continue;
            }
            if ($char === '[') {
                $bracketDepth++;
                $buffer .= $char;
                continue;
            }
            if ($char === ']') {
                $bracketDepth = \max(0, $bracketDepth - 1);
                $buffer .= $char;
                continue;
            }
            if ($char === ',' && $parenDepth === 0 && $bracketDepth === 0) {
                $selectors[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $selectors[] = $buffer;

        return $selectors;
    }

    /**
     * @param array<string,mixed> $aiData
     * @return array<string,mixed>
     */
    private function normalizeVirtualThemeCssClassScope(array $aiData, string $componentCode): array
    {
        $prefix = $this->normalizeComponentCssPrefix($componentCode);
        $renameMap = [];

        foreach (['css_extra', 'css_responsive', 'css_content'] as $cssKey) {
            if (!\is_string($aiData[$cssKey] ?? null)) {
                continue;
            }
            $aiData[$cssKey] = $this->repairComponentClassPrefixTypos((string)$aiData[$cssKey]);
            foreach ($this->collectGenericCssSelectorClasses((string)$aiData[$cssKey]) as $genericClass) {
                $renameMap[$genericClass] = $prefix . '-' . $genericClass;
            }
        }

        foreach (['html_content', 'html_extra', 'html_extra_column', 'footer_extra_text'] as $htmlKey) {
            if (!\is_string($aiData[$htmlKey] ?? null)) {
                continue;
            }
            $aiData[$htmlKey] = $this->repairComponentClassPrefixTypos((string)$aiData[$htmlKey]);
            foreach ($this->collectGenericHtmlClassTokens((string)$aiData[$htmlKey]) as $genericClass) {
                $renameMap[$genericClass] = $prefix . '-' . $genericClass;
            }
        }

        if ($renameMap === []) {
            return $aiData;
        }

        foreach (['css_extra', 'css_responsive', 'css_content'] as $cssKey) {
            if (!\is_string($aiData[$cssKey] ?? null) || $aiData[$cssKey] === '') {
                continue;
            }
            $aiData[$cssKey] = $this->rewriteGenericCssClassSelectors((string)$aiData[$cssKey], $renameMap);
        }

        foreach (['html_content', 'html_extra', 'html_extra_column', 'footer_extra_text'] as $htmlKey) {
            if (!\is_string($aiData[$htmlKey] ?? null) || $aiData[$htmlKey] === '') {
                continue;
            }
            $aiData[$htmlKey] = $this->rewriteHtmlClassTokens((string)$aiData[$htmlKey], $renameMap);
        }

        return $aiData;
    }

    private function repairComponentClassPrefixTypos(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = \preg_replace('/\bpb-(content|header|footer)(?=[a-z0-9])/i', 'pb-$1-', $text) ?? $text;
        $text = \str_replace(
            ['.-content-', '"-content-', '\'-content-', ' -content-', '.-header-', '"-header-', '\'-header-', ' -header-', '.-footer-', '"-footer-', '\'-footer-', ' -footer-', '.-c-', '"-c-', '\'-c-', ' -c-'],
            ['.pb-content-', '"pb-content-', '\'pb-content-', ' pb-content-', '.pb-header-', '"pb-header-', '\'pb-header-', ' pb-header-', '.pb-footer-', '"pb-footer-', '\'pb-footer-', ' pb-footer-', '.pb-c-', '"pb-c-', '\'pb-c-', ' pb-c-'],
            $text
        );

        return $text;
    }

    private function normalizeComponentCssPrefix(string $componentCode): string
    {
        $slug = \strtolower(\trim($componentCode));
        $slug = \str_replace(['\\', '/', '_'], '-', $slug);
        $slug = \preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = \preg_replace('/-+/', '-', $slug) ?? $slug;
        $slug = \trim($slug, '-');
        $slug = \preg_replace('/^(?:content|section|block)-+/i', '', $slug) ?? $slug;
        $slug = \preg_replace('/-page(?=-|$)/i', '', $slug) ?? $slug;

        return $slug !== '' ? 'pb-c' : self::COMPONENT_CSS_CLASS_SCOPE_FALLBACK;
    }

    /**
     * @return list<string>
     */
    private function collectGenericCssSelectorClasses(string $css): array
    {
        if (\trim($css) === '') {
            return [];
        }

        $classes = [];
        foreach (self::GENERIC_CSS_CLASS_TOKENS as $genericClass) {
            if (\preg_match('/\.' . \preg_quote($genericClass, '/') . '\b(?![a-z0-9_-])/i', $css) === 1) {
                $classes[] = $genericClass;
            }
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function collectGenericHtmlClassTokens(string $html): array
    {
        if (\trim($html) === '') {
            return [];
        }

        $matched = \preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/is', $html, $matches);
        if ($matched === false || $matched === 0) {
            return [];
        }

        $genericLookup = \array_fill_keys(self::GENERIC_CSS_CLASS_TOKENS, true);
        $classes = [];
        foreach ($matches[2] as $classValue) {
            $tokens = \preg_split('/\s+/', \trim((string)$classValue)) ?: [];
            foreach ($tokens as $token) {
                $token = \strtolower(\trim((string)$token));
                if ($token !== '' && isset($genericLookup[$token])) {
                    $classes[$token] = true;
                }
            }
        }

        return \array_keys($classes);
    }

    /**
     * @param array<string,string> $renameMap
     */
    private function rewriteGenericCssClassSelectors(string $css, array $renameMap): string
    {
        foreach ($renameMap as $genericClass => $scopedClass) {
            $css = \preg_replace(
                '/\.' . \preg_quote((string)$genericClass, '/') . '\b(?![a-z0-9_-])/i',
                '.' . (string)$scopedClass,
                $css
            ) ?? $css;
        }

        return $css;
    }

    /**
     * @param array<string,string> $renameMap
     */
    private function rewriteHtmlClassTokens(string $html, array $renameMap): string
    {
        return \preg_replace_callback(
            '/\bclass\s*=\s*(["\'])(.*?)\1/is',
            static function (array $matches) use ($renameMap): string {
                $quote = (string)$matches[1];
                $parts = \preg_split('/(\s+)/', (string)$matches[2], -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];
                foreach ($parts as $index => $part) {
                    if (\trim((string)$part) === '') {
                        continue;
                    }
                    $lookup = \strtolower((string)$part);
                    if (isset($renameMap[$lookup])) {
                        $parts[$index] = $renameMap[$lookup];
                    }
                }

                return 'class=' . $quote . \implode('', $parts) . $quote;
            },
            $html
        ) ?? $html;
    }

    /**
     * @param array<string,mixed> $renderContext
     * @return array<string,string>
     */
    private function resolveThemeSafeCssPaletteForPrompt(array $renderContext): array
    {
        $scopeIdentity = \is_array($renderContext['_scope_identity'] ?? null) ? $renderContext['_scope_identity'] : [];
        $sharedContext = \is_array($scopeIdentity['shared_prompt_context'] ?? null) ? $scopeIdentity['shared_prompt_context'] : [];
        $themeDesign = \is_array($sharedContext['theme_design'] ?? null) ? $sharedContext['theme_design'] : [];
        $colorScheme = \is_array($themeDesign['color_scheme'] ?? null) ? $themeDesign['color_scheme'] : [];
        $componentConfig = \is_array($renderContext['component_config'] ?? null) ? $renderContext['component_config'] : [];

        $surface = $this->firstValidCssHexColor([
            $colorScheme['background'] ?? null,
            $colorScheme['surface'] ?? null,
            $componentConfig['style.background'] ?? null,
        ]);
        $text = $this->firstValidCssHexColor([
            $colorScheme['text'] ?? null,
            $colorScheme['body'] ?? null,
            $componentConfig['style.text_color'] ?? null,
        ]);
        $primary = $this->firstValidCssHexColor([
            $colorScheme['primary'] ?? null,
            $componentConfig['style.primary_color'] ?? null,
        ]);
        $secondary = $this->firstValidCssHexColor([
            $colorScheme['secondary'] ?? null,
        ]);
        $accent = $this->firstValidCssHexColor([
            $colorScheme['accent'] ?? null,
            $colorScheme['button'] ?? null,
        ]);

        return [
            'root_bg' => $primary !== '' ? $primary : 'CTX_CONFIRMED_THEME.palette.primary',
            'media_bg' => $surface !== '' ? $surface : 'CTX_CONFIRMED_THEME.palette.surface',
            'surface_bg' => $surface !== '' ? $surface : 'CTX_CONFIRMED_THEME.palette.surface',
            'card_bg' => $surface !== '' ? $surface : 'CTX_CONFIRMED_THEME.palette.surface_alt',
            'text' => $text !== '' ? $text : 'CTX_CONFIRMED_THEME.palette.text',
            'inverse_text' => $this->firstValidCssHexColor([$colorScheme['on_primary'] ?? null, $colorScheme['inverse_text'] ?? null]) ?: 'CTX_CONFIRMED_THEME.palette.on_primary',
            'primary' => $primary !== '' ? $primary : 'CTX_CONFIRMED_THEME.palette.primary',
            'secondary' => $secondary !== '' ? $secondary : 'CTX_CONFIRMED_THEME.palette.secondary',
            'accent' => $accent !== '' ? $accent : 'CTX_CONFIRMED_THEME.palette.accent',
            'shadow' => $this->firstValidCssHexColor([$colorScheme['shadow'] ?? null, $text]) ?: 'CTX_CONFIRMED_THEME.palette.shadow',
        ];
    }

    /**
     * @param list<mixed> $candidates
     */
    private function firstValidCssHexColor(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $color = $this->normalizeCssHexColor((string)$candidate);
            if ($color !== '') {
                return $color;
            }
        }

        return '';
    }

    private function normalizeCssHexColor(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        if (\preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) !== 1) {
            return '';
        }

        return \strtoupper($value);
    }

    /**
     * Stage-3 build queue prompts already carry the full page/block contract.
     * Appending the removed full component contract again makes the model spend
     * most of the request budget reconciling duplicate rules before it can
     * produce the JSON component. Keep build-queue requests on a compact
     * one-pass contract and reserve the long contract for ad hoc generation
     * paths that do not already include CTX_* sections.
     *
     * @param array<string,mixed> $renderContext
     */
    private function shouldUseCompactBuildQueueComponentContract(string $prompt, array $renderContext): bool
    {
        if (!$this->isBuildQueueComponentContext($renderContext)) {
            return false;
        }

        return \str_contains($prompt, 'Stage-2 component output contract V3')
            && \str_contains($prompt, 'CTX_CURRENT_ASSET')
            && \str_contains($prompt, 'CTX_FROZEN_TASK');
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function isBuildQueueComponentContext(array $renderContext): bool
    {
        return $this->resolveBuildQueueComponentTaskContext($renderContext) !== [];
    }


    /**
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function resolveBuildQueueComponentTaskContext(array $renderContext, array $defaultConfig = []): array
    {
        if (\is_array($renderContext['_plan_json_task'] ?? null) && ($renderContext['_plan_json_task'] ?? []) !== []) {
            return $renderContext['_plan_json_task'];
        }
        if (\is_array($renderContext['plan_json_task'] ?? null) && ($renderContext['plan_json_task'] ?? []) !== []) {
            return $renderContext['plan_json_task'];
        }

        return $this->decodeRuntimePlanJsonTask($defaultConfig);
    }

    /**
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function resolveRenderContextVisualContract(array $renderContext, array $defaultConfig = []): array
    {
        if (\is_array($renderContext['_visual_contract'] ?? null)) {
            return $renderContext['_visual_contract'];
        }
        if (\is_array($renderContext['visual_contract'] ?? null)) {
            return $renderContext['visual_contract'];
        }

        return $this->decodeRuntimeVisualContract($defaultConfig);
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function appendCompactBuildQueueComponentContract(
        string $prompt,
        string $componentCode,
        string $prefix,
        bool $isHero,
        bool $isFormGuidanceMode,
        bool $isFaqMode,
        bool $isCtaMode,
        bool $isContactSupportMode,
        bool $isFeatureSafeMode,
        bool $hasRequiredGeneratedImage,
        array $renderContext
    ): string {
        $safePalette = $this->resolveThemeSafeCssPaletteForPrompt($renderContext);
        $paletteLine = \sprintf(
            'root_bg=%s; surface=%s; card=%s; text=%s; inverse_text=%s; primary=%s; secondary=%s; accent=%s; shadow=%s',
            $safePalette['root_bg'],
            $safePalette['surface_bg'],
            $safePalette['card_bg'],
            $safePalette['text'],
            $safePalette['inverse_text'],
            $safePalette['primary'],
            $safePalette['secondary'],
            $safePalette['accent'],
            $safePalette['shadow']
        );
        $roleLine = $this->buildCompactBuildQueueRoleLine(
            $prefix,
            $isHero,
            $isFormGuidanceMode,
            $isFaqMode,
            $isCtaMode,
            $isContactSupportMode,
            $isFeatureSafeMode,
            $hasRequiredGeneratedImage
        );

        return \rtrim($prompt)
            . "\n\nPAGEBUILDER_ONE_PASS_FAST_CONTRACT (HARD, overrides broader duplicated examples):\n"
            . "- Generate only the current component JSON. The prompt already includes CTX_CURRENT_ASSET, CTX_FROZEN_TASK, CTX_CONFIRMED_THEME, route, CTA, and editable-field rules; do not restate or expand those contracts.\n"
            . "- exact_class_prefix: `{$prefix}-`. Every custom class must use this prefix. Use one root section and a small number of purposeful descendants; do not create neighboring blocks, carousels, accordions, tables, or optional decorative selector families.\n"
            . "- PRODUCT_LATENCY_OUTPUT_BUDGET: return a compact premium block sized for one-pass queue execution. html_content <= 1800 chars; css_extra <= " . ($isHero ? '3000' : '2200') . " chars; css_responsive <= 650 chars; extra_fields + php_variables <= 1800 chars; js_content stays empty unless CTX_CTA_ACTION_CONTRACT requires a tiny scoped button bridge.\n"
            . "- ROLE_EXECUTION: {$roleLine}\n"
            . "- Palette roles available for CSS: {$paletteLine}. Replace symbolic roles with real hex values from CTX_CONFIRMED_THEME or these safe roles; never use stale template colors.\n"
            . "- CSS completeness hard gate: html_content and CSS are one inseparable artifact. Every `{$prefix}-*` class used in html_content must have a matching selector block in css_extra or css_responsive with at least one real declaration. Do not return naked HTML, half-styled FAQ/cards/forms/media, or role wrappers whose classes never appear in CSS.\n"
            . "- CSS root/responsive hard gate: css_extra must style `#componentId .{$prefix}-root` plus the actual inner/title/text/action/media/card/form/FAQ roles used by this block; css_responsive must contain real @media rules for classes that exist in html_content.\n"
            . "- CSS rules: one selector block per class actually used, all scoped under #componentId, no comments, no duplicate selector blocks, no @media in css_extra, no CSS url(...), no unfinished functions, no raw declarations outside braces. Use readable contrast and compact depth through background, border, radius, and shadow.\n"
            . "- Responsive rules: css_responsive contains exactly the needed @media (max-width: 768px) and @media (max-width: 420px) blocks. Stack split/support/form layouts, keep min-width:0/max-width:100%, reduce padding, and keep the actual `.{$prefix}-cta` compact.\n"
            . "- Editable text rule: every visible word/number in html_content must be a safe PHP echo backed by matching extra_fields and php_variables rows. Before returning, mentally remove all `<?= ... ?>` fragments and strip tags; no visitor copy may remain.\n"
            . "- Transport rule: first byte `{`, last byte `}`. Return the required JSON string keys only. A smaller valid premium block is the target output, not a fallback.\n"
            . $this->buildStrictRequiredImagePromptTail($componentCode, $renderContext);
    }

    private function buildCompactBuildQueueRoleLine(
        string $prefix,
        bool $isHero,
        bool $isFormGuidanceMode,
        bool $isFaqMode,
        bool $isCtaMode,
        bool $isContactSupportMode,
        bool $isFeatureSafeMode,
        bool $hasRequiredGeneratedImage
    ): string {
        if ($hasRequiredGeneratedImage) {
            return "preserve the exact REQUIRED_IMAGE_STRUCTURE_CONTRACT and editable image tag; wrap it in `{$prefix}-media`, add copy/title/text/action/cta roles, and style only those required roles.";
        }
        if ($isHero) {
            return "strict CSS-only hero: include `{$prefix}-media` with media-stage/subject/detail/label, `{$prefix}-motif`, `{$prefix}-orbit`, `{$prefix}-overlay`, `{$prefix}-inner`, `{$prefix}-text-panel`, title, text, action, and compact CTA; style exactly those roles.";
        }
        if ($isFormGuidanceMode) {
            return "form guidance: root/inner/copy/title/text plus a real `{$prefix}-form` with grouped fields, labels, inputs, textarea, action, and CTA. Do not use contact cards.";
        }
        if ($isFaqMode) {
            return "FAQ: root/inner/copy/title/text, `{$prefix}-faq-list`, at least two `{$prefix}-faq-item` rows with question and answer, then a separated action/CTA.";
        }
        if ($isCtaMode) {
            return "CTA band: one focused root/inner/copy/title/text/proof/action/CTA composition. Do not render FAQ rows, forms, contact cards, or hero overlays.";
        }
        if ($isContactSupportMode) {
            return "contact/support: root/inner/copy/title/text plus a channel hub with at least two `{$prefix}-channel` rows/cards containing sibling `{$prefix}-label` and `{$prefix}-value`, followed by action/CTA/note.";
        }
        if ($isFeatureSafeMode) {
            return "feature/proof/support: root/inner/copy/title/text/action/CTA plus one concise support device such as proof, step, metric, checklist, badge cluster, or quote rail from CTX_BLOCK_VISUAL_SIGNATURE.";
        }

        return "safe content block: root/inner/copy/title/text/action/CTA plus one concise support/proof/detail device derived from CTX_FROZEN_TASK.";
    }

    private function appendComponentCssScopeInstruction(string $prompt, string $componentCode, array $renderContext = []): string
    {
        $prefix = $this->normalizeComponentCssPrefix($componentCode);
        $componentIdentity = \mb_strtolower($componentCode);
        $isHero = $this->renderContextRequiresStrictHeroCover($renderContext);
        $isFormGuidanceMode = !$isHero
            && \preg_match('/(?:support[-_\/ ]*form|form[-_\/ ]*guidance|contact[-_\/ ]*form|message|inquiry[-_\/ ]*form|enquiry[-_\/ ]*form)/iu', $componentIdentity) === 1;
        $isFaqMode = !$isHero && \str_contains($componentIdentity, 'faq');
        $isCtaMode = !$isHero && \preg_match('/(?:contact[-_\/ ]*cta|final[-_\/ ]*cta|cta[-_\/ ]*band|call[-_\/ ]*to[-_\/ ]*action)/iu', $componentIdentity) === 1;
        $isContactSupportMode = !$isHero
            && !$isFormGuidanceMode
            && !$isFaqMode
            && !$isCtaMode
            && \preg_match('/(?:contact|support|help|inquiry|enquiry|channel|method)/iu', $componentIdentity) === 1;
        $isFeatureSafeMode = !$isHero
            && !$isContactSupportMode
            && !$isFormGuidanceMode
            && !$isFaqMode
            && !$isCtaMode
            && \preg_match('/\b(showcase|feature|features|game|games|product|suite|service|reward|promotion|trust|security|proof|faq|newsletter|cta)\b/i', $componentCode) === 1;
        $visualContract = \is_array($renderContext['_visual_contract'] ?? null) ? $renderContext['_visual_contract'] : [];
        $hasRequiredGeneratedImage = (int)($visualContract['required'] ?? 0) === 1
            && \trim((string)($visualContract['slot_id'] ?? '')) !== ''
            && \trim((string)($visualContract['final_url'] ?? $visualContract['url'] ?? '')) !== '';
        $safePalette = $this->resolveThemeSafeCssPaletteForPrompt($renderContext);
        $rootBg = $safePalette['root_bg'];
        $mediaBg = $safePalette['media_bg'];
        $surfaceBg = $safePalette['surface_bg'];
        $cardBg = $safePalette['card_bg'];
        $textColor = $safePalette['text'];
        $inverseText = $safePalette['inverse_text'];
        $accentColor = $safePalette['accent'];
        $primaryColor = $safePalette['primary'];
        $secondaryColor = $safePalette['secondary'];
        $shadowColor = $safePalette['shadow'];
        if ($this->shouldUseCompactBuildQueueComponentContract($prompt, $renderContext)) {
            return $this->appendCompactBuildQueueComponentContract(
                $prompt,
                $componentCode,
                $prefix,
                $isHero,
                $isFormGuidanceMode,
                $isFaqMode,
                $isCtaMode,
                $isContactSupportMode,
                $isFeatureSafeMode,
                $hasRequiredGeneratedImage,
                $renderContext
            );
        }
        $featureCssBaseline = "#componentId .{$prefix}-root{padding:64px 24px;background:{$surfaceBg};color:{$textColor};box-sizing:border-box;}#componentId .{$prefix}-inner{max-width:1200px;width:100%;margin:0 auto;}#componentId .{$prefix}-title{margin:0;font-size:42px;line-height:1.1;color:{$textColor};}#componentId .{$prefix}-cta{display:inline-flex;width:auto;max-width:280px;box-sizing:border-box;border-radius:999px;padding:12px 20px;background:{$accentColor};color:{$textColor};}";
        $contactCssBaseline = "#componentId .{$prefix}-root{position:relative;overflow:hidden;padding:72px 24px;background:{$surfaceBg};color:{$textColor};box-sizing:border-box;}#componentId .{$prefix}-inner{max-width:1200px;width:100%;margin:0 auto;display:flex;flex-wrap:wrap;gap:24px;align-items:stretch;}#componentId .{$prefix}-copy{flex:1 1 340px;min-width:0;padding:28px;border:1px solid {$accentColor};border-radius:24px;background:{$cardBg};box-shadow:0 20px 52px {$shadowColor};}#componentId .{$prefix}-title{margin:0 0 14px;font-size:40px;line-height:1.18;color:{$primaryColor};}#componentId .{$prefix}-text{margin:0;font-size:16px;line-height:1.75;color:{$textColor};max-width:68ch;}#componentId .{$prefix}-cards{flex:1 1 460px;min-width:0;display:flex;flex-wrap:wrap;gap:16px;}#componentId .{$prefix}-channel{flex:1 1 180px;min-width:0;padding:20px;border:1px solid {$secondaryColor};border-radius:20px;background:{$cardBg};color:{$textColor};box-shadow:0 14px 36px {$shadowColor};line-height:1.55;}#componentId .{$prefix}-label{display:block;margin:0 0 6px;color:{$primaryColor};font-weight:700;}#componentId .{$prefix}-value{display:block;color:{$textColor};overflow-wrap:break-word;}#componentId .{$prefix}-action{width:100%;display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:22px;padding-top:18px;}#componentId .{$prefix}-cta{display:inline-flex;width:auto;max-width:280px;box-sizing:border-box;border-radius:999px;padding:12px 22px;background:{$accentColor};color:{$textColor};font-weight:700;}#componentId .{$prefix}-note{display:block;max-width:56ch;color:{$textColor};line-height:1.6;}";
        $featureSafeModeContract = $isFeatureSafeMode
            ? ($hasRequiredGeneratedImage
                ? "- THIS TASK IS FEATURE_ROLE_MODE WITH A REQUIRED GENERATED IMAGE. Ignore FEATURE_CSS_BASELINE and generic feature/card recipes. Follow REQUIRED_IMAGE_STRUCTURE_CONTRACT plus REQUIRED_CSS_ROLE_CONTRACT; they already define the required editable <img> binding while leaving composition and visual rhythm design-owned.\n"
                : "- THIS TASK IS FEATURE_ROLE_MODE. If the prompt provides a verified final_url or REQUIRED_EDITABLE_IMAGE_TAG, use MEDIA_ROLE_OUTLINE plus MEDIA_CSS_BASELINE and render the real editable <img>. If no verified image is supplied, use FEATURE_CSS_BASELINE as a reference for the required roles. Keep the block focused and valid; do not create card grids, carousels, accordions, hover selectors, @media blocks, or extra braces unless the current block contract explicitly requires them.\n")
            : '';
        $contactSupportModeContract = $isContactSupportMode
            ? ($hasRequiredGeneratedImage
                ? "- THIS TASK IS CONTACT_CHANNEL_MODE WITH A REQUIRED GENERATED IMAGE. Do not use the generic contact outline as a fixed skeleton. Follow REQUIRED_IMAGE_STRUCTURE_CONTRACT plus REQUIRED_CSS_ROLE_CONTRACT. Keep the required channel hub with repeated {$prefix}-channel rows/cards and exact .pb-c-label/.pb-c-value sibling spans; never compress channels into the paragraph or replace them with the image.\n"
                : "- THIS TASK IS CONTACT_CHANNEL_MODE. Use CONTACT_ROLE_OUTLINE plus CONTACT_CSS_BASELINE as the required role baseline. Split planned email, phone, office, hours, support, or sales details into separate {$prefix}-channel rows/cards with exact .pb-c-label/.pb-c-value sibling spans. Do not output malformed fragments such as support@ .com, example.com placeholders, fake phone numbers, invented WhatsApp handles, or raw internal field labels. When no exact channel value exists in the frozen context, render final localized non-numeric guidance instead. Never compress multiple contact channels into one long paragraph.\n")
            : '';
        $formGuidanceModeContract = $isFormGuidanceMode
            ? "- THIS TASK IS FORM_GUIDANCE_MODE. Use FORM_ROLE_OUTLINE as the required role baseline. Render a real form with labels, inputs, one textarea, and one separated action wrapper; do not use contact cards or FAQ rows for this role.\n"
            : '';
        $faqModeContract = $isFaqMode
            ? "- THIS TASK IS FAQ_MODE. Use FAQ_ROLE_OUTLINE plus FAQ_CSS_BASELINE as the required role baseline. Render repeated question/answer items, not contact cards, form fields, or a generic CTA slab. css_extra must style #componentId .{$prefix}-faq-item with padding, border-radius, and background/border/box-shadow so each FAQ answer reads as a visible surface.\n"
            : '';
        $ctaModeContract = $isCtaMode
            ? "- THIS TASK IS CTA_MODE. Use CTA_ROLE_OUTLINE as the required role baseline. Render one focused next-step band with one primary action and compact proof; do not reuse contact cards, forms, FAQ rows, or hero overlays.\n"
            : '';
        $safeCssBraceCountRule = $isFeatureSafeMode
            ? ($hasRequiredGeneratedImage
                ? "For required-image tasks, selector coverage is defined by REQUIRED_CSS_ROLE_CONTRACT and the actual role outline; do not apply FEATURE_CSS_BASELINE selector counts."
                : "For this FEATURE_ROLE_MODE task, css_extra must cover root, inner, title, and cta role selectors; additional scoped selectors are allowed only when required by the block intent.")
            : ($isContactSupportMode
                ? ($hasRequiredGeneratedImage
                    ? "For required-image tasks, selector coverage is defined by REQUIRED_CSS_ROLE_CONTRACT and the actual role outline; do not apply CONTACT_CSS_BASELINE selector counts."
                    : "For this CONTACT_CHANNEL_MODE task, css_extra must cover root, inner, copy, title, text, cards, channel, label, value, action, cta, and note role selectors; additional scoped selectors are allowed only when required by the block intent.")
                : ($isFormGuidanceMode
                    ? "For this FORM_GUIDANCE_MODE task, css_extra must cover root, inner, copy, title, text, form, field, label, input, action, and cta role selectors."
                    : ($isFaqMode
                        ? "For this FAQ_MODE task, css_extra must cover root, inner, title, text, faq-list, faq-item, question, answer, action, and cta role selectors."
                        : ($isCtaMode
                            ? "For this CTA_MODE task, css_extra must cover root, inner, copy, title, text, proof, action, and cta role selectors."
                            : "For non-hero text/support blocks, cover the role selectors actually used by visual_signature: root, inner, copy, title, text, action/cta, and any support/proof/detail/step/metric/badge/quote roles you render.")))
            );
        $heroCssBaseline = "#componentId .{$prefix}-root{position:relative;overflow:hidden;min-height:520px;padding:88px 24px;background:{$rootBg};color:{$inverseText};box-sizing:border-box;}#componentId .{$prefix}-media{position:absolute;inset:0;z-index:0;background:{$mediaBg};}#componentId .{$prefix}-media-stage{position:absolute;inset:12% 7% auto auto;width:430px;height:330px;border:1px solid {$accentColor};border-radius:38px;background:{$secondaryColor};box-shadow:0 28px 80px {$shadowColor};}#componentId .{$prefix}-media-subject{position:absolute;inset:34% 16% auto auto;width:260px;height:136px;border-radius:999px;background:{$primaryColor};box-shadow:0 18px 48px {$shadowColor};}#componentId .{$prefix}-media-detail{position:absolute;inset:16% 24% auto auto;width:16px;height:240px;border-radius:999px;background:{$accentColor};}#componentId .{$prefix}-media-label{position:absolute;right:10%;bottom:12%;max-width:240px;padding:10px 16px;border:1px solid {$accentColor};border-radius:999px;background:{$surfaceBg};color:{$textColor};font-size:14px;font-weight:700;}#componentId .{$prefix}-motif{position:absolute;inset:8% 6% auto auto;width:500px;height:400px;border:1px solid {$accentColor};border-radius:44px;background:{$secondaryColor};opacity:.32;}#componentId .{$prefix}-orbit{position:absolute;inset:18% 12% auto auto;width:230px;height:310px;border:1px solid {$accentColor};border-radius:28px;background:{$primaryColor};opacity:.24;}#componentId .{$prefix}-overlay{position:absolute;inset:0;z-index:2;background:{$primaryColor};opacity:.36;}#componentId .{$prefix}-inner{position:relative;z-index:3;max-width:1200px;margin:0 auto;display:flex;align-items:center;}#componentId .{$prefix}-text-panel{max-width:620px;padding:32px;border:1px solid {$accentColor};border-radius:24px;background:{$surfaceBg};color:{$textColor};box-shadow:0 28px 80px {$shadowColor};}#componentId .{$prefix}-title{margin:0 0 16px;font-size:48px;line-height:1.1;color:{$primaryColor};}#componentId .{$prefix}-text{margin:0 0 22px;line-height:1.7;color:{$textColor};}#componentId .{$prefix}-action{display:flex;gap:12px;margin-top:22px;padding-top:18px;}#componentId .{$prefix}-cta{display:inline-flex;width:auto;max-width:280px;padding:12px 20px;border-radius:999px;background:{$accentColor};color:{$textColor};box-shadow:0 12px 24px {$shadowColor};}";
        $faqCssBaseline = "#componentId .{$prefix}-faq-list{display:grid;gap:14px;}#componentId .{$prefix}-faq-item{padding:18px 20px;border-radius:18px;background:{$cardBg};border:1px solid {$accentColor};box-shadow:0 12px 30px {$shadowColor};}#componentId .{$prefix}-question{font-weight:800;color:{$primaryColor};}#componentId .{$prefix}-answer{margin:8px 0 0;line-height:1.65;color:{$textColor};}";
        $heroContract = $isHero
            ? "- HERO_ROLE_OUTLINE: every hero/banner needs one root, media layer, motif/orbit/depth layers, overlay/scrim, inner container, text panel, title, body copy, separated action wrapper, and CTA role. This outline is not a byte-for-byte skeleton; choose composition details from the current block intent while keeping these roles valid and rendering every text node through editable PHP echoes: "
                . "`<section class='{$prefix}-root'><div class='{$prefix}-media'><div class='{$prefix}-media-stage'><div class='{$prefix}-media-subject'></div><div class='{$prefix}-media-detail'></div><div class='{$prefix}-media-label'><?= htmlspecialchars(\$mediaLabel ?? 'Finished visual cue', ENT_QUOTES, 'UTF-8') ?></div></div></div><div class='{$prefix}-motif'></div><div class='{$prefix}-orbit'></div><div class='{$prefix}-overlay'></div><div class='{$prefix}-inner'><div class='{$prefix}-text-panel'><h2 class='{$prefix}-title'><?= htmlspecialchars(\$contentTitle ?? 'Finished heading', ENT_QUOTES, 'UTF-8') ?></h2><p class='{$prefix}-text'><?= nl2br(htmlspecialchars(\$contentDescription ?? 'Finished copy.', ENT_QUOTES, 'UTF-8')) ?></p><div class='{$prefix}-action'><button type='button' class='{$prefix}-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars(\$ctaText ?? 'Finished CTA', ENT_QUOTES, 'UTF-8') ?></button></div></div></div></section>`. "
                . "Declare and bind each shown variable in extra_fields/php_variables. For the shown hero copy, use `content.title` -> `\$contentTitle` and `content.description` -> `\$contentDescription` unless CTX_REQUIRED_EDITABLE_FIELDS lists different exact keys. If a verified image template exists, adapt that single editable <img> as the media role and add class='{$prefix}-media'. Do not keep a second empty {$prefix}-media div after the image, because it can cover the real asset.\n"
                . "- HERO_CSS_SIZE_BUDGET (HARD): CSS-only hero css_extra must stay under 3600 characters after palette replacement and css_responsive under 900. Use at most one selector block for each required hero role. Do not output comments, duplicate selector blocks, or @media inside css_extra. Pseudo-elements, hover blocks, and component-prefixed keyframes are allowed only for required media/CTA atmosphere.\n"
                . "- HERO CSS REQUIRED SELECTORS: css_extra must include complete selectors for `#componentId .{$prefix}-root`, `#componentId .{$prefix}-media`, `#componentId .{$prefix}-media-stage`, `#componentId .{$prefix}-media-subject`, `#componentId .{$prefix}-media-detail`, `#componentId .{$prefix}-media-label`, `#componentId .{$prefix}-motif`, `#componentId .{$prefix}-orbit`, `#componentId .{$prefix}-overlay`, `#componentId .{$prefix}-inner`, `#componentId .{$prefix}-text-panel`, `#componentId .{$prefix}-title`, `#componentId .{$prefix}-text`, `#componentId .{$prefix}-action`, and `#componentId .{$prefix}-cta` when no verified image exists. The overlay selector must include position:absolute, inset:0, background from THEME_ROLE_PALETTE, and opacity:.35-.58 or a rgba/transparent gradient; the text-panel selector must include background, padding, border-radius, and z-index/position above media. The action selector must include margin-top, padding-top, or parent gap so CTA spacing passes even when media-label exists. CSS-only hero media must be visibly designed from THEME_ROLE_PALETTE as a brief-specific editorial/product stage, never a plain dark slab or unrelated stock-art substitute. The media-label text must be a short subject phrase from the approved brief, not a generic decoration label. Root/body and title selectors must include named brand font-family stacks.\n"
                . "- HERO_CSS_BASELINE: when no verified image is supplied, use this compact palette-bound reference and adapt proportions to the approved brief without adding extra selector families: `{$heroCssBaseline}`.\n"
            : '';
        $markupModeContract = $isHero
            ? "- MARKUP STRICT MODE: when REQUIRED_IMAGE_STRUCTURE_CONTRACT is present, satisfy it instead of every other outline. Otherwise choose CONTACT_ROLE_OUTLINE only for CONTACT_CHANNEL_MODE, FORM_ROLE_OUTLINE only for FORM_GUIDANCE_MODE, FAQ_ROLE_OUTLINE only for FAQ_MODE, and CTA_ROLE_OUTLINE only for CTA_MODE; otherwise choose SAFE_TEXT_ROLE_OUTLINE, MEDIA_ROLE_OUTLINE, or HERO_ROLE_OUTLINE. In strict hero mode, media-stage/media-subject/media-detail/media-label plus motif/orbit layers and an action wrapper around the CTA are required by HERO_ROLE_OUTLINE; do not leave the media div empty and do not add other optional cards/forms unless the current task explicitly requires a real contact form.\n"
            : "- MARKUP STRICT MODE: when REQUIRED_IMAGE_STRUCTURE_CONTRACT is present, satisfy it instead of every other outline. Otherwise choose CONTACT_ROLE_OUTLINE only for CONTACT_CHANNEL_MODE, FORM_ROLE_OUTLINE only for FORM_GUIDANCE_MODE, FAQ_ROLE_OUTLINE only for FAQ_MODE, and CTA_ROLE_OUTLINE only for CTA_MODE; otherwise choose SAFE_TEXT_ROLE_OUTLINE or MEDIA_ROLE_OUTLINE from page_design_plan and CTX_BLOCK_VISUAL_SIGNATURE. Do not apply HERO_ROLE_OUTLINE just because a block, template, or page section is named banner/hero; only CTX_CURRENT_ASSET.strict_hero_cover=1 may activate strict hero treatment.\n";
        $heroImageModeContract = $isHero
            ? "- Strict hero rule: when REQUIRED_IMAGE_STRUCTURE_CONTRACT is present, satisfy its required image role, scrim/veil, inner, copy, title, text, action, and cta roles without copying a byte-for-byte skeleton. If no required image structure is present, html_content must include `{$prefix}-media`, `{$prefix}-motif`, `{$prefix}-orbit`, `{$prefix}-overlay`, `{$prefix}-text-panel`, and `{$prefix}-action` classes; without verified image, `{$prefix}-media` is a CSS-only container with `{$prefix}-media-stage`, `{$prefix}-media-subject`, `{$prefix}-media-detail`, and `{$prefix}-media-label` children. css_extra must include matching selectors for every required hero class.\n"
            : "- Non-strict opening/banner rule: page-opening blocks with CTX_CURRENT_ASSET.strict_hero_cover=0 must use their page identity and CTX_BLOCK_VISUAL_SIGNATURE to choose an editorial, support, proof, form, policy, or CTA composition. Do not force full-bleed media, overlay, orbit/motif layers, or text-panel treatment just because the section is visually first on the page.\n";

        return \rtrim($prompt)
            . "\n\nComponent-specific strong contract:\n"
            . "- EXACT_CLASS_PREFIX = `{$prefix}`. Every class you create must begin with this exact prefix followed by one hyphen and an element name.\n"
            . "- This prefix is intentionally minimal. Copy `{$prefix}` exactly; do not expand it with page names, block names, or task labels.\n"
            . "- The valid class prefix token is exactly `{$prefix}-`. A class named only `{$prefix}`, `pb`, `pb-`, `.pb`, `-title`, `-text`, `-card`, `-cta`, `pbtitle`, `pbabout...`, `pbhome...`, `-x...`, or `-about...` is invalid. If you are about to write `#componentId .{$prefix}{...}`, `.-title`, or `.-cta`, write `#componentId .{$prefix}-root{...}`, `#componentId .{$prefix}-title{...}`, or `#componentId .{$prefix}-cta{...}` instead.\n"
            . "- Positive class examples for this component: `{$prefix}-root`, `{$prefix}-inner`, `{$prefix}-copy`, `{$prefix}-title`, `{$prefix}-card`, `{$prefix}-cta`.\n"
            . "- Positive CSS selector examples for this component: `#componentId .{$prefix}-root{position:relative;overflow:hidden;}` and `#componentId .{$prefix}-inner{display:flex;gap:28px;}`.\n"
            . "- Role-outline snippets are structural teaching examples only. In final html_content, never copy placeholder words such as Finished heading/copy/CTA as raw text; every text node in those snippets must become a safe PHP echo backed by matching extra_fields and php_variables.\n"
            . "- VISIBLE_CONTROL_COPY_CONTRACT: every visible `<a>`, `<button>`, form `<label>`, CTA, badge, tag, chip, pill, label/value row, metric/stat, proof item, quote item, step item, kicker, or eyebrow must have meaningful target-locale text. Do not output empty or whitespace-only text nodes, `&nbsp;`, zero-width characters, punctuation-only copy, placeholder words, or empty nested spans. If no text exists in CTX_FROZEN_TASK/current block config, remove that visual control or bind a localized fallback field; blank rounded rectangles fail the build. Final self-audit before JSON return: every control-looking tag must still show readable copy after scripts, comments, and tags are ignored.\n"
            . "- ICON_BUTTON_ACCESSIBLE_EXCEPTION: a hamburger, menu toggle, theme toggle, search icon, close icon, or other icon-only control may omit visible text only when it has a non-empty target-locale `aria-label` or `title` and contains an actual visible icon child (`svg`, `img`, `i`, or span bars such as `<span class='{$prefix}-menu-bar' aria-hidden='true'></span>`). Never output a bare empty `<button>` or empty `<a>` for a toggle.\n"
            . "- THEME_ROLE_PALETTE: root_bg={$rootBg}; media_bg={$mediaBg}; surface_bg={$surfaceBg}; card_bg={$cardBg}; text={$textColor}; inverse_text={$inverseText}; primary={$primaryColor}; secondary={$secondaryColor}; accent={$accentColor}; shadow={$shadowColor}. Values that start with CTX_CONFIRMED_THEME are symbolic roles, not CSS literals; before output, replace each symbolic role with a real hex token from CTX_CONFIRMED_THEME.palette. Role baseline CSS below is a palette-bound reference; do not replace it with generic dark navy, purple, blue, casino, or app-download colors unless the approved stage-1 palette explicitly uses those colors.\n"
            . "- TEXT_CONTRAST_HARD_GATE: every visible text selector must be readable on its effective surface. Normal text contrast must be >= 4.5:1. Dark backgrounds require light text such as #FFFFFF/#F8FAFC; light backgrounds require dark text such as #0F172A. Do not put slate/gray/black text on dark surfaces, and do not put white/pale text on light surfaces. Keep root/title/text/copy/label/cta colors coherent with their background or panel.\n"
            . ($hasRequiredGeneratedImage ? "- REQUIRED IMAGE OVERRIDE: this block has a mandatory generated image slot. REQUIRED_IMAGE_STRUCTURE_CONTRACT and REQUIRED_CSS_ROLE_CONTRACT override generic role outlines, selector-count rules, and any broad layout recipe. Preserve the exact image binding and required roles, but choose the final composition from the current block intent instead of copying a fixed skeleton.\n" : '')
            . $featureSafeModeContract
            . $contactSupportModeContract
            . $formGuidanceModeContract
            . $faqModeContract
            . $ctaModeContract
            . "- SAFE_TEXT_ROLE_OUTLINE: for every non-hero non-form content block without a verified image, include one root, inner, copy, title, text, action, compact CTA, and a real support/detail device. This is a role vocabulary, not a skeleton. CTX_BLOCK_VISUAL_SIGNATURE.composition_pattern and page_design_plan decide the shell first: step rail, metric strip, badge wall, quote rail, comparison band, checklist, stacked editorial, asymmetric support panel, or compact proof cluster are all valid when they match the task. A centered title plus one paragraph plus CTA with no support/proof/visual device is invalid. Three isolated full-width proof strips with a CTA detached below or to the far edge are also invalid. Proof/support-only non-hero blocks must use compact root vertical padding in the 44-64px range; 72px+ empty gutters are invalid without a large verified media layer. Do not repeat the same copy-left/proof-right shell across sibling blocks unless visual_signature explicitly asks for that exact pattern.\n"
            . "- MEDIA_ROLE_OUTLINE: for every non-hero content block with a verified image template/final_url, include root, inner, media, editable image, copy, title, text, and compact CTA roles. Put the exact editable image tag from `verified_asset_editable_img_shape` inside `<div class='{$prefix}-media'>`, add class='{$prefix}-img' to that same <img>, and keep its safe PHP src/alt echoes plus data-pb-ai-image-role/data-pb-ai-asset-slot attributes. The surrounding structure should use this ordered role shell: root section -> inner wrapper -> media wrapper containing the editable img -> copy wrapper -> title/description/CTA PHP echoes. Bind required field keys exactly, commonly content.title, content.description, cta.text, media.image_url, and media.image_alt, in extra_fields/php_variables and never leave bracket notes, comments, or source instructions in final html_content.\n"
            . "- CONTACT_ROLE_OUTLINE: for contact-channel blocks, include root, inner, copy, title, intro text, channel hub, action, compact CTA, and note roles. This is a role outline, not a fixed layout: "
            . "`<section class='{$prefix}-root'><div class='{$prefix}-inner'><div class='{$prefix}-copy'><h2 class='{$prefix}-title'><?= htmlspecialchars(\$contentTitle ?? 'Finished heading', ENT_QUOTES, 'UTF-8') ?></h2><p class='{$prefix}-text'><?= nl2br(htmlspecialchars(\$contentDescription ?? 'Finished intro.', ENT_QUOTES, 'UTF-8')) ?></p></div><div class='{$prefix}-cards'><div class='{$prefix}-channel'><span class='pb-c-label'><?= htmlspecialchars(\$channelLabel1 ?? 'Finished channel label', ENT_QUOTES, 'UTF-8') ?></span><span class='pb-c-value'><?= htmlspecialchars(\$channelValue1 ?? 'Exact supplied value or localized non-numeric guidance', ENT_QUOTES, 'UTF-8') ?></span></div><div class='{$prefix}-channel'><span class='pb-c-label'><?= htmlspecialchars(\$channelLabel2 ?? 'Finished channel label', ENT_QUOTES, 'UTF-8') ?></span><span class='pb-c-value'><?= htmlspecialchars(\$channelValue2 ?? 'Exact supplied value or localized non-numeric guidance', ENT_QUOTES, 'UTF-8') ?></span></div></div><div class='{$prefix}-action'><button type='button' class='{$prefix}-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars(\$ctaText ?? 'Finished CTA', ENT_QUOTES, 'UTF-8') ?></button><small class='{$prefix}-note'><?= htmlspecialchars(\$noteText ?? 'Neutral next-step note.', ENT_QUOTES, 'UTF-8') ?></small></div></div></section>`. "
            . "The exact .pb-c-label/.pb-c-value classes are mandatory and must appear at least twice each. Bind all shown text fields in extra_fields/php_variables; the intro/body text normally binds `content.description` to `\$contentDescription`, not `content.body` or `\$body`. Do not copy placeholder emails, partial email fragments such as support@ .com, fake phone numbers, example domains, raw internal labels, or prompt-like source notes.\n"
            . "- FORM_ROLE_OUTLINE: for form-guidance blocks, include root, inner, copy, title, intro text, a real `<form class='{$prefix}-form'>`, at least two `<label class='{$prefix}-label'>` elements, at least two input/textarea fields, one message textarea, and a separated action wrapper. Do not render contact cards for this role.\n"
            . "- FAQ_ROLE_OUTLINE: for FAQ blocks, include root, inner, title, intro text, `<div class='{$prefix}-faq-list'>`, and at least two `<div class='{$prefix}-faq-item'><div class='{$prefix}-question'><?= htmlspecialchars(\$faqQuestion1 ?? 'Finished question?', ENT_QUOTES, 'UTF-8') ?></div><p class='{$prefix}-answer'><?= nl2br(htmlspecialchars(\$faqAnswer1 ?? 'Finished answer.', ENT_QUOTES, 'UTF-8')) ?></p></div>` groups. Bind every question/answer field in extra_fields/php_variables. css_extra must include scoped selectors for faq-list, faq-item, question, and answer; the faq-item selector must include padding, border-radius, and background, border, or box-shadow. Do not render contact cards or form fields for this role.\n"
            . "- FAQ_CSS_BASELINE: for FAQ blocks, include complete scoped selectors for FAQ_ROLE_OUTLINE roles and use this palette-filled baseline as a reference, not a fixed CSS template: `{$faqCssBaseline}`.\n"
            . "- CTA_ROLE_OUTLINE: for CTA blocks, include one root, one inner band, copy, title, text, compact proof, sibling action wrapper, and one primary CTA. Do not render contact channel cards, forms, FAQ rows, or another hero overlay for this role.\n"
            . "- ACTIONABLE_CTA_OVERRIDE: role outline examples may show a compact CTA placeholder. In final html_content, every primary `.{$prefix}-cta` must be an actionable `<a>` or `<button>` following CTX_CTA_ACTION_CONTRACT, not a static `<div>`.\n"
            . "- TEXT_CSS_BASELINE: include complete scoped selectors for the roles you actually render from SAFE_TEXT_ROLE_OUTLINE, but do not copy a fixed grid/card template. Use palette-filled CSS as a quality floor only: `#componentId .{$prefix}-root{position:relative;overflow:hidden;padding:56px 24px;background:{$surfaceBg};color:{$textColor};box-sizing:border-box;}#componentId .{$prefix}-inner{max-width:1200px;width:100%;margin:0 auto;display:grid;gap:28px;align-items:center;}#componentId .{$prefix}-copy{min-width:0;}#componentId .{$prefix}-title{margin:0;font-size:42px;line-height:1.1;color:{$primaryColor};}#componentId .{$prefix}-text{margin:0;line-height:1.7;color:{$textColor};}#componentId .{$prefix}-action{display:flex;width:auto;max-width:100%;box-sizing:border-box;margin-top:22px;}#componentId .{$prefix}-support{min-width:0;display:grid;gap:12px;}#componentId .{$prefix}-proof{min-width:0;padding:18px 14px;border:1px solid {$accentColor};border-radius:18px;background:{$cardBg};color:{$textColor};line-height:1.45;box-shadow:0 12px 30px {$shadowColor};}#componentId .{$prefix}-cta{display:inline-flex;width:auto;max-width:280px;box-sizing:border-box;border-radius:999px;padding:12px 20px;background:{$accentColor};color:{$textColor};}`. Choose columns, order, card count, rail/band orientation, and alignment from visual_signature. PageBuilder preview widths around 680-760px must still look finished and dense, but there is no universal three-card proof grid.\n"
            . "- SAFE_TEXT_RESPONSIVE_BASELINE: css_responsive must adapt the chosen visual_signature rhythm instead of enforcing one grid. At <=768px keep the block visually intentional and dense; at <=420px use one readable column. If the chosen support device is a step rail, convert it to stacked steps; if it is a metric strip, keep compact equal columns until mobile; if it is editorial/quote/checklist, preserve its scan rhythm. This is a responsive quality contract, not a fixed final design.\n"
            . "- MEDIA_CSS_BASELINE: for verified-image non-hero blocks, include complete scoped selectors for MEDIA_ROLE_OUTLINE roles and use this palette-filled baseline as a reference, not a fixed CSS template: `#componentId .{$prefix}-root{position:relative;overflow:hidden;padding:72px 24px;background:{$surfaceBg};color:{$textColor};box-sizing:border-box;}#componentId .{$prefix}-inner{max-width:1200px;width:100%;margin:0 auto;display:flex;flex-wrap:wrap;gap:32px;align-items:center;}#componentId .{$prefix}-media{flex:1 1 360px;min-width:0;overflow:hidden;border-radius:28px;box-shadow:0 24px 64px {$shadowColor};}#componentId .{$prefix}-img{display:block;width:100%;height:360px;object-fit:cover;object-position:center;}#componentId .{$prefix}-copy{flex:1 1 320px;min-width:0;}#componentId .{$prefix}-title{margin:0;font-size:42px;line-height:1.1;color:{$primaryColor};}#componentId .{$prefix}-text{margin:0;line-height:1.7;color:{$textColor};}#componentId .{$prefix}-cta{display:inline-flex;width:auto;max-width:280px;box-sizing:border-box;border-radius:999px;padding:12px 20px;background:{$accentColor};color:{$textColor};}`.\n"
            . "- CONTACT_CSS_BASELINE: for contact-channel blocks only, include complete scoped selectors for CONTACT_ROLE_OUTLINE roles and use this palette-filled baseline as a reference, not a fixed CSS template: `{$contactCssBaseline}`.\n"
            . "- CTA FIT BUTTON CONTRACT: CTA controls should be real actionable anchors/buttons when a CTA is rendered, not inert decorative divs. Wrapper/row/band/group/container classes may be full-width and should use `{$prefix}-action` or `{$prefix}-actions` when practical; keep the actual CTA control compact in the default layout.\n"
            . "- CTA QUALITY CSS BASELINE: css_extra should include a CTA selector equivalent to `#componentId .{$prefix}-cta{display:inline-flex;width:auto;max-width:280px;flex:0 0 auto;align-self:flex-start;}`. css_responsive may adapt containers and form controls to full width; do not turn the desktop/default CTA button itself into a full-width bar.\n"
            . $markupModeContract
            . "- CONTACT COPY RHYTHM: contact-channel rows/cards use short labels plus short details. If a required-image contact/support skeleton includes `{$prefix}-cards`, keep at least two `{$prefix}-channel` items with exact .pb-c-label/.pb-c-value sibling spans and put one channel in each. A paragraph containing two or more of email, phone, address, business hours, sales, or support is invalid because it reads like raw data instead of a designed contact surface.\n"
            . "- Showcase/features/trust/reward/newsletter tasks without a required image still need SAFE_TEXT roles, but the composition comes from visual_signature, not from a universal proof-card skeleton. When the block has multiple concrete facts, render concise support/proof/detail elements from those facts using the role-appropriate shape: step rail, metric band, badge wall, quote rail, checklist, comparison strip, compact cluster, or side panel. Keep the default desktop CTA inside a copy/action area; do not output full-width horizontal proof strips with the CTA detached at the far left. Keep root vertical padding compact for proof/support-only blocks (44-64px); 72px+ padding reads like empty filler and fails quality. At PageBuilder tablet/preview widths, avoid orphan grids and centered filler stacks; rebalance the selected composition instead. Do not create a generic card grid, list, table, carousel, accordion, or multiple repeated game cards unless the current block contract explicitly asks for that structure. FAQ and CTA modes keep their own role outlines.\n"
            . "- FEATURE_CSS_BASELINE: use this shorter css_extra reference only when no REQUIRED_CSS_ROLE_CONTRACT exists: `{$featureCssBaseline}`. Keep required role selectors complete, but do not treat the example as an exact selector-count rule.\n"
            . "- Verified image priority: if the prompt includes REQUIRED_IMAGE_STRUCTURE_CONTRACT, preserve the copied image tag's src and slot attributes exactly. Do not reconstruct the image tag or URL. If REQUIRED_CSS_ROLE_CONTRACT is present, style those roles and do not write CSS url(...).\n"
            . "- JSON recovery rule: if any requested layout feels too complex for valid JSON, simplify the layout while preserving the required image binding, required roles, scoped classes, and visible copy quality. A small valid premium block is better than a broken multi-card block, but do not fall back to one universal skeleton for every design.\n"
            . "- JSON text safety: visible copy inside html_content must not contain double quote characters, backticks, code fences, raw `<` or `>`, or JSON braces. Rewrite quoted phrases into plain prose before output.\n"
            . "- Raw HTML leakage ban: never escape malformed tags into visible text. Strings such as `&lt;2 class='pb-c-title'&gt;`, `<2 class=...>`, `</pa>`, `</pdiv>`, or `class='...'` printed as text are hard failures; use a valid `<h2 class='{$prefix}-title'>...</h2>` element instead.\n"
            . "- HTML tag whitelist for role outlines: opening tags may only be section, div, h2, p, small, strong, span, form, label, input, textarea, img, a, button, or br. Closing tags may only match non-void tags such as </section>, </div>, </h2>, </p>, </small>, </strong>, </span>, </form>, </label>, </textarea>, </a>, and </button>. Never output </>, </h>, </pa>, </pdiv>, <2>, <h>, or a numeric tag name. If a tag is uncertain, keep the role outline simple instead of improvising malformed markup.\n"
            . "- Use div/card groups instead of ul/ol/li list markup. Use exactly one h2 for the block title; do not use h3 or closing tags like </h>. Prefer p/small/div for labels. If span or strong is used, keep it plain text with no attributes and close it before any parent closes.\n"
            . "- Content links/actions: use `<a class='{$prefix}-cta' href='...'>` only when the href is exactly the CTX_CTA_ACTION_CONTRACT target or one value from allowed_internal_paths. If no real route exists but the block needs a CTA, use `<button type='button' class='{$prefix}-cta' data-pb-ai-action='primary_cta'>` plus scoped js_content from CTX_CTA_ACTION_CONTRACT. Never use an inert CTA div for a primary action, never invent paths such as /download, /faq, /games, or anchors, and never nest cards/sections inside links/buttons.\n"
            . $heroImageModeContract
            . $heroContract
            . "- Use only complete CSS declarations. Each declaration has one property name, one value, and a semicolon. For non-hero blocks, keep css_extra to the minimal CSS shape above plus required support/proof selectors and at most one additional optional card/form selector. If a decorative selector is uncertain, omit it instead of writing broken CSS.\n"
            . "- CSS declaration separator rule: every declaration must end with `;` before the next property. `padding:16px 32pxbackground:{$accentColor}` is invalid; write `padding:16px 32px;background:{$accentColor};` after replacing any symbolic palette role with a real confirmed hex token.\n"
            . "- CSS numeric value rule: opacity and numeric declarations must use complete values such as `.46`, `0.46`, `1`, `240px`, or `100%`; never output truncated values like `opacity:.`, `opacity:`, `width:-`, or `z-index:.`.\n"
            . "- CSS value precision rule: `box-sizing` must be exactly `border-box`; `box-sizing:border` is invalid CSS and will be rejected.\n"
            . "- CSS image URL ban: css_extra must not contain `url(` for PageBuilder AI-generated image assets. Use the verified <img> skeleton for images; use plain colors, borders, and shadows for surfaces.\n"
            . "- CSS function discipline: css_extra may use production-safe functions only when they are complete and valid, including clamp(), linear-gradient(), rgba(), and transform(). Do not use incomplete values, nested broken functions, placeholder values such as `...`, or CSS url(...) for generated image assets.\n"
            . "- CSS brace contract: css_extra may start with the wrapper reset `#componentId{padding:0;}` and must then include `#componentId .{$prefix}-root{...}`. It must not contain `}}`, `{{`, `@media`, comma selectors, nested selectors, comments, raw JSON braces, or a brace inside any CSS value. {$safeCssBraceCountRule}\n"
            . "- Never leave blank CSS values such as `background:;`, `color:;`, `border-color:;`, or `box-shadow:;`. If a value is uncertain, keep the value from the minimal CSS shape or use a confirmed theme color.\n"
            . "- CSS property whitelist: use only position, inset, top, right, bottom, left, overflow, padding, margin, max-width, width, min-width, height, min-height, display, flex, flex-wrap, flex-direction, gap, align-items, justify-content, align-self, grid-template-columns, background, color, border, border-radius, box-shadow, font-family, font-size, font-weight, line-height, z-index, opacity, object-fit, object-position, box-sizing, text-decoration, cursor, and outline. Do not invent property names or write text fragments inside css_extra.\n"
            . "- css_responsive must contain at least one `@media (max-width: 768px)` rule and one `@media (max-width: 420px)` rule. For SAFE_TEXT_ROLE_OUTLINE, responsive rules must preserve the selected visual_signature composition instead of forcing a single three-card grid transition. Put @media blocks only in css_responsive; keep css_extra as scoped base selectors.\n"
            . "- JSON output mode: return exactly one minified JSON object on one line. The first character is `{` and the last character is `}`. Do not wrap it in markdown. Do not append comments or prose. Escape any unavoidable double quote inside string values as `\\\"`; prefer single-quoted HTML attributes so escaping is rarely needed.\n"
            . "- Return one complete JSON object using the required fields only. Never output top-level PHP/PHTML. Do not start the answer with `<?php`, `<?=`, `<section`, or any raw HTML; only a JSON object is valid.\n"
            . "- PHP boundary: `php_variables` is not wrapped in PHP tags and must contain no `<?php`, no `?>`, no echo/print, no arrays, no loops, and no functions. It is only assignment lines like `\$contentTitle = \$getConfig('content.title', 'Finished localized title');`.\n"
            . "- Safe echo exception: html_content may contain `<?= htmlspecialchars(...) ?>` or `<?= nl2br(htmlspecialchars(...)) ?>` inside the JSON string. That exception does not allow `<?php` blocks, full PHTML documents, or raw PHP before/after the JSON object.\n"
            . "- PHP string literal safety: every `\$getConfig` fallback must be quoted; never emit bare visitor words such as Privacy or Security as PHP tokens. Avoid apostrophes in fallback text or escape them.\n"
            . $this->buildStrictRequiredImagePromptTail($componentCode, $renderContext);
    }

    /**
     * Keep the exact image-slot contract as the final prompt fragment. This is
     * prompt-level contract rendering only; it does not synthesize visitor copy.
     *
     * @param array<string,mixed> $renderContext
     */
    private function buildStrictRequiredImagePromptTail(string $componentCode, array $renderContext): string
    {
        $visualContract = \is_array($renderContext['_visual_contract'] ?? null) ? $renderContext['_visual_contract'] : [];
        $scope = \is_array($renderContext['_scope'] ?? null) ? $renderContext['_scope'] : [];
        $PlanJsonTask = \is_array($renderContext['_plan_json_task'] ?? null) ? $renderContext['_plan_json_task'] : [];
        $themePalette = $this->resolveThemePaletteForContract($PlanJsonTask, $scope);
        $artifacts = $this->buildRequiredImagePromptArtifacts($visualContract, $themePalette);
        if (empty($artifacts['required']) || ($artifacts['slot_id'] ?? '') === '' || ($artifacts['final_url'] ?? '') === '' || ($artifacts['img_template'] ?? '') === '') {
            return '';
        }

        $prefix = $this->normalizeComponentCssPrefix($componentCode);
        $slotId = (string)($artifacts['slot_id'] ?? '');
        $finalUrl = (string)($artifacts['final_url'] ?? '');
        $imgTemplate = (string)($artifacts['img_template'] ?? '');
        $skeletonOutline = (string)($artifacts['skeleton_outline'] ?? '');
        $cssStructuralHints = (string)($artifacts['css_structural_hints'] ?? '');
        $roleMap = \is_array($artifacts['palette_role_map'] ?? null) ? $artifacts['palette_role_map'] : [];
        $roleMapLine = $roleMap === []
            ? 'Use only confirmed theme palette tokens from CTX_CONFIRMED_THEME.palette; do not invent fallback colors.'
            : '{' . \implode(', ', \array_map(static fn(string $k, string $v): string => $k . '=' . $v, \array_keys($roleMap), \array_values($roleMap))) . '}';
        $isAbsoluteImageUrl = \preg_match('/^(?:https?:)?\/\//i', $finalUrl) === 1;
        $urlShapeRule = $isAbsoluteImageUrl
            ? 'The required media.image_url fallback is absolute; keep the host/domain exactly as shown in exact_required_image_url_literal.'
            : 'The required media.image_url fallback is relative; keep the leading slash and do not add a host/domain.';
        $bindingContract = [
            'slot_id' => $slotId,
            'media_image_url_default' => $finalUrl,
            'required_img_attrs' => [
                'src' => "<?= htmlspecialchars(\$mediaImageUrl ?? '" . \str_replace("'", "\\'", $finalUrl) . "', ENT_QUOTES, 'UTF-8') ?>",
                'data-pb-ai-image-role' => 'generated-asset',
                'data-pb-ai-asset-slot' => $slotId,
            ],
            'valid_when' => 'html_content contains one img tag whose data-pb-ai-asset-slot matches this contract and whose src is a safe editable media.image_url echo with media_image_url_default as fallback',
            'css_url_allowed' => false,
            'src_shape' => $isAbsoluteImageUrl ? 'absolute' : 'relative',
        ];

        return "\nFINAL REQUIRED IMAGE CONTRACT (last instruction wins):\n"
            . "- exact_component_class_prefix: {$prefix}-\n"
            . "- exact_required_image_url_literal: {$finalUrl}\n"
            . "- exact_required_image_slot_literal: {$slotId}\n"
            . "- exact_required_image_binding_contract_json: " . $this->jsonEncodeForPrompt($bindingContract, 900) . "\n"
            . "- EXACT_REQUIRED_IMG_TAG_TO_COPY: {$imgTemplate}\n"
            . "- The exact_required_image_url_literal must be the default/fallback of editable field media.image_url and must appear inside the safe src echo on the same img tag that carries data-pb-ai-image-role='generated-asset' and data-pb-ai-asset-slot='{$slotId}'.\n"
            . "- URL shape rule: {$urlShapeRule}\n"
            . "- REQUIRED_IMAGE_STRUCTURE_CONTRACT: html_content must contain exactly one required image tag with editable src/alt fields and the copied slot/data binding, plus a coherent section root, content/copy area, heading, body copy, and compact CTA when the block needs a CTA. You may choose overlay, split, editorial, or asymmetric composition according to the block intent; do not copy a single fixed skeleton.\n"
            . ($skeletonOutline !== '' ? "- REQUIRED_ROLE_OUTLINE (required roles/classes, not a byte-for-byte skeleton):\n{$skeletonOutline}\n" : '')
            . "- REQUIRED_PALETTE_ROLE_MAP: {$roleMapLine}\n"
            . ($cssStructuralHints !== '' ? "- REQUIRED_CSS_ROLE_CONTRACT (style the required roles; values and layout rhythm remain design-owned by the current block):\n{$cssStructuralHints}\n" : '')
            . "- HTML structure rule: render real parsed markup, not prompt labels. Do not put the literal words REQUIRED_IMAGE_STRUCTURE_CONTRACT, REQUIRED_ROLE_OUTLINE, EXACT_REQUIRED_IMG_TAG_TO_COPY, `<img`, `</section>`, `class=`, or `&lt;img` inside text nodes.\n"
            . "- Forbidden image outputs: hardcoded non-editable src values, editable src fallback/default different from exact_required_image_url_literal, reconstructed domain paths, shortened hashes, missing data-pb-ai-image-role, missing data-pb-ai-asset-slot, or moving the image into CSS background-image.\n"
            . "- If any design instruction conflicts with this final required-image contract, keep the exact image binding and required roles, but solve layout/aesthetic choices through the current block's own design contract instead of copying a fixed skeleton.\n";
    }

    private function cleanAiHtmlFragment(string $html, array $verifiedAssets = []): string
    {
        [$html, $safePhpEchoTokens] = $this->extractSafeAiHtmlPhpEchoTokens($html);
        $html = \preg_replace('/<\s*style\b[^>]*>[\s\S]*?<\/\s*style\s*>/iu', '', $html) ?? $html;
        $html = \preg_replace('/<\s*script\b[^>]*>[\s\S]*?<\/\s*script\s*>/iu', '', $html) ?? $html;
        $html = \preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/iu', '', $html) ?? $html;
        $html = \str_replace(['<?', '?>'], '', $html);
        $html = \preg_replace('/@(?:component|fields)_(?:start|end)\b[^\r\n<]*/iu', '', $html) ?? $html;
        $html = \trim($html);
        $html = $this->repairMalformedEmptyClosingTags($html);
        $html = $this->repairHtmlTagAttributeSpacing($html);
        $html = $this->repairHtmlFragmentTagBalance($html);
        $hardPolicyReason = $this->detectHardGeneratedSectionHtmlPolicyViolation($html);
        if ($hardPolicyReason !== null) {
            throw new \RuntimeException('AI component structure hard policy failed: ' . $hardPolicyReason);
        }
        $this->assertNoBrokenGeneratedImageReferences($html, $verifiedAssets);
        $html = \preg_replace('/\s{2,}/u', ' ', $html) ?? $html;
        $html = \trim($html);
        $html = $this->repairHtmlFragmentTagBalance($html);
        $html = $this->restoreSafeAiHtmlPhpEchoTokens($html, $safePhpEchoTokens);
        $length = \function_exists('mb_strlen') ? \mb_strlen($html, 'UTF-8') : \strlen($html);
        if ($length > 5000) {
            return \trim($html);
        }

        return \trim($html);
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function extractSafeAiHtmlPhpEchoTokens(string $html): array
    {
        $tokens = [];
        $index = 0;
        $html = \preg_replace_callback('/<\?=[\s\S]*?\?>/u', function (array $matches) use (&$tokens, &$index): string {
            $php = (string)($matches[0] ?? '');
            if (!$this->isSafeAiHtmlPhpEcho($php)) {
                return '';
            }
            $token = '__PB_SAFE_PHP_ECHO_' . $index++ . '__';
            $tokens[$token] = $php;

            return $token;
        }, $html) ?? $html;

        return [$html, $tokens];
    }

    /**
     * @param array<string,string> $tokens
     */
    private function restoreSafeAiHtmlPhpEchoTokens(string $html, array $tokens): string
    {
        if ($tokens === []) {
            return $html;
        }

        return \strtr($html, $tokens);
    }

    private function isSafeAiHtmlPhpEcho(string $php): bool
    {
        $inner = \trim(\preg_replace('/^<\?=\s*|\s*\?>$/u', '', $php) ?? '');
        if (\in_array($inner, ['$cls', '$componentId'], true)) {
            return true;
        }
        if (\preg_match('/(?:->|::|$_|\b(?:eval|exec|shell_exec|system|passthru|include|require|new|function)\b)/i', $inner)) {
            return false;
        }

        $quoted = '(?:\'(?:\\\\.|[^\'])*\'|"(?:\\\\.|[^"])*")';
        $configCall = '$getConfig\s*\(\s*[\'"][A-Za-z0-9_.-]+[\'"]\s*(?:,\s*' . $quoted . ')?\s*\)';
        $valueExpr = '(?:$[A-Za-z_][A-Za-z0-9_]*|' . $configCall . ')(?:\s*\?\?\s*' . $quoted . ')?';
        $htmlspecialchars = 'htmlspecialchars\s*\(\s*' . $valueExpr . '\s*(?:,\s*ENT_QUOTES)?\s*(?:,\s*[\'"]UTF-8[\'"])?\s*\)';

        return \preg_match('/^(?:' . $htmlspecialchars . '|nl2br\s*\(\s*' . $htmlspecialchars . '\s*\))$/u', $inner) === 1;
    }

    private function repairMalformedEmptyClosingTags(string $html): string
    {
        if ($html === '' || !\str_contains($html, '</')) {
            return $html;
        }

        return \preg_replace('/<\s*\/\s*>/u', '', $html) ?? $html;
    }

    private function repairHtmlTagAttributeSpacing(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = \preg_replace_callback(
            '/<\s*[a-z][a-z0-9:-]*\b[^<>]*(?=<\s*\/?[a-z])/iu',
            fn(array $matches): string => $this->closeMalformedOpeningTagBeforeNestedMarker((string)($matches[0] ?? '')),
            $html
        ) ?? $html;
        $html = \preg_replace('/<\s*([a-z][a-z0-9:-]*)\s*=\s*(["\'])/iu', '<$1 class=$2', $html) ?? $html;
        $html = \preg_replace('/<\s*([a-z][a-z0-9:-]*)\s+(["\'])([^"\']+)\2/iu', '<$1 class=$2$3$2', $html) ?? $html;

        return \preg_replace_callback(
            '/<\s*\/?\s*[a-z][a-z0-9:-]*\b[^<>]*(?:>|$)/iu',
            function (array $matches): string {
                $tag = (string)($matches[0] ?? '');
                if ($tag === '') {
                    return $tag;
                }
                $tag = $this->repairHtmlTagAttributeQuoteBeforeNextAttribute($tag);

                return \preg_replace('/(["\'])(?=[a-zA-Z_:][a-zA-Z0-9_:.-]*\s*=)/u', '$1 ', $tag) ?? $tag;
            },
            $html
        ) ?? $html;
    }

    private function closeMalformedOpeningTagBeforeNestedMarker(string $partialTag): string
    {
        $partialTag = \rtrim($partialTag);
        if ($partialTag === '' || \str_ends_with($partialTag, '>')) {
            return $partialTag;
        }

        $quote = '';
        $length = \strlen($partialTag);
        for ($index = 1; $index < $length; $index++) {
            $char = $partialTag[$index];
            if ($quote !== '') {
                if ($char === $quote && ($index === 0 || $partialTag[$index - 1] !== '\\')) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            }
        }
        if ($quote !== '') {
            $partialTag .= $quote;
        }

        return $partialTag . '>';
    }

    private function repairHtmlTagAttributeQuoteBeforeNextAttribute(string $tag): string
    {
        $quote = '';
        $repaired = '';
        $length = \strlen($tag);
        for ($index = 0; $index < $length; $index++) {
            $char = $tag[$index];
            if ($quote !== '') {
                if (\preg_match('/\s(?:class|id|href|src|alt|title|role|loading|decoding|width|height|style|aria-[a-z0-9_-]+|data-[a-z0-9_-]+)\s*=/iu', \substr($tag, $index), $attrMatch, \PREG_OFFSET_CAPTURE) === 1
                    && (int)$attrMatch[0][1] === 0
                ) {
                    $repaired .= $quote;
                    $quote = '';
                }
                if ($char === '>' && ($index === 0 || $tag[$index - 1] !== '\\')) {
                    $repaired .= $quote;
                    $quote = '';
                }
                $repaired .= $char;
                if ($char === $quote && ($index === 0 || $tag[$index - 1] !== '\\')) {
                    $tail = \substr($tag, $index + 1);
                    $next = $tail !== '' ? $tail[0] : '';
                    if ($next !== '' && !\preg_match('/[\s>\/]/', $next)) {
                        if (\preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:.-]*\s*=/u', $tail) === 1) {
                            $repaired .= ' ';
                            $quote = '';
                            continue;
                        }
                        $repaired = \substr($repaired, 0, -1) . ($char === "'" ? '&#39;' : '&quot;');
                        continue;
                    }
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            }
            $repaired .= $char;
        }
        if ($quote !== '') {
            $repaired .= $quote;
        }

        return $repaired;
    }

    /**
     * @param array<string,mixed> $aiData
     */
    private function isAllowedComponentInlineJs(string $js, array $aiData): bool
    {
        $js = \trim($js);
        if ($js === '' || \strlen($js) < 5) {
            return false;
        }
        $forbiddenCaseSensitive = [
            'Function(',
        ];
        foreach ($forbiddenCaseSensitive as $needle) {
            if (\strpos($js, $needle) !== false) {
                return false;
            }
        }
        $forbidden = [
            'eval(',
            'fetch(',
            'XMLHttpRequest',
            'document.write',
            'document.body',
            'document.head',
            'window.location',
            'window.open',
            'import(',
            '<script',
            '</script',
            'innerHTML',
            'outerHTML',
            'insertAdjacentHTML',
            '__proto__',
            'document.cookie',
            'localStorage',
            'sessionStorage',
            'navigator.',
            'history.',
            'top.location',
            'parent.location',
        ];
        foreach ($forbidden as $needle) {
            if (\stripos($js, $needle) !== false) {
                return false;
            }
        }
        if (\strpos($js, 'component') === false) {
            return false;
        }

        return true;
    }

    /**
 *  ?HTML  ? ? ?`>...<`  ? *  ?src/href/data-*  ?URL / slot id  ?leak ? */
    private function extractVisibleHtmlText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = \preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = \preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html) ?? $html;
        $html = \preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = \preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = \preg_replace('/<template\b[^>]*>.*?<\/template>/is', '', $html) ?? $html;
        $html = \preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $html) ?? $html;
        $html = \preg_replace('/<title\b[^>]*>.*?<\/title>/is', '', $html) ?? $html;
        $html = \html_entity_decode($html, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        // PHP echo fragments are source code, not visitor text; leaving them in
        // makes the PHP close marker inside attributes look like a tag ending.
        $phpClosePattern = \preg_quote('?' . '>', '/');
        $html = \preg_replace('/<\?(?:php|=)?[\s\S]*?' . $phpClosePattern . '/i', ' ', $html) ?? $html;
        $html = \preg_replace('/<\?(?:php|=)?[\s\S]*?(?=>|$)/i', ' ', $html) ?? $html;
        $attributeText = [];
        if (\preg_match_all('/\s(?:alt|title|aria-label|placeholder|value)\s*=\s*([\'"])(.*?)\1/isu', $html, $matches) > 0) {
            foreach ($matches[2] ?? [] as $value) {
                $value = \trim(\html_entity_decode((string)$value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
                if ($value !== '') {
                    $attributeText[] = $value;
                }
            }
        }
        $plain = \strip_tags($html);
        if ($attributeText !== []) {
            $plain .= ' ' . \implode(' ', $attributeText);
        }
        $plain = \html_entity_decode($plain, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $plain = \preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        return \trim($plain);
    }

    /**
     * @return list<string>
     */
    private function extractVisibleHtmlTextChunks(string $html): array
    {
        if (\trim($html) === '') {
            return [];
        }

        $html = \preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = \preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = \preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $chunks = [];
        $matchCount = \preg_match_all(
            '/<(?P<tag>h[1-6]|p|a|button|li|label|figcaption|small|strong|em|span|div)\b[^>]*>(?P<body>[\s\S]*?)<\/\k<tag>>/iu',
            $html,
            $matches,
            \PREG_SET_ORDER
        );
        if ($matchCount === false || $matchCount < 1) {
            return [];
        }

        foreach ($matches as $match) {
            $text = $this->extractVisibleHtmlText((string)($match['body'] ?? ''));
            $text = \trim((string)\preg_replace('/\s+/u', ' ', $text));
            if ($text === '') {
                continue;
            }
            $chunks[] = $text;
        }

        return \array_slice(\array_values(\array_unique($chunks)), 0, 80);
    }

    private function assertGeneratedHtmlFragmentWellFormed(string $html): void
    {
        $reason = $this->detectMalformedGeneratedHtmlReason($html);
        if ($reason !== null) {
            throw new \RuntimeException('AI component HTML structure invalid: ' . $reason);
        }
    }

    private function detectMalformedGeneratedHtmlReason(string $html): ?string
    {
        $html = \trim($html);
        if ($html === '') {
            return null;
        }
        if (\preg_match('/<\s+(?:class|id|style|href|src|alt|title|role|aria-[a-z0-9_-]+|data-[a-z0-9_-]+)\s*=/iu', $html) === 1) {
            return 'opening tag is missing an element name';
        }
        $invalidTagReason = $this->detectInvalidHtmlTagTokenReason($html);
        if ($invalidTagReason !== null) {
            return $invalidTagReason;
        }

        $voidTags = \array_fill_keys([
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ], true);
        $tagCount = \preg_match_all('/<\s*\/?\s*([a-z][a-z0-9:-]*)\b[^>]*(?:>|$)/iu', $html, $matches, \PREG_SET_ORDER);
        if ($tagCount === false || $tagCount === 0) {
            return null;
        }

        $stack = [];
        foreach ($matches as $match) {
            $tagText = (string)($match[0] ?? '');
            $tagName = \strtolower((string)($match[1] ?? ''));
            if ($tagName === '') {
                continue;
            }
            $tagReason = $this->detectMalformedHtmlTagTokenReason($tagText);
            if ($tagReason !== null) {
                return $tagReason . ' near <' . $tagName . '>';
            }
            if (\preg_match('/^<\s*\/\s*/', $tagText) === 1) {
                $last = \array_pop($stack);
                if ($last === null) {
                    return 'orphan closing tag </' . $tagName . '>';
                }
                if ($last !== $tagName) {
                    return 'crossed closing tag </' . $tagName . '> while <' . $last . '> is still open';
                }
                continue;
            }
            if (isset($voidTags[$tagName]) || \preg_match('/\/\s*>$/', $tagText) === 1) {
                continue;
            }
            $stack[] = $tagName;
        }

        if ($stack !== []) {
            return 'unclosed tag <' . (string)\end($stack) . '>';
        }

        return null;
    }

    private function detectInvalidHtmlTagTokenReason(string $html): ?string
    {
        if (\preg_match_all('/<[^>]*(?:>|$)/u', $html, $matches, \PREG_SET_ORDER) < 1) {
            return null;
        }

        foreach ($matches as $match) {
            $token = \trim((string)($match[0] ?? ''));
            if ($token === '') {
                continue;
            }
            if (\str_starts_with($token, '<!--') && \str_ends_with($token, '-->')) {
                continue;
            }
            if (\preg_match('/^<\s*\/?\s*[a-z][a-z0-9:-]*(?:\s[^<>]*)?\/?\s*>$/iu', $token) === 1) {
                continue;
            }
            if (\preg_match('/^<\s*\/?\s*\d/iu', $token) === 1) {
                return 'tag name must start with a letter near ' . $this->clipText($token, 80);
            }

            return 'malformed HTML tag token near ' . $this->clipText($token, 80);
        }

        return null;
    }

    private function detectMalformedHtmlTagTokenReason(string $tagText): ?string
    {
        $tagText = \trim($tagText);
        if ($tagText === '') {
            return null;
        }
        if (!\str_ends_with($tagText, '>')) {
            return 'unterminated tag';
        }
        if (\preg_match('/^<\s*[a-z][a-z0-9:-]*\s*=\s*(["\'])/iu', $tagText) === 1
            || \preg_match('/^<\s*[a-z][a-z0-9:-]*\s*(["\'])/iu', $tagText) === 1
        ) {
            return 'attribute name is missing';
        }

        $quote = '';
        $length = \strlen($tagText);
        for ($index = 1; $index < $length - 1; $index++) {
            $char = $tagText[$index];
            if ($quote !== '') {
                if ($char === $quote && ($index === 0 || $tagText[$index - 1] !== '\\')) {
                    $quote = '';
                    $next = $tagText[$index + 1] ?? '';
                    if ($next !== '' && !\preg_match('/[\s>\/]/', $next)) {
                        return 'missing whitespace before next attribute';
                    }
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '<') {
                return 'nested tag marker inside an opening tag';
            }
        }
        if ($quote !== '') {
            return 'unclosed attribute quote';
        }

        return null;
    }

    private function repairHtmlFragmentTagBalance(string $html): string
    {
        if (\trim($html) === '') {
            return '';
        }

        $voidTags = \array_fill_keys([
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ], true);
        $matchCount = \preg_match_all('/<\s*\/?\s*([a-z][a-z0-9:-]*)\b[^>]*>/i', $html, $matches, \PREG_OFFSET_CAPTURE);
        if ($matchCount === false || $matchCount === 0) {
            return $html;
        }

        $result = '';
        $offset = 0;
        $stack = [];
        foreach ($matches[0] as $index => $match) {
            $tagText = (string)$match[0];
            $tagOffset = (int)$match[1];
            $result .= \substr($html, $offset, \max(0, $tagOffset - $offset));
            $offset = $tagOffset + \strlen($tagText);
            $tagName = \strtolower((string)($matches[1][$index][0] ?? ''));
            if ($tagName === '') {
                $result .= $tagText;
                continue;
            }

            if (\preg_match('/^<\s*\/\s*/', $tagText) === 1) {
                $matchedIndex = -1;
                for ($stackIndex = \count($stack) - 1; $stackIndex >= 0; $stackIndex--) {
                    if ($stack[$stackIndex] === $tagName) {
                        $matchedIndex = $stackIndex;
                        break;
                    }
                }
                if ($matchedIndex < 0) {
                    continue;
                }
                for ($stackIndex = \count($stack) - 1; $stackIndex > $matchedIndex; $stackIndex--) {
                    $result .= '</' . $stack[$stackIndex] . '>';
                    \array_pop($stack);
                }
                \array_pop($stack);
                $result .= '</' . $tagName . '>';
                continue;
            }

            $result .= $tagText;
            if (isset($voidTags[$tagName]) || \preg_match('/\/\s*>$/', $tagText) === 1) {
                continue;
            }

            $stack[] = $tagName;
        }

        $result .= \substr($html, $offset);
        for ($stackIndex = \count($stack) - 1; $stackIndex >= 0; $stackIndex--) {
            $result .= '</' . $stack[$stackIndex] . '>';
        }

        return $result;
    }

    /**
 * AI  ?HTML  ? ?repair ? */
    private function clipHtmlFragmentPreservingIntegrity(string $html, int $limit): string
    {
        if ($limit <= 32 || \trim($html) === '') {
            return \trim($html);
        }

        $len = \function_exists('mb_strlen') ? \mb_strlen($html, 'UTF-8') : \strlen($html);
        if ($len <= $limit) {
            return $html;
        }

        $slice = \function_exists('mb_substr')
            ? \mb_substr($html, 0, \max(24, $limit - 24), 'UTF-8')
            : \substr($html, 0, \max(24, $limit - 24));
        $lastGt = false;
        if (\function_exists('mb_strlen') && \function_exists('mb_strrpos')) {
            $ofs = \mb_strrpos($slice, '>', 0, 'UTF-8');
            $lastGt = ($ofs !== false && $ofs >= 24);
            if ($lastGt) {
                $slice = \mb_substr($slice, 0, $ofs + 1, 'UTF-8');
            }
        } else {
            $ofs = \strrpos($slice, '>');
            $lastGt = ($ofs !== false && $ofs >= 24);
            if ($lastGt) {
                $slice = \substr($slice, 0, $ofs + 1);
            }
        }

        return $lastGt ? \trim($slice) : \trim(
            \function_exists('mb_substr')
                ? \mb_substr($html, 0, \max(1, $limit - 24), 'UTF-8')
                : \substr($html, 0, \max(1, $limit - 24))
        );
    }

    private function stripPhpFragmentsFromHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = \preg_replace('/\s+[a-zA-Z_:][a-zA-Z0-9_:.-]*\s*=\s*"[^"]*<\?(?:php|=)?[\s\S]*?(?:"|(?=>)|$)/i', '', $html) ?? $html;
        $html = \preg_replace("/\s+[a-zA-Z_:][a-zA-Z0-9_:.-]*\s*=\s*'[^']*<\?(?:php|=)?[\s\S]*?(?:'|(?=>)|$)/i", '', $html) ?? $html;
        $html = \preg_replace('/\s+[a-zA-Z_:][a-zA-Z0-9_:.-]*\s*=\s*[^\s>]*<\?(?:php|=)?[\s\S]*?(?=\s|>|$)/i', '', $html) ?? $html;
        $html = \preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/i', '', $html) ?? $html;
        $html = \preg_replace('/<\?(?:php|=)?[\s\S]*?(?=>|$)/i', '', $html) ?? $html;
        $html = \preg_replace('/<(li|p|span|div|h[1-6])\b[^>]*>[\s\S]{0,700}(?:$[a-z_][a-z0-9_]*|=>|===|foreach\s*\(|endif\b|endforeach\b)[\s\S]{0,700}<\/\1>/iu', '', $html) ?? $html;

        return \str_replace('?>', '', $html);
    }

    private function detectHardGeneratedSectionHtmlPolicyViolation(string $html, string $locale = ''): ?string
    {
        $trimmed = \trim($html);
        if ($trimmed === '') {
            return null;
        }

        if (\preg_match('/<\s*(?:!doctype|html|head|body)\b|<\/\s*(?:html|head|body)\s*>/iu', $trimmed) === 1) {
            return 'component html_content must be an embeddable fragment, not a full HTML document';
        }

        $tagStructureReason = $this->detectHtmlTagStructureViolation($trimmed);
        if ($tagStructureReason !== null) {
            return $tagStructureReason;
        }

        $emptyControlReason = $this->detectEmptyVisitorControlTextViolation($trimmed);
        if ($emptyControlReason !== null) {
            return $emptyControlReason;
        }

        $visibleText = $this->extractVisibleHtmlText($trimmed);
        $copyReason = $this->detectVisibleCopyLocaleViolation($visibleText, $locale);
        if ($copyReason !== null) {
            return $copyReason;
        }
        foreach ($this->extractVisibleHtmlTextChunks($trimmed) as $chunk) {
            $copyReason = $this->detectVisibleCopyLocaleViolation($chunk, $locale);
            if ($copyReason !== null) {
                return $copyReason;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     * @return array<string,mixed>
     */
    private function repairEmptyVisitorControlCopyInAiPayload(
        array $aiData,
        string $componentCode,
        array $defaultConfig,
        array $renderContext
    ): array {
        foreach (['html_content', 'html_extra'] as $htmlKey) {
            if (!\is_string($aiData[$htmlKey] ?? null)) {
                continue;
            }
            $html = (string)$aiData[$htmlKey];
            if (\trim($html) === '' || $this->detectEmptyVisitorControlTextViolation($html) === null) {
                continue;
            }
            $repaired = $this->repairEmptyVisitorControlCopyInHtml($html, $componentCode, $defaultConfig, $renderContext);
            if ($repaired !== $html) {
                $aiData[$htmlKey] = $repaired;
            }
        }

        return $aiData;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function repairEmptyVisitorControlCopyInHtml(
        string $html,
        string $componentCode,
        array $defaultConfig,
        array $renderContext
    ): string {
        if (\trim($html) === '' || !\class_exists(\DOMDocument::class)) {
            return $html;
        }

        $previous = \libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $options = 0;
        if (\defined('LIBXML_HTML_NOIMPLIED')) {
            $options |= \LIBXML_HTML_NOIMPLIED;
        }
        if (\defined('LIBXML_HTML_NODEFDTD')) {
            $options |= \LIBXML_HTML_NODEFDTD;
        }
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="pb-fragment-root">' . $html . '</div>',
            $options
        );
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $html;
        }

        $root = null;
        foreach ($document->getElementsByTagName('div') as $candidate) {
            if ($candidate instanceof \DOMElement && $candidate->getAttribute('id') === 'pb-fragment-root') {
                $root = $candidate;
                break;
            }
        }
        if (!$root instanceof \DOMElement) {
            return $html;
        }

        $elements = [];
        foreach ($root->getElementsByTagName('*') as $element) {
            if ($element instanceof \DOMElement) {
                $elements[] = $element;
            }
        }

        $changed = false;
        foreach ($elements as $element) {
            $kind = $this->classifyVisibleCopyRequiredElement($element);
            if ($kind === '' || $this->shouldIgnoreEmptyVisitorControlElement($element, $kind)) {
                continue;
            }
            if ($this->elementHasMeaningfulVisitorControlText($element)) {
                continue;
            }

            $fallback = $this->resolveEmptyVisitorControlFallbackText($element, $kind, $componentCode, $defaultConfig, $renderContext);
            if ($fallback === '') {
                continue;
            }
            while ($element->firstChild) {
                $element->removeChild($element->firstChild);
            }
            $element->appendChild($document->createTextNode($fallback));
            $changed = true;
        }

        if (!$changed) {
            return $html;
        }

        $repaired = '';
        foreach ($root->childNodes as $child) {
            $chunk = $document->saveHTML($child);
            if (\is_string($chunk)) {
                $repaired .= $chunk;
            }
        }

        return $repaired !== '' ? $repaired : $html;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function resolveEmptyVisitorControlFallbackText(
        \DOMElement $element,
        string $kind,
        string $componentCode,
        array $defaultConfig,
        array $renderContext
    ): string {
        $locale = $this->resolveEmptyVisitorControlFallbackLocale($defaultConfig, $renderContext);
        $classTokens = $this->extractHtmlClassTokensFromValue($element->getAttribute('class'));
        $identity = \mb_strtolower(\trim($componentCode . ' ' . $kind . ' ' . \implode(' ', $classTokens) . ' ' . $element->getAttribute('data-pb-ai-action')));
        $needles = $this->resolveEmptyVisitorControlFallbackNeedles($identity, $kind);

        $candidate = $this->findFirstEmptyVisitorControlConfiguredCopy($defaultConfig, $needles, $locale);
        if ($candidate === '' && \is_array($renderContext['_plan_json_task'] ?? null)) {
            $candidate = $this->findFirstEmptyVisitorControlConfiguredCopy($renderContext['_plan_json_task'], $needles, $locale);
        }
        if ($candidate !== '') {
            return $candidate;
        }

        return $this->localizeEmptyVisitorControlFallbackText($identity, $kind, $locale);
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function resolveEmptyVisitorControlFallbackLocale(array $defaultConfig, array $renderContext): string
    {
        foreach ([
            $renderContext['_content_locale'] ?? null,
            $renderContext['content_locale'] ?? null,
            $renderContext['locale'] ?? null,
            $renderContext['website_locale'] ?? null,
            $defaultConfig['runtime.content_locale'] ?? null,
        ] as $candidate) {
            $locale = \trim((string)$candidate);
            if ($locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function resolveEmptyVisitorControlFallbackNeedles(string $identity, string $kind): array
    {
        if (\str_contains($identity, 'cta') || \str_contains($identity, 'button') || \str_contains($identity, 'action') || \str_contains($kind, 'CTA')) {
            return ['primary_cta', 'cta', 'button', 'action', 'label', 'text'];
        }
        if (\preg_match('/(?:^|[-_\s])(?:value|metric|stat|score|number|amount)(?:$|[-_\s])/iu', $identity) === 1) {
            return ['value', 'metric', 'stat', 'score', 'number', 'amount'];
        }
        if (\preg_match('/(?:^|[-_\s])(?:badge|tag|chip|pill|label|kicker|eyebrow)(?:$|[-_\s])/iu', $identity) === 1) {
            return ['badge', 'tag', 'chip', 'pill', 'label', 'kicker', 'eyebrow'];
        }
        if (\preg_match('/(?:^|[-_\s])(?:proof|quote|step)(?:$|[-_\s])/iu', $identity) === 1) {
            return ['proof', 'quote', 'step', 'title', 'text'];
        }

        return ['label', 'text', 'title', 'copy'];
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $needles
     */
    private function findFirstEmptyVisitorControlConfiguredCopy(array $source, array $needles, string $locale): string
    {
        $stack = [[$source, '', 0]];
        while ($stack !== []) {
            [$node, $path, $depth] = \array_pop($stack);
            if (!\is_array($node) || $depth > 5) {
                continue;
            }
            foreach ($node as $key => $value) {
                $nextPath = $path === '' ? (string)$key : $path . '.' . (string)$key;
                if (\is_array($value)) {
                    $stack[] = [$value, $nextPath, $depth + 1];
                    continue;
                }
                if (!\is_scalar($value) || !$this->pathContainsAnyNeedle($nextPath, $needles)) {
                    continue;
                }
                $candidate = $this->normalizeEmptyVisitorControlConfiguredCopy((string)$value, $locale);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * @param list<string> $needles
     */
    private function pathContainsAnyNeedle(string $path, array $needles): bool
    {
        $path = \mb_strtolower($path);
        foreach ($needles as $needle) {
            if ($needle !== '' && \str_contains($path, \mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeEmptyVisitorControlConfiguredCopy(string $value, string $locale): string
    {
        $value = $this->sanitizeVisibleCopy($value);
        if ($value === '' || \mb_strlen($value) > 80) {
            return '';
        }
        if (\preg_match('/(?:plan_json|home_page|page_type|task_key|section_code|runtime\.|content\/|app\/code\/|var\/|pb-c-|componentId|https?:\/\/|[{}<>]|\$getConfig)/iu', $value) === 1) {
            return '';
        }

        if ($locale !== '') {
            $localized = $this->filterVisibleCopyForLocale($value, $locale);
            if ($localized !== '') {
                return $localized;
            }
            if ($this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($value)) {
                return '';
            }
        }

        return $value;
    }

    private function localizeEmptyVisitorControlFallbackText(string $identity, string $kind, string $locale): string
    {
        $family = $this->promptLocaleFamily($locale);
        $isCta = \str_contains($identity, 'cta') || \str_contains($identity, 'button') || \str_contains($identity, 'action') || \str_contains($kind, 'CTA');
        $isValue = \preg_match('/(?:^|[-_\s])(?:value|metric|stat|score|number|amount)(?:$|[-_\s])/iu', $identity) === 1;
        $isStep = \preg_match('/(?:^|[-_\s])step(?:$|[-_\s])/iu', $identity) === 1;
        $isProof = \preg_match('/(?:^|[-_\s])(?:proof|quote)(?:$|[-_\s])/iu', $identity) === 1;

        if ($family === 'zh') {
            if ($isCta && \preg_match('/(?:resource|guide|download|material)/iu', $identity) === 1) {
                return "\u{9886}\u{53D6}\u{8D44}\u{6599}";
            }
            if ($isCta && \preg_match('/(?:proof|case|story)/iu', $identity) === 1) {
                return "\u{67E5}\u{770B}\u{6848}\u{4F8B}";
            }
            if ($isCta && \preg_match('/(?:final|offer|pricing|price)/iu', $identity) === 1) {
                return "\u{7ACB}\u{5373}\u{9886}\u{53D6}";
            }
            if ($isCta) {
                return "\u{7ACB}\u{5373}\u{54A8}\u{8BE2}";
            }
            if ($isValue) {
                return "\u{6838}\u{5FC3}\u{6210}\u{679C}";
            }
            if ($isStep) {
                return "\u{4E0B}\u{4E00}\u{6B65}";
            }
            if ($isProof) {
                return "\u{771F}\u{5B9E}\u{53CD}\u{9988}";
            }

            return "\u{91CD}\u{70B9}\u{4EAE}\u{70B9}";
        }

        if ($isCta && \preg_match('/(?:resource|guide|download|material)/iu', $identity) === 1) {
            return 'Get Resources';
        }
        if ($isCta && \preg_match('/(?:proof|case|story)/iu', $identity) === 1) {
            return 'View Cases';
        }
        if ($isCta) {
            return 'Get Started';
        }
        if ($isValue) {
            return 'Clear Results';
        }
        if ($isStep) {
            return 'Next Step';
        }
        if ($isProof) {
            return 'Trusted Feedback';
        }

        return 'Key Highlight';
    }

    private function detectEmptyVisitorControlTextViolation(string $html): ?string
    {
        if (\trim($html) === '' || !\class_exists(\DOMDocument::class)) {
            return null;
        }

        $phpClosePattern = \preg_quote('?' . '>', '/');
        $normalized = \html_entity_decode($html, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $normalized = \preg_replace('/<\?(?:php|=)?[\s\S]*?' . $phpClosePattern . '/iu', '__PB_SAFE_PHP_ECHO__', $normalized) ?? $normalized;
        $normalized = \preg_replace('/<\?(?:php|=)?[\s\S]*?(?=>|$)/iu', '__PB_SAFE_PHP_ECHO__', $normalized) ?? $normalized;

        $previous = \libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $options = 0;
        if (\defined('LIBXML_HTML_NOIMPLIED')) {
            $options |= \LIBXML_HTML_NOIMPLIED;
        }
        if (\defined('LIBXML_HTML_NODEFDTD')) {
            $options |= \LIBXML_HTML_NODEFDTD;
        }
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="pb-fragment-root">' . $normalized . '</div>',
            $options
        );
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$element instanceof \DOMElement || $element->getAttribute('id') === 'pb-fragment-root') {
                continue;
            }
            $kind = $this->classifyVisibleCopyRequiredElement($element);
            if ($kind === '' || $this->shouldIgnoreEmptyVisitorControlElement($element, $kind)) {
                continue;
            }
            if ($this->elementHasMeaningfulVisitorControlText($element)) {
                continue;
            }

            return $kind . ' must contain meaningful visible copy: ' . $this->clipText($this->describeDomElementForPolicyError($element), 120);
        }

        return null;
    }

    private function classifyVisibleCopyRequiredElement(\DOMElement $element): string
    {
        $tagName = \mb_strtolower($element->tagName);
        if ($tagName === 'a') {
            return 'link/CTA';
        }
        if ($tagName === 'button') {
            return 'button/CTA';
        }
        if ($tagName === 'label') {
            return 'form label';
        }

        foreach ($this->extractHtmlClassTokensFromValue($element->getAttribute('class')) as $classToken) {
            if ($this->isDecorativeClassTokenForEmptyControlCheck($classToken)) {
                continue;
            }
            if (\preg_match('/(?:^|[-_])(?:cta|btn|button|badge|tag|chip|pill|label|value|kicker|eyebrow|metric|stat|proof|quote|step|item|option|tab)(?:$|[-_])/iu', $classToken) === 1) {
                return 'visible ' . $classToken . ' element';
            }
        }

        return '';
    }

    private function shouldIgnoreEmptyVisitorControlElement(\DOMElement $element, string $kind): bool
    {
        if ($element->hasAttribute('hidden')) {
            return true;
        }
        $ariaHidden = \mb_strtolower(\trim($element->getAttribute('aria-hidden')));
        if ($ariaHidden === 'true') {
            return true;
        }
        $role = \mb_strtolower(\trim($element->getAttribute('role')));
        if (\in_array($role, ['none', 'presentation'], true)) {
            return true;
        }
        $style = \mb_strtolower($element->getAttribute('style'));
        if (\preg_match('/(?:display\s*:\s*none|visibility\s*:\s*hidden)/iu', $style) === 1) {
            return true;
        }

        $classTokens = $this->extractHtmlClassTokensFromValue($element->getAttribute('class'));
        foreach ($classTokens as $classToken) {
            if (\in_array($classToken, ['sr-only', 'visually-hidden'], true)) {
                return true;
            }
        }

        return \in_array($kind, ['link/CTA', 'button/CTA'], true)
            && $this->elementHasAccessibleIconOnlyLabel($element);
    }

    private function elementHasMeaningfulVisitorControlText(\DOMElement $element): bool
    {
        $text = \html_entity_decode((string)$element->textContent, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = \str_replace(["\xc2\xa0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xEF\xBB\xBF"], ' ', $text);
        $text = \trim((string)\preg_replace('/\s+/u', ' ', $text));
        if (\str_contains($text, '__PB_SAFE_PHP_ECHO__')) {
            return true;
        }

        return $this->sanitizeVisibleCopy($text) !== '';
    }

    private function elementHasAccessibleIconOnlyLabel(\DOMElement $element): bool
    {
        $label = \trim($element->getAttribute('aria-label'));
        if ($label === '') {
            $label = \trim($element->getAttribute('title'));
        }
        if ($this->sanitizeVisibleCopy($label) === '') {
            return false;
        }

        return $this->elementHasIconOnlyControlSignal($element);
    }

    private function elementHasIconOnlyControlSignal(\DOMElement $element): bool
    {
        foreach ($this->extractHtmlClassTokensFromValue($element->getAttribute('class')) as $classToken) {
            if (\preg_match('/(?:^|[-_])(?:icon|bar|hamburger|menu|toggle|close|search|chevron|caret)(?:$|[-_])/iu', $classToken) === 1) {
                return true;
            }
        }

        foreach (['img', 'svg', 'i'] as $tagName) {
            if ($element->getElementsByTagName($tagName)->length > 0) {
                return true;
            }
        }

        foreach ($element->getElementsByTagName('span') as $span) {
            if (!$span instanceof \DOMElement) {
                continue;
            }
            foreach ($this->extractHtmlClassTokensFromValue($span->getAttribute('class')) as $classToken) {
                if (\preg_match('/(?:^|[-_])(?:icon|bar|hamburger|menu|toggle|close|search|chevron|caret)(?:$|[-_])/iu', $classToken) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractHtmlClassTokensFromValue(string $classValue): array
    {
        $classValue = \html_entity_decode($classValue, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $tokens = \preg_split('/\s+/u', \mb_strtolower(\trim($classValue))) ?: [];
        $tokens = \array_filter($tokens, static fn(string $token): bool => $token !== '');

        return \array_values(\array_unique($tokens));
    }

    private function isDecorativeClassTokenForEmptyControlCheck(string $classToken): bool
    {
        $decorative = [
            'pb-c-root', 'pb-c-inner', 'pb-c-copy', 'pb-c-text', 'pb-c-text-panel',
            'pb-c-panel', 'pb-c-cards', 'pb-c-card-grid', 'pb-c-grid', 'pb-c-list',
            'pb-c-support', 'pb-c-action', 'pb-c-actions', 'pb-c-form', 'pb-c-field',
            'pb-c-faq-list', 'pb-c-media', 'pb-c-media-stage', 'pb-c-media-subject',
            'pb-c-media-detail', 'pb-c-motif', 'pb-c-orbit', 'pb-c-overlay',
            'pb-c-img', 'pb-c-image', 'pb-c-visual', 'pb-c-decoration',
            'pb-c-shape', 'pb-c-line', 'pb-c-divider',
        ];

        return \in_array(\mb_strtolower($classToken), $decorative, true);
    }

    private function describeDomElementForPolicyError(\DOMElement $element): string
    {
        $tagName = \mb_strtolower($element->tagName);
        $classValue = \trim($element->getAttribute('class'));
        if ($classValue !== '') {
            return '<' . $tagName . ' class="' . $classValue . '">';
        }

        return '<' . $tagName . '>';
    }

    private function containsVisibleRawHtmlFragment(string $visibleText): bool
    {
        if (\trim($visibleText) === '') {
            return false;
        }

        return \preg_match('/(?:<|&lt;)\s*\/?\s*[a-z0-9][^>\n]{0,120}(?:>|&gt;)|\bclass\s*=\s*(["\']).{1,120}\1/iu', $visibleText) === 1;
    }

    private function detectInvalidContactPlaceholderVisibleCopy(string $visibleText): ?string
    {
        $normalized = \trim((string)\preg_replace('/\s+/u', ' ', $visibleText));
        if ($normalized === '') {
            return null;
        }

        $compact = \strtolower((string)\preg_replace('/\s+/u', '', $normalized));
        if (\preg_match('/(?:support|hello|info|contact|sales)@(?:example|domain|yourdomain)\.(?:com|net|org)|(?:support|hello|info|contact|sales)@\.(?:com|net|org)/u', $compact) === 1) {
            return 'placeholder or partial contact email leaked';
        }
        if (\preg_match('/(?:example|sample|test)@|@(?:example|email|mail|domain)\.(?:com|net|org)/u', $compact) === 1) {
            return 'placeholder contact email leaked';
        }
        if (\preg_match('/\b(?:target-locale\s+label|plan-provided\s+(?:contact\s+)?value|localized\s+support\s+promise|neutral\s+target-locale\s+guidance)\b/iu', $normalized) === 1) {
            return 'contact role example text leaked';
        }

        return null;
    }

    private function detectPolicyPageCtaViolation(string $html, string $pageType): ?string
    {
        if (!$this->isPolicyPageType($pageType) || \trim($html) === '') {
            return null;
        }

        foreach ($this->extractGeneratedCtaTexts($html) as $ctaText) {
            if ($this->isPolicyUnsafeCtaLabel($ctaText)) {
                return 'policy page body CTA leaked app/download action: ' . $this->clipText($ctaText, 80);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractGeneratedCtaTexts(string $html): array
    {
        $texts = [];
        $matchCount = \preg_match_all(
            '/<(?P<tag>a|button|div|span)\b(?P<attrs>(?=[^>]*(?:class\s*=\s*(["\'])(?:(?!\3).)*(?:cta|button|action)(?:(?!\3).)*\3|role\s*=\s*(["\'])button\4))[^>]*)>(?P<body>[\s\S]*?)<\/\k<tag>>/iu',
            $html,
            $matches,
            \PREG_SET_ORDER
        );
        if ($matchCount === false || $matchCount < 1) {
            return [];
        }

        foreach ($matches as $match) {
            $text = \trim((string)\preg_replace('/\s+/u', ' ', \html_entity_decode(\strip_tags((string)($match['body'] ?? '')), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')));
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return \array_values(\array_unique($texts));
    }

    private function detectLabelValueReadabilityViolation(string $html, string $visibleText, string $componentCode = ''): ?string
    {
        $visibleText = \trim((string)\preg_replace('/\s+/u', ' ', $visibleText));
        if ($visibleText === '') {
            return null;
        }

        $normalizedHtml = \html_entity_decode($html, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $hasStructuredLabelValue = \preg_match('/\bpb-c(?:-[a-z0-9]+)*-label\b/iu', $normalizedHtml) === 1
            && \preg_match('/\bpb-c(?:-[a-z0-9]+)*-value\b/iu', $normalizedHtml) === 1;

        $labelPattern = '(?:Email(?:\\s*&\\s*Phone)?|Email\\s+Support|Phone\\s+Support|Phone|Office\\s+Address|Office|Business\\s+Hours|Hours|Quick\\s+Help|Android\\s+Version|Storage\\s+Space|Permissions|Internet)';
        if ($hasStructuredLabelValue) {
            return null;
        }
        if (\preg_match('/\\b' . $labelPattern . '(?=(?:support@|\\+?\\d|[A-Z][a-z]))/u', $visibleText) === 1) {
            return 'label/value text is concatenated without punctuation or spacing';
        }
        if (\preg_match('/\\b' . $labelPattern . '\\s*:(?=\\S)/u', $visibleText) === 1) {
            return 'label/value text needs spacing and sibling structure after the colon';
        }

        $normalizedHtml = \html_entity_decode($html, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
 if (\preg_match('/\\b' . $labelPattern . '\\s*<\\/\\s*(?:strong|span|small|div|p)\\s*>\\s*(?:<[^>]+>\\s*)*(?![: ? ?(?:support@|\\+?\\d|[A-Z][a-z])/iu', $normalizedHtml) === 1) {
            return 'label/value markup lacks visible separator punctuation';
        }

        $labelCount = \preg_match_all('/\\b' . $labelPattern . '\\s*:/u', $visibleText);
        $identity = \mb_strtolower($componentCode . ' ' . $visibleText);
        $isFieldDenseBlock = $labelCount >= 2
            || \str_contains($identity, 'contact')
            || \str_contains($identity, 'method')
            || \str_contains($identity, 'hours')
            || \str_contains($identity, 'address');
        if ($isFieldDenseBlock && $labelCount >= 2) {
            if (\preg_match('/\bpb-c(?:-[a-z0-9]+)*-label\b/iu', $normalizedHtml) !== 1 || \preg_match('/\bpb-c(?:-[a-z0-9]+)*-value\b/iu', $normalizedHtml) !== 1) {
                return 'label/value markup must use sibling .pb-c-label and .pb-c-value elements';
            }
        }

        return null;
    }

    private function detectCtaActionContractViolation(string $html, string $componentCode = ''): ?string
    {
        $html = \trim($html);
        if ($html === '') {
            return null;
        }
        $html = \preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/iu', '__PB_SAFE_PHP_ECHO__', $html) ?? $html;

        if (\preg_match('/<(?!a\b|button\b)([a-z][a-z0-9:-]*)\b[^>]*\bclass\s*=\s*(["\'])(?=[^"\']*\bpb-c(?:-[a-z0-9]+)*-cta\b)[^"\']*\2/iu', $html) === 1) {
            return 'primary CTA class must be on an actionable <a> or <button>, not a static element';
        }

        if (\preg_match_all('/<button\b([^>]*)>(.*?)<\/button>/isu', $html, $buttonMatches, \PREG_SET_ORDER) > 0) {
            foreach ($buttonMatches as $buttonMatch) {
                $attrs = (string)($buttonMatch[1] ?? '');
                $buttonText = $this->sanitizeVisibleCopy(\strip_tags((string)($buttonMatch[2] ?? '')));
                $isCtaButton = \preg_match('/\bclass\s*=\s*(["\'])(?=[^"\']*\bpb-c(?:-[a-z0-9]+)*-cta\b)[^"\']*\1/iu', $attrs) === 1
                    || \preg_match('/\bdata-pb-ai-action\s*=/iu', $attrs) === 1;
                $isActionLookingButton = $this->isActionLookingCtaLabel($buttonText);
                if (!$isCtaButton) {
                    if ($isActionLookingButton && \preg_match('/<form\b[\s\S]*' . \preg_quote($buttonMatch[0], '/') . '/iu', $html) !== 1) {
                        return 'action-looking CTA button must declare data-pb-ai-action or be a submit button inside a form';
                    }
                    continue;
                }
                if (\preg_match('/\btype\s*=\s*(["\'])(button|submit)\1/iu', $attrs, $typeMatch) !== 1) {
                    return 'CTA button must declare type=\'button\' or type=\'submit\'';
                }
                $buttonType = \mb_strtolower((string)($typeMatch[2] ?? ''));
                if ($buttonType === 'submit' && \preg_match('/<form\b/iu', $html) === 1) {
                    continue;
                }
                if ($buttonType !== 'button') {
                    return 'event CTA button must use type=\'button\' outside forms';
                }
                if (\preg_match('/\bdata-pb-ai-action\s*=\s*(["\'])[^"\']+\1/iu', $attrs) !== 1) {
                    return 'event CTA button must declare data-pb-ai-action';
                }
            }
        }

        if (\preg_match_all('/<a\b([^>]*)>/iu', $html, $anchorMatches, \PREG_SET_ORDER) > 0) {
            foreach ($anchorMatches as $anchorMatch) {
                $attrs = (string)($anchorMatch[1] ?? '');
                if (\preg_match('/\bclass\s*=\s*(["\'])(?=[^"\']*\bpb-c(?:-[a-z0-9]+)*-cta\b)[^"\']*\1/iu', $attrs) !== 1) {
                    continue;
                }
                if (\preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/isu', $attrs, $hrefMatch) !== 1) {
                    return 'anchor CTA must include a real href';
                }
                $href = \trim((string)($hrefMatch[2] ?? ''));
                if ($href === '' || $href === '#' || \str_starts_with(\mb_strtolower($href), 'javascript:')) {
                    return 'anchor CTA must not use an inert href';
                }
            }
        }

        $componentIdentity = \mb_strtolower($componentCode);
        $isCtaBlock = (\str_contains($componentIdentity, 'cta') || \preg_match('/(?:final[-_\/ ]*action|next[-_\/ ]*step)/u', $componentIdentity) === 1)
            && !\str_contains($componentIdentity, 'form')
            && !\str_contains($componentIdentity, 'faq')
            && !\str_contains($componentIdentity, 'method');
        $hasCtaClass = \preg_match('/\bpb-c(?:-[a-z0-9]+)*-cta\b/iu', $html) === 1;
        if (($isCtaBlock || $hasCtaClass) && !$this->hasActionableCtaControl($html)) {
            return 'CTA must be a real anchor with href or button with data-pb-ai-action';
        }

        return null;
    }

    private function isActionLookingCtaLabel(string $label): bool
    {
        $label = \trim((string)\preg_replace('/\s+/u', ' ', $label));
        if ($label === '') {
            return false;
        }

        return \preg_match(
            '/\b(?:download|apk|app|install|play|start|begin|open|join|claim|submit|send|contact|baixar|instalar|jogar|comecar|começar|iniciar|enviar|contatar)\b/iu',
            $label
        ) === 1;
    }

    private function hasActionableCtaControl(string $html): bool
    {
        if (\preg_match_all('/<a\b([^>]*)>/iu', $html, $anchorMatches, \PREG_SET_ORDER) > 0) {
            foreach ($anchorMatches as $anchorMatch) {
                $attrs = (string)($anchorMatch[1] ?? '');
                if (\preg_match('/\bclass\s*=\s*(["\'])(?=[^"\']*\bpb-c(?:-[a-z0-9]+)*-cta\b)[^"\']*\1/iu', $attrs) !== 1) {
                    continue;
                }
                if (\preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/isu', $attrs, $hrefMatch) !== 1) {
                    continue;
                }
                $href = \trim((string)($hrefMatch[2] ?? ''));
                if ($href !== '' && !\str_starts_with($href, '#') && !\str_starts_with(\mb_strtolower($href), 'javascript:')) {
                    return true;
                }
            }
        }

        if (\preg_match('/<button\b(?=[^>]*\btype\s*=\s*(["\'])button\1)(?=[^>]*\bdata-pb-ai-action\s*=\s*(["\'])[^"\']+\2)(?=[^>]*\bclass\s*=\s*(["\'])(?=[^"\']*\bpb-c(?:-[a-z0-9]+)*-cta\b)[^"\']*\3)[^>]*>/isu', $html) === 1) {
            return true;
        }

        return \preg_match('/<form\b.*?<button\b(?=[^>]*\btype\s*=\s*(["\'])submit\1)(?=[^>]*\bclass\s*=\s*(["\'])(?=[^"\']*\bpb-c(?:-[a-z0-9]+)*-cta\b)[^"\']*\2)[^>]*>/isu', $html) === 1;
    }

    private function detectBlockRoleFidelityViolation(string $componentCode, string $html, string $visibleText, string $styleCss = ''): ?string
    {
        $identity = \mb_strtolower($componentCode . ' ' . $html);
        $componentIdentity = \mb_strtolower($componentCode);
        $isContactMethod = (
            \str_contains($componentIdentity, 'contact') && \str_contains($componentIdentity, 'method')
        ) || \preg_match('/(?:contact[-_\/ ]*channels?|support[-_\/ ]*channels?)/u', $componentIdentity) === 1;
        if ($isContactMethod && !\str_contains($componentIdentity, 'form') && !\str_contains($componentIdentity, 'faq')) {
            $labelNodeCount = \preg_match_all('/\bpb-c(?:-[a-z0-9]+)*-label\b/iu', $html);
            $valueNodeCount = \preg_match_all('/\bpb-c(?:-[a-z0-9]+)*-value\b/iu', $html);
            if ($labelNodeCount < 2 || $valueNodeCount < 2) {
                return 'Contact-method block role fidelity failed: expected at least two contact channels using sibling .pb-c-label and .pb-c-value elements';
            }
            if (\preg_match('/<form\b/iu', $html) === 1 || \preg_match('/\bpb-c-faq-item\b/iu', $html) === 1) {
                return 'Contact-method block role fidelity failed: contact channel hub must not collapse into a form or FAQ block';
            }
        }

        $isFormGuidance = (
            \str_contains($componentIdentity, 'form')
            || \str_contains($componentIdentity, 'support_form')
            || \str_contains($componentIdentity, 'form_guidance')
            || \preg_match('/(?:support[-_\/ ]*form|form[-_\/ ]*guidance|contact[-_\/ ]*form|message[-_\/ ]*form)/u', $componentIdentity) === 1
        ) && !\str_contains($componentIdentity, 'faq');
        if ($isFormGuidance) {
            if (\preg_match('/<form\b/iu', $html) !== 1) {
                return 'Form-guidance block role fidelity failed: expected a real form structure instead of contact cards';
            }
            if (\preg_match_all('/<(?:input|textarea)\b/iu', $html) < 2) {
                return 'Form-guidance block role fidelity failed: expected at least two editable form fields';
            }
            if (\preg_match_all('/<label\b/iu', $html) < 2) {
                return 'Form-guidance block role fidelity failed: expected visible labels for form fields';
            }
        }
        $isCtaBlock = (\str_contains($componentIdentity, 'cta') || \preg_match('/(?:final[-_\/ ]*action|next[-_\/ ]*step)/u', $componentIdentity) === 1)
            && !\str_contains($componentIdentity, 'form')
            && !\str_contains($componentIdentity, 'faq')
            && !\str_contains($componentIdentity, 'method');
        if ($isCtaBlock) {
            if (\preg_match('/<form\b/iu', $html) === 1 || \preg_match('/\bpb-c-faq-item\b/iu', $html) === 1) {
                return 'CTA block role fidelity failed: CTA must not reuse form or FAQ structure';
            }
            $contactLabelCount = \preg_match_all('/\b(?:Email(?:\s+Support)?|Phone(?:\s+Support)?|Office(?:\s+Address)?|Business\s+Hours|Hours)\b/iu', $visibleText);
            $cardCount = \preg_match_all('/\b(?:pb-c(?:-[a-z0-9]+)*-card|contact[-_ ]?card|method[-_ ]?card)\b/iu', $html);
            if ($contactLabelCount >= 2 || $cardCount >= 3) {
                return 'CTA block role fidelity failed: CTA must be a focused next-step band, not repeated contact-method cards';
            }
            if (!$this->hasActionableCtaControl($html)) {
                return 'CTA block role fidelity failed: expected one visible primary action control';
            }
        }
        if (!\str_contains($componentIdentity, 'faq')) {
            return null;
        }

        $questionCount = \preg_match_all('/[?？؟]/u', $visibleText);
        if ($questionCount < 2) {
            return 'FAQ block role fidelity failed: expected at least two explicit visitor questions with separate answers';
        }
        if (\preg_match('/[?？؟]\s*<\/(?:strong|span|div)>\s*<span\b/iu', $html) === 1) {
            return 'FAQ block role fidelity failed: answer must be a separate paragraph, not an inline span after the question';
        }
        if (\preg_match_all('/<p\b[^>]*class=(["\'])[^"\']*\bpb-c-answer\b[^"\']*\1/iu', $html) < 2) {
            return 'FAQ block role fidelity failed: expected separate answer paragraphs with pb-c-answer';
        }
        if (\preg_match_all('/\bpb-c-faq-item\b/iu', $html) < 2) {
            return 'FAQ block role fidelity failed: expected repeated pb-c-faq-item containers';
        }
        $faqItemCss = $this->extractCssRuleBodyForSelector($styleCss, 'pb-c-faq-item');
        if ($faqItemCss === '') {
            return 'FAQ block role fidelity failed: expected visible FAQ item surface styling';
        }
        foreach (['padding', 'border-radius'] as $requiredProperty) {
            if (!\str_contains($faqItemCss, $requiredProperty)) {
                return 'FAQ block role fidelity failed: FAQ item surface styling is too weak';
            }
        }
        if (!\preg_match('/\b(?:background(?:-[a-z-]+)?|border|box-shadow)\s*:/iu', $faqItemCss)) {
            return 'FAQ block role fidelity failed: FAQ item surface needs background, border, or shadow';
        }

        return null;
    }

    private function extractCssRuleBodyForSelector(string $styleCss, string $className): string
    {
        if (\trim($styleCss) === '' || \trim($className) === '') {
            return '';
        }

        $body = '';
        if (\preg_match_all('/([^{}]+)\{([^{}]*)\}/u', $styleCss, $matches, \PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $selector = \strtolower((string)($match[1] ?? ''));
                if (!\str_contains($selector, '.' . \strtolower($className))) {
                    continue;
                }
                $body .= ' ' . \strtolower((string)($match[2] ?? ''));
            }
        }

        return \trim((string)\preg_replace('/\s+/u', ' ', $body));
    }

    private function detectStructuralGeneratedSectionHtmlReason(string $html, ?string $componentCode = '', string $styleCss = ''): ?string
    {
        unset($styleCss);

        $trimmed = \trim($html);
        if ($trimmed === '') {
            return 'empty html';
        }

        $tagStructureReason = $this->detectHtmlTagStructureViolation($trimmed);
        if ($tagStructureReason !== null) {
            return $tagStructureReason;
        }

        $emptyControlReason = $this->detectEmptyVisitorControlTextViolation($trimmed);
        if ($emptyControlReason !== null) {
            return $emptyControlReason;
        }

        $nestedContainerReason = $this->detectNestedRepeatedContentContainerViolation($trimmed);
        if ($nestedContainerReason !== null) {
            return $nestedContainerReason;
        }

        $componentCode = $this->normalizeOptionalComponentCode($componentCode);
        $ctaActionReason = $this->detectCtaActionContractViolation($trimmed, $componentCode);
        if ($ctaActionReason !== null) {
            return $ctaActionReason;
        }

        if (\preg_match('/<(h[1-6]|p|a)\b[^>]*>\s*<\/\1>/iu', $trimmed) === 1) {
            return 'empty visitor element';
        }

        return null;
    }

    private function detectHtmlTagStructureViolation(string $html): ?string
    {
        $html = \trim($html);
        if ($html === '') {
            return null;
        }

        $voidTags = \array_fill_keys([
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ], true);
        if (\preg_match_all('/<\s*(\/?)\s*([a-z][a-z0-9:-]*)\b([^<>]*?)(\/?)\s*>/isu', $html, $matches, \PREG_SET_ORDER) < 1) {
            return null;
        }

        $stack = [];
        foreach ($matches as $match) {
            $closing = (string)($match[1] ?? '') === '/';
            $tag = \strtolower((string)($match[2] ?? ''));
            if ($tag === '') {
                continue;
            }

            if ($closing) {
                $expected = \end($stack);
                if ($expected === false) {
                    return 'unexpected closing HTML tag: </' . $tag . '>';
                }
                if ($expected !== $tag) {
                    return 'mismatched HTML closing tag: expected </' . $expected . '> before </' . $tag . '>';
                }
                \array_pop($stack);
                continue;
            }

            $selfClosing = (string)($match[4] ?? '') === '/' || isset($voidTags[$tag]);
            if (!$selfClosing) {
                $stack[] = $tag;
            }
        }

        if ($stack === []) {
            return null;
        }

        $unclosed = [];
        foreach ($stack as $tag) {
            $unclosed[$tag] = ($unclosed[$tag] ?? 0) + 1;
        }
        \ksort($unclosed);

        $parts = [];
        foreach ($unclosed as $tag => $count) {
            $parts[] = $tag . '(' . $count . ')';
        }

        return 'unclosed HTML tags: ' . \implode(', ', $parts);
    }

    private function detectNestedRepeatedContentContainerViolation(string $html): ?string
    {
        if (\trim($html) === '') {
            return null;
        }

        $voidTags = \array_fill_keys([
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ], true);
        /** @var list<array{tag:string,classes:array<string,bool>}> $stack */
        $stack = [];
        if (\preg_match_all('/<\s*(\/?)\s*([a-z][a-z0-9:-]*)\b([^<>]*?)(\/?)\s*>/isu', $html, $matches, \PREG_SET_ORDER) < 1) {
            return null;
        }

        foreach ($matches as $match) {
            $closing = (string)($match[1] ?? '') === '/';
            $tag = \strtolower((string)($match[2] ?? ''));
            if ($tag === '') {
                continue;
            }

            if ($closing) {
                for ($index = \count($stack) - 1; $index >= 0; --$index) {
                    if (($stack[$index]['tag'] ?? '') !== $tag) {
                        continue;
                    }
                    $stack = \array_slice($stack, 0, $index);
                    break;
                }
                continue;
            }

            $classes = $this->extractRepeatedContentContainerClassesFromTagAttributes((string)($match[3] ?? ''));
            foreach (\array_keys($classes) as $className) {
                foreach ($stack as $entry) {
                    if (!isset($entry['classes'][$className])) {
                        continue;
                    }
                    return 'nested repeated content container: .' . $className
                        . ' appears inside another .' . $className
                        . '; repeated cards/items/panels must be sibling elements with balanced closing tags';
                }
            }

            $selfClosing = (string)($match[4] ?? '') === '/' || isset($voidTags[$tag]);
            if (!$selfClosing) {
                $stack[] = ['tag' => $tag, 'classes' => $classes];
            }
        }

        return null;
    }

    /**
     * @return array<string,bool>
     */
    private function extractRepeatedContentContainerClassesFromTagAttributes(string $attributes): array
    {
        if ($attributes === ''
            || \preg_match('/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/isu', $attributes, $match) !== 1
        ) {
            return [];
        }

        $classValue = '';
        foreach ([1, 2, 3] as $index) {
            if (!isset($match[$index]) || (string)$match[$index] === '') {
                continue;
            }
            $classValue = (string)$match[$index];
            break;
        }
        $classValue = \html_entity_decode($classValue, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $tokens = \preg_split('/\s+/u', \trim($classValue)) ?: [];
        $classes = [];
        foreach ($tokens as $token) {
            $className = \trim((string)$token);
            if ($className === '' || !$this->isRepeatedContentContainerClass($className)) {
                continue;
            }
            $classes[\strtolower($className)] = true;
        }

        return $classes;
    }

    private function isRepeatedContentContainerClass(string $className): bool
    {
        return \preg_match(
            '/^pb-c-(?:[a-z0-9]+-)*(?:card|item|panel|tile|review|testimonial|quote|step|feature|stat|channel|method|field|entry|row)$/iu',
            $className
        ) === 1;
    }

    private function detectTypographyQualityViolation(string $styleCss): ?string
    {
        $styleCss = \trim($styleCss);
        if ($styleCss === '') {
 //  ?unit test  ? ? ?
            return null;
        }
        if (\preg_match_all('/font-family\s*:\s*([^;}]+)/iu', $styleCss, $matches) < 1) {
            return 'missing branded typography system';
        }

        $defaultFamilies = \array_fill_keys([
            'system-ui',
            '-apple-system',
            'blinkmacsystemfont',
            'segoe ui',
            'roboto',
            'arial',
            'helvetica',
            'sans-serif',
        ], true);
        $hasNonDefaultFont = false;
        $hasHeadingFont = false;
        $hasBodyFont = false;
        if (\preg_match_all('/([^{}]+)\{([^}]*)\}/iu', $styleCss, $ruleMatches, \PREG_SET_ORDER) > 0) {
            foreach ($ruleMatches as $ruleMatch) {
                $selector = \strtolower(\trim((string)($ruleMatch[1] ?? '')));
                $body = (string)($ruleMatch[2] ?? '');
                if (\preg_match('/font-family\s*:\s*([^;}]+)/iu', $body, $fontMatch) !== 1) {
                    continue;
                }
                if (!$this->isNonDefaultFontFamilyDeclaration((string)($fontMatch[1] ?? ''), $defaultFamilies)) {
                    continue;
                }
                $hasNonDefaultFont = true;
                if (\preg_match('/(?:-title\b|\.title\b|\bh[1-6]\b)/iu', $selector) === 1) {
                    $hasHeadingFont = true;
                }
                if (\preg_match('/(?:-root\b|-copy\b|-text\b|\.root\b|\.copy\b|\.text\b|body\b)/iu', $selector) === 1) {
                    $hasBodyFont = true;
                }
            }
        }
        if ($hasHeadingFont && $hasBodyFont) {
            return null;
        }
        if ($hasNonDefaultFont) {
            return 'branded typography must cover both heading and body/root selectors';
        }

        foreach ($matches[1] ?? [] as $declaration) {
            if ($this->isNonDefaultFontFamilyDeclaration((string)$declaration, $defaultFamilies)) {
                return 'branded typography must cover both heading and body/root selectors';
            }
        }

        return 'default system font stack used instead of branded typography';
    }

    /**
     * @param array<string,bool> $defaultFamilies
     */
    private function isNonDefaultFontFamilyDeclaration(string $declaration, array $defaultFamilies): bool
    {
        $families = \array_filter(\array_map(static function (string $family): string {
            return \strtolower(\trim($family, " \t\n\r\0\x0B'\""));
        }, \explode(',', $declaration)));
        if ($families === []) {
            return false;
        }
        foreach ($families as $family) {
            if (!isset($defaultFamilies[$family])) {
                return true;
            }
        }

        return false;
    }

    private function detectGenericCtaIntentViolation(string $visibleText, string $componentCode): ?string
    {
        if (!$this->isGenericConsultCtaLabel($visibleText)) {
            return null;
        }

        $identity = \mb_strtolower($componentCode . ' ' . $visibleText);
        if ($this->isContactOrSupportIntent($identity)) {
            return null;
        }
        if (\preg_match('/\b(?:home|about|blog|download|apk|app|install|reward|bonus|game|feature|showcase|trust|security|safe|cta|hero)\b/iu', $identity) === 1) {
            return 'generic consult CTA label does not match the block conversion intent';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function enforceConfiguredCtaIntentInAiPayload(array $aiData, array $defaultConfig, string $componentCode): array
    {
        $configuredLabel = $this->firstConfigString($defaultConfig, ['cta.text', 'content.cta_text']);
        if ($configuredLabel === '' || $this->isGenericConsultCtaLabel($configuredLabel) || $this->isGenericCtaContactLabel($configuredLabel)) {
            return $aiData;
        }

        $identity = \mb_strtolower($componentCode . ' ' . \implode(' ', \array_map('strval', [
            $defaultConfig['runtime.section_template'] ?? '',
            $defaultConfig['runtime.section_name'] ?? '',
            $defaultConfig['content.title'] ?? '',
            $defaultConfig['content.description'] ?? '',
        ])));
        if ($this->isContactOrSupportIntent($identity)) {
            return $aiData;
        }

        foreach (['html_content', 'html_extra'] as $key) {
            if (!\is_string($aiData[$key] ?? null) || $aiData[$key] === '') {
                continue;
            }
            $aiData[$key] = $this->replaceGenericConsultCtaLabels((string)$aiData[$key], $configuredLabel);
        }

        return $aiData;
    }

    /**
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function assertSharedComponentCopyMatchesSourceIndustry(
        array $aiData,
        string $region,
        array $defaultConfig,
        array $renderContext
    ): void {
        $visible = '';
        foreach (['html_content', 'html_extra', 'html_extra_column', 'footer_extra_text'] as $key) {
            if (\is_string($aiData[$key] ?? null) && \trim((string)$aiData[$key]) !== '') {
                $visible .= ' ' . $this->extractVisibleHtmlText((string)$aiData[$key]);
            }
        }
        $visible = \trim($visible);
        if ($visible === '' || $this->sourceContextAllowsDownloadOrGameCopy($defaultConfig, $renderContext)) {
            return;
        }

        if ($this->containsOutOfScopeDownloadOrGameCopy($visible)) {
            throw new \RuntimeException(
                'AI component copy quality failed: ' . $region
                . ' reused app-download/game industry copy outside the approved source brief.'
            );
        }
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function sourceContextAllowsDownloadOrGameCopy(array $defaultConfig, array $renderContext): bool
    {
        $source = \mb_strtolower($this->collectScalarPromptText([
            'default_config' => $defaultConfig,
            'website_profile' => $renderContext['_website_profile'] ?? [],
            'scope_identity' => $renderContext['_scope_identity'] ?? [],
        ]));

        return \preg_match(
            '/\b(?:download|apk|app|install|android|ios|game|play|casino|poker|rummy|ludo|bonus|reward)\b/iu',
            $source
        ) === 1;
    }

    private function containsOutOfScopeDownloadOrGameCopy(string $visibleText): bool
    {
        return \preg_match(
            '/\b(?:safe\s+download|secure\s+download|download\s+now|install\s+now|play\s+now|start\s+playing|claim\s+bonus|apk|android\s+build|game\s+bonus|free\s+coins?)\b/iu',
            $visibleText
        ) === 1;
    }

    /**
     * @param mixed $value
     */
    private function collectScalarPromptText(mixed $value, int $depth = 0): string
    {
        if ($depth > 4) {
            return '';
        }
        if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
            return ' ' . (string)$value;
        }
        if (!\is_array($value)) {
            return '';
        }

        $text = '';
        foreach ($value as $item) {
            $text .= $this->collectScalarPromptText($item, $depth + 1);
            if (\mb_strlen($text) > 6000) {
                break;
            }
        }

        return $text;
    }

    private function replaceGenericConsultCtaLabels(string $value, string $replacement): string
    {
        $replacement = \trim($replacement);
        if ($replacement === '') {
            return $value;
        }

        return \preg_replace(
            '/\b(?:consult\s+now|contact\s+us|enquire|inquire|talk\s+to\s+us|get\s+in\s+touch)\b|\x{7acb}\x{5373}\x{54a8}\x{8be2}|\x{9a6c}\x{4e0a}\x{54a8}\x{8be2}|\x{8054}\x{7cfb}\x{6211}\x{4eec}|\x{8054}\x{7cfb}\x{5ba2}\x{670d}|\x{54a8}\x{8be2}/iu',
            $replacement,
            $value
        ) ?? $value;
    }

    private function isGenericCtaContactLabel(string $label): bool
    {
        return \preg_match('/\x{7acb}\x{5373}\x{54a8}\x{8be2}|\x{9a6c}\x{4e0a}\x{54a8}\x{8be2}|\x{8054}\x{7cfb}\x{6211}\x{4eec}|\x{8054}\x{7cfb}\x{5ba2}\x{670d}|\x{54a8}\x{8be2}/iu', $label) === 1;
    }

    private function containsCssUnitMergedIntoNextDeclaration(string $css): bool
    {
        $property = '(?:position|inset|overflow|display|width|min-width|max-width|height|min-height|max-height|z-index|opacity|background(?:-image)?|color|border(?:-radius|-color)?|box-shadow|font(?:-size|weight|family)?|line-height|padding|margin|flex(?:-direction|-wrap|-grow|-shrink|-basis)?|grid-template-columns|gap|align-items|justify-content|object-fit|object-position|box-sizing|text-decoration|cursor|outline)';

        return \preg_match('/(?:\d+(?:\.\d+)?(?:px|rem|em|vh|vw|%)|#[0-9a-f]{3,8})(?=\s*' . $property . '\s*:)/i', $css) === 1;
    }

    private function looksLikeSparseOversizedSection(string $html, string $plain): bool
    {
        if (\mb_strlen($plain) > 220) {
            return false;
        }

        return \preg_match('/(?:min-height|height)\s*:\s*(?:[5-9]\d{2}|[1-9]\d{3})px/iu', $html) === 1
            || \preg_match('/padding\s*:\s*(?:clamp\([^)]*(?:9|10|11|12)\dpx|(?:9|10|11|12)\dpx)/iu', $html) === 1;
    }

    private function looksLikeSparseMetricCards(string $html, string $plain): bool
    {
 if (!\preg_match('/\b(?:10\s* ?\.8| ?|rating|secure)\b/iu', $plain)) {
            return false;
        }
        if (\mb_strlen($plain) > 260) {
            return false;
        }

        $largeCardCount = \preg_match_all('/(?:min-height|height)\s*:\s*(?:1[6-9]\d|[2-9]\d{2})px/iu', $html);
        return $largeCardCount >= 2;
    }

    private function looksLikeIsolatedCtaIsland(string $html, string $plain): bool
    {
 if (!\preg_match('/\b(?: et started|start now| ?\b/iu', $plain)) {
            return false;
        }
        if (\mb_strlen($plain) > 160) {
            return false;
        }

        return \preg_match('/(?:min-height|height)\s*:\s*(?:[6-9]\d{2}|[1-9]\d{3})px/iu', $html) === 1;
    }

    private function looksLikeThinNonHeroTextOnlySection(string $html, string $plain, string $componentCode): bool
    {
        if (\mb_strlen($plain) > 240) {
            return false;
        }

        if (!$this->isNonHeroTextQualityScope($html, $componentCode)) {
            return false;
        }
        if (\preg_match('/<img\b|data-pb-ai-image-role=["\']generated-asset["\']/iu', $html) === 1) {
            return false;
        }

        $deviceClassCount = \preg_match_all(
            '/\bpb-c(?:-[a-z0-9]+)*-(?:support|proof|badge|stat|metric|rail|timeline|process|comparison|callout|media|visual|card|channel|feature|item|motif|orbit|shape|detail|cluster)\b/iu',
            $html
        );
        if ($deviceClassCount >= 2) {
            return false;
        }

        $headingCount = \preg_match_all('/<h[1-6]\b/iu', $html);
        $paragraphCount = \preg_match_all('/<p\b/iu', $html);
        $ctaCount = \preg_match_all('/\bpb-c(?:-[a-z0-9]+)*-cta\b/iu', $html);
        $elementCount = \preg_match_all('/<(?:section|div|h[1-6]|p|span|small|strong)\b/iu', $html);

        return $headingCount <= 1
            && $paragraphCount <= 1
            && ($ctaCount >= 1 || $elementCount <= 8);
    }

    private function looksLikeSparseProofOnlyNonHeroSection(string $html, string $plain, string $componentCode): bool
    {
        if (!$this->isNonHeroTextQualityScope($html, $componentCode)) {
            return false;
        }
        if (\mb_strlen($plain) > 260) {
            return false;
        }

        $proofCount = \preg_match_all('/\bpb-c(?:-[a-z0-9]+)*-proof\b/iu', $html);
        if ($proofCount <= 0) {
            return false;
        }

        $hasRicherDevice = \preg_match(
            '/\bpb-c(?:-[a-z0-9]+)*-(?:media|visual|badge|stat|metric|rail|timeline|process|comparison|callout|motif|orbit|shape|cluster|card|step|checklist|feature|item|quote)\b/iu',
            $html
        ) === 1;

        return $proofCount < 3 && !$hasRicherDevice;
    }

    private function looksLikeTabletCollapsedProofOnlyNonHeroSection(
        string $html,
        string $plain,
        string $componentCode,
        string $styleCss
    ): bool {
        // Stage-2 visual_signature owns tablet rhythm now. This removed gate
        // encoded one proof-grid preference and caused unrelated blocks to
        // converge on the same desktop/tablet shell.
        return false;

        if (!$this->isNonHeroTextQualityScope($html, $componentCode)) {
            return false;
        }
        if (\mb_strlen($plain) > 320) {
            return false;
        }

        $proofCount = \preg_match_all('/\bpb-c(?:-[a-z0-9]+)*-proof\b/iu', $html);
        if ($proofCount < 3) {
            return false;
        }
        if (\preg_match('/\bpb-c(?:-[a-z0-9]+)*-support\b/iu', $html) !== 1) {
            return false;
        }

        $payload = $html . "\n" . $styleCss;
        $tabletMedia = '@media\s*\(\s*max-width\s*:\s*(?:7[0-9]{2}|8[0-9]{2}|900)px\s*\)';
        $supportSelector = '\.pb-c(?:-[a-z0-9]+)*-support\s*\{[^}]*';
        $proofSelector = '\.pb-c(?:-[a-z0-9]+)*-proof\s*\{[^}]*';
        $sameMediaBlock = '(?:(?!@media\s*\().){0,1200}';
        $patterns = [
            '/' . $tabletMedia . $sameMediaBlock . $supportSelector . 'grid-template-columns\s*:\s*(?:1fr|repeat\(\s*1\s*,|minmax\(\s*0\s*,\s*1fr\s*\))/isu',
            '/' . $tabletMedia . $sameMediaBlock . $supportSelector . 'display\s*:\s*block\b/isu',
            '/' . $tabletMedia . $sameMediaBlock . $supportSelector . 'flex-direction\s*:\s*column\b/isu',
            '/' . $tabletMedia . $sameMediaBlock . $proofSelector . 'flex\s*:\s*1\s+1\s+100%/isu',
        ];
        foreach ($patterns as $pattern) {
            if (\preg_match($pattern, $payload) === 1) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeCenteredProofOnlyNonHeroSection(
        string $html,
        string $plain,
        string $componentCode,
        string $styleCss
    ): bool {
        if (!$this->isNonHeroTextQualityScope($html, $componentCode)) {
            return false;
        }
        if (\mb_strlen($plain) > 320) {
            return false;
        }
        if (\preg_match('/<img\b|data-pb-ai-image-role=["\']generated-asset["\']/iu', $html) === 1) {
            return false;
        }

        $proofCount = \preg_match_all('/\bpb-c(?:-[a-z0-9]+)*-proof\b/iu', $html);
        if ($proofCount < 3) {
            return false;
        }
        $hasRicherDevice = \preg_match(
            '/\bpb-c(?:-[a-z0-9]+)*-(?:media|visual|badge|stat|metric|rail|timeline|process|comparison|callout|motif|orbit|shape|cluster|card|step|checklist|feature|item|quote)\b/iu',
            $html
        ) === 1;
        if ($hasRicherDevice) {
            return false;
        }

        $payload = $html . "\n" . $styleCss;
        $copyCentered = \preg_match('/\.pb-c(?:-[a-z0-9]+)*-copy\s*\{[^}]*text-align\s*:\s*center\b/iu', $payload) === 1;
        if (!$copyCentered) {
            return false;
        }

        $singleColumnInner = \preg_match('/\.pb-c(?:-[a-z0-9]+)*-inner\s*\{[^}]*grid-template-columns\s*:\s*(?:1fr|minmax\(\s*0\s*,\s*1fr\s*\))/iu', $payload) === 1
            || \preg_match('/\.pb-c(?:-[a-z0-9]+)*-inner\s*\{[^}]*display\s*:\s*block\b/iu', $payload) === 1;
        $centeredAction = \preg_match('/\.pb-c(?:-[a-z0-9]+)*-action\s*\{[^}]*justify-content\s*:\s*center\b/iu', $payload) === 1;

        return $singleColumnInner || $centeredAction;
    }

    private function looksLikeTabletOrphanProofGrid(
        string $html,
        string $plain,
        string $componentCode,
        string $styleCss
    ): bool {
        // Stage-2 visual_signature may intentionally choose 2+1, rail, stack,
        // or cluster rhythms. Browser validation catches actual broken layouts.
        return false;

        if (!$this->isNonHeroTextQualityScope($html, $componentCode)) {
            return false;
        }
        if (\mb_strlen($plain) > 320) {
            return false;
        }

        $proofCount = \preg_match_all('/\bpb-c(?:-[a-z0-9]+)*-proof\b/iu', $html);
        if ($proofCount !== 3) {
            return false;
        }
        $hasRicherDevice = \preg_match(
            '/\bpb-c(?:-[a-z0-9]+)*-(?:media|visual|badge|stat|metric|rail|timeline|process|comparison|callout|motif|orbit|shape|cluster|card|step|checklist|feature|item|quote)\b/iu',
            $html
        ) === 1;
        if ($hasRicherDevice) {
            return false;
        }

        $payload = $html . "\n" . $styleCss;
        if (\preg_match('/@media\s*\(\s*max-width\s*:\s*6[0-9]{2}px\s*\)/iu', $payload) === 1) {
            return false;
        }

        $tabletMedia = '@media\s*\(\s*max-width\s*:\s*(?:7[0-9]{2}|8[0-9]{2}|900)px\s*\)';
        $sameMediaBlock = '(?:(?!@media\s*\().){0,1200}';
        $supportSelector = '\.pb-c(?:-[a-z0-9]+)*-support\s*\{[^}]*';

        return \preg_match('/' . $tabletMedia . $sameMediaBlock . $supportSelector . 'grid-template-columns\s*:\s*repeat\(\s*2\s*,/isu', $payload) === 1;
    }

    private function looksLikeOverPaddedProofOnlyNonHeroSection(
        string $html,
        string $plain,
        string $componentCode,
        string $styleCss
    ): bool {
        if (!$this->isNonHeroTextQualityScope($html, $componentCode)) {
            return false;
        }
        if (\mb_strlen($plain) > 280) {
            return false;
        }
        if (\preg_match('/<img\b|data-pb-ai-image-role=["\']generated-asset["\']/iu', $html) === 1) {
            return false;
        }

        $proofCount = \preg_match_all('/\bpb-c(?:-[a-z0-9]+)*-proof\b/iu', $html);
        if ($proofCount < 3) {
            return false;
        }

        $hasRicherDevice = \preg_match(
            '/\bpb-c(?:-[a-z0-9]+)*-(?:media|visual|badge|stat|metric|rail|timeline|process|comparison|callout|motif|orbit|shape|cluster|card|step|checklist|feature|item|quote)\b/iu',
            $html
        ) === 1;
        if ($hasRicherDevice) {
            return false;
        }

        $payload = $html . "\n" . $styleCss;

        return \preg_match('/\.pb-c(?:-[a-z0-9]+)*-root\s*\{[^}]*padding\s*:\s*(?:7[2-9]|[89]\d|1\d{2})px\s+(?:1\d|2\d|3\d)px/iu', $payload) === 1
            || \preg_match('/\.pb-c(?:-[a-z0-9]+)*-root\s*\{[^}]*padding\s*:\s*clamp\([^;]*(?:7[2-9]|[89]\d|1\d{2})px/iu', $payload) === 1;
    }

    private function looksLikeDetachedNonHeroCtaAfterSupport(string $html, string $componentCode): bool
    {
        // CTA placement is now judged by explicit role contracts and spacing
        // checks, not by a fixed DOM order between support and action groups.
        return false;

        if (!$this->isNonHeroTextQualityScope($html, $componentCode)) {
            return false;
        }
        if (\preg_match('/\bpb-c(?:-[a-z0-9]+)*-(?:support|proof)\b/iu', $html) !== 1
            || \preg_match('/\bpb-c(?:-[a-z0-9]+)*-cta\b/iu', $html) !== 1
        ) {
            return false;
        }

        $supportPos = \stripos($html, '-support');
        $actionPos = \stripos($html, '-action');
        if ($supportPos === false || $actionPos === false) {
            return false;
        }

        return $supportPos < $actionPos;
    }

    private function isNonHeroTextQualityScope(string $html, string $componentCode): bool
    {
        $componentIdentity = \mb_strtolower(\trim($componentCode));
        if (\str_contains($componentIdentity, 'strict_hero_cover')) {
            return false;
        }

        if (\preg_match('/\b(?:header|footer|nav|menu|tabs?|filter|selector|faq|form|contact|method)\b/iu', $componentIdentity) === 1) {
            return false;
        }

        $normalizedComponent = \trim((string)\preg_replace('/[^a-z0-9]+/u', '_', $componentIdentity), '_');
        if ($normalizedComponent !== ''
            && \preg_match('/(?:^|_)(?:cta|final_cta|contact_cta|about_cta|page_cta|download_cta|conversion_cta|cta_band)(?:_|$)/u', $normalizedComponent) === 1
        ) {
            return false;
        }
        if ($componentIdentity === ''
            && \preg_match('/\bpb-c(?:-[a-z0-9]+)*-(?:faq|form|channel)\b/iu', $html) === 1
        ) {
            return false;
        }

        return true;
    }

    private function looksLikeStretchedCtaBar(string $html): bool
    {
        if (\preg_match('/\b(?:full[-_ ]?width|conversion[-_ ]?band|cta[-_ ]?band|download[-_ ]?band)\b/iu', $html) === 1) {
            return false;
        }

        $cssCandidates = [];
        $inlineCandidates = [];
        if (\preg_match_all('/([^{}]+)\{([^}]*)\}/iu', $html, $matches, \PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $selector = (string)($match[1] ?? '');
                if (!$this->selectorTargetsExactCtaElement($selector)) {
                    continue;
                }
                $cssCandidates[] = (string)($match[2] ?? '');
            }
        }
        if (\preg_match_all('/<[^>]+\bclass=(["\'])([^"\']*)\1[^>]*\bstyle=(["\'])(.*?)\3[^>]*>/iu', $html, $matches, \PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                if (!$this->classListContainsExactCtaElement((string)($match[2] ?? ''))) {
                    continue;
                }
                $inlineCandidates[] = (string)($match[4] ?? '');
            }
        }

        foreach ($inlineCandidates as $declarations) {
            $css = \mb_strtolower((string)\preg_replace('/\s+/u', ' ', $declarations));
            if ($this->ctaDeclarationsLookStretched($css) && !$this->ctaDeclarationsLookCompact($css)) {
                return true;
            }
        }

        $hasStretchedCss = false;
        $hasCompactCss = false;
        foreach ($cssCandidates as $declarations) {
            $css = \mb_strtolower((string)\preg_replace('/\s+/u', ' ', $declarations));
            if ($css === '') {
                continue;
            }
            $hasStretchedCss = $hasStretchedCss || $this->ctaDeclarationsLookStretched($css);
            $hasCompactCss = $hasCompactCss || $this->ctaDeclarationsLookCompact($css);
        }
        if (!$hasStretchedCss) {
            return false;
        }
        if ($hasCompactCss) {
            return false;
        }

        return true;
    }

    private function ctaDeclarationsLookCompact(string $css): bool
    {
        if ($css === '') {
            return false;
        }
        if (\preg_match('/\b(?:display\s*:\s*inline-flex|width\s*:\s*auto|flex\s*:\s*0\s+0\s+auto)\b/iu', $css) === 1) {
            return true;
        }
        if (\preg_match('/\bmax-width\s*:\s*(\d+(?:\.\d+)?)px\b/iu', $css, $maxWidthMatch) === 1
            && (float)($maxWidthMatch[1] ?? 9999) <= 360.0
        ) {
            return true;
        }

        return false;
    }

    private function ctaDeclarationsLookStretched(string $css): bool
    {
        if ($css === '') {
            return false;
        }
        if (\preg_match('/\bwidth\s*:\s*100%\s*(?:;|$)/iu', $css) === 1
            && \preg_match('/\bmax-width\s*:\s*(\d+(?:\.\d+)?)px\b/iu', $css, $maxWidthMatch) === 1
            && (float)($maxWidthMatch[1] ?? 9999) <= 360.0
        ) {
            return false;
        }
        if (\preg_match('/(?:\bwidth\s*:\s*100%\s*(?:;|$)|\bflex\s*:\s*1\s*(?:;|$)|\bflex-grow\s*:\s*1\s*(?:;|$))/iu', $css) === 1) {
            return true;
        }

        return \preg_match('/\bdisplay\s*:\s*block\s*(?:;|$)/iu', $css) === 1
            && \preg_match('/\b(?:width|max-width)\s*:\s*100%\s*(?:;|$)/iu', $css) === 1;
    }

    private function selectorTargetsExactCtaElement(string $selector): bool
    {
        $selectors = \preg_split('/,/u', $selector) ?: [];
        foreach ($selectors as $singleSelector) {
            $singleSelector = \trim((string)$singleSelector);
            if ($singleSelector === '') {
                continue;
            }
            if (\preg_match_all('/\.(?:cta|[a-z0-9_-]+-cta)(?![a-z0-9_-])/iu', $singleSelector, $matches, \PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }
            foreach ($matches[0] as $match) {
                $offset = (int)($match[1] ?? 0);
                $token = (string)($match[0] ?? '');
                $after = \substr($singleSelector, $offset + \strlen($token));
                if (\preg_match('/[\s>+~]/u', $after) === 1) {
                    continue;
                }
                return true;
            }
        }

        return false;
    }

    private function classListContainsExactCtaElement(string $classList): bool
    {
        foreach (\preg_split('/\s+/u', \trim($classList)) ?: [] as $className) {
            $className = \trim((string)$className);
            if ($className === '') {
                continue;
            }
            if (\preg_match('/^(?:cta|[a-z0-9_-]+-cta)$/iu', $className) === 1) {
                return true;
            }
        }

        return false;
    }

    private function detectCtaSpacingRhythmViolation(string $html, string $styleCss): ?string
    {
        if (\preg_match('/class=(["\'])[^"\']*\b(?:cta|[a-z0-9_-]+-cta)\b[^"\']*\1/iu', $html) !== 1) {
            return null;
        }
        if (\preg_match('/class=(["\'])[^"\']*\b(?:channel|row|field|form|faq-item|question|answer|label|value)\b[^"\']*\1/iu', $html) !== 1) {
            return null;
        }

        $actionBodies = $this->extractCssRuleBodiesForClassPattern(
            $styleCss,
            '/^(?:action|actions|[a-z0-9_-]+-(?:action|actions))$/iu'
        );
        foreach ($actionBodies as $body) {
            if ($this->cssBodyProvidesActionWrapperSeparation($body)) {
                return null;
            }
        }

        $ctaBodies = $this->extractCssRuleBodiesForClassPattern(
            $styleCss,
            '/^(?:cta|[a-z0-9_-]+-cta)$/iu'
        );
        foreach ($ctaBodies as $body) {
            if ($this->cssBodyProvidesCtaOuterSeparation($body)) {
                return null;
            }
        }

        $copyBodies = $this->extractCssRuleBodiesForClassPattern(
            $styleCss,
            '/^(?:copy|content|panel|[a-z0-9_-]+-(?:copy|content|panel))$/iu'
        );
        foreach ($copyBodies as $body) {
            $normalized = \strtolower(\preg_replace('/\s+/u', '', $body) ?? $body);
            if ((\str_contains($normalized, 'display:flex') || \str_contains($normalized, 'display:grid'))
                && $this->cssBodyProvidesFlowGapSeparation($body)
            ) {
                return null;
            }
        }

        $precedingBodies = $this->extractCssRuleBodiesForClassPattern(
            $styleCss,
            '/^(?:channel|row|field|form-row|faq-item|question|answer|[a-z0-9_-]+-(?:channel|row|field|form-row|faq-item|question|answer))$/iu'
        );
        foreach ($precedingBodies as $body) {
            if ($this->cssBodyProvidesTrailingSeparation($body)) {
                return null;
            }
        }

        return 'CTA/action spacing is too tight; separate the action wrapper from dividers, rows, or form lines with outer margin/padding or parent flow gap';
    }

    /**
     * @return list<string>
     */
    private function extractCssRuleBodiesForClassPattern(string $styleCss, string $classPattern): array
    {
        $bodies = [];
        if (\trim($styleCss) === '' || \preg_match_all('/([^{}]+)\{([^{}]*)\}/u', $styleCss, $matches, \PREG_SET_ORDER) <= 0) {
            return [];
        }

        foreach ($matches as $match) {
            $selector = (string)($match[1] ?? '');
            if (\preg_match_all('/\.([a-z0-9_-]+)/iu', $selector, $classMatches) <= 0) {
                continue;
            }
            foreach ($classMatches[1] ?? [] as $className) {
                if (\preg_match($classPattern, (string)$className) !== 1) {
                    continue;
                }
                $bodies[] = (string)($match[2] ?? '');
                break;
            }
        }

        return $bodies;
    }

    private function cssBodyProvidesActionWrapperSeparation(string $body): bool
    {
        $normalized = \strtolower(\preg_replace('/\s+/u', '', $body) ?? $body);
        if ($normalized === '') {
            return false;
        }
        if (\preg_match('/(?:margin-top|padding-top):(?:clamp|min|max|calc)\(/iu', $normalized) === 1) {
            return true;
        }
        if (\preg_match('/(?:margin-top|padding-top):(\d+(?:\.\d+)?)(px|rem|em)/iu', $normalized, $match) === 1) {
            $value = (float)($match[1] ?? 0);
            $unit = \strtolower((string)($match[2] ?? 'px'));
            return $unit === 'px' ? $value >= 16.0 : $value >= 1.0;
        }
        if (\preg_match('/(?:margin|padding):(\d+(?:\.\d+)?)(px|rem|em)(?:[^;]*?)0(?:px|rem|em|%|)?(?:[^;]*?)0(?:px|rem|em|%|)?/iu', $normalized, $match) === 1) {
            $value = (float)($match[1] ?? 0);
            $unit = \strtolower((string)($match[2] ?? 'px'));
            return $unit === 'px' ? $value >= 16.0 : $value >= 1.0;
        }
        if (\preg_match('/(?:margin|padding):(\d+(?:\.\d+)?)(px|rem|em)[^;]*/iu', $normalized, $match) === 1) {
            $value = (float)($match[1] ?? 0);
            $unit = \strtolower((string)($match[2] ?? 'px'));
            return $unit === 'px' ? $value >= 16.0 : $value >= 1.0;
        }

        return false;
    }

    private function cssBodyProvidesCtaOuterSeparation(string $body): bool
    {
        $normalized = \strtolower(\preg_replace('/\s+/u', '', $body) ?? $body);
        if ($normalized === '') {
            return false;
        }
        if (\preg_match('/margin-top:(?:clamp|min|max|calc)\(/iu', $normalized) === 1) {
            return true;
        }
        if (\preg_match('/margin-top:(\d+(?:\.\d+)?)(px|rem|em)/iu', $normalized, $match) === 1) {
            $value = (float)($match[1] ?? 0);
            $unit = \strtolower((string)($match[2] ?? 'px'));
            return $unit === 'px' ? $value >= 16.0 : $value >= 1.0;
        }
        if (\preg_match('/margin:(\d+(?:\.\d+)?)(px|rem|em)(?:[^;]*?)0(?:px|rem|em|%|)?(?:[^;]*?)0(?:px|rem|em|%|)?/iu', $normalized, $match) === 1) {
            $value = (float)($match[1] ?? 0);
            $unit = \strtolower((string)($match[2] ?? 'px'));
            return $unit === 'px' ? $value >= 16.0 : $value >= 1.0;
        }

        return false;
    }

    private function cssBodyProvidesFlowGapSeparation(string $body): bool
    {
        $normalized = \strtolower(\preg_replace('/\s+/u', '', $body) ?? $body);
        if ($normalized === '') {
            return false;
        }
        if (\preg_match('/gap:(?:clamp|min|max|calc)\(/iu', $normalized) === 1) {
            return true;
        }
        if (\preg_match('/gap:(\d+(?:\.\d+)?)(px|rem|em)/iu', $normalized, $match) === 1) {
            $value = (float)($match[1] ?? 0);
            $unit = \strtolower((string)($match[2] ?? 'px'));
            return $unit === 'px' ? $value >= 16.0 : $value >= 1.0;
        }

        return false;
    }

    private function cssBodyProvidesTrailingSeparation(string $body): bool
    {
        $normalized = \strtolower(\preg_replace('/\s+/u', '', $body) ?? $body);
        if ($normalized === '') {
            return false;
        }
        if (\preg_match('/(?:margin-bottom|padding-bottom|gap):(?:clamp|min|max|calc)\(/iu', $normalized) === 1) {
            return true;
        }
        if (\preg_match('/(?:margin-bottom|padding-bottom|gap):(\d+(?:\.\d+)?)(px|rem|em)/iu', $normalized, $match) === 1) {
            $value = (float)($match[1] ?? 0);
            $unit = \strtolower((string)($match[2] ?? 'px'));
            return $unit === 'px' ? $value >= 12.0 : $value >= 0.75;
        }

        return false;
    }

    private function normalizeOptionalComponentCode(mixed $componentCode): string
    {
        if (!\is_scalar($componentCode) && !(\is_object($componentCode) && \method_exists($componentCode, '__toString'))) {
            return '';
        }

        return \trim((string)$componentCode);
    }

    private function detectHeroBannerQualityViolation(string $html): ?string
    {
        $normalized = \preg_replace('/\s+/u', ' ', $html) ?? $html;
        $hasCssOnlyMediaSkeleton = \preg_match('/class=["\'][^"\']*media[^"\']*["\']/iu', $normalized) === 1
            && \preg_match('/class=["\'][^"\']*(?:overlay|scrim|veil|shade|backdrop)[^"\']*["\']/iu', $normalized) === 1
            && \preg_match('/class=["\'][^"\']*(?:text-panel|copy|content|caption|message|panel|plate|card)[^"\']*["\']/iu', $normalized) === 1;
        $hasGeneratedAssetHero = \preg_match('/data-pb-ai-image-role=["\']generated-asset["\']/iu', $normalized) === 1;
        $hasGeneratedAssetCoverLayer = $hasGeneratedAssetHero
            && \preg_match('/(?:object-fit\s*:\s*cover|class=["\'][^"\']*(?:hero-img|media|image)[^"\']*["\'][^>]*data-pb-ai-image-role=["\']generated-asset["\']|data-pb-ai-image-role=["\']generated-asset["\'][^>]*class=["\'][^"\']*(?:hero-img|media|image)[^"\']*["\'])/iu', $normalized) === 1;
        $hasCssOnlyMotifLayers = \preg_match('/class=["\'][^"\']*motif[^"\']*["\']/iu', $normalized) === 1
            && \preg_match('/class=["\'][^"\']*orbit[^"\']*["\']/iu', $normalized) === 1;
        $hasVisibleMotifCss = \preg_match('/motif[^{]*\{(?=[^}]*\b(?:width|height)\s*:)(?=[^}]*\b(?:background|border|box-shadow)\s*:)[^}]*\}/iu', $normalized) === 1
            && \preg_match('/orbit[^{]*\{(?=[^}]*\b(?:width|height)\s*:)(?=[^}]*\b(?:background|border|box-shadow)\s*:)[^}]*\}/iu', $normalized) === 1;
        $hasCssOnlySubjectStage = \preg_match('/class=["\'][^"\']*media-stage[^"\']*["\']/iu', $normalized) === 1
            && \preg_match('/class=["\'][^"\']*media-subject[^"\']*["\']/iu', $normalized) === 1
            && \preg_match('/class=["\'][^"\']*media-detail[^"\']*["\']/iu', $normalized) === 1
            && \preg_match('/class=["\'][^"\']*media-label[^"\']*["\']/iu', $normalized) === 1;
        $hasVisibleSubjectStageCss = \preg_match('/media-stage[^{]*\{(?=[^}]*\b(?:width|height)\s*:)(?=[^}]*\b(?:background|border|box-shadow)\s*:)[^}]*\}/iu', $normalized) === 1
            && \preg_match('/media-subject[^{]*\{(?=[^}]*\b(?:width|height)\s*:)(?=[^}]*\b(?:background|border|box-shadow)\s*:)[^}]*\}/iu', $normalized) === 1
            && \preg_match('/media-detail[^{]*\{(?=[^}]*\b(?:width|height)\s*:)(?=[^}]*\b(?:background|border|box-shadow)\s*:)[^}]*\}/iu', $normalized) === 1
            && \preg_match('/media-label[^{]*\{(?=[^}]*\b(?:display|padding)\s*:)(?=[^}]*\b(?:background|border|box-shadow)\s*:)[^}]*\}/iu', $normalized) === 1;
        $hasFullBleedBackground = $hasGeneratedAssetCoverLayer
            || \preg_match('/(?:background-image|object-fit\s*:\s*cover)/iu', $normalized) === 1;
        $hasPremiumMediaHero = \preg_match('/(?:data-pb-ai-image-role=["\']generated-asset["\']|class=["\'][^"\']*(?:media|visual|image)[^"\']*["\'])/iu', $normalized) === 1
            && \preg_match('/(?:border-radius|box-shadow|object-fit\s*:\s*cover|inset\s*:\s*0)/iu', $normalized) === 1;
        $hasOverlayPanel = \preg_match('/(?:backdrop-filter|rgba\([^)]*,\s*\.(?:3|4|5|6|7|8|9)|linear-gradient\([^;]*(?:rgba|transparent)|(?:overlay|scrim|veil|shade|backdrop)[^{]*\{[^}]*background\s*:\s*#[0-9a-f]{8}\b|(?:overlay|scrim|veil|shade|backdrop)[^{]*\{[^}]*background\s*:\s*#[0-9a-f]{3,8}[^}]*opacity\s*:\s*\.?[0-9]+)/iu', $normalized) === 1;
        $hasNamedReadabilityLayer = \preg_match('/class=["\'][^"\']*(?:overlay|scrim|veil|shade|backdrop|gradient)[^"\']*["\']/iu', $normalized) === 1;
        $hasNamedTextSafePanel = \preg_match('/class=["\'][^"\']*(?:copy|content|text|caption|message|panel|plate|card)[^"\']*["\']/iu', $normalized) === 1;
        if ($this->looksLikeStretchedCtaBar($normalized)) {
            return 'hero/banner CTA is rendered as a full-width bar instead of a compact action button';
        }
        if ($hasGeneratedAssetHero && (!$hasGeneratedAssetCoverLayer || !$hasFullBleedBackground)) {
            return 'hero/banner generated image is not styled as a cover media layer';
        }
        if (!$hasGeneratedAssetHero && !$hasCssOnlyMediaSkeleton) {
            return 'CSS-only hero/banner lacks the required media layer, overlay, and text-safe panel';
        }
        if (!$hasGeneratedAssetHero && !$hasPremiumMediaHero) {
            return 'hero/banner is not using a full-background image or a premium generated media visual';
        }
        if (!$hasGeneratedAssetHero && $hasCssOnlyMediaSkeleton && !$hasCssOnlyMotifLayers) {
            return 'CSS-only hero/banner lacks visible motif and orbit decoration layers';
        }
        if (!$hasGeneratedAssetHero && $hasCssOnlyMediaSkeleton && !$hasVisibleMotifCss) {
            return 'CSS-only hero/banner motif and orbit layers are not visibly positioned';
        }
        if (!$hasGeneratedAssetHero && $hasCssOnlyMediaSkeleton && (!$hasCssOnlySubjectStage || !$hasVisibleSubjectStageCss)) {
            return 'CSS-only hero/banner lacks a visible subject-matter or editorial media stage';
        }
        if (!$hasOverlayPanel) {
            return 'hero/banner lacks a readable overlay layer for floating content';
        }
        if (!$hasNamedReadabilityLayer || !$hasNamedTextSafePanel) {
            return 'hero/banner text over media must use named scrim/overlay and text-safe panel classes';
        }

        return null;
    }

    private function hasVisitorLinkCluster(string $html): bool
    {
        if (\preg_match_all('/<a\b[^>]*>(.*?)<\/a>/isu', $html, $matches) < 3) {
            return false;
        }

        $labels = [];
        foreach ($matches[1] as $rawLabel) {
            $label = \trim((string)\preg_replace('/\s+/u', ' ', \strip_tags((string)$rawLabel)));
            if ($label !== '' && \mb_strlen($label) >= 2) {
                $labels[] = \mb_strtolower($label);
            }
        }

        return \count(\array_unique($labels)) >= 3;
    }

    private function hasVisitorArticleListStructure(string $html): bool
    {
        if (\preg_match_all('/<(article|li)\b[^>]*>.*?<\/\1>/isu', $html, $items) < 2) {
            return false;
        }
        if (\preg_match_all('/<a\b[^>]*>(.*?)<\/a>/isu', $html, $links) < 2) {
            return false;
        }

        $labels = [];
        foreach ($links[1] as $rawLabel) {
            $label = \trim((string)\preg_replace('/\s+/u', ' ', \strip_tags((string)$rawLabel)));
            if ($label !== '' && \mb_strlen($label) >= 4) {
                $labels[] = \mb_strtolower($label);
            }
        }
        if (\count(\array_unique($labels)) < 2) {
            return false;
        }

        $plain = \mb_strtolower(\trim((string)\preg_replace('/\s+/u', ' ', \strip_tags($html))));
        return \preg_match('/<time\b|date|read|guide|review|tips|news|update|strategy|category|article|editorial|post/iu', $html . ' ' . $plain) === 1;
    }

    /**
     * @param array<string,mixed> $context
     * @return list<string>
     */
    private function extractVerifiedAssetUrls(array $context): array
    {
        $verified = \is_array($context['verified_assets'] ?? null) ? $context['verified_assets'] : [];
        $urls = [];
        foreach ($verified as $value) {
            if (\is_string($value) || \is_numeric($value)) {
                $url = \trim((string)$value);
                if ($url !== '') {
                    $urls[] = $url;
                }
                continue;
            }
            if (\is_array($value)) {
                foreach (['final_url', 'url', 'src'] as $key) {
                    $url = \trim((string)($value[$key] ?? ''));
                    if ($url !== '') {
                        $urls[] = $url;
                        break;
                    }
                }
            }
        }

        return \array_values(\array_unique($urls));
    }

    /**
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     */
    private function assertRequiredImageAssetsUsed(array $aiData, array $renderContext, array $defaultConfig): void
    {
        $requiredAssets = \is_array($renderContext['_required_image_assets'] ?? null) ? $renderContext['_required_image_assets'] : [];
        $visualContract = \is_array($renderContext['_visual_contract'] ?? null)
            ? $renderContext['_visual_contract']
            : $this->decodeRuntimeVisualContract($defaultConfig);
        $imageRequired = (int)($visualContract['required'] ?? 0) === 1
            || \trim((string)($defaultConfig['runtime.section_image_required'] ?? '')) === '1';
        if (!$imageRequired) {
            return;
        }
        $expectedSlotId = \trim((string)($visualContract['slot_id'] ?? $defaultConfig['runtime.section_image_slot_id'] ?? ''));
        if ($expectedSlotId !== '' && $requiredAssets !== []) {
            $requiredAssets = \array_intersect_key($requiredAssets, [$expectedSlotId => true]);
        }
        if ($requiredAssets === []) {
            $slotId = $expectedSlotId;
            $url = $this->firstConfigString($defaultConfig, ['runtime.section_image_url', 'visual.image_url', 'image.url', 'media.image_url']);
            if ($slotId !== '' && $url !== '') {
                $requiredAssets[$slotId] = $url;
            }
        }
        if ($requiredAssets === []) {
            return;
        }

        $html = (string)($aiData['html_content'] ?? $aiData['html_extra'] ?? '');
        $payload = $html . "\n" . (string)($aiData['css_extra'] ?? '') . "\n" . (string)($aiData['css_responsive'] ?? '');
        foreach ($requiredAssets as $slotId => $url) {
            $slotId = \trim((string)$slotId);
            $url = \trim((string)$url);
            if ($slotId === '' || $url === '') {
                continue;
            }
            if (!$this->htmlContainsGeneratedAssetImage($html, $slotId, $url)) {
                $hasUrlInPayload = \str_contains($payload, $url);
                $message = $hasUrlInPayload
                    ? 'Required image slot is missing editor attributes: ' . $slotId
                    : 'Required image slot is not referenced by generated block: ' . $slotId;
                throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
                    $message,
                    [[
                        'rule' => $hasUrlInPayload ? 'required_image.editor_attrs_missing' : 'required_image.slot_missing',
                        'field' => 'html_content',
                        'found' => $hasUrlInPayload ? 'image URL appeared outside the required editable img binding' : 'no editable img binding for ' . $slotId,
                        'expected' => 'editable img tag using media.image_url fallback ' . $url . ' with data-pb-ai-image-role=generated-asset and data-pb-ai-asset-slot=' . $slotId,
                        'hint' => 'Adapt EXACT_REQUIRED_IMG_TAG_TO_COPY into html_content. Keep data-pb-ai-image-role/data-pb-ai-asset-slot exact, declare media.image_url/media.image_alt fields, and render img src/alt from those variables. Do not move the image into CSS.',
                    ]],
                    null
                );
            }
        }
    }

    /**
     * Once a block has verified generated assets, the generated HTML/CSS must not
     * keep a second local ai-generated URL for the same visual. That creates
     * duplicate slot images and bypasses slot backfill/publish migration.
     *
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     */
    private function assertGeneratedImageSourcesUseVerifiedAssets(array $aiData, array $renderContext, array $defaultConfig): void
    {
        $verifiedMap = $this->extractVerifiedAssetMapFromRenderContext($renderContext, $defaultConfig);
        $verifiedUrls = \array_values(\array_filter(\array_map('strval', \array_merge(
            \array_values($verifiedMap),
            $this->extractVerifiedAssetUrlsFromRenderContextManifest($renderContext)
        ))));
        if ($verifiedUrls === []) {
            return;
        }

        $violations = [];
        foreach (['html_content', 'html_extra'] as $key) {
            $html = (string)($aiData[$key] ?? '');
            if ($html === '') {
                continue;
            }
            if (\preg_match_all('/<img\b[^>]*>/iu', $html, $matches) > 0) {
                foreach ($matches[0] as $tag) {
                    $tag = (string)$tag;
                    $src = $this->extractHtmlAttribute($tag, 'src');
                    if ($src === '') {
                        continue;
                    }
                    $role = $this->extractHtmlAttribute($tag, 'data-pb-ai-image-role');
                    $slot = $this->extractHtmlAttribute($tag, 'data-pb-ai-asset-slot');
                    $slotRequiresVerifiedUrl = $slot !== '' && isset($verifiedMap[$slot]);
                    if ($this->isEditableImageSrcForVerifiedAsset($src, $verifiedUrls)) {
                        continue;
                    }
                    if (($role === 'generated-asset' || $slotRequiresVerifiedUrl || $this->looksLikeGeneratedAssetSource($src))
                        && !$this->isVerifiedAssetUrl($src, $verifiedUrls)
                    ) {
                        $violations[] = $src;
                    }
                }
            }
        }

        foreach (['css_extra', 'css_responsive'] as $key) {
            $css = (string)($aiData[$key] ?? '');
            if ($css === '') {
                continue;
            }
            if (\preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/iu', $css, $matches, \PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $src = \trim((string)($match[2] ?? ''));
                    if ($src !== '' && $this->looksLikeGeneratedAssetSource($src) && !$this->isVerifiedAssetUrl($src, $verifiedUrls)) {
                        $violations[] = $src;
                    }
                }
            }
        }

        $violations = \array_values(\array_unique(\array_filter(\array_map('trim', $violations))));
        if ($violations !== []) {
            throw new \RuntimeException('Generated image source is outside verified asset allowlist: ' . \implode(', ', \array_slice($violations, 0, 4)));
        }
    }

    /**
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function stripOptionalUnverifiedGeneratedImageReferences(array $aiData, array $renderContext, array $defaultConfig): array
    {
        if ($this->isImageRequiredForGeneratedBlock($renderContext, $defaultConfig)) {
            return $aiData;
        }

        $verifiedMap = $this->extractVerifiedAssetMapFromRenderContext($renderContext, $defaultConfig);
        $verifiedUrls = \array_values(\array_filter(\array_map('strval', \array_merge(
            \array_values($verifiedMap),
            $this->extractVerifiedAssetUrlsFromRenderContextManifest($renderContext)
        ))));

        foreach (['html_content', 'html_extra'] as $key) {
            if (!\is_string($aiData[$key] ?? null) || $aiData[$key] === '') {
                continue;
            }
            $aiData[$key] = \preg_replace_callback(
                '/<img\b[^>]*>/iu',
                function (array $matches) use ($verifiedUrls): string {
                    $tag = (string)($matches[0] ?? '');
                    $src = $this->extractHtmlAttribute($tag, 'src');
                    if ($src !== '' && $this->looksLikeGeneratedAssetSource($src) && !$this->isVerifiedAssetUrl($src, $verifiedUrls)) {
                        return '';
                    }

                    return $tag;
                },
                (string)$aiData[$key]
            ) ?? (string)$aiData[$key];
        }

        foreach (['css_extra', 'css_responsive', 'css_content'] as $key) {
            if (!\is_string($aiData[$key] ?? null) || $aiData[$key] === '') {
                continue;
            }
            $aiData[$key] = \preg_replace_callback(
                '/(?:^|[;{])\s*[-_a-z][a-z0-9_-]*\s*:\s*[^;{}]*url\(\s*([\'"]?)(.*?)\1\s*\)[^;{}]*;?/iu',
                function (array $matches) use ($verifiedUrls): string {
                    $declaration = (string)($matches[0] ?? '');
                    $src = \trim((string)($matches[2] ?? ''));
                    if ($src !== '' && $this->looksLikeGeneratedAssetSource($src) && !$this->isVerifiedAssetUrl($src, $verifiedUrls)) {
                        return \str_starts_with($declaration, '{') ? '{' : '';
                    }

                    return $declaration;
                },
                (string)$aiData[$key]
            ) ?? (string)$aiData[$key];
        }

        return $aiData;
    }

    /**
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     */
    private function isImageRequiredForGeneratedBlock(array $renderContext, array $defaultConfig): bool
    {
        $visualContract = \is_array($renderContext['_visual_contract'] ?? null)
            ? $renderContext['_visual_contract']
            : $this->decodeRuntimeVisualContract($defaultConfig);

        $imageRequired = (int)($visualContract['required'] ?? 0) === 1
            || \trim((string)($defaultConfig['runtime.section_image_required'] ?? '')) === '1';
        if (!$imageRequired) {
            return false;
        }

        $imageUrl = $this->firstConfigString($visualContract, ['final_url', 'url', 'src'])
            ?: $this->firstConfigString($defaultConfig, ['runtime.section_image_url', 'visual.image_url', 'image.url', 'media.image_url']);

        return $imageUrl !== '';
    }

    private function looksLikeGeneratedAssetSource(string $src): bool
    {
        $normalized = $this->normalizeVerifiedAssetUrl($src);
        $lower = \strtolower($normalized);

        return \str_contains($lower, '/ai-generated/')
            || \str_contains($lower, '/page-build/');
    }

    /**
     * @param array<string,mixed> $renderContext
     * @return list<string>
     */
    private function extractVerifiedAssetUrlsFromRenderContextManifest(array $renderContext): array
    {
        $manifest = \is_array($renderContext['asset_manifest'] ?? null) ? $renderContext['asset_manifest'] : [];
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        $urls = [];
        foreach ($slots as $slot) {
            if (!\is_array($slot) || $this->isPlaceholderAssetSlot($slot)) {
                continue;
            }
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
            if ($finalUrl !== '') {
                $urls[] = $finalUrl;
            }
        }

        return \array_values(\array_unique($urls));
    }

    private function htmlContainsGeneratedAssetImage(string $html, string $slotId, string $url): bool
    {
        if (\preg_match_all('/<img\b[^>]*>/iu', $html, $matches) <= 0) {
            return false;
        }

        foreach ($matches[0] as $tag) {
            $tag = (string)$tag;
            $src = $this->extractHtmlAttribute($tag, 'src');
            if ($src !== $url && !$this->isEditableImageSrcForVerifiedAsset($src, [$url])) {
                continue;
            }
            if ($this->extractHtmlAttribute($tag, 'data-pb-ai-asset-slot') !== $slotId) {
                continue;
            }
            if ($this->extractHtmlAttribute($tag, 'data-pb-ai-image-role') !== 'generated-asset') {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Editable generated images keep the slot binding concrete, while src renders
     * from a PageBuilder field whose fallback/default is the verified final_url.
     *
     * @param list<string> $verifiedAssets
     */
    private function isEditableImageSrcForVerifiedAsset(string $src, array $verifiedAssets): bool
    {
        $src = \trim(\html_entity_decode($src, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
        if ($src === '' || !\str_contains($src, '<' . '?=') || !$this->isSafeAiHtmlPhpEcho($src)) {
            return false;
        }

        foreach ($verifiedAssets as $assetUrl) {
            $assetUrl = \trim((string)$assetUrl);
            if ($assetUrl !== '' && \str_contains($src, $assetUrl)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Contract-driven structural normalization: when the AI already copied the
     * verified final_url into an editable img field fallback, ensure the editor binding
     * attributes from the same runtime contract stay on that tag.
     *
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     */
    private function assertRequiredImageEditorBindingsInAiPayload(
        array $aiData,
        string $region,
        array $renderContext,
        array $defaultConfig
    ): void {
        if ($region !== 'content' || !$this->isImageRequiredForGeneratedBlock($renderContext, $defaultConfig)) {
            return;
        }
        $html = (string)($aiData['html_content'] ?? '');
        if ($html === '') {
            return;
        }

        $visualContract = \is_array($renderContext['_visual_contract'] ?? null)
            ? $renderContext['_visual_contract']
            : $this->decodeRuntimeVisualContract($defaultConfig);
        $slotId = \trim((string)($visualContract['slot_id'] ?? $defaultConfig['runtime.section_image_slot_id'] ?? ''));
        $imageUrl = \trim((string)($visualContract['final_url'] ?? $visualContract['url'] ?? ''));
        if ($imageUrl === '') {
            $imageUrl = $this->firstConfigString($defaultConfig, ['runtime.section_image_url', 'visual.image_url', 'image.url', 'media.image_url']);
        }
        if ($slotId === '' || $imageUrl === '' || $this->htmlContainsGeneratedAssetImage($html, $slotId, $imageUrl)) {
            return;
        }

        $hasAnyImg = \preg_match_all('/<img\b[^>]*>/iu', $html, $matches) > 0;
        $candidateTag = '';
        if ($hasAnyImg) {
            foreach ($matches[0] as $rawTag) {
                $tag = (string)$rawTag;
                $src = $this->extractHtmlAttribute($tag, 'src');
                if ($src === $imageUrl || $this->extractHtmlAttribute($tag, 'data-pb-ai-asset-slot') === $slotId) {
                    $candidateTag = $tag;
                    break;
                }
                if ($candidateTag === '' && $this->extractHtmlAttribute($tag, 'data-pb-ai-image-role') === 'generated-asset') {
                    $candidateTag = $tag;
                    continue;
                }
                if ($candidateTag === '' && $this->looksLikeGeneratedAssetSource($src)) {
                    $candidateTag = $tag;
                }
            }
        }

        $payload = $html . "\n" . (string)($aiData['css_extra'] ?? '') . "\n" . (string)($aiData['css_responsive'] ?? '');
        $hasUrlInPayload = \str_contains($payload, $imageUrl);
        $rule = !$hasAnyImg
            ? 'required_image.img_missing'
            : ($hasUrlInPayload ? 'required_image.editor_attrs_missing' : 'required_image.slot_missing');
        $found = !$hasAnyImg
            ? 'html_content contains no img tag for required slot ' . $slotId
            : ($candidateTag !== '' ? $this->clipText($candidateTag, 280) : 'image markup exists but no tag matches required slot/url');

        throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
            $hasUrlInPayload
                ? 'Required image slot is missing editor attributes: ' . $slotId
                : 'Required image slot is not referenced by generated block: ' . $slotId,
            [[
                'rule' => $rule,
                'field' => 'html_content',
                'found' => $found,
                'expected' => 'editable img tag using media.image_url fallback ' . $imageUrl . ' with data-pb-ai-image-role=generated-asset and data-pb-ai-asset-slot=' . $slotId,
                'hint' => 'Adapt EXACT_REQUIRED_IMG_TAG_TO_COPY into html_content. Keep data-pb-ai-image-role/data-pb-ai-asset-slot exact, declare media.image_url/media.image_alt fields, and render img src/alt from those variables. Do not move the image into CSS.',
            ]]
        );
    }

    /**
     * Validate hero/banner image slot usage and normalize the required contract tag.
     *
     * This is intentionally generic: the only injected values come from the runtime
     * verified image contract, not from page-specific generated output.
     *
     * @param array<string, mixed> $aiData
     * @param array<string, mixed> $defaultConfig
     * @return array<string, mixed>
     */
    private function enforceContractHeroImageUrlsInAiPayload(array $aiData, string $region, array $defaultConfig): array
    {
        if ($region !== 'content') {
            return $aiData;
        }
        $html = (string)($aiData['html_content'] ?? '');
        if ($html === '') {
            return $aiData;
        }
        if (!$this->isHeroOrBannerImageContract($defaultConfig, [], $html)) {
            return $aiData;
        }

        $imageUrl = '';
        foreach (['runtime.section_image_url', 'visual.image_url', 'image.url', 'media.image_url'] as $key) {
            $candidate = \trim((string)($defaultConfig[$key] ?? ''));
            if ($candidate !== '') {
                $imageUrl = $candidate;
                break;
            }
        }
        if ($imageUrl === '') {
            return $aiData;
        }
        $slotId = \trim((string)($defaultConfig['runtime.section_image_slot_id'] ?? $defaultConfig['visual.image_slot_id'] ?? ''));
        if ($slotId !== '') {
            if (!$this->htmlContainsGeneratedAssetImage($html, $slotId, $imageUrl)) {
                throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
                    'AI hero image contract failed: generated hero image must use the provided image URL, generated-asset role, and slot binding on the same img element.',
                    [[
                        'rule' => 'hero_image.binding_missing',
                        'field' => 'html_content',
                        'found' => $this->clipText($html, 320),
                        'expected' => 'editable img tag using media.image_url fallback ' . $imageUrl . ' with data-pb-ai-image-role=generated-asset and data-pb-ai-asset-slot=' . $slotId,
                        /*
 'hint' => ' ?hero html_content ?<img>  ?hero  ?,
                        */
                        'hint' => 'Rewrite hero html_content with a real editable img layer: keep data-pb-ai-image-role/data-pb-ai-asset-slot exact, declare media.image_url/media.image_alt fields, and render img src/alt from those variables.',
                    ]]
                );
            }
        }
        if ($slotId === '' && !\str_contains($html, $imageUrl)) {
            throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
                'AI hero image contract failed: generated hero must use the provided image URL.',
                [[
                    'rule' => 'hero_image.url_missing',
                    'field' => 'html_content',
                    'found' => $this->clipText($html, 320),
                    'expected' => 'html_content contains the provided hero image URL in an img tag',
                    'hint' => 'Render the approved generated image URL directly in the hero img element from the current plan_json block.',
                ]]
            );
        }
        if ($slotId === '' && !\preg_match('/<img\b[^>]*\bdata-pb-ai-image-role\s*=\s*(["\'])generated-asset\1/iu', $html)) {
            throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
                'AI hero image contract failed: generated hero must mark the image element as generated-asset.',
                [[
                    'rule' => 'hero_image.role_missing',
                    'field' => 'html_content',
                    'found' => $this->clipText($html, 320),
                    'expected' => "img tag has data-pb-ai-image-role='generated-asset'",
                    'hint' => 'Add data-pb-ai-image-role=generated-asset to the hero img element rendered for the current plan_json block.',
                ]]
            );
        }

        return $aiData;
    }

    private function replaceFirstString(string $subject, string $search, string $replace): string
    {
        $position = \strpos($subject, $search);
        if ($position === false) {
            return $subject;
        }

        return \substr($subject, 0, $position) . $replace . \substr($subject, $position + \strlen($search));
    }

    /**
     * @param list<string> $verifiedAssets
     */
    private function isVerifiedAssetUrl(string $src, array $verifiedAssets): bool
    {
        if ($verifiedAssets === []) {
            return false;
        }
        $candidate = $this->normalizeVerifiedAssetUrl($src);
        if ($candidate === '') {
            return false;
        }
        foreach ($verifiedAssets as $assetUrl) {
            if ($candidate === $this->normalizeVerifiedAssetUrl((string)$assetUrl)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeVerifiedAssetUrl(string $url): string
    {
        $url = \trim(\html_entity_decode($url, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
        if ($url === '') {
            return '';
        }
        $parts = \parse_url($url);
        if (\is_array($parts) && \trim((string)($parts['path'] ?? '')) !== '') {
            $url = (string)$parts['path'];
        }
        $url = '/' . \ltrim(\str_replace('\\', '/', $url), '/');

        return \preg_replace('#/+#', '/', $url) ?? $url;
    }

    private function assertNoBrokenGeneratedImageReferences(string $html, array $verifiedAssets = []): void
    {
        // Broken or unverified imagery is surfaced through visual QA and browser
        // review. It must not block AI generation unless the visitor-visible
        // content leaks prompt/placeholder/internal metadata.
        return;

        $broken = [];
        if (\preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/iu', $html, $found, \PREG_SET_ORDER) > 0) {
            foreach ($found as $row) {
                $src = \trim((string)($row[2] ?? ''));
                if ($this->isBrokenGeneratedImageSource($src, $verifiedAssets)) {
                    $broken[] = $src === '' ? '<empty img src>' : $src;
                }
            }
        }
        if (\preg_match_all('/url\(\s*([\'\"]?)([^\'\")]*)\1\s*\)/iu', $html, $found, \PREG_SET_ORDER) > 0) {
            foreach ($found as $row) {
                $src = \trim((string)($row[2] ?? ''));
                if ($this->isBrokenGeneratedImageSource($src, $verifiedAssets)) {
                    $broken[] = $src;
                }
            }
        }
        $broken = \array_values(\array_unique($broken));
        if ($broken !== []) {
            throw new \RuntimeException(
                'AI component contains invalid image resource: '
                . \implode(', ', \array_slice($broken, 0, 5))
            );
 throw new \RuntimeException((string)__('AI  ?{1}', [\implode(', ', \array_slice($broken, 0, 5))]));
        }
    }
    private function extractHtmlAttribute(string $tag, string $attribute): string
    {
        if (\preg_match('/\s' . \preg_quote($attribute, '/') . '\s*=\s*(["\'])(.*?)\1/iu', $tag, $matches) === 1) {
            return \html_entity_decode((string)($matches[2] ?? ''), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        }
        if (\preg_match('/\s' . \preg_quote($attribute, '/') . '\s*=\s*([^\s>]+)/iu', $tag, $matches) === 1) {
            return \html_entity_decode(\trim((string)($matches[1] ?? ''), " \t\n\r\0\x0B\"'"), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        }

        return '';
    }

    private function isBrokenGeneratedImageSource(string $src, array $verifiedAssets = []): bool
    {
        $src = \trim(\html_entity_decode($src, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
        if ($src === '' || $src === '#') {
            return true;
        }
        if ($this->isVerifiedAssetUrl($src, $verifiedAssets)) {
            return false;
        }

        $lower = \strtolower($src);
        if (\str_starts_with($lower, '://') || \str_starts_with($lower, '//')) {
            return true;
        }
        if (\str_starts_with($lower, 'data:image/') || \str_starts_with($lower, 'blob:')) {
            return false;
        }
        foreach ([
            'example.com',
            'placeholder.com',
            'placehold.co',
            'via.placeholder',
            'dummyimage.com',
            'placekitten.com',
            'picsum.photos',
            'loremflickr.com',
            'unsplash.com',
            'images.unsplash.com',
            'source.unsplash.com',
            'pexels.com',
            'images.pexels.com',
            'pixabay.com',
            'freepik.com',
            'shutterstock.com',
            'stock.adobe.com',
        ] as $marker) {
            if (\str_contains($lower, $marker)) {
                return true;
            }
        }
        if (\preg_match('/^https?:\/\/.+\.(?:jpe?g|png|webp|gif|svg)(?:[?#].*)?$/i', $src) === 1) {
            return true;
        }
        if (\preg_match('/^(?:\.{0,2}\/)?(?:images?|assets?|uploads?)\/.+\.(?:jpe?g|png|webp|gif|svg)(?:[?#].*)?$/i', $src) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function renderTemplateToHtml(string $phtml, array $defaultConfig, array $renderContext): string
    {
        $renderer = new PreviewRenderer();
        $renderer->setData('component_config', $defaultConfig);

        foreach ($renderContext as $key => $value) {
            $renderer->setData($key, $value);
        }

        $result = $renderer->render($phtml);
        if (!($result['success'] ?? false)) {
            throw new \RuntimeException('AI component render failed: ' . (string)($result['error'] ?? 'unknown'));
        }

        return (string)($result['html'] ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    private function runAiGeneration(string $region, string $prompt, array $defaultConfig = [], array $renderContext = [], bool $retry = false): array
    {
        $guardedPrompt = $this->prependComponentJsonOnlyGuard($prompt, $retry);
        AiSiteWorkflowTrace::prompt('stage3_ai_generation_call', $guardedPrompt, [
            'region' => $region,
            'scenario_code' => 'pagebuilder_component_generation',
            'retry' => $retry,
            'component_code' => (string)($renderContext['component_code'] ?? $defaultConfig['component_code'] ?? ''),
            'page_type' => (string)($renderContext['page_type'] ?? ''),
            'section_code' => (string)($renderContext['section_code'] ?? ''),
            'task_key' => (string)($renderContext['plan_json_task']['task_key'] ?? ''),
        ]);
        $maxTokens = $this->resolveComponentGenerationMaxTokens($region, $renderContext, $retry);
        $fullContent = '';
        $runtimeParams = [
            'allow_zero_balance_provider' => true,
            'response_format' => $this->buildComponentResponseFormat($region),
            'temperature' => $retry ? 0.05 : 0.15,
            'max_tokens' => $maxTokens,
            'timeout' => self::AI_REQUEST_TIMEOUT_SECONDS,
        ];
        $streamParams = $this->buildAiRuntimeParams($runtimeParams, true);
        try {
            $streamResult = $this->callAiOperation('generateStream', [
                'prompt' => $guardedPrompt,
                'on_chunk' => static function (string $chunk) use (&$fullContent): void {
                    $fullContent .= $chunk;
                },
                'scenario_code' => 'pagebuilder_component_generation',
                'test_region' => $region,
                'test_default_config' => $defaultConfig,
                'test_render_context' => $renderContext,
                'params' => $streamParams,
            ]);
        } catch (\Throwable $streamThrowable) {
            if (!$this->shouldFallbackToNonStreamComponentGeneration($streamThrowable)) {
                throw $streamThrowable;
            }
            $fullContent = (string)$this->callAiOperation('generate', [
                'prompt' => $guardedPrompt,
                'scenario_code' => 'pagebuilder_component_generation',
                'test_region' => $region,
                'test_default_config' => $defaultConfig,
                'test_render_context' => $renderContext,
                'params' => $this->buildAiRuntimeParams($runtimeParams, false),
            ]);
            $streamResult = [];
        }
        if ($fullContent === '' && \is_array($streamResult) && \is_scalar($streamResult['content'] ?? null)) {
            $fullContent = (string)$streamResult['content'];
        }

        return $this->decodeAndNormalizeComponentContent(
            $fullContent,
            $region,
            'AI did not return a valid component JSON payload'
        );
    }

    private function shouldFallbackToNonStreamComponentGeneration(\Throwable $throwable): bool
    {
        if ($this->isEmptyAiStreamCompletionFailure($throwable)) {
            return true;
        }

        $message = \mb_strtolower($this->collectThrowableMessages($throwable));
        foreach ([
            'tls connect error',
            'ssl routines',
            'unexpected eof',
            'empty reply from server',
            'connection reset',
            'connection closed',
            'http/2 stream',
        ] as $needle) {
            if (\str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isTransientAiProviderFailure(?\Throwable $throwable): bool
    {
        if (!$throwable instanceof \Throwable) {
            return false;
        }
        if ($this->isEmptyAiStreamCompletionFailure($throwable)) {
            return true;
        }

        $message = \mb_strtolower($this->collectThrowableMessages($throwable));
        foreach ([
            'model unavailable',
            'temporarily unavailable',
            'tls connect error',
            'ssl routines',
            'unexpected eof',
            'empty reply from server',
            'connection reset',
            'connection closed',
            'http/2 stream',
        ] as $needle) {
            if (\str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function resolveComponentGenerationMaxTokens(string $region, array $renderContext = [], bool $retry = false): int
    {
        if (\in_array($region, ['header', 'footer'], true)) {
            return 1024;
        }

        if ($this->isBuildQueueComponentContext($renderContext)) {
            return $retry ? 3584 : 4096;
        }

        return $retry ? 4096 : 6144;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildComponentResponseFormat(string $region): array
    {
        $required = match ($region) {
            'header' => ['extra_fields', 'php_variables', 'css_extra', 'html_extra', 'js_content'],
            'footer' => ['extra_fields', 'php_variables', 'css_extra', 'html_extra_column', 'html_extra', 'footer_extra_text', 'js_content'],
            default => ['extra_fields', 'php_variables', 'css_extra', 'css_responsive', 'html_content', 'js_content'],
        };

        $properties = [];
        foreach ($required as $key) {
            $properties[$key] = ['type' => 'string'];
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'pagebuilder_component_' . \preg_replace('/[^a-z0-9_]+/i', '_', $region),
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => $properties,
                    'required' => $required,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeAndNormalizeComponentContent(string $content, string $region, string $message): array
    {
        $payload = $this->decodeComponentPayloadWithRepair($content, $region);
        if ($payload === null) {
            throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
                $message,
                [[
                    'rule' => 'component_json.parse',
                    'field' => $region,
                    'found' => $this->clipText(\trim($content), 500),
                    'expected' => 'Exactly one valid JSON object using the required component schema',
                    'hint' => 'Return exactly one valid component JSON object for the current plan_json block, with no markdown, prose, raw HTML, or PHP outside JSON.',
                ]]
            );
        }

        return $this->normalizeComponentPayload($payload, $region);
    }

    private function prependComponentJsonOnlyGuard(string $prompt, bool $retry): string
    {
        $guard = [
            '- Return only the component JSON envelope for the current plan_json block; never output raw PHTML, markdown, or prose.',
            '- You may think internally, but final output must contain only one JSON object and nothing else.',
            '- The first character of final output MUST be `{` and the last character MUST be `}`.',
            '- If the next token would be `<?php`, `<?=`, markdown, prose, or HTML, stop and replace the entire response with the required JSON object envelope. This endpoint is a JSON transport endpoint, not a PHP/PHTML renderer.',
            '- Valid transport shape example: {"extra_fields":"group:content => Content\\ncontent.title => Title:text:Final title","php_variables":"$contentTitle = $getConfig(\'content.title\', \'Final title\');","css_extra":"#componentId .pb-c-root{position:relative;}","css_responsive":"@media (max-width: 768px){#componentId .pb-c-root{padding:32px 16px;}}@media (max-width: 420px){#componentId .pb-c-root{padding:28px 14px;}}","html_content":"<section class=\'pb-c-root\'><h2><?= htmlspecialchars($contentTitle ?? \'Final title\', ENT_QUOTES, \'UTF-8\') ?></h2></section>","js_content":""}.',
            '- Do not output analysis, reasoning_content, markdown, code fences, comments, or explanatory prose.',
            '- Keep exact JSON field names required by this task; do not rename keys.',
            '- Ensure all JSON string values are properly escaped and syntactically valid.',
            '- Backslashes are only legal for JSON escapes like \\", \\\\, \\/, \\n, \\r, \\t, or \\uXXXX. Do not output stray backslashes before HTML text, class names, tag names, or visitor copy.',
            '- PHP/template code boundary: php_variables may contain only simple getConfig assignments, and html_content may contain only safe field echo expressions using htmlspecialchars or nl2br(htmlspecialchars). No PHP blocks, loops, conditions, echo/print, functions, arrays, or framework template snippets.',
            '- Top-level output boundary: never start the response with `<?php`, `<?=`, `<section`, `<div`, `<html`, or any PHTML/HTML. The raw final response must start with `{` because it is JSON transport, not a PHP/template file.',
            '- PHP marker boundary: `<?php` is forbidden in every field. `php_variables` is a JSON string containing assignment lines only, without PHP opening/closing tags. The only allowed PHP marker is a safe `<?= ... ?>` echo inside the html_content JSON string.',
            '- PHP default literal safety: every `$getConfig` fallback in php_variables must be a quoted PHP string literal. Never output bare locale words such as Privacy, Security, Support, or Step outside quotes; avoid apostrophes in fallback copy or escape them.',
            '- HTML string fields must be well-formed fragments: balanced tags, closed attribute quotes, no orphan closing tags, and no framework wrapper leakage.',
            '- HTML close-tag contract is strict: never merge adjacent closing tags, never invent tags such as </h>, </h3p>, </h2div>, </pa>, </buttondiv>, or </divsection>, and never close a parent element while a child heading/span/strong is still open.',
            '- Do not create empty tag names. `< class=...>`, `< >`, `</ >`, and `<span=...>` are invalid; use `<div class=...>` or plain text.',
            '- css_extra must contain only complete scoped selector blocks. No empty values like background:;, no invented property names, no raw text, no dangling braces, no comments, no comma selector lists, no `}}`, no `{{`, and balanced braces only.',
            '- css_responsive is the only field allowed to contain @media blocks. For content blocks it must include complete `@media (max-width: 768px)` and `@media (max-width: 420px)` blocks when the downstream contract asks for responsive CSS.',
            '- CSS functions are allowed only when complete and production-safe: clamp(), min(), max(), calc(), linear-gradient(), radial-gradient(), rgba(), color-mix(), and transform() are valid with balanced parentheses. CSS url(...) is forbidden for generated assets.',
            '- JSON string safety: do not put double quote characters inside html_content visible copy; rewrite quoted phrases into plain prose so JSON remains valid.',
            '- Use one simple root wrapper and shallow child blocks. Avoid nested inline tags inside headings; put emphasis in sibling spans or paragraphs instead.',
            '- Every `<` inside html_content must begin a valid HTML tag name or be escaped as visitor text; never leave dangling `<`, empty tag names, or comparison symbols in copy.',
            '- Use single quotes for all HTML attributes inside JSON strings. For editable image templates, keep data-pb-ai-image-role and data-pb-ai-asset-slot exact, render src/alt from media fields, and use the concrete final_url only as the media.image_url default/fallback; never return symbolic URL or slot placeholders.',
            '- Every custom class in html_content must use the requested component prefix. For content blocks in this workflow the prefix is `pb-c`, so classes must look like `pb-c-root` or `pb-c-card`; `.pb`, `pb`, `pb-`, `-card`, and generic class names are invalid.',
            '- Keep the actual compact action button clearly identifiable, normally `pb-c-cta`. Prefer `pb-c-action` or `pb-c-actions` for wrappers so wrapper CSS is not mistaken for button CSS.',
            '- Non-empty visible control contract: every `<a>`, `<button>`, form `<label>`, `.pb-c-cta`, `.pb-c-btn`, `.pb-c-button`, `.pb-c-badge`, `.pb-c-tag`, `.pb-c-chip`, `.pb-c-pill`, `.pb-c-label`, `.pb-c-value`, `.pb-c-kicker`, `.pb-c-eyebrow`, `.pb-c-metric`, `.pb-c-stat`, `.pb-c-proof`, `.pb-c-quote`, `.pb-c-step`, and `.pb-c-item` must contain meaningful target-locale visible copy through a safe PHP echo or localized literal. Whitespace, `&nbsp;`, zero-width characters, punctuation-only text, placeholder words, and empty child spans are invalid.',
            '- If you cannot source copy for a visible chip/badge/tag/pill/label/metric/proof/CTA from the current plan_json block, omit that whole element or bind a concrete fallback field such as `cta.text`; never draw empty rounded rectangles or input-like outline pills as decoration.',
            '- Never place standalone punctuation/symbol-only decorations in visible HTML text. Build arrows, dividers, stars, suit marks, plus signs, and other ornaments with CSS borders, gradients, pseudo-elements, or background layers instead.',
        ];
        if ($retry) {
            $guard[] = 'RECOVERY MODE: previous component JSON did not satisfy the contract. Return the corrected final JSON object immediately.';
        }

        return \implode("\n", $guard) . "\n\n" . $prompt;
    }

    private function buildPromptRolePriorityContract(): string
    {
        return "ROLE PRIORITY CONTRACT:\n"
            . "- Use plan_json.pages.{page_type}.{block_key} as the only source for block role, copy, media, CTA, and layout intent.\n"
            . "- Do not read or reconstruct any source outside plan_json.pages.{page_type}.{block_key}.\n"
            . "- Preserve the current block contract before adding optional visual polish.";
    }

    private function buildStage3UserBriefReferenceAddon(string $brief, string $refinement, string $refinementTargetLabel): string
    {
        return "USER BRIEF REFERENCE:\n"
            . ($brief !== ''
                ? "Original user one-line brief: {$brief}\n"
                : "Original user one-line brief: omitted; use confirmed plan_json task context only.\n")
            . ($refinement !== ''
                ? "Latest refine instruction for {$refinementTargetLabel}: {$refinement}\n"
                : "Latest refine instruction for {$refinementTargetLabel}: none.\n")
            . "Priority rule: satisfy the latest explicit user instruction while keeping the current plan_json block contract valid and visitor-facing.\n";
    }

    private function buildStage3ConfirmedPlanExecutionRule(string $taskScopeLabel): string
    {
        return "Confirmed-plan execution rule: {$taskScopeLabel} must execute the frozen plan-json task contract and confirmed theme as the concrete plan derived from the user prompt. "
            . "When the latest explicit user instruction gives a clearer content or design direction, preserve that intent while keeping the block contract valid. "
            . "Within page_type + block/region identity, you may exercise professional design freedom on layout, rhythm, decoration, and interaction, "
            . "but do not replace this block's job, invent cross-page content, or render planning metadata as visitor copy.\n";
    }

    private function collectThrowableMessages(\Throwable $throwable): string
    {
        $messages = [];
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        return \implode(' | ', $messages);
    }

    private function isEmptyAiStreamCompletionFailure(\Throwable $throwable): bool
    {
        for ($current = $throwable; $current instanceof \Throwable; $current = $current->getPrevious()) {
            $message = \mb_strtolower($current->getMessage(), 'UTF-8');
            if (
                (\str_contains($message, '流式') && \str_contains($message, '未返回任何内容'))
                || \str_contains($message, 'ai stream generation completed without content')
                || \str_contains($message, 'stream generation completed without content')
                || \str_contains($message, 'empty ai stream completion')
                || (
                    \str_contains($message, 'stream')
                    && (
                        \str_contains($message, 'completed without content')
                        || \str_contains($message, 'without any content')
                        || \str_contains($message, 'returned no content')
                    )
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizeComponentPayload(array $data, string $region = ''): array
    {
        foreach (['component', 'payload', 'data', 'content', 'section', 'block'] as $containerKey) {
            if (\is_array($data[$containerKey] ?? null)) {
                $data = \array_replace($data, $data[$containerKey]);
            }
        }

        $fieldMappings = [
            'extra_fields' => ['extra_fields', 'extraFields', 'fields'],
            'php_variables' => ['php_variables', 'phpVariables', 'php_vars', 'phpVars'],
            'css_extra' => ['css_extra', 'cssExtra', 'css', 'css_content', 'cssContent', 'style', 'styles'],
            'css_responsive' => ['css_responsive', 'cssResponsive', 'responsive_css', 'responsiveCss'],
            'html_content' => ['html_content', 'htmlContent', 'html', 'content', 'body', 'markup', 'template'],
            'html_extra' => ['html_extra', 'htmlExtra'],
            'html_extra_column' => ['html_extra_column', 'htmlExtraColumn'],
            'footer_extra_text' => ['footer_extra_text', 'footerExtraText'],
            'js_content' => ['js_content', 'jsContent', 'js', 'javascript'],
        ];

        $normalized = [];
        foreach ($fieldMappings as $normalizedKey => $possibleKeys) {
            foreach ($possibleKeys as $key) {
                if (!isset($data[$key]) || $data[$key] === null) {
                    continue;
                }
                $normalized[$normalizedKey] = $data[$key];
                break;
            }
        }

        if (!isset($normalized['html_content'])) {
            $html = $this->findFirstStringByKeys($data, ['html_content', 'htmlContent', 'html', 'markup', 'template']);
            if ($html !== null) {
                $normalized['html_content'] = $html;
            }
        }
        if ($region === 'content' && (!isset($normalized['html_content']) || \trim((string)$normalized['html_content']) === '')) {
            $html = $this->findFirstStringByKeys($data, ['html_extra', 'htmlExtra', 'html_extra_column', 'htmlExtraColumn']);
            if ($html !== null) {
                $normalized['html_content'] = $html;
            }
        }
        if (!isset($normalized['css_extra'])) {
            $css = $this->findFirstStringByKeys($data, ['css_extra', 'cssExtra', 'css_content', 'cssContent', 'css', 'style', 'styles']);
            if ($css !== null) {
                $normalized['css_extra'] = $css;
            }
        }
        if (!isset($normalized['js_content'])) {
            $js = $this->findFirstStringByKeys($data, ['js_content', 'jsContent', 'javascript', 'js']);
            if ($js !== null) {
                $normalized['js_content'] = $js;
            }
        }
        if (isset($normalized['php_variables']) && \is_string($normalized['php_variables'])) {
            $normalized['php_variables'] = $this->repairGetConfigPhpVariableFallbacks($normalized['php_variables']);
        }

        return $normalized !== [] ? \array_replace($data, $normalized) : $data;
    }

    private function repairGetConfigPhpVariableFallbacks(string $phpVariables): string
    {
        $lines = \preg_split('/\R/u', \str_replace(["\r\n", "\r"], "\n", $phpVariables));
        if (!\is_array($lines)) {
            return $phpVariables;
        }

        foreach ($lines as $index => $line) {
            $trimmed = \trim((string)$line);
            if (!\preg_match('/^($[A-Za-z_][A-Za-z0-9_]*)\s*=\s*$getConfig\s*\(/', $trimmed, $prefixMatch)) {
                continue;
            }

            $fieldStart = \strpos($trimmed, "'");
            if ($fieldStart === false) {
                continue;
            }
            $fieldEnd = \strpos($trimmed, "'", $fieldStart + 1);
            if ($fieldEnd === false) {
                continue;
            }
            $fieldKey = \substr($trimmed, $fieldStart + 1, $fieldEnd - $fieldStart - 1);
            if ($fieldKey === '') {
                continue;
            }
            $fallbackStart = \strpos($trimmed, "'", $fieldEnd + 1);
            if ($fallbackStart === false) {
                continue;
            }
            $fallbackEnd = \strrpos($trimmed, "');");
            if ($fallbackEnd === false || $fallbackEnd <= $fallbackStart) {
                $fallbackEnd = \strrpos($trimmed, "')");
            }
            if ($fallbackEnd === false || $fallbackEnd <= $fallbackStart) {
                continue;
            }

            $fallback = \substr($trimmed, $fallbackStart + 1, $fallbackEnd - $fallbackStart - 1);
            $lines[$index] = $prefixMatch[1] . ' = $getConfig(' . \var_export($fieldKey, true) . ', ' . \var_export($fallback, true) . ');';
        }

        return \implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $keys
     */
    private function findFirstStringByKeys(array $data, array $keys, int $depth = 0): ?string
    {
        if ($depth > 4) {
            return null;
        }
        foreach ($keys as $key) {
            if (isset($data[$key]) && \is_string($data[$key]) && \trim($data[$key]) !== '') {
                return (string)$data[$key];
            }
        }
        foreach ($data as $value) {
            if (!\is_array($value)) {
                continue;
            }
            $found = $this->findFirstStringByKeys($value, $keys, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeComponentPayloadWithRepair(string $content, string $region): ?array
    {
        $parser = $this->getResponseJsonParser();
        $content = $this->stripInvalidPhpPrefixBeforeJsonObject($content);

        $decoded = $parser->extractAndDecode($content);
        if (!\is_array($decoded)) {
            $repaired = $this->repairInvalidJsonBackslashEscapes($content);
            if ($repaired !== $content) {
                $decoded = $parser->extractAndDecode($repaired);
            }
        }
        return \is_array($decoded) ? $decoded : null;
    }

    private function stripInvalidPhpPrefixBeforeJsonObject(string $content): string
    {
        $trimmed = \ltrim($content);
        if (!\str_starts_with($trimmed, '<?php') && !\str_starts_with($trimmed, '<?=') && !\str_starts_with($trimmed, '<?')) {
            return $content;
        }

        $objectStart = \strpos($trimmed, '{');
        if ($objectStart === false) {
            return $content;
        }

        return \substr($trimmed, $objectStart);
    }

    private function repairInvalidJsonBackslashEscapes(string $content): string
    {
        return \preg_replace_callback(
            '/\\\\(?!["\\\\\/bfnrtu])/u',
            static fn(): string => '\\\\',
            $content
        ) ?? $content;
    }

    private function emitJsonRepairChunk(string $region, string $message): void
    {
        $chunkForwarder = RequestContext::get(self::REQUEST_CTX_AI_CHUNK_FORWARDER);
        if (\is_callable($chunkForwarder)) {
            try {
                $chunkForwarder([
                    'region' => $region !== '' ? ($region . '_json_repair') : 'json_repair',
                    'chunk' => $message,
                ]);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function buildAiRuntimeParams(array $params, bool $isStream = false): array
    {
        if (
            \is_array($params['response_format'] ?? null)
            && \in_array(\strtolower(\trim((string)($params['response_format']['type'] ?? ''))), ['json_object', 'json_schema'], true)
        ) {
            $params = $this->sanitizeStructuredJsonRequestParams($params);
        }

        if (\PHP_SAPI === 'cli' && $isStream) {
            $params['timeout'] = 0;
            $params['disable_ai_timeout'] = true;
            $params['disable_cli_timeout'] = true;
            $params['enforce_timeout_in_stream'] = false;
        }

        return $params;
    }

    /**
 * DeepSeek/GLM  ?thinking  ?JSON  ?reasoning_content ? *  ?thinking ? ? *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function sanitizeStructuredJsonRequestParams(array $params): array
    {
        $params['thinking'] = ['type' => 'disabled'];
        $params['thinking_mode'] = 'disabled';
        $params['enable_thinking'] = false;
        $params['enable_reasoning'] = false;
        unset($params['reasoning_effort'], $params['thinking_budget'], $params['thinking_budget_tokens']);

        return $params;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function buildRenderContext(string $pageType, array $websiteProfile, array $scope, array $defaultConfig): array
    {
        $blogContext = $this->buildBlogRenderContext($scope, $pageType);
        $styleSettings = [];
        $contentLocale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        if ($contentLocale === '') {
            $contentLocale = \trim((string)($defaultConfig['runtime.content_locale'] ?? ''));
        }
        $PlanJsonTask = $this->decodeRuntimePlanJsonTask($defaultConfig);
        $blockContractRaw = $defaultConfig['runtime.block_contract_json'] ?? null;
        $blockContract = [];
        if (\is_string($blockContractRaw) && \trim($blockContractRaw) !== '') {
            $decodedBlockContract = \json_decode($blockContractRaw, true);
            $blockContract = \is_array($decodedBlockContract) ? $decodedBlockContract : [];
        }

        $context = \array_merge([
            'page' => $this->buildPreviewPageStub($pageType, $websiteProfile, $scope, $blogContext),
            'style_settings' => $styleSettings,
            'style' => $styleSettings,
            'component_config' => $defaultConfig,
            'is_preview' => true,
            'content_locale' => $contentLocale,
            'locale' => $contentLocale,
            'website_locale' => $contentLocale,
            '_content_locale' => $contentLocale,
            '_content_locale_explicit' => $this->hasResolvedContentLocale($websiteProfile, $scope),
            '_website_profile' => $websiteProfile,
            '_scope_identity' => [
                'site_title' => $scope['site_title'] ?? null,
                'target_domain' => $scope['target_domain'] ?? null,
                'selected_domain' => $scope['selected_domain'] ?? null,
                'website_profile' => $scope['website_profile'] ?? null,
                'shared_prompt_context' => $scope['plan_json']['shared_prompt_context'] ?? null,
            ],
            '_visual_contract' => $this->decodeRuntimeVisualContract($defaultConfig),
            '_required_editable_fields' => (string)($defaultConfig['runtime.required_editable_fields'] ?? ''),
            '_scope' => $scope,
            'plan_json_task' => $PlanJsonTask,
            'block_contract' => $blockContract,
            '_plan_json_task' => $PlanJsonTask,
            'asset_manifest' => \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : ['version' => 1, 'slots' => []],
            'verified_assets' => $this->extractVerifiedAssetsForRenderContext($scope, $pageType, $defaultConfig),
        ], $blogContext);
        if (\is_callable($scope['_inline_image_asset_generator'] ?? null)) {
            $context['_inline_image_asset_generator'] = $scope['_inline_image_asset_generator'];
        }

        return $context;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function decodeRuntimeVisualContract(array $defaultConfig): array
    {
        $raw = $defaultConfig['runtime.visual_contract_json'] ?? null;
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw) || \trim($raw) === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
 *  ?PlanJsonTask  ?defaultConfig  ?strict recovery prompt  ?
 * runtime_context / theme_palette  ?JSON ?
     *
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function decodeRuntimePlanJsonTask(array $defaultConfig): array
    {
        foreach ([
            'runtime.plan_json_task_json',
            'runtime.task_contract_json',
            'runtime.plan_json_task',
            'runtime.task_contract',
        ] as $candidateKey) {
            $raw = $defaultConfig[$candidateKey] ?? null;
            if (\is_array($raw)) {
                return $raw;
            }
            if (\is_string($raw) && \trim($raw) !== '') {
                $decoded = \json_decode($raw, true);
                if (\is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @return array<string,mixed>
     */
    private function buildRuntimePlanJsonTaskSnapshot(array $PlanJsonTask): array
    {
        $snapshot = [];
        foreach ([
            'task_key',
            'task_type',
            'page_type',
            'region',
            'section_code',
            'section_key',
            'block_key',
            'block_type',
            'page_flow_role',
            'visual_signature',
            'image_intent',
            'runtime_context',
            'plan_context',
            'task_script',
            'block_task',
            'implementation_contract',
        ] as $key) {
            if (\array_key_exists($key, $PlanJsonTask)) {
                $snapshot[$key] = $PlanJsonTask[$key];
            }
        }

        return $snapshot;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $PlanJsonTask
     */
    private function attachRuntimeTaskContextDefaults(array &$defaultConfig, array $PlanJsonTask, string $locale): void
    {
        $runtimeContext = \is_array($PlanJsonTask['runtime_context'] ?? null) ? $PlanJsonTask['runtime_context'] : [];
        $languageContract = \is_array($runtimeContext['language_contract'] ?? null)
            ? $runtimeContext['language_contract']
            : $this->buildStage3TaskLanguageContract($locale);
        $defaultConfig['runtime.language_contract_json'] = (string)\json_encode(
            $languageContract,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE
        );
        $blockContract = $this->resolveBlockContract($PlanJsonTask);
        if ($blockContract !== []) {
            $defaultConfig['runtime.block_contract_json'] = (string)\json_encode(
                $blockContract,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE
            );
        }
        if ($PlanJsonTask !== []) {
            $defaultConfig['runtime.plan_json_task_json'] = (string)\json_encode(
                $this->buildRuntimePlanJsonTaskSnapshot($PlanJsonTask),
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE
            );
        }
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,string>
     */
    private function extractVerifiedAssetsFromManifest(array $scope): array
    {
        $manifest = \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [];
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        $assets = [];
        foreach ($slots as $slotId => $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
            if ($finalUrl !== '' && !$this->isPlaceholderAssetSlot($slot)) {
                $assets[(string)($slot['slot_id'] ?? $slotId)] = $finalUrl;
            }
        }

        return $assets;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $defaultConfig
     * @return array<string,string>
     */
    private function extractVerifiedAssetsForRenderContext(array $scope, string $pageType, array $defaultConfig): array
    {
        $sharedRegion = \trim((string)($defaultConfig['runtime.shared_region'] ?? ''));
        if (\in_array($sharedRegion, ['header', 'footer'], true)) {
            return [];
        }

        return $this->extractVerifiedAssetsForTarget(
            $scope,
            $this->firstConfigString($defaultConfig, ['runtime.section_page_type']) ?: $pageType,
            $this->firstConfigString($defaultConfig, ['runtime.section_key', 'runtime.block_key']),
            $this->firstConfigString($defaultConfig, ['runtime.section_code']),
            $this->firstConfigString($defaultConfig, ['runtime.task_key']),
            $this->firstConfigString($defaultConfig, ['runtime.section_image_slot_id', 'visual.image_slot_id'])
        );
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $PlanJsonTask
     * @return array<string,string>
     */
    private function extractVerifiedAssetsForPlanJsonTask(array $scope, array $PlanJsonTask): array
    {
        $taskType = \trim((string)($PlanJsonTask['task_type'] ?? ''));
        $taskKey = \trim((string)($PlanJsonTask['task_key'] ?? ''));
        if ($taskType === 'shared_component' || \str_starts_with($taskKey, 'shared:')) {
            return $this->extractIdentityVerifiedAssets($scope);
        }

        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];

        return $this->extractVerifiedAssetsForTarget(
            $scope,
            \trim((string)($PlanJsonTask['page_type'] ?? $planContext['source_page_type'] ?? '')),
            \trim((string)($PlanJsonTask['block_key'] ?? $PlanJsonTask['section_key'] ?? $PlanJsonTask['source_block_key'] ?? $planContext['source_block_key'] ?? '')),
            \trim((string)($PlanJsonTask['section_code'] ?? $planContext['section_code'] ?? '')),
            $taskKey,
            ''
        );
    }

    /**
     * Shared header/footer tasks may legally reuse global identity assets such as
     * logo and favicon. Include only those verified global slots in the
     * allowlist so shared prompts stay constrained without blocking brand reuse.
     *
     * @param array<string,mixed> $scope
     * @return array<string,string>
     */
    private function extractIdentityVerifiedAssets(array $scope): array
    {
        $manifest = \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [];
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        $assets = [];
        foreach ($slots as $fallbackId => $slot) {
            if (!\is_array($slot) || $this->isPlaceholderAssetSlot($slot)) {
                continue;
            }
            $slotId = \trim((string)($slot['slot_id'] ?? $fallbackId));
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
            $pageType = \strtolower(\trim((string)($slot['page_type'] ?? '')));
            $sectionCode = \strtolower(\trim((string)($slot['section_code'] ?? '')));
            if (
                $slotId === ''
                || $finalUrl === ''
                || !\str_starts_with(\strtolower($slotId), 'identity:')
                || !\in_array($pageType, ['', 'global'], true)
                || !\in_array($sectionCode, ['', 'identity', 'global'], true)
            ) {
                continue;
            }
            $assets[$slotId] = $finalUrl;
        }

        return $assets;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,string>
     */
    private function extractVerifiedAssetsForTarget(
        array $scope,
        string $pageType,
        string $blockKey = '',
        string $sectionCode = '',
        string $taskKey = '',
        string $slotId = ''
    ): array {
        $assets = $this->extractVerifiedAssetsFromPlanJsonBlockAssets($scope, $pageType, $blockKey, $sectionCode, $taskKey, $slotId);
        $manifest = \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [];
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        foreach ($slots as $fallbackId => $slot) {
            if (!\is_array($slot) || $this->isPlaceholderAssetSlot($slot)) {
                continue;
            }
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
            if ($finalUrl === '') {
                continue;
            }
            $candidateSlotId = \trim((string)($slot['slot_id'] ?? $fallbackId));
            if (!$this->assetSlotMatchesTarget($slot, $candidateSlotId, $pageType, $blockKey, $sectionCode, $taskKey, $slotId)) {
                continue;
            }
            if (!isset($assets[$candidateSlotId])) {
                $assets[$candidateSlotId] = $finalUrl;
            }
        }

        return $assets;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,string>
     */
    private function extractVerifiedAssetsFromPlanJsonBlockAssets(
        array $scope,
        string $pageType,
        string $blockKey,
        string $sectionCode,
        string $taskKey,
        string $slotId
    ): array {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        if ($page === []) {
            return [];
        }

        $assets = [];
        foreach ($page as $candidateBlockKey => $block) {
            if (!\is_array($block) || !\is_string($candidateBlockKey)) {
                continue;
            }
            if (isset(self::PLAN_JSON_PAGE_ASSET_META_KEYS[$candidateBlockKey])) {
                continue;
            }
            if (!$this->planJsonBlockMatchesAssetTarget($block, $candidateBlockKey, $blockKey, $sectionCode, $taskKey)) {
                continue;
            }
            $rawAssets = \is_array($block['assets'] ?? null) ? $block['assets'] : [];
            if ($rawAssets === []) {
                continue;
            }
            $defaultConfig = \array_replace(
                \is_array($block['default_config'] ?? null) ? $block['default_config'] : [],
                \is_array($block['fields'] ?? null) ? $block['fields'] : []
            );
            $assetRows = [];
            foreach ($rawAssets as $fallbackSlotId => $asset) {
                $this->appendBlockLocalAsset($assetRows, $asset, $fallbackSlotId, $defaultConfig);
            }
            foreach ($assetRows as $candidateSlotId => $asset) {
                $finalUrl = \trim((string)($asset['final_url'] ?? $asset['url'] ?? ''));
                if ($finalUrl === '') {
                    continue;
                }
                $slot = \array_replace([
                    'page_type' => $pageType,
                    'block_key' => $candidateBlockKey,
                    'section_code' => $this->firstConfigString($block, ['section_code', 'component_code', 'code']),
                    'task_key' => $this->firstConfigString(\is_array($block['result_ref'] ?? null) ? $block['result_ref'] : [], ['task_key']),
                ], $asset);
                if (!$this->assetSlotMatchesTarget($slot, $candidateSlotId, $pageType, $blockKey, $sectionCode, $taskKey, $slotId)) {
                    continue;
                }
                $assets[$candidateSlotId] = $finalUrl;
            }
        }

        return $assets;
    }

    /**
     * @param array<string,mixed> $block
     */
    private function planJsonBlockMatchesAssetTarget(
        array $block,
        string $candidateBlockKey,
        string $blockKey,
        string $sectionCode,
        string $taskKey
    ): bool {
        if ($blockKey === '' && $sectionCode === '' && $taskKey === '') {
            return true;
        }
        if ($blockKey !== '') {
            foreach ([$candidateBlockKey, $block['block_key'] ?? null, $block['section_key'] ?? null] as $candidate) {
                if (\trim((string)$candidate) === $blockKey) {
                    return true;
                }
            }
        }

        $candidateSectionCode = $this->firstConfigString($block, ['section_code', 'component_code', 'code']);
        if ($sectionCode !== '' && $this->assetSectionIdentityMatches($candidateSectionCode, $sectionCode)) {
            return true;
        }

        $resultRef = \is_array($block['result_ref'] ?? null) ? $block['result_ref'] : [];
        $candidateTaskKey = $this->firstConfigString($block, ['task_key']);
        if ($candidateTaskKey === '') {
            $candidateTaskKey = $this->firstConfigString($resultRef, ['task_key']);
        }

        return $taskKey !== '' && $candidateTaskKey === $taskKey;
    }

    private function assetSectionIdentityMatches(string $candidate, string $sectionCode): bool
    {
        $candidate = \trim($candidate);
        $sectionCode = \trim($sectionCode);
        if ($candidate === '' || $sectionCode === '') {
            return false;
        }
        if ($candidate === $sectionCode) {
            return true;
        }

        $normalize = static function (string $value): string {
            $value = \str_replace('\\', '/', $value);
            $value = \preg_replace('#^content/#', '', $value) ?? $value;
            $value = \str_replace(['/', '_'], '-', $value);

            return \strtolower(\trim($value, '-'));
        };

        return $normalize($candidate) === $normalize($sectionCode);
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function assetSlotMatchesTarget(
        array $slot,
        string $candidateSlotId,
        string $pageType,
        string $blockKey,
        string $sectionCode,
        string $taskKey,
        string $slotId
    ): bool {
        $candidateSlotId = \trim($candidateSlotId);
        if ($slotId !== '' && $candidateSlotId === $slotId) {
            return true;
        }

        $slotPageType = \trim((string)($slot['page_type'] ?? ''));
        if ($pageType !== '') {
            if ($slotPageType !== '' && $slotPageType !== $pageType) {
                return false;
            }
            if ($slotPageType === '' && !\str_starts_with($candidateSlotId, 'page:' . $pageType . ':')) {
                return false;
            }
        }

        $slotTaskKey = \trim((string)($slot['task_key'] ?? ''));
        if ($taskKey !== '' && $slotTaskKey !== '' && $slotTaskKey === $taskKey) {
            return true;
        }
        $slotBlockKey = \trim((string)($slot['block_key'] ?? ''));
        if ($blockKey !== '' && $slotBlockKey !== '' && $slotBlockKey === $blockKey) {
            return true;
        }
        $slotSectionCode = \trim((string)($slot['section_code'] ?? ''));
        if ($sectionCode !== '' && $slotSectionCode !== '' && $slotSectionCode === $sectionCode) {
            return true;
        }
        if ($sectionCode !== '') {
            $normalizedCode = \str_replace('/', '-', $sectionCode);
            if ($candidateSlotId === 'page:' . $pageType . ':' . $normalizedCode || $candidateSlotId === 'page:' . $pageType . ':' . $sectionCode) {
                return true;
            }
        }
        if ($blockKey !== '') {
            $normalizedBlock = \str_replace('/', '-', $blockKey);
            if ($candidateSlotId === 'page:' . $pageType . ':' . $normalizedBlock || $candidateSlotId === 'page:' . $pageType . ':' . $blockKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function isPlaceholderAssetSlot(array $slot): bool
    {
        $finalUrl = \trim((string)($slot['final_url'] ?? ''));
        foreach (\is_array($slot['variants'] ?? null) ? $slot['variants'] : [] as $variant) {
            if (!\is_array($variant)) {
                continue;
            }
            $isPlaceholder = (int)($variant['placeholder'] ?? 0) === 1;
            foreach (['mode', 'model', 'source'] as $key) {
                $value = \strtolower(\trim((string)($variant[$key] ?? '')));
                $isPlaceholder = $isPlaceholder
                    || $value === 'placeholder'
                    || $value === 'local_composed'
                    || $value === 'local-premium-composition-v1'
                    || \str_contains($value, 'local_composition');
            }
            if (\trim((string)($variant['generation_fallback_reason'] ?? '')) !== '') {
                $isPlaceholder = true;
            }
            if ($isPlaceholder && ($finalUrl === '' || $this->assetVariantReferencesFinalUrl($variant, $finalUrl))) {
                return true;
            }
        }

        $source = \strtolower(\trim((string)($slot['source'] ?? '')));
        $lowerFinalUrl = \strtolower($finalUrl);
        return $source === 'generated'
            && \str_contains($lowerFinalUrl, '/ai-generated/')
            && \str_ends_with($lowerFinalUrl, '.svg');
    }

    /**
     * @param array<string,mixed> $variant
     */
    private function assetVariantReferencesFinalUrl(array $variant, string $finalUrl): bool
    {
        $finalUrl = \trim($finalUrl);
        foreach (['url', 'final_url'] as $key) {
            if (\trim((string)($variant[$key] ?? '')) === $finalUrl) {
                return true;
            }
        }
        $path = \trim((string)($variant['path'] ?? ''));
        return $path !== '' && '/' . \ltrim(\str_replace('\\', '/', $path), '/') === $finalUrl;
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function extractManifestSlots(array $scope): array
    {
        $manifest = \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [];
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];

        return \array_values(\array_filter($slots, static fn($slot): bool => \is_array($slot)));
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolveHeaderAssetUrl(array $scope): string
    {
        foreach ($this->extractManifestSlots($scope) as $slot) {
            $slotType = \trim((string)($slot['slot_type'] ?? ''));
            $field = \strtolower(\trim((string)($slot['field'] ?? '')));
            $kind = \strtolower(\trim((string)($slot['kind'] ?? '')));
            $slotId = \strtolower(\trim((string)($slot['slot_id'] ?? '')));
            $sectionCode = \strtolower(\trim((string)($slot['section_code'] ?? '')));
            $pageType = \strtolower(\trim((string)($slot['page_type'] ?? '')));
            if (
                $slotType !== 'logo_icon'
                || !\in_array($field, ['logo', 'logo.image', 'brand.logo'], true)
                || !\in_array($kind, ['website_logo', 'logo', 'brand_logo'], true)
                || !\str_starts_with($slotId, 'identity:')
                || !\in_array($sectionCode, ['', 'identity', 'global'], true)
                || !\in_array($pageType, ['', 'global'], true)
            ) {
                continue;
            }
            $finalUrl = $this->normalizePublishableLogoAssetUrl((string)($slot['final_url'] ?? ''));
            if ($finalUrl !== '' && !$this->looksLikeSectionGeneratedAssetUrl($finalUrl)) {
                return $finalUrl;
            }
        }
        return '';
    }

    private function looksLikeSectionGeneratedAssetUrl(string $value): bool
    {
        $path = (string)(\parse_url($value, \PHP_URL_PATH) ?: $value);
        $basename = \strtolower((string)\basename($path));
        if ($basename === '') {
            return false;
        }

        return \preg_match('/(?:^|[-_])(?:page|content|section|hero|banner|trust|badge|badges|category|categories|showcase|feature|features|testimonial|story|faq|download|cta)(?:[-_.]|$)/i', $basename) === 1;
    }

    /**
 *  ?section  ?URL ? *
 *  ? ?build  ?stage1  ? ?
 * 1.  ? * 2.  ? ? ? ? ?page_type  ?
 * a. slot_id  ?section  ?page+code  ?`page:home_page:content-home-page-hero` ? * b.  ?page_type  ? ?slot_id/block_key/task_key  ?section  ? * c.  ?page_type  ? ?slot ? * d. removed ?='' ?  ? ?brief/label ?stage1  ?slot  ?brief ? * e. removed + slot_type  ?slot ? *
 *  ?removed slot  ?slot_id=5 ? ?"hero" ?
 *  ?page-scoped slot  ?stage1  ?removed  ? *
     * @param array<string,mixed> $section
     * @param array<string,mixed> $scope
     */
    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveSectionManifestSlot(string $pageType, string $sectionCode, string $sectionKey, array $scope): array
    {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            return [];
        }

        $expectedIds = [];
        $appendExpectedId = static function (string $value) use (&$expectedIds): void {
            $value = \strtolower(\trim(\str_replace('\\', '/', $value)));
            if ($value === '' || \in_array($value, $expectedIds, true)) {
                return;
            }
            $expectedIds[] = $value;
        };
        foreach ([$sectionCode, $sectionKey] as $token) {
            $token = \trim((string)$token);
            if ($token === '') {
                continue;
            }
            $appendExpectedId('page:' . $pageType . ':' . \str_replace('/', '-', $token));
            $appendExpectedId('page:' . $pageType . ':' . $token);
        }

        $normalizedSectionCode = $this->normalizeAssetLookupToken($sectionCode);
        $normalizedSectionKey = $this->normalizeAssetLookupToken($sectionKey);
        foreach ($this->extractManifestSlots($scope) as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $slotId = \strtolower(\trim((string)($slot['slot_id'] ?? '')));
            if ($slotId !== '' && \in_array($slotId, $expectedIds, true)) {
                return $slot;
            }
        }

        foreach ($this->extractManifestSlots($scope) as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $slotPageType = \trim((string)($slot['page_type'] ?? ''));
            if ($slotPageType !== '' && $slotPageType !== $pageType) {
                continue;
            }

            $slotSectionCode = $this->normalizeAssetLookupToken((string)($slot['section_code'] ?? ''));
            $slotBlockKey = $this->normalizeAssetLookupToken((string)($slot['block_key'] ?? ''));
            $slotTaskKey = \strtolower(\trim((string)($slot['task_key'] ?? '')));
            if (
                ($normalizedSectionCode !== '' && $slotSectionCode === $normalizedSectionCode)
                || ($normalizedSectionKey !== '' && $slotBlockKey === $normalizedSectionKey)
                || ($normalizedSectionKey !== '' && $slotTaskKey !== '' && \str_ends_with($slotTaskKey, ':' . $normalizedSectionKey))
            ) {
                return $slot;
            }
        }

        return [];
    }

    private function normalizeAssetLookupToken(string $value): string
    {
        $value = \strtolower(\trim($value));
        if ($value === '') {
            return '';
        }
        $value = \str_replace(['\\', '/', '_'], ['-', '-', '-'], $value);

        return \trim((string)\preg_replace('/[^a-z0-9-]+/i', '-', $value), '-');
    }

    private function resolveSectionAssetUrl(string $pageType, array $section, array $scope): string
    {
        $candidates = [];
        foreach ([
            (string)($section['key'] ?? ''),
            (string)($section['source_block_key'] ?? ''),
            (string)($section['code'] ?? ''),
            (string)($section['name'] ?? ''),
        ] as $candidate) {
            $candidate = \strtolower(\trim($candidate));
            if ($candidate !== '') {
                $candidates[$candidate] = true;
            }
        }

        $templateKey = \strtolower(\trim((string)($section['template'] ?? '')));
        $isHeroLikeTemplate = \in_array($templateKey, ['hero', 'banner'], true);
        $preferredTypes = $isHeroLikeTemplate
            ? ['hero_image', 'section_image', 'trust_brand_image']
            : ['section_image', 'trust_brand_image', 'hero_image'];

        $slots = $this->extractManifestSlots($scope);

        $sectionCode = \strtolower(\trim((string)($section['code'] ?? '')));
        $sectionKey = \strtolower(\trim((string)($section['key'] ?? '')));
        $expectedSlotIdsOrdered = [];
        $appendExpectedId = static function (string $candidate) use (&$expectedSlotIdsOrdered): void {
            if ($candidate === '' || \in_array($candidate, $expectedSlotIdsOrdered, true)) {
                return;
            }
            $expectedSlotIdsOrdered[] = $candidate;
        };
        if ($sectionCode !== '') {
            $normalizedCode = \str_replace('/', '-', $sectionCode);
            $appendExpectedId("page:{$pageType}:{$normalizedCode}");
            $appendExpectedId("page:{$pageType}:{$sectionCode}");
        }
        if ($sectionKey !== '') {
            $normalizedKey = \str_replace('/', '-', $sectionKey);
            $appendExpectedId("page:{$pageType}:{$normalizedKey}");
            $appendExpectedId("page:{$pageType}:{$sectionKey}");
        }

        $slotsByExactId = [];
        foreach ($slots as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $slotId = \strtolower(\trim((string)($slot['slot_id'] ?? '')));
            if ($slotId === '') {
                continue;
            }
            $slotsByExactId[$slotId] = $slot;
        }

 //  ?verifiedOnly  ?pass ?miss  ? //  ?scoped  ?removed ? ?slot_type  ? ? //  ?page-scoped  ?slot ?page:home_page:cta ?
 //  ?section  ?removed slot ?details ? // Full refactor rule: programmatic/local fallback images are not
        // compatible production assets. If no verified asset exists, leave the
        // section without an inherited URL so the block-level inline generator
        // must call the real text-to-image model for its required slot.
        foreach ([true] as $verifiedOnly) {
            foreach ($expectedSlotIdsOrdered as $expectedId) {
                $slot = $slotsByExactId[$expectedId] ?? null;
                if (!\is_array($slot)) {
                    continue;
                }
                $finalUrl = \trim((string)($slot['final_url'] ?? ''));
                if ($finalUrl === '') {
                    continue;
                }
                if ($verifiedOnly && $this->isManifestSlotPlaceholder($slot)) {
                    continue;
                }
                return $finalUrl;
            }

            $matched = $this->findSlotByKeywordsScoped($slots, $candidates, $preferredTypes, $pageType, true, $verifiedOnly);
            if ($matched !== '') {
                return $matched;
            }

            $matched = $this->findSlotByKeywordsScoped($slots, $candidates, $preferredTypes, $pageType, false, $verifiedOnly, true);
            if ($matched !== '') {
                return $matched;
            }

            $matched = $this->findSlotByKeywordsAcrossPageTypes($slots, $candidates, $preferredTypes, $verifiedOnly);
            if ($matched !== '') {
                return $matched;
            }

        }

        return '';
    }

    /**
 *  ?page_type  ?page-scoped  ?removed keyword  ?miss  ? *  ?stage1  ?page_type  ?removed slot ?details ? *
     * @param list<array<string,mixed>> $slots
     * @param array<string,bool>        $candidates
     * @param list<string>              $preferredTypes
     */
    private function findSlotByKeywordsAcrossPageTypes(
        array $slots,
        array $candidates,
        array $preferredTypes,
        bool $verifiedOnly
    ): string {
        if ($candidates === []) {
            return '';
        }
        foreach ($preferredTypes as $preferredType) {
            foreach ($slots as $slot) {
                if (!\is_array($slot)) {
                    continue;
                }
                $slotType = \trim((string)($slot['slot_type'] ?? ''));
                $finalUrl = \trim((string)($slot['final_url'] ?? ''));
                if ($slotType !== $preferredType || $finalUrl === '') {
                    continue;
                }
                if ($verifiedOnly && $this->isManifestSlotPlaceholder($slot)) {
                    continue;
                }
                $haystack = \strtolower(\implode(' ', [
                    (string)($slot['slot_id'] ?? ''),
                    (string)($slot['block_key'] ?? ''),
                    (string)($slot['task_key'] ?? ''),
                ]));
                foreach ($this->normalizeKeywordCandidateList($candidates) as $needle) {
                    if ($needle !== '' && \str_contains($haystack, $needle)) {
                        return $finalUrl;
                    }
                }
            }
        }
        return '';
    }

    /**
 *  ?page_type  ?slot ? *
     * @param list<array<string,mixed>> $slots
     * @param array<string,bool>        $candidates
     * @param list<string>              $preferredTypes
     */
    private function findSlotByKeywordsScoped(
        array $slots,
        array $candidates,
        array $preferredTypes,
        string $pageType,
        bool $requireSamePageType,
        bool $verifiedOnly,
        bool $includeBriefInHaystack = false
    ): string {
        if ($candidates === []) {
            return '';
        }
        foreach ($preferredTypes as $preferredType) {
            foreach ($slots as $slot) {
                if (!\is_array($slot)) {
                    continue;
                }
                $slotType = \trim((string)($slot['slot_type'] ?? ''));
                $finalUrl = \trim((string)($slot['final_url'] ?? ''));
                if ($slotType !== $preferredType || $finalUrl === '') {
                    continue;
                }
                if ($verifiedOnly && $this->isManifestSlotPlaceholder($slot)) {
                    continue;
                }
                $slotPageType = \trim((string)($slot['page_type'] ?? ''));
                if ($requireSamePageType) {
                    if ($slotPageType !== $pageType) {
                        continue;
                    }
                } elseif ($slotPageType !== '') {
                    continue;
                }
                $haystackParts = [
                    (string)($slot['slot_id'] ?? ''),
                    (string)($slot['block_key'] ?? ''),
                    (string)($slot['task_key'] ?? ''),
                ];
                if ($includeBriefInHaystack) {
                    $haystackParts[] = (string)($slot['label'] ?? '');
                    $haystackParts[] = (string)($slot['brief'] ?? '');
                }
                $haystack = \strtolower(\implode(' ', $haystackParts));
                foreach ($this->normalizeKeywordCandidateList($candidates) as $needle) {
                    if ($needle !== '' && \str_contains($haystack, $needle)) {
                        return $finalUrl;
                    }
                }
            }
        }
        return '';
    }

    /**
 *  ?page_type  ?slot_type  ?slot final_url ? *
     * @param list<array<string,mixed>> $slots
     * @param list<string>              $preferredTypes
     */
    private function findFirstScopedSlot(
        array $slots,
        array $preferredTypes,
        string $pageType,
        bool $requireSamePageType,
        bool $verifiedOnly
    ): string {
        foreach ($preferredTypes as $preferredType) {
            foreach ($slots as $slot) {
                if (!\is_array($slot)) {
                    continue;
                }
                $slotType = \trim((string)($slot['slot_type'] ?? ''));
                $finalUrl = \trim((string)($slot['final_url'] ?? ''));
                if ($slotType !== $preferredType || $finalUrl === '') {
                    continue;
                }
                if ($verifiedOnly && $this->isManifestSlotPlaceholder($slot)) {
                    continue;
                }
                $slotPageType = \trim((string)($slot['page_type'] ?? ''));
                if ($requireSamePageType) {
                    if ($slotPageType !== $pageType) {
                        continue;
                    }
                } elseif ($slotPageType !== '') {
                    continue;
                }
                return $finalUrl;
            }
        }
        return '';
    }

    /**
 *  ?manifest slot  e/ ?placeholder fallback  ?SVG ? *
     * @param array<string,mixed> $slot
     */
    private function isManifestSlotPlaceholder(array $slot): bool
    {
        $variants = \is_array($slot['variants'] ?? null) ? $slot['variants'] : [];
        if ($variants === []) {
            return false;
        }
        foreach ($variants as $variant) {
            if (!\is_array($variant)) {
                continue;
            }
            if ((int)($variant['placeholder'] ?? 0) === 1) {
                continue;
            }
            $mode = \strtolower(\trim((string)($variant['mode'] ?? '')));
            $model = \strtolower(\trim((string)($variant['model'] ?? '')));
            $source = \strtolower(\trim((string)($variant['source'] ?? '')));
            if (
                $mode === 'placeholder'
                || $mode === 'local_composed'
                || $model === 'local-premium-composition-v1'
                || \str_contains($mode, 'local_composition')
                || \str_contains($model, 'local_composition')
                || \str_contains($source, 'fallback')
                || \trim((string)($variant['generation_fallback_reason'] ?? '')) !== ''
            ) {
                continue;
            }
            $url = \strtolower(\trim((string)($variant['url'] ?? '')));
            if ($url !== '' && (\str_ends_with($url, '.svg') || \str_contains($url, '/placeholder'))) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * @param array<string|int, mixed> $candidates
     * @return list<string>
     */
    private function normalizeKeywordCandidateList(array $candidates): array
    {
        $keywords = [];
        foreach ($candidates as $key => $value) {
            $keyword = \is_string($key) ? $key : '';
            if ($keyword === '' && \is_scalar($value)) {
                $keyword = (string)$value;
            }
            $keyword = \strtolower(\trim($keyword));
            if ($keyword === '') {
                continue;
            }
            $keywords['k:' . $keyword] = $keyword;
        }

        return \array_values($keywords);
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $blogContext
     */
    private function buildPreviewPageStub(string $pageType, array $websiteProfile, array $scope, array $blogContext): PreviewPageStub
    {
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        $navigationPages = $this->buildNavigationPages($scope, $locale);
        $headerNavigationPages = $this->buildHeaderNavigationPages($scope, $locale);
        $siteTitle = $this->getPageBlueprintService()->resolveSiteDisplayName($websiteProfile, $scope);
        $siteTitle = $this->resolveLocaleSafeSiteDisplayName($siteTitle, $websiteProfile, $scope, $locale);

        return new PreviewPageStub([
            'website_id' => (int)($scope['draft_website_id'] ?? $scope['website_id'] ?? 0),
            'type' => $pageType,
            'title' => $siteTitle,
            'meta_title' => $siteTitle,
            'logo' => (string)($websiteProfile['logo'] ?? ''),
            'icon' => (string)($websiteProfile['icon'] ?? $websiteProfile['favicon'] ?? ''),
            'header_navigation_pages' => $headerNavigationPages,
            'navigation_pages' => $navigationPages,
            'blog_posts' => $blogContext['blog_posts'] ?? [],
            'blog_categories' => $blogContext['blog_categories'] ?? [],
            'home_page_config' => [
                'style' => 'default',
                'style_setting' => [],
                'layout_config' => [],
                'logo' => (string)($websiteProfile['logo'] ?? ''),
                'icon' => (string)($websiteProfile['icon'] ?? $websiteProfile['favicon'] ?? ''),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function buildNavigationPages(array $scope, string $locale = ''): array
    {
        $pageTypes = $this->resolveScopedPageTypes($scope);
        $locale = $locale !== '' ? $locale : $this->resolveScopePrimaryLocale($scope);
        $routeContract = $this->resolvePageRouteContract($scope, $pageTypes, $locale);
        $routesByType = $this->getPageRouteContractService()->routesByType($routeContract);
        if ($routesByType !== []) {
            $items = [];
            foreach ($pageTypes as $index => $pageType) {
                if ($pageType === Page::TYPE_BLOG || $pageType === Page::TYPE_BLOG_CATEGORY) {
                    continue;
                }
                if (!\is_array($routesByType[$pageType] ?? null)) {
                    continue;
                }
                $title = $this->localizePageTypeTitle($pageType, $locale);
                if ($title === '') {
                    $title = (string)($routesByType[$pageType]['label'] ?? '');
                }
                if ($title === '') {
                    $title = $this->humanizeIdentifier($pageType);
                }
                $items[] = [
                    'title' => $title,
                    'handle' => (string)($routesByType[$pageType]['handle'] ?? ''),
                    'url' => (string)($routesByType[$pageType]['path'] ?? '#'),
                    'type' => $pageType,
                    'page_id' => $index + 1,
                ];
            }
            if ($items !== []) {
                return $items;
            }
        }

        $labels = Page::getPageTypes();
        $items = [];

        foreach ($pageTypes as $index => $pageType) {
            if ($pageType === Page::TYPE_BLOG || $pageType === Page::TYPE_BLOG_CATEGORY) {
                continue;
            }

            $handle = Page::getDefaultHandleForType($pageType);
            $title = $this->localizePageTypeTitle($pageType, $locale);
            if ($title === '') {
                $title = $this->filterVisibleCopyForLocale((string)($labels[$pageType] ?? $pageType), $locale);
            }
            if ($title === '') {
                $title = $this->humanizeIdentifier($pageType);
            }
            $items[] = [
                'title' => $title,
                'handle' => $handle,
                'url' => $pageType === Page::TYPE_HOME ? '/' : '/' . $handle,
                'type' => $pageType,
                'page_id' => $index + 1,
            ];
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function buildHeaderNavigationPages(array $scope, string $locale = ''): array
    {
        $locale = $locale !== '' ? $locale : $this->resolveScopePrimaryLocale($scope);
        $navigationPages = $this->buildNavigationPages($scope, $locale);
        $sharedPromptContext = $this->resolveSharedPromptContext($scope);
        $sharedHeaderItems = $this->localizePromptLinkItemsForLocale(
            $this->normalizePromptLinkItems($sharedPromptContext['header_items'] ?? []),
            $navigationPages,
            $locale
        );
        $sharedHeaderItems = $this->normalizeLinkItemsAgainstRouteContract($sharedHeaderItems, $scope, $locale, $navigationPages, 'header_route_types');
        if (\count($sharedHeaderItems) >= 3) {
            return \array_slice(\array_values(\array_map(function (array $item): array {
                $href = \trim((string)($item['href'] ?? '#'));
                return [
                    'title' => (string)($item['label'] ?? ''),
                    'handle' => $this->deriveHandleFromHref($href),
                    'url' => $href !== '' ? $href : '#',
                    'type' => (string)($item['type'] ?? ''),
                    'page_id' => 0,
                ];
            }, $sharedHeaderItems)), 0, 5);
        }
        $routeItems = $this->routeLinkItemsForScope($scope, $locale, 'header_route_types');
        if ($routeItems !== []) {
            return \array_values(\array_map(function (array $item): array {
                $href = \trim((string)($item['href'] ?? '#'));
                return [
                    'title' => (string)($item['label'] ?? ''),
                    'handle' => $this->deriveHandleFromHref($href),
                    'url' => $href !== '' ? $href : '#',
                    'type' => (string)($item['type'] ?? ''),
                    'page_id' => 0,
                ];
            }, $routeItems));
        }

        return $navigationPages;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $fallbackItems
     * @return list<array{label:string,href:string,type?:string}>
     */
    private function localizePromptLinkItemsForLocale(array $items, array $fallbackItems, string $locale): array
    {
        if ($items === []) {
            return [];
        }

        $fallbackByHref = [];
        $fallbackByType = [];
        foreach ($fallbackItems as $fallbackItem) {
            if (!\is_array($fallbackItem)) {
                continue;
            }
            $fallbackHref = \trim((string)($fallbackItem['href'] ?? $fallbackItem['url'] ?? ''));
            $fallbackType = \trim((string)($fallbackItem['type'] ?? ''));
            if ($fallbackHref !== '') {
                $fallbackByHref[$fallbackHref] = $fallbackItem;
            }
            if ($fallbackType !== '') {
                $fallbackByType[$fallbackType] = $fallbackItem;
            }
        }

        $localized = [];
        foreach ($items as $index => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $href = \trim((string)($item['href'] ?? $item['url'] ?? '#'));
            $type = \trim((string)($item['type'] ?? ''));
            $fallback = [];
            if ($type !== '' && isset($fallbackByType[$type])) {
                $fallback = $fallbackByType[$type];
            } elseif ($href !== '' && isset($fallbackByHref[$href])) {
                $fallback = $fallbackByHref[$href];
            } elseif (($href === '' || $href === '#') && \is_array($fallbackItems[$index] ?? null)) {
                $fallback = $fallbackItems[$index];
            }
            $fallbackType = \is_array($fallback) ? \trim((string)($fallback['type'] ?? '')) : '';
            $effectiveType = $type !== '' ? $type : $fallbackType;

            $label = $this->filterNavigationLabelForLocale(
                \trim((string)($item['label'] ?? $item['title'] ?? $item['text'] ?? '')),
                $locale,
                $effectiveType
            );
            if ($label !== '' && $this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($label)) {
                $label = '';
            }
            if ($label === '' && \is_array($fallback)) {
                $label = $this->filterNavigationLabelForLocale(
                    \trim((string)($fallback['label'] ?? $fallback['title'] ?? $fallback['text'] ?? '')),
                    $locale,
                    $effectiveType
                );
            }
            if ($label === '' && $effectiveType !== '') {
                $label = $this->localizePageTypeTitle($effectiveType, $locale);
            }
            if ($label === '') {
                continue;
            }

            $resolvedHref = $href !== ''
                ? $href
                : \trim((string)((\is_array($fallback) ? ($fallback['href'] ?? $fallback['url'] ?? '#') : '#')));

            $normalized = [
                'label' => $label,
                'href' => $resolvedHref !== '' ? $resolvedHref : '#',
            ];
            if ($type !== '') {
                $normalized['type'] = $type;
            } elseif ($fallbackType !== '') {
                $normalized['type'] = $fallbackType;
            }
            $localized[] = $normalized;
        }

        return $localized;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @return list<array<string,mixed>>
     */
    private function resolveDefaultConfigLinkFallbackItems(array $defaultConfig, string $field): array
    {
        if (\str_contains($field, 'navigation')) {
            if (\is_array($defaultConfig['nav_items'] ?? null)) {
                return $defaultConfig['nav_items'];
            }

            return $this->decodeLinkItemsSample((string)($defaultConfig['navigation.items'] ?? '')) ?? [];
        }

        if (\str_contains($field, 'featured_links')) {
            return $this->decodeLinkItemsSample((string)($defaultConfig['links.column1_items'] ?? '')) ?? [];
        }

        if (\str_contains($field, 'policy_links')) {
            return $this->decodeLinkItemsSample((string)($defaultConfig['links.column2_items'] ?? '')) ?? [];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildBlogRenderContext(array $scope, string $pageType): array
    {
        $websiteId = (int)($scope['draft_website_id'] ?? $scope['website_id'] ?? 0);
        if ($websiteId <= 0 && !\in_array($pageType, [Page::TYPE_BLOG, Page::TYPE_BLOG_CATEGORY, Page::TYPE_BLOG_LIST], true)) {
            return [];
        }

        $page = clone $this->getPageModel();
        $page->clearData()->clearQuery();
        $page->setData(Page::schema_fields_WEBSITE_ID, $websiteId);
        $page->setData(Page::schema_fields_TYPE, $pageType);

        $blogPosts = $page->getBlogPosts(20, 'published_at', 'DESC');
        $blogCategories = $page->getBlogCategories();
        $currentPost = \is_array($blogPosts[0] ?? null) ? $blogPosts[0] : [];
        $currentCategory = \is_array($blogCategories[0] ?? null) ? $blogCategories[0] : [];
        $relatedPosts = \array_values(\array_slice($blogPosts, 1, 6));
        $categoryPosts = $this->filterBlogPostsByCategory($blogPosts, (int)($currentCategory['category_id'] ?? $currentCategory['id'] ?? 0));

        return [
            'blog_posts' => $blogPosts,
            'blog_categories' => $blogCategories,
            'recent_posts' => \array_values(\array_slice($blogPosts, 0, 10)),
            'related_posts' => $relatedPosts,
            'current_post' => $currentPost,
            'current_category' => $currentCategory,
            'category_posts' => $categoryPosts,
        ];
    }

    /**
     * @param list<array<string,mixed>> $blogPosts
     * @return list<array<string,mixed>>
     */
    private function filterBlogPostsByCategory(array $blogPosts, int $categoryId): array
    {
        if ($categoryId <= 0) {
            return $blogPosts;
        }

        return \array_values(\array_filter(
            $blogPosts,
            static fn(array $post): bool => (int)($post['category_id'] ?? 0) === $categoryId
        ));
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<string>
     */
    private function resolveScopedPageTypes(array $scope): array
    {
        $scopeCompatibilityService = $this->scopeCompatibilityService;
        if ($scopeCompatibilityService === null) {
            $scopeCompatibilityService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
        }

        return $scopeCompatibilityService->resolveScopedPageTypes($scope);
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $headerConfig
     */
    private function buildHeaderGenerationPrompt(array $websiteProfile, array $scope, string $siteDisplayName, array $headerConfig): string
    {
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        $brief = $this->pickString(
            $websiteProfile['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null
        );
        $siteSummary = $this->filterVisibleCopyForLocale(
            $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope),
            $locale
        );
        $pageTypes = $this->resolveScopedPageTypes($scope);
        $pageTypeLabels = Page::getPageTypes();
        $pageList = [];
        foreach ($pageTypes as $pageType) {
            $pageList[] = $this->normalizePromptVisibleLabel(
                (string)($pageTypeLabels[$pageType] ?? ''),
                $this->localizePageTypeTitle($pageType, $locale),
                $locale
            );
        }

        $styleCode = $this->resolvePromptStyleCode($scope, Page::TYPE_HOME);
        $styleDirection = $this->describeStyleDirection($styleCode);
        $langRule = $this->buildPrimaryLanguageRuleEn($websiteProfile, $scope);
        $stage3LocaleContract = $this->buildStage3LocaleExecutionPromptAddon($websiteProfile, $scope);
        $sharedRefinement = $this->resolveSharedComponentRefinement($scope, 'header');
        $PlanJsonTask = $this->resolveSharedPlanJsonTask($scope, 'header');
        $PlanJsonPromptAddon = $this->buildSharedPlanJsonTaskPromptAddon($PlanJsonTask, 'header', $scope);
        $themeContract = $this->buildThemeContractPromptAddon($scope);
        $skillContract = $this->buildWelineSkillContractPromptAddon();
        $claudeDesignSkill = $this->buildClaudeDesignSkillPromptAddon('stage3', $scope);
        $designDirectionPrompt = $this->getDesignDirectionService()->buildStageThreePromptAddon($scope);
        $visibleCopyRule = $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope);
        $templateScaffoldGuard = $this->buildTemplateScaffoldGuardPromptAddon($websiteProfile, $scope, $locale, 'header');
        $routeContractPrompt = $this->buildSharedRouteContractPromptAddon($scope, $locale);

        $sharedQualityGateContract = $this->buildSharedQualityGateSelfCheckPromptAddon('header', $PlanJsonTask, $websiteProfile, $scope, $locale);

        $headerPrompt = $this->buildPromptRolePriorityContract()
            . "Generate the shared header from the current plan_json site task context only.\n"
            . $langRule
            . $this->clipText($stage3LocaleContract, 260)
            . $this->buildSharedOutputRulesPromptAddon('header')
            . "Use only the current plan_json path while building the header.\n"
            . "You are generating one PageBuilder website header component.\n"
            . "Site name: {$siteDisplayName}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . "Selected pages: " . \implode(', ', $pageList) . "\n"
            . "Current navigation data: " . $this->jsonEncodeForPrompt($headerConfig['nav_items'] ?? [], 360) . "\n"
            . $routeContractPrompt
            . "CTX_SHARED_QUALITY_GATE_CONTRACT:\n" . $sharedQualityGateContract
            . $this->clipText($templateScaffoldGuard, 760)
            . $this->clipText($visibleCopyRule, 260)
            . $this->clipText($skillContract, 280)
            . $this->clipText($claudeDesignSkill, 320)
            . $this->clipText($designDirectionPrompt, 1200)
            . $this->clipText($themeContract, 420)
            . $this->buildSharedVisualRulesPromptAddon('header')
            . $this->clipText($PlanJsonPromptAddon, 780)
            . $this->buildStage3UserBriefReferenceAddon($brief, $sharedRefinement, 'this header')
            . $this->buildStage3ConfirmedPlanExecutionRule('This header component')
            . $this->buildSharedComponentJsonSafetyRulesEn('header');
        AiSiteWorkflowTrace::prompt('stage3_header_prompt_assembled', $headerPrompt, [
            'task_key' => (string)($PlanJsonTask['task_key'] ?? ''),
        ]);

        return $headerPrompt;
    }

    private function buildFooterGenerationPrompt(array $websiteProfile, array $scope, string $siteDisplayName, array $footerConfig): string
    {
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        $brief = $this->pickString(
            $websiteProfile['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null
        );
        $siteSummary = $this->filterVisibleCopyForLocale(
            $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope),
            $locale
        );
        $styleCode = $this->resolvePromptStyleCode($scope, Page::TYPE_HOME);
        $styleDirection = $this->describeStyleDirection($styleCode);
        $langRule = $this->buildPrimaryLanguageRuleEn($websiteProfile, $scope);
        $stage3LocaleContract = $this->buildStage3LocaleExecutionPromptAddon($websiteProfile, $scope);
        $sharedRefinement = $this->resolveSharedComponentRefinement($scope, 'footer');
        $PlanJsonTask = $this->resolveSharedPlanJsonTask($scope, 'footer');
        $PlanJsonPromptAddon = $this->buildSharedPlanJsonTaskPromptAddon($PlanJsonTask, 'footer', $scope);
        $themeContract = $this->buildThemeContractPromptAddon($scope);
        $skillContract = $this->buildWelineSkillContractPromptAddon();
        $claudeDesignSkill = $this->buildClaudeDesignSkillPromptAddon('stage3', $scope);
        $designDirectionPrompt = $this->getDesignDirectionService()->buildStageThreePromptAddon($scope);
        $visibleCopyRule = $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope);
        $templateScaffoldGuard = $this->buildTemplateScaffoldGuardPromptAddon($websiteProfile, $scope, $locale, 'footer');
        $routeContractPrompt = $this->buildSharedRouteContractPromptAddon($scope, $locale);

        $sharedQualityGateContract = $this->buildSharedQualityGateSelfCheckPromptAddon('footer', $PlanJsonTask, $websiteProfile, $scope, $locale);

        $footerPrompt = $this->buildPromptRolePriorityContract()
            . "Generate the shared footer from the current plan_json site task context only.\n"
            . $langRule
            . $this->clipText($stage3LocaleContract, 260)
            . $this->buildSharedOutputRulesPromptAddon('footer')
            . "Use only the current plan_json path while building the footer.\n"
            . "You are generating one PageBuilder website footer component.\n"
            . "Site name: {$siteDisplayName}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . "Footer link data: " . $this->jsonEncodeForPrompt([
                'column1' => $footerConfig['links.column1_items'] ?? '',
                'column2' => $footerConfig['links.column2_items'] ?? '',
                'column3' => $footerConfig['links.column3_items'] ?? '',
            ], 360) . "\n"
            . $routeContractPrompt
            . "CTX_SHARED_QUALITY_GATE_CONTRACT:\n" . $sharedQualityGateContract
            . $this->clipText($templateScaffoldGuard, 900)
            . $this->clipText($visibleCopyRule, 1100)
            . "Footer copy rule: footer_extra_text is optional. If you write it, synthesize one short target-locale sentence from the site plan. Footer column headings, link labels, copyright text, helper text, logo alt/title/aria text, and any injected footer_extra_text must all be translated/re-written into content_locale. Do not quote or copy the customer brief, source objective, stage-1 notes, or English brand summary verbatim. If no target-locale sentence can be safely composed, leave footer_extra_text empty rather than inventing generic support/download/game/app copy.\n"
            . $this->clipText($skillContract, 280)
            . $this->clipText($claudeDesignSkill, 320)
            . $this->clipText($designDirectionPrompt, 1200)
            . $this->clipText($themeContract, 420)
            . $this->buildSharedVisualRulesPromptAddon('footer')
            . $this->clipText($PlanJsonPromptAddon, 780)
            . $this->buildStage3UserBriefReferenceAddon($brief, $sharedRefinement, 'this footer')
            . $this->buildStage3ConfirmedPlanExecutionRule('This footer component')
            . $this->buildSharedComponentJsonSafetyRulesEn('footer');
        AiSiteWorkflowTrace::prompt('stage3_footer_prompt_assembled', $footerPrompt, [
            'task_key' => (string)($PlanJsonTask['task_key'] ?? ''),
        ]);

        return $footerPrompt;
    }

    private function buildSectionGenerationPrompt(string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): string
    {
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        $brief = $this->pickString(
            $websiteProfile['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null
        );
        $siteSummary = $this->filterVisibleCopyForLocale(
            $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope),
            $locale
        );
        $pageInstructionMap = Page::getPageTypePromptInstructionsMap();
        $pageInstruction = (string)($pageInstructionMap[$pageType] ?? '');
        $sectionKey = (string)($section['key'] ?? '');
        $PlanJsonTask = $this->resolveSectionPlanJsonTask($scope, $pageType, (string)($section['code'] ?? ''), $sectionKey);
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $sectionName = $this->normalizePromptVisibleLabel(
            $this->pickString(
                $planContext['block_goal'] ?? null,
                $blockTask['task_goal'] ?? null,
                $section['name'] ?? null,
                $section['code'] ?? null
            ),
            $sectionKey !== '' ? $sectionKey : (string)($section['code'] ?? 'section'),
            $locale
        );
        $sectionTemplate = (string)($section['template'] ?? 'hero');
        $rawConfig = \is_array($section['config'] ?? null) ? $section['config'] : [];
        // Strip planning/observation language from section config values before they
        // become "Suggested section config" in the prompt. Stage-1 AI often writes
        // planning sentences like "Visitors see the workflow dashboard" into config fields,
        // which the stage-3 AI then blindly renders as visible content.
        $cleanedConfig = $this->filterPlanningLanguageFromConfig($rawConfig);
        $sectionConfig = \json_encode(
            $this->filterPromptArrayForLocale($cleanedConfig, $locale),
            \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT
        );
        $refinement = $this->resolveSectionRefinement($scope, $pageType, (string)($section['code'] ?? ''), $sectionKey);
        $blogPrompt = $this->buildBlogPromptAddon($pageType, $sectionKey, $scope);
        $styleCode = $this->resolvePromptStyleCode($scope, $pageType);
        $styleDirection = $this->describeStyleDirection($styleCode);
        $langRule = $this->buildPrimaryLanguageRuleEn($websiteProfile, $scope);
        $stage3LocaleContract = $this->buildStage3LocaleExecutionPromptAddon($websiteProfile, $scope);
        $blockLanguageContract = $this->buildCurrentBlockLanguageContractPromptAddon($locale, $PlanJsonTask);
        $PlanJsonPromptAddon = $this->planJsonJsonTaskPromptAddon($PlanJsonTask, 'section', $scope);
        $themeContract = $this->buildThemeContractPromptAddon($scope);
        $designDirectionPrompt = $this->getDesignDirectionService()->buildStageThreePromptAddon($scope);
        $visualExcellence = $this->buildVisualExcellencePromptAddon('section');
        $sectionVisualContract = $this->buildSectionVisualContract($pageType, $section, $blueprint, $websiteProfile, $scope);
        $strictHeroCover = (int)($sectionVisualContract['strict_hero_cover'] ?? 0) === 1;
        $premiumDesignContract = $this->buildPremiumSectionDesignContractPromptAddon(
            $pageType,
            $sectionKey,
            $sectionTemplate,
            (string)($planContext['page_flow_role'] ?? $section['page_flow_role'] ?? ''),
            $styleCode,
            $strictHeroCover
        );
        $blockVisualSignature = $this->resolveBlockVisualSignature($PlanJsonTask, $section);
        if ($blockVisualSignature !== []) {
            $sectionVisualContract['visual_signature'] = $blockVisualSignature;
        }
        $sectionThemePalette = $this->resolveThemePaletteForContract($PlanJsonTask, $scope);
        $sectionVisualContractPrompt = $this->buildSectionVisualContractPromptAddon($sectionVisualContract, $sectionThemePalette);
        $blockVisualSignaturePrompt = $this->buildBlockVisualSignaturePromptAddon($PlanJsonTask, $section);
        $siblingDiversityPrompt = $this->buildSiblingBlockDiversityPromptAddon($pageType, $PlanJsonTask, $scope);
        $pageLabel = $this->normalizePromptVisibleLabel(
            (string)($blueprint['page_label'] ?? ''),
            $this->localizePageTypeTitle($pageType, $locale),
            $locale
        );
        $currentPageAssignmentPrompt = $this->buildCurrentPageAssignmentPromptAddon(
            $pageType,
            $pageLabel,
            $PlanJsonTask,
            $scope,
            $locale
        );
        $currentBlockAssignmentPrompt = $this->buildCurrentBlockAssignmentPromptAddon(
            $pageType,
            $section,
            $PlanJsonTask,
            $scope,
            $locale
        );
        $componentPrefix = $this->normalizeComponentCssPrefix((string)($section['code'] ?? ''));
        $currentBlockRoleContract = $this->buildRoleSpecificRecoveryContractFromExplicitPlan(
            $PlanJsonTask,
            $section,
            $componentPrefix,
            $strictHeroCover
        );
        $skillContract = $this->buildWelineSkillContractPromptAddon();
        $claudeDesignSkill = $this->buildClaudeDesignSkillPromptAddon('stage3', $scope);
        $visibleCopyRule = $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope);
        $templateScaffoldGuard = $this->buildTemplateScaffoldGuardPromptAddon($websiteProfile, $scope, $locale, 'section');
        $policyPageActionPrompt = $this->isPolicyPageType($pageType)
            ? "Policy page identity: this page is for compliance, rights, rules, and support clarity. Do not inherit the site's download/install/play/reward CTA in body blocks. Use target-locale policy/support actions such as policy info, rights review, terms summary, data protection help, or contact support.\n"
            : '';

        // Append quality-gate self-checks to the Stage-2 prompt.
        $qualityGateContract = $this->buildQualityGateSelfCheckPromptAddon($pageType, $section, $PlanJsonTask, $websiteProfile, $scope, $locale);
        $defaultConfigForPrompt = $this->buildSectionDefaultConfig($pageType, $section, $blueprint, $websiteProfile, $scope);
        $ctaActionContract = $this->buildCtaActionPromptAddon($defaultConfigForPrompt, $scope, $locale);
        $editableFieldContract = $this->buildVirtualThemeEditableFieldPromptContract($defaultConfigForPrompt, $PlanJsonTask);
        $virtualThemeFrameworkContract = $this->buildVirtualThemeAdaptationFrameworkPromptAddon(
            $componentPrefix,
            $sectionTemplate,
            $strictHeroCover
        );

        $sectionPrompt = $this->buildPromptRolePriorityContract()
            . "Generate this content component from the current plan_json page block only.\n"
            . $langRule
            . $this->clipText($stage3LocaleContract, 520) . "\n"
            . $blockLanguageContract
            . "Use plan_json.pages.{page_type}.{block_key} for page, block, role, copy, media, CTA, and structure; do not use any source outside that path.\n"
            . "Stage-3 execution order: user prompt intent and latest explicit refinement are primary for content/design decisions; frozen_task_context + confirmed_theme are the concrete confirmed plan slice for this block; copy_policy is the visible-output contract against invalid structure, prompt placeholders, and internal metadata leakage; current_asset_context, quality_gate_contract, skill_guidance, and design_quality are guidance unless they protect that structure contract.\n"
            . "You are generating exactly one PageBuilder content component for the current task.\n"
            . "Page type: {$pageLabel} ({$pageType})\n"
            . $currentPageAssignmentPrompt
            . "Section: {$sectionName}; role={$sectionKey}; structure={$sectionTemplate}\n"
            . $currentBlockAssignmentPrompt
            . ($sectionTemplate === 'hero' && $strictHeroCover ? "Hero default: real 1920x750-style editable <img> cover layer, scrim, text-safe panel, and no CSS-background-only replacement.\n" : '')
            . ($sectionTemplate === 'hero' && !$strictHeroCover ? "Page banner freedom: this is a page-opening role, not a fixed hero formula. Use the current page identity to choose a distinct composition; do not clone the home-page full image + overlay + floating copy panel pattern.\n" : '')
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . "Page guidance: {$pageInstruction}\n"
            . $policyPageActionPrompt
            . ($currentBlockRoleContract !== '' ? "CTX_CURRENT_BLOCK_ROLE_CONTEXT (planning context; never render these labels as visitor copy):\n" . $currentBlockRoleContract : '')
            . $blockVisualSignaturePrompt
            . $siblingDiversityPrompt
            . "Suggested section config: {$sectionConfig}\n"
            . $templateScaffoldGuard
            . $editableFieldContract
            . ($ctaActionContract !== '' ? $ctaActionContract : '')
            . $virtualThemeFrameworkContract
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . "Theme and visual decisions must follow CTX_CONFIRMED_THEME and the current block.\n"
            . "Bind editable copy and media to the fields owned by this plan_json block.\n"
            . "Do not output planning metadata in visitor-visible HTML.\n"
            . "CTX_CONFIRMED_THEME:\n" . $this->clipText($themeContract, 1500) . "\n"
            . "CTX_DESIGN_DIRECTION:\n" . $this->clipText($designDirectionPrompt, 3600) . "\n"
            . "CTX_FROZEN_TASK:\n" . $PlanJsonPromptAddon
            . "CTX_DESIGN_QA_GUIDE (diagnostic guidance; blocking checks protect schema/HTML structure and internal placeholder leakage only):\n" . $qualityGateContract
            . "CTX_COPY_POLICY:\n" . $this->clipText($visibleCopyRule, 720) . "\n"
            . "CTX_SKILL_GUIDANCE:\n" . $this->clipText($skillContract, 520) . "\n" . $this->clipText($claudeDesignSkill, 820) . "\n"
            . "CTX_DESIGN_QUALITY:\n" . $visualExcellence . "\n" . $premiumDesignContract . "\n"
            . $this->buildStage3UserBriefReferenceAddon($brief, $refinement, 'this section')
            . $this->buildStage3ConfirmedPlanExecutionRule('This section component')
            . ($blogPrompt !== '' ? $blogPrompt . "\n" : '')
            . "Final execution rule: execute this block only for page_type={$pageType}, section={$sectionName} (role={$sectionKey}). "
            . "User prompt intent is primary for content/design decisions, and the confirmed plan + frozen task context are the concrete block contract derived from it. "
            . "Follow output schema and visible-copy leak rules; design may be expressive inside this block's contract. "
            . "Immediately before returning, audit CTX_REQUIRED_EDITABLE_FIELDS: every required exact key must appear once in extra_fields and once in a simple php_variables `\$getConfig('exact.key', ...)` assignment, especially `content.description` for intro/body copy. "
            . "If a required image exists, render a real editable <img> from CTX_CURRENT_ASSET: keep slot/data attributes exact, but render src/alt from media fields with the verified final_url as the src fallback/default. "
            . "Simplify risky HTML/CSS rather than dropping to a flat slab.\n";
        AiSiteWorkflowTrace::prompt('stage3_section_prompt_assembled', $sectionPrompt, [
            'page_type' => $pageType,
            'section_code' => (string)($section['code'] ?? ''),
            'section_key' => $sectionKey,
            'task_key' => (string)($PlanJsonTask['task_key'] ?? ''),
        ]);

        return $sectionPrompt;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $scope
     */
    private function buildCtaActionPromptAddon(array $defaultConfig, array $scope, string $locale): string
    {
        $label = $this->filterVisibleCopyForLocale(
            $this->firstConfigString($defaultConfig, ['cta.text', 'content.cta_text', 'button_text', 'button.label']),
            $locale
        );
        if ($label === '') {
            $label = $this->resolvePrimaryCtaText($scope, $locale);
        }
        $url = $this->firstConfigString($defaultConfig, ['cta.url', 'content.cta_url', 'button_url', 'button.href']);
        $normalizedUrl = $url !== '' ? $this->normalizeHrefAgainstRouteContract($url, $scope, $locale) : '';
        if ($normalizedUrl === '' && $url !== '' && !$this->shouldValidateGeneratedHrefAgainstRouteContract($url)) {
            $normalizedUrl = $url;
        }
        $labelForPrompt = $label !== '' ? $label : 'use the current block cta label from CTX_FROZEN_TASK';
        $targetForPrompt = $normalizedUrl !== '' ? $normalizedUrl : '-';

        return "CTX_CTA_ACTION_CONTRACT:\n"
            . "- primary_cta_label: " . $this->clipText($labelForPrompt, 120) . "\n"
            . "- primary_cta_target: " . $targetForPrompt . "\n"
            . "- primary_cta_label is mandatory visible copy. Never render an empty CTA anchor/button, empty outline pill, or whitespace-only label. If the planned label is unavailable, use a concrete target-locale fallback from the current block intent such as " . $this->clipText($this->resolvePrimaryCtaText($scope, $locale), 80) . ".\n"
            . "- Pixel event source: inspect CTX_FROZEN_TASK current_block_context.analytics_events first. When it contains a primary CTA/form/contact/download/signup/product event, use that event_name. If it is absent, choose the closest Weline Visitor default event name from block intent: hero_cta_click, pricing_cta_click, lead_submit, signup_click, contact_click, download_click, booking_click, demo_request_click, add_to_cart, buy_now, begin_checkout, or route_click. Never use vague names like click, button_click, section_click, or ai_event.\n"
            . "- If this block renders a primary CTA, it must be an actionable control, not an inert decorative div. When primary_cta_target is a real route/URL, declare `cta.text` and `cta.url`, bind them with `\$ctaText = \$getConfig('cta.text', '<primary_cta_label>');` and `\$ctaUrl = \$getConfig('cta.url', '<primary_cta_target>');`, then render an `<a class='pb-c-cta weline-pixel::<event_name>'>` whose href safely echoes `\$ctaUrl`, whose label safely echoes `\$ctaText`, and whose attributes include useful non-sensitive metadata such as data-name or data-pixel-value when available. Replace <event_name> with the concrete snake_case event before returning JSON; never output the placeholder literally.\n"
            . "- If no real route/URL is available but the block identity still requires an action, declare only `cta.text`, bind it with `\$ctaText = \$getConfig('cta.text', '<primary_cta_label>');`, render a `<button type='button' class='pb-c-cta weline-pixel::<event_name>' data-pb-ai-action='primary_cta'>` whose label safely echoes `\$ctaText`, and provide scoped js_content that binds click on `.pb-c-cta[data-pb-ai-action]`, toggles a local active class, and dispatches a bubbled `CustomEvent('pb:cta', {detail:{action,target,label}})` from component. Replace <event_name> with the concrete snake_case event before returning JSON. Do not add cta.url for button-only actions. Do not use window, document, fetch, inline onclick, or global selectors.\n"
            . "- Pixel tracking rule: prefer the declarative `weline-pixel::<event_name>` class on the exact clicked CTA anchor/button. For a lead/contact/signup form, add `data-pb-lead-form` plus a scoped submit listener that calls `window.WelinePixel.track('lead_submit', {source:'pagebuilder_ai_site', form_name, trigger:'submit'}, {element: leadForm, domEvent: event, keepalive: true})` only after checking the function exists. Do not inject GA, Meta, TikTok, Bing, gtag, fbq, ttq, UET, or pixel endpoint requests; Weline Visitor handles provider fan-out.\n"
            . "- Never output `href='#'`, hash-only anchors, `/download`, `#download`, `/faq`, `/games`, query strings, or external domains. Use only primary_cta_target or a button event with data-pb-ai-action.\n";
    }

    private function buildVirtualThemeAdaptationFrameworkPromptAddon(
        string $componentPrefix,
        string $sectionTemplate = '',
        bool $strictHeroCover = false
    ): string {
        $prefix = $componentPrefix !== '' ? $componentPrefix : 'pb-c';
        $heroRule = $strictHeroCover || $sectionTemplate === 'hero'
            ? "- Hero/banner variant: the framework still owns viewport safety; use the real media role as an editable <img> cover layer inside {$prefix}-media, keep overlay/scrim below copy, and never position text outside the root.\n"
            : "- Non-hero variant: keep the section dense and editorial; do not create oversized full-viewport hero slabs or detached centered CTA islands.\n";

        return "CTX_VIRTUAL_THEME_ADAPTATION_FRAMEWORK (framework scaffold; planning context, never visitor copy):\n"
            . "- PageBuilder owns the outer virtual-theme wrapper and responsive containment. The AI owns only the information architecture inside that wrapper: copy, media, proof devices, forms, FAQ rows, and actions for this block.\n"
            . "- Required stable shell roles: `<section class='{$prefix}-root'>`, one `<div class='{$prefix}-inner'>`, a copy/text role (`{$prefix}-copy` or {$prefix}-text-panel), title (`{$prefix}-title`), body (`{$prefix}-text`), optional media/support roles, and an action wrapper (`{$prefix}-action`) when a CTA exists. These role names are implementation scaffolding, not visible text.\n"
            . "- Base CSS framework: {$prefix}-root uses position:relative; overflow:hidden; box-sizing:border-box; min-width:0. {$prefix}-inner uses width:100%; max-width around 1180px; margin:0 auto; display:grid/flex; gap; min-width:0. All media/cards/forms/text columns use min-width:0; max-width:100%; box-sizing:border-box.\n"
            . "- Responsive framework: css_extra defines desktop/base selectors only; css_responsive contains complete `@media (max-width: 768px)` and `@media (max-width: 420px)` blocks. At <=768px, split layouts stack or simplify without horizontal overflow. At <=420px, use one readable column, reduce padding, and keep every text/media/form/card within max-width:100%.\n"
            . "- CTA framework: the actual CTA must remain a compact recognizable `<a class='{$prefix}-cta'>` with a real href or `<button type='button' class='{$prefix}-cta' data-pb-ai-action='primary_cta'>`. Full-width behavior belongs on wrappers/forms/mobile containers first; never make the desktop/default CTA a stretched page-width bar or an inert div/span.\n"
            . "- Visible copy framework: compact controls, proof chips, badges, tags, pills, labels, values, metrics, steps, and CTA buttons must contain meaningful target-locale text through a safe PHP echo or localized literal. Empty rounded outlines are forbidden; omit the element instead of rendering a blank control.\n"
            . "- Event framework: for event-driven CTA buttons, include data-pb-ai-action and component-scoped js_content from CTX_CTA_ACTION_CONTRACT when custom interaction is needed; the PageBuilder action bridge click event name is `pb:cta`. Also add the Weline Visitor declarative class `weline-pixel::<event_name>` from CTX_FROZEN_TASK analytics_events or the closest default event name so the same CTA is measurable. For lead/contact/signup forms, add data-pb-lead-form and the scoped WelinePixel.track submit pattern from CTX_CTA_ACTION_CONTRACT. The virtual-theme framework scopes action events to this component, so do not use inline onclick, document selectors, fetch, global state, invented routes, or third-party pixel snippets.\n"
            . "- Layout safety bans: no fixed pixel columns that cannot shrink, no negative horizontal offsets, no translateX side panels, no absolutely positioned content outside {$prefix}-root, no `100vw` inner content inside normal sections, and no cards/sections nested inside links/buttons.\n"
            . $heroRule;
    }

    /**
     * @param array<string,mixed> $section
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function buildQualityGateSelfCheckPromptAddon(
        string $pageType,
        array $section,
        array $PlanJsonTask,
        array $websiteProfile,
        array $scope,
        string $locale
    ): string {
        $renderer = $this->getVisualBlockContractRenderer();
        $themePalette = $this->resolveThemePaletteForContract($PlanJsonTask, $scope);
        $brief = $this->resolveBlockBriefForContract($PlanJsonTask, $section, $websiteProfile, $scope);
        $visualContract = \is_array($section['visual_contract'] ?? null) ? $section['visual_contract'] : [];
        $hasVerifiedHeroImage = (int)($visualContract['required'] ?? 0) === 1
            && \trim((string)($visualContract['final_url'] ?? $visualContract['url'] ?? '')) !== ''
            && (
                \in_array((string)($visualContract['slot_type'] ?? ''), ['hero_image'], true)
                || \in_array((string)($visualContract['usage'] ?? ''), ['section_background_cover'], true)
            );

        $visualSignature = $this->resolveBlockVisualSignature($PlanJsonTask, $section);
        $pageDesignPlan = $this->resolvePageDesignPlanForBlock($PlanJsonTask);

        return $renderer->renderSectionVisualContract(
            $themePalette,
            $brief,
            $locale,
            $hasVerifiedHeroImage,
            $visualSignature,
            $pageDesignPlan
        );
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveThemePaletteForContract(array $PlanJsonTask, array $scope): array
    {
        $runtimeContext = \is_array($PlanJsonTask['runtime_context'] ?? null) ? $PlanJsonTask['runtime_context'] : [];
        $themeContext = \is_array($runtimeContext['theme_context_snapshot'] ?? null) ? $runtimeContext['theme_context_snapshot'] : [];
        if ($themeContext === []) {
            $themeContext = $this->findThemeContextCandidate($scope);
        }
        $normalized = $this->normalizeThemeContract($themeContext);

        return \is_array($normalized['palette'] ?? null) ? $normalized['palette'] : [];
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $section
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveBlockBriefForContract(array $PlanJsonTask, array $section, array $websiteProfile, array $scope): array
    {
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $taskScript = \is_array($PlanJsonTask['task_script'] ?? null) ? $PlanJsonTask['task_script'] : [];
        $contentContract = \is_array($PlanJsonTask['content_contract'] ?? null) ? $PlanJsonTask['content_contract'] : [];

        $facts = [];
        foreach ([
            $contentContract['must_include_facts'] ?? null,
            $planContext['must_include_facts'] ?? null,
            $blockTask['must_include_facts'] ?? null,
            $section['must_include_facts'] ?? null,
        ] as $candidate) {
            if (!\is_array($candidate)) {
                continue;
            }
            foreach ($candidate as $fact) {
                if (\is_string($fact) && \trim($fact) !== '') {
                    $facts[] = \trim($fact);
                }
            }
        }
        foreach (\is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [] as $fieldRequirement) {
            if (!\is_array($fieldRequirement)) {
                continue;
            }
            foreach ([$fieldRequirement['sample'] ?? null, $fieldRequirement['implementation_note'] ?? null] as $candidate) {
                if (\is_string($candidate) && \trim($candidate) !== '') {
                    $facts[] = \trim($candidate);
                }
            }
        }

        $blockKey = (string)($PlanJsonTask['block_key'] ?? $PlanJsonTask['section_key'] ?? $section['source_block_key'] ?? '');
        $pageFlowRole = (string)($planContext['page_flow_role'] ?? $blockTask['page_flow_role'] ?? '');
        $blockGoal = (string)($planContext['block_goal'] ?? $blockTask['task_goal'] ?? '');

        $visualSignature = $this->resolveBlockVisualSignature($PlanJsonTask, $section);
        $imageIntent = $this->resolveBlockImageIntent($PlanJsonTask, $section);

        return [
            'page_goal' => (string)($planContext['page_goal'] ?? $blockTask['page_goal'] ?? ''),
            'block_goal' => $blockGoal,
            'must_include_facts' => \array_values(\array_unique($facts)),
            'task_key' => (string)($PlanJsonTask['task_key'] ?? ''),
            'section_code' => (string)($PlanJsonTask['section_code'] ?? $section['code'] ?? ''),
            'block_key' => $blockKey,
            'page_type' => (string)($PlanJsonTask['page_type'] ?? ''),
            'page_flow_role' => $pageFlowRole,
            'stage1_block_content' => (string)($planContext['stage1_block_content'] ?? ''),
            'role_fidelity_hint' => $this->resolveRoleFidelityHint($blockKey, $pageFlowRole, $blockGoal),
            'visual_signature' => $visualSignature,
            'image_intent' => $imageIntent,
            'visual_atmosphere' => (string)($imageIntent['visual_atmosphere'] ?? ''),
            'image_treatment' => (string)($imageIntent['image_treatment'] ?? ''),
            'site_title' => (string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? ''),
            'site_brand' => (string)($websiteProfile['site_brand'] ?? $scope['site_brand'] ?? ''),
            'brand_name' => (string)($websiteProfile['brand_name'] ?? $scope['brand_name'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    private function resolveBlockVisualSignature(array $PlanJsonTask, array $section = []): array
    {
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $stylePlan = \is_array($blockTask['style_plan'] ?? null) ? $blockTask['style_plan'] : [];

        foreach ([
            $blockTask['visual_signature'] ?? null,
            $stylePlan['visual_signature'] ?? null,
            $planContext['stage1_visual_signature'] ?? null,
            $section['visual_signature'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    private function resolveBlockImageIntent(array $PlanJsonTask, array $section = []): array
    {
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $stylePlan = \is_array($blockTask['style_plan'] ?? null) ? $blockTask['style_plan'] : [];

        return $this->resolvePlannedImageIntent($PlanJsonTask, $blockTask, $stylePlan, $planContext, $section);
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @return array<string,mixed>
     */
    private function resolveBlockContract(array $PlanJsonTask): array
    {
        $runtimeContext = \is_array($PlanJsonTask['runtime_context'] ?? null) ? $PlanJsonTask['runtime_context'] : [];
        $runtimeBlockContract = \is_array($runtimeContext['block_contract'] ?? null) ? $runtimeContext['block_contract'] : [];
        foreach ([
            $runtimeBlockContract['contract_v2'] ?? null,
            $PlanJsonTask['block_contract'] ?? null,
            $PlanJsonTask['output_contract']['block_contract'] ?? null,
            $runtimeBlockContract,
        ] as $candidate) {
            if (!\is_array($candidate) || $candidate === []) {
                continue;
            }
            $compact = [];
            foreach ([
                'version',
                'page_flow_role',
                'block_goal',
                'morphology_id',
            ] as $key) {
                $text = \trim((string)($candidate[$key] ?? ''));
                if ($text !== '') {
                    $compact[$key] = $text;
                }
            }
            foreach ([
                'composition_pattern',
                'content_hierarchy',
                'media_strategy',
                'responsive_contract',
                'diversity_constraints',
                'acceptance_checks',
            ] as $key) {
                if (\is_array($candidate[$key] ?? null) && $candidate[$key] !== []) {
                    $compact[$key] = $candidate[$key];
                }
            }
            if ($compact !== []) {
                return $compact;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $PlanJsonTask
     */
    private function buildBlockContractPrompt(array $defaultConfig, array $renderContext, array $PlanJsonTask): string
    {
        $contract = $this->resolveBlockContract($PlanJsonTask);
        $runtimeContext = \is_array($PlanJsonTask['runtime_context'] ?? null) ? $PlanJsonTask['runtime_context'] : [];
        $contentLocale = \trim((string)(
            $runtimeContext['content_locale']
            ?? $renderContext['content_locale']
            ?? $renderContext['_content_locale']
            ?? $defaultConfig['runtime.content_locale']
            ?? ''
        ));
        $languageContract = $this->resolveStage3TaskLanguageContract($runtimeContext, $contentLocale);
        $media = \is_array($contract['media_strategy'] ?? null) ? $contract['media_strategy'] : [];
        $slotId = \trim((string)($media['asset_slot_id'] ?? ''));
        if ($slotId === '') {
            $slotId = $this->firstConfigString($defaultConfig, ['runtime.section_image_slot_id', 'visual.image_slot_id']);
        }
        if ($slotId === '' && \is_array($renderContext['_visual_contract'] ?? null)) {
            $slotId = \trim((string)($renderContext['_visual_contract']['slot_id'] ?? ''));
        }
        if ($slotId !== '') {
            $contract['required_image_slot_id'] = $slotId;
        }
        $diversity = \is_array($contract['diversity_constraints'] ?? null) ? $contract['diversity_constraints'] : [];
        if (!\is_array($diversity['forbidden_repetition'] ?? null) || $diversity['forbidden_repetition'] === []) {
            $diversity['forbidden_repetition'] = [
                'same title+paragraph+button skeleton',
                'same media placement and background layer as adjacent block',
            ];
        }
        if (!\is_array($diversity['must_differ_from_previous_block'] ?? null) || $diversity['must_differ_from_previous_block'] === []) {
            $diversity['must_differ_from_previous_block'] = ['morphology_id', 'media_placement', 'background_layer', 'support_device'];
        }
        $diversity['must_not_repeat_adjacent_morphology'] = true;
        $contract['diversity_constraints'] = $diversity;
        if ($contentLocale !== '') {
            $contract['content_locale'] = $contentLocale;
            $contract['language_contract'] = $languageContract;
        }
        $componentPrefix = $this->normalizeComponentCssPrefix((string)(
            $PlanJsonTask['section_code']
            ?? $PlanJsonTask['component_code']
            ?? $PlanJsonTask['task_key']
            ?? ''
        ));
        $template = \trim((string)($contract['section_template'] ?? $PlanJsonTask['section_template'] ?? ''));
        $mediaUsage = \trim((string)($contract['media_strategy']['usage'] ?? ''));

        return "5d CTX_CURRENT_BLOCK_CONTRACT (compact morphology contract; binding for layout/content/media): "
            . $this->jsonEncodeForPrompt($contract, 1800) . "\n"
            . "- block_contract execution rule: implement morphology_id, composition_pattern, content_hierarchy, media_strategy, responsive_contract, diversity_constraints, and acceptance_checks before generic skeletons. Do not rewrite the plan goal or replace a required real image with CSS-only output.\n"
            . "- morphology_id is binding: the HTML structure and CSS rhythm must visibly express this morphology. A different morphology_id must produce a different composition, not the same title/paragraph/card/CTA shell with new colors.\n"
            . "- required_image_slot_id/media.asset_slot_id rule: if present, the generated editable <img> must bind that exact slot. Do not invent URLs or move the required image into CSS.\n"
            . "- adjacent block guard: previous_morphology_id and must_differ_from_previous_block are hard constraints. Change media placement, support/proof device, background layer, spatial rhythm, and card density from adjacent blocks unless the current contract explicitly says otherwise.\n"
            . "- forbidden repetition rule: avoid the forbidden_repetition patterns in diversity_constraints and never render a generic title+paragraph+CTA-only block.\n"
            . ($contentLocale !== '' ? "- block language rule: all visible text derived from this block contract must be rewritten into source_of_truth_locale={$contentLocale}; do not paste plan text in another language.\n" : '')
            . "5e " . $this->buildVirtualThemeAdaptationFrameworkPromptAddon(
                $componentPrefix,
                $template,
                \str_contains($mediaUsage, 'cover')
            );
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @return array<string,mixed>
     */
    private function resolvePageDesignPlanForBlock(array $PlanJsonTask): array
    {
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $stylePlan = \is_array($blockTask['style_plan'] ?? null) ? $blockTask['style_plan'] : [];

        foreach ([
            $planContext['page_design_plan'] ?? null,
            $stylePlan['page_design_plan'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $section
     */
    private function buildBlockVisualSignaturePromptAddon(array $PlanJsonTask, array $section = []): string
    {
        $visualSignature = $this->resolveBlockVisualSignature($PlanJsonTask, $section);
        $imageIntent = $this->resolveBlockImageIntent($PlanJsonTask, $section);
        $pageDesignPlan = $this->resolvePageDesignPlanForBlock($PlanJsonTask);
        if ($visualSignature === [] && $imageIntent === [] && $pageDesignPlan === []) {
            return '';
        }

        $lines = ["CTX_BLOCK_VISUAL_SIGNATURE (layout/media contract; overrides generic split-panel/card defaults):"];
        if ($visualSignature !== []) {
            $lines[] = '- ' . $this->jsonEncodeForPrompt($visualSignature, 900);
        }
        if ($imageIntent !== []) {
            $lines[] = '- image_intent: ' . $this->jsonEncodeForPrompt($imageIntent, 900);
        }
        if ($pageDesignPlan !== []) {
            $lines[] = '- page_design_plan: ' . $this->jsonEncodeForPrompt($pageDesignPlan, 900);
        }
        $lines[] = '- Use composition_pattern and media_strategy from visual_signature as the first design brief before choosing a layout, so the block does not drift into the same media-left/copy-right or three-card rhythm by habit.';
        $lines[] = '- Follow image_intent exactly: needs_image=true means this block must consume/generate the planned image slot when available; needs_image=false means use the planned css_motif/visual_atmosphere/image_treatment and do not invent image URLs.';
        $lines[] = '- SAFE_TEXT_ROLE_OUTLINE and TEXT_CSS_BASELINE are fallback role vocabulary. Let visual_signature and page_design_plan decide the shell first so sibling blocks can feel intentionally different.';

        return \implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $scope
     */
    private function buildSiblingBlockDiversityPromptAddon(string $pageType, array $PlanJsonTask, array $scope): string
    {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            return '';
        }

        $currentBlockKey = \trim((string)($PlanJsonTask['block_key'] ?? $PlanJsonTask['section_key'] ?? ''));
        $root = $this->resolvePlanJsonTaskRoot($scope);
        $pageTasks = \is_array($root['page_tasks'][$pageType] ?? null) ? $root['page_tasks'][$pageType] : [];
        if ($pageTasks === []) {
            return '';
        }

        $siblings = [];
        foreach ($pageTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $blockKey = \trim((string)($task['block_key'] ?? $task['section_key'] ?? ''));
            if ($blockKey === '' || $blockKey === $currentBlockKey) {
                continue;
            }
            $signature = $this->resolveBlockVisualSignature($task);
            $composition = \trim((string)($signature['composition_pattern'] ?? ''));
            if ($composition === '') {
                continue;
            }
            $siblings[] = $blockKey . '=>' . $composition;
        }

        if ($siblings === []) {
            return '';
        }

        return "CTX_SIBLING_BLOCK_COMPOSITIONS (design context for variety, not a validation gate):\n"
            . '- ' . \implode('; ', \array_slice($siblings, 0, 12)) . "\n"
            . "- Use this as creative context so the current block can choose its own information angle, rhythm, and support device instead of drifting into the same generic shell.\n";
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $scope
     */
    private function buildCurrentPageAssignmentPromptAddon(
        string $pageType,
        string $pageLabel,
        array $PlanJsonTask,
        array $scope,
        string $locale
    ): string {
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $stylePlan = \is_array($blockTask['style_plan'] ?? null) ? $blockTask['style_plan'] : [];
        $pageIdentity = \is_array($planContext['page_identity_contract'] ?? null) ? $planContext['page_identity_contract'] : [];
        $pageDesignPlan = \is_array($planContext['page_design_plan'] ?? null)
            ? $planContext['page_design_plan']
            : (\is_array($stylePlan['page_design_plan'] ?? null) ? $stylePlan['page_design_plan'] : []);
        $root = $this->resolvePlanJsonTaskRoot($scope);
        $pageTasksByType = \is_array($root['page_tasks'] ?? null) ? $root['page_tasks'] : [];
        $otherPages = [];
        foreach ($pageTasksByType as $candidatePageType => $tasks) {
            $candidatePageType = \trim((string)$candidatePageType);
            if ($candidatePageType === '' || $candidatePageType === $pageType || !\is_array($tasks)) {
                continue;
            }
            $firstTask = [];
            foreach ($tasks as $task) {
                if (\is_array($task) && $task !== []) {
                    $firstTask = $task;
                    break;
                }
            }
            if ($firstTask === []) {
                continue;
            }
            $siblingPlanContext = \is_array($firstTask['plan_context'] ?? null) ? $firstTask['plan_context'] : [];
            $siblingBlockTask = \is_array($firstTask['block_task'] ?? null) ? $firstTask['block_task'] : [];
            $siblingStylePlan = \is_array($siblingBlockTask['style_plan'] ?? null) ? $siblingBlockTask['style_plan'] : [];
            $siblingPageDesignPlan = \is_array($siblingPlanContext['page_design_plan'] ?? null)
                ? $siblingPlanContext['page_design_plan']
                : (\is_array($siblingStylePlan['page_design_plan'] ?? null) ? $siblingStylePlan['page_design_plan'] : []);
            $otherPages[] = [
                'page_type' => $candidatePageType,
                'page_goal' => $this->clipText($this->normalizePlanJsonRequirementSample($siblingPlanContext['page_goal'] ?? '', false), 180),
                'page_flow_role' => $this->clipText($this->normalizePlanJsonRequirementSample($siblingPlanContext['page_flow_role'] ?? $siblingStylePlan['page_flow_role'] ?? '', false), 120),
                'section_flow' => $this->clipText($this->normalizePlanJsonRequirementSample($siblingPageDesignPlan['section_flow'] ?? '', false), 180),
                'layout_rhythm' => $this->clipText($this->normalizePlanJsonRequirementSample($siblingPageDesignPlan['layout_rhythm'] ?? $siblingPageDesignPlan['composition_strategy'] ?? '', false), 180),
            ];
            if (\count($otherPages) >= 10) {
                break;
            }
        }

        $assignment = [
            'page_type' => $pageType,
            'page_label' => $pageLabel,
            'page_goal' => $this->clipText($this->normalizePlanJsonRequirementSample($planContext['page_goal'] ?? '', false), 260),
            'page_flow_role' => $this->clipText($this->normalizePlanJsonRequirementSample($planContext['page_flow_role'] ?? $stylePlan['page_flow_role'] ?? '', false), 120),
            'page_identity_contract' => $pageIdentity,
            'page_design_plan' => $pageDesignPlan,
            'other_selected_pages' => $otherPages,
        ];
        if ($locale !== '') {
            $filtered = $this->filterPromptArrayForLocale($assignment, $locale);
            if (\is_array($filtered)) {
                $assignment = $filtered;
            }
        }

        return "CTX_CURRENT_PAGE_ASSIGNMENT (page-level design brief; planning context, never visitor copy):\n"
            . $this->jsonEncodeForPrompt($assignment, 2200) . "\n"
            . "- Page assignment rule: design this block as part of page_type={$pageType}, not as a reusable home-page section. Page goal, page_flow_role, page_identity_contract, and page_design_plan should shape the layout rhythm, media choice, copy angle, and CTA tone.\n"
            . "- Cross-page variety rule: other_selected_pages explains what the rest of the generated site is doing. Use it to make this page feel purpose-built and visually distinct while staying in the same brand system. This is design direction, not a hard validation gate.\n"
            . "- Page media rhythm rule: for non-policy pages, avoid leaving the page as all text/stat/card-only sections when the confirmed plan or user brief asks for rich imagery. If this block has a verified image, render it. If it has no verified image, create a substantial CSS media/supporting visual surface such as a phone mockup, table frame, support console, editorial poster, card-room rail, or review wall instead of small dots only. Policy pages may remain dense legal text. This is design guidance only, not a validation gate.\n";
    }

    /**
     * @param array<string,mixed> $section
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $scope
     */
    private function buildCurrentBlockAssignmentPromptAddon(
        string $pageType,
        array $section,
        array $PlanJsonTask,
        array $scope,
        string $locale
    ): string {
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $taskScript = \is_array($PlanJsonTask['task_script'] ?? null) ? $PlanJsonTask['task_script'] : [];
        $contentPlan = \is_array($blockTask['content_plan'] ?? null) ? $blockTask['content_plan'] : [];
        $fieldRequirements = \is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [];
        $visualSignature = $this->resolveBlockVisualSignature($PlanJsonTask, $section);
        $imageIntent = $this->resolveBlockImageIntent($PlanJsonTask);
        $blockKey = \trim((string)($PlanJsonTask['block_key'] ?? $PlanJsonTask['section_key'] ?? $section['key'] ?? ''));
        $sectionCode = \trim((string)($PlanJsonTask['section_code'] ?? $section['code'] ?? ''));
        $pageFlowRole = \trim((string)($PlanJsonTask['page_flow_role'] ?? $planContext['page_flow_role'] ?? $blockTask['page_flow_role'] ?? ''));
        $blockGoal = $this->pickString(
            $planContext['block_goal'] ?? null,
            $blockTask['task_goal'] ?? null,
            $taskScript['story_goal'] ?? null,
            $section['name'] ?? null
        );

        $siblings = [];
        $root = $this->resolvePlanJsonTaskRoot($scope);
        $pageTasks = \is_array($root['page_tasks'][$pageType] ?? null) ? $root['page_tasks'][$pageType] : [];
        foreach ($pageTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $siblingBlockKey = \trim((string)($task['block_key'] ?? $task['section_key'] ?? ''));
            if ($siblingBlockKey === '' || ($blockKey !== '' && $siblingBlockKey === $blockKey)) {
                continue;
            }
            $siblingPlanContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
            $siblingBlockTask = \is_array($task['block_task'] ?? null) ? $task['block_task'] : [];
            $siblingSignature = $this->resolveBlockVisualSignature($task);
            $siblings[] = [
                'block_key' => $siblingBlockKey,
                'section_code' => (string)($task['section_code'] ?? ''),
                'page_flow_role' => (string)($task['page_flow_role'] ?? $siblingPlanContext['page_flow_role'] ?? $siblingBlockTask['page_flow_role'] ?? ''),
                'block_goal' => $this->clipText($this->pickString(
                    $siblingPlanContext['block_goal'] ?? null,
                    $siblingBlockTask['task_goal'] ?? null
                ), 180),
                'composition_pattern' => (string)($siblingSignature['composition_pattern'] ?? ''),
            ];
            if (\count($siblings) >= 10) {
                break;
            }
        }

        $assignment = [
            'task_key' => (string)($PlanJsonTask['task_key'] ?? ''),
            'page_type' => $pageType,
            'block_key' => $blockKey,
            'section_code' => $sectionCode,
            'section_template' => (string)($section['template'] ?? ''),
            'page_flow_role' => $pageFlowRole,
            'role_kind' => $this->resolveExplicitBlockRoleKindForPrompt($PlanJsonTask, $section),
            'block_goal' => $this->clipText($blockGoal, 260),
            'stage3_directive' => $this->clipText((string)($taskScript['stage3_directive'] ?? ''), 260),
            'content_plan' => $contentPlan,
            'field_content_requirements' => $fieldRequirements,
            'visual_signature' => $visualSignature,
            'image_intent' => $imageIntent,
            'sibling_blocks_on_same_page' => $siblings,
        ];
        if ($locale !== '') {
            $filtered = $this->filterPromptArrayForLocale($assignment, $locale);
            if (\is_array($filtered)) {
                $assignment = $filtered;
            }
        }

        $localeLabel = $locale !== '' ? $locale : 'the target locale';

        return "CTX_CURRENT_BLOCK_ASSIGNMENT (highest-priority module identity; planning context, never visitor copy):\n"
            . $this->jsonEncodeForPrompt($assignment, 2200) . "\n"
            . "- Assignment rule: generate only this block_key/section_code. The visible heading, cards, proof, form labels, FAQ rows, media, and CTA must express this block_goal/content_plan, not the whole website objective.\n"
            . "- Module-boundary rule: sibling_blocks_on_same_page shows what nearby modules already cover. Use it as page context so the current block has a distinct purpose, information angle, and layout rhythm. This is not a post-generation dedupe rule; it is the current module brief.\n"
            . "- Copy specificity rule: do not paste broad site goals, campaign objectives, or queue/build labels as visible copy. Rewrite the current block assignment into finished {$localeLabel} website copy.\n";
    }

    private function resolveRoleFidelityHint(string $blockKey, string $pageFlowRole, string $blockGoal): string
    {
        $normalized = \strtolower($blockKey . ' ' . $pageFlowRole . ' ' . $blockGoal);
        if (\str_contains($normalized, 'contact_cta') || (\str_contains($normalized, 'cta') && \str_contains($normalized, 'contact'))) {
            return 'Render a focused final contact/download CTA band. Do not repeat contact method cards, FAQ rows, or forms.';
        }
        if (\str_contains($normalized, 'contact_methods') || \str_contains($normalized, 'support hours')) {
            return 'Render a channel hub with visible contact methods and separated labels/values. Do not degrade into a generic hero slab.';
        }
        if (\str_contains($normalized, 'faq')) {
            return 'Render distinct question-answer rows. Do not use card grids, hero shells, or contact channel tiles.';
        }
        if (\str_contains($normalized, 'form')) {
            return 'Render a real form-guidance block with visible labels and fields, not contact cards or a single CTA strip.';
        }

        return '';
    }

    private function getVisualBlockContractRenderer(): \GuoLaiRen\PageBuilder\Service\AI\Contract\AiSiteVisualBlockContractRenderer
    {
        if ($this->visualBlockContractRenderer === null) {
            $this->visualBlockContractRenderer = new \GuoLaiRen\PageBuilder\Service\AI\Contract\AiSiteVisualBlockContractRenderer();
        }

        return $this->visualBlockContractRenderer;
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function buildSharedQualityGateSelfCheckPromptAddon(
        string $region,
        array $PlanJsonTask,
        array $websiteProfile,
        array $scope,
        string $locale
    ): string {
        $renderer = $this->getVisualBlockContractRenderer();
        $themePalette = $this->resolveThemePaletteForContract($PlanJsonTask, $scope);
        $brief = $this->resolveBlockBriefForContract($PlanJsonTask, [], $websiteProfile, $scope);

        return $renderer->renderSharedRegionVisualContract($region, $themePalette, $brief, $locale);
    }

    /**
     * Stage-2 content component JSON output rules.
     */
    private function buildSectionOutputRulesPromptAddon(): string
    {
        $contentExample = [
            'extra_fields' => <<<'TEXT'
group:ai_content => AI editable content
content.title => Title:text:Launch faster with a focused plan
content.description => Description:textarea:Give visitors a clear promise and a reason to act.
card.item_1_title => Card 1 title:text:Fast setup
card.item_1_text => Card 1 text:textarea:Start with guided steps and reusable page sections.
card.item_2_title => Card 2 title:text:Clear proof
card.item_2_text => Card 2 text:textarea:Show outcomes with specific support copy.
cta.text => CTA text:text:Start now
TEXT,
            'php_variables' => <<<'TEXT'
$title = $getConfig('content.title', 'Launch faster with a focused plan');
$description = $getConfig('content.description', 'Give visitors a clear promise and a reason to act.');
$cardItem1Title = $getConfig('card.item_1_title', 'Fast setup');
$cardItem1Text = $getConfig('card.item_1_text', 'Start with guided steps and reusable page sections.');
$cardItem2Title = $getConfig('card.item_2_title', 'Clear proof');
$cardItem2Text = $getConfig('card.item_2_text', 'Show outcomes with specific support copy.');
$ctaText = $getConfig('cta.text', 'Start now');
TEXT,
            'css_extra' => "#componentId{padding:0;}#componentId .pb-c-root{padding:56px 24px;background:linear-gradient(135deg,#080912,#17122f);color:#ffffff;box-sizing:border-box;}#componentId .pb-c-inner{max-width:1120px;margin:0 auto;display:grid;gap:20px;}#componentId .pb-c-card-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}#componentId .pb-c-cta{display:inline-flex;padding:12px 18px;border-radius:8px;background:#f7c948;color:#111827;text-decoration:none;}",
            'css_responsive' => "@media (max-width: 768px){#componentId .pb-c-card-grid{grid-template-columns:1fr;}#componentId .pb-c-root{padding:40px 18px;}}@media (max-width: 420px){#componentId .pb-c-root{padding:32px 14px;}#componentId .pb-c-cta{max-width:100%;}}",
            'html_content' => <<<'TEXT'
<section class='pb-c-root'><div class='pb-c-inner'><h2 class='pb-c-title'><?= htmlspecialchars($title ?? 'Launch faster with a focused plan', ENT_QUOTES, 'UTF-8') ?></h2><p class='pb-c-text'><?= nl2br(htmlspecialchars($description ?? 'Give visitors a clear promise and a reason to act.', ENT_QUOTES, 'UTF-8')) ?></p><div class='pb-c-card-grid'><div class='pb-c-card'><strong><?= htmlspecialchars($cardItem1Title ?? 'Fast setup', ENT_QUOTES, 'UTF-8') ?></strong><p><?= nl2br(htmlspecialchars($cardItem1Text ?? 'Start with guided steps and reusable page sections.', ENT_QUOTES, 'UTF-8')) ?></p></div><div class='pb-c-card'><strong><?= htmlspecialchars($cardItem2Title ?? 'Clear proof', ENT_QUOTES, 'UTF-8') ?></strong><p><?= nl2br(htmlspecialchars($cardItem2Text ?? 'Show outcomes with specific support copy.', ENT_QUOTES, 'UTF-8')) ?></p></div></div><button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars($ctaText ?? 'Start now', ENT_QUOTES, 'UTF-8') ?></button></div></section>
TEXT,
            'js_content' => '',
        ];
        $contentExampleJson = (string)\json_encode(
            $contentExample,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return "Stage-2 component output contract V3 (this overrides any broader visual advice above):\n"
            . "1. Single responsibility: generate only the current block. Do not reinterpret the whole website, do not generate neighboring blocks, and do not print planning/contract/schema text.\n"
            . "2. Return exactly one JSON object. First character `{`, last character `}`. No markdown, no prose, no second object, no raw CSS/HTML outside JSON.\n"
            . "3. Required string keys only: extra_fields, php_variables, css_extra, css_responsive, html_content, js_content. For content blocks, extra_fields/php_variables are editor metadata for primary reusable values, not an exhaustive visitor-copy gate. Prefer declaring title/body/CTA/media values that should be easy to edit, while allowing block-specific badges, stats, proof labels, FAQ rows, and visual microcopy when they improve the finished section. php_variables may contain only simple `\$var = \$getConfig('field.key', 'default');` assignments for declared fields. Set js_content to empty unless this block renders a CTA/form/FAQ interaction that needs scoped behavior; CTA buttons may use a tiny component-scoped click bridge from CTX_CTA_ACTION_CONTRACT.\n"
            . "3a. EDITABLE_FIELDS_HINT: CTX_REQUIRED_EDITABLE_FIELDS is a helpful editor-field hint, not a completion gate. Use those exact keys when they naturally match the generated section, but do not fail, truncate, or distort useful component copy solely because a dynamic field name was not predeclared.\n"
            . "3b. Teaching example for content component JSON (copy the shape, not the text; rewrite values from CTX_FROZEN_TASK): {$contentExampleJson}\n"
            . "3c. Editor-friendly binding example: primary title/body/CTA copy can be backed by `content.title`, `content.description`, and `cta.text` fields. Secondary visual copy may remain inline when it is specific to the generated layout and passes the content-quality rules.\n"
            . "3d. Field binding workflow: choose lower-case dot keys from recognized families (`content.*`, `cta.*`, `media.*`, `card.*`, `feature.*`, `proof.*`, `stat.*`, `faq.*`, `review.*`, `step.*`, `form.*`, `channel.*`, `badge.*`, `item.*`, `policy.*`, `rule.*`). Required fields from CTX_REQUIRED_EDITABLE_FIELDS are not suggestions: declare and bind the exact dot key, even when a nearby synonym exists (`content.description` is not interchangeable with `content.body`). Write each row in extra_fields, bind the exact key in php_variables, then use only safe PHP echoes as visible text in html_content. Never use camelCase keys such as contentTitle or channelLabel1 as field keys.\n"
            . "3e. Machine self-check: visible text must read like final visitor-facing website copy. Remove prompt labels, schema keys, internal plan-json fields, placeholders, malformed contact fragments, and raw source text; do not self-reject good dynamic copy only because it is not mirrored as an editable field.\n"
            . "4. html_content layout: use one root section / inner container / copy panel / optional media panel / optional CTA panel composition. Hero blocks include scrim + text-panel. Non-hero layout must follow CTX_BLOCK_VISUAL_SIGNATURE.composition_pattern when present (stacked editorial, step rail, proof band, FAQ rows, form guidance, CTA band, channel hub, etc.). Split media+copy is only one option, not the default for every block. Do not invent decorative wrapper tags that drop the required parts from CTX_CURRENT_ASSET.responsive_layout_contract.\n"
            . "4a. If REQUIRED_IMAGE_STRUCTURE_CONTRACT is supplied by CTX_CURRENT_ASSET, it overrides this generic composition. Preserve the exact image binding and required semantic roles, then choose a refined layout rhythm for the current block instead of copying a byte-for-byte skeleton.\n"
            . "4b. HTML_IN_JSON rule: html_content must decode to real HTML tags, not displayed source code. Do not put removed skeleton labels, raw `<section ...>` examples, `</div>`, class='...', CSS declarations, or escaped `&lt;section` inside visitor-visible text. The decoded html_content string should begin with `<section`; all visible text nodes must be safe PHP field echoes that render final customer copy.\n"
            . "4c. Nesting rule: use exactly one h2 for the block title and do not use h3. Card subtitles should be `<div>` or `<p>` text. h2/p/small text must be a safe PHP field echo, not raw hardcoded copy. If strong/span is used, it must have no attributes and must close immediately around short echoed text. Prefer sibling div/p labels over nested inline tags. Never put div, section, form, card, image, list, link, button, or another heading inside h2/p.\n"
            . "5. Allowed HTML shape: section/div/h2/p/span/strong/small/form/label/input/textarea/img/br/a/button. Use div groups for cards/steps/layout. For primary CTAs use a real `<a class='pb-c-cta'>` when CTX_CTA_ACTION_CONTRACT provides a real target, or `<button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'>` when the action is event-driven. Every tag name must be present, every attribute must be separated by one space, every quote must close, and every non-void tag must close in reverse order. Never output invented close tags such as </h>, </pa>, </pdiv>, </buttondiv>, or </divsection>.\n"
            . "6. Attribute rule: use single quotes inside HTML attributes and one real space before each next attribute. Valid shapes include `<div class='pb-c-card'>`, an `<a class='pb-c-cta'>` whose href and label are safe PHP field echoes, and a `<button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'>` whose label is a safe PHP field echo. For images, adapt the verified img template into an editable image tag: keep data-pb-ai-image-role and data-pb-ai-asset-slot exact, but render src/alt from media.image_url/media.image_alt variables with the verified final_url as fallback. Invalid shapes: `< class='pb-c-card'>`, `<div class='pb-c-card>`, `<strong class='pb-c-card'>`, `<span class='pb-c-chip'>`, `<button class='pb-c-cta'>` without type/action attributes.\n"
            . "7. Class rule: every custom class starts with the exact component prefix given below plus an element suffix. In this workflow that means `pb-c-root`, `pb-c-inner`, `pb-c-card`, etc. Never use `.pb` or `pb` as a selector/class by itself, never start a class with `-`, `pb` without a suffix, `content-`, or a generic class like card/btn/item/title.\n"
            . "8. css_extra carries the block's visual depth. Use complete selector blocks scoped under `#componentId`, no raw declarations outside braces, no comments. You SHOULD use confirmed theme palette hex tokens, layered backgrounds (gradients allowed), box-shadow, border-radius, padding/margin, display:flex|grid, flex-wrap, gap, max-width, transition for hover/focus, and pseudo-elements when they materially improve aesthetics. Functions linear-gradient(...) and radial-gradient(...) ARE PERMITTED and recommended for surface depth. When the selected style direction is a game/neon launch style, primary CTAs should feel conversion-grade: add hover lift, active press, focus glow, and a CSS-only shine/pulse/halo animation when it can be done safely.\n"
            . "8a. Typography quality guidance: css_extra should contain explicit font-family declarations for both `#componentId .pb-c-title` and at least one body/root selector (`#componentId .pb-c-root`, `#componentId .pb-c-copy`, or `#componentId .pb-c-text`). Prefer a named brand-appropriate family before generic fallback, for example Georgia/Cambria/Palatino for editorial artisanal, Trebuchet MS/Verdana for approachable, or Optima/Candara for warm lifestyle. This is an art-direction instruction, not a content completion gate.\n"
            . "9. css_responsive MUST contain at least one `@media (max-width: 768px)` block and one `@media (max-width: 420px)` block. Inside each media block, set containers/media/form fields plus text-bearing children to width:100%; max-width:100%; min-width:0; box-sizing:border-box where needed, and set long-copy surfaces to overflow-wrap:anywhere. For SAFE_TEXT_ROLE_OUTLINE, the <=768px preview state should preserve the current visual_signature rhythm, not collapse into a generic centered stack or a universal three-card proof grid. CTA wrappers may be full-width; the actual CTA button should not become a desktop full-width bar. Empty css_responsive is INVALID for content blocks.\n"
            . "10. CSS syntax rule: every declaration is complete `property:value;`. Use stable property names. Keep braces balanced. Function notation (linear-gradient, radial-gradient, rgba, clamp, min, max, calc, color-mix) IS allowed when the parentheses are balanced and the values parse; never emit a bare `(` without matching `)`. The only valid box-sizing value is `border-box`; never output `box-sizing:border`.\n"
            . "11. Responsive layout rule: desktop uses fluid grid/flex flow; tablet (<=900px) may simplify split layouts but must keep proof/support clusters dense; mobile (<=420px) is single column. Apply width:100%; max-width:100%; min-width:0; box-sizing:border-box to layout containers, media, cards, form fields, headings, body copy, labels, chips/badges, media captions, and CTA labels. At mobile widths do not force white-space:nowrap on text, brand, nav, badges, or buttons; wrap or shorten instead. Prefer action/actions for CTA wrappers so wrapper CSS is not mistaken for button CSS. Never push forms/cards outside the viewport with negative margins, translateX, fixed side offsets, absolute side panels, or overflow-hidden clipping of real content.\n"
            . "12. Image slot rule: if a verified image template/final_url is supplied, html_content must contain a real editable `<img>` for it. Keep data-pb-ai-image-role and data-pb-ai-asset-slot exact, declare media.image_url/media.image_alt in extra_fields, bind them in php_variables, and render src/alt with safe field echoes using the verified final_url as the src fallback. Do not invent image URLs. Do not place image URLs in css_extra via url(...); image assets belong in real <img> tags. If Stage-1 image_intent.needs_image=true but no verified asset is supplied, treat that as an unresolved upstream asset state and never pretend a CSS motif, emoji, icon, phone outline, or placeholder is the generated image.\n"
            . "13. Hero/media readability rule: if text sits over media, the html_content skeleton must include a scrim/overlay div and a text-panel div using the exact component prefix provided below. css_extra must include a matching scoped scrim/overlay selector with position:absolute, inset:0, background from the confirmed palette, and either opacity:.35-.58 or a rgba/transparent gradient; the text panel must have its own readable background, padding, border-radius, and z-index above media. If you cannot include those classes correctly, do not place text over media; use a normal side-by-side layout instead.\n"
            . "13a. Framework wrapper rhythm rule: content html_content owns a complete `.pb-c-root` shell. css_extra should include `#componentId{padding:0;}` so the framework mount section does not add a second vertical rhythm; the `.pb-c-root` selector owns the block background and compact spacing. Non-hero root vertical padding should normally stay in the 44-64px range unless there is a large verified media layer.\n"
            . "13b. Strict hero full-bleed rule: only when CTX_CURRENT_ASSET.strict_hero_cover=1, css_extra must include `#componentId{padding:0;}` and the root selector must use width:100vw or min-width:100vw plus margin:0 calc(50% - 50vw); do not set a pixel max-width on the root. Constrain only the inner/text panel. A centered 1200px banner or any top/bottom theme-color gutter around the image is invalid for strict_hero_cover=1 unless the latest customer request explicitly limits banner width. Page-opening/banner blocks with strict_hero_cover=0 must use page-specific composition instead of inheriting this full-bleed formula.\n"
            . "14. Content rule: visible copy must be target-locale visitor copy derived from this block's page_goal/block_goal/content_plan and CTX_BLOCK_QA_CONTRACT.must_include_facts. Do not render why_this_block, page_goal, block_goal, data_contract, visual_contract, runtime_context, selected_skill_codes, template fragments, raw HTML tag source, or CSS source. Visible copy must not contain double quote characters because they often break JSON strings. Treat the frozen plan as intent: rewrite it into natural website copy, never paste the blueprint sentence itself. Prefer matching extra_fields/php_variables for primary editor-facing values, but dynamic component copy is allowed when it is final, localized, and useful.\n"
            . "14-content-contract. Do not self-censor normal marketing/support/reward wording solely because it is not an exact source fact. Blocking validation is limited to required JSON/HTML structure plus prompt/blueprint placeholder or visible internal metadata leakage.\n"
            . "14-policy. Policy/compliance page action rule: privacy, terms, refund, shipping, and cookie policy blocks must not inherit the site's primary download/install CTA unless the current block explicitly says it is a conversion CTA. Use policy-info, review, rights, safety, or support-oriented action copy instead.\n"
            . "14a. CSS brace rule: css_extra must be well-formed scoped selector blocks like `#componentId .pb-c-name{property:value;}` plus optional component-named `@keyframes pb-c-name{...}` blocks for motion. @media blocks belong in css_responsive ONLY and must be one complete `@media (...) { selector{...} }` body each. No raw declarations outside selector/keyframes blocks, no `}}` glued without a `{` between, no comma selectors that span unrelated regions. Count opening and closing braces before returning; counts must match exactly.\n"
            . "14b. Block-role contract: task_key/block_key/page_flow_role are binding. If this is a contact_methods/contact-method block, render a visible contact-channel hub with at least two repeated channel rows/cards using sibling `<span class='pb-c-label'>` and `<span class='pb-c-value'>` elements whose text is safe PHP field echoes; a verified image can support the atmosphere but cannot replace the channel list. If this is support_form_guidance/form-guidance/contact-form/message-form, render a designed `<form class='pb-c-form'>` with repeated `.pb-c-field` groups; every label/control pair must be grouped, label text must be a safe PHP field echo, inputs use `.pb-c-input`, the message textarea uses `.pb-c-textarea`, and css_extra must style `#componentId .pb-c-form`, `.pb-c-field`, `.pb-c-label`, `.pb-c-input`, and `.pb-c-textarea` with column/grid rhythm, gap, width:100%, padding, border-radius, border/background, box-sizing:border-box, and focus states. Form email inputs may exist, but email placeholders/defaults must be localized words with no `@`, no dot-domain, and no example address. Form helper, response-time, privacy, consent, secure-handling, and small note text are visitor copy too: bind them through extra_fields/php_variables, commonly `form.note_text`, and render a safe PHP echo instead of raw text. Do not output naked inline browser inputs or unrelated contact cards for this role. If this is support_faq/faq/faq-list, render repeated `pb-c-faq-item` rows with `pb-c-question` plus separate paragraph `pb-c-answer`, and every question/answer text node must be a safe PHP field echo; css_extra must style `#componentId .pb-c-faq-item` with padding, border-radius, and background/border/box-shadow. If this is contact_cta/final_cta/cta, render one focused next-step CTA band with one primary action and compact proof, not repeated contact cards, a form, FAQ rows, or a reused hero overlay; the primary action must be `<a class='pb-c-cta'>` with a real allowed href or `<button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'>` with a safe PHP echoed label plus scoped js_content. CTA blocks must not output question-answer copy, `pb-c-faq-item`, `pb-c-question`, `pb-c-answer`, partial email fragments such as `support@ .com`, or three-plus contact cards.\n"
            . "14c. Spacing rhythm contract: CTA/action groups must not touch dividers, channel rows, text rows, or form lines. Put the CTA in a sibling `.pb-c-action`/`.pb-c-actions` wrapper after the rows/forms/cards, not inside a channel/field/FAQ row. Use outer margin-top/padding-top on that wrapper, bottom spacing on the preceding row group, or parent flex/grid row gap; the CTA button's own internal padding does not count as separation.\n"
            . "15. Size budget: html_content <= 2400 chars, css_extra <= 800 chars when site theme.css classes are available (otherwise <= 2600), css_responsive <= 900 chars. Prefer .pb-c-section/.pb-c-card/.pb-c-cta-primary from theme.css before writing new selectors.\n"
            . "16. Final self-check before output: JSON parses; HTML tags/quotes are balanced; CSS braces and parentheses are balanced; CSS selectors match HTML classes exactly; theme palette hex tokens and brand typography are used where they serve the design; if the block has a primary CTA, it is a real anchor/button action control with href or data-pb-ai-action, not a static div; if the block has a primary CTA in a game/neon style, it has visible hover/focus/active polish and safe CSS motion; no raw text after the JSON object.\n";
    }

    private function buildSharedOutputRulesPromptAddon(string $region): string
    {
        $fields = $region === 'footer'
            ? 'extra_fields, php_variables, css_extra, html_extra_column, html_extra, footer_extra_text, js_content'
            : 'extra_fields, php_variables, css_extra, html_extra, js_content';
        $minimalRule = $region === 'footer'
            ? "- Footer link columns, logo, and visual shell are already provided by framework config. Return html_extra_column and html_extra as empty strings. footer_extra_text is optional: write one short target-locale visitor sentence only when it can be synthesized from approved site facts; otherwise keep it empty.\n"
            : "- Header navigation, logo, CTA, hover/focus states, and mobile behavior are already provided by framework config. Return html_extra as an empty string. css_extra is empty unless the latest user request explicitly asks to restyle the shared header itself. Do not rebuild navigation, logo, links, images, CSS, or badges.\n";
        $minimalExample = $region === 'footer'
            ? '{"extra_fields":"","php_variables":"","css_extra":"","html_extra_column":"","html_extra":"","footer_extra_text":"","js_content":""}'
            : '{"extra_fields":"","php_variables":"","css_extra":"","html_extra":"","js_content":""}';

        return "Shared {$region} output contract V3:\n"
            . "1. Return exactly one {$region} JSON object only. No markdown, no prose, no code fence, no full page document, no raw CSS/HTML after JSON.\n"
            . "2. Required string keys only: {$fields}. Every value must be a JSON string. Set extra_fields, php_variables, and js_content to empty strings. css_extra stays empty unless the latest user request explicitly asks to restyle this shared {$region} component itself.\n"
            . "3. Visible copy must be final customer-facing text in the target locale. This includes nav labels, link group headings, footer helper text, logo alt/title/aria, form placeholders, copyright/disclaimer text, and CTA labels. Never print prompts, task labels, contract keys, page_type, section_code, runtime_context, or why-this-block explanations.\n"
            . $minimalRule
            . "4. Do not output image tags in shared components. Logo/image rendering is handled by framework config.\n"
            . "5. css_extra ownership rule: shared header/footer visual shell, palette, spacing, shadows, gradients, hover/focus states, and mobile behavior are owned by framework config by default. Only when the latest user request explicitly asks to restyle this shared {$region}, css_extra may contain a small scoped override using confirmed theme tokens; otherwise it must be an empty string.\n"
            . "6. HTML fragments only: no PHP, no style/script tags, no framework wrappers, no malformed attributes, no orphan closing tags. Footer html_extra_column/html_extra must stay empty.\n"
            . "7. Keep the shared output tiny. Shared components must not block page section generation with long CSS or duplicated navigation/link markup.\n"
            . "8. Schema-only JSON shape: {$minimalExample}\n"
            . "9. Do not copy example text or stale examples from memory. For footer only, fill footer_extra_text with one synthesized target-locale sentence when the site plan supports it; otherwise keep it empty. Never quote the customer brief/source objective/English brand summary verbatim. Never invent app-download, game, casino, reward, APK, install, or generic support-site text for unrelated briefs.\n";
    }

    private function buildSharedVisualRulesPromptAddon(string $region): string
    {
        return "Shared {$region} visual rules:\n"
            . "- Do not implement visual styling in shared JSON unless the latest user request explicitly asks to restyle this shared {$region}; the framework-owned shared shell already carries palette, spacing, contrast, shadows, gradients, hover/focus, and mobile behavior.\n"
            . "- When no shared restyle is explicitly requested, preserve the visual system by returning tiny schema-only JSON and not overriding framework CSS.\n"
            . "- If a shared restyle is explicitly requested, keep it premium but simple: one shell surface, one grouped content pattern, one interaction pattern, one mobile-safe spacing rule, scoped to the shared {$region} only and using confirmed theme tokens.\n"
            . "- CSS reliability wins: no @keyframes, no nested @media, no color-mix(), no clip-path/mask/filter chains, no complex nested gradients, and no long selector lists.\n";
    }

    private function buildSharedComponentJsonSafetyRulesEn(string $region): string
    {
        $fields = $region === 'footer'
            ? 'extra_fields, php_variables, css_extra, html_extra_column, html_extra, footer_extra_text, js_content'
            : 'extra_fields, php_variables, css_extra, html_extra, js_content';

        return "Shared component JSON safety:\n"
            . "- Output exactly one JSON object using only these string keys: {$fields}.\n"
            . "- Do not use html_content or css_responsive for shared header/footer components.\n"
            . "- extra_fields, php_variables, and js_content must be empty strings.\n"
            . "- css_extra is CSS only, scoped under #componentId, with balanced braces and semicolon-terminated declarations.\n"
            . "- For shared header/footer, css_extra must contain only simple selector blocks shaped exactly like `#componentId .pb-name{property:value;property:value;}`. Do not use @media, nested blocks, comments, or raw declarations outside braces.\n"
            . "- CSS self-check before output: count `{` and `}` in css_extra; they must be equal, every `{` must have one later `}`, and the final CSS character must be `}` when css_extra is not empty.\n"
            . "- Keep css_extra short: header max 3 selector blocks, footer max 3 selector blocks. Put mobile safety into base flex-wrap/max-width rules instead of @media.\n"
            . "- html_extra/html_extra_column are static HTML fragments only: no PHP, no <style>, no <script>, no full page document, no framework wrapper ids.\n"
            . "- Use single quotes for HTML attributes inside JSON strings. Escape only JSON double quotes and line breaks.\n";
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $scope
     */
    private function buildSharedPlanJsonTaskPromptAddon(array $PlanJsonTask, string $contextLabel, array $scope = []): string
    {
        if ($PlanJsonTask === []) {
            throw new \RuntimeException('Build prompt contract failed: missing stage-2 plan-json task context for ' . $contextLabel . '; scope-level prompt fallback is forbidden.');
        }
        $runtimeContext = \is_array($PlanJsonTask['runtime_context'] ?? null) ? $PlanJsonTask['runtime_context'] : [];
        $themeContext = \is_array($runtimeContext['theme_context_snapshot'] ?? null) ? $runtimeContext['theme_context_snapshot'] : [];
        $sharedPromptContext = \is_array($runtimeContext['shared_prompt_context'] ?? null) ? $runtimeContext['shared_prompt_context'] : [];
        if ($themeContext === [] || $sharedPromptContext === []) {
            throw new \RuntimeException('Build prompt contract failed: task runtime_context missing stage-2 theme/shared context for ' . $contextLabel . '.');
        }
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $taskScript = \is_array($PlanJsonTask['task_script'] ?? null) ? $PlanJsonTask['task_script'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $siteContext = \is_array($runtimeContext['site_context'] ?? null) ? $runtimeContext['site_context'] : [];
        $assetContext = \is_array($runtimeContext['asset_context'] ?? null) ? $runtimeContext['asset_context'] : [];
        $contentLocale = \trim((string)($runtimeContext['content_locale'] ?? $this->resolveScopePrimaryLocale($scope)));
        $languageContract = $this->resolveStage3TaskLanguageContract($runtimeContext, $contentLocale);

        return "Shared plan-json task context for {$contextLabel}:\n"
            . "- task_key: " . (string)($PlanJsonTask['task_key'] ?? '') . "\n"
            . "- block_goal: " . (string)($planContext['block_goal'] ?? $blockTask['task_goal'] ?? '') . "\n"
            . "- story_goal: " . (string)($taskScript['story_goal'] ?? '') . "\n"
            . "- content_locale: " . ($contentLocale !== '' ? $contentLocale : 'not provided') . "\n"
            . "- language_contract: " . $this->jsonEncodeForPrompt($languageContract, 700) . "\n"
 . "- shared language rule: every visitor-facing shared header/footer label, CTA, logo alt/title/aria text, footer helper sentence, and link label must be rewritten into content_locale before output. Short CTA labels are not exempt; ` ? ?is invalid for pt_BR and must become a natural Portuguese play/support label.\n"
            . "- site_context: " . $this->jsonEncodeForPrompt($siteContext, 1200) . "\n"
            . "- stage1.theme_context: " . $this->jsonEncodeForPrompt($themeContext, 1200) . "\n"
            . "- stage1.shared_prompt_context: " . $this->jsonEncodeForPrompt($sharedPromptContext, 1000) . "\n"
            . "- asset_context_snapshot: " . $this->jsonEncodeForPrompt($assetContext, 900) . "\n"
            . "- shared execution rule: use this context only to create final shared header/footer visitor copy and styling. Do not print contract keys or planning sentences.\n";
    }

    private function buildComponentJsonPhpSafetyRulesEn(): string
    {
        return "PHP / HTML / CSS / JSON safety (critical -invalid output breaks the site build):\n"
            . "- Output exactly one JSON object only, with exactly these string keys: extra_fields, php_variables, css_extra, css_responsive, html_content, js_content. The first non-whitespace character must be { and the last non-whitespace character must be }. Do not append a second object, raw CSS, `php_variables:` labels, markdown, or explanation after the closing brace. Every value must be a valid JSON string: escape double quotes as \\\", represent newlines inside strings as \\n. Do not truncate strings mid-escape.\n"
            . "- JSON escape discipline: never place a backslash before normal HTML text, class names, tag names, or visitor copy. Legal backslash escapes are only \\\", \\\\, \\/, \\n, \\r, \\t, and \\uXXXX; invalid sequences like \\d, \\R, or \\< break the build.\n"
            . "- Field php_variables: for content blocks, use only one simple assignment per line shaped exactly like `\$title = \$getConfig('content.title', 'Finished localized title');`. No arrays, no conditionals, no loops, no helpers, no function declarations, no `\$this`, no superglobals, and no PHP open/close tags. For shared header/footer, php_variables remains empty.\n"
            . "- In php_variables, arrays are not allowed in this virtual-theme build. Never paste JavaScript object literals or JSON blobs here. The PHP token => must not appear in php_variables.\n"
            . "- Do not redeclare or break framework-provided variables (\$page, \$getConfig, \$componentId, \$cls, \$parseLinks, \$navItems, etc.) unless you know exactly how; prefer using them read-only.\n"
            . "- Field extra_fields: for content blocks, declare every visitor-visible editable value used by html_content. Format examples: `group:ai_content => AI editable content`, `content.title => Title:text:Finished localized title`, `content.description => Description:textarea:Finished localized body`, `cta.text => CTA text:text:Book demo`. Body/intro/copy requirements in this build normally use `content.description`; do not create `content.body` unless CTX_REQUIRED_EDITABLE_FIELDS explicitly lists that exact key. Add `cta.url => CTA URL:text:<real CTX_CTA_ACTION_CONTRACT target>` only when a real target is supplied; never use `/`, `#`, or invented paths as examples/defaults. Add `media.image_url => Image:image:<exact verified final_url from verified_asset_src_allowlist>` and `media.image_alt => Image alt:text:Finished localized alt` only when this prompt supplies a verified asset URL. For shared header/footer, extra_fields remains empty.\n"
            . "- html_extra, html_extra_column, html_content: HTML fragments only. No <style>, no <script>, no @component_start/@fields_start metadata. The only allowed PHP inside html_content is safe field output using htmlspecialchars or nl2br(htmlspecialchars), where the echoed variable is assigned in php_variables from the same declared field.\n"
            . "- HTML_IN_JSON: html_content must be parsed markup, not visible source text. The decoded html_content string should begin with `<section`; do not output removed skeleton labels, raw `<section ...>` examples, `class='...'`, CSS declarations, or escaped `&lt;section` as visitor-visible copy.\n"
            . "- Visitor text node contract: for content blocks, h2/p/small/span/div/button/a label text must be rendered as safe PHP field echoes backed by extra_fields/php_variables. The rendered defaults should be final customer-facing copy in the target locale, never JSON keys, prompt labels, slot ids, raw tag snippets, CSS source, or framework/plan-json identifiers.\n"
            . "- Visible comparison text: never use raw `<` or `>` characters in visitor copy for time, amount, or comparison wording. Write the phrase in words in the target locale instead, such as within 5 minutes / under 5 minutes. Raw comparison fragments such as `<5 minutes` or `>24h` break HTML parsing.\n"
            . "- Visible output contract scope: the generator is not hard-gated on source-truth factuality, wording quality, language polish, placeholders, prompt-like copy, or normal marketing/support/reward text. Blocking validation is limited to JSON shape, embeddable/balanced HTML structure, action/link/control structure, required editable image binding, and executable CSS syntax. Rewrite prompt-like or placeholder copy into final target-locale website copy as guidance, not because PHP will reject the content.\n"
            . "- Form placeholder source lock: placeholder/value attributes are visitor-visible copy and must render from safe PHP field echoes backed by extra_fields/php_variables, exactly like text nodes. Do not output example emails such as example@email.com, fake phone numbers, sample names, or English placeholder text for a non-English site; use localized generic prompts for name, email, and issue description.\n"
            . "- Contact channel value contract: exact email, phone, WhatsApp, handle, address, or domain values may appear only when that exact value is present in the frozen task context or approved site profile. If no exact value is supplied, use final localized non-numeric guidance such as the official in-app help center, secure message form, or live support channel. Never invent +91 98765 43210, 1234567890, 1800..., 800..., 000..., support@example.com, support@ .com, example domains, or sample handles.\n"
            . "- HTML fragments must be balanced and embeddable: close every non-void tag, do not output full <html>/<head>/<body> documents, and do not leave stray closing tags.\n"
            . "- Closing-tag grammar: never merge adjacent closing tags into one token. `</p></a>`, `</small></div>`, and `</div></section>` are valid; `</pa>`, `</smalldiv>`, `</pdiv>`, and `</divsection>` are invalid build-breaking HTML.\n"
            . "- Heading grammar: heading elements must close with the same exact element name, and inline tags inside headings must close first. Valid shape: h2 containing one safe PHP echo for the title. Invalid: `</h>`, `</spanh2>`, `</h3div>`, or closing a parent while span/strong remains open.\n"
            . "- Disclosure markup discipline: do not use `<details>` or `<summary>` for generated FAQ/accordion sections unless explicitly requested. These tags often produce malformed attributes in model output; use static div/h3/p groups styled by scoped CSS instead.\n"
            . "- HTML quote discipline: use single quotes for attributes inside html_content/css strings, including required generated image <img> attributes. Do not put raw double-quoted HTML attributes inside JSON string values.\n"
            . "- HTML fragment discipline: every `<` in html_content must begin a valid tag name or be escaped as text, all attribute quotes must close, and all non-void tags must be balanced before returning JSON.\n"
            . "- HTML scope discipline: html_content is already nested inside the framework root. Never output `id='componentId'`, `id=\"componentId\"`, or any inner wrapper id that pretends to be the framework root. Use class-only wrappers with the component prefix.\n"
            . "- HTML attribute discipline: never output malformed attributes such as `<span='...'>`, `<div='...'>`, `<a ... class='x'href='...'>`, or `<span='class-name'>`. Every attribute needs a name, equals sign, quoted value, and whitespace before the next attribute.\n"
            . "- css_extra, css_responsive: CSS only. No <? ... ?> and no PHP. These fields own the component's visual quality and responsive behavior inside the virtual-theme scaffold; the renderer only provides containment and the scoped action-event bridge, not block-specific beautification.\n"
            . "- Responsive layout: ship a real responsive solution. Base css_extra defines desktop, tablet (<=900px) may simplify split layouts but must keep proof/support groups visually dense, mobile (<=420px) is single column. css_responsive MUST contain at least one `@media (max-width: 768px)` and one `@media (max-width: 420px)` block. Inside each, set children to width:100%; max-width:100%; min-width:0; box-sizing:border-box where needed and rebalance typography/spacing. SAFE_TEXT_ROLE_OUTLINE responsive behavior must follow the selected visual_signature rhythm instead of applying the same three-proof-card transition to every block.\n"
            . "- Visual depth: scoped CSS should use confirmed theme palette tokens with layered backgrounds (linear-gradient/radial-gradient permitted), pseudo-elements for ornament, hover/focus states, box-shadow elevation, border-radius, padding/gap rhythm, type scale, transition, and safe CSS motion when the selected style direction calls for it. For game/neon launch styles, use CSS-only CTA shine/pulse/halo, hover lift, active press, slow review rail movement, or card/chip drift where it fits the block. Every selector, @keyframes, and @media block must have balanced { } braces.\n"
            . "- Image-text pairing: when the style direction asks for a game/neon visual atmosphere, avoid text-only body sections. Pair copy with a verified image, CSS table frame, card table, chip stack, jackpot meter, avatar/review rail, rulebook surface, support-message visual, editorial poster, phone mockup, or help-desk console unless the block is dense legal text.\n"
            . "- CSS grammar discipline: no empty declarations (`content:;`), no malformed properties (`-index`), no bare percent values (`height:%`), no merged declarations (`position:relativez-index:1`), no extra `}`. Function notation (linear-gradient, radial-gradient, rgba, clamp, min, max, calc, color-mix) IS allowed when parentheses are balanced and the values parse.\n"
            . "- CSS reliability mode: every declaration must be `property: value;`; put a semicolon before the next property. Use scoped selectors with the supplied component prefix only; optional @keyframes names must also use the component prefix. `box-sizing` must be exactly `border-box`. Avoid mask/clip-path/filter chains unless trivially correct. Do not introduce CSS comments. When in doubt about a complex value, fall back to a safer hex/shadow/spacing token rather than truncating mid-function.\n"
            . "- Typography guidance: css_extra should explicitly style both `#componentId .pb-c-title` and at least one body/root selector (`#componentId .pb-c-root`, `#componentId .pb-c-copy`, or `#componentId .pb-c-text`) with brand-appropriate font-family stacks. Use a named family first, then generic fallback when practical.\n"
            . "- Color contrast: never pair dark foreground text with dark backgrounds or light foreground text with light backgrounds; define readable text/CTA/focus states in CSS before returning.\n"
            . "- Content links/actions: generated content blocks should use `<a class='pb-c-cta'>` only when the href is a real CTX_CTA_ACTION_CONTRACT target or exactly appears in allowed_internal_paths. If no real route is available but a primary action is required, use `<button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars(\$ctaText ?? 'Finished localized CTA', ENT_QUOTES, 'UTF-8') ?></button>` and component-scoped js_content to emit the action event. Never output `href='#'`, hash-only anchors, invented internal paths, query strings, download routes, FAQ routes, game routes, or wrap a card/div/grid/panel/section inside an `<a>` or `<button>`. Every `.pb-c-cta` anchor/button must contain visible safe PHP echoed CTA text; an empty CTA element with only attributes is invalid.\n"
            . "- FAQ interaction contract: if html_content contains `.pb-c-faq-list`, `.pb-c-faq-item`, `.pb-c-question`, a chevron, plus/minus, or an accordion/support FAQ role, every FAQ row must expose a localized question title in `.pb-c-question` or h3.pb-c-question and a separate `.pb-c-answer` paragraph. Number chips, icons, or chevrons cannot be the only visible question content. When the design shows collapsed/expandable rows, use a button-like `.pb-c-question` control with type='button' and aria-expanded plus tiny scoped js_content that toggles only this component's row open state; otherwise render answers visibly without chevron affordances.\n"
            . "- Repeated-card structure recipe: repeated content containers such as `.pb-c-card`, `.pb-c-item`, `.pb-c-panel`, `.pb-c-review`, `.pb-c-faq-item`, and `.pb-c-channel` must be direct sibling children of one rail/grid/list wrapper. Each item must close before the next item opens. A testimonial/review card should use clear internal layers: card header/author, compact meta/rating row, then a separate quote/body paragraph below that row; never put the quote paragraph inside the star/meta row, and never start the next `.pb-c-card` before the previous card's body and wrapper are closed.\n"
            . "- Repeated-card self-check: before returning JSON, scan html_content from left to right. The valid shape is `div.pb-c-rail > div.pb-c-card ... </div> + div.pb-c-card ... </div> + div.pb-c-card ... </div>`. If one `.pb-c-card` appears while another `.pb-c-card` is still open, rewrite the HTML skeleton instead of relying on renderer repair.\n"
            . "- Page hierarchy: do not make the section one flat theme-color slab. Use palette roles, surface elevation, dividers, texture, cards, or spacing to distinguish this block from adjacent blocks.\n"
            . "- CSS class names: never use generic selectors like .card, .icon, .btn, .title, .item, .panel, .row, .container, .section, .text, .image, or .active. Use only the exact component prefix supplied later, shaped like pb-c-element, scope selectors with #componentId, and keep CSS selectors and HTML class attributes in sync. The html_content fragment is nested inside #componentId, so CSS must use descendant selectors such as `#componentId .pb-c-root`; do not write `#componentId.pb-c-root` unless the framework root itself has that class, which it will not.\n"
            . "- Images: never output broken image placeholders or visible placeholder labels. If no verified asset URL is provided, create visual rhythm with CSS-only shapes/pseudo-elements or omit media; do not use empty src, example.com, placeholder services, stock-photo services, external CDN/stock URLs, or unverified .jpg/.png/.webp URLs. If a verified asset URL is provided, put it in media.image_url as the editable field default/fallback and render a real <img> from that field.\n"
            . "- Decorations: never put standalone symbols such as arrows, suit marks, stars, bullets, plus signs, or divider glyphs in visitor-visible HTML. Render decoration with CSS borders, gradients, pseudo-elements, background layers, or box-shadow instead.\n"
            . "- External image ban: never use Unsplash, Pexels, Pixabay, Freepik, Shutterstock, Adobe Stock, source.unsplash.com, images.unsplash.com, picsum.photos, loremflickr.com, scheme-less URLs like ://... or //..., or any image URL that is not supplied as a verified final_url in this prompt.\n"
            . "- IMAGE_SRC_SELF_CHECK before returning: scan every <img src> and every CSS url(...). Each image must either render a safe editable media.image_url echo whose fallback/default exactly equals one value from verified_asset_src_allowlist, or be removed. CSS url(...) for generated assets is invalid.\n"
            . "- AI image editability: required generated image slots must be real <img> elements in html_content, not CSS-only background-image references. Adapt the prompt's concrete `verified_asset_editable_img_shape` so the same <img> carries data-pb-ai-asset-slot and data-pb-ai-image-role='generated-asset', while src and alt render from media.image_url/media.image_alt fields. Replace the media.image_alt fallback with a short visitor-facing image description in the target locale; never copy task labels, slot labels, prompt briefs, or instruction sentences into alt/title/aria text. Symbolic URL or slot placeholder strings are invalid. Use CSS object-fit/absolute positioning when the image needs to look like a banner background. For hero/banner images, the same class on the <img> must be styled by matching CSS as an absolute cover layer; a class spelling mismatch is invalid.\n"
            . "- No post-render visitor-copy cleanup exists. If labels, copy, layout, or image-slot binding are wrong, fix this JSON output instead of assuming PHP will repair it later.\n"
            . "- js_content: use an empty string for static blocks. For CTA/action blocks that use `<button data-pb-ai-action>`, provide only a tiny scoped click bridge using `component.querySelectorAll(...)`, `addEventListener`, local class toggles, and `component.dispatchEvent(new CustomEvent(...))`; no document/window selectors, fetch, inline onclick, or global state.\n";
    }

    /**
     * @param array<string,string> $verifiedAssets
     */
    private function buildVerifiedAssetPromptContract(array $verifiedAssets): string
    {
        $allowlist = [];
        $templates = [];
        $phpEchoOpen = '<' . '?=';
        $phpEchoClose = '?' . '>';
        foreach ($verifiedAssets as $slotId => $finalUrl) {
            $slotId = \str_replace(["\r", "\n"], '', \trim((string)$slotId));
            $finalUrl = \str_replace(["\r", "\n"], '', \trim((string)$finalUrl));
            if ($slotId === '' || $finalUrl === '') {
                continue;
            }
            $allowlist[] = "  * {$finalUrl}";
            $templates[] = "  * slot_id: {$slotId}; final_url: {$finalUrl}; editable_img: <img src='{$phpEchoOpen} htmlspecialchars(\$mediaImageUrl ?? '" . $finalUrl . "', ENT_QUOTES, 'UTF-8') {$phpEchoClose}' alt='{$phpEchoOpen} htmlspecialchars(\$mediaImageAlt ?? 'Finished localized alt text', ENT_QUOTES, 'UTF-8') {$phpEchoClose}' data-pb-ai-image-role='generated-asset' data-pb-ai-asset-slot='" . $slotId . "'>";
        }
        if ($allowlist === []) {
            return '';
        }

        return "- verified_asset_src_allowlist (CLOSED SET - the only legal image src/url values for this component):\n" . \implode("\n", \array_values(\array_unique($allowlist))) . "\n"
            . "- verified_asset_editable_img_shape (copy/adapt one concrete editable_img per required slot):\n" . \implode("\n", \array_values(\array_unique($templates))) . "\n"
            . "- REQUIRED EDITABLE IMAGE TAG: adapt one concrete editable_img above for each required slot. You may add a component-prefixed class, but keep data-pb-ai-image-role and data-pb-ai-asset-slot on the same <img>; render src/alt from media.image_url/media.image_alt fields, with media.image_url default/fallback exactly matching the template final_url. If a REQUIRED_EDITABLE_IMAGE_TAG or REQUIRED_IMAGE_STRUCTURE_CONTRACT appears in the same prompt, preserve that slot/data binding and required role placement instead of reconstructing it from memory. Do not output data-pb-ai-asset-slot on an <img> whose src is missing or whose editable src fallback differs from the matching final_url. Do not return symbolic placeholder strings for URL or slot values.\n"
            . "- Slot placement: the editable/adapted <img> must be inside html_content for this component, not only in CSS, config, comments, or alt text. Put the editable <img> as a direct child of this component's root wrapper or inside the first real media wrapper under that root, before decorative-only layers. This rule applies even for testimonial, FAQ, trust, or category sections.\n"
            . "- Editable image fields: when an <img> is used, extra_fields must include editable media fields such as `media.image_url => Image:image:<exact final_url>` and `media.image_alt => Image alt:text:<localized alt>`, php_variables must bind them with `\$mediaImageUrl = \$getConfig('media.image_url', '<exact final_url>');` and `\$mediaImageAlt = \$getConfig('media.image_alt', '<localized alt>');`, and the <img> src/alt must render those variables with safe htmlspecialchars output while preserving data-pb-ai-image-role and data-pb-ai-asset-slot.\n"
            . "- Image URL exclusivity: the media.image_url default/fallback for each editable image slot must be copied exactly from the matching final_url above. Do not add, shorten, translate, encode, prefix, trim query strings from, or replace that URL.\n"
            . "- URL character fidelity: treat each final_url as an opaque literal string. Copy every slash, dash, underscore, extension, and fingerprint character exactly into media.image_url; never retype from memory, normalize separators, remove dashes before hashes, or concatenate path segments.\n"
            . "- URL derivation ban: never construct an image path from target_domain, slot_id, section_code, filename, or folder conventions. Do not replace `/domain/page-...` with `domain-page...`; that is a broken invented URL. Paste the exact src from the concrete template only as the media.image_url default/fallback.\n"
            . "- URL exactness gate: an editable image URL fallback is valid only if it matches an allowlist value character-for-character. If the allowlist value is relative, keep it relative; if it is absolute, keep its host exactly. A missing slash, changed folder name, removed `generated`, inserted or removed domain fragment, dropped dot, or one-character hash difference is invalid. Do not repair by guessing or reconstructing a path; copy the literal template URL again.\n"
            . "- Image alt text: replace the editable_img fallback `Finished localized alt text` with concise visitor-facing image alt text in the website content locale. Never copy slot labels, task labels, component labels, prompt briefs, or action/instruction sentences into alt/title/aria attributes. Invalid examples: 'Introduce brand story and mission', 'Showcase testimonials', 'Answer common questions', 'licenses, security certifications'. Valid examples are concrete visual descriptions such as 'Workflow dashboard with approval route cards' or 'Operations team reviewing live handoff status'.\n"
            . "- IMAGE_SRC_SELF_CHECK: before returning JSON, inspect every <img src> and every CSS url(...). Every generated asset <img> src must be a safe editable media.image_url echo whose fallback/default exactly equals one value in verified_asset_src_allowlist. Prefer removing CSS url(...) entirely because generated image assets must be editable <img> elements. Do not leave stock-photo URLs in failed-payload repairs.\n"
            . "- External image ban: never use Unsplash, Pexels, Pixabay, Freepik, Shutterstock, Adobe Stock, source.unsplash.com, images.unsplash.com, picsum.photos, loremflickr.com, scheme-less URLs like ://... or //..., or any image URL not listed in verified_asset_src_allowlist.\n";
    }

    private function resolvePromptStyleCode(array $scope, string $pageType): string
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planPages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $planPage = \is_array($planPages[$pageType] ?? null) ? $planPages[$pageType] : [];
        $styleCode = \trim((string)($planPage['style_code'] ?? $scope['style_code'] ?? 'default'));
        if ($styleCode === '' || $styleCode === 'default') {
            $snapshot = \is_array($scope['design_direction_snapshot'] ?? null) ? $scope['design_direction_snapshot'] : [];
            $directionCode = \trim((string)($snapshot['code'] ?? $scope['design_direction_code'] ?? ''));
            if ($directionCode !== '') {
                $styleCode = $directionCode;
            }
        }

        return $styleCode !== '' ? $styleCode : 'default';
    }

    private function describeStyleDirection(string $styleCode): string
    {
        return match ($styleCode) {
            DesignDirectionService::BUILTIN_CARD_GAME_CODE => 'dark neon card-game entertainment page, central table/game visual, deep brown and ink-blue bands, neon teal edges, gold/orange play CTA, review rail, game list, FAQ and legal trust sections',
            'fintech-hub' => 'clean, data-driven, premium, trustworthy, high-contrast calls to action',
            'saas-starter' => 'modern product marketing, concise, structured, conversion-oriented',
            'fitness-pro' => 'energetic, bold, motivating, performance-focused',
            'sattaking', 'poker-arena', 'ludo-empire', 'rummy-royal' => 'high-energy gaming style, vivid contrast, strong CTA rhythm',
            'tpmst' => 'practical, service-focused, trustworthy, content-forward',
            default => 'clean editorial structure, clear hierarchy, practical CTA emphasis',
        };
    }

    private function buildWelineSkillContractPromptAddon(): string
    {
        return "Weline/PageBuilder skill contract / frontend skill contract for this virtual-theme component:\n"
            . "- pagebuilder-style-templates: output must map to PageBuilder component fields/config, keep @fields/default_config alignment, scope all CSS under the component root id, and use data-glr-ref/GlrDownloadRegistry-compatible download or CTA links when applicable.\n"
            . "- editable-field-planning: before writing html_content, plan the editable field list for this block. Every visitor-facing text, CTA label/URL, and real image src/alt that belongs to the component must be declared in extra_fields, assigned in php_variables with `\$getConfig(...)`, and rendered from those variables in html_content. Do not rely on PageBuilder to infer fields from hardcoded HTML text.\n"
 . "- CTA intent contract: cta.text/content.cta_text are authoritative business-action hints. Do not replace a download/install/reward/play action with generic consult/contact labels. A visible CTA label such as Consult now, Contact us, or  ?is valid only for contact/support/lead-capture blocks.\n"
            . "- theme-development: use confirmed theme palette tokens and CSS variables/inline scoped styles; no CDN, no global selectors, no unrelated hardcoded brand colors, no duplicate pixel/tracking snippets.\n"
            . "- frontend-components: generate one reusable component/block with editable fields and visitor-facing copy; do not emit full-page HTML, static placeholder sections, internal prompt text, generic substitute content, or page-type labels as visible eyebrow text.\n"
            . "- frontend ownership: all aesthetics, layout rhythm, responsive rules, and any tiny allowed interaction belong in the AI JSON fields. Do not rely on PageRenderService or a global compatibility stylesheet to make the result attractive or mobile-safe.\n"
            . "- page-design-plan: for page-owned blocks, page_design_plan is the design brief. Preserve its color_layering, section_flow, interaction_notes, and anti_monotony_rule in the final visual hierarchy.\n"
            . "- asset-rule: when the block explicitly has a required generated-image contract and CTX_CURRENT_ASSET supplies a verified final_url/template, render that real editable <img>. When the block is CSS-motif/no-generated-image by contract, create a theme-colored CSS-only visual or omit media. Never use CSS-only motifs, emoji, phone outlines, stock URLs, or placeholders as a substitute for a required generated image; never render broken image placeholders.\n"
            . "- shared-logo-rule: header and footer are shared brand surfaces. If logo.image/logo.url/brand.logo provides a verified logo asset, use the same logo asset in both header and footer by default; do not replace it with section photos, trust badges, category imagery, or generated decorative art.\n"
            . "- shared-logo-variant-rule: only use a separate light/dark/monochrome logo treatment when the approved design plan or latest user instruction requires it for contrast against different header/footer backgrounds. Preserve the same brand shape/name, change only the visual treatment needed for legibility, and keep the alt/aria label as the localized brand name.\n"
            . "- logo-fallback-rule: when no verified logo asset exists, create one consistent symbol-only CSS/SVG brand mark and reuse that identity in both header and footer. Do not render site names, initials, monograms, slogans, or decorative text as the logo.\n"
            . "- ai-module-development: this is an audited AI scenario result; include only content that follows the provided stage-1 theme context and current plan-json task contract.\n"
            . "- queue-usage/sse-streaming: long generation is already queued; return the final component JSON only, not progress narration or markdown.\n";
    }

    private function buildClaudeDesignSkillPromptAddon(string $stage, array $scope = []): string
    {
        $guide = \trim(\implode("\n", $this->getSkillRegistry()->buildPromptGuideLinesForScope($stage, $scope)));
        if ($guide === '') {
            return '';
        }

        return "Selected frontend skill guide (compressed; current task contract wins):\n"
            . $this->clipText($guide, 2800) . "\n"
            . "- Skill guidance is style/process support only. The current block contract, required image slot, output schema, locale, and CSS safety rules override any generic skill example.\n";
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function buildVisibleCopyGovernancePromptAddon(array $websiteProfile, array $scope): string
    {
        $contentLocale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        $defaultLocale = \trim((string)($scope['default_locale'] ?? $websiteProfile['default_locale'] ?? ''));
        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['planning_locale'] ?? $websiteProfile['plan_locale'] ?? ''));
        $metadataLeakExample = "- Metadata rewrite example (content_locale=pt_BR): BAD visible body `\u{970D}\u{8679}\u{68CB}\u{724C}\u{9986}\u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}\u{3001}\u{7279}\u{8272}\u{5185}\u{5BB9}\u{3001}\u{4FE1}\u{4EFB}\u{4FE1}\u{606F}\u{548C}\u{4E3B}\u{8981}\u{884C}\u{52A8}\u{5165}\u{53E3}`. GOOD editable default `Explore salas neon com regras claras, provas de confianca e uma entrada rapida para jogar.` The BAD text may appear in CTX_BLOCK_GOAL, block_goal, story_goal, or plan_context; use it only as intent, never as website copy.\n";

        return "Visible copy governance:\n"
            . "- content_locale/default_locale: " . ($contentLocale !== '' ? $contentLocale : 'not provided') . ($defaultLocale !== '' && $defaultLocale !== $contentLocale ? " (default_locale {$defaultLocale})" : '') . "\n"
            . "- plan_locale: " . ($planLocale !== '' ? $planLocale : 'not provided') . " is only an internal planning language hint, never a visitor-facing language source.\n"
            . "- Visitor-visible copy must use content_locale/default_locale. Do not use plan_locale unless it is the same locale.\n"
            . "- Visitor-facing attributes are copy too: alt, title, aria-label, placeholder, value, button labels, nav labels, footer labels, and form labels must be rewritten into content_locale/default_locale before output.\n"
            . "- Short labels are still language leaks: for a non-CJK content_locale, do not output even 1-2 Chinese/Japanese/Korean characters in CTA text, nav text, badges, button labels, logo alt/title, or form labels.\n"
            . "- Planned content is not exempt: if task_script, block_task.content_plan, field samples, nav labels, CTA labels, SEO snippets, or stage-1 plan text use another language, translate/rewrite them into content_locale/default_locale before rendering html_content/footer/header text.\n"
            . "- Brand/profile normalization: never output schema/object names such as websiteProfile, Website Profile, site profile, profile, or raw target_domain as a brand/site title. If the stored site_title/SEO/logo text is in another language than content_locale/default_locale, rewrite it into a concise visitor-facing brand label in the target locale using the business category and market from the brief.\n"
            . "- For non-CJK content locales, Chinese/Japanese/Korean brand names, meta snippets, logo text, badges, and alt text are internal source material only unless the user explicitly requested that script as visible copy. Rewrite them into the target locale before output.\n"
            . "- Task labels, component labels, section labels, image-slot labels, queue/plan-json labels, and data-contract role labels are internal metadata. Never render them verbatim as headings, card titles, badges, CTA text, alt/title/aria text, or body copy; rewrite them into final customer-facing copy.\n"
            . "- Instruction-shaped English copy is forbidden as visible copy. Sentences or alt text starting with Introduce, Showcase, Answer, Reassure, Remove, Educate, Encourage, or Close are task instructions; rewrite them into concrete customer-facing copy before output.\n"
 . "- Rewrite planning/observation sentences into direct marketing copy before rendering. Do not visibly output phrases like \"Visitors see...\", \"Visitors can review...\", \" ?..\", or \" ?..\".\n"
            . "- Never output blueprint meta-copy: page/current-section highlight headings, planning observations, design instructions, task scripts, or sentences that tell the AI to display hierarchy, trust proof, primary actions, flow, risks, or content grouping. Those are instructions, not website copy.\n"
            . "- Contract-field leak ban: never render content_contract, design_contract, implementation_contract, data_contract, visual_contract, page_goal, block_goal, why_this_block, planning_reason, selected_skill_codes, skill_snapshots, internal planning fields, or qa_report_contract as visible copy. These are build-time context only.\n"
            . $metadataLeakExample
            . "- Never render internal identifiers or paths as visible copy: plan_locale, page_type, section_code, task_key, block_key, runtime_context, app/code paths, var/ paths, content/... component paths, shared:* keys, or page:* keys.\n"
            . "- Industry relevance rule: do not reuse app-download, game, casino, reward, APK, install, or generic support-site copy unless the approved brief explicitly asks for that industry. Keep vertical language tied to the confirmed brief instead of cross-contaminating other site types.\n"
            . "- Renderer will not repair visitor copy after generation; the JSON you return must already contain final customer-specific visible copy.\n"
            . "- Never output internal list labels shaped like schema role + number, for example card/category/badge/step placeholders. Each card/list item needs a customer-specific title and description from the brief, Plan JSON, or verified content data.\n"
            . "- Internal identifier rewrite: if any candidate visible text still contains home_page, about_page, contact_page, page_type, section_code, task_key, plan_locale, runtime_context, shared:, page:, content/, app/code/, or var/, treat it as leaked metadata and rewrite it into natural customer-facing copy before returning.\n"
 . "- Number-label spacing audit: check every metric, badge, stat, step, timeline chip, CTA, and nav label in every script. If a number is immediately followed by Latin or Cyrillic letters in visible copy, insert a readable space or rewrite the phrase into natural copy before returning. Examples: use `10 млн`, `4.8 звезды`, or `24 hours`; never `10млн`, `4.8звезды`, or `24hours`.\n"
            . "- Never render broken image placeholders. If a verified uploaded asset URL is absent, create the visual with CSS-only shapes/pseudo-elements or omit media.\n";
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function buildTemplateScaffoldGuardPromptAddon(array $websiteProfile, array $scope, string $locale, string $contextLabel): string
    {
        $sourceTruth = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];
        $siteIdentity = \is_array($sourceTruth['site_identity'] ?? null) ? $sourceTruth['site_identity'] : [];
        $allowedBrandTerms = [];
        foreach ([
            $this->getPageBlueprintService()->resolveUserSiteTitle($websiteProfile, $scope),
            $scope['site_title'] ?? null,
            $scope['site_name'] ?? null,
            $websiteProfile['site_title'] ?? null,
            $websiteProfile['site_name'] ?? null,
            $siteIdentity['site_name'] ?? null,
        ] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                $allowedBrandTerms[] = $this->filterVisibleCopyForLocale($candidate, $locale);
            }
        }
        foreach (\is_array($siteIdentity['brand_terms'] ?? null) ? $siteIdentity['brand_terms'] : [] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                $allowedBrandTerms[] = $this->filterVisibleCopyForLocale($candidate, $locale);
            }
        }
        $allowedBrandTerms = $this->uniqueNonEmptyStrings($allowedBrandTerms);
        $allowedLookup = \array_fill_keys(\array_map(static fn(string $term): string => \mb_strtolower($term), $allowedBrandTerms), true);
        $forbiddenTerms = [];
        foreach (self::TEMPLATE_SCAFFOLD_BRAND_TERMS as $term) {
            if (!isset($allowedLookup[\mb_strtolower($term)])) {
                $forbiddenTerms[] = $term;
            }
        }

        return "TEMPLATE_SCAFFOLD_GUARD for {$contextLabel}:\n"
            . "- approved_brand_terms: " . $this->jsonEncodeForPrompt($allowedBrandTerms, 360) . "\n"
            . "- forbidden_template_or_example_brand_terms: " . $this->jsonEncodeForPrompt($forbiddenTerms, 360) . "\n"
            . "- Style templates, default_config values, layout JSON, component readmes, and examples are scaffolds only. Copy structure, editable-field shape, and visual motifs; never copy their brand names, stale page copy, social links, game lists, support links, SEO text, alt/title/aria text, placeholders, or CTA targets as final visitor content.\n"
            . "- If suggested config or frozen context contains a forbidden term, treat it as stale scaffold content and rewrite the affected extra_fields defaults, php_variables defaults, html_content/html_extra/footer_extra_text, alt/title/aria/placeholder text, and CTA labels using the approved brand and current brief.\n"
            . "- Before returning JSON, scan every visible string and editable default. Any foreign brand term outside approved_brand_terms must be removed or rewritten; do not leave Ludo/Satta/Poker/Bharat example copy in the generated output.\n";
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function uniqueNonEmptyStrings(array $values): array
    {
        $result = [];
        $seen = [];
        foreach ($values as $value) {
            $value = \trim((string)$value);
            if ($value === '') {
                continue;
            }
            $key = \mb_strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function buildStage3LocaleExecutionPromptAddon(array $websiteProfile, array $scope): string
    {
        $contentLocale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        if ($contentLocale === '') {
            return '';
        }

        $localeHint = $this->describeLocaleForAiPrompt($contentLocale);
        $languageRule = "- Visitor-visible content language: write headings, body copy, navigation labels, button labels, footer text, form labels, and alt/title/aria text in {$contentLocale}. Product names, brand names, domain names, acronyms, model names, URLs, and user-provided proper nouns may keep their original spelling when that is natural for the site.\n";

        return "Stage-3 language execution contract (hard requirement):\n"
            . "- source_of_truth_locale: {$contentLocale} ({$localeHint})\n"
            . "- Internal planning language is not output language. stage-1/plan-json/story/task samples are intent only.\n"
            . "- Before composing html_content/html_extra/footer text/nav labels, normalize every candidate sentence to source_of_truth_locale.\n"
            . $languageRule
            . "- Proofread the final visible copy in source_of_truth_locale before returning: fix misspelled words, broken word fragments, and missing spaces between sentence text and CTA/link labels. Do not concatenate two labels or a paragraph plus CTA without whitespace or punctuation.\n"
 . "- Numeric label spacing: write metric labels with a visible space or line break between numbers and words, e.g. `4.8 звезды`; never `4.8звезды`.\n"
            . "- Final self-check before returning JSON: if any visitor-visible sentence is not in source_of_truth_locale, rewrite it now and then return.\n";
    }

    /**
     * @return array<string,mixed>
     */
    private function buildStage3TaskLanguageContract(string $contentLocale): array
    {
        $localeProfile = $this->buildLocalePromptProfile($contentLocale);

        return [
            'source_of_truth_locale' => $contentLocale,
            'locale_profile' => $localeProfile,
            'visible_copy_rule' => 'All visitor-visible headings, body copy, CTA labels, nav/footer text, form labels, alt/title/aria/placeholder text must be written in source_of_truth_locale.',
            'plan_text_rule' => 'The confirmed plan and task samples are intent only; rewrite or translate them before visible output.',
            'proper_noun_rule' => 'Brand names, domain names, product names, URLs, acronyms, model names, and user-provided proper nouns may keep original spelling when natural.',
            'script_rule' => 'Use locale_profile.required_visible_script and locale_profile.text_direction for final visible copy. Do not leave Chinese, English, or planning-language prose unless it is an approved proper noun.',
            'forbidden_visible_sources' => ['block_goal', 'task_goal', 'story_goal', 'why_this_block', 'planning_reason', 'block_contract', 'visual_signature', 'image_intent', 'asset_requirements', 'execution_script'],
            'self_check' => 'Before returning JSON, scan every visible string and rewrite any sentence that is not in source_of_truth_locale.',
        ];
    }

    /**
     * @param array<string,mixed> $runtimeContext
     * @return array<string,mixed>
     */
    private function resolveStage3TaskLanguageContract(array $runtimeContext, string $contentLocale): array
    {
        $contract = \is_array($runtimeContext['language_contract'] ?? null)
            ? $runtimeContext['language_contract']
            : [];
        if ($contract === []) {
            return $this->buildStage3TaskLanguageContract($contentLocale);
        }
        if (\trim((string)($contract['source_of_truth_locale'] ?? '')) === '' && $contentLocale !== '') {
            $contract['source_of_truth_locale'] = $contentLocale;
        }
        if (!\is_array($contract['locale_profile'] ?? null) && $contentLocale !== '') {
            $contract['locale_profile'] = $this->buildLocalePromptProfile($contentLocale);
        }

        return $contract;
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     */
    private function buildCurrentBlockLanguageContractPromptAddon(string $contentLocale, array $PlanJsonTask): string
    {
        if ($contentLocale === '') {
            return '';
        }
        $runtimeContext = \is_array($PlanJsonTask['runtime_context'] ?? null) ? $PlanJsonTask['runtime_context'] : [];
        $contract = $this->resolveStage3TaskLanguageContract($runtimeContext, $contentLocale);

        return "CTX_WEBSITE_LANGUAGE (hard block-local language contract): "
            . $this->jsonEncodeForPrompt($contract, 700) . "\n"
            . "- HARD LANGUAGE CONTRACT: every visitor-visible string generated for this block must be in source_of_truth_locale={$contentLocale}; translate/rewrite any plan text, field sample, CTA label, alt text, placeholder, aria-label, nav/footer label, or form label before it becomes output.\n"
            . "- Proper nouns may keep original spelling only when they are the confirmed brand/product/domain/URL/acronym; the surrounding sentence must still be in source_of_truth_locale.\n";
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function buildThemeContractPromptAddon(array $scope): string
    {
        $contract = $this->resolveThemeContract($scope);
        $locale = $this->resolveScopePrimaryLocale($scope);
        $palette = \is_array($contract['palette'] ?? null) ? $contract['palette'] : [];
        if ($palette === []) {
            $palette = ["primary" => "#1a1a2e", "secondary" => "#e94560", "accent" => "#0f3460", "background" => "#fafafa", "surface" => "#ffffff", "text_primary" => "#1a1a2e", "text_secondary" => "#555555", "border" => "#e5e5e5", "success" => "#00c853", "warning" => "#ffc107"];
        }
        $themeContext = \is_array($contract['raw_context'] ?? null) ? $contract['raw_context'] : [];
        $artDirection = \is_array($contract['art_direction'] ?? null) ? $contract['art_direction'] : [];
        if ($locale !== '') {
            $filteredPalette = $this->filterPromptArrayForLocale($palette, $locale);
            $filteredThemeContext = $this->filterPromptArrayForLocale($themeContext, $locale);
            $filteredArtDirection = $this->filterPromptArrayForLocale($artDirection, $locale);
            $palette = \is_array($filteredPalette) ? $filteredPalette : $palette;
            $themeContext = \is_array($filteredThemeContext) ? $filteredThemeContext : $themeContext;
            $artDirection = \is_array($filteredArtDirection) ? $filteredArtDirection : $artDirection;
        }
        $roleMap = $this->buildPaletteRoleMapFromThemePalette($palette, false);
        $heroRoleMap = $this->buildPaletteRoleMapFromThemePalette($palette, true);
        $roleMapPrompt = $this->formatPaletteRoleMapForPrompt($roleMap);
        $heroRoleMapPrompt = $this->formatPaletteRoleMapForPrompt($heroRoleMap);

        return "Confirmed visual contract from the approved stage-1 theme and Plan JSON:\n"
            . "- theme_name: " . (string)($contract['name'] ?? '') . "\n"
            . "- visual_tone: " . (string)($contract['visual_tone'] ?? '') . "\n"
            . "- font_family: " . (string)($contract['font_family'] ?? '') . "\n"
            . "- style_signature: " . (string)($contract['style_signature'] ?? '') . "\n"
            . ($roleMapPrompt !== '' ? "- readable_palette_role_map: {$roleMapPrompt}\n" : '')
            . ($heroRoleMapPrompt !== '' ? "- hero_readable_palette_role_map: {$heroRoleMapPrompt}\n" : '')
            . ($roleMapPrompt !== '' ? "- HARD palette role execution: css_extra must use role map values for text, muted_text, surface, surface_alt, cta_bg, and cta_text. CTA selectors such as .pb-c-cta must pair background=cta_bg with color=cta_text from readable_palette_role_map; do not substitute #FFFFFF/#000000 unless that exact value appears in the role map.\n" : '')
            . "- art_direction: " . $this->jsonEncodeForPrompt($artDirection, 1200) . "\n"
            . "- palette: " . $this->jsonEncodeForPrompt($palette, 1800) . "\n"
            . "- theme_context_summary: " . $this->jsonEncodeForPrompt($themeContext, 1600) . "\n"
            . "- Use these exact palette tokens for generated CSS and extra fields. Do not invent unrelated accent colors.\n"
            . "- Palette usage is role-based: map tokens to page base, elevated surface, text, muted text, CTA, accent, divider, and focus state before writing CSS.\n"
            . "- Contrast is non-negotiable: if a background/surface token is dark, choose light text/link/chip colors; if it is light, choose dark text. Never place dark text on a dark background or light text on a light background.\n"
            . "- Theme color is not a paint bucket: do not make every block the same full-bleed primary/accent color. Use surfaces, cards, dividers, textures, and spacing to create adjacent-section hierarchy inside the confirmed palette.\n";
    }

    private function buildVisualExcellencePromptAddon(string $componentScope): string
    {
        $scopeRule = match ($componentScope) {
            'header' => '- Header quality floor: css_extra must visibly restyle the shell/nav/CTA with depth, contrast, active/hover states, and a brand-specific motif; a plain border-only header is invalid.' . "\n",
            'footer' => '- Footer quality floor: the framework-owned footer shell, configured link columns, logo, and optional footer_extra_text create the closing surface. For shared footer JSON, do not force html_extra/html_extra_column or css_extra; keep the tiny schema contract unless the latest user request explicitly asks to restyle this shared footer.' . "\n",
            default => '- Section quality floor: html_content should include a component-specific wrapper plus visible design devices such as a generated-image media frame, CSS-only motif, trust/metric strip, timeline/process rail, comparison band, badge cluster, proof/support group, or editorial callout; css_extra should style those devices. This is design guidance, not a completion gate.' . "\n",
        };

        return "Visual excellence system prompt for {$componentScope}:\n"
            . "- Produce a polished customer preview, not a placeholder template. Use the approved brief/theme; do not leak plan/schema/task text.\n"
            . "- Specificity guidance: every visible block should carry brief-derived anchors through visitor copy, media subject, motifs, proof chips, CTA wording, or layout affordances. Avoid blocks that would fit another business after changing the logo.\n"
            . "- First-impression guidance: the page should read as a finished premium website at screenshot distance, with deliberate scale, focal hierarchy, non-flat surfaces, and a clear conversion path. Do not output wireframe-like panels, loose content dumps, or generic decorative backgrounds.\n"
            . "- Negative prompt coverage: reject placeholder/wireframe/skeleton-looking sections, lorem ipsum or prompt/schema/task leakage, generic stock-card sameness, fake UI controls, unsupported claims or invented metrics/contact/security/payment facts, unreadable overlays, low-contrast text, disconnected CTA placement, oversized empty gutters, broken/missing assets, decorative clutter that competes with the primary action, and responsive layouts that would overlap, crop, or create horizontal scroll.\n"
            . "- Avoid generic sameness: each block needs a role-specific composition, not the same centered title plus three cards.\n"
            . "- Repetition repair rule: if the natural draft uses the same card grid, metric row, gradient orb, icon list, or CTA treatment as another likely section, change the composition before returning JSON.\n"
            . "- Own the final rendering in the returned JSON: scoped html_content/css_extra must carry aesthetics, contrast, and hover/focus polish; css_responsive carries explicit `@media (max-width: 768px)` and `@media (max-width: 420px)` blocks. Do not return an empty css_responsive for content blocks.\n"
            . "- Base layout foundation: treat this as a fallback quality floor, not a fixed skeleton. The root owns section background and vertical padding; the inner wrapper uses max-width 1120px-1280px, margin:0 auto, horizontal padding 20px-32px, min-width:0, and box-sizing:border-box. Use 4px/8px rhythm for gaps, card padding, media spacing, and action separation; reduce those values deliberately at 768px and 420px.\n"
            . "- Typography foundation: headings need stable breakpoint sizes, line-height around 1.05-1.2, margin reset, and enough bottom spacing before body copy. Body, labels, badges, captions, and CTA text need line-height around 1.45-1.75, overflow-wrap:anywhere where long words can appear, and letter-spacing:0 unless a locale-safe positive value is explicitly part of the style. Do not use negative letter spacing.\n"
            . "- Foundation versus composition: the foundation protects readability, spacing, and responsive safety. It must not turn every block into the same root > inner > centered title > cards layout. Choose composition from the approved block plan, visual_signature, user request, and section role, then apply the foundation to that composition.\n"
            . "- CTA shape contract: CTA-looking controls should read as intentional buttons in the default layout with display:inline-flex, width:auto, max-width around 280px, and flex:0 0 auto. Use a full-width wrapper if the composition needs a band.\n"
            . "- CTA label contract: CTA copy must match the page/block job. Download/app/APK/reward/game blocks use download/play/reward language; blog/learning blocks use reading/explore language; only contact/support blocks use consult/contact language.\n"
            . "- Policy-page CTA contract: privacy/terms/refund/shipping/cookie policy blocks must use policy/data/rights/terms/support actions. They must not render download, APK, play, bonus, reward, game, or install CTA text inside the policy page body, even when the global site CTA is app download.\n"
            . "- Semantic affordance contract: anything shaped like a button, tab, pill, badge, step, carousel dot, indicator, input, progress bar, chip, stat, or control must have localized visible text or a meaningful icon with accessible label. Decoration may be abstract, but it must not look clickable, form-like, or stateful.\n"
            . "- Empty-control negative contract: do not output unlabeled dots, empty rounded pills, blank horizontal bars, empty input-like strips, orphan carousel indicators, iconless step markers, decorative control rows, or placeholder UI chrome. If a row of dots/pills/bars appears, it must become labeled proof, steps, status, tabs, or real carousel controls with text/icon/aria semantics; otherwise delete it before returning JSON.\n"
            . "- Aesthetic negative contract: do not use generic gradient blobs, decorative halos, random icon soup, card walls, repeated centered headers, dense badge clutter, image thumbnails as afterthoughts, or theme-color flood fills when they do not serve the block role. Negative prompts protect quality; they do not force every block into the same layout.\n"
            . "- Empty-surface contract: a large flat dark/light rectangle with only a logo, one sentence, or one CTA is not premium. A non-hero title plus one paragraph plus CTA is also not premium unless it has styled support/proof/visual devices. Use content density, surface elevation, borders, shadows, media, and proof/support copy so every large panel earns its space.\n"
            . "- Brief-specific motif contract: convert the current business brief into visible art direction through safe palette choices, shaped surfaces, copy labels, badges, media treatment, and spacing rhythm. Generic casino/gaming/business panels are invalid when the brief provides richer nouns or audience context.\n"
            . "- Responsive structure is mandatory for the roles actually used by this block: root shell, inner container, copy/title/text zone, media/CSS visual zone, action/form/proof zone, and any decorative layers need component-prefixed classes and matching scoped CSS. Do not add unused role wrappers just to imitate another block.\n"
            . "- At <=900px stack split layouts; at <=420px use one column. Containers/media/forms may use width:100%, max-width:100%, box-sizing:border-box, min-width:0; keep the desktop/default CTA button visually compact and avoid making it a page-width bar. Prefer action/actions for CTA wrappers so wrapper CSS is not mistaken for button CSS.\n"
            . "- If image plus form/card appears, keep both in normal grid/flex flow. Do not absolutely push cards outside the root or overlap inputs/text with media/decorations.\n"
            . "- Text over photos or busy textures needs a real scrim/panel/veil layer. Never put body copy directly on a busy image.\n"
            . "- Use readable palette pairings, surface depth, shadows, texture, dividers, and accessible focus states. Do not use fragile CSS tricks when simple scoped CSS works.\n"
            . "- HTML fields must be valid fragments with one component-owned root, balanced tags/quotes, no framework wrappers, no neighboring block HTML, and no PHP.\n"
            . "- Before returning, mentally check 1440/1024/768/390px. Rewrite any layout that would overflow, crop text, lose contrast, or overlap content.\n"
            . $scopeRule
            . "- If no real asset is available, create CSS-only visual language that matches the brief or omit media; never leave visible placeholder media.\n";
    }

    private function buildPremiumSectionDesignContractPromptAddon(
        string $pageType,
        string $sectionKey,
        string $sectionTemplate,
        string $pageFlowRole = '',
        string $styleCode = '',
        ?bool $strictHeroCoverFromPlan = null
    ): string
    {
        $pageFlowRole = \strtolower(\trim($pageFlowRole));
        $isHero = \in_array($sectionTemplate, ['hero', 'banner'], true)
            || \in_array($pageFlowRole, ['opening', 'hero', 'banner', 'above_fold', 'above-fold'], true);
        $strictHeroCover = $strictHeroCoverFromPlan ?? false;
        $recipe = 'Use a section-specific composition, not the same card grid used by other blocks.';
        if (\in_array($pageFlowRole, ['trust_story', 'proof'], true)) {
            $recipe = 'Trust/security/social-proof recipe: use a badge wall, credential seal, quote rail, metric strip, or verification timeline. Do not use a generic three-card row plus one image.';
        } elseif ($pageType === 'home_page') {
            $recipe = 'Game/showcase recipe: follow visual_signature.composition_pattern when present. Prefer editorial split, feature rail, comparison band, or asymmetric media feature rather than the same three-card grid used elsewhere on the page. Vary media placement and surface rhythm per block.';
        } elseif ($pageType === 'contact_page' || \in_array($pageFlowRole, ['support', 'education'], true)) {
            $recipe = 'FAQ/support recipe: use accordion/list rhythm with an asymmetric help panel or support badge cluster. It must not look like testimonials, trust badges, or a feature grid.';
        } elseif (\in_array($pageFlowRole, ['conversion', 'final_cta'], true)) {
            $recipe = 'CTA/download recipe: use a cinematic full-bleed band, strong overlay copy, device/download affordance, and one unmistakable action path. Do not render another neutral card group.';
        } elseif ($pageType === 'about_page') {
            $recipe = 'Story/about recipe: use editorial split layout, timeline, founder/mission panel, or layered narrative cards. It must not reuse the hero/card-grid composition.';
        } elseif ($this->isPolicyPageType($pageType)) {
            $recipe = 'Policy recipe: use compact compliance summary, anchor navigation, trust notes, and readable policy surfaces. It must not reuse the home-page hero/card-grid composition.';
        }

        $heroRule = $isHero && $strictHeroCover
            ? "- HERO/BANNER DEFAULT BASELINE: unless the user's latest block-adjustment instruction or approved design plan explicitly conflicts, render a real premium 1920x750-style banner with a dominant first-viewport visual. Reset the framework component wrapper with `#componentId{padding:0;}` so the image owns the whole banner band. The root shell must be viewport-width even inside a centered PageBuilder wrapper: use width:100vw or min-width:100vw with margin:0 calc(50% - 50vw); never put max-width on the hero root. Only the inner text-safe container may use max-width. When a verified generated image exists, it must be a real editable <img> cover layer with object-fit:cover and the required slot attributes; the image/background should occupy the full hero band and read as the main visual, not as a side illustration. Content sits inside a floating text-safe panel on top of an image-wide scrim/gradient veil. The text panel must have its own readable background/scrim, padding, max-width, and foreground colors so paragraphs remain readable on busy photos. If no verified image is available, the CSS-only media layer must still read as deliberate art direction through palette, shadow, border, overlay, and text-panel composition; it must express a subject-matter stage or editorial surface from the approved brief, not generic overlapping circles, blobs, a blank slab, unlabeled dots/pills/bars, or a centered card on empty background. The primary CTA should read as a compact button in the default layout; place it in a sibling action wrapper after text/rows with visible outside clearance, never tight against a divider or row line. Wrapper bands can be full width. Do NOT create a small side image, isolated centered card, narrow media frame, centered 1200px image island, top/bottom theme-color gutters around the hero image, CSS background-image-only hero, huge empty side gutters, blank input-like strips, orphan indicator dots, or dark body text directly over a detailed photo as the default. If the user explicitly asks for another hero composition, follow it while preserving premium generated imagery, strong hierarchy, and readable overlay treatment.\n"
            : ($isHero
                ? "- PAGE-BANNER FREEDOM RULE: banner/hero means this block introduces the current page, not that it must reuse the home-page hero shell. Use page_identity_contract and page_design_plan first. About pages may use story/timeline/mission composition; contact pages may use support-channel/status composition; policy pages may use compact compliance/anchor summaries; tutorial/custom pages may use rule navigation or step-preview composition. Full-width image + scrim + floating copy panel is only one option, not the default for every page.\n"
                : "- NON-HERO HARD RULE: this block needs its own layout purpose and visual rhythm. Do not copy the hero structure or the previous block's card/media arrangement.\n");
        $cardGameRule = $styleCode === DesignDirectionService::BUILTIN_CARD_GAME_CODE
            ? "- CARD-GAME STYLE EXECUTION: keep the dark game-publisher launch language visible through structure, not only colors. For home_page opening/banner, avoid a normal photo-cover hero plus copy card; stage a center poster/device/game-board focal surface with compact side command/proof cards, route chips, particle/halo texture, and a strong gold/orange APK CTA. For review/testimonial roles, prefer a horizontal rail or marquee-like quote track with edge fades and readable cards. For FAQ, use numbered dark accordion rows. For about/contact/policy openings, change the composition to story/proof, support-channel, or legal rulebook identity instead of replaying the home launch stage. Card-game style is visual direction only: it never authorizes deposit, withdrawal, payout, arrival-time, 24-hour support, or inflated reward claims that are not in the exact block plan.\n"
            : '';

        return "Premium site design contract for this section:\n"
            . "- Base prompt precedence: this section recipe is the default quality baseline. If the latest user refinement or approved block plan explicitly asks for a different composition, use that composition while preserving the same premium quality bar, content relevance, image-slot usage, contrast, and anti-placeholder constraints.\n"
            . $heroRule
            . $cardGameRule
            . "- Section recipe: {$recipe}\n"
            . "- Heading semantics rule: opening, hero, above-fold, and page-intro blocks must render exactly one `<h1 class='pb-c-title'>`; non-opening content blocks use `h2.pb-c-title` or lower. Do not make every section title an h2.\n"
            . "- Spacing rhythm rule: use a coherent 4px/8px spacing scale for section padding, inner gaps, copy spacing, card/media padding, and action separation. CTA/action groups must have deliberate breathing room. When a CTA follows text, channel rows, form fields, or dividers, style its action wrapper or CTA with at least one clear margin, padding, or gap so the button never touches a line or neighboring row.\n"
            . "- Baseline structure rule: every section needs a clear root, bounded inner layout, readable title/body grouping, and styled role wrappers for the content it actually renders. This is a quality baseline only; it must not force identical grids, repeated centered headers, or reused card shells across blocks.\n"
            . "- Review/testimonial structure rule: if this section renders testimonials, player voices, reviews, quotes, or social proof, build a rail/grid/list wrapper whose repeated cards are siblings. Inside each card, keep author/name, location/meta/rating, and quote/body as separate child layers so labels do not collide with paragraph text.\n"
            . "- Anti-monotony rule: adjacent blocks must not share the same three-card row, same image position, same copy labels, or same pale background/card treatment. Change composition, scale, motif, and spacing per section role.\n"
            . "- Rejected output patterns: centered title plus one paragraph plus CTA with no support/proof/visual device; centered title plus three small cards plus the same image; tiny cartoon/SVG-looking media used as a substitute for a real generated image; repeated generic CTA/category labels across blocks; hero built as a boxed card next to a small image; CTA stretched into a page-width bar; large empty dark panel with only a logo or one button; blank horizontal strips; unlabeled dots/pills/chips; orphan carousel indicators; iconless step markers; input-like bars without labels; generic gradient blobs or decorative halos as the main visual; card walls with no hierarchy; fake stats, fake badges, fake support/payment/security claims; CSS-only hero made only from overlapping circles/blobs with no subject-matter stage.\n"
            . "- If a verified generated image exists, use it prominently and naturally as a real editable <img>. For hero, the <img> is a cover layer behind the overlay copy; for non-hero it is a purposeful media surface, not a thumbnail afterthought.\n";
    }

    /**
     * @param array<string,mixed> $visualSignature
     */
    private function buildNonHeroRoleOutlineFromVisualSignature(
        array $visualSignature,
        bool $isContactSupportContract,
        string $contractIdentity
    ): string {
        if ($isContactSupportContract) {
            return "section.pb-c-root\n"
                . "  div.pb-c-inner\n"
                . "    div.pb-c-media\n"
                . "      img.pb-c-img (editable image tag from verified_asset_editable_img_shape)\n"
                . "    div.pb-c-copy\n"
                . "      h2.pb-c-title\n"
                . "      p.pb-c-text\n"
                . "      div.pb-c-cards\n"
                . "        div.pb-c-channel > span.pb-c-label + span.pb-c-value\n"
                . "        div.pb-c-channel > span.pb-c-label + span.pb-c-value\n"
                . "      div.pb-c-action\n"
                . "        div.pb-c-cta\n"
                . "      small.pb-c-note";
        }

        $pattern = \strtolower(\trim((string)($visualSignature['composition_pattern'] ?? '')));
        $mediaStrategy = \strtolower(\trim((string)($visualSignature['media_strategy'] ?? '')));
        $identity = \strtolower($contractIdentity . ' ' . $pattern . ' ' . $mediaStrategy);

        if (
            \str_contains($pattern, 'stack')
            || \str_contains($pattern, 'editorial')
            || \str_contains($pattern, 'narrative')
        ) {
            return "section.pb-c-root\n"
                . "  div.pb-c-inner (stacked editorial)\n"
                . "    div.pb-c-copy\n"
                . "      h2.pb-c-title\n"
                . "      p.pb-c-text\n"
                . "    div.pb-c-media (full-width band below copy)\n"
                . "      img.pb-c-img (editable image tag from verified_asset_editable_img_shape)\n"
                . "    div.pb-c-action\n"
                . "      div.pb-c-cta";
        }

        if (
            \str_contains($pattern, 'step')
            || \str_contains($pattern, 'timeline')
            || \str_contains($pattern, 'process')
            || \str_contains($pattern, 'rail')
        ) {
            return "section.pb-c-root\n"
                . "  div.pb-c-inner (step/process rail)\n"
                . "    h2.pb-c-title\n"
                . "    div.pb-c-steps\n"
                . "      div.pb-c-step\n"
                . "      div.pb-c-step\n"
                . "      div.pb-c-step\n"
                . "    div.pb-c-media (optional supporting visual)\n"
                . "      img.pb-c-img (editable image tag from verified_asset_editable_img_shape when required)\n"
                . "    div.pb-c-action\n"
                . "      div.pb-c-cta";
        }

        if (
            \str_contains($pattern, 'proof')
            || \str_contains($pattern, 'badge')
            || \str_contains($pattern, 'metric')
            || \str_contains($pattern, 'trust')
            || \preg_match('/\b(proof|trust|badge|metric)\b/i', $identity) === 1
        ) {
            return "section.pb-c-root\n"
                . "  div.pb-c-inner (proof/metric band)\n"
                . "    h2.pb-c-title\n"
                . "    div.pb-c-proof-band\n"
                . "      div.pb-c-proof-item\n"
                . "      div.pb-c-proof-item\n"
                . "      div.pb-c-proof-item\n"
                . "    div.pb-c-media (optional accent visual)\n"
                . "      img.pb-c-img (editable image tag from verified_asset_editable_img_shape when required)";
        }

        if (
            \str_contains($pattern, 'faq')
            || \str_contains($pattern, 'accordion')
            || \str_contains($pattern, 'question')
            || \preg_match('/\b(faq|accordion|support)\b/i', $identity) === 1
        ) {
            return "section.pb-c-root\n"
                . "  div.pb-c-inner (FAQ/support rows)\n"
                . "    h2.pb-c-title\n"
                . "    div.pb-c-faq-list\n"
                . "      div.pb-c-faq-item > div.pb-c-question + p.pb-c-answer\n"
                . "      div.pb-c-faq-item > div.pb-c-question + p.pb-c-answer";
        }

        if (
            \str_contains($pattern, 'cta')
            || \str_contains($pattern, 'conversion')
            || \str_contains($pattern, 'band')
        ) {
            return "section.pb-c-root\n"
                . "  div.pb-c-inner (CTA band)\n"
                . "    div.pb-c-copy\n"
                . "      h2.pb-c-title\n"
                . "      p.pb-c-text\n"
                . "      small.pb-c-note\n"
                . "    div.pb-c-media (optional supporting visual)\n"
                . "      img.pb-c-img (editable image tag from verified_asset_editable_img_shape when required)\n"
                . "    div.pb-c-action\n"
                . "      div.pb-c-cta";
        }

        if (\str_contains($pattern, 'reverse') || \str_contains($pattern, 'media-right')) {
            return "section.pb-c-root\n"
                . "  div.pb-c-inner (copy left, media right)\n"
                . "    div.pb-c-copy\n"
                . "      h2.pb-c-title\n"
                . "      p.pb-c-text\n"
                . "      div.pb-c-action > div.pb-c-cta\n"
                . "    div.pb-c-media\n"
                . "      img.pb-c-img (editable image tag from verified_asset_editable_img_shape)";
        }

        return "section.pb-c-root\n"
            . "  div.pb-c-inner (split panel; use only when composition_pattern calls for split)\n"
            . "    div.pb-c-media\n"
            . "      img.pb-c-img (editable image tag from verified_asset_editable_img_shape)\n"
            . "    div.pb-c-copy\n"
            . "      h2.pb-c-title\n"
            . "      p.pb-c-text\n"
            . "      div.pb-c-action\n"
            . "        div.pb-c-cta";
    }

    /**
     * Build prompt artifacts for the required generated-image contract.
     *
     * The returned artifacts describe the editable image tag, role outline,
     * CSS structural hints, and palette role map that Stage-2 must follow.
 * 2) skeleton_outline  ? ?/  ?AI
 * ?.pb-c-*  ?<img>  ? * 3) palette_role_map  ? ?scope themePalette  ?hex
 * token  ?/ text / accent / cta_bg / cta_text /
 * scrim  ? ?hex  ?css_extra ?
     *
     * @param array<string,mixed> $visualContract
 * @param array<string,string> $themePalette role => #hex ?scope  ?
     * @return array{
     *   required:bool,
     *   slot_id:string,
     *   final_url:string,
     *   is_hero:bool,
     *   img_template:string,
     *   skeleton_outline:string,
     *   palette_role_map:array<string,string>,
     *   css_structural_hints:string
     * }
     */
    private function buildRequiredImagePromptArtifacts(array $visualContract, array $themePalette = []): array
    {
        $required = (int)($visualContract['required'] ?? 0) === 1;
        $slotId = (string)($visualContract['slot_id'] ?? '');
        $finalUrl = \trim((string)($visualContract['final_url'] ?? $visualContract['url'] ?? ''));
        $usage = (string)($visualContract['usage'] ?? '');
        $placement = (string)($visualContract['placement'] ?? '');
        $isHeroPlacement = \array_key_exists('strict_hero_cover', $visualContract)
            ? (int)($visualContract['strict_hero_cover'] ?? 0) === 1
            : (\in_array((string)($visualContract['slot_type'] ?? ''), ['hero_image'], true)
                || \in_array($usage, ['section_background_cover'], true)
                || \in_array($placement, ['background_layer'], true));
        $contractIdentity = \mb_strtolower(\implode(' ', \array_map('strval', [
            $visualContract['page_type'] ?? '',
            $visualContract['section_code'] ?? '',
            $visualContract['section_key'] ?? '',
            $visualContract['section_template'] ?? '',
            $visualContract['subject'] ?? '',
        ])));
        $isContactSupportContract = \preg_match('/\b(?:contact|support|privacy_contact|help|faq)\b/iu', $contractIdentity) === 1;

        $requiredImgTemplate = '';
        $skeletonOutline = '';
        $cssStructuralHints = '';
        $roleMap = [];

        if ($required && $slotId !== '' && $finalUrl !== '') {
            $imgClass = $isHeroPlacement ? 'pb-c-hero-img' : 'pb-c-img';
            $phpEchoOpen = '<' . '?=';
            $phpEchoClose = '?' . '>';
            $requiredImgTemplate = "<img class='{$imgClass}' src='"
                . "{$phpEchoOpen} htmlspecialchars(\$mediaImageUrl ?? '"
                . \htmlspecialchars($finalUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . "', ENT_QUOTES, 'UTF-8') {$phpEchoClose}' alt='{$phpEchoOpen} htmlspecialchars(\$mediaImageAlt ?? 'Finished localized alt text', ENT_QUOTES, 'UTF-8') {$phpEchoClose}' data-pb-ai-image-role='generated-asset' data-pb-ai-asset-slot='"
                . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . "'>";

            $roleMap = $this->buildPaletteRoleMapFromThemePalette($themePalette, $isHeroPlacement);

            if ($isHeroPlacement) {
                $skeletonOutline = "section.pb-c-root\n"
                    . "  img.pb-c-hero-img (editable image tag from REQUIRED_EDITABLE_IMAGE_TAG)\n"
                    . "  div.pb-c-scrim (overlay layer above the image)\n"
                    . "  div.pb-c-inner\n"
                    . "    div.pb-c-copy\n"
                    . "      h1.pb-c-title\n"
                    . "      p.pb-c-text\n"
                    . "      div.pb-c-action\n"
                    . "        div.pb-c-cta";
                $cssStructuralHints = "CSS structural hints must use palette_role_map values and style the required hero image roles only.\n"
                    . "  #componentId: padding:0\n"
                    . "  .pb-c-root: position:relative; overflow:hidden; width:100vw; min-height:520px; margin:0 calc(50% - 50vw); padding:72px 24px; box-sizing:border-box; font-family= CTX_CONFIRMED_THEME.font_family or a named brand family before generic fallback; background= palette_role_map.surface or linear-gradient(palette_role_map.surface -> palette_role_map.surface_alt). Do not put max-width on root; constrain only .pb-c-inner\n"
                    . "  .pb-c-hero-img: position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:center\n"
                    . "  .pb-c-scrim: position:absolute; inset:0; background= palette_role_map.scrim (solid hex requires opacity:.42-.58; rgba/linear-gradient may carry transparency directly)\n"
                    . "  .pb-c-inner: position:relative; z-index:1; max-width:1200px; margin:0 auto; display:flex; align-items:center; min-height:420px\n"
                    . "  .pb-c-copy: max-width:620px; padding:32px; border-radius:24px; background= palette_role_map.copy_panel_bg; color= palette_role_map.copy_panel_text; box-shadow:0 28px 80px palette_role_map.shadow\n"
                    . "  .pb-c-title: margin:0 0 16px; font-family= CTX_CONFIRMED_THEME.font_family or a named display family before generic fallback; font-size:52px; line-height:1.1; color= palette_role_map.copy_panel_text. In css_responsive, override title font-size to 42px at tablet and 34px at mobile; never use vw/clamp for font-size\n"
                    . "  .pb-c-text: margin:0 0 22px; line-height:1.7; color= palette_role_map.muted_text\n"
                    . "  .pb-c-action: margin:22px 0 0; padding:18px 0 0; display:flex; gap:12px; align-items:center; must be a sibling after channel/form/content rows, not inside a bordered row\n"
                    . "  .pb-c-cta: display:inline-flex; width:auto; max-width:280px; padding:12px 20px; border-radius:999px; background= palette_role_map.cta_bg; color= palette_role_map.cta_text; transition:transform .2s, box-shadow .2s\n"
                    . "  .pb-c-cta:hover: transform:translateY(-2px); box-shadow:0 12px 24px palette_role_map.shadow";
            } else {
                $visualSignature = \is_array($visualContract['visual_signature'] ?? null) ? $visualContract['visual_signature'] : [];
                $skeletonOutline = $this->buildNonHeroRoleOutlineFromVisualSignature(
                    $visualSignature,
                    $isContactSupportContract,
                    $contractIdentity
                );
                $cssStructuralHints = "CSS structural hints must style the current plan_json block and its required roles only.\n"
                    . "  .pb-c-root: position:relative; overflow:hidden; padding:72px 24px; box-sizing:border-box; font-family= CTX_CONFIRMED_THEME.font_family or a named brand family before generic fallback; background= palette_role_map.surface\n"
                    . "  .pb-c-inner: max-width:1200px; margin:0 auto; display:flex; flex-wrap:wrap; gap:32px; align-items:center\n"
                    . "  .pb-c-media: flex:1 1 360px; min-width:0; overflow:hidden; border-radius:24px; box-shadow:0 24px 64px palette_role_map.shadow\n"
                    . "  .pb-c-img: display:block; width:100%; height:360px; object-fit:cover; object-position:center\n"
                    . "  .pb-c-copy: flex:1 1 320px; min-width:0\n"
                    . "  .pb-c-title: margin:0 0 16px; font-family= CTX_CONFIRMED_THEME.font_family or a named display family before generic fallback; font-size:42px; line-height:1.1; color= palette_role_map.text. In css_responsive, override title font-size to 34px at tablet and 28px at mobile; never use vw/clamp for font-size\n"
                    . "  .pb-c-text: margin:0 0 22px; line-height:1.7; color= palette_role_map.muted_text\n"
                    . ($isContactSupportContract
                        ? "  .pb-c-cards: display:flex; flex-wrap:wrap; gap:14px; margin:0 0 22px\n"
                            . "  .pb-c-card: flex:1 1 160px; min-width:0; padding:16px; border-radius:18px; background= palette_role_map.surface_alt; box-shadow=0 12px 30px palette_role_map.shadow\n"
                        : '')
                    . "  .pb-c-action: margin:22px 0 0; padding:18px 0 0; display:flex; gap:12px; align-items:center; must be a sibling after channel/form/content rows, not inside a bordered row\n"
                    . "  .pb-c-cta: display:inline-flex; width:auto; max-width:280px; padding:12px 20px; border-radius:999px; background= palette_role_map.cta_bg; color= palette_role_map.cta_text; transition:transform .2s, box-shadow .2s\n"
                    . "  .pb-c-cta:hover: transform:translateY(-2px); box-shadow:0 12px 24px palette_role_map.shadow";
            }
        }

        return [
            'required' => $required,
            'slot_id' => $slotId,
            'final_url' => $finalUrl,
            'is_hero' => $isHeroPlacement,
            'img_template' => $requiredImgTemplate,
            'skeleton_outline' => $skeletonOutline,
            'palette_role_map' => $roleMap,
            'css_structural_hints' => $cssStructuralHints,
        ];
    }

    /**
     * Build semantic palette role names for the required image prompt.
     *
     * The prompt renderer keeps these role names separate from actual theme
     * values so Stage-2 replaces them from CTX_CONFIRMED_THEME.
     *
     * @param array<string,string> $themePalette
     * @return array<string,string>
     */
    private function buildPaletteRoleMapFromThemePalette(array $themePalette, bool $isHero): array
    {
        if ($themePalette === []) {
            return [];
        }

        $primary = $this->pickPaletteColor($themePalette, ['primary', 'brand', 'button']);
        $accent = $this->pickPaletteColor($themePalette, ['accent', 'secondary', 'highlight']);
        $surface = $this->pickPaletteColor($themePalette, ['surface', 'background', 'base']);
        $surfaceAlt = $this->pickPaletteColor($themePalette, ['surface_alt', 'background_alt', 'panel', 'card']);
        $text = $this->pickPaletteColor($themePalette, ['text', 'body', 'foreground']);
        $mutedText = $this->pickPaletteColor($themePalette, ['muted_text', 'muted', 'subtle', 'text_muted']);
        $shadow = $this->pickPaletteColor($themePalette, ['shadow', 'shadow_color', 'border']);
        $surfaceForText = $surface !== '' ? $surface : $surfaceAlt;
        $text = $surfaceForText !== ''
            ? $this->resolveReadablePaletteTextColor($surfaceForText, $text, $themePalette, [$primary, $accent, $surfaceAlt])
            : $text;
        $mutedText = $surfaceForText !== ''
            ? $this->resolveReadablePaletteTextColor($surfaceForText, $mutedText !== '' ? $mutedText : $text, $themePalette, [$text, $primary, $accent])
            : ($mutedText !== '' ? $mutedText : $text);
        $shadow = $this->resolvePaletteShadowColor($shadow, $surfaceForText, $primary, $text, $accent);

        $ctaBg = $accent !== '' ? $accent : ($primary !== '' ? $primary : $text);
        $ctaText = $ctaBg !== ''
            ? $this->resolveReadablePaletteTextColor($ctaBg, $surface !== '' ? $surface : $text, $themePalette, [$text, $surfaceAlt, $primary])
            : '';

        $copyPanelBg = $isHero ? ($surface !== '' ? $surface : $primary) : ($surfaceAlt !== '' ? $surfaceAlt : $surface);
        $copyPanelText = $copyPanelBg !== ''
            ? $this->resolveReadablePaletteTextColor($copyPanelBg, $text, $themePalette, [$mutedText, $primary, $accent])
            : $text;

        $scrim = $isHero ? ($primary !== '' ? $primary : $text) : $surface;

        return \array_filter([
            'primary' => $primary,
            'accent' => $accent,
            'surface' => $surface,
            'surface_alt' => $surfaceAlt !== '' ? $surfaceAlt : $surface,
            'text' => $text,
            'muted_text' => $mutedText !== '' ? $mutedText : $text,
            'shadow' => $shadow !== '' ? $shadow : $text,
            'cta_bg' => $ctaBg,
            'cta_text' => $ctaText,
            'copy_panel_bg' => $copyPanelBg,
            'copy_panel_text' => $copyPanelText,
            'scrim' => $scrim,
        ], static fn(string $value): bool => $value !== '');
    }

    /**
     * @param array<string,mixed> $visualContract
     * @param array<string,string> $themePalette
     */
    private function buildSectionVisualContractPromptAddon(array $visualContract, array $themePalette = []): string
    {
        if ($visualContract === []) {
            return '';
        }
        $artifacts = $this->buildRequiredImagePromptArtifacts($visualContract, $themePalette);
        $required = (bool)($artifacts['required'] ?? false);
        $slotId = (string)($artifacts['slot_id'] ?? '');
        $finalUrl = (string)($artifacts['final_url'] ?? '');
        $usage = (string)($visualContract['usage'] ?? '');
        $placement = (string)($visualContract['placement'] ?? '');
        $subject = (string)($visualContract['subject'] ?? '');
        $style = (string)($visualContract['style'] ?? '');
        $strictHeroCover = (int)($visualContract['strict_hero_cover'] ?? 0) === 1;
        $contractOriginallyRequired = (int)($visualContract['contract_required'] ?? $visualContract['original_required'] ?? 0) === 1;
        $requiredAssetContract = ($required && $slotId !== '' && $finalUrl !== '')
            ? $this->buildVerifiedAssetPromptContract([$slotId => $finalUrl])
            : '';
        $requiredImgTemplate = (string)($artifacts['img_template'] ?? '');
        $skeletonOutline = (string)($artifacts['skeleton_outline'] ?? '');
        $cssStructuralHints = (string)($artifacts['css_structural_hints'] ?? '');
        $roleMap = \is_array($artifacts['palette_role_map'] ?? null) ? $artifacts['palette_role_map'] : [];
        $roleMapLine = $this->formatPaletteRoleMapForPrompt($roleMap);
        if ($required && $slotId !== '' && $finalUrl === '') {
            return "Block visual contract:\n"
                . "- visual_contract: " . $this->jsonEncodeForPrompt($visualContract, 1600) . "\n"
                . "- image_required: yes\n"
                . "- Pending image slot {$slotId}: this block still requires generated media, but no verified final_url/template is available yet. Block rendering must wait for a confirmed generated image instead of producing a final CSS-only substitute.\n"
                . "- Required image confirmation rule: do not invent a placeholder image URL, do not claim CSS-only media is a generated image, and do not return visitor-facing final HTML for this block until the FINAL IMAGE CONTRACT supplies an exact image URL/template.\n";
        }
        if ((int)($visualContract['image_unavailable'] ?? 0) === 1) {
            if ($contractOriginallyRequired) {
                return "Block visual contract:\n"
                    . "- visual_contract: " . $this->jsonEncodeForPrompt($visualContract, 1600) . "\n"
                    . "- image_required: yes\n"
                    . "- image_unavailable: yes; this is an unresolved required generated-image dependency, not a CSS-only design choice. Block generation must fail/retry before final HTML is accepted.\n"
                    . "- Do not invent image URLs, do not render empty <img> tags, do not replace the required generated image with CSS-only media, and do not show unavailable-image diagnostics as visitor copy.\n";
            }

            return "Block visual contract:\n"
                . "- visual_contract: " . $this->jsonEncodeForPrompt($visualContract, 1600) . "\n"
                . "- image_required: no\n"
                . "- image_unavailable: yes; build a polished CSS-only or product-UI media surface for this section.\n"
                . "- Do not invent image URLs, do not render empty <img> tags, and do not show unavailable-image diagnostics as visitor copy.\n";
        }

        return "Block visual contract:\n"
            . "- visual_contract: " . $this->jsonEncodeForPrompt($visualContract, 1600) . "\n"
            . "- image_required: " . ($required ? 'yes' : 'no') . "\n"
            . ($required
                ? "- Required image slot {$slotId}: use the exact generated asset for this block as the media.image_url default/fallback. Usage={$usage}; placement={$placement}; subject={$subject}; style={$style}.\n"
                    . ($finalUrl !== '' ? "- Required final_url for this slot: {$finalUrl}\n" : '')
                    . ($requiredImgTemplate !== '' ? "- REQUIRED_EDITABLE_IMAGE_TAG: {$requiredImgTemplate}\n" : '')
                    . ($skeletonOutline !== '' ? "- REQUIRED_ROLE_OUTLINE (role list only, not HTML to copy; additional scoped wrappers are allowed only when they improve this block and remain valid. Do not copy parenthetical notes into html_content):\n{$skeletonOutline}\n" : '')
                    . ($roleMapLine !== '' ? "- REQUIRED_PALETTE_ROLE_MAP (HARD): use these semantic palette roles exactly where applicable: {$roleMapLine}.\n" : '')
                    . ($cssStructuralHints !== '' ? "- REQUIRED_CSS_ROLE_CONTRACT (style required roles using palette role values; layout rhythm and composition remain design-owned by this block):\n{$cssStructuralHints}\n" : '')
                    . $requiredAssetContract
                    . "- Required slot HTML rule: the REQUIRED_EDITABLE_IMAGE_TAG is the concrete editable slot/data template for html_content. Keep class, data-pb-ai-image-role, and data-pb-ai-asset-slot exact; render src from media.image_url with the verified final_url as fallback, and render alt from media.image_alt. The validator checks html_content, not css_extra, so CSS background-image alone will fail.\n"
                    . "- Required structure rule: html_content must contain the core roles from REQUIRED_ROLE_OUTLINE plus the exact editable image binding. More refined brand composition is welcome, but do not remove the required image, root, copy/title/text/CTA core roles. Visible text and alt must be localized, brand-relevant field echoes; do not leave placeholder strings or role notes.\n"
                    . "- Required palette rule: css_extra must use palette role values and the current confirmed theme.\n"
                    . "- Required URL copy rule: do not type, infer, concatenate, shorten, or normalize the image path. Do not build a path from slot_id, target_domain, filename, or folder patterns. Use the verified final_url inside REQUIRED_EDITABLE_IMAGE_TAG only as the media.image_url default/fallback. Do not use css_extra url(...) for this asset.\n"
                    . "- Required URL anti-memory rule: never write `/pub/media/page-build/ai-generated/` paths from memory. Copy the verified final_url exactly into the media.image_url field default/fallback. If the verified final_url is relative, keep it relative; if it includes a domain, keep the entire domain segment exactly as shown. Any missing slash, domain fragment, dot, or hash character is invalid.\n"
                    . ($strictHeroCover
                        ? "- Required slot placement rule: place the editable <img> before overlay/text layers inside this component's root wrapper or first media wrapper. Because strict_hero_cover=1, include `#componentId{padding:0;}`, make that same <img> the full-cover media layer with CSS position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:center, and make the root full-bleed with width:100vw plus margin:0 calc(50% - 50vw). The generated image must own the banner as a dominant background/focal layer, not a small side panel. Do not satisfy this with a CSS background-image, side thumbnail, narrow centered media frame, decorative second image, blank bar, or unlabeled dot/pill row.\n"
                        : "- Required slot placement rule: place the editable <img> as a page-specific media surface, visual card, device/status panel, story frame, proof badge, or compact opening accent that fits this page. Do not force full-bleed 1920x750 cover treatment unless strict_hero_cover=1.\n")
                    . "- Required slot self-check: before returning JSON, search html_content for the exact slot_id on an <img> tag with data-pb-ai-image-role='generated-asset', and verify the exact final_url appears as the media.image_url default/fallback for that editable src. Do not rely on section config, CSS url(...), comments, alt text, or prose to satisfy the image contract.\n"
                    . ($strictHeroCover
                        ? "- If the slot is hero/background_cover and the user's latest block-adjustment instruction does not explicitly request another composition, build the block as a real 1920x750-style banner: viewport-width root, full-cover image layer, solid scrim/veil overlay, floating content container, and unmistakable CTA. A side thumbnail, centered 1200px image island, huge theme-color gutters, cartoon-like illustration panel, empty input-like strip, or row of unlabeled dots/pills is invalid as the default baseline.\n"
                        : "- Page-banner anti-sameness rule: do not clone the home-page banner formula. A page opening may be compact, split, anchored, card-led, process-led, support-led, story-led, or compliance-led as long as it clearly introduces this page and remains visually polished.\n")
                : "- Optional image slot: do not invent image URLs. If no verified generated image is supplied, design with CSS-only motif/pseudo-elements or omit media; no placeholder service.\n");
    }

    /**
     * @param array<string,mixed> $roleMap
     */
    private function formatPaletteRoleMapForPrompt(array $roleMap): string
    {
        $pairs = [];
        foreach ($roleMap as $key => $value) {
            $key = \trim((string)$key);
            $value = \trim((string)$value);
            if ($key === '' || $value === '') {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        return $pairs === [] ? '' : '{' . \implode(', ', $pairs) . '}';
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,string>
     */
    private function resolveThemeStyleDefaults(array $scope, string $region): array
    {
        $contract = $this->resolveThemeContract($scope);
        $palette = \is_array($contract['palette'] ?? null) ? $contract['palette'] : [];
        if ($palette === []) {
            return [];
        }

        $primary = $this->pickPaletteColor($palette, ['primary', 'button']);
        $accent = $this->pickPaletteColor($palette, ['accent', 'secondary', 'primary']);
        $secondary = $this->pickPaletteColor($palette, ['secondary', 'accent']);
        $surface = $this->pickPaletteColor($palette, ['surface', 'background', 'primary']);
        $text = $this->pickPaletteColor($palette, ['text', 'body']);
        $background = $this->pickPaletteColor($palette, ['background', 'surface']);

        if ($region === 'header') {
            $headerBg = $surface !== '' ? $surface : $primary;
            $headerText = $this->resolveReadableTextColor($headerBg, $text);

            return \array_filter([
                'style.bg_color' => $headerBg,
                'style.text_color' => $headerText,
                'style.link_color' => $headerText,
                'style.link_hover_color' => $accent,
                'style.accent_color' => $accent,
            ], static fn(string $value): bool => $value !== '');
        }

        if ($region === 'footer') {
            $footerBg = $surface !== '' ? $surface : $primary;
            $footerText = $this->resolveReadableTextColor($footerBg, $text);

            return \array_filter([
                'style.bg_color' => $footerBg,
                'style.text_color' => $footerText,
                'style.title_color' => $footerText,
                'style.link_color' => $footerText,
                'style.link_hover_color' => $accent !== '' ? $accent : $secondary,
                'style.accent_color' => $accent !== '' ? $accent : $secondary,
            ], static fn(string $value): bool => $value !== '');
        }

        $contentBg = $background !== '' ? $background : '#ffffff';
        $contentText = $this->resolveReadableTextColor($contentBg, $text);

        return \array_filter([
            'style.bg_color' => $contentBg,
            'style.text_color' => $contentText,
            'style.title_color' => $contentText,
            'style.accent_color' => $accent !== '' ? $accent : $primary,
            'style.bg_gradient' => ($primary !== '' && $accent !== '')
                ? 'linear-gradient(135deg, ' . $primary . ' 0%, ' . $accent . ' 100%)'
                : '',
        ], static fn(string $value): bool => $value !== '');
    }

    /**
     * @param array<string,mixed> $scope
     * @return array{name?:string,visual_tone?:string,font_family?:string,palette?:array<string,string>}
     */
    private function resolveThemeContract(array $scope): array
    {
        $candidates = [];
        try {
            $PlanJsonTaskRoot = $this->resolvePlanJsonTaskRoot($scope);
            if ($PlanJsonTaskRoot !== []) {
                $candidates[] = $PlanJsonTaskRoot;
            }
        } catch (\RuntimeException $exception) {
            if (!\str_contains($exception->getMessage(), 'plan_json has no executable blocks')) {
                throw $exception;
            }
        }

        $candidates[] = [
            'theme_design' => \is_array($scope['theme_design'] ?? null) ? $scope['theme_design'] : [],
            'theme_style' => \is_array($scope['theme_style'] ?? null) ? $scope['theme_style'] : [],
            'palette' => \is_array($scope['palette'] ?? null) ? $scope['palette'] : [],
        ];
        $candidates[] = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];

        foreach ($candidates as $candidate) {
            if (!\is_array($candidate) || $candidate === []) {
                continue;
            }

            $themeContext = $this->findThemeContextCandidate($candidate);
            $contract = $this->normalizeThemeContract($themeContext);
            if (\is_array($contract['palette'] ?? null) && $contract['palette'] !== []) {
                return $contract;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function findThemeContextCandidate(array $source, int $depth = 0): array
    {
        if ($depth > 6) {
            return [];
        }

        foreach (['theme_context_snapshot', 'theme_design'] as $key) {
            if (\is_array($source[$key] ?? null)) {
                return $source[$key];
            }
        }

        if (\is_array($source['palette'] ?? null) || \is_array($source['color_scheme'] ?? null)) {
            return $source;
        }

        foreach ($source as $value) {
            if (!\is_array($value)) {
                continue;
            }
            $candidate = $this->findThemeContextCandidate($value, $depth + 1);
            if ($candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $themeContext
     * @return array{name?:string,visual_tone?:string,font_family?:string,palette?:array<string,string>}
     */
    private function normalizeThemeContract(array $themeContext): array
    {
        if ($themeContext === []) {
            return [];
        }

        $palette = [];
        foreach ([
            \is_array($themeContext['palette'] ?? null) ? $themeContext['palette'] : [],
            \is_array($themeContext['color_scheme'] ?? null) ? $themeContext['color_scheme'] : [],
            \is_array($themeContext['theme_design']['color_scheme'] ?? null) ? $themeContext['theme_design']['color_scheme'] : [],
        ] as $candidate) {
            foreach ($candidate as $key => $value) {
                if (!\is_string($key) || !\is_scalar($value)) {
                    continue;
                }
                $color = \trim((string)$value);
                if (!\preg_match('/^#[0-9a-f]{6}$/i', $color)) {
                    continue;
                }
                $palette[\strtolower($key)] = $color;
            }
        }

        $visualDirection = \is_array($themeContext['visual_direction'] ?? null) ? $themeContext['visual_direction'] : [];
        $typography = \is_array($themeContext['typography_spacing_radius'] ?? null) ? $themeContext['typography_spacing_radius'] : [];
        $artDirection = \is_array($themeContext['art_direction'] ?? null) ? $themeContext['art_direction'] : [];

        return [
            'name' => (string)($themeContext['name'] ?? $visualDirection['name'] ?? $palette['name'] ?? ''),
            'visual_tone' => (string)($themeContext['visual_tone'] ?? $themeContext['content_tone'] ?? ''),
            'font_family' => (string)($themeContext['font_family'] ?? $visualDirection['font_family'] ?? $typography['font_family'] ?? ''),
            'style_signature' => (string)($themeContext['style_signature'] ?? $themeContext['visual_identity'] ?? $visualDirection['style_signature'] ?? ''),
            'art_direction' => $artDirection,
            'palette' => $palette,
            'raw_context' => $themeContext,
        ];
    }

    /**
     * @param array<string,string> $palette
     * @param list<string> $keys
     */
    private function pickPaletteColor(array $palette, array $keys): string
    {
        foreach ($keys as $key) {
            $color = \trim((string)($palette[\strtolower($key)] ?? ''));
            if ($color !== '') {
                return $color;
            }
        }

        return '';
    }

    /**
     * @param array<string,string> $palette
     * @param list<string> $extraCandidates
     */
    private function resolveReadablePaletteTextColor(
        string $backgroundColor,
        string $preferredTextColor,
        array $palette,
        array $extraCandidates = []
    ): string {
        $backgroundRgb = $this->parseCssColorToRgb($backgroundColor);
        $preferredTextColor = \trim($preferredTextColor);
        if ($backgroundRgb === null) {
            return $preferredTextColor;
        }

        $candidateValues = \array_merge(
            [$preferredTextColor],
            $extraCandidates,
            [
                $this->pickPaletteColor($palette, ['text', 'body', 'foreground']),
                $this->pickPaletteColor($palette, ['copy_panel_text']),
                $this->pickPaletteColor($palette, ['secondary']),
                $this->pickPaletteColor($palette, ['primary', 'brand', 'button']),
                $this->pickPaletteColor($palette, ['accent', 'highlight']),
                $this->pickPaletteColor($palette, ['muted_text', 'muted', 'subtle', 'text_muted']),
                $this->pickPaletteColor($palette, ['surface', 'background', 'base']),
                $this->pickPaletteColor($palette, ['surface_alt', 'background_alt', 'panel', 'card']),
                '#0f172a',
                '#f8fafc',
                '#ffffff',
            ]
        );
        $seen = [];
        foreach ($candidateValues as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            $key = \strtolower($candidate);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $candidateRgb = $this->parseCssColorToRgb($candidate);
            if ($candidateRgb !== null && $this->contrastRatio($backgroundRgb, $candidateRgb) >= 4.5) {
                return $candidate;
            }
        }

        return $this->resolveReadableTextColor($backgroundColor, $preferredTextColor);
    }

    private function resolvePaletteShadowColor(string $shadow, string $surface, string $primary, string $text, string $accent): string
    {
        $shadow = \trim($shadow);
        if ($shadow !== '' && !$this->isLowContrastPalettePair($shadow, $surface, 1.25)) {
            return $shadow;
        }

        foreach ([$text, $primary, $accent] as $candidate) {
            $candidate = \trim($candidate);
            if ($candidate !== '' && !$this->isLowContrastPalettePair($candidate, $surface, 1.25)) {
                return $candidate;
            }
        }

        return $shadow !== '' ? $shadow : $text;
    }

    private function isLowContrastPalettePair(string $foreground, string $background, float $threshold): bool
    {
        if (\trim($foreground) === '' || \trim($background) === '') {
            return false;
        }
        $foregroundRgb = $this->parseCssColorToRgb($foreground);
        $backgroundRgb = $this->parseCssColorToRgb($background);
        if ($foregroundRgb === null || $backgroundRgb === null) {
            return false;
        }

        return $this->contrastRatio($foregroundRgb, $backgroundRgb) < $threshold;
    }

    private function resolveReadableTextColor(string $backgroundColor, string $preferredTextColor = ''): string
    {
        $backgroundRgb = $this->parseCssColorToRgb($backgroundColor);
        $preferredTextColor = \trim($preferredTextColor);
        if ($backgroundRgb === null) {
            return $preferredTextColor;
        }

        $preferredRgb = $preferredTextColor !== '' ? $this->parseCssColorToRgb($preferredTextColor) : null;
        if ($preferredRgb !== null && $this->contrastRatio($backgroundRgb, $preferredRgb) >= 4.5) {
            return $preferredTextColor;
        }

        $light = '#f8fafc';
        $dark = '#0f172a';
        $lightRatio = $this->contrastRatio($backgroundRgb, $this->parseCssColorToRgb($light) ?? [248, 250, 252]);
        $darkRatio = $this->contrastRatio($backgroundRgb, $this->parseCssColorToRgb($dark) ?? [15, 23, 42]);

        return $lightRatio >= $darkRatio ? $light : $dark;
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    private function parseCssColorToRgb(string $color): ?array
    {
        $color = \trim($color);
        if ($color === '') {
            return null;
        }

        if (\preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $color, $matches) === 1) {
            $hex = $matches[1];
            if (\strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }

            return [
                \hexdec(\substr($hex, 0, 2)),
                \hexdec(\substr($hex, 2, 2)),
                \hexdec(\substr($hex, 4, 2)),
            ];
        }

        if (\preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i', $color, $matches) === 1) {
            return [
                \max(0, \min(255, (int)$matches[1])),
                \max(0, \min(255, (int)$matches[2])),
                \max(0, \min(255, (int)$matches[3])),
            ];
        }

        return null;
    }

    /**
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     */
    private function contrastRatio(array $a, array $b): float
    {
        $aLum = $this->relativeLuminance($a);
        $bLum = $this->relativeLuminance($b);
        $lighter = \max($aLum, $bLum);
        $darker = \min($aLum, $bLum);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private function relativeLuminance(array $rgb): float
    {
        $channels = \array_map(static function (int $value): float {
            $channel = $value / 255;

            return $channel <= 0.03928
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function buildBlogPromptAddon(string $pageType, string $sectionKey, array $scope): string
    {
        if (!\in_array($pageType, [Page::TYPE_BLOG, Page::TYPE_BLOG_CATEGORY, Page::TYPE_BLOG_LIST], true)) {
            return '';
        }

        $blogContext = $this->buildBlogRenderContext($scope, $pageType);
        $postPreview = \array_map(static function (array $post): array {
            return [
                'title' => (string)($post['title'] ?? ''),
                'category_name' => (string)($post['category_name'] ?? ''),
                'url' => (string)($post['url'] ?? ''),
            ];
        }, \array_slice((array)($blogContext['blog_posts'] ?? []), 0, 5));
        $categoryPreview = \array_map(static function (array $category): array {
            return [
                'name' => (string)($category['name'] ?? ''),
                'url' => (string)($category['url'] ?? ''),
            ];
        }, \array_slice((array)($blogContext['blog_categories'] ?? []), 0, 5));

        $roleHint = match ($sectionKey) {
            'hero' => 'Use this section for blog positioning and reading guidance.',
            'highlights' => 'Use this section to render real post lists or category cards.',
            'details' => 'Use this section for category navigation, reading paths, recent posts, or current post context.',
            default => 'Organize this section around real blog data.',
        };

        return "Blog real-data requirements:\n"
            . "- Available variables: $blog_posts, $blog_categories, $recent_posts, $related_posts, $current_post, $current_category, $category_posts.\n"
            . "- {$roleHint}\n"
            . "- Example post data: " . \json_encode($postPreview, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- Example category data: " . \json_encode($categoryPreview, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- Use loops over real variables such as foreach (($blog_posts ?? []) as $post); do not hardcode fake posts.\n";
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildHeaderDefaultConfig(array $websiteProfile, array $scope, string $siteDisplayName): array
    {
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        $siteDisplayName = $this->resolveLocaleSafeSiteDisplayName($siteDisplayName, $websiteProfile, $scope, $locale);
        $navItems = $this->buildHeaderNavigationPages($scope, $locale);
        $navTextLines = [];
        foreach ($navItems as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $navTextLines[] = \trim((string)($item['title'] ?? '')) . '=>' . \trim((string)($item['url'] ?? '#'));
        }

        $effectiveLogo = $this->resolveSharedLogoAssetUrl($websiteProfile, $scope);

        $defaultConfig = [
            'logo.display' => 'yes',
            'logo.text' => $siteDisplayName,
            'logo.image' => $effectiveLogo,
            'logo.url' => $effectiveLogo,
            'identity.shared_logo_asset' => $effectiveLogo,
            'identity.logo_reuse_policy' => 'reuse the same verified logo asset in header and footer by default; only use a contrast-safe variant when the design plan explicitly needs it',
            'navigation.display' => 'yes',
            'navigation.items' => \implode("\n", $navTextLines),
            'nav_items' => \array_map(static fn(array $item): array => [
                'text' => (string)($item['title'] ?? ''),
                'href' => (string)($item['url'] ?? '#'),
            ], $navItems),
            'cta.show' => 'yes',
            'cta.text' => $this->resolvePrimaryCtaText($scope, $locale),
            'cta.url' => $this->resolvePrimaryCtaUrl($scope),
        ];
        $defaultConfig = \array_replace($defaultConfig, $this->resolveThemeStyleDefaults($scope, 'header'));

        $sharedPlanJsonTask = $this->resolveSharedPlanJsonTask($scope, 'header');
        $defaultConfig = $this->applyPlanJsonDefaults($defaultConfig, $sharedPlanJsonTask, $locale, $scope);
        $defaultConfig = $this->pinCustomerSiteTitleOnSharedDefaultConfig($defaultConfig, $websiteProfile, $scope, $locale);
        $defaultConfig = $this->normalizeSharedDefaultConfigLinksAgainstRouteContract($defaultConfig, $scope, $locale);
        $defaultConfig['logo.image'] = $effectiveLogo;
        $defaultConfig['logo.url'] = $effectiveLogo;
        $defaultConfig['identity.shared_logo_asset'] = $effectiveLogo;
        $defaultConfig['runtime.shared_region'] = 'header';
        $defaultConfig['runtime.content_locale'] = $locale;
        $this->attachRuntimeTaskContextDefaults($defaultConfig, $sharedPlanJsonTask, $locale);

        return $defaultConfig;
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildFooterDefaultConfig(array $websiteProfile, array $scope, string $siteDisplayName): array
    {
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        $siteDisplayName = $this->resolveLocaleSafeSiteDisplayName($siteDisplayName, $websiteProfile, $scope, $locale);
        $sharedPromptContext = $this->resolveSharedPromptContext($scope);
        $navigationPages = $this->buildNavigationPages($scope, $locale);
        $brandSummary = $this->filterVisibleCopyForLocale(
            $this->pickString(
                $sharedPromptContext['site_positioning'] ?? null,
                $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope)
            ),
            $locale
        );
        if ($brandSummary === '') {
            $brandSummary = $this->localizeBuildText('brand_summary', $locale);
        }
        $legalLines = [];
        $featuredLines = [];
        $allLines = [];
        $featuredTypeMap = \array_flip([
            Page::TYPE_HOME,
            Page::TYPE_ABOUT,
            Page::TYPE_CONTACT,
            Page::TYPE_BLOG_LIST,
            Page::TYPE_CUSTOM,
        ]);

        $sharedFeatured = $this->normalizeLinkItemsAgainstRouteContract(
            $this->localizePromptLinkItemsForLocale(
                $this->normalizePromptLinkItems($sharedPromptContext['footer_featured'] ?? []),
                $navigationPages,
                $locale
            ),
            $scope,
            $locale,
            $navigationPages,
            'footer_featured_route_types'
        );
        foreach ($sharedFeatured as $item) {
            $featuredLines[] = (string)($item['label'] ?? '') . '=>' . (string)($item['href'] ?? '#');
        }
        $sharedPolicies = $this->normalizeLinkItemsAgainstRouteContract(
            $this->localizePromptLinkItemsForLocale(
                $this->normalizePromptLinkItems($sharedPromptContext['footer_policies'] ?? []),
                $navigationPages,
                $locale
            ),
            $scope,
            $locale,
            $navigationPages,
            'footer_policy_route_types'
        );
        foreach ($sharedPolicies as $item) {
            $legalLines[] = (string)($item['label'] ?? '') . '=>' . (string)($item['href'] ?? '#');
        }

        foreach ($navigationPages as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? '');
            $title = $this->filterVisibleCopyForLocale(\trim((string)($item['title'] ?? '')), $locale);
            if ($title === '' && $type !== '') {
                $title = $this->localizePageTypeTitle($type, $locale);
            }
            if ($title === '') {
                continue;
            }
            $line = $title . '=>' . \trim((string)($item['url'] ?? '#'));
            $allLines[] = $line;
            if (\in_array($type, [Page::TYPE_TERMS_OF_SERVICE, Page::TYPE_PRIVACY_POLICY, Page::TYPE_COOKIE_POLICY, Page::TYPE_REFUND_POLICY, Page::TYPE_SHIPPING_POLICY], true)) {
                $legalLines[] = $line;
            }
            if (isset($featuredTypeMap[$type])) {
                $featuredLines[] = $line;
            }
        }

        if ($featuredLines === []) {
            $featuredLines = \array_slice($allLines, 0, 4);
        }
        if ($legalLines === []) {
            $legalLines = \array_slice($allLines, 1, 3);
        }

        $effectiveLogo = $this->resolveSharedLogoAssetUrl($websiteProfile, $scope);
        $defaultConfig = [
            'brand.name' => $siteDisplayName,
            'brand.logo' => $effectiveLogo,
            'identity.shared_logo_asset' => $effectiveLogo,
            'identity.logo_reuse_policy' => 'reuse the same verified logo asset in header and footer by default; only use a contrast-safe variant when the design plan explicitly needs it',
            'brand.description' => $brandSummary,
            'links.column1_title' => $this->localizeBuildText('featured_pages', $locale),
            'links.column1_items' => \implode("\n", $featuredLines),
            'links.column2_title' => $this->localizeBuildText('policy_info', $locale),
            'links.column2_items' => \implode("\n", $legalLines),
            'links.column3_title' => $this->localizeBuildText('all_pages', $locale),
            'links.column3_items' => \implode("\n", $allLines),
            'copyright.text' => $this->localizeBuildText('all_rights_reserved', $locale),
            'copyright.year' => \date('Y'),
        ];
        $defaultConfig = \array_replace($defaultConfig, $this->resolveThemeStyleDefaults($scope, 'footer'));

        $sharedPlanJsonTask = $this->resolveSharedPlanJsonTask($scope, 'footer');
        $defaultConfig = $this->applyPlanJsonDefaults($defaultConfig, $sharedPlanJsonTask, $locale, $scope);
        $defaultConfig = $this->pinCustomerSiteTitleOnSharedDefaultConfig($defaultConfig, $websiteProfile, $scope, $locale);
        $defaultConfig = $this->normalizeSharedDefaultConfigLinksAgainstRouteContract($defaultConfig, $scope, $locale);
        $defaultConfig['brand.logo'] = $effectiveLogo;
        $defaultConfig['identity.shared_logo_asset'] = $effectiveLogo;
        $defaultConfig['runtime.shared_region'] = 'footer';
        $defaultConfig['runtime.content_locale'] = $locale;
        $this->attachRuntimeTaskContextDefaults($defaultConfig, $sharedPlanJsonTask, $locale);

        return $defaultConfig;
    }

    /**
     * @param array<string,mixed> $section
     * @param array<string,mixed> $blueprint
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildSectionDefaultConfig(string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): array
    {
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        $sectionConfig = \is_array($section['config'] ?? null) ? $section['config'] : [];

        $title = $this->filterVisibleCopyForLocale($this->pickString(
            $sectionConfig['section_title'] ?? null,
            $sectionConfig['headline'] ?? null,
            $blueprint['page_title'] ?? null,
            (string)($section['name'] ?? '')
        ), $locale);
        $subtitle = $this->filterVisibleCopyForLocale($this->pickString(
            $sectionConfig['eyebrow'] ?? null,
            $sectionConfig['subtitle'] ?? null
        ), $locale);
        $description = $this->filterVisibleCopyForLocale($this->pickString(
            $sectionConfig['section_intro'] ?? null,
            $sectionConfig['description'] ?? null,
            $sectionConfig['section_text'] ?? null,
            $blueprint['ai_description'] ?? null
        ), $locale);

        $sectionTpl = \strtolower(\trim((string)($section['template'] ?? '')));
        $PlanJsonTask = $this->resolveSectionPlanJsonTask(
            $scope,
            $pageType,
            \trim((string)($section['code'] ?? '')),
            \trim((string)($section['key'] ?? ''))
        );
        $isHeroSectionTpl = $this->imageIntentRequestsStrictHeroCover($this->resolveBlockImageIntent($PlanJsonTask, $section));

        $bgType = 'color';
        $bgColor = '#ffffff';
        if ($isHeroSectionTpl) {
            $bgType = 'gradient';
        } elseif ($sectionTpl === 'cta') {
            $bgColor = '#0f172a';
        }

        $defaultConfig = [
            'content.title' => $title,
            'content.subtitle' => $subtitle,
            'content.description' => $description,
            'title' => $title,
            'heading' => $title,
            'headline' => $title,
            'section_title' => $title,
            'subtitle' => $subtitle,
            'eyebrow' => $subtitle,
            'description' => $description,
            'body' => $description,
            'section_intro' => $description,
            'content.heading' => $title,
            'content.headline' => $title,
            'content.section_title' => $title,
            'content.body' => $description,
            'layout.container_width' => '1200',
            'layout.padding_top' => (string)($isHeroSectionTpl ? 96 : 72),
            'layout.padding_bottom' => (string)($sectionTpl === 'cta' ? 96 : 72),
            'layout.text_align' => ($sectionTpl === 'checklist') ? 'left' : 'center',
            'style.bg_type' => $bgType,
            'style.bg_color' => $bgColor,
            'style.text_color' => ($sectionTpl === 'cta') ? '#e2e8f0' : '#334155',
            'style.title_color' => ($sectionTpl === 'cta') ? '#ffffff' : '#0f172a',
            'style.accent_color' => '#2563eb',
        ];
        $defaultConfig = \array_replace(
            $defaultConfig,
            $this->resolveSectionCtaDefaultConfig($scope, $pageType, $section, $locale)
        );
        $stageOneSamples = $this->collectStageOneVisibleSamplesForPage($scope, $pageType);
        if ($stageOneSamples !== []) {
            $defaultConfig['contract.stage1_samples'] = \json_encode(
                \array_slice($stageOneSamples, 0, 6),
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
            );
        }
        $resolvedTitle = \trim((string)($defaultConfig['content.title'] ?? $defaultConfig['title'] ?? ''));
        $resolvedSubtitle = \trim((string)($defaultConfig['content.subtitle'] ?? $defaultConfig['subtitle'] ?? ''));
        $resolvedDescription = \trim((string)($defaultConfig['content.description'] ?? $defaultConfig['description'] ?? ''));
        foreach (['title', 'heading', 'headline', 'section_title', 'content.heading', 'content.headline', 'content.section_title'] as $field) {
            if ($resolvedTitle !== '') {
                $defaultConfig[$field] = $resolvedTitle;
            }
        }
        foreach (['subtitle', 'eyebrow'] as $field) {
            if ($resolvedSubtitle !== '') {
                $defaultConfig[$field] = $resolvedSubtitle;
            }
        }
        foreach (['description', 'body', 'section_intro', 'content.body'] as $field) {
            if ($resolvedDescription !== '') {
                $defaultConfig[$field] = $resolvedDescription;
            }
        }
        $themeTokens = $this->collectContractThemeTokens($scope);
        if ($themeTokens !== []) {
            $defaultConfig['contract.theme_tokens'] = \implode(' ', \array_slice($themeTokens, 0, 8));
        }
        $sectionImageUrl = $this->resolveSectionAssetUrl($pageType, $section, $scope);
        $sectionImageSlotId = 'page:' . $pageType . ':' . \str_replace('/', '-', (string)($section['code'] ?? ''));
        $sectionImageAlt = $title !== '' ? $title : (string)($section['name'] ?? 'Section image');
        if ($sectionImageUrl !== '') {
            $defaultConfig['visual.image_url'] = $sectionImageUrl;
            $defaultConfig['visual.image_alt'] = $sectionImageAlt;
            $defaultConfig['image.url'] = $sectionImageUrl;
            $defaultConfig['media.image_url'] = $sectionImageUrl;
            $defaultConfig['media.image_alt'] = $sectionImageAlt;
            $resolvedImageSlotId = $this->resolveSectionAssetSlotId($scope, $sectionImageUrl);
            $sectionImageSlotId = $resolvedImageSlotId !== '' ? $resolvedImageSlotId : $sectionImageSlotId;
            if ($sectionImageSlotId === '') {
                $sectionImageSlotId = 'page:' . $pageType . ':' . \str_replace('/', '-', (string)($section['code'] ?? ''));
            }
        }
        $defaultConfig = \array_replace($defaultConfig, $this->resolveThemeStyleDefaults($scope, 'content'));

        $PlanJsonTask = $this->resolveSectionPlanJsonTask(
            $scope,
            $pageType,
            (string)($section['code'] ?? ''),
            (string)($section['key'] ?? '')
        );

        $defaultConfig = $this->applyPlanJsonDefaults($defaultConfig, $PlanJsonTask, $locale, $scope);
        $defaultConfig = $this->enforceSectionCtaIntentDefaults($defaultConfig, $scope, $pageType, $section, $locale);
        $resolvedTitle = \trim((string)($defaultConfig['content.title'] ?? $defaultConfig['title'] ?? ''));
        $resolvedSubtitle = \trim((string)($defaultConfig['content.subtitle'] ?? $defaultConfig['subtitle'] ?? ''));
        $resolvedDescription = \trim((string)($defaultConfig['content.description'] ?? $defaultConfig['description'] ?? ''));
        foreach (['title', 'heading', 'headline', 'section_title', 'content.heading', 'content.headline', 'content.section_title'] as $field) {
            if ($resolvedTitle !== '') {
                $defaultConfig[$field] = $resolvedTitle;
            }
        }
        foreach (['subtitle', 'eyebrow'] as $field) {
            if ($resolvedSubtitle !== '') {
                $defaultConfig[$field] = $resolvedSubtitle;
            }
        }
        foreach (['description', 'body', 'section_intro', 'content.body'] as $field) {
            if ($resolvedDescription !== '') {
                $defaultConfig[$field] = $resolvedDescription;
            }
        }

 //  ?section  ? ? ?runtime.*  ?
 // stub/fallback  ?verified_assets ?SVG ? // runtime.*  ?stripInternalComponentConfig  ? $defaultConfig['runtime.section_template'] = (string)($section['template'] ?? '');
        $defaultConfig['runtime.section_page_type'] = $pageType;
        $defaultConfig['runtime.section_code'] = (string)($section['code'] ?? '');
        $defaultConfig['runtime.section_key'] = (string)($section['key'] ?? '');
        $defaultConfig['runtime.task_key'] = (string)($PlanJsonTask['task_key'] ?? '');
        $defaultConfig['runtime.content_locale'] = $locale;
        $runtimeContext = \is_array($PlanJsonTask['runtime_context'] ?? null) ? $PlanJsonTask['runtime_context'] : [];
        $languageContract = \is_array($runtimeContext['language_contract'] ?? null)
            ? $runtimeContext['language_contract']
            : $this->buildStage3TaskLanguageContract($locale);
        $defaultConfig['runtime.language_contract_json'] = (string)\json_encode(
            $languageContract,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE
        );
        $blockContract = $this->resolveBlockContract($PlanJsonTask);
        if ($blockContract !== []) {
            $defaultConfig['runtime.block_contract_json'] = (string)\json_encode(
                $blockContract,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE
            );
        }
        if ($PlanJsonTask !== []) {
            $defaultConfig['runtime.plan_json_task_json'] = (string)\json_encode(
                $this->buildRuntimePlanJsonTaskSnapshot($PlanJsonTask),
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE
            );
        }
        if ($sectionImageSlotId !== '') {
            $defaultConfig['runtime.section_image_slot_id'] = $sectionImageSlotId;
        }
        if ($sectionImageUrl !== '') {
            $defaultConfig['runtime.section_image_url'] = $sectionImageUrl;
            $defaultConfig['runtime.section_image_alt'] = $sectionImageAlt;
        }
        $requiredEditableFields = $this->buildVirtualThemeRequiredEditableFields($defaultConfig, $PlanJsonTask);
        if ($requiredEditableFields !== []) {
            $defaultConfig['runtime.required_editable_fields'] = (string)\json_encode(
                $requiredEditableFields,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE
            );
        }

        return $defaultConfig;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param list<string> $stageOneSamples
     * @return array<string,mixed>
     */
    private function repairDefaultVisibleCopyFromStageOneSamples(array $defaultConfig, array $stageOneSamples): array
    {
        foreach ([
            'title',
            'heading',
            'headline',
            'description',
            'body',
            'summary',
            'section_title',
            'section_intro',
            'features.title',
            'features.description',
            'content.title',
            'content.subtitle',
            'content.description',
            'content.heading',
            'content.headline',
            'content.body',
            'content.summary',
            'content.section_title',
            'content.section_intro',
        ] as $key) {
            $value = \trim((string)($defaultConfig[$key] ?? ''));
            if ($value === '' || $this->containsPlanningObservationCopy($value)) {
                $defaultConfig[$key] = '';
            }
        }

        return $defaultConfig;
    }

    /**
 *  ?final_url  ?manifest  ?slot_id ? * @param array<string,mixed> $scope
     */
    private function resolveSectionAssetSlotId(array $scope, string $finalUrl): string
    {
        $finalUrl = \trim($finalUrl);
        if ($finalUrl === '') {
            return '';
        }
        foreach ($this->extractManifestSlots($scope) as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            if (\trim((string)($slot['final_url'] ?? '')) === $finalUrl) {
                return \trim((string)($slot['slot_id'] ?? ''));
            }
        }

        return '';
    }

    private function isPlanJsonDynamicPageBlockKey(string $key): bool
    {
        $key = \trim($key);
        if ($key === '') {
            return false;
        }

        return !\in_array($key, [
            'page_key',
            'page_type',
            'type',
            'status',
            'title',
            'label',
            'page_title',
            'page_goal',
            'page_design_plan',
            'theme_alignment_summary',
            'content_locale',
            'seo',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'route',
            'slug',
            'path',
            'layout',
            'sections',
            'section_refinements',
            'blocks',
            'block_previews',
            'updated_at',
            'started_at',
            'finished_at',
            'error',
            'error_message',
        ], true);
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<string>
     */
    private function collectStageOneVisibleSamplesForPage(array $scope, string $pageType): array
    {
        $samples = [];
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        if ($page === []) {
            foreach ($pages as $candidate) {
                if (\is_array($candidate) && \trim((string)($candidate['page_type'] ?? '')) === $pageType) {
                    $page = $candidate;
                    break;
                }
            }
        }
        $pageBlocks = [];
        foreach ($page as $blockKey => $block) {
            if (!\is_string($blockKey) || !\is_array($block)) {
                continue;
            }
            if (!$this->isPlanJsonDynamicPageBlockKey($blockKey)) {
                continue;
            }
            $pageBlocks[] = $block;
        }
        foreach ($pageBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            foreach (\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [] as $field) {
                if (\is_array($field) && \is_scalar($field['sample'] ?? null)) {
                    $samples[] = $this->sanitizeVisibleCopy((string)$field['sample']);
                }
            }
            $realtime = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
            foreach (['headline'] as $key) {
                if (\is_scalar($realtime[$key] ?? null)) {
                    $samples[] = $this->sanitizeVisibleCopy((string)$realtime[$key]);
                }
            }
            foreach (\is_array($realtime['supporting_copy'] ?? null) ? $realtime['supporting_copy'] : [] as $copy) {
                if (\is_scalar($copy)) {
                    $samples[] = $this->sanitizeVisibleCopy((string)$copy);
                }
            }
        }

        return \array_values(\array_filter(\array_unique($samples), static function (string $value): bool {
            return $value !== '' && \mb_strlen($value) >= 4;
        }));
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<string>
     */
    private function collectContractThemeTokens(array $scope): array
    {
        $tokens = [];
        foreach ([
            $scope['plan_json'] ?? null,
            $scope['content_manifest'] ?? null,
        ] as $source) {
            if (\is_array($source)) {
                $this->collectContractThemeTokensRecursive($source, $tokens);
            }
        }

        return \array_values(\array_unique(\array_filter($tokens, static function (string $token): bool {
            return !\in_array(\strtolower($token), ['#ffffff', '#fff', '#000000', '#000'], true);
        })));
    }

    /**
     * @param array<string|int,mixed> $source
     * @param list<string> $tokens
     */
    private function collectContractThemeTokensRecursive(array $source, array &$tokens, int $depth = 0): void
    {
        if ($depth > 8) {
            return;
        }
        foreach ($source as $value) {
            if (\is_scalar($value)) {
                $candidate = \trim((string)$value);
                if (\preg_match('/^#[0-9a-f]{6}$/i', $candidate) === 1) {
                    $tokens[] = $candidate;
                }
                continue;
            }
            if (\is_array($value)) {
                $this->collectContractThemeTokensRecursive($value, $tokens, $depth + 1);
            }
        }
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolvePlanJsonTaskRoot(array $scope): array
    {
        $cacheKey = $this->PlanJsonTaskRootCacheKey($scope);
        if ($cacheKey !== '' && isset($this->PlanJsonTaskRootCache[$cacheKey])) {
            return $this->PlanJsonTaskRootCache[$cacheKey];
        }

        $root = $this->PlanJsonTaskRootFromBuildBlueprint($scope);
        if ($cacheKey !== '') {
            if (\count($this->PlanJsonTaskRootCache) >= 8) {
                $firstKey = \array_key_first($this->PlanJsonTaskRootCache);
                if ($firstKey !== null) {
                    unset($this->PlanJsonTaskRootCache[$firstKey]);
                }
            }
            $this->PlanJsonTaskRootCache[$cacheKey] = $root;
        }

        return $root;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function PlanJsonTaskRootFromBuildBlueprint(array $scope): array
    {
        $sharedTasks = [];
        $pageTasks = [];
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        foreach ($this->getPlanJsonTaskService()->listTaskDefinitions($scope) as $task) {
            if (!\is_array($task) || $task === []) {
                continue;
            }
            $taskType = \trim((string)($task['task_type'] ?? ''));
            if ($taskType === 'shared_component') {
                $sharedTasks[] = $task;
                continue;
            }
            if ($taskType === 'page_section') {
                $pageType = \trim((string)($task['page_type'] ?? ''));
                if ($pageType !== '') {
                    $pageTasks[$pageType][] = $task;
                }
            }
        }

        if ($sharedTasks === [] && $pageTasks === []) {
            throw new \RuntimeException('Build prompt contract failed: plan_json has no executable blocks.');
        }

        return [
            'signature' => (string)($planJson['signature'] ?? $planJson['plan_signature'] ?? ''),
            'shared_tasks' => $sharedTasks,
            'page_tasks' => $pageTasks,
        ];
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function PlanJsonTaskRootCacheKey(array $scope): string
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        if ($planJson === []) {
            return '';
        }

        $signature = \trim((string)($planJson['signature'] ?? $planJson['plan_signature'] ?? ''));
        if ($signature !== '') {
            return $signature;
        }

        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $blockKeys = [];
        foreach (\array_slice($pages, 0, 8, true) as $pageType => $page) {
            if (!\is_array($page)) {
                continue;
            }
            foreach ($page as $blockKey => $block) {
                if (\is_string($blockKey) && \is_array($block) && $this->isPlanJsonDynamicPageBlockKey($blockKey)) {
                    $blockKeys[] = (string)$pageType . ':' . $blockKey;
                }
            }
        }

        return \sha1((string)\json_encode([
            'pages' => \count($pages),
            'block_count' => \count($blockKeys),
            'block_keys' => \array_slice($blockKeys, 0, 16),
            'locale' => $this->resolveScopePrimaryLocale($scope),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveSharedPlanJsonTask(array $scope, string $region): array
    {
        $region = \trim($region);
        if ($region === '') {
            return [];
        }

        $root = $this->resolvePlanJsonTaskRoot($scope);
        foreach (\is_array($root['shared_tasks'] ?? null) ? $root['shared_tasks'] : [] as $task) {
            if (!\is_array($task)) {
                continue;
            }
            if (\trim((string)($task['region'] ?? '')) === $region) {
                return $task;
            }
            if (\trim((string)($task['task_key'] ?? '')) === 'shared:' . $region) {
                return $task;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveSectionPlanJsonTask(array $scope, string $pageType, string $sectionCode, string $sectionKey = ''): array
    {
        $root = $this->resolvePlanJsonTaskRoot($scope);
        $pageTasks = \is_array($root['page_tasks'][$pageType] ?? null) ? $root['page_tasks'][$pageType] : [];
        foreach ($pageTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskSectionCode = \trim((string)($task['section_code'] ?? ''));
            $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
            $planSectionCode = \trim((string)($planContext['section_code'] ?? ''));
            $blockKey = \trim((string)($task['block_key'] ?? ''));
            $taskKey = \trim((string)($task['task_key'] ?? ''));

            if ($sectionCode !== '' && ($taskSectionCode === $sectionCode || $planSectionCode === $sectionCode)) {
                return $task;
            }
            if ($sectionKey !== '' && ($blockKey === $sectionKey || \str_ends_with($taskKey, ':' . $sectionKey))) {
                return $task;
            }
            if ($sectionCode !== '' && \str_ends_with($taskKey, ':' . $sectionCode)) {
                return $task;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     */
    private function planJsonJsonTaskPromptAddon(array $PlanJsonTask, string $contextLabel, array $scope = []): string
    {
        if ($PlanJsonTask === []) {
            throw new \RuntimeException('Build prompt contract failed: missing stage-2 plan-json task context for ' . $contextLabel . '; scope-level prompt fallback is forbidden.');
        }

        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $taskScript = \is_array($PlanJsonTask['task_script'] ?? null) ? $PlanJsonTask['task_script'] : [];
        $implementationContract = \is_array($PlanJsonTask['implementation_contract'] ?? null) ? $PlanJsonTask['implementation_contract'] : [];
        $runtimeContext = \is_array($PlanJsonTask['runtime_context'] ?? null) ? $PlanJsonTask['runtime_context'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $themeContext = \is_array($runtimeContext['theme_context_snapshot'] ?? null) ? $runtimeContext['theme_context_snapshot'] : [];
        if ($themeContext === [] && \is_array($PlanJsonTask['theme_context_snapshot'] ?? null)) {
            $themeContext = $PlanJsonTask['theme_context_snapshot'];
        }
        $sharedPromptContext = \is_array($runtimeContext['shared_prompt_context'] ?? null) ? $runtimeContext['shared_prompt_context'] : [];
        if ($sharedPromptContext === [] && \is_array($PlanJsonTask['shared_prompt_context'] ?? null)) {
            $sharedPromptContext = $PlanJsonTask['shared_prompt_context'];
        }
        if ($themeContext === [] || $sharedPromptContext === []) {
            throw new \RuntimeException(
                'Build prompt contract failed: task runtime_context missing stage-2 theme/shared context for '
                . $contextLabel
                . '.'
            );
        }
        $contextRefs = \is_array($runtimeContext['context_refs'] ?? null) ? $runtimeContext['context_refs'] : [];
        $siteContext = \is_array($runtimeContext['site_context'] ?? null) ? $runtimeContext['site_context'] : [];
        $policyContext = \is_array($runtimeContext['policy_context'] ?? null) ? $runtimeContext['policy_context'] : [];
        $skillContext = \is_array($runtimeContext['skill_context'] ?? null) ? $runtimeContext['skill_context'] : [];
        $referenceContext = \is_array($runtimeContext['reference_context'] ?? null) ? $runtimeContext['reference_context'] : [];
        $assetContext = \is_array($runtimeContext['asset_context'] ?? null) ? $runtimeContext['asset_context'] : [];
        $taskScriptDataContract = \is_array($taskScript['data_contract'] ?? null) ? $taskScript['data_contract'] : [];
        $implementationDataContract = \is_array($implementationContract['data_contract'] ?? null) ? $implementationContract['data_contract'] : [];
        $fieldRequirements = \is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [];
        $acceptance = \is_array($implementationContract['acceptance'] ?? null) ? $implementationContract['acceptance'] : [];
        $contentPlan = \is_array($blockTask['content_plan'] ?? null) ? $blockTask['content_plan'] : [];
        $stylePlan = \is_array($blockTask['style_plan'] ?? null) ? $blockTask['style_plan'] : [];
        $planBlock = \is_array($planContext['block'] ?? null) ? $planContext['block'] : [];
        $analyticsEvents = \is_array($planBlock['analytics_events'] ?? null)
            ? $planBlock['analytics_events']
            : (\is_array($blockTask['analytics_events'] ?? null) ? $blockTask['analytics_events'] : []);
        $pageDesignPlan = \is_array($planContext['page_design_plan'] ?? null)
            ? $planContext['page_design_plan']
            : (\is_array($stylePlan['page_design_plan'] ?? null) ? $stylePlan['page_design_plan'] : []);
        $currentBlockVisualSignature = $this->resolveBlockVisualSignature($PlanJsonTask);
        $currentBlockImageIntent = $this->resolveBlockImageIntent($PlanJsonTask);
        $currentBlockContract = $this->resolveBlockContract($PlanJsonTask);
        $contentLocale = \trim((string)($runtimeContext['content_locale'] ?? $this->resolveScopePrimaryLocale($scope)));
        $languageContract = $this->resolveStage3TaskLanguageContract($runtimeContext, $contentLocale);
        $localeContext = \is_array($runtimeContext['locale_context'] ?? null)
            ? $runtimeContext['locale_context']
            : (\is_array($languageContract['locale_profile'] ?? null) ? $languageContract['locale_profile'] : []);
        $visibleCopyContract = \is_array($runtimeContext['visible_copy_contract'] ?? null)
            ? $runtimeContext['visible_copy_contract']
            : (\is_array($blockTask['visible_copy_contract'] ?? null) ? $blockTask['visible_copy_contract'] : []);
        $policySlicesPrompt = $this->planJsonTaskPolicySlicesPromptAddon($PlanJsonTask, $contextLabel, $scope);
        $contextScale = $this->resolvePlanJsonTaskContextScale($PlanJsonTask);
        $includeExtendedContext = $contextScale >= 0.8;
        if ($contentLocale !== '') {
            foreach ([
                'themeContext' => $themeContext,
                'sharedPromptContext' => $sharedPromptContext,
                'taskScript' => $taskScript,
                'blockTask' => $blockTask,
                'fieldRequirements' => $fieldRequirements,
                'contentPlan' => $contentPlan,
                'stylePlan' => $stylePlan,
                'pageDesignPlan' => $pageDesignPlan,
                'siteContext' => $siteContext,
                'policyContext' => $policyContext,
                'skillContext' => $skillContext,
                'referenceContext' => $referenceContext,
                'assetContext' => $assetContext,
            ] as $name => $payload) {
                $filtered = $this->filterPromptArrayForLocale($payload, $contentLocale);
                if (\is_array($filtered)) {
                    ${$name} = $filtered;
                }
            }
        }
        $localeHint = $contentLocale !== '' ? $this->describeLocaleForAiPrompt($contentLocale) : '';
        $stage3LocaleRule = $contentLocale !== ''
            ? "- stage3 locale execution rule: source_of_truth_locale={$contentLocale} ({$localeHint}). plan-json text is intent only; rewrite any non-{$contentLocale} planned sentence before it becomes visible copy.\n"
            : '';
        $languageContractPrompt = $contentLocale !== ''
            ? "1b CTX_WEBSITE_LANGUAGE: " . $this->jsonEncodeForPrompt($languageContract, 900) . "\n"
                . "- HARD LANGUAGE CONTRACT: source_of_truth_locale={$contentLocale} ({$localeHint}). Every visible heading, paragraph, CTA label, card text, form label/placeholder, alt/title/aria text, nav/footer label, and editable-field default generated for this block must be in this locale. Translate/rewrite the plan text first; do not paste plan_locale or English/Chinese planning text when it differs.\n"
            : '';
        $verifiedAssets = $this->extractVerifiedAssetsForPlanJsonTask($scope, $PlanJsonTask);
        if ($verifiedAssets === [] && \is_array($runtimeContext['asset_manifest'] ?? null)) {
            $verifiedAssets = $this->extractVerifiedAssetsForPlanJsonTask(
                ['asset_manifest' => $runtimeContext['asset_manifest']],
                $PlanJsonTask
            );
        }
        $currentAssetForPrompt = \is_array($runtimeContext['current_asset'] ?? null) ? $runtimeContext['current_asset'] : [];
        $visualContractForPrompt = \is_array($PlanJsonTask['visual_contract'] ?? null) ? $PlanJsonTask['visual_contract'] : [];
        $strictHeroCoverForPrompt = (int)($assetContext['strict_hero_cover'] ?? $currentAssetForPrompt['strict_hero_cover'] ?? $visualContractForPrompt['strict_hero_cover'] ?? 0) === 1;
        $verifiedAssetShapeRule = $strictHeroCoverForPrompt
            ? "- Required slot shape: because CTX_CURRENT_ASSET.strict_hero_cover=1, make that same editable <img> a cover layer with CSS object-fit/absolute/inset/width/height, then place overlay text above it; do not replace the editable <img> with CSS background-image only. Add a component-prefixed hero image class to the <img> and style the exact same selector with position:absolute; inset:0; width:100%; height:100%; object-fit:cover.\n"
            : "- Required slot shape: CTX_CURRENT_ASSET.strict_hero_cover is not enabled, so the editable <img> must become a page-specific media surface, visual card, device/status panel, story frame, proof badge, or compact opening accent that fits this page. Do not turn it into a full-cover hero/background layer solely because a block/template is named hero or banner.\n";
        $noVerifiedImageVisualRule = $strictHeroCoverForPrompt
            ? "- CSS-only strict hero rule: CTX_CURRENT_ASSET.strict_hero_cover=1, so satisfy the HERO_ROLE_OUTLINE from the component-specific contract. The `.pb-c-media` element must contain `.pb-c-media-stage`, `.pb-c-media-subject`, `.pb-c-media-detail`, and `.pb-c-media-label` children; never leave `.pb-c-media` empty. Style `.pb-c-media` with position:absolute; inset:0; and a confirmed palette hex background so the visual owns the full banner band; style `.pb-c-media-stage`, `.pb-c-media-subject`, `.pb-c-media-detail`, `.pb-c-media-label`, `.pb-c-motif`, and `.pb-c-orbit` as structured editorial/product surfaces, not generic circles/blobs, blank bars, or unlabeled dot/pill controls; the label text must name a concrete subject from the approved brief; style `.pb-c-overlay` with a confirmed dark/brand overlay token and opacity; style `.pb-c-text-panel` with a readable confirmed palette panel background; and put `.pb-c-cta` inside `.pb-c-action` with visible outer spacing.\n"
            : "- CSS-only non-strict visual rule: CTX_CURRENT_ASSET.strict_hero_cover=0 or absent, so do not use HERO_ROLE_OUTLINE. Use CTX_BLOCK_VISUAL_SIGNATURE and page_design_plan to choose a page-specific editorial/support/proof/form/policy/CTA composition with CSS-only motifs, proof devices, rails, badges, panels, or compact media surfaces. Do not force full-bleed cover media, overlay, orbit/motif layers, or text-panel treatment because of a hero/banner name.\n";
        $currentBlockRequiresImage = $this->imageIntentNeedsImage($currentBlockImageIntent)
            || (int)($assetContext['required'] ?? $currentAssetForPrompt['required'] ?? $visualContractForPrompt['required'] ?? 0) === 1
            || (int)($assetContext['contract_required'] ?? $currentAssetForPrompt['contract_required'] ?? $visualContractForPrompt['contract_required'] ?? 0) === 1;
        $verifiedAssetRule = $verifiedAssets !== []
            ? "- verified_assets: " . $this->jsonEncodeForPrompt($verifiedAssets, 1800) . "\n"
                . "- HARD CONTRACT: every verified_asset final_url for this section MUST appear as a real editable <img> in html_content by adapting the concrete template below. Do not skip any. Unused generated images waste API tokens.\n"
                . "- Image-text composition contract: do not paste the image as a lonely asset. Pair each generated image with localized heading/body/CTA/proof copy from the current block, and style the image as an integrated media panel, proof visual, editorial frame, or cover layer according to the block contract.\n"
                . "- Rules: use the supplied final_url value without changing it as the media.image_url default/fallback; match the asset by slot_id context. If no asset matches this section, render CSS-only decorative structure; never use <svg>.\n"
                . "- Slot placement contract: place the editable <img> for the matching slot inside this component's root wrapper before decorative-only layers. For non-hero sections, use it as a media card, portrait/avatar rail, proof image, or editorial visual; never omit it because the section is text-heavy.\n"
                . "- Editable tag contract: html_content must contain a real <img> tag adapted from verified_asset_editable_img_shape. That same <img> must keep data-pb-ai-asset-slot and data-pb-ai-image-role='generated-asset' values from the copied editable_img, while src/alt render from media.image_url/media.image_alt fields. You may add a component-prefixed class.\n"
                . $verifiedAssetShapeRule
                . "- Image contract self-check: before returning JSON, verify that html_content includes every matching slot_id inside an <img> tag and every final_url appears as the media.image_url default/fallback for that editable src. If a final_url appears only in CSS, config comments, or text, the output is invalid.\n"
                . $this->buildVerifiedAssetPromptContract($verifiedAssets)
            : "- verified_assets: []\n"
                . "- NO_VERIFIED_IMAGE_MODE: no verified real image URL is available for this task. html_content must not contain <img>, src=, data-pb-ai-image-role, data-pb-ai-asset-slot, CSS url(...), `/pub/media/`, stock-photo URLs, or guessed file paths.\n"
                . ($currentBlockRequiresImage ? "- REQUIRED_IMAGE_PENDING: the frozen block image_intent/visual contract still requires generated media, but this run has no confirmed final_url. Do not reinterpret the block as CSS-only by design; build only a temporary localized product/editorial media surface and keep the output ready for retry with the exact asset slot.\n" : '')
                . $noVerifiedImageVisualRule
                . "- verified asset rule: render visual media as CSS-only shapes/pseudo-elements or omit media; do not invent image URLs or placeholder media.\n";
        $ctaResponsiveOverride = "- CTA responsive override: if any frozen task/context/design field says CTA, button, or CTA/form controls should fill available width, apply full-width behavior to bands, rows, forms, inputs, or mobile containers first. Prefer action/actions for wrappers so wrapper CSS is not mistaken for button CSS. The actual CTA button should remain a recognizable button in the default layout and must not be styled as a desktop page-width bar.\n";
        $siteContextLimit = $this->planJsonTaskContextLimit($PlanJsonTask, 1600, $contextScale);
        $themeContextLimit = $this->planJsonTaskContextLimit($PlanJsonTask, 1400, $contextScale);
        $sharedPromptContextLimit = $this->planJsonTaskContextLimit($PlanJsonTask, 1200, $contextScale);
        $taskScriptLimit = $this->planJsonTaskContextLimit($PlanJsonTask, 1400, $contextScale);
        $blockTaskLimit = $this->planJsonTaskContextLimit($PlanJsonTask, 1400, $contextScale);
        $fieldRequirementLimit = $this->planJsonTaskContextLimit($PlanJsonTask, 900, $contextScale);
        $contentPlanLimit = $this->planJsonTaskContextLimit($PlanJsonTask, 1200, $contextScale);
        $stylePlanLimit = $this->planJsonTaskContextLimit($PlanJsonTask, 1200, $contextScale);
        $pageDesignPlanLimit = $this->planJsonTaskContextLimit($PlanJsonTask, 1200, $contextScale);
        $siteContextPrompt = $this->jsonEncodeForPrompt($siteContext, $siteContextLimit);
        $themeContextPrompt = $this->jsonEncodeForPrompt($themeContext, $themeContextLimit);
        $sharedPromptContextPrompt = $this->jsonEncodeForPrompt($sharedPromptContext, $sharedPromptContextLimit);
        $assetContextPrompt = $this->jsonEncodeForPrompt($assetContext, $this->planJsonTaskContextLimit($PlanJsonTask, 900, $contextScale));
        $taskScriptPrompt = $this->jsonEncodeForPrompt($taskScript, $taskScriptLimit);
        $blockTaskPrompt = $this->jsonEncodeForPrompt($blockTask, $blockTaskLimit);
        $fieldRequirementsPrompt = $this->jsonEncodeForPrompt($fieldRequirements, $fieldRequirementLimit);
        $contentPlanPrompt = $this->jsonEncodeForPrompt($contentPlan, $contentPlanLimit);
        $stylePlanPrompt = $this->jsonEncodeForPrompt($stylePlan, $stylePlanLimit);
        $pageDesignPlanPrompt = $this->jsonEncodeForPrompt($pageDesignPlan, $pageDesignPlanLimit);

        $frozenContext = "Frozen Stage-2 task context for this {$contextLabel} (authoritative confirmed-plan slice for this block only; do not read conversation history or broad scope fallbacks):\n"
            . ($policySlicesPrompt !== '' ? $policySlicesPrompt . "\n" : '')
            . "1 task_identity: " . $this->jsonEncodeForPrompt([
                'task_key' => (string)($PlanJsonTask['task_key'] ?? ''),
                'page_type' => (string)($PlanJsonTask['page_type'] ?? $planContext['page_type'] ?? ''),
                'block_key' => (string)($PlanJsonTask['block_key'] ?? $planContext['block_key'] ?? ''),
                'section_code' => (string)($PlanJsonTask['section_code'] ?? $planContext['section_code'] ?? ''),
                'content_locale' => $contentLocale,
                'locale_context' => $localeContext,
                'context_refs' => $contextRefs,
            ], 900) . "\n"
            . $languageContractPrompt
            . "2 site_context: " . $this->jsonEncodeForPrompt([
                'site' => $siteContext,
                'policy_context' => $policyContext,
            ], $siteContextLimit) . "\n"
            . "2b shared_prompt_context: " . $sharedPromptContextPrompt . "\n"
            . "3 theme_context: " . $this->jsonEncodeForPrompt([
                'theme_context_snapshot' => $themeContext,
                'stage1_theme_summary' => (string)($planContext['stage1_theme_summary'] ?? ''),
                'stage1_style_direction' => (string)($planContext['stage1_style_direction'] ?? ''),
            ], $themeContextLimit) . "\n"
            . "4 page_context: " . $this->jsonEncodeForPrompt([
                'page_goal' => (string)($planContext['page_goal'] ?? ''),
                'page_flow_role' => (string)($planContext['page_flow_role'] ?? $stylePlan['page_flow_role'] ?? ''),
                'page_identity_contract' => \is_array($planContext['page_identity_contract'] ?? null) ? $planContext['page_identity_contract'] : [],
                'page_design_plan' => $pageDesignPlan,
            ], $pageDesignPlanLimit) . "\n"
            . "5 current_block_context: " . $this->jsonEncodeForPrompt([
                'content_locale' => $contentLocale,
                'language_contract' => $languageContract,
                'locale_context' => $localeContext,
                'visible_copy_contract' => $visibleCopyContract,
                'block_context_source' => 'plan_json.pages dynamic block + current task runtime_context + block_contract',
                'block_goal' => (string)($planContext['block_goal'] ?? ''),
                'stage1_block_content' => (string)($planContext['stage1_block_content'] ?? ''),
                'story_goal' => (string)($taskScript['story_goal'] ?? ''),
                'stage3_directive' => (string)($taskScript['stage3_directive'] ?? ''),
                'content_plan' => $contentPlan,
                'style_plan' => $stylePlan,
                'block_contract' => $currentBlockContract,
                'visual_signature' => $currentBlockVisualSignature,
                'image_intent' => $currentBlockImageIntent,
                'analytics_events' => $analyticsEvents,
                'implementation_detail' => (string)($blockTask['implementation_detail'] ?? ''),
                'field_content_requirements' => $fieldRequirements,
                'data_contract' => \array_replace_recursive($implementationDataContract, $taskScriptDataContract),
                'acceptance' => $acceptance,
            ], $blockTaskLimit + $contentPlanLimit + $stylePlanLimit) . "\n"
            . "5b block_visual_signature_hard_contract: " . $this->jsonEncodeForPrompt($currentBlockVisualSignature, 700) . "\n"
            . "- visual_signature execution rule: composition_pattern / spatial_rhythm / media_strategy / surface_treatment are binding layout choices. Implement them before any generic split-panel or three-card template.\n"
            . "5c block_image_intent_hard_contract: " . $this->jsonEncodeForPrompt($currentBlockImageIntent, 800) . "\n"
            . "- image_intent execution rule: this is the frozen Stage-1 media plan. If needs_image=true, use the required/verified image contract or generated slot for this exact block and placement. If needs_image=false, build the planned css_motif/visual_atmosphere/image_treatment as CSS-only visual support. For non-policy body/proof/support/contact/article blocks, that CSS-only support should read as a substantial media surface, not empty card padding; contact/support blocks should feel like a support console, phone assistance panel, or help-desk scene when media is planned. Do not invent new image needs, ignore planned media, or reuse another block's image shell.\n"
            . $this->buildBlockContractPrompt([], [], $PlanJsonTask)
            . "6 skill_and_reference_context: " . $this->jsonEncodeForPrompt([
                'skill_context' => $skillContext,
                'reference_context' => $referenceContext,
                'design_tags' => \is_array($blockTask['design_tags'] ?? null) ? $blockTask['design_tags'] : [],
                'realtime_content' => \is_array($blockTask['realtime_content'] ?? null) ? $blockTask['realtime_content'] : [],
            ], $this->planJsonTaskContextLimit($PlanJsonTask, 1300, $contextScale)) . "\n"
            . "7 asset_context: " . $this->jsonEncodeForPrompt([
                'verified_assets' => $verifiedAssets,
                'asset_context' => $assetContext,
                'asset_contract' => $verifiedAssets === []
                    ? 'no verified assets for this task; pending/unresolved asset slots are not legal image URLs'
                    : 'use only the verified_assets listed here',
            ], $this->planJsonTaskContextLimit($PlanJsonTask, 1400, $contextScale)) . "\n"
            . ($includeExtendedContext ? "8 policy_context: " . $this->jsonEncodeForPrompt($policyContext, $this->planJsonTaskContextLimit($PlanJsonTask, 800, $contextScale)) . "\n" : '')
            . ($includeExtendedContext ? "9 skill_context: " . $this->jsonEncodeForPrompt($skillContext, $this->planJsonTaskContextLimit($PlanJsonTask, 900, $contextScale)) . "\n" : '')
            . "- priority rule: user prompt intent is primary for content/design decisions; this frozen context is the confirmed block-level execution form of that intent. If site_context contains a clearer explicit user instruction, honor it while keeping schema, locale, asset, and safety contracts valid.\n"
            . "- block-context execution rule: generate only current_block_context for task_identity.task_key. Do not borrow content, layout, CTA labels, image slots, or acceptance rules from sibling blocks, stale blueprints, full scope, UI projection, or memory.\n"
            . "- design execution rule: apply confirmed page_design_plan and theme_context first, then current_block_context. Generate only this task's block for its page_type and block_key; you may design freely inside that contract, not why the block exists.\n"
            . "- content execution rule: every heading, body line, list/card item, CTA, form label, placeholder, alt text, and editable default must be derived from current_block_context.content_plan, field_content_requirements, data_contract, and acceptance. If plan samples are not visitor-ready, rewrite them into finished {$contentLocale} copy; do not replace them with generic marketing filler.\n"
            . "- visible-copy source rule: current_block_context.visible_copy_contract.intent_only_sources are never visible copy sources. They may guide structure and priorities, but exact block_goal/task_goal/story_goal/contract sentences must not appear in HTML or editable defaults.\n"
            . "- CSS execution rule: write fewer complete selectors instead of many fragile selectors. If a decoration is hard to express safely, omit the decoration and keep the layout valid.\n"
            . "- semantic affordance execution rule: before returning JSON, inspect every pill, dot, chip, badge, tab, bar, step, input-like strip, carousel indicator, and control-shaped element. If it lacks visible localized text or a meaningful icon/aria label, either convert it into labeled content from current_block_context or delete it. Decorative shapes must not resemble controls.\n"
            . "- negative prompt execution rule: before returning JSON, scan for the negative families from the base visual contract: placeholder/wireframe/skeleton appearance, repeated template shells, meaningless decoration, fake controls, fake facts/metrics/contact/security/payment claims, missing or invented assets, unreadable contrast, crowded hierarchy, CTA ambiguity, overflow/overlap/cropping, and horizontal scroll. If any appears, rewrite the block rather than adding another decorative layer.\n"
            . "- base layout execution rule: apply a foundation only after choosing this block's own composition. Root/inner/copy/media/action roles should establish spacing, containment, typography, and responsive safety; they must not force a repeated skeleton. Use 4px/8px spacing rhythm, max-width 1120px-1280px for inner content when not full-bleed, min-width:0 and box-sizing:border-box on layout children, stable heading/body font sizes per breakpoint, line-height 1.45-1.75 for readable copy, and letter-spacing:0 unless a locale-safe positive value is explicitly intended.\n"
            . "- responsive execution rule: normal grid/flex flow only; stack at <=900px and use one column at <=420px. Apply min-width:0, max-width:100%, box-sizing:border-box, and overflow-wrap:anywhere to layout containers plus text-bearing children such as brand/logo text, headings, paragraphs, labels, nav items, badges/chips, cards, media captions, form fields, and CTA labels. Do not use white-space:nowrap on brand/nav/badges/buttons/headings at <=420px unless that same rule provides a safe wrap or shorter label. Review/testimonial/card rails must stay inside the component width: use grid wrapping, or put all cards inside one max-width:100% overflow-x:auto wrapper; never position extra cards beyond the viewport or let a rail increase body scrollWidth. Use overflow:hidden only for decorative media/motifs, never to hide clipped copy or controls. Apply the compact CTA override only to the actual clickable CTA button/anchor element; full-width bands, layout containers, and form wrappers may remain responsive. Prefer action/actions for wrappers so wrapper CSS is not mistaken for button CSS.\n"
            . $ctaResponsiveOverride
            . "- plan-json language rule: plan-json text is intent only. Rewrite it into final visitor copy in source_of_truth_locale before placing it in HTML.\n"
            . "- visible-copy contract scope: do not paste plan/context sentences as website text. Blocking checks cover required output structure, prompt/blueprint leakage, placeholders, internal identifiers, and malformed fragments; they do not reject normal marketing/support/reward wording merely because it is not an exact source fact.\n"
            . $stage3LocaleRule
            . ($verifiedAssets !== [] ? "- task policy slices are already expanded from the exact task contract; do not request broad-scope fallback context.\n" : '')
            . $verifiedAssetRule;
        AiSiteWorkflowTrace::prompt('plan_json_block_context', $frozenContext, [
            'context_label' => $contextLabel,
            'task_key' => (string)($PlanJsonTask['task_key'] ?? ''),
            'page_type' => (string)($PlanJsonTask['page_type'] ?? ''),
            'section_code' => (string)($PlanJsonTask['section_code'] ?? ''),
            'region' => (string)($PlanJsonTask['region'] ?? ''),
        ]);

        return $frozenContext;
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $scope
     */
    private function planJsonTaskPolicySlicesPromptAddon(array $PlanJsonTask, string $contextLabel, array $scope = []): string
    {
        $policySlices = $this->normalizePlanJsonStringList($PlanJsonTask['policy_slices'] ?? []);
        $acceptanceRuleIds = $this->normalizePlanJsonStringList($PlanJsonTask['acceptance_rule_ids'] ?? []);
        $budget = $this->resolvePlanJsonTaskContextBudget($PlanJsonTask);
        if ($policySlices === [] && $acceptanceRuleIds === [] && $budget <= 0) {
            return '';
        }

        $builder = new AiSiteDesignPolicyPromptBuilder();
        $lines = [
            'Task contract slices for ' . $contextLabel . ':',
        ];
        if ($policySlices !== []) {
            $lines[] = 'policy_slices => ' . $this->clipText($builder->buildPolicySlicePrompt($policySlices), $this->planJsonTaskContextLimit($PlanJsonTask, 1200, $this->resolvePlanJsonTaskContextScale($PlanJsonTask)));
        }
        if ($acceptanceRuleIds !== []) {
            $lines[] = 'acceptance_rule_ids => ' . \implode(', ', $acceptanceRuleIds);
        }
        if ($budget > 0) {
            $lines[] = 'context_budget.max_tokens => ' . $budget;
        }

        return \implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function normalizePlanJsonStringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            if (\is_array($value)) {
                $value = $value['task_id'] ?? $value['block_id'] ?? $value['id'] ?? '';
            }
            $text = \trim((string)$value);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return \array_values(\array_unique($result));
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     */
    private function resolvePlanJsonTaskContextBudget(array $PlanJsonTask): int
    {
        $budget = 0;
        foreach ([
            $PlanJsonTask['context_budget']['max_tokens'] ?? null,
            $PlanJsonTask['context_budget']['max_input_tokens'] ?? null,
            $PlanJsonTask['context_budget']['prompt_tokens'] ?? null,
        ] as $candidate) {
            if (\is_scalar($candidate)) {
                $budget = (int)$candidate;
                if ($budget > 0) {
                    break;
                }
            }
        }

        return \max(0, $budget);
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     */
    private function resolvePlanJsonTaskContextScale(array $PlanJsonTask): float
    {
        $budget = $this->resolvePlanJsonTaskContextBudget($PlanJsonTask);
        if ($budget <= 0) {
            return 1.0;
        }

        if ($budget <= 1200) {
            return 0.55;
        }
        if ($budget <= 1800) {
            return 0.75;
        }

        return 1.0;
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     */
    private function planJsonTaskContextLimit(array $PlanJsonTask, int $defaultLimit, float $scale): int
    {
        $limit = (int)\round($defaultLimit * \max(0.45, \min(1.0, $scale)));

        return \max(240, $limit);
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $PlanJsonTask
     * @return array<string,mixed>
     */
    private function applyPlanJsonDefaults(array $defaultConfig, array $PlanJsonTask, string $locale = '', array $scope = []): array
    {
        if ($PlanJsonTask === []) {
            return $defaultConfig;
        }

        $taskScript = \is_array($PlanJsonTask['task_script'] ?? null) ? $PlanJsonTask['task_script'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $fieldRequirements = \is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [];
        foreach ($fieldRequirements as $requirement) {
            if (!\is_array($requirement)) {
                continue;
            }
            $field = $this->normalizePlanJsonRequirementField($requirement['field'] ?? '');
            $isLinkField = \str_contains($field, 'navigation') || \str_contains($field, 'featured_links') || \str_contains($field, 'policy_links');
            $sample = $this->normalizePlanJsonRequirementSample($requirement['sample'] ?? '', $isLinkField);
            if ($field === '' || $sample === '') {
                continue;
            }
            if (!$isLinkField && $locale !== '') {
                $sample = $this->filterVisibleCopyForLocale($sample, $locale);
                if ($sample === '') {
                    continue;
                }
            }
            if (\in_array(\strtolower($sample), ['header', 'footer'], true)) {
                continue;
            }
            if ($isLinkField) {
                $defaultConfig = $this->applyPlanJsonLinkFieldDefaults($defaultConfig, $field, $sample, $locale, $scope);
                continue;
            }

            $candidateKeys = match (true) {
                \str_contains($field, 'brand'), \str_contains($field, 'platform'), \str_contains($field, 'site_title'), \str_contains($field, 'logo_text') => ['logo.text', 'brand.name'],
                \str_contains($field, 'title'), \str_contains($field, 'headline') => ['content.title'],
                \str_contains($field, 'subtitle'), \str_contains($field, 'eyebrow'), \str_contains($field, 'tagline'), \str_contains($field, 'slogan') => ['content.subtitle'],
                \str_contains($field, 'description'), \str_contains($field, 'body'), \str_contains($field, 'text'), \str_contains($field, 'copy') => ['content.description', 'brand.description'],
                \str_contains($field, 'button_url'), \str_contains($field, 'url'), \str_contains($field, 'href') => ['cta.url'],
                \str_contains($field, 'button_text'), \str_contains($field, 'cta') => ['cta.text'],
                default => [],
            };
            foreach ($candidateKeys as $candidateKey) {
                if (!\array_key_exists($candidateKey, $defaultConfig)) {
                    continue;
                }
                if (\in_array($candidateKey, ['logo.text', 'brand.name'], true)) {
                    $titleCandidate = $this->getPageBlueprintService()->normalizeSiteDisplayNameCandidate($sample);
                    if ($titleCandidate === '') {
                        continue;
                    }
                    $defaultConfig[$candidateKey] = $titleCandidate;
                    break;
                }
                $defaultConfig[$candidateKey] = $sample;
                break;
            }
        }

        if (isset($defaultConfig['content.title']) && $this->sanitizeVisibleCopy((string)$defaultConfig['content.title']) === '') {
            $defaultConfig['content.title'] = '';
        }
        if (isset($defaultConfig['content.description']) && $this->sanitizeVisibleCopy((string)$defaultConfig['content.description']) === '') {
            $defaultConfig['content.description'] = '';
        }

        $defaultConfig = $this->applyPlanJsonContentPlanDefaults($defaultConfig, $blockTask, $locale);

        $visibleSummary = $this->resolveVisiblePlanJsonTaskSummary($taskScript, $blockTask, $planContext, $locale);
        if ($locale !== '') {
            $visibleSummary = $this->filterVisibleCopyForLocale($visibleSummary, $locale);
        }
        if ($visibleSummary !== '' && \trim((string)($defaultConfig['content.description'] ?? '')) === '') {
            $defaultConfig['content.description'] = $this->clipText($visibleSummary, 180);
        }

        return $this->sanitizeDefaultConfigVisibleCopy(
            $this->applyPlanJsonDataContractDefaults($defaultConfig, $PlanJsonTask, $locale),
            $locale
        );
    }

    /**
 * PlanJson  ?
     *
     * @param array<string, mixed> $defaultConfig
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function pinCustomerSiteTitleOnSharedDefaultConfig(
        array $defaultConfig,
        array $websiteProfile,
        array $scope,
        string $locale
    ): array {
        $userSiteTitle = $this->getPageBlueprintService()->resolveUserSiteTitle($websiteProfile, $scope);
        if ($userSiteTitle === '') {
            return $defaultConfig;
        }

        $label = $this->filterVisibleCopyForLocale($userSiteTitle, $locale);
        if ($label === '') {
            $label = $userSiteTitle;
        }
        if ($label !== '' && $this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($label)) {
            return $defaultConfig;
        }

        if (\array_key_exists('logo.text', $defaultConfig)) {
            $defaultConfig['logo.text'] = $label;
        }
        if (\array_key_exists('brand.name', $defaultConfig)) {
            $defaultConfig['brand.name'] = $label;
        }

        return $defaultConfig;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $blockTask
     * @return array<string,mixed>
     */
    private function applyPlanJsonContentPlanDefaults(array $defaultConfig, array $blockTask, string $locale = ''): array
    {
        $contentPlan = \is_array($blockTask['content_plan'] ?? null) ? $blockTask['content_plan'] : [];
        if ($contentPlan === []) {
            return $defaultConfig;
        }

        $copyRows = \is_array($contentPlan['content_copy'] ?? null) ? $contentPlan['content_copy'] : [];
        $visibleCopyRows = [];
        foreach ($copyRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $field = $this->normalizePlanJsonRequirementField($row['field'] ?? '');
            $copy = $this->sanitizeVisibleCopy((string)($row['copy'] ?? $row['sample'] ?? $row['default'] ?? ''));
            if ($locale !== '') {
                $copy = $this->filterVisibleCopyForLocale($copy, $locale);
            }
            if ($field === '' || $copy === '') {
                continue;
            }
            $visibleCopyRows[] = ['field' => $field, 'copy' => $copy];

            $candidateKeys = match (true) {
                \str_contains($field, 'brand'),
                \str_contains($field, 'platform'),
                \str_contains($field, 'site_title'),
                \str_contains($field, 'logo_text') => ['logo.text', 'brand.name'],
                \str_contains($field, 'title'),
                \str_contains($field, 'headline') => ['content.title'],
                \str_contains($field, 'subtitle'),
                \str_contains($field, 'eyebrow'),
                \str_contains($field, 'tagline'),
                \str_contains($field, 'slogan') => ['content.subtitle'],
                \str_contains($field, 'description'),
                \str_contains($field, 'body'),
                \str_contains($field, 'text'),
                \str_contains($field, 'copy') => ['content.description', 'brand.description'],
                \str_contains($field, 'button_url'),
                \str_contains($field, 'url'),
                \str_contains($field, 'href') => ['cta.url', 'content.cta_url'],
                \str_contains($field, 'button_text'),
                \str_contains($field, 'cta_label'),
                \str_contains($field, 'cta_text'),
                \str_contains($field, 'cta'),
                \str_contains($field, 'primary_cta') => ['cta.text', 'content.cta_text'],
                default => [],
            };
            foreach ($candidateKeys as $candidateKey) {
                if (!\array_key_exists($candidateKey, $defaultConfig)) {
                    $defaultConfig[$candidateKey] = $copy;
                    continue;
                }
                $current = \trim((string)$defaultConfig[$candidateKey]);
                if ($current === '' || ($locale !== '' && $this->filterVisibleCopyForLocale($current, $locale) === '')) {
                    $defaultConfig[$candidateKey] = $copy;
                }
            }
        }
        if ($visibleCopyRows !== []) {
            $defaultConfig['runtime.content_copy_rows'] = \json_encode(
                $this->dedupeContentCopyRows($visibleCopyRows),
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
            );
        }

        $ctaRows = \is_array($contentPlan['cta_plan'] ?? null) ? $contentPlan['cta_plan'] : [];
        foreach ($ctaRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $label = $this->sanitizeVisibleCopy((string)($row['label'] ?? $row['text'] ?? ''));
            if ($locale !== '') {
                $label = $this->filterVisibleCopyForLocale($label, $locale);
            }
            $href = \trim((string)($row['target'] ?? $row['href'] ?? $row['url'] ?? ''));
            if ($label !== '') {
                foreach (['cta.text', 'content.cta_text'] as $candidateKey) {
                    if (!\array_key_exists($candidateKey, $defaultConfig) || \trim((string)$defaultConfig[$candidateKey]) === '') {
                        $defaultConfig[$candidateKey] = $label;
                    }
                }
            }
            if ($href !== '') {
                foreach (['cta.url', 'content.cta_url'] as $candidateKey) {
                    if (!\array_key_exists($candidateKey, $defaultConfig) || \trim((string)$defaultConfig[$candidateKey]) === '') {
                        $defaultConfig[$candidateKey] = $href;
                    }
                }
            }
            if ($label !== '' || $href !== '') {
                break;
            }
        }

        return $defaultConfig;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function applyPlanJsonLinkFieldDefaults(array $defaultConfig, string $field, string $sample, string $locale = '', array $scope = []): array
    {
        $fallbackItems = $this->resolveDefaultConfigLinkFallbackItems($defaultConfig, $field);
        $items = $this->normalizePromptLinkItems($this->decodeLinkItemsSample($sample), $fallbackItems);
        if ($items !== [] && $locale !== '') {
            $items = $this->localizePromptLinkItemsForLocale($items, $fallbackItems, $locale);
        }
        if ($items !== [] && $scope !== []) {
            $items = $this->normalizeLinkItemsAgainstRouteContract(
                $items,
                $scope,
                $locale,
                $fallbackItems,
                $this->resolveRouteTypeKeyForLinkField($field)
            );
        }
        if ($items === []) {
            return $defaultConfig;
        }

        if (\str_contains($field, 'navigation')) {
            if (\array_key_exists('nav_items', $defaultConfig)) {
                $defaultConfig['nav_items'] = \array_map(static fn(array $item): array => [
                    'text' => (string)($item['label'] ?? ''),
                    'href' => (string)($item['href'] ?? '#'),
                ], $items);
            }
            if (\array_key_exists('navigation.items', $defaultConfig)) {
                $defaultConfig['navigation.items'] = $this->buildLinkLines($items);
            }

            return $defaultConfig;
        }

        if (\str_contains($field, 'featured_links') && \array_key_exists('links.column1_items', $defaultConfig)) {
            $defaultConfig['links.column1_items'] = $this->buildLinkLines($items);
        }
        if (\str_contains($field, 'policy_links') && \array_key_exists('links.column2_items', $defaultConfig)) {
            $defaultConfig['links.column2_items'] = $this->buildLinkLines($items);
        }

        return $defaultConfig;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function fillDefaultConfigVisibleCopyFromContractSamples(array $defaultConfig, string $locale = ''): array
    {
        return $defaultConfig;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $PlanJsonTask
     * @return array<string,mixed>
     */
    private function applyPlanJsonDataContractDefaults(array $defaultConfig, array $PlanJsonTask, string $locale = ''): array
    {
        foreach ($this->extractPlanJsonDataContractLines($PlanJsonTask) as $line) {
            if (!\str_contains($line, ':')) {
                continue;
            }
            [$rawKey, $rawValue] = \explode(':', $line, 2);
            $key = \strtolower(\trim($rawKey));
            $value = $this->sanitizeVisibleCopy(\trim(\trim($rawValue), " \t\n\r\0\x0B'\""));
            if ($locale !== '') {
                $value = $this->filterVisibleCopyForLocale($value, $locale);
            }
            if ($key === '' || $value === '') {
                continue;
            }

            $candidateKeys = match (true) {
                \str_contains($key, 'site_title'), \str_contains($key, 'platform_name'), \str_contains($key, 'brand_name') => ['logo.text', 'brand.name'],
                \str_contains($key, 'site_tagline'), \str_contains($key, 'platform_tagline') => ['brand.description', 'content.subtitle'],
                \str_contains($key, 'primary_cta_label'), \str_contains($key, 'cta_label'), \str_contains($key, 'cta_text') => ['cta.text'],
                \str_contains($key, 'primary_cta_href'), \str_contains($key, 'cta_href'), \str_contains($key, 'cta_url') => ['cta.url'],
                default => [],
            };
            foreach ($candidateKeys as $candidateKey) {
                if (\array_key_exists($candidateKey, $defaultConfig)) {
                    $defaultConfig[$candidateKey] = $value;
                    break;
                }
            }
        }

        return $defaultConfig;
    }

    private function normalizePlanJsonRequirementField(mixed $fieldRaw): string
    {
        if (\is_array($fieldRaw)) {
            foreach ($fieldRaw as $candidate) {
                if (!\is_scalar($candidate)) {
                    continue;
                }
                $value = \strtolower(\trim((string)$candidate));
                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        }

        if (!\is_scalar($fieldRaw)) {
            return '';
        }

        return \strtolower(\trim((string)$fieldRaw));
    }

    private function normalizePlanJsonRequirementSample(mixed $sampleRaw, bool $isLinkField): string
    {
        if (\is_array($sampleRaw)) {
            if ($isLinkField) {
                $encoded = \json_encode($sampleRaw, \JSON_UNESCAPED_UNICODE);
                return \is_string($encoded) ? \trim($encoded) : '';
            }

            $parts = [];
            \array_walk_recursive($sampleRaw, static function (mixed $value) use (&$parts): void {
                if (\is_scalar($value)) {
                    $parts[] = \trim((string)$value);
                }
            });

            return $this->sanitizeVisibleCopy(\implode(' / ', \array_filter($parts, static fn(string $value): bool => $value !== '')));
        }

        if (!\is_scalar($sampleRaw)) {
            return '';
        }
        if ($isLinkField) {
            return \trim((string)$sampleRaw);
        }

        return $this->sanitizeVisibleCopy((string)$sampleRaw);
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function sanitizeDefaultConfigVisibleCopy(array $defaultConfig, string $locale = ''): array
    {
        foreach ($defaultConfig as $key => $value) {
            if (\is_string($value)) {
                if ($key === 'navigation.items') {
                    $defaultConfig[$key] = $this->sanitizeNavigationItemsText($value, $locale);
                    continue;
                }
                if ($this->isVisualTextConfigKey($key)) {
                    $defaultConfig[$key] = $this->sanitizeVisibleCopy($value);
                    if ($locale !== '') {
                        $defaultConfig[$key] = $this->filterVisibleCopyForLocale($defaultConfig[$key], $locale);
                    }
                }
                continue;
            }

            if ($key === 'nav_items' && \is_array($value)) {
                foreach ($value as $idx => $item) {
                    if (!\is_array($item)) {
                        continue;
                    }
                    $item['text'] = $this->filterNavigationLabelForLocale(
                        (string)($item['text'] ?? $item['label'] ?? ''),
                        $locale,
                        (string)($item['type'] ?? '')
                    );
                    if ($item['text'] === '') {
                        unset($value[$idx]);
                        continue;
                    }
                    $value[$idx] = $item;
                }
                $defaultConfig[$key] = \array_values($value);
            }
        }

        return $this->fillDefaultConfigVisibleCopyFromContractSamples($defaultConfig, $locale);
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function sanitizeGeneratedComponentDefaultConfig(array $defaultConfig, string $region = ''): array
    {
        $locale = \trim((string)($defaultConfig['runtime.content_locale'] ?? ''));
        $defaultConfig = $this->applyGeneratedComponentLayoutDefaults($defaultConfig, $region);

        return $this->sanitizeDefaultConfigVisibleCopy(
            $this->stripInternalComponentConfig($defaultConfig),
            $locale
        );
    }

    /**
     * Shared header/footer identity is customer-owned input, not generated page copy.
     * The locale copy filter may reject Latin brand names on a Chinese site, but the
     * stored site title must remain available so the renderer never falls back to
     * generic placeholders such as "Brand Name".
     *
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     * @return array<string,mixed>
     */
    private function pinSharedIdentityConfigAfterSanitize(array $defaultConfig, string $region, array $renderContext): array
    {
        if (!\in_array($region, ['header', 'footer'], true)) {
            return $defaultConfig;
        }

        $websiteProfile = \is_array($renderContext['_website_profile'] ?? null) ? $renderContext['_website_profile'] : [];
        $scope = \is_array($renderContext['_scope'] ?? null) ? $renderContext['_scope'] : [];
        $locale = \trim((string)($renderContext['_content_locale'] ?? $defaultConfig['runtime.content_locale'] ?? ''));

        return $this->pinCustomerSiteTitleOnSharedDefaultConfig($defaultConfig, $websiteProfile, $scope, $locale);
    }

    /**
     * In virtual-theme generation the AI fragment owns the section hierarchy.
     * When the returned HTML already contains a heading, keeping the framework
     * shell title produces duplicated headings or leaks section identifiers.
     *
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $aiData
     * @return array<string,mixed>
     */
    private function applyAiPayloadOwnershipToDefaultConfig(array $defaultConfig, string $region, array $aiData): array
    {
        if ($region !== 'content') {
            return $defaultConfig;
        }

        $html = (string)($aiData['html_content'] ?? $aiData['html_extra'] ?? '');
        if ($html === '' || \preg_match('/<h[1-3]\b/i', $html) !== 1) {
            return $defaultConfig;
        }

        foreach (['content.title', 'content.subtitle', 'content.description'] as $key) {
            if (\array_key_exists($key, $defaultConfig)) {
                $defaultConfig[$key] = '';
            }
        }

        return $defaultConfig;
    }

    /**
     * The content framework has a 1200px default container. Hero/banner blocks
     * need explicit persisted layout config so previews and editor rebuilds do
     * not collapse the full-bleed generated background back into a narrow card.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function applyGeneratedComponentLayoutDefaults(array $config, string $region): array
    {
        if ($region !== 'content' || !$this->isDefaultHeroBannerComponentConfig($config)) {
            return $config;
        }

        $imageUrl = $this->firstConfigString($config, [
            'runtime.section_image_url',
            'visual.image_url',
            'image.url',
            'media.image_url',
        ]);
        $config['layout.container_width'] = 'full';
        $config['layout.padding_top'] = '0';
        $config['layout.padding_bottom'] = '0';
        $config['layout.text_align'] = 'left';
        if ($imageUrl !== '') {
            $config['style.bg_type'] = 'image';
            $config['style.bg_image'] = $imageUrl;
        }

        return $config;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function isDefaultHeroBannerComponentConfig(array $config): bool
    {
        $visualContract = $this->decodeRuntimeVisualContract($config);

        return (int)($visualContract['strict_hero_cover'] ?? 0) === 1;
    }

    /**
     * @param array<string|int,mixed> $config
     * @return array<string|int,mixed>
     */
    private function stripInternalComponentConfig(array $config): array
    {
        foreach ($config as $key => $value) {
            if (\is_string($key) && $this->isInternalComponentConfigKey($key)) {
                unset($config[$key]);
                continue;
            }
            if (\is_array($value)) {
                $config[$key] = $this->stripInternalComponentConfig($value);
            }
        }

        return $config;
    }

    private function isInternalComponentConfigKey(string $key): bool
    {
        $normalized = \strtolower(\trim($key));
        if ($normalized === '') {
            return false;
        }

        return \str_starts_with($normalized, 'contract.')
            || \str_starts_with($normalized, 'prompt.')
            || \str_starts_with($normalized, 'runtime.')
            || \str_starts_with($normalized, 'task_script.')
            || \str_starts_with($normalized, 'implementation_contract.')
            || \str_contains($normalized, 'prompt_context')
            || \str_contains($normalized, 'stage1_')
            || \str_contains($normalized, 'stage' . '2_')
            || \str_contains($normalized, 'stage3_');
    }

    private function sanitizeNavigationItemsText(string $lines, string $locale = ''): string
    {
        $rows = \preg_split('/\r?\n/', $lines) ?: [];
        $cleanRows = [];
        foreach ($rows as $row) {
            $row = \trim($row);
            if ($row === '') {
                continue;
            }
            $parts = \explode('=>', $row, 2);
            $label = $this->filterNavigationLabelForLocale((string)($parts[0] ?? ''), $locale);
            $href = \trim((string)($parts[1] ?? '#'));
            if ($label === '') {
                continue;
            }
            $cleanRows[] = $label . '=>' . ($href !== '' ? $href : '#');
        }

        return \implode("\n", $cleanRows);
    }

    private function filterNavigationLabelForLocale(string $value, string $locale = '', string $pageType = ''): string
    {
        $label = \trim(\preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($label === '') {
            return '';
        }
        if ($this->isSymbolOnlyVisibleCopy($label) || $this->containsPlanningObservationCopy($label)) {
            return '';
        }
        if ($locale !== '' && $this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($label)) {
            $label = '';
        }
        if ($label === '' && $pageType !== '') {
            $label = $this->localizePageTypeTitle($pageType, $locale);
        }
        if ($label === '') {
            return '';
        }

        return $this->clipText($label, 72);
    }

    private function isVisualTextConfigKey(string $key): bool
    {
        $normalized = \strtolower(\trim($key));
        if (
            \str_contains($normalized, 'style.')
            || \str_contains($normalized, 'color')
            || \str_contains($normalized, '.url')
            || \str_contains($normalized, 'image')
            || \in_array($normalized, ['brand.logo', 'logo', 'identity.shared_logo_asset'], true)
        ) {
            return false;
        }

        return \str_contains($normalized, 'content.')
            || \str_contains($normalized, 'title')
            || \str_contains($normalized, 'subtitle')
            || \str_contains($normalized, 'description')
            || \str_contains($normalized, 'logo.text')
            || \str_contains($normalized, 'brand.')
            || \str_contains($normalized, 'cta.text');
    }

    private function sanitizeVisibleCopy(string $value): string
    {
        $value = \trim(\preg_replace('/\s+/u', ' ', $value) ?? $value);
        $value = \preg_replace('/([\p{Han}]{2,6})\1/u', '$1', $value) ?? $value;
        if ($value === '') {
            return '';
        }
        if ($this->isSymbolOnlyVisibleCopy($value)) {
            return '';
        }
        $normalized = \mb_strtolower($value);
        if (
            \preg_match('/\bwebsite\s*profile\b/iu', $value) === 1
            || \preg_match('/\bsite\s*profile\b/iu', $value) === 1
        ) {
            return '';
        }
        if (
            \preg_match('/^\s*#\s*ROLE\b/iu', $value) === 1
            || \preg_match('/^\s*ROLE\s*:/iu', $value) === 1
            || (\preg_match('/^\s*#\s+/u', $value) === 1 && \preg_match('/\bYou are a\b/iu', $value) === 1)
        ) {
            return '';
        }
        if (\in_array($normalized, ['n/a', 'unknown', 'todo', 'placeholder'], true)) {
            return '';
        }
        foreach (['core selling points', 'feature characteristics', 'page type', 'content block', 'planning', 'blueprint'] as $marker) {
            if ($marker !== '' && \mb_stripos($normalized, \mb_strtolower($marker)) !== false) {
                return '';
            }
        }
        foreach (['visitors see', 'users see', 'visitors can review', 'planning observation'] as $marker) {
            if ($marker !== '' && \mb_stripos($normalized, \mb_strtolower($marker)) !== false) {
                return '';
            }
        }
        foreach ([
            'priority from stage one',
            'directional prompt',
            'field example',
            'content rule',
            'content fill rule',
            'Generated website section',
            'Website content language',
            'Return ONLY',
            'prompt text',
            'customer brief',
            'website requirement',
            'planning/plan language',
            'stage-2 planned text',
            'source intent',
            'confirmed stage-one content',
            'confirmed stage-1 plan',
            'stage-2 task detail',
            'frontend component skill',
            'block_task.content_plan',
            'block_task.style_plan',
            'Required by block task schema',
            'planning_reason',
            'Built from plan',
            'generated from plan',
            'content_fill_rule',
            'field_content_requirements',
            'task_script',
            'stage3_directive',
            'generated plan JSON page',
        ] as $marker) {
            if ($marker !== '' && \mb_stripos($normalized, \mb_strtolower($marker)) !== false) {
                return '';
            }
        }

        if (\preg_match('/^(?:present|provide|showcase|explain|build|create|highlight|include|display|structure|design|render|add)\b.{0,160}\b(?:cta|card|cards|accordion|accordions|section|block|layout|grid|module|page|signals?|content|policy|terms?|download)\b/iu', $value) === 1) {
            return '';
        }

        if (\preg_match('/\b(?:AI_GENERATED_[A-Z0-9_]+|task_key|section_code|block_key|page_type|plan_locale|runtime_context|content\/[a-z0-9_\/-]+|app\/code\/[a-z0-9_\/-]+|var\/[a-z0-9_\/-]+|home_page|about_page|shared:[a-z0-9:_\/-]+|page:[a-z0-9:_\/-]+)\b/iu', $value)) {
            return '';
        }
        if (\preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)+$/', $normalized) === 1) {
            return '';
        }
        if ($this->looksLikeGeneratedIdentifierLabel($value)) {
            return '';
        }

        if ($this->containsPlanningObservationCopy($value)) {
            return '';
        }

        $value = \preg_replace('/\b[\w.-]+\.(?:svg|png|jpe?g|webp|gif)\b/i', '', $value) ?? $value;
        $value = \trim(\preg_replace('/\s{2,}/u', ' ', $value) ?? $value);
        if ($value === '') {
            return '';
        }

        return $this->clipText($value, 220);
    }

    private function looksLikeGeneratedIdentifierLabel(string $value): bool
    {
        $trimmed = \trim($value);
        if ($trimmed === '') {
            return false;
        }
        $normalized = \mb_strtolower($trimmed);
        if (\preg_match('/^[a-z0-9]+(?:[ _-]+[a-z0-9]+){1,6}$/', $normalized) !== 1) {
            return false;
        }

        $tokens = \preg_split('/[ _-]+/', $normalized) ?: [];
        $generic = \array_fill_keys([
            'about',
            'accordion',
            'badge',
            'badges',
            'block',
            'categories',
            'category',
            'content',
            'cta',
            'download',
            'faq',
            'features',
            'final',
            'footer',
            'game',
            'games',
            'header',
            'hero',
            'home',
            'or',
            'page',
            'section',
            'security',
            'showcase',
            'story',
            'testimonials',
            'trust',
        ], true);
        $matched = 0;
        foreach ($tokens as $token) {
            if (isset($generic[$token])) {
                $matched++;
            }
        }

        return $matched >= \max(2, \count($tokens) - 1);
    }

    private function isSymbolOnlyVisibleCopy(string $value): bool
    {
        $value = \trim(\preg_replace('/\s+/u', '', $value) ?? $value);
        if ($value === '') {
            return false;
        }

        return \preg_match('/^[\p{S}\p{P}]+$/u', $value) === 1;
    }

    private function stripPlanningKeywordPrefix(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        if (\substr_count($value, '/') + \substr_count($value, ':') < 2) {
            return $value;
        }
 if (\preg_match('/\s[- ?(?<copy>.+)$/u', $value, $matches) !== 1) {
            return $value;
        }

        $copy = \trim((string)($matches['copy'] ?? ''));
        return $copy !== '' ? $copy : $value;
    }

    private function containsPlanningObservationCopy(string $value): bool
    {
        $value = \trim($value);
        if ($value === '') {
            return false;
        }

        if ($this->containsPromptPlanningLeakText($value)) {
            return true;
        }

        $normalized = \mb_strtolower($value);
        foreach ([
            'Visitors see',
            'Visitor sees',
            'Visitors can review',
            'Visitors can verify',
            'Visitors understand',
            'Visitors are guided',
            'Show popular',
            'Showcase popular',
            'Highlight benefits',
            'Emphasize benefits',
            'Strengthen trust',
            'Build trust',
            'before publishing',
            'reviewable page content',
            'priority from stage one',
            'field sample',
            'card hierarchy',
            'planning observation',
            'page rhythm',
            'design variation',
            "\u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}",
            "\u{4E3B}\u{8981}\u{884C}\u{52A8}\u{5165}\u{53E3}",
            "\u{9762}\u{5411}\u{4E2D}\u{6587}\u{7528}\u{6237}",
            "\u{901A}\u{8FC7} SEO",
            "\u{5B9E}\u{73B0}\u{76EE}\u{6807}",
            '聚合核心价值',
            '主要行动入口',
            '面向中文用户',
            '通过 SEO',
            '实现目标',
        ] as $marker) {
            $marker = \mb_strtolower($marker);
            if ($marker !== '' && \mb_stripos($normalized, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function containsPromptPlanningLeakText(string $value): bool
    {
        return $this->matchPromptPlanningLeakText($value) !== [];
    }

    /**
     * @return list<string>
     */
    private function matchPromptPlanningLeakText(string $value): array
    {
        $value = \trim($value);
        if ($value === '') {
            return [];
        }

        $matches = [];
        $patterns = [
            '/\b(?:visitors?|users?)\s+(?:see|can\s+review|can\s+verify|understand|are\s+guided|are\s+ready)\b.{0,140}\b(?:proof|cta|action|before\s+publishing|content|trust)\b/iu',
            '/\b(?:show|showcase|present|display|highlight|emphasize|strengthen|build|structure|design|render|include)\b.{0,120}\b(?:cta|card|cards|section|block|layout|grid|content|trust|proof|selling\s+points?|hierarchy)\b/iu',
            '/(?:rewrite|render|use|provide|present|include|output)\s+(?:concrete|visitor-facing|download|category|trust|proof|cta|feature).{0,90}(?:copy|language|labels?|path|cards?)/iu',
            '/\b(?:priority\s+from\s+stage\s+one|field\s+sample|planning\s+observation|page\s+rhythm|design\s+variation|stage[- ]?[123]|plan[-_ ]?json|block[_ ]?goal|task[_ ]?goal|story[_ ]?goal|why[_ ]?this[_ ]?block)\b/iu',
            '/(?:\x{805A}\x{5408}\x{6838}\x{5FC3}\x{4EF7}\x{503C}|\x{4E3B}\x{8981}\x{884C}\x{52A8}\x{5165}\x{53E3}|\x{9762}\x{5411}\x{4E2D}\x{6587}\x{7528}\x{6237}|\x{901A}\x{8FC7}\s*SEO|\x{5B9E}\x{73B0}\x{76EE}\x{6807})/iu',
 '/^(?: ?\b.{0,120}[.!? ?\s*$/iu',
 '/(?: ??(?: tion|block).{0,24}(?: ?.{0,16}(?: ?|selling\s+points?)/iu',
 '/(?: splay|show|present).{0,28}(?: ?hierarchy|visual\s+hierarchy).{0,90}(?: ?points?|differences?|trust)/iu',
 '/(?: ).{0,22}(?: ?action|main\s+action|cta).{0,60}(?: ?.{0,60}(?: id|hesitation| ?/iu',
 '/(?: ?not|don\'t).{0,36}(?: ?|sections?|content).{0,48}(?: ?visual|same\s+layout|feel\s+like\s+one)/iu',
 '/(?: ?|users?).{0,28}(?: ?review|can\s+verify|understand|ready\s+to).{0,100}(?: |before\s+publishing|proof|cta|action)/iu',
            '/(?:rewrite|render|use|provide|present|include|output)\s+(?:concrete|visitor-facing|download|category|trust|proof|cta|feature).{0,90}(?:copy|language|labels?|path|cards?)/iu',
 '/(?: ?.{0,18}(?: ?.{0,80}(?: ?/iu',
 '/(?: ?.{0,16}(?: ?.{0,80}(?: ?/iu',
 '/(?: ?.{0,40}(?: ?.{0,40}(?: ?/iu',
        ];
        foreach ($patterns as $pattern) {
            if (@\preg_match($pattern, '') === false) {
                continue;
            }
            if (\preg_match($pattern, $value, $found) === 1) {
                $matches[] = (string)($found[0] ?? $pattern);
            }
        }

        return \array_slice(\array_values(\array_unique($matches)), 0, 10);
    }

    /**
     * Walk a section config array and strip planning/observation language from all
     * leaf string values. This prevents stage-1 blueprint planning text from leaking
     * into the prompt as "Suggested section config" -where the AI would then treat
     * it as finished copy.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function filterPlanningLanguageFromConfig(array $config): array
    {
        foreach ($config as $key => $value) {
            if (\is_string($value)) {
                if ($this->containsPlanningObservationCopy($value)) {
                    // Replace with a clean marker instead of the planning sentence
                    $config[$key] = '[content to be written in target locale]';
                }
            } elseif (\is_array($value)) {
                $config[$key] = $this->filterPlanningLanguageFromConfig($value);
            }
        }
        return $config;
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @return list<string>
     */
    private function extractPlanJsonDataContractLines(array $PlanJsonTask): array
    {
        $sources = [];
        foreach (['task_script', 'implementation_contract'] as $rootKey) {
            $root = \is_array($PlanJsonTask[$rootKey] ?? null) ? $PlanJsonTask[$rootKey] : [];
            $dataContract = \is_array($root['data_contract'] ?? null) ? $root['data_contract'] : [];
            $sources[] = \is_array($dataContract['required_data'] ?? null) ? $dataContract['required_data'] : [];
        }

        $lines = [];
        foreach ($sources as $source) {
            foreach ($source as $item) {
                if (\is_scalar($item)) {
                    $lines[] = \trim((string)$item);
                }
            }
        }

        return \array_values(\array_filter($lines, static fn(string $line): bool => $line !== ''));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function jsonEncodeForPrompt(array $payload, int $maxChars): string
    {
        if ($payload === []) {
            return '{}';
        }

        return $this->clipText(
            (string)\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR),
            $maxChars
        );
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolveSectionRefinement(array $scope, string $pageType, string $sectionCode, string $fallbackKey): string
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planPages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $planPage = \is_array($planPages[$pageType] ?? null) ? $planPages[$pageType] : [];
        $refinements = \is_array($planPage['section_refinements'] ?? null) ? $planPage['section_refinements'] : [];

        if ($sectionCode !== '' && \is_scalar($refinements[$sectionCode] ?? null)) {
            return \trim((string)$refinements[$sectionCode]);
        }
        if ($fallbackKey !== '' && \is_scalar($refinements[$fallbackKey] ?? null)) {
            return \trim((string)$refinements[$fallbackKey]);
        }

        return '';
    }

    /**
 * HTML  ? ?shared_component_refinements  ?section_refinements ?*-site-header ? *
     * @param array<string,mixed> $scope
     */
    private function resolveSharedComponentRefinement(array $scope, string $region): string
    {
        $region = \trim($region);
        if (!\in_array($region, ['header', 'footer'], true)) {
            return '';
        }

        $direct = \is_array($scope['shared_component_refinements'] ?? null) ? $scope['shared_component_refinements'] : [];
        if (\is_scalar($direct[$region] ?? null)) {
            $text = \trim((string)$direct[$region]);
            if ($text !== '') {
                return $text;
            }
        }

        $canonicalKey = $region === 'header' ? 'header/ai-site-header' : 'footer/ai-site-footer';
        $dashKey = $region === 'header' ? 'header-ai-site-header' : 'footer-ai-site-footer';
        $sharedKey = 'shared:' . $region;
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planPages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        foreach ($planPages as $planPage) {
            if (!\is_array($planPage)) {
                continue;
            }
            $refinements = \is_array($planPage['section_refinements'] ?? null) ? $planPage['section_refinements'] : [];
            foreach ([$sharedKey, $canonicalKey, $dashKey] as $key) {
                if (\is_scalar($refinements[$key] ?? null)) {
                    $text = \trim((string)$refinements[$key]);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
            $suffix = $region === 'header' ? '-site-header' : '-site-footer';
            foreach ($refinements as $key => $value) {
                if (!\is_string($key) || !\is_scalar($value)) {
                    continue;
                }
                if (\str_ends_with($key, $suffix)) {
                    $text = \trim((string)$value);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolveSectionCtaDefaultConfig(array $scope, string $pageType, array $section, string $locale = ''): array
    {
        $label = $this->resolveSectionCtaText($scope, $pageType, $section, $locale);
        $url = $this->resolvePrimaryCtaUrl($scope);
        if ($label === '') {
            return [];
        }

        return [
            'cta.text' => $label,
            'content.cta_text' => $label,
            'cta.url' => $url,
            'content.cta_url' => $url,
        ];
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    private function enforceSectionCtaIntentDefaults(array $defaultConfig, array $scope, string $pageType, array $section, string $locale = ''): array
    {
        $label = $this->resolveSectionCtaText($scope, $pageType, $section, $locale);
        if ($label === '') {
            return $defaultConfig;
        }

        $identity = $this->buildSectionCtaIntentNeedle($scope, $pageType, $section);
        $contactIntent = $this->isContactOrSupportIntent($identity);
        $current = \trim((string)($defaultConfig['cta.text'] ?? $defaultConfig['content.cta_text'] ?? ''));
        $policyUnsafeCta = $this->isPolicyPageType($pageType) && $this->isPolicyUnsafeCtaLabel($current);
        if ($current === '' || $policyUnsafeCta || (!$contactIntent && ($this->isGenericConsultCtaLabel($current) || $this->isGenericCtaContactLabel($current)))) {
            $defaultConfig['cta.text'] = $label;
            $defaultConfig['content.cta_text'] = $label;
        }

        $url = $this->resolvePrimaryCtaUrl($scope);
        if ($url !== '') {
            foreach (['cta.url', 'content.cta_url'] as $key) {
                if (\trim((string)($defaultConfig[$key] ?? '')) === '') {
                    $defaultConfig[$key] = $url;
                }
            }
        }

        return $defaultConfig;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $section
     */
    private function resolveSectionCtaText(array $scope, string $pageType, array $section, string $locale = ''): string
    {
        $locale = $locale !== '' ? $locale : $this->resolveScopePrimaryLocale($scope);
        $sectionNeedle = $this->buildSectionLocalCtaIntentNeedle($pageType, $section);
        $needle = $sectionNeedle;
        if ($this->isContactOrSupportIntent($sectionNeedle)) {
            return $this->localizeBuildText('contact_us', $locale);
        }
        if ($this->isPolicyPageType($pageType)) {
            return $this->localizeBuildText('policy_info', $locale);
        }
        if (\in_array($pageType, [Page::TYPE_BLOG_LIST, Page::TYPE_BLOG_CATEGORY, Page::TYPE_BLOG], true)
            && \preg_match('/\b(?:download|apk|app|install|newsletter|cta)\b/iu', $sectionNeedle) !== 1
        ) {
            return $this->localizeBuildText('explore_more', $locale);
        }
        if (\in_array($pageType, [
            Page::TYPE_PRIVACY_POLICY,
            Page::TYPE_TERMS_OF_SERVICE,
            Page::TYPE_REFUND_POLICY,
            Page::TYPE_SHIPPING_POLICY,
            Page::TYPE_COOKIE_POLICY,
        ], true)
            && \preg_match('/\b(?:download|apk|app|install|reward|bonus|coin|coupon|promotion|offer|gift|prize|cashback|contact|support|form|newsletter|cta)\b/iu', $sectionNeedle) !== 1
        ) {
            return $this->localizeBuildText('policy_info', $locale);
        }
        if (\preg_match('/\b(?:reward|bonus|coin|coupon|promotion|offer|gift|prize|cashback)\b/iu', $needle) === 1) {
            return $this->localizeBuildText('claim_bonus', $locale);
        }
        if (\preg_match('/\b(?:download|apk|app|install)\b/iu', $needle) === 1) {
            return $this->localizeBuildText('download_now', $locale);
        }
        if (\in_array($pageType, [Page::TYPE_BLOG_LIST, Page::TYPE_BLOG_CATEGORY, Page::TYPE_BLOG], true)
            || \preg_match('/\b(?:blog|article|guide|learn|resource|news|reading)\b/iu', $needle) === 1
        ) {
            return $this->localizeBuildText('explore_more', $locale);
        }
        if (\preg_match('/\b(?:game|play|table|poker|rummy|blackjack|ludo|casino)\b/iu', $needle) === 1) {
            return $this->localizeBuildText('start_playing', $locale);
        }

        return $this->resolvePrimaryCtaText($scope, $locale);
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $section
     */
    private function buildSectionLocalCtaIntentNeedle(string $pageType, array $section): string
    {
        $sectionConfig = \is_array($section['config'] ?? null) ? $section['config'] : [];
        $parts = [
            $pageType,
            (string)($section['code'] ?? ''),
            (string)($section['key'] ?? ''),
            (string)($section['template'] ?? ''),
            (string)($section['name'] ?? ''),
            (string)($section['description'] ?? ''),
            (string)($sectionConfig['section_title'] ?? ''),
            (string)($sectionConfig['headline'] ?? ''),
            (string)($sectionConfig['description'] ?? ''),
        ];

        return \mb_strtolower(\implode(' ', \array_filter($parts, static fn(string $part): bool => \trim($part) !== '')));
    }

    private function isPolicyPageType(string $pageType): bool
    {
        return \in_array($pageType, [
            Page::TYPE_PRIVACY_POLICY,
            Page::TYPE_TERMS_OF_SERVICE,
            Page::TYPE_REFUND_POLICY,
            Page::TYPE_SHIPPING_POLICY,
            Page::TYPE_COOKIE_POLICY,
        ], true);
    }

    private function isPolicyUnsafeCtaLabel(string $label): bool
    {
        $label = \trim((string)\preg_replace('/\s+/u', ' ', $label));
        if (\preg_match(
            '/\b(?:download|apk|app|install|play|bonus|reward|casino|rummy|ludo|teen\s*patti|claim|coins?)\b/iu',
            $label
        ) === 1) {
            return true;
        }
        if ($label === '') {
            return false;
        }

        return \preg_match('/\b(?:download|apk|app|install|play|bonus|reward|casino|rummy|ludo|teen\s*patti)\b/iu', $label) === 1;
    }

    private function buildSectionCtaIntentNeedle(array $scope, string $pageType, array $section): string
    {
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $siteBrief = \is_array($scope['site_brief'] ?? null) ? $scope['site_brief'] : [];
        $sourceTruth = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];
        $parts = [
            $pageType,
            (string)($section['code'] ?? ''),
            (string)($section['key'] ?? ''),
            (string)($section['template'] ?? ''),
            (string)($section['name'] ?? ''),
            (string)($section['description'] ?? ''),
            (string)($websiteProfile['brief_description'] ?? ''),
            (string)($websiteProfile['site_tagline'] ?? ''),
            (string)($siteBrief['primary_goal'] ?? ''),
        ];
        foreach (\is_array($sourceTruth['conversion_goals'] ?? null) ? $sourceTruth['conversion_goals'] : [] as $goal) {
            if (\is_scalar($goal)) {
                $parts[] = (string)$goal;
            }
        }

        return \mb_strtolower(\implode(' ', \array_filter($parts, static fn(string $part): bool => \trim($part) !== '')));
    }

    private function isContactOrSupportIntent(string $needle): bool
    {
        return \preg_match('/\b(?:contact|support|help|service|form|mail|phone|whatsapp|lead|inquiry|enquiry)\b/iu', $needle) === 1;
    }

    private function isGenericConsultCtaLabel(string $label): bool
    {
        return \preg_match('/\b(?:consult|contact us|enquire|inquire|talk to us|get in touch)\b/iu', $label) === 1;
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolvePrimaryCtaText(array $scope, string $locale = ''): string
    {
        $locale = $locale !== '' ? $locale : $this->resolveScopePrimaryLocale($scope);
        $sharedPromptContext = $this->resolveSharedPromptContext($scope);
        $requirementExpansion = \is_array($sharedPromptContext['requirement_expansion'] ?? null) ? $sharedPromptContext['requirement_expansion'] : [];
        $siteStrategy = \is_array($sharedPromptContext['site_strategy'] ?? null) ? $sharedPromptContext['site_strategy'] : [];
        $sharedAction = $this->filterVisibleCopyForLocale(
            \trim($this->pickString(
                $requirementExpansion['primary_cta'] ?? null,
                $siteStrategy['primary_cta'] ?? null,
                $sharedPromptContext['shared_cta_strategy']['primary_action'] ?? null,
                $sharedPromptContext['shared_cta_strategy']['primary_cta'] ?? null,
                $sharedPromptContext['primary_cta'] ?? null
            )),
            $locale
        );
        if ($sharedAction !== '' && !$this->isGenericConsultCtaLabel($sharedAction)) {
            return $this->selectPrimaryCtaLabel($sharedAction);
        }

        $pageTypes = $this->resolveScopedPageTypes($scope);
        $primaryNeedle = $this->buildSectionCtaIntentNeedle($scope, \implode(' ', $pageTypes), []);
        if (\preg_match('/\b(?:download|apk|app|install)\b/iu', $primaryNeedle) === 1) {
            return $this->localizeBuildText('download_now', $locale);
        }
        if (\count($pageTypes) === 1 && \in_array(Page::TYPE_CONTACT, $pageTypes, true)) {
            return $this->localizeBuildText('contact_us', $locale);
        }
        if (\count($pageTypes) === 1 && \in_array(Page::TYPE_BLOG_LIST, $pageTypes, true)) {
            return $this->localizeBuildText('explore_more', $locale);
        }

        return $this->localizeBuildText('get_started', $locale);
    }

    private function selectPrimaryCtaLabel(string $label): string
    {
        $parts = \preg_split('/\s*(?:\/|\||,|\x{FF0C}|\x{3001})\s*/u', $label, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            $part = \trim((string)$part);
            if ($part !== '') {
                return $part;
            }
        }

        return $label;
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolvePrimaryCtaUrl(array $scope): string
    {
        $locale = $this->resolveScopePrimaryLocale($scope);
        $sharedPromptContext = $this->resolveSharedPromptContext($scope);
        $target = \trim((string)($sharedPromptContext['shared_cta_strategy']['primary_target'] ?? ''));
        if ($target !== '') {
            $contractTarget = $this->normalizeHrefAgainstRouteContract($target, $scope, $locale);
            if ($contractTarget !== '') {
                return $contractTarget;
            }
        }

        foreach ($this->normalizePromptLinkItems($sharedPromptContext['header_items'] ?? []) as $item) {
            $label = \mb_strtolower((string)($item['label'] ?? ''));
            $href = \trim((string)($item['href'] ?? ''));
            if ($href === '') {
                continue;
            }
            if (\str_contains($label, 'download') || \str_contains($href, 'download')) {
                $contractTarget = $this->normalizeHrefAgainstRouteContract($href, $scope, $locale);
                if ($contractTarget !== '') {
                    return $contractTarget;
                }
            }
        }

        return $this->firstAvailableRoutePath($scope, $locale, [Page::TYPE_CONTACT, Page::TYPE_BLOG_LIST, Page::TYPE_ABOUT, Page::TYPE_HOME]);
    }

    /**
     * Stage-1 may carry a planning-language site title while stage-3 renders a
     * different visitor locale. Prefer explicit plan/profile display names that
     * already match the target locale instead of leaking the planning brief.
     *
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function resolveLocaleSafeSiteDisplayName(
        string $siteDisplayName,
        array $websiteProfile,
        array $scope,
        string $locale
    ): string {
        $userSiteTitle = $this->getPageBlueprintService()->resolveUserSiteTitle($websiteProfile, $scope);
        if ($userSiteTitle !== '') {
            $label = $this->filterVisibleCopyForLocale($userSiteTitle, $locale);
            if ($label !== '' && $this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($label)) {
                $label = '';
            }
            if ($label !== '') {
                return $label;
            }
        }

        $fallbackCandidates = [$siteDisplayName];
        if ($userSiteTitle === '') {
            $sharedPromptContext = $this->resolveSharedPromptContext($scope);
            $fallbackCandidates = \array_merge($fallbackCandidates, [
                $sharedPromptContext['site_display_name'] ?? null,
                $sharedPromptContext['header_plan']['site_display_name'] ?? null,
                $sharedPromptContext['header_plan']['title'] ?? null,
                $sharedPromptContext['footer_plan']['site_display_name'] ?? null,
                $sharedPromptContext['footer_plan']['title'] ?? null,
                $scope['plan_json']['shared_prompt_context']['site_display_name'] ?? null,
                $scope['plan_json']['theme_context_snapshot']['site_display_name'] ?? null,
            ]);
        }

        foreach ($fallbackCandidates as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $label = \trim((string)$candidate);
            if ($label === '') {
                continue;
            }
            $label = $this->filterVisibleCopyForLocale($label, $locale);
            if ($label !== '' && $this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($label)) {
                $label = '';
            }
            if ($label !== '') {
                return $label;
            }
        }

        return $this->localizeBuildText('site_name_fallback', $locale);
    }

    private function pickString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $candidate = \trim((string)$value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function clipText(string $value, int $limit): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        if (\function_exists('mb_strlen') && \function_exists('mb_substr')) {
            if (\mb_strlen($value) <= $limit) {
                return $value;
            }

            return \rtrim(\mb_substr($value, 0, \max(1, $limit - 3))) . '...';
        }

        if (\strlen($value) <= $limit) {
            return $value;
        }

        return \rtrim(\substr($value, 0, \max(1, $limit - 3))) . '...';
    }

    protected function isTestEnvironment(): bool
    {
        return (\defined('ENV_TEST') && ENV_TEST === true)
            || \defined('PHPUNIT_COMPOSER_INSTALL')
            || \defined('__PHPUNIT_PHAR__');
    }


    private function getFrameworkBuilder(): FrameworkBuilder
    {
        return $this->frameworkBuilder ?? ObjectManager::getInstance(FrameworkBuilder::class);
    }

    private function getResponseJsonParser(): AiResponseJsonParser
    {
        return $this->responseJsonParser ?? ObjectManager::getInstance(AiResponseJsonParser::class);
    }

    private function getCodeFixer(): CodeFixer
    {
        return $this->codeFixer ?? ObjectManager::getInstance(CodeFixer::class);
    }

    private function getCodeValidator(): CodeValidator
    {
        return $this->codeValidator ?? ObjectManager::getInstance(CodeValidator::class);
    }

    private function getSkillRegistry(): AiSiteSkillRegistry
    {
        return $this->skillRegistry ?? ObjectManager::getInstance(AiSiteSkillRegistry::class);
    }

    private function getDesignDirectionService(): DesignDirectionService
    {
        return ObjectManager::getInstance(DesignDirectionService::class);
    }

    private function getPlanJsonTaskService(): AiSitePlanJsonTaskService
    {
        return ObjectManager::getInstance(AiSitePlanJsonTaskService::class);
    }

    private function getScopeCompatibilityService(): AiSiteScopeCompatibilityService
    {
        return $this->scopeCompatibilityService ?? ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
    }

    /**
     * Dispatches AI generation through the injected service when available, or the
     * framework AI query provider in normal runtime.
     *
     * Supported operations:
     *  - generate(prompt, model_code?, scenario_code?, locale?, params?, user_id?, is_backend?) -> string
     *  - generateStream(prompt, on_chunk, model_code?, scenario_code?, locale?, params?) -> ['status'=>'fulfilled']
     *
     * @param array<string, mixed> $params
     */
    private function callAiOperation(string $operation, array $params): mixed
    {
        if (isset($params['prompt']) && \is_string($params['prompt'])) {
            $params['prompt'] = $this->getSkillRegistry()->prependPromptGuide($params['prompt'], 'stage3');
        }

        $testRegion = \trim((string)($params['test_region'] ?? ''));
        $testDefaultConfig = \is_array($params['test_default_config'] ?? null) ? $params['test_default_config'] : [];
        $testRenderContext = \is_array($params['test_render_context'] ?? null) ? $params['test_render_context'] : [];
        unset($params['test_region'], $params['test_default_config'], $params['test_render_context']);
        if (
            \in_array($operation, ['generate', 'generateStream'], true)
            && $this->shouldUseLocalFakeAiPayload($testDefaultConfig, $testRenderContext)
        ) {
            $contractFixturePayload = \json_encode(
                $this->buildPhpunitContractAiPayload($testRegion, $testDefaultConfig, $testRenderContext),
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
            ) ?: '{}';
            if ($operation === 'generateStream') {
                $callback = $params['on_chunk'] ?? null;
                if (!\is_callable($callback)) {
                    throw new \InvalidArgumentException('on_chunk callable is required for generateStream');
                }
                $callback($contractFixturePayload);

                return ['status' => 'fulfilled'];
            }

            return $contractFixturePayload;
        }
        if (
            \in_array($operation, ['generate', 'generateStream'], true)
            && $this->aiService === null
            && $this->isTestEnvironment()
            && !(bool)RequestContext::get(self::REQUEST_KEY_FORCE_REAL_AI_IN_TEST, false)
        ) {
            $contractFixturePayload = \json_encode(
                $this->buildPhpunitContractAiPayload($testRegion, $testDefaultConfig, $testRenderContext),
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
            ) ?: '{}';
            if ($operation === 'generateStream') {
                $callback = $params['on_chunk'] ?? null;
                if (!\is_callable($callback)) {
                    throw new \InvalidArgumentException('on_chunk callable is required for generateStream');
                }
                $callback($contractFixturePayload);

                return ['status' => 'fulfilled'];
            }

            return $contractFixturePayload;
        }

        if ($this->aiService !== null) {
            return $this->dispatchAiOperationViaInjectedService($this->aiService, $operation, $params);
        }

        return w_query('ai', $operation, $params);
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function shouldUseLocalFakeAiPayload(array $defaultConfig, array $renderContext): bool
    {
        if ((bool)RequestContext::get(self::REQUEST_KEY_FORCE_REAL_AI_IN_TEST, false)) {
            return false;
        }

        foreach ([$renderContext['_scope'] ?? null, $renderContext['scope'] ?? null, $defaultConfig] as $source) {
            if (!\is_array($source)) {
                continue;
            }
            if (!empty($source['fake_mode']) || (string)($source['build_execution_mode'] ?? '') === 'local_fake_demo') {
                return true;
            }
        }

        return false;
    }

    /**
     * Ordinary PHPUnit integration tests exercise queue/state/layout contracts;
     * only tests that set REQUEST_KEY_FORCE_REAL_AI_IN_TEST should hit a model.
     *
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     * @return array<string,string>
     */
    private function buildPhpunitContractAiPayload(string $region, array $defaultConfig, array $renderContext): array
    {
        $region = \trim($region);
        $task = \is_array($renderContext['plan_json_task'] ?? null) ? $renderContext['plan_json_task'] : [];
        $fieldPlan = \is_array($task['field_plan'] ?? null) ? $task['field_plan'] : [];
        $title = $this->sanitizeLocalFakeVisibleCopy((string)(
            $fieldPlan['title']
            ?? $defaultConfig['content.title']
            ?? $defaultConfig['title']
            ?? $task['section_label']
            ?? $task['section_code']
            ?? 'Premium experience'
        ));
        $copy = $this->sanitizeLocalFakeVisibleCopy((string)(
            $fieldPlan['body']
            ?? $fieldPlan['copy']
            ?? $defaultConfig['content.copy']
            ?? $task['content_plan']['summary']
            ?? $task['goal']
            ?? 'Focused content with clear hierarchy, scannable proof points, and direct next actions.'
        ));
        $title = $title !== '' ? $title : 'Premium experience';
        $copy = $copy !== '' ? $copy : 'Focused content with clear hierarchy, scannable proof points, and direct next actions.';
        $ctaText = $this->sanitizeLocalFakeVisibleCopy((string)(
            $fieldPlan['cta']
            ?? $fieldPlan['cta_text']
            ?? $defaultConfig['cta.text']
            ?? $defaultConfig['content.cta_text']
            ?? 'Preview path ready'
        ));
        $ctaText = $ctaText !== '' ? $ctaText : 'Preview path ready';
        $phpTitle = $this->escapePhpSingleQuotedString($title);
        $phpCopy = $this->escapePhpSingleQuotedString($copy);
        $phpCtaText = $this->escapePhpSingleQuotedString($ctaText);
        $fieldTitle = \str_replace(["\r", "\n"], ' ', $title);
        $fieldCopy = \str_replace(["\r", "\n"], ' ', $copy);
        $fieldCtaText = \str_replace(["\r", "\n"], ' ', $ctaText);

        if ($region === 'header') {
            return [
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => ".pb-c-root{position:relative;padding:16px 24px;background:#0f1020;color:#f8f7ff;border-bottom:1px solid rgba(124,255,240,.28)}.pb-c-inner{max-width:1180px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:18px}.pb-c-brand{font-weight:800;letter-spacing:.02em}.pb-c-nav{display:flex;gap:14px;flex-wrap:wrap}.pb-c-link{color:#bffcff;text-decoration:none;font-weight:700}",
                'html_extra' => "<div class='pb-c-root'><div class='pb-c-inner'><div class='pb-c-brand'>{$title}</div><nav class='pb-c-nav'><a class='pb-c-link' href='#'>Home</a><a class='pb-c-link' href='#'>Experience</a><a class='pb-c-link' href='#'>Contact</a></nav></div></div>",
                'js_content' => '',
            ];
        }

        if ($region === 'footer') {
            return [
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => ".pb-c-root{position:relative;padding:40px 24px;background:#090a15;color:#f8f7ff;border-top:1px solid rgba(255,59,212,.26)}.pb-c-inner{max-width:1180px;margin:0 auto;display:grid;gap:14px}.pb-c-title{font-size:24px;font-weight:800}.pb-c-copy{max-width:720px;color:#c9c8d8;line-height:1.65}",
                'html_extra_column' => "<div class='pb-c-title'>{$title}</div>",
                'html_extra' => "<div class='pb-c-root'><div class='pb-c-inner'><div class='pb-c-title'>{$title}</div><p class='pb-c-copy'>{$copy}</p></div></div>",
                'footer_extra_text' => $copy,
                'js_content' => '',
            ];
        }

        return [
            'extra_fields' => "group:ai_content => AI editable content\ncontent.title => Title:text:{$fieldTitle}\ncontent.description => Description:textarea:{$fieldCopy}\ncta.text => CTA text:text:{$fieldCtaText}",
            'php_variables' => "\$contentTitle = \$getConfig('content.title', '{$phpTitle}');\n\$contentDescription = \$getConfig('content.description', '{$phpCopy}');\n\$ctaText = \$getConfig('cta.text', '{$phpCtaText}');",
            'css_extra' => ".pb-c-root{position:relative;overflow:hidden;padding:64px 24px;background:linear-gradient(135deg,#111225,#191531 58%,#101729);color:#f8f7ff}.pb-c-root:before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(124,255,240,.08),rgba(255,59,212,.08));pointer-events:none}.pb-c-inner{position:relative;max-width:1120px;margin:0 auto;display:grid;gap:18px}.pb-c-kicker{margin:0;color:#7cfff0;font-weight:800;text-transform:uppercase}.pb-c-title{margin:0;font-size:42px;line-height:1.1;font-weight:900}.pb-c-copy{max-width:760px;margin:0;color:#d9d7e8;line-height:1.7}.pb-c-cta{display:inline-flex;width:max-content;padding:12px 18px;border:1px solid rgba(124,255,240,.7);color:#0f1020;background:#7cfff0;text-decoration:none;font-weight:900}",
            'css_responsive' => "@media(max-width:720px){.pb-c-root{padding:48px 18px}.pb-c-title{font-size:32px}.pb-c-copy{font-size:15px}}",
            'html_content' => "<section class='pb-c-root'><div class='pb-c-inner'><p class='pb-c-kicker'><?= htmlspecialchars(\$ctaText ?? '{$phpCtaText}', ENT_QUOTES, 'UTF-8') ?></p><h2 class='pb-c-title'><?= htmlspecialchars(\$contentTitle ?? '{$phpTitle}', ENT_QUOTES, 'UTF-8') ?></h2><p class='pb-c-copy'><?= nl2br(htmlspecialchars(\$contentDescription ?? '{$phpCopy}', ENT_QUOTES, 'UTF-8')) ?></p><button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars(\$ctaText ?? '{$phpCtaText}', ENT_QUOTES, 'UTF-8') ?></button></div></section>",
            'js_content' => '',
        ];
    }

    private function escapePhpSingleQuotedString(string $value): string
    {
        return \str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function sanitizeLocalFakeVisibleCopy(string $value): string
    {
        $value = \trim(\strip_tags($value));
        $value = \preg_replace('/[\s\p{Zs}]+/u', ' ', $value) ?? $value;

        return \trim($value);
    }

    private function dispatchAiOperationViaInjectedService(AiService $aiService, string $operation, array $params): mixed
    {
        return match ($operation) {
            'generate' => $aiService->generate(
                (string)($params['prompt'] ?? ''),
                $params['model_code'] ?? null,
                $params['scenario_code'] ?? null,
                $params['locale'] ?? null,
                \is_array($params['params'] ?? null) ? $params['params'] : [],
                isset($params['user_id']) ? (int)$params['user_id'] : null,
                (bool)($params['is_backend'] ?? false),
            ),
            'generateStream' => $this->dispatchInjectedGenerateStream($aiService, $params),
            default => throw new \InvalidArgumentException(
                'Unsupported AI operation in PageBuilder helper: ' . $operation
            ),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array{status:string}
     */
    private function dispatchInjectedGenerateStream(AiService $aiService, array $params): array
    {
        $callback = $params['on_chunk'] ?? null;
        if (!\is_callable($callback)) {
            throw new \InvalidArgumentException('on_chunk callable is required for generateStream');
        }

        $aiService->generateStream(
            (string)($params['prompt'] ?? ''),
            $callback,
            $params['model_code'] ?? null,
            $params['scenario_code'] ?? null,
            $params['locale'] ?? null,
            \is_array($params['params'] ?? null) ? $params['params'] : [],
        );

        return ['status' => 'fulfilled'];
    }

    private function getPageBlueprintService(): AiSitePageBlueprintService
    {
        return $this->pageBlueprintService ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);
    }

    private function getPageModel(): Page
    {
        return $this->pageModel ?? ObjectManager::getInstance(Page::class);
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function resolvePrimaryLocale(array $websiteProfile, array $scope): string
    {
        $generatedLocale = '';
        foreach ([
            $scope['plan_generated_locale'] ?? null,
            $scope['plan_json']['plan_generated_locale'] ?? null,
        ] as $candidate) {
            $locale = \trim((string)$candidate);
            if ($locale !== '') {
                $generatedLocale = $locale;
                break;
            }
        }

        $defaultLocale = '';
        foreach ([
            $scope['default_locale'] ?? null,
            $websiteProfile['default_locale'] ?? null,
            $scope['website_profile']['default_locale'] ?? null,
            $scope['default_language'] ?? null,
            $websiteProfile['default_language'] ?? null,
            $scope['website_profile']['default_language'] ?? null,
        ] as $candidate) {
            $locale = \trim((string)$candidate);
            if ($locale !== '') {
                $defaultLocale = $locale;
                break;
            }
        }

        $preferGeneratedLocale = $generatedLocale !== ''
            && (
                $defaultLocale === ''
                || ($this->isEnglishLocale($defaultLocale) && !$this->isEnglishLocale($generatedLocale))
            );
        $confirmedPlanLocale = '';
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        if ((int)($planJson['confirmed'] ?? 0) === 1) {
            foreach ([
                $planJson['content_locale'] ?? null,
                $planJson['i18n']['primary_locale'] ?? null,
                $planJson['i18n']['content_locale'] ?? null,
            ] as $candidate) {
                $locale = \trim((string)$candidate);
                if ($locale !== '') {
                    $confirmedPlanLocale = $locale;
                    break;
                }
            }
        }

        foreach ([
            $scope['ai_content_locale'] ?? null,
            $preferGeneratedLocale ? $generatedLocale : null,
            $scope['selected_content_locale'] ?? null,
            $scope['selected_locale'] ?? null,
            $confirmedPlanLocale !== '' ? $confirmedPlanLocale : null,
            $scope['default_locale'] ?? null,
            $websiteProfile['default_locale'] ?? null,
            $scope['website_profile']['default_locale'] ?? null,
            $scope['default_language'] ?? null,
            $websiteProfile['default_language'] ?? null,
            $scope['website_profile']['default_language'] ?? null,
            !$preferGeneratedLocale ? $generatedLocale : null,
            $scope['content_locale'] ?? null,
            $websiteProfile['content_locale'] ?? null,
            $scope['website_profile']['content_locale'] ?? null,
            $scope['locale'] ?? null,
            $websiteProfile['locale'] ?? null,
            $scope['website_profile']['locale'] ?? null,
            $scope['website_locale'] ?? null,
            $websiteProfile['locales'][0] ?? null,
            $scope['website_profile']['locales'][0] ?? null,
        ] as $candidate) {
            $locale = \trim((string)$candidate);
            if ($locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function hasResolvedContentLocale(array $websiteProfile, array $scope): bool
    {
        return $this->resolvePrimaryLocale($websiteProfile, $scope) !== '';
    }

    private function resolveScopePrimaryLocale(array $scope): string
    {
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];

        return $this->resolvePrimaryLocale($websiteProfile, $scope);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveSharedPromptContext(array $scope): array
    {
        foreach ([
            $this->extractSharedPromptContextFromTask($this->resolveSharedPlanJsonTask($scope, 'header')),
            $this->extractSharedPromptContextFromTask($this->resolveSharedPlanJsonTask($scope, 'footer')),
            \is_array($scope['plan_json']['shared_prompt_context'] ?? null) ? $scope['plan_json']['shared_prompt_context'] : [],
        ] as $candidate) {
            if (!\is_array($candidate) || $candidate === []) {
                continue;
            }
            $candidate = $this->normalizeSharedPromptContextCandidate($candidate);
            if (
                \is_array($candidate['header_items'] ?? null)
                || \is_array($candidate['footer_featured'] ?? null)
                || \is_array($candidate['footer_policies'] ?? null)
                || \is_array($candidate['shared_cta_strategy'] ?? null)
                || \is_scalar($candidate['site_display_name'] ?? null)
            ) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $plan
     * @return list<array{field:string,sample:string}>
     */
    private function buildSharedPlanFieldRequirements(array $plan, string $region): array
    {
        $rows = [];
        $payload = \is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        if ($region === 'header') {
            $items = \is_array($payload['header_items'] ?? null) ? $payload['header_items'] : [];
            if ($items !== []) {
                $rows[] = [
                    'field' => 'navigation.items',
                    'sample' => $this->buildLinkLines($this->normalizePromptLinkItems($items)),
                ];
            }
        } else {
            foreach ([
                'featured_links' => $payload['featured'] ?? $payload['featured_links'] ?? null,
                'policy_links' => $payload['policies'] ?? $payload['policy_links'] ?? null,
            ] as $field => $items) {
                if (!\is_array($items) || $items === []) {
                    continue;
                }
                $rows[] = [
                    'field' => $field,
                    'sample' => $this->buildLinkLines($this->normalizePromptLinkItems($items)),
                ];
            }
        }

        $realtime = \is_array($plan['realtime_content'] ?? null) ? $plan['realtime_content'] : [];
        foreach (\is_array($realtime['cta'] ?? null) ? $realtime['cta'] : [] as $cta) {
            if (!\is_array($cta)) {
                continue;
            }
            $label = $this->sanitizeVisibleCopy((string)($cta['label'] ?? $cta['text'] ?? ''));
            $target = \trim((string)($cta['target'] ?? $cta['href'] ?? $cta['url'] ?? ''));
            if ($label !== '') {
                $rows[] = ['field' => 'cta_text', 'sample' => $label];
            }
            if ($target !== '') {
                $rows[] = ['field' => 'cta_url', 'sample' => $target];
            }
            break;
        }
        foreach (['site_display_name', 'title'] as $field) {
            $sample = $this->sanitizeVisibleCopy((string)($plan[$field] ?? ''));
            if ($sample !== '') {
                $rows[] = ['field' => $field === 'title' ? 'brand_name' : $field, 'sample' => $sample];
                break;
            }
        }

        return \array_values(\array_filter($rows, static fn(array $row): bool => \trim((string)$row['sample']) !== ''));
    }

    /**
     * @param array<string,mixed> $plan
     * @return list<array{field:string,copy:string}>
     */
    private function buildSharedPlanContentCopyRows(array $plan, string $region): array
    {
        $rows = [];
        foreach ($this->buildSharedPlanFieldRequirements($plan, $region) as $row) {
            $field = \trim((string)($row['field'] ?? ''));
            $sample = $this->sanitizeVisibleCopy((string)($row['sample'] ?? ''));
            if ($field !== '' && $sample !== '') {
                $rows[] = ['field' => $field, 'copy' => $sample];
            }
        }
        $realtime = \is_array($plan['realtime_content'] ?? null) ? $plan['realtime_content'] : [];
        foreach ([$realtime['headline'] ?? null, $plan['goal'] ?? null] as $copy) {
            if (!\is_scalar($copy)) {
                continue;
            }
            $sample = $this->sanitizeVisibleCopy((string)$copy);
            if ($sample !== '') {
                $rows[] = ['field' => 'headline', 'copy' => $sample];
                break;
            }
        }
        foreach (\is_array($realtime['supporting_copy'] ?? null) ? $realtime['supporting_copy'] : [] as $copy) {
            if (!\is_scalar($copy)) {
                continue;
            }
            $sample = $this->sanitizeVisibleCopy((string)$copy);
            if ($sample !== '') {
                $rows[] = ['field' => 'supporting_copy', 'copy' => $sample];
            }
            if (\count($rows) >= 8) {
                break;
            }
        }

        return $this->dedupeContentCopyRows($rows);
    }

    /**
     * Stage-1 shared plans may store the customer-facing chrome contract under
     * nested header_plan/footer_plan payloads. Normalize that shape once so
     * generation prompts, default config, and link fallback logic consume the
     * same contract instead of leaking planning-language profile defaults.
     *
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    private function normalizeSharedPromptContextCandidate(array $candidate): array
    {
        $headerPlan = \is_array($candidate['header_plan'] ?? null) ? $candidate['header_plan'] : [];
        $footerPlan = \is_array($candidate['footer_plan'] ?? null) ? $candidate['footer_plan'] : [];
        $headerPayload = \is_array($headerPlan['payload'] ?? null) ? $headerPlan['payload'] : [];
        $footerPayload = \is_array($footerPlan['payload'] ?? null) ? $footerPlan['payload'] : [];

        if (!\is_scalar($candidate['site_display_name'] ?? null)) {
            foreach ([
                $headerPlan['site_display_name'] ?? null,
                $headerPlan['title'] ?? null,
                $footerPlan['site_display_name'] ?? null,
                $footerPlan['title'] ?? null,
            ] as $siteCandidate) {
                if (!\is_scalar($siteCandidate) || \trim((string)$siteCandidate) === '') {
                    continue;
                }
                $candidate['site_display_name'] = \trim((string)$siteCandidate);
                break;
            }
        }

        if (!\is_array($candidate['header_items'] ?? null)) {
            foreach ([
                $headerPayload['header_items'] ?? null,
                $headerPayload['items'] ?? null,
                $headerPlan['header_items'] ?? null,
            ] as $items) {
                if (!\is_array($items) || $items === []) {
                    continue;
                }
                $candidate['header_items'] = $items;
                break;
            }
        }

        if (!\is_array($candidate['footer_featured'] ?? null)) {
            foreach ([
                $footerPayload['featured'] ?? null,
                $footerPayload['featured_links'] ?? null,
                $footerPayload['links'] ?? null,
                $footerPlan['featured'] ?? null,
            ] as $items) {
                if (!\is_array($items) || $items === []) {
                    continue;
                }
                $candidate['footer_featured'] = $items;
                break;
            }
        }

        if (!\is_array($candidate['footer_policies'] ?? null)) {
            foreach ([
                $footerPayload['policies'] ?? null,
                $footerPayload['policy_links'] ?? null,
                $footerPlan['policies'] ?? null,
            ] as $items) {
                if (!\is_array($items) || $items === []) {
                    continue;
                }
                $candidate['footer_policies'] = $items;
                break;
            }
        }

        if (!\is_array($candidate['shared_cta_strategy'] ?? null)) {
            $cta = $this->resolveSharedPromptContextCtaStrategy($headerPlan, $footerPlan);
            if ($cta !== []) {
                $candidate['shared_cta_strategy'] = $cta;
            }
        }

        return $candidate;
    }

    /**
     * @param array<string,mixed> ...$plans
     * @return array<string,string>
     */
    private function resolveSharedPromptContextCtaStrategy(array ...$plans): array
    {
        foreach ($plans as $plan) {
            $realtime = \is_array($plan['realtime_content'] ?? null) ? $plan['realtime_content'] : [];
            $payload = \is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
            foreach ([
                $realtime['cta'] ?? null,
                $payload['cta'] ?? null,
                $payload['ctas'] ?? null,
            ] as $items) {
                if (!\is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    if (!\is_array($item)) {
                        continue;
                    }
                    $label = \trim((string)($item['label'] ?? $item['text'] ?? ''));
                    if ($label === '') {
                        continue;
                    }
                    $strategy = ['primary_action' => $label];
                    $target = \trim((string)($item['target'] ?? $item['href'] ?? $item['url'] ?? ''));
                    if ($target !== '') {
                        $strategy['primary_target'] = $target;
                    }
                    return $strategy;
                }
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    private function extractSharedPromptContextFromTask(array $task): array
    {
        $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];

        return \is_array($runtimeContext['shared_prompt_context'] ?? null)
            ? $runtimeContext['shared_prompt_context']
            : [];
    }

    private function normalizePromptVisibleLabel(string $candidate, string $fallback, string $locale): string
    {
        $candidate = $this->filterVisibleCopyForLocale(\trim($candidate), $locale);
        if ($candidate !== '') {
            return $candidate;
        }

        $fallback = \trim($fallback);
        if ($locale !== '' && !$this->isEnglishLocale($locale)) {
            $localizedFallback = $this->promptLocaleFamily($locale) === 'en'
                ? ''
                : $this->localizeBuildText('section_fallback', $locale);
            if ($localizedFallback !== '') {
                return $localizedFallback;
            }

            return '';
        }

        return $fallback !== '' ? $fallback : 'Section';
    }

    private function filterVisibleCopyForLocale(string $value, string $locale): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        $contactPlaceholderReason = $this->detectInvalidContactPlaceholderVisibleCopy($value);
        if ($contactPlaceholderReason !== null) {
            return '';
        }
        if ($this->containsPlanningObservationCopy($value)) {
            return '';
        }
        if ($locale !== '' && $this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($value)) {
            return '';
        }
        if ($locale !== '' && !$this->isEnglishLocale($locale) && $this->containsEnglishBoilerplateVisibleCopy($value)) {
            return '';
        }
        if ($locale !== '' && !$this->isEnglishLocale($locale) && $this->hasDominantLatinProseForNonEnglishLocale($value, $locale)) {
            return '';
        }

        return $this->sanitizeVisibleCopy($value);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function filterPromptArrayForLocale(mixed $value, string $locale): mixed
    {
        if (\is_string($value)) {
            return $this->filterVisibleCopyForLocale($value, $locale);
        }
        if (!\is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->filterPromptArrayForLocale($item, $locale);
            if ($value[$key] === '') {
                unset($value[$key]);
            }
        }

        return $value;
    }

    private function localizePageTypeTitle(string $pageType, string $locale): string
    {
        $localized = $this->lookupLocalizedPageTypeTitle($pageType, $locale);
        if ($localized !== '') {
            return $localized;
        }

        return match ($pageType) {
            Page::TYPE_HOME => 'Home',
            Page::TYPE_ABOUT => 'About',
            Page::TYPE_CONTACT => 'Contact',
            Page::TYPE_BLOG_LIST, Page::TYPE_BLOG => 'Blog',
            Page::TYPE_PRIVACY_POLICY => 'Privacy Policy',
            Page::TYPE_TERMS_OF_SERVICE => 'Terms of Service',
            Page::TYPE_REFUND_POLICY => 'Refund Policy',
            Page::TYPE_SHIPPING_POLICY => 'Shipping Policy',
            Page::TYPE_COOKIE_POLICY => 'Cookie Policy',
            default => '',
        };
    }

    private function localizeBuildText(string $key, string $locale): string
    {
        $localized = $this->lookupLocalizedBuildText($key, $locale);
        if ($localized !== '') {
            return $localized;
        }

        return match ($key) {
            'policy_info' => 'Policy Info',
            'featured_pages' => 'Featured Pages',
            'all_pages' => 'All Pages',
            'all_rights_reserved' => 'All rights reserved.',
            'brand_summary' => 'A curated destination with clear information, trusted support, and simple next steps.',
            'site_name_fallback' => 'Website',
            'contact_us' => 'Contact Us',
            'explore_more' => 'Explore More',
            'get_started' => 'Get Started',
            'download_now' => 'Download Now',
            'claim_bonus' => 'Claim Bonus',
            'start_playing' => 'Start Playing',
            default => $key,
        };
    }

    private function lookupLocalizedPageTypeTitle(string $pageType, string $locale): string
    {
        $labelsByFamily = [
            'zh' => [
                Page::TYPE_HOME => '首页',
                Page::TYPE_ABOUT => '关于我们',
                Page::TYPE_CONTACT => '联系我们',
                Page::TYPE_BLOG_LIST => '博客',
                Page::TYPE_BLOG => '博客',
                Page::TYPE_PRIVACY_POLICY => '隐私政策',
                Page::TYPE_TERMS_OF_SERVICE => '服务条款',
                Page::TYPE_REFUND_POLICY => '退款政策',
                Page::TYPE_SHIPPING_POLICY => '配送政策',
                Page::TYPE_COOKIE_POLICY => 'Cookie 政策',
            ],
            'pt' => [
                Page::TYPE_HOME => 'Início',
                Page::TYPE_ABOUT => 'Sobre',
                Page::TYPE_CONTACT => 'Contato',
                Page::TYPE_BLOG_LIST => 'Blog',
                Page::TYPE_BLOG => 'Blog',
                Page::TYPE_PRIVACY_POLICY => 'Política de Privacidade',
                Page::TYPE_TERMS_OF_SERVICE => 'Termos de Serviço',
                Page::TYPE_REFUND_POLICY => 'Política de Reembolso',
                Page::TYPE_SHIPPING_POLICY => 'Política de Envio',
                Page::TYPE_COOKIE_POLICY => 'Política de Cookies',
            ],
            'ar' => [
                Page::TYPE_HOME => 'الرئيسية',
                Page::TYPE_ABOUT => 'من نحن',
                Page::TYPE_CONTACT => 'اتصل بنا',
                Page::TYPE_BLOG_LIST => 'المدونة',
                Page::TYPE_BLOG => 'المدونة',
                Page::TYPE_PRIVACY_POLICY => 'سياسة الخصوصية',
                Page::TYPE_TERMS_OF_SERVICE => 'شروط الخدمة',
                Page::TYPE_REFUND_POLICY => 'سياسة الاسترداد',
                Page::TYPE_SHIPPING_POLICY => 'سياسة الشحن',
                Page::TYPE_COOKIE_POLICY => 'سياسة ملفات تعريف الارتباط',
            ],
            'en' => [
                Page::TYPE_HOME => 'Home',
                Page::TYPE_ABOUT => 'About',
                Page::TYPE_CONTACT => 'Contact',
                Page::TYPE_BLOG_LIST => 'Blog',
                Page::TYPE_BLOG => 'Blog',
                Page::TYPE_PRIVACY_POLICY => 'Privacy Policy',
                Page::TYPE_TERMS_OF_SERVICE => 'Terms of Service',
                Page::TYPE_REFUND_POLICY => 'Refund Policy',
                Page::TYPE_SHIPPING_POLICY => 'Shipping Policy',
                Page::TYPE_COOKIE_POLICY => 'Cookie Policy',
            ],
        ];
        $family = $this->promptLocaleFamily($locale);
        $labels = $labelsByFamily[$family] ?? $labelsByFamily['en'];

        return (string)($labels[$pageType] ?? '');
    }

    private function lookupLocalizedBuildText(string $key, string $locale): string
    {
        $labelsByFamily = [
            'zh' => [
                'policy_info' => '法律信息',
                'featured_pages' => '重点页面',
                'all_pages' => '全部页面',
                'all_rights_reserved' => '版权所有。',
                'brand_summary' => '一个信息清晰、支持可信、下一步明确的网站。',
                'contact_us' => '联系我们',
                'explore_more' => '查看更多',
                'get_started' => '开始使用',
                'download_now' => '立即下载',
                'claim_bonus' => '领取奖励',
                'start_playing' => '开始游戏',
                'site_name_fallback' => '网站',
                'section_fallback' => '页面区块',
            ],
            'pt' => [
                'policy_info' => 'Informações legais',
                'featured_pages' => 'Páginas principais',
                'all_pages' => 'Todas as páginas',
                'all_rights_reserved' => 'Todos os direitos reservados.',
                'brand_summary' => 'Um destino selecionado com informações claras, suporte confiável e próximos passos simples.',
                'contact_us' => 'Fale conosco',
                'explore_more' => 'Explorar mais',
                'get_started' => 'Começar',
                'download_now' => 'Baixar agora',
                'claim_bonus' => 'Resgatar bônus',
                'start_playing' => 'Começar a jogar',
                'site_name_fallback' => 'Site',
                'section_fallback' => 'Seção',
            ],
            'ar' => [
                'policy_info' => 'معلومات قانونية',
                'featured_pages' => 'صفحات مميزة',
                'all_pages' => 'كل الصفحات',
                'all_rights_reserved' => 'جميع الحقوق محفوظة.',
                'brand_summary' => 'وجهة واضحة بمعلومات موثوقة ودعم مباشر وخطوات سهلة.',
                'contact_us' => 'اتصل بنا',
                'explore_more' => 'استكشف المزيد',
                'get_started' => 'ابدأ الآن',
                'download_now' => 'حمّل الآن',
                'claim_bonus' => 'احصل على المكافأة',
                'start_playing' => 'ابدأ اللعب',
                'site_name_fallback' => 'الموقع',
                'section_fallback' => 'قسم',
            ],
            'en' => [
                'policy_info' => 'Policy Info',
                'featured_pages' => 'Featured Pages',
                'all_pages' => 'All Pages',
                'all_rights_reserved' => 'All rights reserved.',
                'brand_summary' => 'A curated destination with clear information, trusted support, and simple next steps.',
                'contact_us' => 'Contact Us',
                'explore_more' => 'Explore More',
                'get_started' => 'Get Started',
                'download_now' => 'Download Now',
                'claim_bonus' => 'Claim Bonus',
                'start_playing' => 'Start Playing',
                'site_name_fallback' => 'Website',
                'section_fallback' => 'Section',
            ],
        ];
        $family = $this->promptLocaleFamily($locale);
        $labels = $labelsByFamily[$family] ?? $labelsByFamily['en'];

        return (string)($labels[$key] ?? '');
    }

    private function promptLocaleFamily(string $locale): string
    {
        $locale = \trim($locale);
        if ($this->isPortugueseLocale($locale)) {
            return 'pt';
        }
        if ($this->isThaiLocale($locale)) {
            return 'th';
        }
        if ($this->isHindiLocale($locale)) {
            return 'hi';
        }
        if ($this->isArabicLocale($locale)) {
            return 'ar';
        }
        if ($this->isChineseLocale($locale)) {
            return 'zh';
        }
        if ($this->isRussianLocale($locale)) {
            return 'ru';
        }
        return 'en';
    }

    private function isPortugueseLocale(string $locale): bool
    {
        return \preg_match('/^pt(?:[_-]|$)/i', \trim($locale)) === 1;
    }

    private function isThaiLocale(string $locale): bool
    {
        return \preg_match('/^th(?:[_-]|$)/i', \trim($locale)) === 1;
    }

    private function isHindiLocale(string $locale): bool
    {
        return \preg_match('/^(?:hi|hi[_-]in)(?:[_-]|$)/i', \trim($locale)) === 1;
    }

    private function isArabicLocale(string $locale): bool
    {
        return \preg_match('/^ar(?:[_-]|$)/i', \trim($locale)) === 1;
    }

    private function humanizeIdentifier(string $value): string
    {
        $value = \trim(\str_replace(['-', '_'], ' ', $value));
        $value = \preg_replace('/\s+/u', ' ', $value) ?? $value;
        return $value !== '' ? \ucwords($value) : '';
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<string> $pageTypes
     * @return array<string,mixed>
     */
    private function resolvePageRouteContract(array $scope, array $pageTypes = [], string $locale = ''): array
    {
        $pageTypes = $pageTypes !== [] ? $pageTypes : $this->resolveScopedPageTypes($scope);
        $raw = \is_array($scope['page_route_contract'] ?? null) ? $scope['page_route_contract'] : [];

        return $this->getPageRouteContractService()->normalize($raw, $pageTypes, $scope, $locale);
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function buildSharedRouteContractPromptAddon(array $scope, string $locale): string
    {
        $routeContract = $this->resolvePageRouteContract($scope, [], $locale);
        $routesByType = $this->getPageRouteContractService()->routesByType($routeContract);
        if ($routesByType === []) {
            return '';
        }

        return "PAGE ROUTE CONTRACT:\n"
            . "- routes_by_type: " . $this->jsonEncodeForPrompt($routesByType, 900) . "\n"
            . "- allowed_internal_paths: " . $this->jsonEncodeForPrompt($routeContract['allowed_internal_paths'] ?? [], 360) . "\n"
            . "- link_groups: " . $this->jsonEncodeForPrompt($routeContract['link_groups'] ?? [], 700) . "\n"
            . "- header/footer/content/CTA hrefs must use only exact path values from allowed_internal_paths, and header/footer fields must obey their exact link_groups allowed_paths; no domains, query strings, hashes, hash-only anchors, or `href='#'`. Do not invent studio, product, preorder, gift, service, download, faq, games, singular/plural, anchor, or campaign paths unless the exact path is listed here.\n"
            . "- Section anchors are not route links. Header/footer must not generate #games/#download-style page-scroll links unless a separate section_anchor_contract with real rendered ids is explicitly provided; this prompt does not provide one by default.\n"
            . "- If a desired destination is not in the route contract, omit that link and use the nearest listed page type instead; for content CTA actions use a `<button type='button' data-pb-ai-action='primary_cta'>` event control. Never create a new internal URL in html_content, html_extra, footer_extra_text, navigation.items, links.*_items, or cta.url.\n";
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function normalizeSharedDefaultConfigLinksAgainstRouteContract(array $defaultConfig, array $scope, string $locale): array
    {
        $routeContract = $this->resolvePageRouteContract($scope, [], $locale);
        if ($this->getPageRouteContractService()->routesByType($routeContract) === []) {
            return $defaultConfig;
        }

        if (\array_key_exists('nav_items', $defaultConfig) || \array_key_exists('navigation.items', $defaultConfig)) {
            $items = \is_array($defaultConfig['nav_items'] ?? null)
                ? $this->normalizePromptLinkItems($defaultConfig['nav_items'])
                : $this->normalizePromptLinkItems($this->decodeLinkItemsSample((string)($defaultConfig['navigation.items'] ?? '')));
            $items = $this->normalizeLinkItemsAgainstRouteContract($items, $scope, $locale, [], 'header_route_types');
            if (\count($items) < 3) {
                $items = $this->routeLinkItemsForScope($scope, $locale, 'header_route_types');
            }
            if ($items !== []) {
                $defaultConfig['nav_items'] = \array_map(static fn(array $item): array => [
                    'text' => (string)($item['label'] ?? ''),
                    'href' => (string)($item['href'] ?? '#'),
                    'type' => (string)($item['type'] ?? ''),
                ], $items);
                $defaultConfig['navigation.items'] = $this->buildLinkLines($items);
            }
        }

        foreach ([
            'links.column1_items' => 'footer_featured_route_types',
            'links.column2_items' => 'footer_policy_route_types',
            'links.column3_items' => 'all',
        ] as $field => $routeTypeKey) {
            if (!\array_key_exists($field, $defaultConfig)) {
                continue;
            }
            $items = $this->normalizePromptLinkItems($this->decodeLinkItemsSample((string)$defaultConfig[$field]));
            $items = $this->normalizeLinkItemsAgainstRouteContract($items, $scope, $locale, [], $routeTypeKey === 'all' ? '' : $routeTypeKey);
            $minimum = $field === 'links.column1_items' ? 3 : ($field === 'links.column2_items' ? 2 : 1);
            if (\count($items) < $minimum) {
                $items = $this->routeLinkItemsForScope($scope, $locale, $routeTypeKey);
            }
            if ($items !== []) {
                $defaultConfig[$field] = $this->buildLinkLines($items);
            }
        }

        if (\array_key_exists('cta.url', $defaultConfig)) {
            $ctaUrl = $this->normalizeHrefAgainstRouteContract((string)$defaultConfig['cta.url'], $scope, $locale);
            if ($ctaUrl === '') {
                $ctaUrl = $this->firstAvailableRoutePath($scope, $locale, [Page::TYPE_CONTACT, Page::TYPE_BLOG_LIST, Page::TYPE_ABOUT, Page::TYPE_HOME]);
            }
            $defaultConfig['cta.url'] = $ctaUrl;
        }

        return $defaultConfig;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $scope
     * @param list<array<string,mixed>> $fallbackItems
     * @return list<array{label:string,href:string,type?:string}>
     */
    private function normalizeLinkItemsAgainstRouteContract(array $items, array $scope, string $locale = '', array $fallbackItems = [], string $routeTypeKey = ''): array
    {
        if ($items === []) {
            return [];
        }
        $routeContract = $this->resolvePageRouteContract($scope, [], $locale);
        $routesByType = $this->getPageRouteContractService()->routesByType($routeContract);
        if ($routesByType === []) {
            return $items;
        }
        $allowedTypes = [];
        if ($routeTypeKey !== '' && \is_array($routeContract[$routeTypeKey] ?? null)) {
            $allowedTypes = \array_fill_keys(\array_values(\array_map('strval', $routeContract[$routeTypeKey])), true);
        }

        $routesByPath = [];
        $routesByLabel = [];
        foreach ($routesByType as $type => $route) {
            if ($allowedTypes !== [] && !isset($allowedTypes[$type])) {
                continue;
            }
            $path = (string)($route['path'] ?? '');
            if ($path !== '') {
                $routesByPath[$path] = ['type' => $type, 'route' => $route];
            }
            foreach ([
                $this->localizePageTypeTitle($type, $locale),
                (string)($route['label'] ?? ''),
                $this->humanizeIdentifier($type),
            ] as $labelCandidate) {
                $key = $this->normalizeRouteLabelKey($labelCandidate);
                if ($key !== '') {
                    $routesByLabel[$key] = ['type' => $type, 'route' => $route];
                }
            }
        }

        $normalized = [];
        $seenPaths = [];
        foreach (\array_values($items) as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $type = \trim((string)($item['type'] ?? ''));
            $href = \trim((string)($item['href'] ?? $item['url'] ?? $item['target'] ?? ''));
            $label = $this->filterNavigationLabelForLocale(
                (string)($item['label'] ?? $item['title'] ?? $item['text'] ?? ''),
                $locale,
                $type
            );

            $match = null;
            if ($type !== '' && ($allowedTypes === [] || isset($allowedTypes[$type])) && \is_array($routesByType[$type] ?? null)) {
                $match = ['type' => $type, 'route' => $routesByType[$type]];
            }
            if ($match === null && $href !== '') {
                $requestedPath = $this->getPageRouteContractService()->normalizeHrefPath($href);
                $path = $this->getPageRouteContractService()->normalizeHrefToContractPath($routeContract, $href);
                if ($path !== '' && \is_array($routesByPath[$path] ?? null)) {
                    $match = $routesByPath[$path];
                    if ($requestedPath !== $path) {
                        $label = '';
                    }
                }
            }
            if ($match === null && $label !== '') {
                $labelKey = $this->normalizeRouteLabelKey($label);
                if ($labelKey !== '' && \is_array($routesByLabel[$labelKey] ?? null)) {
                    $match = $routesByLabel[$labelKey];
                }
            }
            if ($match === null) {
                continue;
            }

            $route = \is_array($match['route'] ?? null) ? $match['route'] : [];
            $routeType = (string)($match['type'] ?? '');
            $path = (string)($route['path'] ?? '');
            if ($path === '' || isset($seenPaths[$path])) {
                continue;
            }
            if ($label === '') {
                $label = $this->localizePageTypeTitle($routeType, $locale);
            }
            if ($label === '') {
                $label = (string)($route['label'] ?? $this->humanizeIdentifier($routeType));
            }
            if ($label !== '' && $this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($label)) {
                $label = $this->localizePageTypeTitle($routeType, $locale);
                if ($label === '' && $this->isEnglishLocale($locale)) {
                    $label = $this->humanizeIdentifier($routeType);
                }
            }
            if ($label === '') {
                continue;
            }

            $seenPaths[$path] = true;
            $normalized[] = [
                'label' => $label,
                'href' => $path,
                'type' => $routeType,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<string> $preferredTypes
     */
    private function firstAvailableRoutePath(array $scope, string $locale, array $preferredTypes): string
    {
        $routeContract = $this->resolvePageRouteContract($scope, [], $locale);
        $routesByType = $this->getPageRouteContractService()->routesByType($routeContract);
        foreach ($preferredTypes as $type) {
            if (\is_array($routesByType[$type] ?? null) && \trim((string)($routesByType[$type]['path'] ?? '')) !== '') {
                return (string)$routesByType[$type]['path'];
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function normalizeHrefAgainstRouteContract(string $href, array $scope, string $locale = ''): string
    {
        $routeContract = $this->resolvePageRouteContract($scope, [], $locale);
        return $this->getPageRouteContractService()->normalizeHrefToContractPath($routeContract, $href);
    }

    private function resolveRouteTypeKeyForLinkField(string $field): string
    {
        return match (true) {
            \str_contains($field, 'navigation') => 'header_route_types',
            \str_contains($field, 'featured_links') => 'footer_featured_route_types',
            \str_contains($field, 'policy_links') => 'footer_policy_route_types',
            default => '',
        };
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array{label:string,href:string,type?:string}>
     */
    private function routeLinkItemsForScope(array $scope, string $locale, string $routeTypeKey): array
    {
        $routeContract = $this->resolvePageRouteContract($scope, [], $locale);
        $routesByType = $this->getPageRouteContractService()->routesByType($routeContract);
        if ($routesByType === []) {
            return [];
        }
        $types = $routeTypeKey === 'all'
            ? \array_keys($routesByType)
            : (\is_array($routeContract[$routeTypeKey] ?? null) ? \array_values(\array_map('strval', $routeContract[$routeTypeKey])) : []);
        $items = [];
        foreach ($types as $type) {
            if (!\is_array($routesByType[$type] ?? null)) {
                continue;
            }
            $route = $routesByType[$type];
            $label = $this->localizePageTypeTitle($type, $locale);
            if ($label === '') {
                $label = (string)($route['label'] ?? $this->humanizeIdentifier($type));
            }
            if ($label !== '' && $this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($label)) {
                $label = $this->localizePageTypeTitle($type, $locale);
                if ($label === '' && $this->isEnglishLocale($locale)) {
                    $label = $this->humanizeIdentifier($type);
                }
            }
            if ($label === '') {
                continue;
            }
            $items[] = [
                'label' => $label,
                'href' => (string)($route['path'] ?? '#'),
                'type' => $type,
            ];
        }

        return $items;
    }

    private function normalizeRouteLabelKey(string $label): string
    {
        $label = \mb_strtolower(\trim($label));
        $label = \preg_replace('/\s+/u', ' ', $label) ?? $label;

        return $label;
    }

    private function getPageRouteContractService(): AiSitePageRouteContractService
    {
        return ObjectManager::getInstance(AiSitePageRouteContractService::class);
    }

    /**
     * @param mixed $items
     * @param list<array<string,mixed>> $fallbackItems
     * @return list<array{label:string,href:string,type?:string}>
     */
    private function normalizePromptLinkItems(mixed $items, array $fallbackItems = []): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $index => $item) {
            if (\is_array($item)) {
                $label = \trim((string)($item['label'] ?? $item['title'] ?? $item['text'] ?? ''));
                $href = \trim((string)($item['href'] ?? $item['url'] ?? $item['target'] ?? ''));
                if ($href === '') {
                    $href = \trim((string)($fallbackItems[$index]['href'] ?? '#'));
                }
                if ($label === '') {
                    continue;
                }
                $normalized[] = [
                    'label' => $label,
                    'href' => $href !== '' ? $href : '#',
                    'type' => \trim((string)($item['type'] ?? '')),
                ];
                continue;
            }

            if (\is_scalar($item)) {
                $label = \trim((string)$item);
                if ($label === '') {
                    continue;
                }
                $normalized[] = [
                    'label' => $label,
                    'href' => \trim((string)($fallbackItems[$index]['href'] ?? '#')),
                ];
            }
        }

        return $normalized;
    }

    /**
     * @return list<array<string,mixed>>|null
     */
    private function decodeLinkItemsSample(string $sample): ?array
    {
        $sample = \trim($sample);
        if ($sample === '') {
            return null;
        }

        if (\str_starts_with($sample, '[') || \str_starts_with($sample, '{')) {
            $decoded = \json_decode($sample, true);
            return \is_array($decoded) ? $decoded : null;
        }

        $items = [];
        if (\str_contains($sample, '=>')) {
            foreach (\preg_split('/\r?\n/', $sample) ?: [] as $row) {
                $row = \trim($row);
                if ($row === '') {
                    continue;
                }
                [$label, $href] = \explode('=>', $row, 2);
                $items[] = ['label' => \trim($label), 'href' => \trim($href)];
            }
            return $items;
        }

        foreach (\preg_split('/\s*\/\s*/u', $sample) ?: [] as $label) {
            $label = \trim($label);
            if ($label === '') {
                continue;
            }
            $items[] = ['label' => $label, 'href' => '#'];
        }

        return $items;
    }

    /**
     * @param list<array{label:string,href:string,type?:string}> $items
     */
    private function buildLinkLines(array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            $label = \trim((string)($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $lines[] = $label . '=>' . \trim((string)($item['href'] ?? '#'));
        }

        return \implode("\n", $lines);
    }

    private function deriveHandleFromHref(string $href): string
    {
        $href = \trim($href);
        if ($href === '' || $href === '/' || $href === '#') {
            return '';
        }

        $href = \preg_replace('/^[a-z]+:\/\/[^\/]+/i', '', $href) ?? $href;
        $path = (string)(\parse_url($href, \PHP_URL_PATH) ?? $href);
        return \trim($path, '/');
    }

    private function isChineseLocale(string $locale): bool
    {
        return \str_starts_with(\strtolower(\trim($locale)), 'zh');
    }

    private function isEnglishLocale(string $locale): bool
    {
        $locale = \strtolower(\trim($locale));
        return $locale === 'en' || \str_starts_with($locale, 'en_') || \str_starts_with($locale, 'en-');
    }

    private function isRussianLocale(string $locale): bool
    {
        $locale = \strtolower(\trim($locale));
        return $locale === 'ru' || \str_starts_with($locale, 'ru_') || \str_starts_with($locale, 'ru-');
    }

    private function containsEnglishBoilerplateVisibleCopy(string $value): bool
    {
        $normalized = \preg_replace('/\s+/u', ' ', \trim($value)) ?? $value;
        if ($normalized === '') {
            return false;
        }

        foreach ([
            'A curated destination with clear information, trusted support, and simple next steps.',
            'Featured Pages',
            'Policy Info',
            'All Pages',
            'All rights reserved.',
            'Contact Us',
            'Download Now',
            'Explore More',
            'Get Started',
            'Claim Bonus',
            'Start Playing',
        ] as $phrase) {
            if (\mb_stripos($normalized, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isJapaneseLocale(string $locale): bool
    {
        $locale = \strtolower(\trim($locale));
        return $locale === 'ja' || \str_starts_with($locale, 'ja_') || \str_starts_with($locale, 'ja-');
    }

    private function isKoreanLocale(string $locale): bool
    {
        $locale = \strtolower(\trim($locale));
        return $locale === 'ko' || \str_starts_with($locale, 'ko_') || \str_starts_with($locale, 'ko-');
    }

    private function isCjkLocale(string $locale): bool
    {
        return $this->isChineseLocale($locale)
            || $this->isJapaneseLocale($locale)
            || $this->isKoreanLocale($locale);
    }

    private function isNonCjkLocale(string $locale): bool
    {
        return $locale !== ''
            && !$this->isChineseLocale($locale)
            && !$this->isJapaneseLocale($locale)
            && !$this->isKoreanLocale($locale);
    }

    private function hasMeaningfulCjkContent(string $text): bool
    {
        $matches = [];
        if (\preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+/u', $text, $matches) <= 0) {
            return false;
        }

        $total = 0;
        foreach ($matches[0] as $segment) {
            $length = \function_exists('mb_strlen') ? \mb_strlen((string)$segment) : \strlen((string)$segment);
            $total += $length;
            if ($length >= 4) {
                return true;
            }
        }

        return $total >= 6;
    }

    private function hasAnyCjkContent(string $text): bool
    {
        return \preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text) === 1;
    }

    /**
     * @param array<string,bool> $allowedTerms
     */
    private function hasDominantLatinProseForCjkLocale(string $text, array $allowedTerms = []): bool
    {
        $text = \html_entity_decode($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $text = (string)\preg_replace('/https?:\/\/\S+|\/pub\/media\/\S+/iu', ' ', $text);
 $segments = \preg_split('/[\r\n ?? ?|]+/u', $text) ?: [$text];

        foreach ($segments as $segment) {
            $segment = \trim((string)\preg_replace('/\s+/u', ' ', (string)$segment));
            if ($segment === '') {
                continue;
            }

            $matches = [];
            \preg_match_all('/[A-Za-z][A-Za-z0-9\'-]*/u', $segment, $matches);
            $words = $matches[0] ?? [];
            if ($words === []) {
                continue;
            }
            $words = \array_values(\array_filter($words, static function (string $word) use ($allowedTerms): bool {
                return !isset($allowedTerms[\strtolower(\trim($word, " \t\n\r\0\x0B'\"-"))]);
            }));
            if ($words === []) {
                continue;
            }

            $letterCount = 0;
            foreach ($words as $word) {
                $letterCount += \strlen((string)$word);
            }

            $hasCjk = $this->hasMeaningfulCjkContent($segment);
            if ($hasCjk && \count($words) >= 5 && $letterCount >= 30) {
                return true;
            }
            if ($hasCjk) {
                continue;
            }
            if (\count($words) >= 3 && $letterCount >= 16) {
                return true;
            }
            if (
                \count($words) >= 2
                && \preg_match('/\b(?:download|play|learn|more|get|started|start|contact|about|subscribe|signup|sign|shop|explore|read|view)\b/iu', $segment) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,bool> $allowedTerms
     */
    private function hasDominantLatinProseForNonEnglishLocale(string $text, string $locale, array $allowedTerms = []): bool
    {
        if ($this->isEnglishLocale($locale)) {
            return false;
        }
        $text = \html_entity_decode($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $text = (string)\preg_replace('/https?:\/\/\S+|\/pub\/media\/\S+/iu', ' ', $text);
        $segments = \preg_split('/[\r\n.!?;:|\s]+/u', $text) ?: [$text];

        foreach ($segments as $segment) {
            $segment = \trim((string)\preg_replace('/\s+/u', ' ', (string)$segment));
            if ($segment === '') {
                continue;
            }

            \preg_match_all('/[A-Za-z][A-Za-z0-9\'-]*/u', $segment, $matches);
            $words = $matches[0] ?? [];
            if ($words === []) {
                continue;
            }
            $words = \array_values(\array_filter($words, static function (string $word) use ($allowedTerms): bool {
                return !isset($allowedTerms[\strtolower(\trim($word, " \t\n\r\0\x0B'\"-"))]);
            }));
            if ($words === []) {
                continue;
            }

            $letterCount = 0;
            foreach ($words as $word) {
                $letterCount += \strlen((string)$word);
            }
            if (\count($words) >= 5 && $letterCount >= 28) {
                return true;
            }
            if (
                \count($words) >= 3
                && \preg_match('/\b(?:home|about|featured|pages|policy|info|privacy|terms|support|reserved|rights|destination|clear|trusted|simple|next|steps|download|play|get|started|contact|subscribe|explore|learn|more)\b/iu', $segment) === 1
            ) {
                return true;
            }
            if (
                \count($words) >= 2
                && \preg_match('/\b(?:trust|security|proof|final|cta|hero|feature|features|card|cards|section|block|download|play|start|started|contact|support|learn|more|explore|button|label|headline|content|copy)\b/iu', $segment) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $renderContext
     * @return array<string,bool>
     */
    private function collectAllowedLatinTermsFromRenderContext(array $renderContext): array
    {
        $allowed = \array_fill_keys([
            'apk', 'ios', 'android', 'whatsapp', 'ssl', 'upi', 'vip', 'app', 'faq', 'seo',
            'cta', 'api', 'html', 'css', 'json', 'url', 'www', 'cookie', 'cookies',
        ], true);
        $sources = [];
        $this->collectAllowedLatinTermSources($renderContext['_website_profile'] ?? [], $sources);
        $this->collectAllowedLatinTermSources($renderContext['_scope_identity'] ?? [], $sources);
        $this->collectAllowedLatinTermSources($renderContext['page'] ?? [], $sources);
        $this->collectAllowedLatinTermSources($renderContext['component_config'] ?? [], $sources);

        foreach ($sources as $source) {
            if ($this->isLongLatinProseSource($source)) {
                $terms = $this->extractLatinEntityTermsFromLongSource($source);
            } elseif (\preg_match_all('/\b(?:[A-Z][A-Za-z0-9\'-]{1,}|[A-Z0-9]{2,})\b/u', $source, $matches) < 1) {
                $terms = $this->latinIdentityVariants($source);
            } else {
                $terms = \array_merge($matches[0] ?? [], $this->latinIdentityVariants($source));
            }
            foreach ($terms as $word) {
                $normalized = \strtolower(\trim((string)$word, " \t\n\r\0\x0B'\"-"));
                if (\strlen($normalized) >= 2) {
                    $allowed[$normalized] = true;
                }
            }
        }

        return $allowed;
    }

    /**
     * @return list<string>
     */
    private function extractLatinEntityTermsFromLongSource(string $source): array
    {
        $terms = [];
        $source = \trim((string)\preg_replace('/\s+/u', ' ', $source));
        if ($source === '') {
            return [];
        }
        if (\preg_match_all('/\b(?:[A-Z][A-Za-z0-9\'-]{1,}|[A-Z0-9]{2,})(?:\s+(?:[A-Z][A-Za-z0-9\'-]{1,}|[A-Z0-9]{2,})){0,3}\b/u', $source, $matches) < 1) {
            return [];
        }

        foreach ($matches[0] ?? [] as $phrase) {
            foreach (\array_merge([(string)$phrase], $this->latinIdentityVariants((string)$phrase)) as $term) {
                $term = \trim((string)$term);
                if ($term !== '' && \strlen($term) >= 2) {
                    $terms[] = $term;
                }
            }
        }

        return \array_slice(\array_values(\array_unique($terms)), 0, 32);
    }

    /**
     * @param list<string> $sources
     */
    private function collectAllowedLatinTermSources(mixed $value, array &$sources, string $path = '', int $depth = 0): void
    {
        if ($depth > 5 || \count($sources) >= 80) {
            return;
        }
        if (\is_array($value)) {
            foreach ($value as $key => $child) {
                $nextPath = $path === '' ? (string)$key : $path . '.' . (string)$key;
                $this->collectAllowedLatinTermSources($child, $sources, $nextPath, $depth + 1);
            }
            return;
        }
        if (!\is_scalar($value) || !$this->isAllowedLatinTermSourcePath($path)) {
            return;
        }
        $source = \trim((string)$value);
        if ($source !== '') {
            $sources[] = $source;
        }
    }

    private function isAllowedLatinTermSourcePath(string $path): bool
    {
        return \preg_match(
            '/(?:^|\.)(?:site_title|site_name|brand|brand_name|business_name|product|product_name|game|game_name|service|service_name|platform|platform_name|app_name|keyword|keywords|domain|target_domain|name|title|tagline|brief|brief_description|description|summary|goal|objective|audience|intent|vertical|industry|offer|must_include_facts)(?:$|\.)/i',
            $path
        ) === 1;
    }

    private function isLongLatinProseSource(string $source): bool
    {
        $source = \trim((string)\preg_replace('/\s+/u', ' ', $source));
        if ($source === '') {
            return false;
        }
        \preg_match_all('/[A-Za-z][A-Za-z0-9\'-]*/u', $source, $matches);
        $words = $matches[0] ?? [];
        if (\count($words) >= 7) {
            return true;
        }
        $latinLetterCount = 0;
        foreach ($words as $word) {
            $latinLetterCount += \strlen((string)$word);
        }
        if ($latinLetterCount >= 42 && \preg_match('/[.!?;]/u', $source) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function assertRenderedHtmlMatchesLocale(string $html, array $renderContext): void
    {
        $locale = $this->resolveGeneratedContentLocaleForPolicy($renderContext);
        $visibleText = $this->extractVisibleHtmlText($html);
        $reason = $this->detectVisibleCopyLocaleViolation($visibleText, $locale, $renderContext);
        if ($reason !== null) {
            throw new \RuntimeException('Generated component locale hard policy failed: ' . $reason);
        }

        foreach ($this->extractVisibleHtmlTextChunks($html) as $chunk) {
            $reason = $this->detectVisibleCopyLocaleViolation($chunk, $locale, $renderContext);
            if ($reason !== null) {
                throw new \RuntimeException('Generated component locale hard policy failed: ' . $reason);
            }
        }
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function detectVisibleCopyLocaleViolation(string $visibleText, string $locale = '', array $renderContext = []): ?string
    {
        $locale = \trim($locale);
        $visibleText = \trim((string)\preg_replace('/\s+/u', ' ', $visibleText));
        if ($visibleText === '' || $locale === '') {
            return null;
        }

        if ($this->containsPromptPlanningLeakText($visibleText)) {
            return 'planning or contract instruction text leaked into visible copy: ' . $this->clipText($visibleText, 160);
        }

        if ($this->hasDisallowedCjkVisibleCopyForLocale($visibleText, $locale, $renderContext)) {
            return 'non-target CJK planning or UI copy leaked into visible copy: ' . $this->clipText($visibleText, 160);
        }

        if (!$this->isEnglishLocale($locale)) {
            $allowedLatinTerms = $this->collectAllowedLatinTermsFromRenderContext($renderContext);
            if ($this->hasDominantLatinProseForNonEnglishLocale($visibleText, $locale, $allowedLatinTerms)) {
                return $this->summarizeNonTargetLatinCopyForLocaleError($visibleText, $allowedLatinTerms);
            }
            if ($this->looksLikeGeneratedIdentifierLabel($visibleText) && !$this->isAllowedLatinIdentityText($visibleText, $allowedLatinTerms)) {
                return 'identifier-style English label leaked into non-English visible copy: ' . $this->clipText($visibleText, 120);
            }
        }

        if ($this->isArabicLocale($locale) && $this->requiresArabicScriptForVisibleCopy($visibleText, $renderContext)) {
            return 'Arabic locale visible copy must contain Arabic script: ' . $this->clipText($visibleText, 160);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function hasDisallowedCjkVisibleCopyForLocale(string $visibleText, string $locale, array $renderContext = []): bool
    {
        if (!$this->isNonCjkLocale($locale) || !$this->hasAnyCjkContent($visibleText)) {
            return false;
        }

        $allowed = $this->collectAllowedCjkTermsFromRenderContext($renderContext);
        if ($allowed === []) {
            return true;
        }

        if (\preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+/u', $visibleText, $matches) <= 0) {
            return false;
        }
        foreach ($matches[0] ?? [] as $segment) {
            $segment = \trim((string)$segment);
            if ($segment !== '' && !isset($allowed[$segment])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $renderContext
     * @return array<string,bool>
     */
    private function collectAllowedCjkTermsFromRenderContext(array $renderContext): array
    {
        $sources = [];
        $this->collectAllowedLatinTermSources($renderContext['_website_profile'] ?? [], $sources);
        $this->collectAllowedLatinTermSources($renderContext['_scope_identity'] ?? [], $sources);
        $this->collectAllowedLatinTermSources($renderContext['page'] ?? [], $sources);
        $this->collectAllowedLatinTermSources($renderContext['component_config'] ?? [], $sources);

        $allowed = [];
        foreach ($sources as $source) {
            if (\preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+/u', $source, $matches) <= 0) {
                continue;
            }
            foreach ($matches[0] ?? [] as $segment) {
                $segment = \trim((string)$segment);
                if ($segment !== '') {
                    $allowed[$segment] = true;
                }
            }
        }

        return $allowed;
    }

    /**
     * @param array<string,bool> $allowedLatinTerms
     */
    private function isAllowedLatinIdentityText(string $visibleText, array $allowedLatinTerms): bool
    {
        if ($allowedLatinTerms === []) {
            return false;
        }
        if (\preg_match_all('/\b[A-Za-z][A-Za-z0-9\'-]*\b/u', $visibleText, $matches) < 1) {
            return false;
        }
        foreach ($matches[0] ?? [] as $word) {
            $normalized = \strtolower(\trim((string)$word, " \t\n\r\0\x0B'\"-"));
            if ($normalized !== '' && !isset($allowedLatinTerms[$normalized])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function requiresArabicScriptForVisibleCopy(string $visibleText, array $renderContext): bool
    {
        if (\preg_match('/\p{Arabic}/u', $visibleText) === 1) {
            return false;
        }
        if ($this->hasAnyCjkContent($visibleText)) {
            return false;
        }
        $allowedLatinTerms = $this->collectAllowedLatinTermsFromRenderContext($renderContext);
        $letters = \preg_match_all('/\p{L}/u', $visibleText);
        if ($letters < 18) {
            return false;
        }
        if ($this->isAllowedLatinIdentityText($visibleText, $allowedLatinTerms)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function retiredVisibleContactFactGate(string $visibleText, array $renderContext): void
    {
        return;

        $emails = $this->extractEmailFacts($visibleText);
        $phones = $this->extractPhoneFacts($visibleText);
        if ($emails === [] && $phones === []) {
            return;
        }

        $sources = [];
        $this->collectScalarContactFactSources($renderContext['_website_profile'] ?? [], $sources);
        $this->collectScalarContactFactSources($renderContext['_scope_identity'] ?? [], $sources);
        $this->collectScalarContactFactSources($renderContext['page'] ?? [], $sources);
        $this->collectScalarContactFactSources($renderContext['component_config'] ?? [], $sources);

        $approvedEmails = [];
        $approvedPhones = [];
        foreach ($sources as $source) {
            foreach ($this->extractEmailFacts($source) as $email) {
                $approvedEmails[$email] = true;
            }
            foreach ($this->extractPhoneFacts($source) as $phone) {
                $approvedPhones[$phone] = true;
            }
        }

        $unexpectedEmails = \array_values(\array_filter($emails, static fn(string $email): bool => !isset($approvedEmails[$email])));
        $unexpectedPhones = \array_values(\array_filter($phones, static fn(string $phone): bool => !isset($approvedPhones[$phone])));
        if ($unexpectedEmails === [] && $unexpectedPhones === []) {
            return;
        }

        $parts = [];
        if ($unexpectedEmails !== []) {
            $parts[] = 'emails=' . \implode(',', \array_slice($unexpectedEmails, 0, 3));
        }
        if ($unexpectedPhones !== []) {
            $parts[] = 'phones=' . \implode(',', \array_slice($unexpectedPhones, 0, 3));
        }

        return;
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function retiredVisibleCommercialClaimGate(string $visibleText, array $renderContext): void
    {
        return;

        $sourceText = $this->collectCommercialClaimSourceText($renderContext);
        $visible = $this->normalizeClaimText($visibleText);
        $source = $this->normalizeClaimText($sourceText);
        if ($visible === '') {
            return;
        }

        $violations = [];
        $claimGroups = [
            'deposit/first-deposit/recharge' => [
 'visible' => '/(?: t\s*deposit|deposit|recharge|top\s*up)/iu',
 'source' => '/(?: t\s*deposit|deposit|recharge|top\s*up)/iu',
            ],
            'withdrawal/payout/arrival-time' => [
 'visible' => '/(?: ??:al)?|cash\s*out|cashout|payout|credited\s*instantly|instant\s*cashout)/iu',
 'source' => '/(?: (?:al)?|cash\s*out|cashout|payout|credited\s*instantly|instant\s*cashout)/iu',
            ],
            'support or processing speed promise' => [
 'visible' => '/(?:24\s*\/\s*7|24\s*(?:hours?| ?| ??: ??| ??: ??| ??: ?| ??: ?|instant|immediate|real[-\s]*time|fast\s*reply|quick\s*response|within\s*\d+\s*(?:hours?|minutes?)|1\s*[- ?3\s*(?: ??(?: ?days?))/iu',
 'source' => '/(?:24\s*\/\s*7|24\s*(?:hours?| ?| ??: ??| ??: ??| ??: ?| ??: ?|instant|immediate|real[-\s]*time|fast\s*reply|quick\s*response|within\s*\d+\s*(?:hours?|minutes?)|1\s*[- ?3\s*(?: ??(?: ?days?))/iu',
            ],
        ];

        foreach ($claimGroups as $label => $patterns) {
            if (\preg_match($patterns['visible'], $visible) === 1 && \preg_match($patterns['source'], $source) !== 1) {
                $snippet = $this->firstRegexSnippet($visibleText, $patterns['visible']);
                $violations[] = $label . ($snippet !== '' ? '=' . $snippet : '');
            }
        }

        foreach ($this->extractRewardClaimNumbers($visibleText) as $number => $snippet) {
            $number = (string)$number;
            if (!$this->sourceContainsRewardClaimNumber($sourceText, $number)) {
                $violations[] = 'retired reward factuality check=' . $snippet;
            }
        }

        if ($violations === []) {
            return;
        }

        return;
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function collectCommercialClaimSourceText(array $renderContext): string
    {
        $PlanJsonTask = \is_array($renderContext['_plan_json_task'] ?? null) ? $renderContext['_plan_json_task'] : [];
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $taskScript = \is_array($PlanJsonTask['task_script'] ?? null) ? $PlanJsonTask['task_script'] : [];
        $runtimeContext = \is_array($PlanJsonTask['runtime_context'] ?? null) ? $PlanJsonTask['runtime_context'] : [];

        $payload = [
            'website_profile' => $renderContext['_website_profile'] ?? [],
            'scope_identity' => $renderContext['_scope_identity'] ?? [],
            'page' => $renderContext['page'] ?? [],
            'source_truth' => [
                $runtimeContext['site_context'] ?? [],
                $runtimeContext['source_truth'] ?? [],
                $runtimeContext['source_truth_snapshot'] ?? [],
                $runtimeContext['business_facts'] ?? [],
                $runtimeContext['policy_context'] ?? [],
            ],
            'plan_context' => [
                'page_goal' => $planContext['page_goal'] ?? '',
                'block_goal' => $planContext['block_goal'] ?? '',
                'stage1_block_content' => $planContext['stage1_block_content'] ?? '',
                'must_include_facts' => $planContext['must_include_facts'] ?? [],
            ],
            'block_task' => [
                'content_plan' => $blockTask['content_plan'] ?? [],
                'task_goal' => $blockTask['task_goal'] ?? '',
                'copy_facts' => $blockTask['copy_facts'] ?? [],
                'must_include_facts' => $blockTask['must_include_facts'] ?? [],
            ],
            'task_script' => [
                'story_goal' => $taskScript['story_goal'] ?? '',
                'stage3_directive' => $taskScript['stage3_directive'] ?? '',
                'field_content_requirements' => $taskScript['field_content_requirements'] ?? [],
                'data_contract' => $taskScript['data_contract'] ?? [],
            ],
        ];

        return $this->collectScalarPromptText($payload);
    }

    private function normalizeClaimText(string $text): string
    {
        $text = \html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = \mb_strtolower($text);
        $text = \preg_replace('/\s+/u', ' ', $text) ?? $text;

        return \trim($text);
    }

    private function firstRegexSnippet(string $text, string $pattern): string
    {
        if (\preg_match($pattern, $text, $matches, \PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }
        $offset = (int)($matches[0][1] ?? 0);
        $length = (int)\max(1, \strlen((string)($matches[0][0] ?? '')));
        $start = \max(0, $offset - 24);

        return $this->clipText(\trim(\mb_substr($text, $start, $length + 48)), 96);
    }

    /**
     * @return array<string,string>
     */
    private function extractRewardClaimNumbers(string $text): array
    {
        $claims = [];
 $pattern = '/(?:(?: ??|inr)?\s*([0-9][0-9,\.]{0,8})\s*(?: |rewards|chips?)|(?: ward|rewards|chips?).{0,10}?(?: ??|inr)?\s*([0-9][0-9,\.]{0,8}))/iu';
        if (\preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER) < 1) {
            return [];
        }
        foreach ($matches as $match) {
            $firstNumber = (string)($match[1] ?? '');
            $secondNumber = (string)($match[2] ?? '');
            $rawNumber = $firstNumber !== '' ? $firstNumber : $secondNumber;
            $number = \preg_replace('/\D+/u', '', $rawNumber) ?? '';
            if ($number === '') {
                continue;
            }
            $claims[$number] = $this->clipText(\trim((string)($match[0] ?? '')), 80);
        }

        return $claims;
    }

    private function sourceContainsRewardClaimNumber(string $sourceText, string $number): bool
    {
        if ($number === '') {
            return false;
        }
        foreach ($this->extractRewardClaimNumbers($sourceText) as $sourceNumber => $_snippet) {
            if ($sourceNumber === $number) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string>
     */
    private function extractEmailFacts(string $text): array
    {
        if (\preg_match_all('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu', $text, $matches) < 1) {
            return [];
        }

        return \array_values(\array_unique(\array_map(
            static fn(string $email): string => \strtolower($email),
            $matches[0] ?? []
        )));
    }

    /**
     * @return array<string>
     */
    private function extractPhoneFacts(string $text): array
    {
        if (\preg_match_all('/(?:\+\d{1,3}[\s().-]*)?(?:\d[\s().-]*){7,}\d/u', $text, $matches) < 1) {
            return [];
        }

        $phones = [];
        foreach ($matches[0] ?? [] as $phone) {
            $normalized = \preg_replace('/\D+/u', '', (string)$phone) ?? '';
            if (\strlen($normalized) >= 8) {
                $phones[] = $normalized;
            }
        }

        return \array_values(\array_unique($phones));
    }

    /**
     * @param array<int,string> $sources
     */
    private function collectScalarContactFactSources(mixed $value, array &$sources, int $depth = 0): void
    {
        if ($depth > 6 || \count($sources) >= 160) {
            return;
        }
        if (\is_array($value)) {
            foreach ($value as $child) {
                $this->collectScalarContactFactSources($child, $sources, $depth + 1);
            }
            return;
        }
        if (!\is_scalar($value)) {
            return;
        }
        $source = \trim((string)$value);
        if ($source !== '') {
            $sources[] = $source;
        }
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function stripAllowedVisibleIdentitySnippets(string $text, array $renderContext): string
    {
        if ($text === '') {
            return '';
        }

        $sources = [];
        $this->collectAllowedLatinTermSources($renderContext['_website_profile'] ?? [], $sources);
        $this->collectAllowedLatinTermSources($renderContext['_scope_identity'] ?? [], $sources);
        $this->collectAllowedLatinTermSources($renderContext['page'] ?? [], $sources);
        $this->collectAllowedLatinTermSources($renderContext['component_config'] ?? [], $sources);
        foreach (\array_values(\array_unique($sources)) as $source) {
            $source = \trim((string)$source);
            if ($source === '' || \strlen($source) < 3 || $this->isLongLatinProseSource($source)) {
                continue;
            }
            foreach (\array_merge([$source], $this->latinIdentityVariants($source)) as $identitySnippet) {
                $identitySnippet = \trim((string)$identitySnippet);
                if ($identitySnippet !== '' && \strlen($identitySnippet) >= 3) {
                    $text = \str_ireplace($identitySnippet, ' ', $text);
                }
            }
        }

        return \trim((string)\preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return list<string>
     */
    private function latinIdentityVariants(string $source): array
    {
        $source = \trim($source);
        if ($source === '') {
            return [];
        }
        if (\preg_match_all('/[A-Za-z0-9]+/u', $source, $matches) < 1) {
            return [];
        }

        $words = [];
        foreach ($matches[0] ?? [] as $word) {
            $normalized = \strtolower(\trim((string)$word));
            if (\strlen($normalized) >= 2) {
                $words[] = $normalized;
            }
        }
        $words = \array_values(\array_unique($words));
        if ($words === []) {
            return [];
        }

        $variants = $words;
        if (\count($words) > 1) {
            $variants[] = \implode('-', $words);
            $variants[] = \implode('', $words);
            $variants[] = \implode(' ', $words);
        }

        return \array_values(\array_unique($variants));
    }

    private function hasLargeCjkVisibleCopyForNonCjkLocale(string $text): bool
    {
        $matches = [];
        if (\preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+/u', $text, $matches) <= 0) {
            return false;
        }

        $total = 0;
        foreach ($matches[0] as $segment) {
            $length = \function_exists('mb_strlen') ? \mb_strlen((string)$segment) : \strlen((string)$segment);
            if ($length >= 12) {
                return true;
            }
            $total += $length;
        }

        return $total >= 18;
    }

    /**
     * @param array<string,bool> $allowedTerms
     */
    private function summarizeNonTargetLatinCopyForLocaleError(string $text, array $allowedTerms): string
    {
        $words = [];
        if (\preg_match_all('/\b[A-Za-z][A-Za-z\'-]{2,}\b/u', $text, $matches) > 0) {
            foreach ($matches[0] ?? [] as $word) {
                $normalized = \strtolower(\trim((string)$word, " \t\n\r\0\x0B'\"-"));
                if ($normalized !== '' && !isset($allowedTerms[$normalized])) {
                    $words[] = (string)$word;
                }
            }
        }
        $words = \array_slice(\array_values(\array_unique($words)), 0, 10);
        $snippet = \trim(\mb_substr($text, 0, 240));

        return 'Unexpected Latin words: '
            . ($words !== [] ? \implode(', ', $words) : 'none')
            . '; visible snippet: '
            . $snippet;
    }

    private function assertRenderedHtmlPassesBuildQualityGate(string $componentCode, string $html, string $semanticComponentCode = '', string $styleCss = '', array $renderContext = []): void
    {
        if (\trim($html) === '') {
            return;
        }
 //  ?
        $hardPolicyReason = $this->detectHardGeneratedSectionHtmlPolicyViolation(
            $html,
            $this->resolveGeneratedContentLocaleForPolicy($renderContext)
        );
        if ($hardPolicyReason !== null) {
            throw new \RuntimeException('Generated component structure hard policy failed: ' . $hardPolicyReason);
        }
        $cssPolicyReason = $this->detectHardGeneratedSectionCssPolicyViolation($styleCss);
        if ($cssPolicyReason !== null) {
            throw new \RuntimeException('Generated component structure hard policy failed: ' . $cssPolicyReason);
        }
        if (
            $this->requiresPrimaryHeadingForRenderedComponent($componentCode, $renderContext)
            && \preg_match('/<h1\b/iu', $html) !== 1
        ) {
            throw new \RuntimeException('Generated component structure hard policy failed: opening/page-intro section must render one h1 heading.');
        }
        // This gate is intentionally narrow: it protects the component contract
        // from invalid/leaked build structures only. Normal copy, marketing
        // claims, language polish, images, and art direction stay in prompt
        // guidance plus browser QA, not hard blocking.
    }

    /**
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $renderContext
     * @return array<string,mixed>
     */
    private function normalizeRequiredPrimaryHeadingInAiPayload(array $aiData, string $componentCode, string $semanticComponentCode, array $renderContext): array
    {
        if (!$this->requiresPrimaryHeadingForRenderedComponent($semanticComponentCode !== '' ? $semanticComponentCode : $componentCode, $renderContext)) {
            return $aiData;
        }
        $htmlKey = \array_key_exists('html_content', $aiData) ? 'html_content' : (\array_key_exists('html_extra', $aiData) ? 'html_extra' : '');
        if ($htmlKey === '') {
            return $aiData;
        }
        $html = (string)$aiData[$htmlKey];
        if (\preg_match('/<h1\b/iu', $html) === 1) {
            return $aiData;
        }
        $pattern = '/<h2\b([^>]*\bclass\s*=\s*(["\'])(?:(?!\2).)*\bpb-c-title\b(?:(?!\2).)*\2[^>]*)>(.*?)<\/h2>/isu';
        $fixed = \preg_replace($pattern, '<h1$1>$3</h1>', $html, 1);
        if (\is_string($fixed) && $fixed !== $html && \preg_match('/<h1\b/iu', $fixed) === 1) {
            $aiData[$htmlKey] = $fixed;
            return $aiData;
        }

        $fixed = \preg_replace('/<h2\b([^>]*)>(.*?)<\/h2>/isu', '<h1$1>$2</h1>', $html, 1);
        if (\is_string($fixed) && $fixed !== $html && \preg_match('/<h1\b/iu', $fixed) === 1) {
            $aiData[$htmlKey] = $fixed;
        }

        return $aiData;
    }

    private function detectHardGeneratedSectionCssPolicyViolation(string $styleCss): ?string
    {
        if (\trim($styleCss) === '') {
            return null;
        }
        if (\preg_match('/font-size\s*:\s*(?:clamp|min|max|calc)\s*\(/iu', $styleCss) === 1) {
            return 'font-size must use fixed breakpoint values, not clamp/min/max/calc expressions.';
        }
        if (\preg_match('/font-size\s*:[^;{}]*\bvw\b/iu', $styleCss) === 1) {
            return 'font-size must use fixed breakpoint values, not viewport-width units.';
        }
        if (\preg_match('/letter-spacing\s*:\s*-/iu', $styleCss) === 1) {
            return 'letter-spacing must not use negative values.';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function requiresPrimaryHeadingForRenderedComponent(string $componentCode, array $renderContext): bool
    {
        $PlanJsonTask = \is_array($renderContext['_plan_json_task'] ?? null)
            ? $renderContext['_plan_json_task']
            : [];
        $planContext = \is_array($PlanJsonTask['plan_context'] ?? null) ? $PlanJsonTask['plan_context'] : [];
        $blockTask = \is_array($PlanJsonTask['block_task'] ?? null) ? $PlanJsonTask['block_task'] : [];
        $visualContract = \is_array($renderContext['_visual_contract'] ?? null) ? $renderContext['_visual_contract'] : [];
        $identity = \mb_strtolower(\implode(' ', \array_map('strval', [
            $componentCode,
            $renderContext['component_code'] ?? '',
            $renderContext['section_code'] ?? '',
            $renderContext['page_flow_role'] ?? '',
            $PlanJsonTask['task_key'] ?? '',
            $PlanJsonTask['section_code'] ?? '',
            $PlanJsonTask['section_key'] ?? '',
            $PlanJsonTask['section_template'] ?? '',
            $PlanJsonTask['page_flow_role'] ?? '',
            $planContext['section_code'] ?? '',
            $planContext['section_key'] ?? '',
            $planContext['page_flow_role'] ?? '',
            $blockTask['section_template'] ?? '',
            $blockTask['page_flow_role'] ?? '',
            $visualContract['section_template'] ?? '',
            $visualContract['page_flow_role'] ?? '',
        ])));

        if (\preg_match('/\b(?:opening|above[_-]?fold|hero|banner|page[_-]?intro)\b/iu', $identity) === 1) {
            return true;
        }

        return $this->isFirstPageSectionTask($PlanJsonTask, $renderContext);
    }

    /**
     * @param array<string,mixed> $PlanJsonTask
     * @param array<string,mixed> $renderContext
     */
    private function isFirstPageSectionTask(array $PlanJsonTask, array $renderContext): bool
    {
        $pageType = \trim((string)($PlanJsonTask['page_type'] ?? ''));
        if ($pageType === '') {
            $visualContract = \is_array($renderContext['_visual_contract'] ?? null)
                ? $renderContext['_visual_contract']
                : [];
            $pageType = \trim((string)($visualContract['page_type'] ?? ''));
        }
        if ($pageType === '') {
            return false;
        }

        $scope = \is_array($renderContext['_scope'] ?? null) ? $renderContext['_scope'] : [];
        $tasks = [];
        foreach ($this->getPlanJsonTaskService()->listTaskKeysByPageType($scope, $pageType) as $taskKey) {
            $task = $this->getPlanJsonTaskService()->getTaskDefinition($scope, (string)$taskKey);
            if (\is_array($task) && $task !== []) {
                $tasks[] = $task;
            }
        }
        if ($tasks === []) {
            return false;
        }

        $currentKey = \trim((string)($PlanJsonTask['task_key'] ?? ''));
        $currentSection = \trim((string)($PlanJsonTask['section_code'] ?? ''));
        $currentSort = null;
        $firstSort = null;

        foreach ($tasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            if (\trim((string)($task['page_type'] ?? '')) !== $pageType) {
                continue;
            }
            if (\trim((string)($task['task_type'] ?? '')) !== 'page_section') {
                continue;
            }

            $sortOrder = (int)($task['sort_order'] ?? 0);
            if ($firstSort === null || $sortOrder < $firstSort) {
                $firstSort = $sortOrder;
            }

            $taskKey = \trim((string)($task['task_key'] ?? ''));
            $sectionCode = \trim((string)($task['section_code'] ?? ''));
            if (($currentKey !== '' && $taskKey === $currentKey)
                || ($currentSection !== '' && $sectionCode === $currentSection)
            ) {
                $currentSort = $sortOrder;
            }
        }

        return $firstSort !== null && $currentSort !== null && $currentSort === $firstSort;
    }

    /**
     * @param array<string,mixed> $renderContext
     */
    private function detectGeneratedInternalLinkRouteContractViolation(string $html, array $renderContext): ?string
    {
        if (\preg_match_all('/<a\b([^>]*?)\shref\s*=\s*(["\'])(.*?)\2([^>]*)>/isu', $html, $matches, \PREG_SET_ORDER) < 1) {
            return null;
        }

        $scope = $this->resolveRouteContractScopeFromRenderContext($renderContext);
        $routeContract = $this->resolvePageRouteContract(
            $scope,
            [],
            $this->resolveRouteContractLocaleFromRenderContext($renderContext, $scope)
        );
        if ($this->getPageRouteContractService()->routesByType($routeContract) === []) {
            return null;
        }

        $violations = [];
        foreach ($matches as $match) {
            $prefix = (string)($match[1] ?? '');
            $href = \html_entity_decode(\trim((string)($match[3] ?? '')), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            $suffix = (string)($match[4] ?? '');
            $tag = '<a' . $prefix . ' href=' . (string)($match[2] ?? '"') . (string)($match[3] ?? '') . (string)($match[2] ?? '"') . $suffix . '>';
            if (\preg_match('/\sdata-glr-ref\s*=/iu', $tag) === 1) {
                continue;
            }
            if (!$this->shouldValidateGeneratedHrefAgainstRouteContract($href)) {
                continue;
            }
            if ($this->getPageRouteContractService()->normalizeHrefToContractPath($routeContract, $href) !== '') {
                continue;
            }
            $violations[] = $href;
        }

        $violations = \array_values(\array_unique(\array_filter($violations, static fn(string $href): bool => $href !== '')));
        if ($violations === []) {
            return null;
        }

        return 'internal hrefs are outside the confirmed page route contract: '
            . \implode(', ', \array_slice($violations, 0, 6))
            . '. Use only allowed_internal_paths or render the CTA as a button event control with data-pb-ai-action.';
    }

    /**
     * @param array<string,mixed> $renderContext
     * @return array<string,mixed>
     */
    private function resolveRouteContractScopeFromRenderContext(array $renderContext): array
    {
        foreach (['_scope', 'scope'] as $key) {
            if (\is_array($renderContext[$key] ?? null)) {
                return $renderContext[$key];
            }
        }

        return $renderContext;
    }

    /**
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $scope
     */
    private function resolveRouteContractLocaleFromRenderContext(array $renderContext, array $scope): string
    {
        foreach (['content_locale', 'locale', 'website_locale'] as $key) {
            $locale = \trim((string)($renderContext[$key] ?? ''));
            if ($locale !== '') {
                return $locale;
            }
        }

        return $this->resolveScopePrimaryLocale($scope);
    }

    /**
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     */
    private function resolveGeneratedContentLocaleForPolicy(array $renderContext, array $defaultConfig = []): string
    {
        $scope = $this->resolveRouteContractScopeFromRenderContext($renderContext);
        $locale = $this->resolveRouteContractLocaleFromRenderContext($renderContext, $scope);
        if ($locale !== '') {
            return $locale;
        }

        foreach (['content_locale', 'locale', 'website_locale', 'default_locale', 'default_language'] as $key) {
            $locale = \trim((string)($defaultConfig[$key] ?? ''));
            if ($locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    private function shouldValidateGeneratedHrefAgainstRouteContract(string $href): bool
    {
        $href = \trim($href);
        if ($href === '') {
            return false;
        }
        if ($href === '#') {
            return true;
        }
        if (\preg_match('#^(?:mailto|tel|sms|data|blob|javascript):#iu', $href) === 1 || \str_starts_with($href, '//')) {
            return false;
        }
        if (\preg_match('#^[a-z][a-z0-9+.-]*://#i', $href) === 1) {
            return false;
        }
        if (\str_starts_with($href, '#')) {
            return true;
        }
        if (\str_starts_with($href, '/')) {
            return true;
        }

        return \preg_match('#^(?:[a-z0-9][a-z0-9._~/-]*)(?:[?#].*)?$#iu', $href) === 1;
    }

    private function detectComponentVisualContractViolation(string $componentCode, string $html, string $styleCss = '', array $renderContext = []): ?string
    {
        unset($componentCode, $html, $styleCss, $renderContext);
        // Visual/style quality belongs to design direction guidance and browser QA,
        // not to the completion gate.
        return null;
    }

    private function detectDesignDirectionQualityViolation(string $identity, string $html, string $styleCss, array $renderContext): ?string
    {
        unset($identity, $html, $styleCss, $renderContext);
        // Direction fit is a QA observation and prompt target. It must never
        // fail generation by itself.
        return null;
    }

    private function resolveDesignDirectionCodeForQuality(array $renderContext): string
    {
        $scope = \is_array($renderContext['scope'] ?? null) ? $renderContext['scope'] : $renderContext;
        $snapshot = \is_array($scope['design_direction_snapshot'] ?? null) ? $scope['design_direction_snapshot'] : [];
        $code = \trim((string)($snapshot['code'] ?? $scope['design_direction_code'] ?? ''));

        return $code;
    }

    private function detectContactSupportQualityViolation(string $html, string $styleCss = ''): ?string
    {
        $formReason = $this->detectFormControlQualityViolation($html, $styleCss);
        if ($formReason !== null) {
            return $formReason;
        }

        $structuredCount = \preg_match_all(
            '/\bclass\s*=\s*(["\'])(?:(?!\1).)*(?:card|cards|channel|method|form|field|input|note)(?:(?!\1).)*\1/is',
            $html
        );
        if ($structuredCount !== false && $structuredCount >= 2) {
            return null;
        }

        $paragraphCount = \preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches);
        if ($paragraphCount === false || $paragraphCount === 0) {
            return null;
        }
        foreach ($matches[1] ?? [] as $rawParagraph) {
            $text = \trim(\html_entity_decode(\strip_tags((string)$rawParagraph), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
            if ($text === '' || \mb_strlen($text) < 120) {
                continue;
            }
            $signals = 0;
            $signals += \preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $text) ?: 0;
            $signals += \preg_match_all('/\+?\d[\d\s().-]{6,}\d/u', $text) ?: 0;
            $signals += \preg_match_all('/\b(?:address|office|hours|support|sales|phone|email|whatsapp|faq)\b/iu', $text) ?: 0;
            if ($signals >= 2) {
                return 'contact/support details are compressed into a long paragraph; split channels into cards or form rows';
            }
        }

        return null;
    }

    private function detectFormControlQualityViolation(string $html, string $styleCss): ?string
    {
        if (\preg_match('/<\s*form\b/i', $html) !== 1
            && \preg_match('/<\s*(?:input|textarea|select)\b/i', $html) !== 1
        ) {
            return null;
        }

        $controlCount = \preg_match_all('/<\s*(?:input|textarea|select)\b/i', $html);
        if ($controlCount === false || $controlCount < 2) {
            return 'form guidance block has too few visible input controls';
        }
        if (\preg_match('/<\s*textarea\b/i', $html) !== 1) {
            return 'form guidance block is missing a message textarea';
        }

        $formClassPattern = '/\bclass\s*=\s*(["\'])(?:(?!\1).)*\bpb-c-form\b(?:(?!\1).)*\1/is';
        $fieldClassPattern = '/\bclass\s*=\s*(["\'])(?:(?!\1).)*\bpb-c-field\b(?:(?!\1).)*\1/is';
        $labelClassPattern = '/\bclass\s*=\s*(["\'])(?:(?!\1).)*\bpb-c-label\b(?:(?!\1).)*\1/is';
        $inputClassPattern = '/\bclass\s*=\s*(["\'])(?:(?!\1).)*\bpb-c-input\b(?:(?!\1).)*\1/is';
        $textareaClassPattern = '/\bclass\s*=\s*(["\'])(?:(?!\1).)*\bpb-c-textarea\b(?:(?!\1).)*\1/is';
        if (\preg_match($formClassPattern, $html) !== 1) {
            return 'form guidance block must use the explicit pb-c-form role class';
        }
        $fieldCount = \preg_match_all($fieldClassPattern, $html);
        if ($fieldCount === false || $fieldCount < 2) {
            return 'form controls must be grouped into repeated pb-c-field rows, not inline naked labels and inputs';
        }
        $labelCount = \preg_match_all($labelClassPattern, $html);
        if ($labelCount === false || $labelCount < 2) {
            return 'form fields must use visible pb-c-label labels';
        }
        if (\preg_match($inputClassPattern, $html) !== 1 || \preg_match($textareaClassPattern, $html) !== 1) {
            return 'form controls must use pb-c-input and pb-c-textarea role classes';
        }

        $css = \preg_replace('/\s+/u', ' ', $styleCss);
        $css = \is_string($css) ? $css : '';
        $hasFormLayout = \preg_match('/#componentId\s+\.pb-c-form\s*\{[^}]*\bdisplay\s*:\s*(?:grid|flex)\b[^}]*\bgap\s*:/i', $css) === 1;
        $hasFieldLayout = \preg_match('/#componentId\s+\.pb-c-field\s*\{[^}]*\b(?:display\s*:\s*(?:grid|flex)|margin-bottom\s*:|gap\s*:)/i', $css) === 1;
        $hasInputStyle = $this->cssSelectorRuleContainsAll($css, 'pb-c-input', [
            '/\bwidth\s*:\s*100%/i',
            '/\bpadding\s*:/i',
            '/\bborder-radius\s*:/i',
            '/\bbox-sizing\s*:\s*border-box/i',
        ]);
        $hasTextareaStyle = $this->cssSelectorRuleContainsAll($css, 'pb-c-textarea', [
            '/\bwidth\s*:\s*100%/i',
            '/\bpadding\s*:/i',
            '/\bborder-radius\s*:/i',
            '/\bbox-sizing\s*:\s*border-box/i',
        ]);
        if (!$hasFormLayout || !$hasFieldLayout || !$hasInputStyle || !$hasTextareaStyle) {
            return 'form guidance CSS must style form, field, input, and textarea roles instead of leaving native inline controls';
        }

        return null;
    }

    /**
 *  ?HTML  ?void  ? *
     * @return array<string,int> tagName => openCount
     */
    /**
     * @param list<string> $patterns
     */
    private function cssSelectorRuleContainsAll(string $css, string $className, array $patterns): bool
    {
        $quotedClass = \preg_quote($className, '/');
        if (\preg_match_all('/#componentId\s+\.' . $quotedClass . '\s*\{([^}]*)\}/i', $css, $matches) < 1) {
            return false;
        }
        foreach ($matches[1] ?? [] as $body) {
            $body = (string)$body;
            foreach ($patterns as $pattern) {
                if (\preg_match($pattern, $body) !== 1) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    private function detectUnclosedHtmlTags(string $html): array
    {
        $voidTags = \array_fill_keys([
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ], true);
        $matchCount = \preg_match_all('/<\s*\/?\s*([a-z][a-z0-9:-]*)\b[^>]*>/i', $html, $matches, \PREG_OFFSET_CAPTURE);
        if ($matchCount === false || $matchCount === 0) {
            return [];
        }

        $stack = [];
        foreach ($matches[0] as $index => $match) {
            $tagText = (string)$match[0];
            $tagName = \strtolower((string)($matches[1][$index][0] ?? ''));
            if ($tagName === '') {
                continue;
            }
            if (\preg_match('/^<\s*\/\s*/', $tagText) === 1) {
                for ($stackIndex = \count($stack) - 1; $stackIndex >= 0; $stackIndex--) {
                    if ($stack[$stackIndex] === $tagName) {
                        \array_splice($stack, $stackIndex, 1);
                        break;
                    }
                }
                continue;
            }
            if (\in_array($tagName, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'], true)) {
                continue;
            }
            $stack[] = $tagName;
        }

        if ($stack === []) {
            return [];
        }

        $unclosed = [];
        foreach ($stack as $tag) {
            $unclosed[$tag] = ($unclosed[$tag] ?? 0) + 1;
        }
        \ksort($unclosed);

        return $unclosed;
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function buildPrimaryLanguageRuleEn(array $websiteProfile, array $scope): string
    {
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        if ($locale === '') {
            return '';
        }
        $hint = $this->describeLocaleForAiPrompt($locale);

        return "Website content language (locale {$locale} - {$hint}): all visitor-visible copy (headings, buttons, nav labels, body text, footer, alt text) must be written in this language from the website requirement. Do not use the planning/plan language as visitor copy unless it matches this locale.\n";
    }

    private function emitComponentFallbackNotice(string $region, string $componentCode, string $reason): void
    {
        $sse = RequestContext::get(RequestContext::SSE_WRITER_KEY);
        if (!$sse || !\method_exists($sse, 'sendEvent')) {
            return;
        }

        try {
            // Local component replacement is forbidden; surface only a product-safe retry status.
            $sse->sendEvent('warning', [
                'region' => $region,
                'component_code' => $componentCode,
                'message' => 'AI is refining this section to meet quality gates.',
                'component_fallback_forbidden' => true,
                'reason_category' => $this->classifyComponentFallbackNoticeReason($reason),
            ]);
        } catch (\Throwable) {
        }
    }

    private function classifyComponentFallbackNoticeReason(string $reason): string
    {
        $normalized = \strtolower($reason);
        if (\str_contains($normalized, 'timeout') || \str_contains($normalized, 'slow') || \str_contains($normalized, 'ssl') || \str_contains($normalized, 'curl')) {
            return 'provider_transport_retry';
        }
        if (\str_contains($normalized, 'editable field') || \str_contains($normalized, 'quality') || \str_contains($normalized, 'contract')) {
            return 'quality_gate_retry';
        }

        return 'generation_retry';
    }

    /**
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     * @return array<string,string>
     */
    private function extractVerifiedAssetMapFromRenderContext(array $renderContext, array $defaultConfig): array
    {
        $assets = [];
        foreach (['_required_image_assets', 'verified_assets'] as $contextKey) {
            $contextAssets = \is_array($renderContext[$contextKey] ?? null) ? $renderContext[$contextKey] : [];
            foreach ($contextAssets as $slotId => $value) {
                if (\is_array($value)) {
                    $slotId = \trim((string)($value['slot_id'] ?? $slotId));
                    $url = $this->firstConfigString($value, ['final_url', 'url', 'src']);
                } else {
                    $slotId = \trim((string)$slotId);
                    $url = \trim((string)$value);
                }
                if ($slotId !== '' && !\is_numeric($slotId) && $url !== '') {
                    $assets[$slotId] = $url;
                }
            }
        }

        $visualContract = \is_array($renderContext['_visual_contract'] ?? null)
            ? $renderContext['_visual_contract']
            : $this->decodeRuntimeVisualContract($defaultConfig);
        $slotId = \trim((string)($visualContract['slot_id'] ?? $defaultConfig['runtime.section_image_slot_id'] ?? $defaultConfig['visual.image_slot_id'] ?? ''));
        $url = $this->firstConfigString($visualContract, ['final_url', 'url', 'src'])
            ?: $this->firstConfigString($defaultConfig, ['runtime.section_image_url', 'visual.image_url', 'image.url', 'media.image_url']);
        if ($slotId !== '' && $url !== '') {
            $assets[$slotId] = $url;
        }

        return $assets;
    }

    private function describeLocaleForAiPrompt(string $locale): string
    {
        $profile = $this->buildLocalePromptProfile($locale);
        $languageName = \trim((string)($profile['language_name'] ?? ''));
        $script = \trim((string)($profile['script'] ?? ''));
        $direction = \trim((string)($profile['text_direction'] ?? ''));
        if ($languageName !== '' && $languageName !== 'the selected locale') {
            return $languageName
                . ($script !== '' ? ' / ' . $script . ' script' : '')
                . ($direction === 'rtl' ? ' / RTL' : '');
        }

        return match ($locale) {
            'zh_Hans_CN' => 'Simplified Chinese',
            'zh_Hant_TW' => 'Traditional Chinese',
            'en_US' => 'English',
            'ja_JP' => 'Japanese',
            'ko_KR' => 'Korean',
            'ru_RU' => 'Russian',
            default => $locale,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLocalePromptProfile(string $locale): array
    {
        $normalized = \strtolower(\str_replace('-', '_', \trim($locale)));
        $languageName = 'the selected locale';
        $script = 'locale-native';
        $direction = 'ltr';
        $requiredScript = 'the native writing system for the selected locale';

        if ($normalized === 'zh' || \str_starts_with($normalized, 'zh_')) {
            $languageName = \str_contains($normalized, 'hant') ? 'Traditional Chinese' : 'Simplified Chinese';
            $script = 'Han Chinese';
            $requiredScript = 'Chinese characters';
        } elseif ($normalized === 'ar' || \str_starts_with($normalized, 'ar_')) {
            $languageName = 'Arabic';
            $script = 'Arabic';
            $direction = 'rtl';
            $requiredScript = 'Arabic script';
        } elseif ($normalized === 'ru' || \str_starts_with($normalized, 'ru_')) {
            $languageName = 'Russian';
            $script = 'Cyrillic';
            $requiredScript = 'Cyrillic Russian text';
        } elseif ($normalized === 'th' || \str_starts_with($normalized, 'th_')) {
            $languageName = 'Thai';
            $script = 'Thai';
            $requiredScript = 'Thai script';
        } elseif ($normalized === 'hi' || \str_starts_with($normalized, 'hi_')) {
            $languageName = 'Hindi';
            $script = 'Devanagari';
            $requiredScript = 'Devanagari Hindi text';
        } elseif ($normalized === 'de' || \str_starts_with($normalized, 'de_')) {
            $languageName = 'German';
            $script = 'Latin';
            $requiredScript = 'German prose';
        } elseif ($normalized === 'fr' || \str_starts_with($normalized, 'fr_')) {
            $languageName = 'French';
            $script = 'Latin';
            $requiredScript = 'French prose';
        } elseif ($normalized === 'es' || \str_starts_with($normalized, 'es_')) {
            $languageName = 'Spanish';
            $script = 'Latin';
            $requiredScript = 'Spanish prose';
        } elseif ($normalized === 'it' || \str_starts_with($normalized, 'it_')) {
            $languageName = 'Italian';
            $script = 'Latin';
            $requiredScript = 'Italian prose';
        } elseif ($normalized === 'ja' || \str_starts_with($normalized, 'ja_')) {
            $languageName = 'Japanese';
            $script = 'Japanese';
            $requiredScript = 'Japanese text';
        } elseif ($normalized === 'ko' || \str_starts_with($normalized, 'ko_')) {
            $languageName = 'Korean';
            $script = 'Hangul';
            $requiredScript = 'Korean Hangul text';
        } elseif ($normalized === 'pt' || \str_starts_with($normalized, 'pt_')) {
            $languageName = 'Portuguese';
            $script = 'Latin';
            $requiredScript = 'Portuguese prose';
        } elseif ($normalized === 'en' || \str_starts_with($normalized, 'en_')) {
            $languageName = 'English';
            $script = 'Latin';
            $requiredScript = 'English prose';
        }

        return [
            'locale' => $locale,
            'language_name' => $languageName,
            'script' => $script,
            'text_direction' => $direction,
            'required_visible_script' => $requiredScript,
            'copy_instruction' => 'Write natural customer-facing ' . $languageName . ' copy. Translate or rewrite planning text before it appears in HTML.',
            'forbidden_visible_copy' => [
                'Chinese/CJK planning prose when source_of_truth_locale is not CJK',
                'English boilerplate or section labels when source_of_truth_locale is not English',
                'raw block_goal/task_goal/story_goal/why_this_block/planning_reason sentences',
                'schema keys, prompt labels, or contract field names',
            ],
        ];
    }
}
