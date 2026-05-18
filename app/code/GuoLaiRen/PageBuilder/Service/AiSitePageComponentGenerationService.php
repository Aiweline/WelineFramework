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
use GuoLaiRen\PageBuilder\Service\AI\QA\RenderDataQualityLinter;
use Weline\Ai\Service\AiService;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\SchedulerSystem;

class AiSitePageComponentGenerationService
{
    private const REQUEST_CTX_AI_CHUNK_FORWARDER = 'pagebuilder.ai.chunk.forwarder';
    public const REQUEST_KEY_FORCE_REAL_AI_IN_TEST = 'pagebuilder.ai.force_real_in_test';
    public const REQUEST_KEY_FAST_BLOCK_ARTIFACT = 'pagebuilder.ai.fast_block_artifact';
    private const SYNTAX_FIX_MAX_ATTEMPTS = 2;
    // BuildPlan v2.2: generated visitor sections must pass quality gates; no fallback copy can ship.
    private const ENFORCE_COMPONENT_QUALITY_VALIDATION = true;
    private const AI_REQUEST_TIMEOUT_SECONDS = 180;
    private const COMPONENT_CSS_CLASS_SCOPE_FALLBACK = 'pb-ai-site-component';
    private const COMPONENT_CSS_SCOPE_PLACEHOLDER = '#componentId';
    private const GENERIC_CSS_CLASS_TOKENS = [
        'card', 'title', 'header', 'footer', 'content', 'wrapper', 'container',
        'item', 'list', 'row', 'col', 'box', 'panel', 'section', 'main',
        'nav', 'menu', 'btn', 'button', 'link', 'text', 'icon', 'image',
        'form', 'input', 'label', 'group', 'active', 'disabled', 'hidden',
        'show', 'hide', 'open', 'close', 'toggle', 'dropdown', 'modal',
    ];

    private ?\GuoLaiRen\PageBuilder\Service\AI\Contract\AiSiteVisualBlockContractRenderer $visualBlockContractRenderer = null;

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
        $cacheKey = \md5((string)\json_encode([
            'region' => $region,
            'site' => $siteDisplayName,
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
        $scope = $this->normalizeStageTwoBuildScope($scope, $websiteProfile);
        $blueprint = $this->getPageBlueprintService()->buildPageBlueprint($pageType, $scope, $websiteProfile);
        $blueprint = $this->mergeBuildTaskSectionsIntoBlueprint($pageType, $blueprint, $scope);
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
     * Stage2 prompt assembly must always read the latest frozen task tree.
     * Preview/build entrypoints can load an older persisted scope, so normalize
     * it here before any section prompt resolves build_plan_v2 task context.
     *
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $websiteProfile
     * @return array<string,mixed>
     */
    private function normalizeStageTwoBuildScope(array $scope, array $websiteProfile): array
    {
        $scope = $this->getScopeCompatibilityService()->normalizeScope($scope);
        if (!\is_array($scope['build_plan_v2'] ?? null)) {
            return $scope;
        }

        $workspaceTrack = $this->getScopeCompatibilityService()->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));

        return $this->getBuildTaskService()->ensureTaskScope(
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
        $identity = \strtolower($pageType . ' ' . $sectionCode . ' ' . $sectionKey . ' ' . $sectionTemplate . ' ' . (string)($section['name'] ?? ''));
        $isHero = \preg_match('/\b(hero|banner|cover|opening|above[-_ ]?fold)\b/i', $identity) === 1;
        $wantsImage = $isHero
            || \preg_match('/\b(showcase|feature|product|game|suite|download|cta|conversion|spotlight|trust|security|safe|badge|proof|testimonial|review|story|faq)\b/i', $identity) === 1;
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
        $manifestSlotType = \trim((string)($manifestSlot['slot_type'] ?? ''));
        $manifestFinalUrl = !$this->isManifestSlotPlaceholder($manifestSlot)
            ? \trim((string)($manifestSlot['final_url'] ?? ''))
            : '';
        $finalUrl = $manifestSlot !== [] ? $manifestFinalUrl : $this->resolveSectionAssetUrl($pageType, $section, $scope);
        $hasVerifiedImage = $finalUrl !== '';
        $wantsImage = $wantsImage || $manifestDesired || $manifestRequired;
        $requiresImage = $manifestRequired || $hasVerifiedImage;
        $usage = $isHero ? 'section_background_cover' : ($wantsImage ? 'section_media_surface' : 'optional_css_visual');
        $style = $isHero
            ? 'premium cinematic website banner, realistic/editorial photography or photoreal premium 3D, 1920x750 wide full-bleed cover background, not cartoon or SVG-like'
            : 'premium brand website media, realistic/editorial or high-end commercial art, not clip-art, not cartoon, not placeholder';
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
            'slot_id' => $slotId,
            'slot_type' => $manifestSlotType !== '' ? $manifestSlotType : ($isHero ? 'hero_image' : 'section_image'),
            'page_type' => $pageType,
            'section_code' => $sectionCode,
            'section_key' => $sectionKey,
            'section_template' => $sectionTemplate,
            'usage' => $usage,
            'placement' => $isHero ? 'background_layer' : ($requiresImage ? 'media_panel' : 'none'),
            'aspect_ratio' => $isHero ? '1920:750' : '4:3',
            'target_size' => $isHero ? '1920x750' : '',
            'subject' => $this->clipText($this->sanitizeVisibleCopy($brief), 220),
            'style' => $style,
            'responsive_layout_contract' => [
                'desktop' => 'Use a centered safe-width inner container. Split layouts are allowed only when every grid/flex child has min-width:0 and no panel exceeds the container.',
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
                ? ($isHero
                    ? 'Render the concrete final_url from this contract as a real editable <img> cover layer inside html_content for the full-width 1920x750-style banner. The same <img> carries data-pb-ai-image-role and the concrete data-pb-ai-asset-slot from this contract.'
                    : 'Render the concrete final_url from this contract as a real editable <img> media surface inside html_content. The same <img> carries data-pb-ai-image-role and the concrete data-pb-ai-asset-slot from this contract.')
                : ($requiresImage
                    ? 'This block has a mandatory generated image slot. Generate or reuse the real slot asset before final AI output; if no verified final_url/template is supplied later, fail this block instead of rendering CSS-only fake media.'
                    : ($wantsImage
                    ? 'This block benefits from visual media. If a verified final_url is supplied, render it as a real editable <img>; otherwise build a polished CSS-only media/motif surface and do not invent external image URLs.'
                    : 'No verified final_url is available for this visual. Build a polished CSS-only motif/media surface; never use svg, placeholder image URLs, or invented external image URLs.')),
        ];
        if ($finalUrl !== '') {
            $contract['final_url'] = $finalUrl;
        }
        if ($requiresImage && $hasVerifiedImage) {
            $contract['required_img_class'] = $isHero ? 'pb-c-hero-img' : 'pb-c-img';
            $contract['required_structure'] = $isHero
                ? 'Use pb-c-root > img.pb-c-hero-img + pb-c-scrim + pb-c-inner > pb-c-copy. The img selector must be #componentId .pb-c-hero-img and must contain position:absolute, inset:0, width:100%, height:100%, object-fit:cover, object-position:center.'
                : 'Use pb-c-root > pb-c-inner > pb-c-media > img.pb-c-img plus pb-c-copy. The img selector must be #componentId .pb-c-img and must contain width:100%, height:360px, object-fit:cover, object-position:center.';
        }

        return $contract;
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
     * 骞跺彂鐢熸垚澶氫釜缁勪欢锛坔eader + footer + 澶氫釜 section 鍙悓鏃惰繘琛岋級
     *
     * 浣跨敤 Fiber 瀹炵幇骞跺彂锛氭瘡涓粍浠跺湪鐙珛 Fiber 涓皟鐢?generateComponent()锛?     * 澶嶇敤瀹屾暣鐨?AI 璋冪敤 鈫?JSON 淇 鈫?璇硶鏍￠獙 鈫?鑷姩淇娴佺▼銆?     *
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
    private function mergeBuildTaskSectionsIntoBlueprint(string $pageType, array $blueprint, array $scope): array
    {
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $tasks = \is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [];
        $replaceBlueprintSections = (string)($buildBlueprint['source'] ?? '') === 'build_plan_v2';
        if ($tasks === []) {
            $tasks = $this->buildSectionTasksFromExecutionBlueprint($pageType, $scope);
            $replaceBlueprintSections = $tasks !== [];
        }
        if ($tasks === []) {
            return $blueprint;
        }

        $sections = $replaceBlueprintSections ? [] : \array_values(\array_filter(
            \is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : [],
            static fn($section): bool => \is_array($section)
        ));
        $known = [];
        foreach ($sections as $section) {
            $value = \trim((string)($section['code'] ?? ''));
            if ($value !== '') {
                $known[$value] = true;
            }
        }

        foreach ($tasks as $task) {
            if (!\is_array($task) || \trim((string)($task['page_type'] ?? '')) !== $pageType) {
                continue;
            }
            if (\trim((string)($task['task_type'] ?? '')) !== 'page_section') {
                continue;
            }

            $taskKey = \trim((string)($task['task_key'] ?? ''));
            $blockKey = $this->resolveBuildTaskBlockKey($task);
            $sectionCode = $this->normalizeBuildTaskSectionCode($pageType, (string)($task['section_code'] ?? ''), $blockKey, $taskKey);
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
            $description = $this->resolveVisibleBuildTaskSummary($taskScript, $blockTask, $planContext);
            $visibleSectionTitle = $this->sanitizeVisibleCopy($label);
            if ($visibleSectionTitle === '') {
                $visibleSectionTitle = $this->humanizeIdentifier($sectionKey !== '' ? $sectionKey : $sectionCode);
            }

            $sections[] = [
                'key' => $sectionKey,
                'code' => $sectionCode,
                'name' => $visibleSectionTitle !== '' ? $visibleSectionTitle : $sectionCode,
                'template' => $this->inferBuildTaskSectionTemplate($task, $sectionKey, \count($sections)),
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
     * @return list<array<string,mixed>>
     */
    private function buildSectionTasksFromExecutionBlueprint(string $pageType, array $scope): array
    {
        $page = $this->resolveExecutionBlueprintPagePlan($scope, $pageType);
        $blocks = $this->resolveExecutionBlueprintBlocksForPage($scope, $pageType);
        if ($blocks === []) {
            return [];
        }

        $tasks = [];
        $sharedPromptContext = $this->normalizeSharedPromptContextFromExecutionBlueprint($scope);
        $themeContext = $this->resolveExecutionBlueprintThemeContext($scope);
        $contentLocale = $this->resolveScopePrimaryLocale($scope);
        $pageDesignPlan = \is_array($page['page_design_plan'] ?? null) ? $page['page_design_plan'] : [];
        foreach (\array_values($blocks) as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockKey = \trim((string)($block['block_key'] ?? ''));
            if ($blockKey === '') {
                $blockKey = 'block_' . ($index + 1);
            }
            $locale = $this->resolveScopePrimaryLocale($scope);
            $fieldPlan = $this->sanitizeExecutionBlueprintFieldPlanForGeneration(
                \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [],
                $locale
            );
            $label = $this->pickExecutionBlueprintBlockLabel($block, $fieldPlan, $blockKey);
            $description = $this->pickExecutionBlueprintBlockDescription($block, $fieldPlan);
            $sectionCode = 'content/' . $this->slugForGeneratedSectionCode($pageType) . '-' . $this->slugForGeneratedSectionCode($blockKey);
            $contentRows = $this->buildExecutionBlueprintContentCopyRows($label, $description, $fieldPlan);
            $realtimeContent = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
            $stylePlan = \is_array($block['design_tags'] ?? null) ? $block['design_tags'] : [];
            if ($pageDesignPlan !== []) {
                $stylePlan['page_design_plan'] = $pageDesignPlan;
            }
            $tasks[] = [
                'task_key' => 'page:' . $pageType . ':' . $blockKey,
                'task_type' => 'page_section',
                'page_type' => $pageType,
                'section_key' => $blockKey,
                'block_key' => $blockKey,
                'section_code' => $sectionCode,
                'label' => $label,
                'sort_order' => 100 + ($index * 10),
                'plan_context' => [
                    'block_goal' => (string)($block['goal'] ?? ''),
                    'field_plan' => $fieldPlan,
                    'page_design_plan' => $pageDesignPlan,
                    'source_page_type' => $pageType,
                    'source_block_key' => $blockKey,
                    'page_flow_role' => (string)($block['page_flow_role'] ?? ''),
                ],
                'block_task' => [
                    'task_goal' => (string)($block['goal'] ?? ''),
                    'content_plan' => [
                        'title' => $label,
                        'body_copy' => \array_values(\array_map(
                            static fn(array $row): string => (string)($row['copy'] ?? ''),
                            \array_filter($contentRows, static fn(array $row): bool => \trim((string)($row['copy'] ?? '')) !== '')
                        )),
                        'content_copy' => $contentRows,
                    ],
                    'style_plan' => $stylePlan,
                    'realtime_content' => $realtimeContent,
                    'meta_fields' => $fieldPlan,
                ],
                'task_script' => [
                    'story_goal' => (string)($block['goal'] ?? ''),
                    'content_fill_rule' => 'Write finished visitor-facing copy from the approved page plan fields; never render planning observations or block identifiers.',
                    'field_content_requirements' => $fieldPlan,
                ],
                'runtime_context' => [
                    'theme_context_snapshot' => $themeContext,
                    'shared_prompt_context' => $sharedPromptContext,
                    'content_locale' => $contentLocale,
                ],
            ];
        }

        return $tasks;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveExecutionBlueprintPagePlan(array $scope, string $pageType): array
    {
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $pagePlans = \is_array($executionBlueprint['page_plans'] ?? null) ? $executionBlueprint['page_plans'] : [];
        if (\is_array($pagePlans[$pageType] ?? null)) {
            return $pagePlans[$pageType];
        }

        $pages = \is_array($executionBlueprint['pages'] ?? null) ? $executionBlueprint['pages'] : [];
        return \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function resolveExecutionBlueprintBlocksForPage(array $scope, string $pageType): array
    {
        $page = $this->resolveExecutionBlueprintPagePlan($scope, $pageType);
        foreach (['blocks', 'display_blocks'] as $field) {
            $blocks = \is_array($page[$field] ?? null) ? $page[$field] : [];
            if ($blocks !== []) {
                return \array_values(\array_filter($blocks, static fn($block): bool => \is_array($block)));
            }
        }

        return [];
    }

    /**
     * @param list<array<string,mixed>> $fieldPlan
     */
    private function pickExecutionBlueprintBlockLabel(array $block, array $fieldPlan, string $fallback): string
    {
        foreach ($fieldPlan as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $fieldName = \strtolower(\trim((string)($field['field'] ?? '')));
            if (!\preg_match('/headline|title|section_title|cta_label/', $fieldName)) {
                continue;
            }
            $sample = $this->sanitizeVisibleCopy((string)($field['sample'] ?? $field['copy'] ?? ''));
            if ($sample !== '') {
                return $this->clipText($sample, 72);
            }
        }

        return $this->clipText($this->sanitizeVisibleCopy($this->pickString(
            $block['goal'] ?? null,
            $block['content'] ?? null,
            $fallback
        )), 72);
    }

    /**
     * @param list<array<string,mixed>> $fieldPlan
     */
    private function pickExecutionBlueprintBlockDescription(array $block, array $fieldPlan): string
    {
        foreach ($fieldPlan as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $fieldName = \strtolower(\trim((string)($field['field'] ?? '')));
            if (!\preg_match('/subheadline|description|intro|summary|content|body/', $fieldName)) {
                continue;
            }
            $sample = $this->sanitizeVisibleCopy((string)($field['sample'] ?? $field['copy'] ?? ''));
            if ($sample !== '') {
                return $this->clipText($sample, 180);
            }
        }

        return $this->clipText($this->sanitizeVisibleCopy((string)($block['content'] ?? $block['goal'] ?? '')), 180);
    }

    /**
     * @param list<array<string,mixed>> $fieldPlan
     * @return list<array<string,string>>
     */
    private function buildExecutionBlueprintContentCopyRows(string $label, string $description, array $fieldPlan): array
    {
        $rows = [];
        foreach ([
            ['field' => 'section_intro', 'copy' => $description],
        ] as $row) {
            if (\trim((string)$row['copy']) !== '') {
                $rows[] = $row;
            }
        }
        foreach ($fieldPlan as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $fieldName = \trim((string)($field['field'] ?? ''));
            $sample = $this->sanitizeVisibleCopy((string)($field['sample'] ?? $field['copy'] ?? ''));
            if ($fieldName !== '' && $sample !== '') {
                $rows[] = ['field' => $fieldName, 'copy' => $sample];
            }
            if (\count($rows) >= 8) {
                break;
            }
        }

        return $this->dedupeContentCopyRows($rows);
    }

    /**
     * Execution-blueprint blocks may still contain planning-only fields from
     * older AI outputs. Generation prompts should receive content examples, not
     * reasons, implementation prose, or asset filenames that can leak to pages.
     *
     * @param list<array<string,mixed>> $fieldPlan
     * @return list<array<string,string>>
     */
    private function sanitizeExecutionBlueprintFieldPlanForGeneration(array $fieldPlan, string $locale): array
    {
        $clean = [];
        foreach ($fieldPlan as $field) {
            if (!\is_array($field)) {
                continue;
            }

            $fieldName = \trim((string)($field['field'] ?? ''));
            if ($fieldName === '') {
                continue;
            }

            $sample = '';
            foreach (['sample', 'copy', 'default', 'value'] as $sourceKey) {
                $candidate = $this->sanitizeVisibleCopy((string)($field[$sourceKey] ?? ''));
                if ($locale !== '') {
                    $candidate = $this->filterVisibleCopyForLocale($candidate, $locale);
                }
                if ($candidate !== '') {
                    $sample = $candidate;
                    break;
                }
            }

            if ($sample === '') {
                continue;
            }

            $clean[] = [
                'field' => $fieldName,
                'sample' => $this->clipText($sample, 180),
            ];
        }

        return $clean;
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
    private function resolveBuildTaskBlockKey(array $task): string
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
        $manifestLogo = $this->resolveHeaderAssetUrl($scope);
        if ($manifestLogo !== '') {
            return $manifestLogo;
        }

        return $this->normalizePublishableLogoAssetUrl((string)($websiteProfile['logo'] ?? ''));
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

        return $value;
    }

    /**
     * @param array<string,mixed> $taskScript
     * @param array<string,mixed> $blockTask
     * @param array<string,mixed> $planContext
     */
    private function resolveVisibleBuildTaskSummary(array $taskScript, array $blockTask, array $planContext): string
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

    private function normalizeBuildTaskSectionCode(string $pageType, string $sectionCode, string $blockKey, string $taskKey): string
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
    private function inferBuildTaskSectionTemplate(array $task, string $sectionKey, int $sectionIndex): string
    {
        $needle = \strtolower($sectionKey . ' ' . (string)($task['label'] ?? '') . ' ' . (string)($task['task_key'] ?? ''));
        if ($sectionIndex === 0 || \str_contains($needle, 'hero') || \str_contains($needle, 'banner')) {
            return 'hero';
        }
        if (\str_contains($needle, 'cta') || \str_contains($needle, 'contact')) {
            return 'cta';
        }
        if (\str_contains($needle, 'grid') || \str_contains($needle, 'values') || \str_contains($needle, 'features')) {
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
     * @return \Generator yields
     *   [componentKey => ['status' => 'fulfilled', 'result' => array<string,mixed>]]
     *   or
     *   [componentKey => ['status' => 'rejected', 'error' => \Throwable]]
     */
    public function generateComponentEventsConcurrently(array $components): \Generator
    {
        if ($components === []) {
            return;
        }

        if ($this->isTestEnvironment() || !\class_exists(\Fiber::class)) {
            foreach ($components as $componentKey => $spec) {
                try {
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
            $tasks[$componentKey] = function () use ($spec): array {
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

        $runner = new FiberTaskRunner(defaultConcurrency: $this->resolveConcurrency(\count($tasks)));
        foreach ($runner->runEvents($tasks) as $componentKey => $event) {
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

        $generator = $renderContext['_inline_image_asset_generator'] ?? null;
        if (!\is_callable($generator)) {
            throw new \RuntimeException('Required inline image generator is missing for block image slot.');
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
            throw new \RuntimeException('Required inline image slot id is missing for block.');
        }

        $result = $this->generateInlineImageAssetWithRetries($generator, $slotId, $defaultConfig, $renderContext);
        if (!\is_array($result)) {
            throw new \RuntimeException('Required inline image generator returned an invalid result for ' . $slotId . '.');
        }

        $url = \trim((string)($result['final_url'] ?? $result['url'] ?? ''));
        if ($url === '') {
            throw new \RuntimeException('Required inline image generator returned no final URL for ' . $slotId . '.');
        }

        $alt = $this->firstConfigString($defaultConfig, ['visual.image_alt', 'content.heading', 'title', 'runtime.section_name']);
        if ($alt === '') {
            $alt = 'Generated section image';
        }

        $defaultConfig['visual.image_url'] = $url;
        $defaultConfig['visual.image_alt'] = $alt;
        $defaultConfig['image.url'] = $url;
        $defaultConfig['media.image_url'] = $url;
        $defaultConfig['runtime.section_image_url'] = $url;
        $defaultConfig['runtime.section_image_alt'] = $alt;
        $defaultConfig['runtime.section_image_slot_id'] = $slotId;

        $verifiedAssets = \is_array($renderContext['verified_assets'] ?? null) ? $renderContext['verified_assets'] : [];
        $verifiedAssets[$slotId] = $url;
        $renderContext['verified_assets'] = $verifiedAssets;
        $requiredAssets = \is_array($renderContext['_required_image_assets'] ?? null) ? $renderContext['_required_image_assets'] : [];
        $requiredAssets[$slotId] = $url;
        $renderContext['_required_image_assets'] = $requiredAssets;
        $renderContext['_visual_contract'] = \array_replace($visualContract, ['slot_id' => $slotId, 'final_url' => $url]);
        $renderContext['_inline_generated_asset'] = [
            'slot_id' => $slotId,
            'final_url' => $url,
        ];

        $spec['defaultConfig'] = $defaultConfig;
        $spec['renderContext'] = $renderContext;

        return $spec;
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

        return 2;
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
     * N 璺祦寮忚蛋缁熶竴 {@see w_query()}锛圓iQueryProvider::generateStreamBatch锛夛紝涓庣珯鍐呭叾瀹冩ā鍧?AI 鎺ュ叆涓€鑷达紱
     * 澶辫触鎴栬В鏋愬紓甯告椂瀵硅 key 鍥為€€鍒板崟璺?{@see generateComponent()}銆?     *
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
                $attemptPrompt = $this->appendComponentCssScopeInstruction(
                    (string)($spec['prompt'] ?? ''),
                    $componentCode,
                    \is_array($spec['renderContext'] ?? null) ? $spec['renderContext'] : []
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
                    \is_array($spec['defaultConfig'] ?? null) ? $spec['defaultConfig'] : [],
                    \is_array($spec['renderContext'] ?? null) ? $spec['renderContext'] : [],
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
     * No application-level cap: the caller decides the task set size.
     */
    /**
     * 闄愬埗鍑虹珯 HTTP 骞跺彂锛岄伩鍏嶄换鍔℃暟杈冨鏃跺 DeepSeek 绛?API 鍏ㄥ紑杩炴帴瀵艰嚧鎺掗槦/闄愰€燂紝
     * 闀挎椂闂存棤鍚炲悙瑙﹀彂 OpenAiProvider 涓?cURL LOW_SPEED 涓锛堛€孡ess than 1 bytes/sec鈥︺€嶏級銆?     *
     * 閰嶇疆锛歿@see Env::get()} 閿?`pagebuilder.ai_site.max_http_concurrency`锛堥粯璁?4锛屾湁鏁堣寖鍥?1-2锛夈€?     */
    private function resolveConcurrency(int $taskCount): int
    {
        $taskCount = \max(1, $taskCount);
        $maxConcurrent = (int) Env::get('pagebuilder.ai_site.max_http_concurrency', 4);
        $maxConcurrent = \max(1, \min(32, $maxConcurrent));

        return \min($taskCount, $maxConcurrent);
    }

    /**
     * 骞跺彂鐢熸垚 header + footer 鍏变韩缁勪欢
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
        $scope = $this->normalizeStageTwoBuildScope($scope, $websiteProfile);
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
     * 骞跺彂鐢熸垚涓€涓〉闈㈢殑鎵€鏈?section
     *
     * @return array{blueprint:array<string,mixed>, sections:list<array<string,mixed>>}
     */
    public function generatePageSectionsConcurrently(string $pageType, array $websiteProfile, array $scope): array
    {
        $blueprint = $this->getPageBlueprintService()->buildPageBlueprint($pageType, $scope, $websiteProfile);
        $blueprint = $this->mergeBuildTaskSectionsIntoBlueprint($pageType, $blueprint, $scope);
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

        // 鎸?sort_order 鎺掑簭
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
        $verifiedAssets = $this->extractVerifiedAssetUrls($renderContext);
        $aiData = $this->stripOptionalUnverifiedGeneratedImageReferences($aiData, $renderContext, $defaultConfig);
        $aiData = $this->enforceContractHeroImageUrlsInAiPayload($aiData, $region, $defaultConfig);
        $this->assertRequiredImageEditorBindingsInAiPayload($aiData, $region, $renderContext, $defaultConfig);
        $aiData = $this->enforceConfiguredCtaIntentInAiPayload($aiData, $defaultConfig, $componentCode);
        $this->assertSharedComponentCopyMatchesSourceIndustry($aiData, $region, $defaultConfig, $renderContext);
        $this->assertGeneratedImageSourcesUseVerifiedAssets($aiData, $renderContext, $defaultConfig);
        $this->assertRequiredImageAssetsUsed($aiData, $renderContext, $defaultConfig);
        // 寮哄埗濂戠害锛氭湁 verified_assets 鏃?HTML 蹇呴』寮曠敤鑷冲皯涓€涓湡瀹炲浘鐗?URL
        if ($verifiedAssets !== []) {
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
        $aiData = $this->ensureAiPayloadValid($aiData, $region, $componentCode, $verifiedAssets);
        $defaultConfig = $this->applyAiPayloadOwnershipToDefaultConfig($defaultConfig, $region, $aiData);
        $persistedConfig = $this->sanitizeGeneratedComponentDefaultConfig($defaultConfig, $region);

        $phtml = $this->getFrameworkBuilder()->buildComponent($region, $componentInfo, $aiData);
        if ((bool)RequestContext::get(self::REQUEST_KEY_FAST_BLOCK_ARTIFACT, false)) {
            $html = (string)($aiData['html_content'] ?? $aiData['html_extra'] ?? '');
            $this->assertRenderedHtmlPassesBuildQualityGate($componentCode, $html);

            return [
                'code' => $componentCode,
                'name' => $name,
                'region' => $region,
                'phtml' => $phtml,
                'html' => $html,
                'default_config' => $persistedConfig,
                'ai_data' => $aiData,
            ];
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
                    'hint' => '重写 JSON 字段，移除 PHP/template 片段、破损标签、未闭合引号或会生成非法 PHTML 的内容；不要依赖本地语法修补。',
                ]]
            );
        }

        $html = $this->renderTemplateToHtml($phtml, $persistedConfig, $renderContext);
        $this->assertNoBrokenGeneratedImageReferences($html, $verifiedAssets);
        $this->assertRenderedHtmlMatchesLocale($html, $renderContext);
        $this->assertRenderedHtmlPassesBuildQualityGate($componentCode, $html);

        return [
            'code' => $componentCode,
            'name' => $name,
            'region' => $region,
            'phtml' => $phtml,
            'html' => $html,
            'default_config' => $persistedConfig,
            'ai_data' => $aiData,
        ];
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function isHeroOrBannerImageContract(array $defaultConfig, array $renderContext = [], string $html = ''): bool
    {
        $template = \strtolower(\trim((string)($defaultConfig['runtime.section_template'] ?? '')));
        $visualContract = \is_array($renderContext['_visual_contract'] ?? null)
            ? $renderContext['_visual_contract']
            : $this->decodeRuntimeVisualContract($defaultConfig);
        $slotType = \strtolower(\trim((string)($visualContract['slot_type'] ?? '')));

        return \in_array($template, ['hero', 'banner'], true)
            || \str_contains($slotType, 'hero')
            || \str_contains($slotType, 'banner');
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
        $attemptPrompt = $this->appendComponentCssScopeInstruction($prompt, $componentCode, $renderContext);
        $isHeroComponent = \preg_match('/\b(hero|banner|cover|opening|above[-_ ]?fold)\b/i', $componentCode) === 1;
        $componentPrefix = $this->normalizeComponentCssPrefix($componentCode);
        $lastThrowable = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $promptForAttempt = $attemptPrompt;
            if ($attempt > 0) {
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
                if ($attempt < 2 && $this->shouldRetryAiComponentGeneration($throwable)) {
                    $this->emitComponentFallbackNotice($region, $componentCode, 'retrying contract-guided generation after: ' . $this->summarizeThrowable($throwable));
                    continue;
                }

                break;
            }
        }

        $finalReason = $this->summarizeThrowable($lastThrowable ?? new \RuntimeException('unknown component generation failure'));
        $this->emitComponentFallbackNotice($region, $componentCode, $finalReason);
        throw new \RuntimeException('AI component generation failed: ' . $finalReason, 0, $lastThrowable);
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
        $buildPlanTask = \is_array($renderContext['_build_plan_task'] ?? null) ? $renderContext['_build_plan_task'] : [];
        $themePalette = $this->resolveThemePaletteForContract($buildPlanTask, $scope);
        $artifacts = $this->buildRequiredImagePromptArtifacts($visualContract, $themePalette);
        $strictImageTail = $this->buildStrictRequiredImagePromptTail($componentCode, $renderContext);

        $skeletonOutline = (string)($artifacts['skeleton_outline'] ?? '');
        $cssStructuralHints = (string)($artifacts['css_structural_hints'] ?? '');
        $requiredImgTemplate = (string)($artifacts['img_template'] ?? '');
        $roleMap = \is_array($artifacts['palette_role_map'] ?? null) ? $artifacts['palette_role_map'] : [];

        // 当区块没有验证图片时，按主题色板组装通用结构提示（hero / 非 hero）。
        if ($skeletonOutline === '' || $cssStructuralHints === '') {
            $roleMap = $this->buildPaletteRoleMapFromThemePalette($themePalette, $isHeroComponent);
            if ($isHeroComponent) {
                $skeletonOutline = "section.{$componentPrefix}-root\n"
                    . "  div.{$componentPrefix}-scrim     <-- CSS-only 视觉层（pseudo-element / 渐变层 / 形状装饰）\n"
                    . "  div.{$componentPrefix}-inner\n"
                    . "    div.{$componentPrefix}-text-panel\n"
                    . "      h2.{$componentPrefix}-title\n"
                    . "      p.{$componentPrefix}-text\n"
                    . "      div.{$componentPrefix}-cta";
                $cssStructuralHints = "结构层 CSS 必填规则（颜色由 palette_role_map 决定）：\n"
                    . "  .{$componentPrefix}-root: position:relative; overflow:hidden; min-height:520px; padding:88px 24px; box-sizing:border-box; background= palette_role_map.surface 或 linear-gradient(palette_role_map.surface->palette_role_map.surface_alt)\n"
                    . "  .{$componentPrefix}-scrim: position:absolute; inset:0; background= linear-gradient(palette_role_map.scrim, transparent)\n"
                    . "  .{$componentPrefix}-inner: position:relative; z-index:1; max-width:1180px; margin:0 auto; display:flex; align-items:center; min-height:380px\n"
                    . "  .{$componentPrefix}-text-panel: max-width:620px; padding:32px; border-radius:24px; background= palette_role_map.copy_panel_bg; color= palette_role_map.copy_panel_text; box-shadow:0 28px 80px palette_role_map.shadow\n"
                    . "  .{$componentPrefix}-title: margin:0 0 16px; font-size: clamp(32px, 5vw, 52px); line-height:1.1; color= palette_role_map.copy_panel_text\n"
                    . "  .{$componentPrefix}-text: margin:0 0 22px; line-height:1.7; color= palette_role_map.muted_text\n"
                    . "  .{$componentPrefix}-cta: display:inline-flex; width:auto; max-width:280px; padding:12px 20px; border-radius:999px; background= palette_role_map.cta_bg; color= palette_role_map.cta_text; transition:transform .2s, box-shadow .2s\n"
                    . "  .{$componentPrefix}-cta:hover: transform:translateY(-2px); box-shadow:0 12px 24px palette_role_map.shadow";
            } else {
                $skeletonOutline = "section.{$componentPrefix}-root\n"
                    . "  div.{$componentPrefix}-inner\n"
                    . "    div.{$componentPrefix}-copy\n"
                    . "      h2.{$componentPrefix}-title\n"
                    . "      p.{$componentPrefix}-text\n"
                    . "      div.{$componentPrefix}-action\n"
                    . "        div.{$componentPrefix}-cta";
                $cssStructuralHints = "结构层 CSS 必填规则（颜色由 palette_role_map 决定）：\n"
                    . "  .{$componentPrefix}-root: position:relative; overflow:hidden; padding:72px 24px; box-sizing:border-box; background= palette_role_map.surface\n"
                    . "  .{$componentPrefix}-inner: max-width:1180px; margin:0 auto; display:flex; flex-wrap:wrap; gap:24px; align-items:center\n"
                    . "  .{$componentPrefix}-copy: flex:1 1 320px; min-width:0\n"
                    . "  .{$componentPrefix}-title: margin:0 0 16px; font-size: clamp(28px, 4vw, 42px); line-height:1.1; color= palette_role_map.text\n"
                    . "  .{$componentPrefix}-text: margin:0 0 22px; line-height:1.7; color= palette_role_map.muted_text\n"
                    . "  .{$componentPrefix}-cta: display:inline-flex; width:auto; max-width:280px; padding:12px 20px; border-radius:999px; background= palette_role_map.cta_bg; color= palette_role_map.cta_text; transition:transform .2s, box-shadow .2s\n"
                    . "  .{$componentPrefix}-cta:hover: transform:translateY(-2px); box-shadow:0 12px 24px palette_role_map.shadow";
            }
        }

        $roleMapLine = $roleMap === []
            ? ''
            : '{' . \implode(', ', \array_map(static fn(string $k, string $v): string => $k . '=' . $v, \array_keys($roleMap), \array_values($roleMap))) . '}';

        $findingsBlock = '';
        if ($failure instanceof \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException) {
            $rendered = $failure->renderFindingsForPrompt();
            if ($rendered !== '') {
                $findingsBlock = "- PREVIOUS_FINDINGS (HARD：本轮必须逐条修复以下违约项，不能仅重抄上一版输出):\n" . $rendered . "\n";
            }
        }

        return "STRICT PAGEBUILDER COMPONENT RECOVERY PROMPT (本次为修复重试，必须严格按 palette + 结构重写，禁止凭空发明颜色):\n"
            . "- Previous output failed validation: " . $this->summarizeThrowable($failure) . "\n"
            . $findingsBlock
            . "- Generate exactly one {$region} component for {$sectionName}.\n"
            . "- Site: {$siteTitle}. " . ($brief !== '' ? "Brief: {$brief}. " : '') . "Visitor copy locale: " . ($locale !== '' ? $locale : 'site default') . ".\n"
            . "- Output exactly one minified JSON object with these string keys only: extra_fields, php_variables, css_extra, css_responsive, html_content, js_content.\n"
            . "- Focus on structural correctness first: valid JSON, balanced HTML tags, balanced CSS braces/parentheses, readable visitor copy, and selectors scoped under #componentId.\n"
            . "- Keep the CTA and typography usable, but do not treat one exact font stack or one exact button shape as a hard failure condition.\n"
            . "- extra_fields, php_variables, and js_content must be empty strings; css_responsive 必须包含至少一段 `@media (max-width: 768px)` 与一段 `@media (max-width: 420px)` 完整规则。\n"
            . "- REQUIRED_ROLE_OUTLINE (required roles/classes, not a byte-for-byte skeleton; keep core roles and exact image binding, but allow refined scoped wrappers when they improve this block):\n{$skeletonOutline}\n"
            . ($requiredImgTemplate !== '' ? "- REQUIRED_HTML_IMG_TO_COPY_VERBATIM (HARD：粘贴到 .{$componentPrefix}-media 或骨架指定位置内，禁止修改 src/data 属性): {$requiredImgTemplate}\n" : '')
            . ($roleMapLine !== '' ? "- REQUIRED_PALETTE_ROLE_MAP (HARD：css_extra 颜色全部来自此字典，禁用所有非本字典色值): {$roleMapLine}\n" : "- 当前 scope 未提供 themePalette，请从 CTX_CONFIRMED_THEME.palette 中提取 hex token；禁止使用任何兜底色 (#111827 / #f59e0b / #f8fafc / #92400e / #cbd5e1 等历史模板色全部已被禁用)。\n")
            . "- REQUIRED_CSS_ROLE_CONTRACT (style required roles with palette role values; layout rhythm and composition remain design-owned by this block):\n{$cssStructuralHints}\n"
            . "- html_content must decode to real HTML tags and must not leak prompt/schema text into visitor-visible copy.\n"
            . "- Prefer {$componentPrefix}-* class names for generated structure, but keep the main goal on valid scoped markup rather than rigid naming ceremony.\n"
            . "- Do not include markdown, prose, comments, raw HTML outside JSON, or a second JSON object.\n"
            . "- 必须执行 13 项门禁自检：visual_depth 信号 ≥3（gradient/shadow/visual/layout/motion/surface），responsive_signals ≥4 且含 @media，可见文案使用 content_locale，颜色全部来自 REQUIRED_PALETTE_ROLE_MAP。\n"
            . $strictImageTail;
    }

    private function buildRoleSpecificRecoveryContract(string $componentCode, string $componentPrefix): string
    {
        $identity = \mb_strtolower($componentCode);
        if (
            \preg_match('/(?:contact[-_\/ ]*methods?|contact[-_\/ ]*channels?|support[-_\/ ]*channels?)/u', $identity) === 1
            && !\str_contains($identity, 'form')
            && !\str_contains($identity, 'faq')
        ) {
            return "- CONTACT_METHOD_REQUIRED_STRUCTURE (HARD): html_content must contain a visible channel hub, not only hero text or an image. Include at least two repeated channel rows/cards shaped like `<div class='{$componentPrefix}-channel'><span class='pb-c-label'>Email:</span><span class='pb-c-value'>support@example.com</span></div>`. Keep the exact `pb-c-label` and `pb-c-value` classes on the label/value spans; use real visitor contact facts from the plan. A verified image may be a background or side visual, but it cannot replace the channel rows.\n";
        }
        if (\preg_match('/(?:support[-_\/ ]*form|form[-_\/ ]*guidance|contact[-_\/ ]*form|message)/u', $identity) === 1) {
            return "- FORM_GUIDANCE_REQUIRED_STRUCTURE (HARD): html_content must contain `<form class='pb-c-form'>` with at least two `<label>` elements, at least two `<input>` or `<textarea>` fields, and one message/issue textarea. Do not output email/phone/address cards for this role.\n";
        }
        if (\str_contains($identity, 'faq')) {
            return "- FAQ_REQUIRED_STRUCTURE (HARD): html_content must contain `.pb-c-faq-list` with repeated `.pb-c-faq-item` groups; each group has `.pb-c-question` ending with `?` and a separate `<p class='pb-c-answer'>...</p>` answer. Do not output contact cards for FAQ.\n";
        }

        return '';
    }

    private function shouldRetryAiComponentGeneration(\Throwable $throwable): bool
    {
        // 强契约异常一律允许重试：findings 已经携带定点修复线索，
        // strict recovery prompt 会把它们逐条回灌给 AI。
        if ($throwable instanceof \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException) {
            return true;
        }

        $message = $this->collectThrowableMessages($throwable);
        foreach ([
            'valid component JSON payload',
            'HTML structure invalid',
            'HTML class scope contract failed',
            'CSS structure contract failed',
            'CSS class scope contract failed',
            'content hard policy failed',
            'content quality failed',
            'visual contract failed',
            'hero image contract failed',
            'hero image cover CSS contract failed',
            'Required image slot is not referenced',
            'Required image slot is missing editor attributes',
            'Generated image source is outside verified asset allowlist',
            'render-data quality gate',
        ] as $needle) {
            if (\stripos($message, $needle) !== false) {
                return true;
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

        return $this->clipText($message, 220);
    }

    /**
     * 执行一个 assert 闭包，如果抛出 \Throwable，则把它转封成
     * AiSiteComponentContractException，并按 ruleId 记录一条 finding。
     * AiSiteComponentContractException 本身会原样透传（已带 findings）。
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
                    'expected' => '通过 ' . $ruleId . ' 强契约检查',
                    'hint' => '请根据当前提示中的 REQUIRED_IMAGE_STRUCTURE_CONTRACT / REQUIRED_CSS_ROLE_CONTRACT / REQUIRED_PALETTE_ROLE_MAP / V3 输出契约重写对应字段（html_content / css_extra / css_responsive）。',
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
        array $verifiedAssets = []
    ): array
    {
        $componentCode = $this->normalizeOptionalComponentCode($componentCode);
        $aiData = $this->applyStrictVirtualThemeComponentPolicy($aiData, $region, $componentCode, $verifiedAssets);
        $aiData = $this->normalizeVirtualThemeCssClassScope($aiData, $componentCode);

        $this->runAssertWithFindingCapture('assert.invalid_root_class', $componentCode, function () use ($aiData): void {
            $this->assertNoInvalidComponentRootClassSelectors($aiData);
        });
        $this->runAssertWithFindingCapture('assert.html_scope', $componentCode, function () use ($aiData, $region, $componentCode): void {
            $this->assertGeneratedComponentHtmlScopeContract($aiData, $region, $componentCode);
        });
        $this->runAssertWithFindingCapture('assert.css_contract', $componentCode, function () use ($aiData, $region, $componentCode): void {
            $this->assertGeneratedComponentCssContract($aiData, $region, $componentCode);
        });
        $this->runAssertWithFindingCapture('assert.hero_image_cover_css', $componentCode, function () use ($aiData, $region, $componentCode): void {
            $this->assertHeroGeneratedImageCoverCssContract($aiData, $region, $componentCode);
        });

        $validation = $this->getCodeValidator()->validateAiData($aiData, $region);
        if (!empty($validation['valid'])) {
            return $aiData;
        }

        $errors = \array_values(\array_filter(\array_map('strval', $validation['errors'] ?? [])));
        $message = \implode('; ', \array_slice($errors, 0, 5));

        // P1-E：把 validator 的错误列表整体回灌到契约异常的 findings 中，让
        // strict recovery prompt 可以逐条引用，而不是仅取 throwable.message 的
        // 前 220 字符。
        $findings = [];
        foreach (\array_slice($errors, 0, 10) as $error) {
            $findings[] = [
                'rule' => 'ai_payload.validator',
                'field' => $region,
                'found' => $error,
                'expected' => 'CodeValidator::validateAiData passes',
                'hint' => '请按当前提示中的 schema 与门禁清单重写组件 JSON，逐条修复上述校验错误。',
            ];
        }

        throw new \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException(
            'AI component JSON schema validation failed: ' . ($message !== '' ? $message : 'unknown validation error'),
            $findings
        );
    }

    /**
     * @param array<string,mixed> $aiData
     * @return array<string,mixed>
     */
    private function applyStrictVirtualThemeComponentPolicy(
        array $aiData,
        string $region,
        string $componentCode = '',
        array $verifiedAssets = []
    ): array
    {
        $aiData['extra_fields'] = '';
        $aiData['php_variables'] = '';
        if (\in_array($region, ['header', 'footer'], true)) {
            $aiData['css_extra'] = '';
            $aiData['css_responsive'] = '';
            $aiData['css_content'] = '';
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
                $this->resolveVirtualThemeCssValidationLimit($region, $cssKey)
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
            $hardPolicyReason = $this->detectHardGeneratedSectionHtmlPolicyViolation((string)($aiData['html_content'] ?? ''));
            if ($hardPolicyReason !== null) {
                throw new \RuntimeException('AI component content hard policy failed: ' . $hardPolicyReason);
            }
        }

        if (self::ENFORCE_COMPONENT_QUALITY_VALIDATION && $region === 'content') {
            // Visitor-copy quality rules must inspect visitor HTML only. CSS tokens
            // such as 100vh or 32px are valid styling and are checked separately.
            $lowQualityInspectionSource = (string)($aiData['html_content'] ?? '');
            $lowQualityCssSource = (string)($aiData['css_extra'] ?? '')
                . "\n" . (string)($aiData['css_responsive'] ?? '')
                . "\n" . (string)($aiData['css_content'] ?? '');
            $lowQualityReason = $this->detectLowQualityGeneratedSectionHtmlReason(
                $lowQualityInspectionSource,
                $componentCode,
                $lowQualityCssSource
            );
            if ($lowQualityReason !== null) {
                throw new \RuntimeException('AI component content quality failed: ' . $lowQualityReason);
            }
        }

        return $aiData;
    }

    private function resolveVirtualThemeCssValidationLimit(string $region, string $cssKey): int
    {
        if ($cssKey === 'css_responsive') {
            return 1200;
        }

        return $region === 'content' ? 6000 : 2000;
    }

    private function normalizeVirtualThemeCssForValidation(string $css, int $limit): string
    {
        $css = \trim($css);
        if ($css === '') {
            return '';
        }

        if (!\str_contains($css, '{')) {
            throw new \RuntimeException('AI CSS structure contract failed: CSS fields must contain complete scoped selector blocks, not loose declarations.');
        }

        $css = $this->repairCssDeclarationSyntaxForValidation($css);
        $css = $this->repairCssParenthesesOutsideStrings($css);
        $css = $this->balanceCssBraces($css);

        $structuralReason = $this->detectCssStructuralBalanceReason($css);
        if ($structuralReason !== null) {
            throw new \RuntimeException('AI CSS structure contract failed: ' . $structuralReason);
        }

        $css = $this->normalizeVirtualThemeCssComponentScope($css);
        if ($css === '') {
            return '';
        }

        $length = \function_exists('mb_strlen') ? \mb_strlen($css, 'UTF-8') : \strlen($css);
        if ($length > $limit) {
            throw new \RuntimeException('AI CSS structure contract failed: CSS output exceeds the allowed contract size for this field.');
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

        $length = \function_exists('mb_strlen') ? \mb_strlen($css) : \strlen($css);
        if ($length <= $limit) {
            return $css;
        }

        $slice = \function_exists('mb_substr')
            ? \mb_substr($css, 0, \max(1, $limit))
            : \substr($css, 0, \max(1, $limit));
        $lastClose = \strrpos($slice, '}');
        if ($lastClose === false) {
            return \trim($this->balanceCssBraces($slice));
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
        $css = \preg_replace('/#\s*<\?=\s*\$componentId\s*\?>/i', self::COMPONENT_CSS_SCOPE_PLACEHOLDER, $css) ?? $css;
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

        if (\preg_match('/\sid\s*=\s*(["\'])(?:#?componentId|#\s*<\?=\s*\$componentId\s*\?>)\1/iu', $html) === 1) {
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
    private function assertHeroGeneratedImageCoverCssContract(array $aiData, string $region, string $componentCode): void
    {
        if ($region !== 'content') {
            return;
        }

        $html = (string)($aiData['html_content'] ?? '');
        if ($html === '' || \preg_match('/\b(hero|banner|cover|opening|above[-_ ]?fold)\b/i', $componentCode) !== 1) {
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

    private function appendComponentCssScopeInstruction(string $prompt, string $componentCode, array $renderContext = []): string
    {
        $prefix = $this->normalizeComponentCssPrefix($componentCode);
        $isHero = \preg_match('/\b(hero|banner|cover|opening|above[-_ ]?fold)\b/i', $componentCode) === 1;
        $isContactSupportMode = !$isHero
            && \preg_match('/\b(contact|support|help|form|inquiry|enquiry|faq)\b/i', $componentCode) === 1;
        $isFeatureSafeMode = !$isHero
            && !$isContactSupportMode
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
        $featureCssBaseline = "#componentId .{$prefix}-root{padding:64px 24px;background:{$surfaceBg};color:{$textColor};box-sizing:border-box;}#componentId .{$prefix}-inner{max-width:1180px;width:100%;margin:0 auto;}#componentId .{$prefix}-title{margin:0;font-size:42px;line-height:1.1;color:{$textColor};}#componentId .{$prefix}-cta{display:inline-flex;width:auto;max-width:280px;box-sizing:border-box;border-radius:999px;padding:12px 20px;background:{$accentColor};color:{$textColor};}";
        $contactCssBaseline = "#componentId .{$prefix}-root{position:relative;overflow:hidden;padding:72px 24px;background:{$surfaceBg};color:{$textColor};box-sizing:border-box;}#componentId .{$prefix}-inner{max-width:1180px;width:100%;margin:0 auto;display:flex;flex-wrap:wrap;gap:24px;align-items:stretch;}#componentId .{$prefix}-copy{flex:1 1 340px;min-width:0;padding:28px;border:1px solid {$accentColor};border-radius:24px;background:{$cardBg};box-shadow:0 20px 52px {$shadowColor};}#componentId .{$prefix}-title{margin:0 0 14px;font-size:40px;line-height:1.18;color:{$primaryColor};}#componentId .{$prefix}-text{margin:0;font-size:16px;line-height:1.75;color:{$textColor};max-width:68ch;}#componentId .{$prefix}-cards{flex:1 1 460px;min-width:0;display:flex;flex-wrap:wrap;gap:16px;}#componentId .{$prefix}-card{flex:1 1 180px;min-width:0;padding:20px;border:1px solid {$secondaryColor};border-radius:20px;background:{$cardBg};color:{$textColor};box-shadow:0 14px 36px {$shadowColor};line-height:1.55;}#componentId .{$prefix}-action{width:100%;display:flex;flex-wrap:wrap;gap:12px;align-items:center;}#componentId .{$prefix}-cta{display:inline-flex;width:auto;max-width:280px;box-sizing:border-box;border-radius:999px;padding:12px 22px;background:{$accentColor};color:{$textColor};font-weight:700;}#componentId .{$prefix}-note{display:block;max-width:56ch;color:{$textColor};line-height:1.6;}";
        $featureSafeModeContract = $isFeatureSafeMode
            ? ($hasRequiredGeneratedImage
                ? "- THIS TASK IS FEATURE_ROLE_MODE WITH A REQUIRED GENERATED IMAGE. Ignore FEATURE_CSS_BASELINE and generic feature/card recipes. Follow REQUIRED_IMAGE_STRUCTURE_CONTRACT plus REQUIRED_CSS_ROLE_CONTRACT; they already define the required editable <img> binding while leaving composition and visual rhythm design-owned.\n"
                : "- THIS TASK IS FEATURE_ROLE_MODE. If the prompt provides a verified final_url or REQUIRED_HTML_IMG_TO_COPY_VERBATIM, use MEDIA_ROLE_OUTLINE plus MEDIA_CSS_BASELINE and paste the real <img>. If no verified image is supplied, use FEATURE_CSS_BASELINE as a reference for the required roles. Keep the block focused and valid; do not create card grids, carousels, accordions, hover selectors, @media blocks, or extra braces unless the current block contract explicitly requires them.\n")
            : '';
        $contactSupportModeContract = $isContactSupportMode
            ? ($hasRequiredGeneratedImage
                ? "- THIS TASK IS CONTACT_SUPPORT_MODE WITH A REQUIRED GENERATED IMAGE. Do not use the generic contact outline as a fixed skeleton. Follow REQUIRED_IMAGE_STRUCTURE_CONTRACT plus REQUIRED_CSS_ROLE_CONTRACT. If the required role outline includes {$prefix}-cards, keep card-style channel grouping and put one contact/support/FAQ channel into each {$prefix}-card; never compress channels into the paragraph.\n"
                : "- THIS TASK IS CONTACT_SUPPORT_MODE. Use CONTACT_ROLE_OUTLINE plus CONTACT_CSS_BASELINE as the required role baseline. Split email, phone, office, hours, FAQ, support, or sales details into separate {$prefix}-card items. Never compress multiple contact channels into one long paragraph.\n")
            : '';
        $safeCssBraceCountRule = $isFeatureSafeMode
            ? ($hasRequiredGeneratedImage
                ? "For required-image tasks, selector coverage is defined by REQUIRED_CSS_ROLE_CONTRACT and the actual role outline; do not apply FEATURE_CSS_BASELINE selector counts."
                : "For this FEATURE_ROLE_MODE task, css_extra must cover root, inner, title, and cta role selectors; additional scoped selectors are allowed only when required by the block intent.")
            : ($isContactSupportMode
                ? ($hasRequiredGeneratedImage
                    ? "For required-image tasks, selector coverage is defined by REQUIRED_CSS_ROLE_CONTRACT and the actual role outline; do not apply CONTACT_CSS_BASELINE selector counts."
                    : "For this CONTACT_SUPPORT_MODE task, css_extra must cover root, inner, copy, title, text, cards, card, action, cta, and note role selectors; additional scoped selectors are allowed only when required by the block intent.")
                : "For TEXT_CSS_BASELINE cover root, inner, copy, title, text, action, and cta role selectors.");
        $heroContract = $isHero
            ? "- HERO_ROLE_OUTLINE: every hero/banner needs one root, media layer, motif/orbit/depth layers, overlay/scrim, inner container, text panel, title, body copy, and CTA role. This outline is not a byte-for-byte skeleton; choose composition details from the current block intent while keeping these roles valid: `<section class='{$prefix}-root'><div class='{$prefix}-media'><div class='{$prefix}-media-stage'><div class='{$prefix}-media-subject'></div><div class='{$prefix}-media-detail'></div><div class='{$prefix}-media-label'>Finished visual cue</div></div></div><div class='{$prefix}-motif'></div><div class='{$prefix}-orbit'></div><div class='{$prefix}-overlay'></div><div class='{$prefix}-inner'><div class='{$prefix}-text-panel'><h2 class='{$prefix}-title'>Finished heading</h2><p class='{$prefix}-text'>Finished copy.</p><div class='{$prefix}-cta'>Finished CTA</div></div></div></section>`. If a verified image template exists, use that single copied <img> as the media role and add class='{$prefix}-media'. Do not keep a second empty {$prefix}-media div after the image, because it can cover the real asset.\n"
                . "- HERO CSS REQUIRED SELECTORS: css_extra must include complete selectors for `#componentId .{$prefix}-root`, `#componentId .{$prefix}-media`, `#componentId .{$prefix}-media-stage`, `#componentId .{$prefix}-media-subject`, `#componentId .{$prefix}-media-detail`, `#componentId .{$prefix}-media-label`, `#componentId .{$prefix}-motif`, `#componentId .{$prefix}-orbit`, `#componentId .{$prefix}-overlay`, `#componentId .{$prefix}-inner`, `#componentId .{$prefix}-text-panel`, and `#componentId .{$prefix}-cta` when no verified image exists. CSS-only hero media must be visibly designed from THEME_ROLE_PALETTE as a brief-specific editorial/product stage, never a plain dark slab or unrelated stock-art substitute. The media-label text must be a short subject phrase from the approved brief, not a generic decoration label. The overlay selector sets position:absolute; inset:0; z-index:2; background:{$primaryColor}; opacity:.36;. The text-panel selector sets position:relative; z-index:3; max-width, padding, border-radius, readable color, and background:{$surfaceBg}. The CTA selector should look like a button, not a desktop bar: display:inline-flex; align-items:center; justify-content:center; width:auto; max-width around 280px; flex:0 0 auto; align-self:flex-start; min-height:48px; box-sizing:border-box; background and color with strong contrast; text-decoration:none.\n"
                . "- HERO_CSS_BASELINE: when no verified image is supplied, css_extra must cover the HERO_ROLE_OUTLINE selectors and may use this editorial baseline as a reference, not a byte-for-byte CSS template: `#componentId .{$prefix}-root{position:relative;overflow:hidden;padding:112px 24px;background:{$rootBg};color:{$inverseText};box-sizing:border-box;}#componentId .{$prefix}-media{position:absolute;inset:0;z-index:0;background:{$mediaBg};}#componentId .{$prefix}-media-stage{position:absolute;inset:12% 7% auto auto;width:430px;height:350px;z-index:1;border:1px solid {$accentColor};border-radius:44px;background:{$secondaryColor};box-shadow:0 28px 90px {$shadowColor};opacity:.72;}#componentId .{$prefix}-media-subject{position:absolute;inset:34% 16% auto auto;width:260px;height:138px;z-index:2;border:1px solid {$accentColor};border-radius:999px;background:{$primaryColor};box-shadow:0 18px 54px {$secondaryColor};opacity:.88;}#componentId .{$prefix}-media-detail{position:absolute;inset:16% 24% auto auto;width:16px;height:250px;z-index:2;border-radius:999px;background:{$accentColor};box-shadow:0 12px 34px {$secondaryColor};opacity:.76;}#componentId .{$prefix}-media-label{position:absolute;inset:auto 10% 12% auto;z-index:3;display:inline-flex;width:auto;max-width:240px;box-sizing:border-box;padding:10px 16px;border:1px solid {$accentColor};border-radius:999px;background:{$surfaceBg};color:{$textColor};font-size:14px;font-weight:700;line-height:1.2;box-shadow:0 12px 32px {$shadowColor};}#componentId .{$prefix}-motif{position:absolute;inset:8% 6% auto auto;width:500px;height:420px;z-index:1;border:1px solid {$accentColor};border-radius:46px;background:{$secondaryColor};box-shadow:0 26px 90px {$shadowColor};opacity:.28;}#componentId .{$prefix}-orbit{position:absolute;inset:18% 12% auto auto;width:240px;height:320px;z-index:1;border:1px solid {$accentColor};border-radius:28px;background:{$primaryColor};box-shadow:0 18px 54px {$secondaryColor};opacity:.24;}#componentId .{$prefix}-overlay{position:absolute;inset:0;z-index:2;background:{$primaryColor};opacity:.36;}#componentId .{$prefix}-inner{position:relative;z-index:3;max-width:1180px;width:100%;margin:0 auto;display:flex;flex-wrap:wrap;gap:24px;align-items:center;}#componentId .{$prefix}-text-panel{flex:1 1 480px;min-width:0;max-width:640px;padding:48px 40px;border-radius:20px;background:{$surfaceBg};color:{$textColor};box-shadow:0 18px 56px {$shadowColor};border:1px solid {$accentColor};}#componentId .{$prefix}-title{margin:0 0 16px;font-size:48px;line-height:1.1;color:{$primaryColor};}#componentId .{$prefix}-text{margin:0 0 24px;font-size:18px;line-height:1.7;color:{$textColor};}#componentId .{$prefix}-cta{display:inline-flex;width:auto;max-width:280px;box-sizing:border-box;border-radius:999px;padding:16px 32px;background:{$accentColor};color:{$textColor};font-size:18px;font-weight:700;cursor:pointer;border:1px solid {$secondaryColor};box-shadow:0 4px 16px {$secondaryColor};}`. Keep the required selectors and palette roles, but adapt spacing, proportions, and surface rhythm to the approved business brief.\n"
            : '';

        return \rtrim($prompt)
            . "\n\nComponent-specific strong contract:\n"
            . "- EXACT_CLASS_PREFIX = `{$prefix}`. Every class you create must begin with this exact prefix followed by one hyphen and an element name.\n"
            . "- This prefix is intentionally minimal. Copy `{$prefix}` exactly; do not expand it with page names, block names, or task labels.\n"
            . "- The valid class prefix token is exactly `{$prefix}-`. A class named only `{$prefix}`, `pb`, `pb-`, `.pb`, `-title`, `-text`, `-card`, `-cta`, `pbtitle`, `pbabout...`, `pbhome...`, `-x...`, or `-about...` is invalid. If you are about to write `#componentId .{$prefix}{...}`, `.-title`, or `.-cta`, write `#componentId .{$prefix}-root{...}`, `#componentId .{$prefix}-title{...}`, or `#componentId .{$prefix}-cta{...}` instead.\n"
            . "- Positive class examples for this component: `{$prefix}-root`, `{$prefix}-inner`, `{$prefix}-copy`, `{$prefix}-title`, `{$prefix}-card`, `{$prefix}-cta`.\n"
            . "- Positive CSS selector examples for this component: `#componentId .{$prefix}-root{...}` and `#componentId .{$prefix}-inner{...}`.\n"
            . "- THEME_ROLE_PALETTE: root_bg={$rootBg}; media_bg={$mediaBg}; surface_bg={$surfaceBg}; card_bg={$cardBg}; text={$textColor}; inverse_text={$inverseText}; primary={$primaryColor}; secondary={$secondaryColor}; accent={$accentColor}; shadow={$shadowColor}. Values that start with CTX_CONFIRMED_THEME are symbolic roles, not CSS literals; before output, replace each symbolic role with a real hex token from CTX_CONFIRMED_THEME.palette. Role baseline CSS below is a palette-bound reference; do not replace it with generic dark navy, purple, blue, casino, or app-download colors unless the approved stage-1 palette explicitly uses those colors.\n"
            . ($hasRequiredGeneratedImage ? "- REQUIRED IMAGE OVERRIDE: this block has a mandatory generated image slot. REQUIRED_IMAGE_STRUCTURE_CONTRACT and REQUIRED_CSS_ROLE_CONTRACT override generic role outlines, selector-count rules, and any broad layout recipe. Preserve the exact image binding and required roles, but choose the final composition from the current block intent instead of copying a fixed skeleton.\n" : '')
            . $featureSafeModeContract
            . $contactSupportModeContract
            . "- SAFE_TEXT_ROLE_OUTLINE: for every non-hero non-form content block without a verified image, include one root, inner, copy, title, text, action, and compact CTA role. This is the minimum role outline, not a byte-for-byte skeleton: `<section class='{$prefix}-root'><div class='{$prefix}-inner'><div class='{$prefix}-copy'><h2 class='{$prefix}-title'>Finished heading</h2><p class='{$prefix}-text'>Finished copy.</p></div><div class='{$prefix}-action'><div class='{$prefix}-cta'>Finished CTA</div></div></div></section>`.\n"
            . "- MEDIA_ROLE_OUTLINE: for every non-hero content block with a verified image template/final_url, include root, inner, media, copied image, copy, title, text, and compact CTA roles. Paste the real <img> after adding class='{$prefix}-img'. This is a role outline, not a fixed layout: `<section class='{$prefix}-root'><div class='{$prefix}-inner'><div class='{$prefix}-media'>PASTE_VERIFIED_IMG_HERE</div><div class='{$prefix}-copy'><h2 class='{$prefix}-title'>Finished heading</h2><p class='{$prefix}-text'>Finished copy.</p><div class='{$prefix}-cta'>Finished CTA</div></div></div></section>`.\n"
            . "- CONTACT_ROLE_OUTLINE: for contact/support/help/form blocks, include root, inner, copy, title, intro text, channel cards, action, compact CTA, and note roles. This is a role outline, not a fixed layout: `<section class='{$prefix}-root'><div class='{$prefix}-inner'><div class='{$prefix}-copy'><h2 class='{$prefix}-title'>Finished heading</h2><p class='{$prefix}-text'>Finished intro.</p></div><div class='{$prefix}-cards'><div class='{$prefix}-card'><strong>Channel</strong><span>Short detail.</span></div><div class='{$prefix}-card'><strong>Channel</strong><span>Short detail.</span></div><div class='{$prefix}-card'><strong>Channel</strong><span>Short detail.</span></div></div><div class='{$prefix}-action'><div class='{$prefix}-cta'>Finished CTA</div><small class='{$prefix}-note'>Short response-time or support note.</small></div></div></section>`.\n"
            . "- TEXT_CSS_BASELINE: include complete scoped selectors for the SAFE_TEXT_ROLE_OUTLINE roles and use this palette-filled baseline as a reference, not a byte-for-byte CSS template: `#componentId .{$prefix}-root{position:relative;overflow:hidden;padding:64px 24px;background:{$surfaceBg};color:{$textColor};box-sizing:border-box;}#componentId .{$prefix}-inner{max-width:1180px;width:100%;margin:0 auto;display:flex;flex-wrap:wrap;gap:24px;align-items:center;}#componentId .{$prefix}-copy{flex:1 1 320px;min-width:0;}#componentId .{$prefix}-title{margin:0;font-size:42px;line-height:1.1;color:{$primaryColor};}#componentId .{$prefix}-text{margin:0;line-height:1.7;color:{$textColor};}#componentId .{$prefix}-action{display:flex;width:100%;max-width:100%;box-sizing:border-box;}#componentId .{$prefix}-cta{display:inline-flex;width:auto;max-width:280px;box-sizing:border-box;border-radius:999px;padding:12px 20px;background:{$accentColor};color:{$textColor};}`.\n"
            . "- MEDIA_CSS_BASELINE: for verified-image non-hero blocks, include complete scoped selectors for MEDIA_ROLE_OUTLINE roles and use this palette-filled baseline as a reference, not a fixed CSS template: `#componentId .{$prefix}-root{position:relative;overflow:hidden;padding:72px 24px;background:{$surfaceBg};color:{$textColor};box-sizing:border-box;}#componentId .{$prefix}-inner{max-width:1180px;width:100%;margin:0 auto;display:flex;flex-wrap:wrap;gap:32px;align-items:center;}#componentId .{$prefix}-media{flex:1 1 360px;min-width:0;overflow:hidden;border-radius:28px;box-shadow:0 24px 64px {$shadowColor};}#componentId .{$prefix}-img{display:block;width:100%;height:360px;object-fit:cover;object-position:center;}#componentId .{$prefix}-copy{flex:1 1 320px;min-width:0;}#componentId .{$prefix}-title{margin:0;font-size:42px;line-height:1.1;color:{$primaryColor};}#componentId .{$prefix}-text{margin:0;line-height:1.7;color:{$textColor};}#componentId .{$prefix}-cta{display:inline-flex;width:auto;max-width:280px;box-sizing:border-box;border-radius:999px;padding:12px 20px;background:{$accentColor};color:{$textColor};}`.\n"
            . "- CONTACT_CSS_BASELINE: for contact/support/help/form blocks, include complete scoped selectors for CONTACT_ROLE_OUTLINE roles and use this palette-filled baseline as a reference, not a fixed CSS template: `{$contactCssBaseline}`.\n"
            . "- CTA FIT BUTTON CONTRACT: CTA-looking divs should look like intentional action buttons, not accidental page-width bars. Wrapper/row/band/group/container classes may be full-width and should use `{$prefix}-action` or `{$prefix}-actions` when practical; keep the actual CTA button compact in the default layout.\n"
            . "- CTA QUALITY CSS BASELINE: css_extra should include a CTA selector equivalent to `#componentId .{$prefix}-cta{display:inline-flex;width:auto;max-width:280px;flex:0 0 auto;align-self:flex-start;}`. css_responsive may adapt containers and form controls to full width; do not turn the desktop/default CTA button itself into a full-width bar.\n"
            . "- MARKUP STRICT MODE: when REQUIRED_IMAGE_STRUCTURE_CONTRACT is present, satisfy it instead of every other outline. Otherwise choose CONTACT_ROLE_OUTLINE for CONTACT_SUPPORT_MODE tasks; otherwise choose SAFE_TEXT_ROLE_OUTLINE, MEDIA_ROLE_OUTLINE, or HERO_ROLE_OUTLINE. In no-verified-image hero mode, media-stage/media-subject/media-detail/media-label plus motif/orbit layers are required by HERO_ROLE_OUTLINE; do not leave the media div empty and do not add other optional cards/forms unless the current task explicitly requires a real contact form.\n"
            . "- CONTACT COPY RHYTHM: contact/support cards use short labels plus short details. If a required-image contact/support skeleton includes `{$prefix}-cards`, keep at least two `{$prefix}-card` items and put one channel in each. A paragraph containing two or more of email, phone, address, business hours, sales, or support is invalid because it reads like raw data instead of a designed contact surface.\n"
            . "- Showcase/features/trust/reward/FAQ/newsletter/CTA tasks without a required image still use SAFE_TEXT_ROLE_OUTLINE. Compress multiple items into one polished paragraph separated by target-locale punctuation; do not create a card grid, list, table, carousel, accordion, or multiple repeated game cards unless the current block contract explicitly asks for that structure.\n"
            . "- FEATURE_CSS_BASELINE: use this shorter css_extra reference only when no REQUIRED_CSS_ROLE_CONTRACT exists: `{$featureCssBaseline}`. Keep required role selectors complete, but do not treat the example as an exact selector-count rule.\n"
            . "- Verified image priority: if the prompt includes REQUIRED_IMAGE_STRUCTURE_CONTRACT, preserve the copied image tag's src and slot attributes exactly. Do not reconstruct the image tag or URL. If REQUIRED_CSS_ROLE_CONTRACT is present, style those roles and do not write CSS url(...).\n"
            . "- JSON recovery rule: if any requested layout feels too complex for valid JSON, simplify the layout while preserving the required image binding, required roles, scoped classes, and visible copy quality. A small valid premium block is better than a broken multi-card block, but do not fall back to one universal skeleton for every design.\n"
            . "- JSON text safety: visible copy inside html_content must not contain double quote characters, backticks, code fences, raw `<` or `>`, or JSON braces. Rewrite quoted phrases into plain prose before output.\n"
            . "- Raw HTML leakage ban: never escape malformed tags into visible text. Strings such as `&lt;2 class='pb-c-title'&gt;`, `<2 class=...>`, `</pa>`, `</pdiv>`, or `class='...'` printed as text are hard failures; use a valid `<h2 class='{$prefix}-title'>...</h2>` element instead.\n"
            . "- HTML tag whitelist for role outlines: opening tags may only be section, div, h2, p, small, strong, span, img. Closing tags may only be </section>, </div>, </h2>, </p>, </small>, </strong>, </span>. Never output </>, </h>, </pa>, </pdiv>, <2>, <h>, or a numeric tag name. If a tag is uncertain, keep the role outline simple instead of improvising malformed markup.\n"
            . "- Use div/card groups instead of ul/ol/li list markup. Use exactly one h2 for the block title; do not use h3 or closing tags like </h>. Prefer p/small/div for labels. If span or strong is used, keep it plain text with no attributes and close it before any parent closes.\n"
            . "- Content blocks should not use `<a>` or `<button>` tags. Use `<div class='{$prefix}-cta'>` for CTA-looking controls unless the task explicitly provides a real href/form action. This prevents link/button nesting and merged closing-tag failures.\n"
            . "- If this component is a hero/banner and REQUIRED_IMAGE_STRUCTURE_CONTRACT is present, satisfy its required image role, scrim/veil, inner, copy, title, text, and cta roles without copying a byte-for-byte skeleton. If no required image structure is present, html_content must include `{$prefix}-media`, `{$prefix}-motif`, `{$prefix}-orbit`, `{$prefix}-overlay`, and `{$prefix}-text-panel` classes; without verified image, `{$prefix}-media` is a CSS-only container with `{$prefix}-media-stage`, `{$prefix}-media-subject`, `{$prefix}-media-detail`, and `{$prefix}-media-label` children. css_extra must include matching selectors for every required hero class.\n"
            . $heroContract
            . "- Use only complete CSS declarations. Each declaration has one property name, one value, and a semicolon. For non-hero blocks, keep css_extra to the minimal CSS shape above plus at most one card/form selector. If a decorative selector is uncertain, omit it instead of writing broken CSS.\n"
            . "- CSS declaration separator rule: every declaration must end with `;` before the next property. `padding:16px 32pxbackground:{$accentColor}` is invalid; write `padding:16px 32px;background:{$accentColor};` after replacing any symbolic palette role with a real confirmed hex token.\n"
            . "- CSS numeric value rule: opacity and numeric declarations must use complete values such as `.46`, `0.46`, `1`, `240px`, or `100%`; never output truncated values like `opacity:.`, `opacity:`, `width:-`, or `z-index:.`.\n"
            . "- CSS value precision rule: `box-sizing` must be exactly `border-box`; `box-sizing:border` is invalid CSS and will be rejected.\n"
            . "- CSS image URL ban: css_extra must not contain `url(` for PageBuilder AI-generated image assets. Use the verified <img> skeleton for images; use plain colors, borders, and shadows for surfaces.\n"
            . "- CSS function discipline: css_extra may use production-safe functions only when they are complete and valid, including clamp(), linear-gradient(), rgba(), and transform(). Do not use incomplete values, nested broken functions, placeholder values such as `...`, or CSS url(...) for generated image assets.\n"
            . "- CSS brace contract: css_extra starts with `#componentId .{$prefix}-root{` and ends with one `}`. It must not contain `}}`, `{{`, `@media`, comma selectors, nested selectors, comments, raw JSON braces, or a brace inside any CSS value. {$safeCssBraceCountRule}\n"
            . "- Never leave blank CSS values such as `background:;`, `color:;`, `border-color:;`, or `box-shadow:;`. If a value is uncertain, keep the value from the minimal CSS shape or use a confirmed theme color.\n"
            . "- CSS property whitelist: use only position, inset, overflow, padding, margin, max-width, width, min-width, height, display, flex, flex-wrap, gap, align-items, background, color, border, border-radius, box-shadow, font-size, line-height, z-index, opacity, object-fit, object-position, box-sizing, text-decoration, cursor, and outline. Do not invent property names or write text fragments inside css_extra.\n"
            . "- css_responsive must contain at least one `@media (max-width: 768px)` rule and one `@media (max-width: 420px)` rule. Put @media blocks only in css_responsive; keep css_extra as scoped base selectors.\n"
            . "- JSON output mode: return exactly one minified JSON object on one line. The first character is `{` and the last character is `}`. Do not wrap it in markdown. Do not append comments or prose. Escape any unavoidable double quote inside string values as `\\\"`; prefer single-quoted HTML attributes so escaping is rarely needed.\n"
            . "- Return one complete JSON object using the required fields only. Do not output PHP opening or closing tags in html_content.\n"
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
        $buildPlanTask = \is_array($renderContext['_build_plan_task'] ?? null) ? $renderContext['_build_plan_task'] : [];
        $themePalette = $this->resolveThemePaletteForContract($buildPlanTask, $scope);
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
            ? 'The required image src is absolute; keep the host/domain exactly as shown in exact_required_image_url_literal.'
            : 'The required image src is relative; keep the leading slash and do not add a host/domain.';
        $bindingContract = [
            'slot_id' => $slotId,
            'src' => $finalUrl,
            'required_img_attrs' => [
                'src' => $finalUrl,
                'data-pb-ai-image-role' => 'generated-asset',
                'data-pb-ai-asset-slot' => $slotId,
            ],
            'valid_when' => 'html_content contains one img tag whose src and data-pb-ai-asset-slot exactly match this contract',
            'css_url_allowed' => false,
            'src_shape' => $isAbsoluteImageUrl ? 'absolute' : 'relative',
        ];

        return "\nFINAL REQUIRED IMAGE CONTRACT (last instruction wins):\n"
            . "- exact_component_class_prefix: {$prefix}-\n"
            . "- exact_required_image_url_literal: {$finalUrl}\n"
            . "- exact_required_image_slot_literal: {$slotId}\n"
            . "- exact_required_image_binding_contract_json: " . $this->jsonEncodeForPrompt($bindingContract, 900) . "\n"
            . "- EXACT_REQUIRED_IMG_TAG_TO_COPY: {$imgTemplate}\n"
            . "- The exact_required_image_url_literal is valid only when it appears as the src of EXACT_REQUIRED_IMG_TAG_TO_COPY with both data-pb-ai-image-role='generated-asset' and data-pb-ai-asset-slot='{$slotId}' on the same img tag.\n"
            . "- URL shape rule: {$urlShapeRule}\n"
            . "- REQUIRED_IMAGE_STRUCTURE_CONTRACT: html_content must contain exactly one required image tag with the copied src/slot binding, plus a coherent section root, content/copy area, heading, body copy, and compact CTA when the block needs a CTA. You may choose overlay, split, editorial, or asymmetric composition according to the block intent; do not copy a single fixed skeleton.\n"
            . ($skeletonOutline !== '' ? "- REQUIRED_ROLE_OUTLINE (required roles/classes, not a byte-for-byte skeleton):\n{$skeletonOutline}\n" : '')
            . "- REQUIRED_PALETTE_ROLE_MAP: {$roleMapLine}\n"
            . ($cssStructuralHints !== '' ? "- REQUIRED_CSS_ROLE_CONTRACT (style the required roles; values and layout rhythm remain design-owned by the current block):\n{$cssStructuralHints}\n" : '')
            . "- HTML structure rule: render real parsed markup, not prompt labels. Do not put the literal words REQUIRED_IMAGE_STRUCTURE_CONTRACT, REQUIRED_ROLE_OUTLINE, EXACT_REQUIRED_IMG_TAG_TO_COPY, `<img`, `</section>`, `class=`, or `&lt;img` inside text nodes.\n"
            . "- Forbidden image outputs: any src different from exact_required_image_url_literal, reconstructed domain paths, shortened hashes, missing data-pb-ai-image-role, missing data-pb-ai-asset-slot, or moving the image into CSS background-image.\n"
            . "- If any design instruction conflicts with this final required-image contract, keep the exact image binding and required roles, but solve layout/aesthetic choices through the current block's own design contract instead of copying a fixed skeleton.\n";
    }

    private function cleanAiHtmlFragment(string $html, array $verifiedAssets = []): string
    {
        if (\preg_match('/<\s*(?:style|script)\b/i', $html) === 1) {
            throw new \RuntimeException('AI component content hard policy failed: html_content must not contain style or script tags; use JSON CSS fields only.');
        }
        if (\str_contains($html, '<?') || \str_contains($html, '?>')) {
            throw new \RuntimeException('AI component content hard policy failed: html_content must not contain PHP fragments.');
        }
        $svgPolicyReason = $this->detectInlineSvgGeneratedSectionViolation($html);
        if ($svgPolicyReason !== null) {
            throw new \RuntimeException('AI component content hard policy failed: ' . $svgPolicyReason);
        }
        if (\preg_match('/@(?:component|fields)_(?:start|end)\b/i', $html) === 1) {
            throw new \RuntimeException('AI component content hard policy failed: html_content must not contain framework component markers.');
        }
        $html = \trim($html);
        $html = $this->repairMalformedEmptyClosingTags($html);
        $html = $this->repairHtmlTagAttributeSpacing($html);
        $html = $this->repairHtmlFragmentTagBalance($html);
        $this->assertGeneratedHtmlFragmentWellFormed($html);
        $hardPolicyReason = $this->detectHardGeneratedSectionHtmlPolicyViolation($html);
        if ($hardPolicyReason !== null) {
            throw new \RuntimeException('AI component content hard policy failed: ' . $hardPolicyReason);
        }
        $this->assertNoBrokenGeneratedImageReferences($html, $verifiedAssets);
        $html = \preg_replace('/\s{2,}/u', ' ', $html) ?? $html;
        $html = \trim($html);
        $html = $this->repairHtmlFragmentTagBalance($html);
        $length = \function_exists('mb_strlen') ? \mb_strlen($html, 'UTF-8') : \strlen($html);
        if ($length > 5000) {
            throw new \RuntimeException('AI component content contract failed: html_content exceeds the allowed contract size.');
        }
        $this->assertGeneratedHtmlFragmentWellFormed($html);

        return \trim($html);
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
     * 鎻愬彇 HTML 涓?璁垮鍙鏂囨湰"鈥斺€斿墺鎺夋墍鏈夋爣绛撅紙鍖呮嫭灞炴€э級锛屽彧淇濈暀 `>...<` 涔嬮棿鐨勫唴瀹广€?     * 鐢ㄤ簬娉勬紡妫€娴嬶紝閬垮厤鎶?src/href/data-* 涓殑鍚堟硶 URL / slot id 璇垽涓?leak銆?     */
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
        $plain = \strip_tags($html);
        $plain = \html_entity_decode($plain, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $plain = \preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        return \trim($plain);
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
     * AI 瓒呴暱 HTML 鑻ョ敤瀛楄妭/瀛楃纭€ф埅鏂細鐮村潖鏍囩闂幆锛涙湯灏捐鍒版渶杩戜竴娆″畬鏁存爣绛剧粨鏉熻竟鐣屽悗鍐嶈窇涓€娆?repair銆?     */
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
        $html = \preg_replace('/<(li|p|span|div|h[1-6])\b[^>]*>[\s\S]{0,700}(?:\$[a-z_][a-z0-9_]*|=>|===|foreach\s*\(|endif\b|endforeach\b)[\s\S]{0,700}<\/\1>/iu', '', $html) ?? $html;

        return \str_replace('?>', '', $html);
    }

    private function isLowQualityGeneratedSectionHtml(string $html): bool
    {
        return $this->detectLowQualityGeneratedSectionHtmlReason($html) !== null;
    }

    private function detectHardGeneratedSectionHtmlPolicyViolation(string $html): ?string
    {
        $trimmed = \trim($html);
        if ($trimmed === '') {
            return null;
        }

        $plain = \trim((string)\preg_replace('/\s+/u', ' ', \strip_tags($trimmed)));
        $svgPolicyReason = $this->detectInlineSvgGeneratedSectionViolation($trimmed);
        if ($svgPolicyReason !== null) {
            return $svgPolicyReason;
        }
        if (\preg_match('/\bai-site-fallback\b|<svg\s+viewBox=["\']0 0 520 360["\']/iu', $trimmed) === 1) {
            return 'plan-derived fallback visual leaked into generated content';
        }

        // Inspect only visitor-visible text so valid URL and slot attributes do not false-positive.
        $visibleText = $this->extractVisibleHtmlText($trimmed);
        if ($this->containsVisibleRawHtmlFragment($visibleText)) {
            return 'raw HTML fragment leaked into visitor content';
        }
        if (\preg_match('/AI content placeholder|placeholder\s+(?:content|copy|section|text|block|image|visual)|example\.com|Generated visual|prompt text|customer brief|website requirement|planning\/plan language|stage-2 planned text|source intent|Built from plan|generated from plan|confirmed stage-one content|content_fill_rule|field_content_requirements|stage3_directive|task_script|\$[a-z_][a-z0-9_]*|=>|===/iu', $visibleText) === 1) {
            return 'prompt or placeholder text leaked';
        }
        if (\preg_match('/\b(?:AI_GENERATED_[A-Z0-9_]+|task_key|section_code|block_key|page_type|plan_locale|runtime_context|content\/[a-z0-9_\/-]+|app\/code\/[a-z0-9_\/-]+|var\/[a-z0-9_\/-]+|home_page|about_page|shared:[a-z0-9:_\/-]+|page:[a-z0-9:_\/-]+)\b/iu', $visibleText) === 1) {
            return 'internal task identifiers leaked';
        }
        $visibleTextWithTagBoundaries = \html_entity_decode(
            \strip_tags(\preg_replace('/<[^>]+>/u', ' ', $trimmed) ?? $trimmed),
            \ENT_QUOTES | \ENT_SUBSTITUTE,
            'UTF-8'
        );
        $visibleTextWithTagBoundaries = \trim((string)\preg_replace('/\s+/u', ' ', $visibleTextWithTagBoundaries));
        if (\preg_match('/\b(?:[A-Z][A-Za-z]{2,}\s+)?(?:Card|Category|Badge|Step|Item)\s+\d+\b/iu', $visibleText . ' ' . $visibleTextWithTagBoundaries) === 1) {
            return 'internal field labels leaked';
        }

        if ($this->containsPlanningObservationCopy($plain)) {
            return 'planning observation copy leaked into visitor content';
        }

        return null;
    }

    private function containsVisibleRawHtmlFragment(string $visibleText): bool
    {
        if (\trim($visibleText) === '') {
            return false;
        }

        return \preg_match('/(?:<|&lt;)\s*\/?\s*[a-z0-9][^>\n]{0,120}(?:>|&gt;)|\bclass\s*=\s*(["\']).{1,120}\1/iu', $visibleText) === 1;
    }

    private function detectInlineSvgGeneratedSectionViolation(string $html): ?string
    {
        if (\preg_match('/<svg\b|data:image\/svg\+xml/iu', $html) === 1) {
            return 'inline svg visual is not allowed in virtual-theme components; use verified image assets or CSS-only decoration';
        }

        return null;
    }

    private function detectLowQualityGeneratedSectionHtmlReason(string $html, ?string $componentCode = '', string $styleCss = ''): ?string
    {
        $componentCode = $this->normalizeOptionalComponentCode($componentCode);
        $trimmed = \trim($html);
        if ($trimmed === '') {
            return 'empty html';
        }
        if (\preg_match('/\bai-site-fallback\b|<svg\s+viewBox=["\']0 0 520 360["\']/iu', $trimmed) === 1) {
            return 'plan-derived fallback visual leaked into generated content';
        }
        $plain = \trim((string)\preg_replace('/\s+/u', ' ', \strip_tags($trimmed)));
        $isNavigationComponent = \preg_match('/(?:nav|menu|tabs?|filter|selector|series)/i', $componentCode . ' ' . $trimmed) === 1
            || \preg_match('/<(?:nav|a|button)\b/iu', $trimmed) === 1;
        $minimumVisibleTextLength = $isNavigationComponent ? 0 : 18;
        if (!$isNavigationComponent && ($plain === '' || \mb_strlen($plain) < $minimumVisibleTextLength)) {
            return 'insufficient visitor-facing text';
        }
        $visibleText = $this->extractVisibleHtmlText($trimmed);
        if (\preg_match('/AI content placeholder|ai-empty|placeholder\s+(?:content|copy|section|text|block|image|visual)|demo|example\.com|Generated visual|inline SVG|Visual preview generated|Generated website section|Website content language|visitor-visible copy|Do not use the|Return ONLY|prompt text|customer brief|website requirement|planning\/plan language|stage-2 planned text|source intent|Built from plan|generated from plan|confirmed stage-one content|content_fill_rule|field_content_requirements|stage3_directive|task_script/iu', $visibleText) === 1) {
            return 'prompt or placeholder text leaked';
        }
        if ($this->containsEnglishBoilerplateVisibleCopy($visibleText)) {
            return 'English boilerplate copy leaked into visitor content';
        }
        $genericCtaReason = $this->detectGenericCtaIntentViolation($visibleText, $componentCode);
        if ($genericCtaReason !== null) {
            return $genericCtaReason;
        }
        if (!$isNavigationComponent) {
            $typographyReason = $this->detectTypographyQualityViolation($styleCss);
            if ($typographyReason !== null) {
                return $typographyReason;
            }
        }

        if (\preg_match('/\b(?:AI_GENERATED_[A-Z0-9_]+|task_key|section_code|block_key|page_type|plan_locale|runtime_context|content\/[a-z0-9_\/-]+|app\/code\/[a-z0-9_\/-]+|var\/[a-z0-9_\/-]+|home_page|about_page|shared:[a-z0-9:_\/-]+|page:[a-z0-9:_\/-]+)\b/iu', $visibleText) === 1) {
            return 'internal task identifiers leaked';
        }

        if ($this->containsPlanningObservationCopy($plain)) {
            return 'planning observation copy leaked into visitor content';
        }
        if (\preg_match('/\d(?:[.,]\d+)?(?:Hours?|Steps?|Years?|Days?|Rating|Reviews?|Support|Downloads?|Projects?|Clients?|Products?)\b/u', $visibleText) === 1) {
            return 'missing whitespace between number and visible label';
        }

        if (\preg_match('/<(h[1-6]|p|a)\b[^>]*>\s*<\/\1>/iu', $trimmed) === 1) {
            return 'empty visitor element';
        }
        if (\preg_match('/<a\b(?![^>]*\bclass\s*=)[^>]*>/iu', $trimmed) === 1
            && !$this->hasVisitorArticleListStructure($trimmed)
        ) {
            return 'unstyled browser-default link leaked into visitor content';
        }
        if (\preg_match('/>\s*(?:[\p{S}\p{P}\s]{3,})\s*</u', $trimmed) === 1
            && \preg_match('/[\p{L}\p{N}]/u', $visibleText) !== 1
        ) {
            return 'symbol-only decorative control leaked into visitor content';
        }

        $hasVisual = \preg_match('/class=["\'][^"\']*(?:card|visual|panel|media|grid|badge)[^"\']*/iu', $trimmed) === 1;
        $hasRealCopy = \mb_strlen($plain) >= 32;
        $hasVisitorLinkCluster = $this->hasVisitorLinkCluster($trimmed);
        $hasVisitorArticleList = $this->hasVisitorArticleListStructure($trimmed);

        if (!$hasVisual && !$hasRealCopy && !$hasVisitorLinkCluster && !$hasVisitorArticleList) {
            return 'missing real copy, visual hierarchy, or visitor content structure';
        }
        if ($this->looksLikeSparseOversizedSection($trimmed, $plain)) {
            return 'oversized low-density section with too much empty space';
        }
        if ($this->looksLikeSparseMetricCards($trimmed, $plain)) {
            return 'metric cards are oversized and lack supporting proof copy';
        }
        if ($this->looksLikeIsolatedCtaIsland($trimmed, $plain)) {
            return 'CTA is isolated inside a mostly empty section';
        }
        if ($this->looksLikeStretchedCtaBar($trimmed)) {
            return 'CTA is rendered as a full-width bar instead of a compact action button';
        }
        if (\preg_match('/\b(hero|banner|opening|above[-_ ]?fold)\b/i', $componentCode) === 1
        ) {
            $heroReason = $this->detectHeroBannerQualityViolation($trimmed . "\n" . $styleCss);
            if ($heroReason !== null) {
                return $heroReason;
            }
        }

        return null;
    }

    private function detectTypographyQualityViolation(string $styleCss): ?string
    {
        $styleCss = \trim($styleCss);
        if ($styleCss === '') {
            return 'missing branded typography system';
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
        if (\preg_match('/\b(?:home|about|blog|download|apk|app|install|reward|bonus|game|feature|showcase|trust|security|safe|cta|hero)\b|下载|安装|奖励|金币|游戏|棋牌|博客|攻略|信任|安全/iu', $identity) === 1) {
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
            '/\b(?:download|apk|app|install|android|ios|game|play|casino|poker|rummy|ludo|bonus|reward)\b|下载|安装|应用|游戏|棋牌|畅玩|奖励|金币|奖金/iu',
            $source
        ) === 1;
    }

    private function containsOutOfScopeDownloadOrGameCopy(string $visibleText): bool
    {
        return \preg_match(
            '/\b(?:safe\s+download|secure\s+download|download\s+now|install\s+now|play\s+now|start\s+playing|claim\s+bonus|apk|android\s+build|game\s+bonus|free\s+coins?)\b|安全下载|安心畅玩|立即下载|下载安装|开始畅玩|领取金币|金币奖励|游戏奖励/iu',
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
        if (!\preg_match('/\b(?:10\s*млн|4\.8|звезд|скачив|безопасн|downloads?|rating|secure)\b/iu', $plain)) {
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
        if (!\preg_match('/\b(?:скачать|download|get started|start now|начать)\b/iu', $plain)) {
            return false;
        }
        if (\mb_strlen($plain) > 160) {
            return false;
        }

        return \preg_match('/(?:min-height|height)\s*:\s*(?:[6-9]\d{2}|[1-9]\d{3})px/iu', $html) === 1;
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
        $hasCssOnlyMotifLayers = \preg_match('/class=["\'][^"\']*motif[^"\']*["\']/iu', $normalized) === 1
            && \preg_match('/class=["\'][^"\']*orbit[^"\']*["\']/iu', $normalized) === 1;
        $hasVisibleMotifCss = \preg_match('/motif[^{]*\{(?=[^}]*\b(?:width|height)\s*:)(?=[^}]*\b(?:background|border|box-shadow)\s*:)[^}]*\}/iu', $normalized) === 1
            && \preg_match('/orbit[^{]*\{(?=[^}]*\b(?:width|height)\s*:)(?=[^}]*\b(?:background|border|box-shadow)\s*:)[^}]*\}/iu', $normalized) === 1;
        $hasCssOnlySubjectStage = \preg_match('/class=["\'][^"\']*(?:media-stage|media-subject|media-detail|media-label|media-card|media-dish|media-stem|product|subject|stage|vessel|bowl|cup|plate)[^"\']*["\']/iu', $normalized) === 1;
        $hasFullBleedBackground = \preg_match('/(?:data-pb-ai-image-role=["\']generated-asset["\'][^>]*(?:width:100%|height:100%|object-fit)|background-image|object-fit\s*:\s*cover|inset\s*:\s*0)/iu', $normalized) === 1;
        $hasPremiumMediaHero = \preg_match('/(?:data-pb-ai-image-role=["\']generated-asset["\']|class=["\'][^"\']*(?:media|visual|image)[^"\']*["\'])/iu', $normalized) === 1
            && \preg_match('/(?:border-radius|box-shadow|object-fit\s*:\s*cover|inset\s*:\s*0)/iu', $normalized) === 1;
        $hasOverlayPanel = \preg_match('/(?:backdrop-filter|rgba\([^)]*,\s*\.(?:3|4|5|6|7|8|9)|linear-gradient\([^;]*(?:rgba|transparent)|(?:overlay|scrim|veil|shade|backdrop)[^{]*\{[^}]*background\s*:\s*#[0-9a-f]{3,8}[^}]*opacity\s*:\s*\.?[0-9]+)/iu', $normalized) === 1;
        $hasNamedReadabilityLayer = \preg_match('/class=["\'][^"\']*(?:overlay|scrim|veil|shade|backdrop|gradient)[^"\']*["\']/iu', $normalized) === 1;
        $hasNamedTextSafePanel = \preg_match('/class=["\'][^"\']*(?:copy|content|text|caption|message|panel|plate|card)[^"\']*["\']/iu', $normalized) === 1;
        if ($this->looksLikeStretchedCtaBar($normalized)) {
            return 'hero/banner CTA is rendered as a full-width bar instead of a compact action button';
        }
        if (!$hasCssOnlyMediaSkeleton && !$hasFullBleedBackground && !$hasPremiumMediaHero) {
            return 'hero/banner is not using a full-background image or a premium generated media visual';
        }
        if (!$hasGeneratedAssetHero && $hasCssOnlyMediaSkeleton && !$hasCssOnlyMotifLayers) {
            return 'CSS-only hero/banner lacks visible motif and orbit decoration layers';
        }
        if (!$hasGeneratedAssetHero && $hasCssOnlyMediaSkeleton && !$hasVisibleMotifCss) {
            return 'CSS-only hero/banner motif and orbit layers are not visibly positioned';
        }
        if (!$hasGeneratedAssetHero && $hasCssOnlyMediaSkeleton && !$hasCssOnlySubjectStage && !$hasVisibleMotifCss) {
            return 'CSS-only hero/banner lacks a visible subject-matter or editorial media stage';
        }
        if (!$hasCssOnlyMediaSkeleton && !$hasOverlayPanel) {
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
                        'expected' => "<img src='{$url}' data-pb-ai-image-role='generated-asset' data-pb-ai-asset-slot='{$slotId}'>",
                        'hint' => 'Copy EXACT_REQUIRED_IMG_TAG_TO_COPY into html_content. Keep src, data-pb-ai-image-role, and data-pb-ai-asset-slot on the same img tag. Do not move the image into CSS.',
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

        return (int)($visualContract['required'] ?? 0) === 1
            || \trim((string)($defaultConfig['runtime.section_image_required'] ?? '')) === '1';
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
            if ($this->extractHtmlAttribute($tag, 'src') !== $url) {
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
     * Contract-driven structural normalization: when the AI already copied the
     * exact verified final_url into an img tag, ensure the editor binding
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
                'expected' => "<img src='{$imageUrl}' data-pb-ai-image-role='generated-asset' data-pb-ai-asset-slot='{$slotId}'>",
                'hint' => '在 html_content 中放入 EXACT_REQUIRED_IMG_TAG_TO_COPY 的真实 img 标签；保留 src、data-pb-ai-image-role、data-pb-ai-asset-slot；不要把图片移到 CSS、注释或文案里。',
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
                        'expected' => "<img src='{$imageUrl}' data-pb-ai-image-role='generated-asset' data-pb-ai-asset-slot='{$slotId}'>",
                        'hint' => '重写 hero html_content，把规划好的图片作为真实 <img> 放入 hero 媒体层；不要让本地代码插入或替换图片标签。',
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
                    'hint' => '重写 hero html_content，使用规划好的图片 URL；不要用 CSS 背景、注释或替代路径满足图片合同。',
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
                    'hint' => '重写 hero 图片标签，保留 generated-asset 角色属性；不要依赖本地归一化补属性。',
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
            throw new \RuntimeException((string)__('AI 缁勪欢鍖呭惈鏃犳晥鍥剧墖璧勬簮锛?{1}', [\implode(', ', \array_slice($broken, 0, 5))]));
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
            throw new \RuntimeException((string)__('AI 缁勪欢棰勮娓叉煋澶辫触锛?{message}', [
                'message' => (string)($result['error'] ?? 'unknown'),
            ]));
        }

        return (string)($result['html'] ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    private function runAiGeneration(string $region, string $prompt, array $defaultConfig = [], array $renderContext = [], bool $retry = false): array
    {
        $guardedPrompt = $this->prependComponentJsonOnlyGuard($prompt, $retry);
        $maxTokens = \in_array($region, ['header', 'footer'], true) ? 1024 : 6144;
        $fullContent = (string)$this->callAiOperation('generate', [
            'prompt' => $guardedPrompt,
            'scenario_code' => 'pagebuilder_component_generation',
            'params' => $this->buildAiRuntimeParams([
                'allow_zero_balance_provider' => true,
                'response_format' => ['type' => 'json_object'],
                'temperature' => $retry ? 0.05 : 0.15,
                'max_tokens' => $maxTokens,
                'timeout' => self::AI_REQUEST_TIMEOUT_SECONDS,
            ], false),
        ]);

        return $this->decodeAndNormalizeComponentContent(
            $fullContent,
            $region,
            'AI did not return a valid component JSON payload'
        );
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
                    'hint' => '重新输出单个可解析 JSON 对象；不要添加 markdown、解释、第二个对象、未转义引号、裸 HTML 或推理内容。',
                ]]
            );
        }

        return $this->normalizeComponentPayload($payload, $region);
    }

    private function prependComponentJsonOnlyGuard(string $prompt, bool $retry): string
    {
        $guard = [
            'CRITICAL OUTPUT CONTRACT FOR PAGEBUILDER COMPONENT JSON:',
            '- You may think internally, but final output must contain only one JSON object and nothing else.',
            '- The first character of final output MUST be `{` and the last character MUST be `}`.',
            '- Do not output analysis, reasoning_content, markdown, code fences, comments, or explanatory prose.',
            '- Keep exact JSON field names required by this task; do not rename keys.',
            '- Ensure all JSON string values are properly escaped and syntactically valid.',
            '- Backslashes are only legal for JSON escapes like \\", \\\\, \\/, \\n, \\r, \\t, or \\uXXXX. Do not output stray backslashes before HTML text, class names, tag names, or visitor copy.',
            '- PHP/template code is forbidden in every field for this JSON task: no <?php, <?=, <?, ?>, $variables, foreach, echo, or framework template snippets.',
            '- HTML string fields must be well-formed fragments: balanced tags, closed attribute quotes, no orphan closing tags, and no framework wrapper leakage.',
            '- HTML close-tag contract is strict: never merge adjacent closing tags, never invent tags such as </h>, </h3p>, </h2div>, </pa>, </buttondiv>, or </divsection>, and never close a parent element while a child heading/span/strong is still open.',
            '- Do not create empty tag names. `< class=...>`, `< >`, `</ >`, and `<span=...>` are invalid; use `<div class=...>` or plain text.',
            '- css_extra must contain only complete scoped selector blocks. No empty values like background:;, no invented property names, no raw text, no dangling braces, no comments, no comma selector lists, no `}}`, no `{{`, and balanced braces only.',
            '- css_responsive is the only field allowed to contain @media blocks. For content blocks it must include complete `@media (max-width: 768px)` and `@media (max-width: 420px)` blocks when the downstream contract asks for responsive CSS.',
            '- CSS functions are allowed only when complete and production-safe: clamp(), min(), max(), calc(), linear-gradient(), radial-gradient(), rgba(), color-mix(), and transform() are valid with balanced parentheses. CSS url(...) is forbidden for generated assets.',
            '- JSON string safety: do not put double quote characters inside html_content visible copy; rewrite quoted phrases into plain prose so JSON remains valid.',
            '- Use one simple root wrapper and shallow child blocks. Avoid nested inline tags inside headings; put emphasis in sibling spans or paragraphs instead.',
            '- Every `<` inside html_content must begin a valid HTML tag name or be escaped as visitor text; never leave dangling `<`, empty tag names, or comparison symbols in copy.',
            '- Use single quotes for all HTML attributes inside JSON strings. When the prompt provides exact editable image templates, copy their concrete src and data-pb-ai-asset-slot values; never return symbolic URL or slot placeholders.',
            '- Every custom class in html_content must use the requested component prefix. For content blocks in this workflow the prefix is `pb-c`, so classes must look like `pb-c-root` or `pb-c-card`; `.pb`, `pb`, `pb-`, `-card`, and generic class names are invalid.',
            '- Keep the actual compact action button clearly identifiable, normally `pb-c-cta`. Prefer `pb-c-action` or `pb-c-actions` for wrappers so wrapper CSS is not mistaken for button CSS.',
            '- Never place standalone punctuation/symbol-only decorations in visible HTML text. Build arrows, dividers, stars, suit marks, plus signs, and other ornaments with CSS borders, gradients, pseudo-elements, or background layers instead.',
        ];
        if ($retry) {
            $guard[] = 'RECOVERY MODE: previous component JSON did not satisfy the contract. Return the corrected final JSON object immediately.';
        }

        return \implode("\n", $guard) . "\n\n" . $prompt;
    }

    private function collectThrowableMessages(\Throwable $throwable): string
    {
        $messages = [];
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        return \implode(' | ', $messages);
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

        return $normalized !== [] ? \array_replace($data, $normalized) : $data;
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

        $decoded = $parser->extractAndDecode($content);
        if (!\is_array($decoded)) {
            $repaired = $this->repairInvalidJsonBackslashEscapes($content);
            if ($repaired !== $content) {
                $decoded = $parser->extractAndDecode($repaired);
            }
        }
        return \is_array($decoded) ? $decoded : null;
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
            && \strtolower(\trim((string)($params['response_format']['type'] ?? ''))) === 'json_object'
        ) {
            $params = $this->sanitizeStructuredJsonRequestParams($params);
        }

        if (\PHP_SAPI === 'cli' && $isStream) {
            $params['timeout'] = (int)($params['timeout'] ?? self::AI_REQUEST_TIMEOUT_SECONDS);
            $params['disable_ai_timeout'] = false;
            $params['disable_cli_timeout'] = false;
            $params['enforce_timeout_in_stream'] = true;
        }

        return $params;
    }

    /**
     * DeepSeek/GLM 绛?thinking 鍗忚妯″瀷鍦?JSON 鍚堢害浠诲姟涓婂彲鑳藉彧杩斿洖 reasoning_content銆?     * 瀵圭粍浠剁敓鎴愮粺涓€绂佺敤 thinking锛岄伩鍏嶆祦寮?闈炴祦寮忚涓哄垎鍙夈€?     *
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

        $context = \array_merge([
            'page' => $this->buildPreviewPageStub($pageType, $websiteProfile, $scope, $blogContext),
            'style_settings' => $styleSettings,
            'style' => $styleSettings,
            'component_config' => $defaultConfig,
            'is_preview' => true,
            '_content_locale' => $this->resolvePrimaryLocale($websiteProfile, $scope),
            '_content_locale_explicit' => $this->hasResolvedContentLocale($websiteProfile, $scope),
            '_website_profile' => $websiteProfile,
            '_scope_identity' => [
                'site_title' => $scope['site_title'] ?? null,
                'target_domain' => $scope['target_domain'] ?? null,
                'selected_domain' => $scope['selected_domain'] ?? null,
                'website_profile' => $scope['website_profile'] ?? null,
                'shared_prompt_context' => $scope['execution_blueprint']['shared_prompt_context']
                    ?? $scope['plan_workbench']['confirmed']['shared_prompt_context']
                    ?? null,
            ],
            '_visual_contract' => $this->decodeRuntimeVisualContract($defaultConfig),
            '_scope' => $scope,
            '_build_plan_task' => $this->decodeRuntimeBuildPlanTask($defaultConfig),
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
     * 把 buildPlanTask 从 defaultConfig 抽出，供 strict recovery prompt 重新取
     * runtime_context / theme_palette 使用。优先读取已序列化的 JSON，再退回结构化字段。
     *
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function decodeRuntimeBuildPlanTask(array $defaultConfig): array
    {
        foreach ([
            'runtime.build_plan_task_json',
            'runtime.task_contract_json',
            'runtime.build_plan_task',
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
     * @param array<string,mixed> $buildPlanTask
     * @return array<string,string>
     */
    private function extractVerifiedAssetsForBuildPlanTask(array $scope, array $buildPlanTask): array
    {
        $taskType = \trim((string)($buildPlanTask['task_type'] ?? ''));
        $taskKey = \trim((string)($buildPlanTask['task_key'] ?? ''));
        if ($taskType === 'shared_component' || \str_starts_with($taskKey, 'shared:')) {
            return $this->extractIdentityVerifiedAssets($scope);
        }

        $planContext = \is_array($buildPlanTask['plan_context'] ?? null) ? $buildPlanTask['plan_context'] : [];

        return $this->extractVerifiedAssetsForTarget(
            $scope,
            \trim((string)($buildPlanTask['page_type'] ?? $planContext['source_page_type'] ?? '')),
            \trim((string)($buildPlanTask['block_key'] ?? $buildPlanTask['section_key'] ?? $buildPlanTask['source_block_key'] ?? $planContext['source_block_key'] ?? '')),
            \trim((string)($buildPlanTask['section_code'] ?? $planContext['section_code'] ?? '')),
            $taskKey,
            ''
        );
    }

    /**
     * Shared header/footer tasks may legally reuse global identity assets such as
     * logo and title icon. Include only those verified global slots in the
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
        $manifest = \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [];
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        $assets = [];
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
            $assets[$candidateSlotId] = $finalUrl;
        }

        return $assets;
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
     * 寮鸿濂戠害锛氳В鏋?section 搴斿綋缁ф壙鐨勫浘鐗?URL銆?     *
     * 璁捐鍘熷垯锛堢敤鎴疯姹?涓嶈兘璁?build 鐢ㄥ崰浣嶅浘褰?stage1 宸茬粡鏈夌湡瀹炲浘"锛夛細
     *   1. 鐪熷疄鍥剧墖姘歌繙浼樺厛浜庡崰浣嶅浘銆?     *   2. 鍦?閮芥槸鐪熷疄"鎴?閮芥槸鍗犱綅"鍐呴儴锛屽啀鎸?page_type 绮剧‘搴︽帓搴忥細
     *      a. slot_id 涓?section 涔?page+code 绮剧‘鍖归厤锛堝 `page:home_page:content-home-page-hero`锛夈€?     *      b. 鍚?page_type 涓嬨€乻lot_type 鍛戒腑涓?slot_id/block_key/task_key 鍚?section 鍏抽敭瀛椼€?     *      c. 鍚?page_type 涓嬨€乻lot_type 鍛戒腑鐨勯涓?slot銆?     *      d. legacy锛坧age_type==''锛? 鍏抽敭瀛楀懡涓紙haystack 鍚?brief/label锛屽洜 stage1 鏁ｆ爣 slot 淇℃伅甯稿湪 brief锛夈€?     *      e. legacy + slot_type 鍛戒腑鐨勯涓?slot銆?     *
     * 杩欐牱鏃㈤伩鍏嶈鍏抽敭瀛楀尮閰嶉敊鐨?legacy slot 鎶㈠崰锛堝 slot_id=5銆乥rief 鍚?"hero"锛夛紝
     * 涔熶笉浼氳浠呮湁鍗犱綅鍥剧殑 page-scoped slot 鎺掓尋鎺?stage1 宸茶惤鍦扮殑鐪熷疄 legacy 璧勪骇銆?     *
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

        // 鎸?verifiedOnly 鍒囦袱涓灞?pass锛氬厛灏濊瘯鍙寫闈炲崰浣嶅浘锛屽叏閮?miss 鍚庡啀鏀惧鍒板崰浣嶅浘銆?        // 鍏抽敭瀛楀尮閰嶏紙鏃犺 scoped 杩樻槸 legacy锛夊繀椤绘帓鍦?浠绘剰 slot_type 鍛戒腑"涔嬪墠锛?        // 鍚﹀垯 page-scoped 浣嗚涔変笉鐩稿叧鐨?slot锛堝 page:home_page:cta锛変細鎶㈠崰
        // 涓?section 鐪熸鍚屽悕鐨?legacy slot锛堝 details锛夈€?        // Full refactor rule: programmatic/local fallback images are not
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
     * 璺?page_type 鐨勫叧閿瓧鍖归厤锛氫粎鍦?page-scoped 涓?legacy keyword 鍖归厤鍏?miss 鍚庡惎鐢ㄣ€?     * 鐢ㄤ簬鏁戞椿閭ｄ簺琚?stage1 閿欒鍦版墦鍒颁簡闈炲綋鍓?page_type 鐨?legacy slot锛堝 details鈫抋bout_page锛夈€?     *
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
     * 鍦ㄦ寚瀹?page_type 鑼冨洿鍐咃紝鎸夊€欓€夊叧閿瓧鍛戒腑 slot銆?     *
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
     * 鍦ㄦ寚瀹?page_type 鑼冨洿鍐咃紝杩斿洖棣栦釜 slot_type 鍛戒腑鐨?slot final_url锛堜笉渚濊禆鍏抽敭瀛楀尮閰嶏級銆?     *
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
     * 鍒ゆ柇 manifest slot 鏄惁浠呮湁鍗犱綅鍙樹綋锛坒ake_mode/鎵嬪姩 placeholder fallback 鍐欑洏鐨?SVG锛夈€?     *
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
        $buildPlanTask = $this->resolveSharedBuildPlanTask($scope, 'header');
        $buildPlanPromptAddon = $this->buildSharedBuildPlanTaskPromptAddon($buildPlanTask, 'header', $scope);
        $themeContract = $this->buildThemeContractPromptAddon($scope);
        $skillContract = $this->buildWelineSkillContractPromptAddon();
        $claudeDesignSkill = $this->buildClaudeDesignSkillPromptAddon('stage3', $scope);
        $visibleCopyRule = $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope);
        $routeContractPrompt = $this->buildSharedRouteContractPromptAddon($scope, $locale);

        $sharedQualityGateContract = $this->buildSharedQualityGateSelfCheckPromptAddon('header', $buildPlanTask, $websiteProfile, $scope, $locale);

        return $langRule
            . $this->clipText($stage3LocaleContract, 260)
            . "You are generating one PageBuilder website header component.\n"
            . "Site name: {$siteDisplayName}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . "Selected pages: " . \implode(', ', $pageList) . "\n"
            . "Current navigation data: " . $this->jsonEncodeForPrompt($headerConfig['nav_items'] ?? [], 360) . "\n"
            . $routeContractPrompt
            . $this->buildSharedOutputRulesPromptAddon('header')
            . "CTX_SHARED_QUALITY_GATE_CONTRACT:\n" . $sharedQualityGateContract
            . $this->clipText($visibleCopyRule, 260)
            . $this->clipText($skillContract, 280)
            . $this->clipText($claudeDesignSkill, 320)
            . $this->clipText($themeContract, 420)
            . $this->buildSharedVisualRulesPromptAddon('header')
            . $this->clipText($buildPlanPromptAddon, 780)
            . ($sharedRefinement !== '' ? "Latest user refinement for this header: {$sharedRefinement}\n" : '')
            . $this->buildSharedComponentJsonSafetyRulesEn('header');
    }

    private function buildFooterGenerationPrompt(array $websiteProfile, array $scope, string $siteDisplayName, array $footerConfig): string
    {
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        $siteSummary = $this->filterVisibleCopyForLocale(
            $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope),
            $locale
        );
        $styleCode = $this->resolvePromptStyleCode($scope, Page::TYPE_HOME);
        $styleDirection = $this->describeStyleDirection($styleCode);
        $langRule = $this->buildPrimaryLanguageRuleEn($websiteProfile, $scope);
        $stage3LocaleContract = $this->buildStage3LocaleExecutionPromptAddon($websiteProfile, $scope);
        $sharedRefinement = $this->resolveSharedComponentRefinement($scope, 'footer');
        $buildPlanTask = $this->resolveSharedBuildPlanTask($scope, 'footer');
        $buildPlanPromptAddon = $this->buildSharedBuildPlanTaskPromptAddon($buildPlanTask, 'footer', $scope);
        $themeContract = $this->buildThemeContractPromptAddon($scope);
        $skillContract = $this->buildWelineSkillContractPromptAddon();
        $claudeDesignSkill = $this->buildClaudeDesignSkillPromptAddon('stage3', $scope);
        $visibleCopyRule = $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope);
        $routeContractPrompt = $this->buildSharedRouteContractPromptAddon($scope, $locale);

        $sharedQualityGateContract = $this->buildSharedQualityGateSelfCheckPromptAddon('footer', $buildPlanTask, $websiteProfile, $scope, $locale);

        return $langRule
            . $this->clipText($stage3LocaleContract, 260)
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
            . $this->buildSharedOutputRulesPromptAddon('footer')
            . "CTX_SHARED_QUALITY_GATE_CONTRACT:\n" . $sharedQualityGateContract
            . $this->clipText($visibleCopyRule, 1100)
            . "Footer source lock: footer_extra_text is optional. If you write it, synthesize one short target-locale sentence from approved site facts. Do not quote or copy the customer brief, source objective, source truth, stage-1 notes, or English brand summary verbatim. If no target-locale sentence can be safely composed, leave footer_extra_text empty rather than inventing generic support/download/game/app copy.\n"
            . $this->clipText($skillContract, 280)
            . $this->clipText($claudeDesignSkill, 320)
            . $this->clipText($themeContract, 420)
            . $this->buildSharedVisualRulesPromptAddon('footer')
            . $this->clipText($buildPlanPromptAddon, 780)
            . ($sharedRefinement !== '' ? "Latest user refinement for this footer: {$sharedRefinement}\n" : '')
            . $this->buildSharedComponentJsonSafetyRulesEn('footer');
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
        $buildPlanTask = $this->resolveSectionBuildPlanTask($scope, $pageType, (string)($section['code'] ?? ''), $sectionKey);
        $planContext = \is_array($buildPlanTask['plan_context'] ?? null) ? $buildPlanTask['plan_context'] : [];
        $blockTask = \is_array($buildPlanTask['block_task'] ?? null) ? $buildPlanTask['block_task'] : [];
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
        // planning sentences like "Visitors see the game lobby" into config fields,
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
        $buildPlanPromptAddon = $this->buildBuildPlanTaskPromptAddon($buildPlanTask, 'section', $scope);
        $themeContract = $this->buildThemeContractPromptAddon($scope);
        $visualExcellence = $this->buildVisualExcellencePromptAddon('section');
        $premiumDesignContract = $this->buildPremiumSectionDesignContractPromptAddon($pageType, $sectionKey, $sectionTemplate);
        $sectionVisualContract = $this->buildSectionVisualContract($pageType, $section, $blueprint, $websiteProfile, $scope);
        $sectionThemePalette = $this->resolveThemePaletteForContract($buildPlanTask, $scope);
        $sectionVisualContractPrompt = $this->buildSectionVisualContractPromptAddon($sectionVisualContract, $sectionThemePalette);
        $skillContract = $this->buildWelineSkillContractPromptAddon();
        $claudeDesignSkill = $this->buildClaudeDesignSkillPromptAddon('stage3', $scope);
        $visibleCopyRule = $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope);
        $pageLabel = $this->normalizePromptVisibleLabel(
            (string)($blueprint['page_label'] ?? ''),
            $this->localizePageTypeTitle($pageType, $locale),
            $locale
        );

        // 13 项门禁反向编码：把 visual_depth / responsive_signals / theme_visible /
        // language_consistency / must_include_facts 等门禁判定规则直接写进 prompt，
        // 让 AI 在生成前按相同规则自检，替代散落的「示例段+硬骨架」补丁。
        $qualityGateContract = $this->buildQualityGateSelfCheckPromptAddon($pageType, $section, $buildPlanTask, $websiteProfile, $scope, $locale);

        return $langRule
            . $this->clipText($stage3LocaleContract, 520) . "\n"
            . "Stage-2 prompt assembly order: base_output_contract -> confirmed_theme -> frozen_task_context -> current_asset_context -> quality_gate_contract -> copy_policy -> skill_guidance -> design_quality -> latest_refinement.\n"
            . "You are generating exactly one PageBuilder content component for the current task.\n"
            . "Page type: {$pageLabel} ({$pageType})\n"
            . "Section: {$sectionName}; role={$sectionKey}; structure={$sectionTemplate}\n"
            . ($sectionTemplate === 'hero' ? "Hero default: real 1920x750-style editable <img> cover layer, scrim, text-safe panel, and no CSS-background-only replacement.\n" : '')
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . ($brief !== '' ? "Customer brief: {$brief}\n" : '')
            . "Page guidance: {$pageInstruction}\n"
            . "Suggested section config: {$sectionConfig}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . "CTX_CURRENT_ASSET (highest priority for image URL and slot binding; paste exact strings, never reconstruct paths):\n" . $sectionVisualContractPrompt
            . "CTX_BASE_OUTPUT_CONTRACT:\n" . $this->buildSectionOutputRulesPromptAddon()
            . "CTX_CONFIRMED_THEME:\n" . $this->clipText($themeContract, 1500) . "\n"
            . "CTX_FROZEN_TASK:\n" . $buildPlanPromptAddon
            . "CTX_QUALITY_GATE_CONTRACT (13 强契约门禁反向编码，生成前必须按本清单自检):\n" . $qualityGateContract
            . "CTX_COPY_POLICY:\n" . $this->clipText($visibleCopyRule, 720) . "\n"
            . "CTX_SKILL_GUIDANCE:\n" . $this->clipText($skillContract, 520) . "\n" . $this->clipText($claudeDesignSkill, 820) . "\n"
            . "CTX_DESIGN_QUALITY:\n" . $this->clipText($visualExcellence, 720) . "\n" . $this->clipText($premiumDesignContract, 900) . "\n"
            . ($refinement !== '' ? "Latest refine instruction for this section: {$refinement}\n" : '')
            . ($blogPrompt !== '' ? $blogPrompt . "\n" : '')
            . "Final execution rule: follow the current task contract and output schema over all generic guidance. If a required image exists, render the editable <img> tag from CTX_CURRENT_ASSET in html_content with exact src/slot strings. Optional visual flourish that risks invalid HTML/CSS must be simplified, not dropped to a flat slab.\n";
    }

    /**
     * @param array<string,mixed> $section
     * @param array<string,mixed> $buildPlanTask
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function buildQualityGateSelfCheckPromptAddon(
        string $pageType,
        array $section,
        array $buildPlanTask,
        array $websiteProfile,
        array $scope,
        string $locale
    ): string {
        $renderer = $this->getVisualBlockContractRenderer();
        $themePalette = $this->resolveThemePaletteForContract($buildPlanTask, $scope);
        $brief = $this->resolveBlockBriefForContract($buildPlanTask, $section, $websiteProfile, $scope);
        $visualContract = \is_array($section['visual_contract'] ?? null) ? $section['visual_contract'] : [];
        $hasVerifiedHeroImage = (int)($visualContract['required'] ?? 0) === 1
            && \trim((string)($visualContract['final_url'] ?? $visualContract['url'] ?? '')) !== ''
            && (
                \in_array((string)($visualContract['slot_type'] ?? ''), ['hero_image'], true)
                || \in_array((string)($visualContract['usage'] ?? ''), ['section_background_cover'], true)
            );

        return $renderer->renderSectionVisualContract($themePalette, $brief, $locale, $hasVerifiedHeroImage);
    }

    /**
     * @param array<string,mixed> $buildPlanTask
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveThemePaletteForContract(array $buildPlanTask, array $scope): array
    {
        $runtimeContext = \is_array($buildPlanTask['runtime_context'] ?? null) ? $buildPlanTask['runtime_context'] : [];
        $themeContext = \is_array($runtimeContext['theme_context_snapshot'] ?? null) ? $runtimeContext['theme_context_snapshot'] : [];
        if ($themeContext === []) {
            $themeContext = $this->findThemeContextCandidate($scope);
        }
        $normalized = $this->normalizeThemeContract($themeContext);

        return \is_array($normalized['palette'] ?? null) ? $normalized['palette'] : [];
    }

    /**
     * @param array<string,mixed> $buildPlanTask
     * @param array<string,mixed> $section
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveBlockBriefForContract(array $buildPlanTask, array $section, array $websiteProfile, array $scope): array
    {
        $planContext = \is_array($buildPlanTask['plan_context'] ?? null) ? $buildPlanTask['plan_context'] : [];
        $blockTask = \is_array($buildPlanTask['block_task'] ?? null) ? $buildPlanTask['block_task'] : [];
        $contentContract = \is_array($buildPlanTask['content_contract'] ?? null) ? $buildPlanTask['content_contract'] : [];

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

        return [
            'page_goal' => (string)($planContext['page_goal'] ?? $blockTask['page_goal'] ?? ''),
            'block_goal' => (string)($planContext['block_goal'] ?? $blockTask['task_goal'] ?? ''),
            'must_include_facts' => \array_values(\array_unique($facts)),
            'site_title' => (string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? ''),
            'site_brand' => (string)($websiteProfile['site_brand'] ?? $scope['site_brand'] ?? ''),
            'brand_name' => (string)($websiteProfile['brand_name'] ?? $scope['brand_name'] ?? ''),
        ];
    }

    private function getVisualBlockContractRenderer(): \GuoLaiRen\PageBuilder\Service\AI\Contract\AiSiteVisualBlockContractRenderer
    {
        if ($this->visualBlockContractRenderer === null) {
            $this->visualBlockContractRenderer = new \GuoLaiRen\PageBuilder\Service\AI\Contract\AiSiteVisualBlockContractRenderer();
        }

        return $this->visualBlockContractRenderer;
    }

    /**
     * @param array<string,mixed> $buildPlanTask
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function buildSharedQualityGateSelfCheckPromptAddon(
        string $region,
        array $buildPlanTask,
        array $websiteProfile,
        array $scope,
        string $locale
    ): string {
        $renderer = $this->getVisualBlockContractRenderer();
        $themePalette = $this->resolveThemePaletteForContract($buildPlanTask, $scope);
        $brief = $this->resolveBlockBriefForContract($buildPlanTask, [], $websiteProfile, $scope);

        return $renderer->renderSharedRegionVisualContract($region, $themePalette, $brief, $locale);
    }

    /**
     * 鑻辨枃纭害鏉燂細闄嶄綆鍚堝苟杩?.phtml 鍚庣殑 PHP 璇硶閿欒锛堝 unexpected "=>"锛?     */
    private function buildSectionOutputRulesPromptAddon(): string
    {
        // V3 输出契约：与 AiSiteQualityGateService 的 13 项门禁信号严格对齐。
        // 关键变更（V2→V3）：
        //   1) 强制提供 gradient / @media / clamp 至少 1 次，匹配 visual_depth + responsive_signals 门禁；
        //   2) css 预算上调：html_content<=2400 / css_extra<=2400 / css_responsive<=900；
        //   3) 删除「css_responsive 必须为空 / 禁止 @media / 禁止 linear-gradient / 禁止 clamp」与门禁互相打架的条款；
        //   4) 不再强制单一 minimal skeleton，骨架由 CTX_CURRENT_ASSET 上下文动态决定。
        return "Stage-2 component output contract V3 (this overrides any broader visual advice above):\n"
            . "1. Single responsibility: generate only the current block. Do not reinterpret the whole website, do not generate neighboring blocks, and do not print planning/contract/schema text.\n"
            . "2. Return exactly one JSON object. First character `{`, last character `}`. No markdown, no prose, no second object, no raw CSS/HTML outside JSON.\n"
            . "3. Required string keys only: extra_fields, php_variables, css_extra, css_responsive, html_content, js_content. Set extra_fields, php_variables, and js_content to empty strings.\n"
            . "4. html_content layout: use one root section / inner container / copy panel / optional media panel / optional CTA panel composition. Hero blocks include scrim + text-panel; non-hero may use 2-column or stacked variants according to the block role. Do not invent decorative wrapper tags that drop the required parts from CTX_CURRENT_ASSET.responsive_layout_contract.\n"
            . "4a. If REQUIRED_IMAGE_STRUCTURE_CONTRACT is supplied by CTX_CURRENT_ASSET, it overrides this generic composition. Preserve the exact image binding and required semantic roles, then choose a refined layout rhythm for the current block instead of copying a byte-for-byte skeleton.\n"
            . "4b. HTML_IN_JSON rule: html_content must decode to real HTML tags, not displayed source code. Do not put legacy skeleton labels, raw `<section ...>` examples, `</div>`, class='...', CSS declarations, or escaped `&lt;section` inside visitor-visible text. The decoded html_content string should begin with `<section` and all visible text nodes should be final customer copy only.\n"
            . "4c. Nesting rule: use exactly one h2 for the block title and do not use h3. Card subtitles should be `<div>` or `<p>` text. h2 contains plain text only. p/small contain plain text only. If strong/span is used, it must have no attributes and must close immediately around short text. Prefer sibling div/p labels over nested inline tags. Never put div, section, form, card, image, list, link, button, or another heading inside h2/p.\n"
            . "5. Allowed HTML shape: section/div/h2/p/span/strong/small/form/label/input/textarea/img/br. Use div groups for cards/steps/CTA instead of ul/ol/li/a/button. Do not use `<a>` or `<button>` in content blocks unless a real URL/form action is explicitly supplied. Every tag name must be present, every attribute must be separated by one space, every quote must close, and every non-void tag must close in reverse order. Never output invented close tags such as </h>, </pa>, </pdiv>, </buttondiv>, or </divsection>.\n"
            . "6. Attribute rule: use single quotes inside HTML attributes and one real space before each next attribute. Valid shapes: `<div class='pb-c-card'>`, `<div class='pb-c-cta'>Text</div>`. For images, copy the verified img template exactly when one is provided; never create an img example yourself. Invalid shapes: `< class='pb-c-card'>`, `<div class='pb-c-card>`, `<strong class='pb-c-card'>`, `<span class='pb-c-chip'>`, `<button class='pb-c-cta'>`.\n"
            . "7. Class rule: every custom class starts with the exact component prefix given below plus an element suffix. In this workflow that means `pb-c-root`, `pb-c-inner`, `pb-c-card`, etc. Never use `.pb` or `pb` as a selector/class by itself, never start a class with `-`, `pb` without a suffix, `content-`, or a generic class like card/btn/item/title.\n"
            . "8. css_extra carries the block's visual depth. Use complete selector blocks scoped under `#componentId`, no raw declarations outside braces, no comments. You SHOULD use confirmed theme palette hex tokens, layered backgrounds (gradients allowed), box-shadow, border-radius, padding/margin, display:flex|grid, flex-wrap, gap, max-width, transition for hover/focus, and pseudo-elements when they materially improve aesthetics. Functions linear-gradient(...) and radial-gradient(...) ARE PERMITTED and recommended for surface depth.\n"
            . "8a. Typography hard gate: css_extra must contain explicit font-family declarations for both `#componentId .pb-c-title` and at least one body/root selector (`#componentId .pb-c-root`, `#componentId .pb-c-copy`, or `#componentId .pb-c-text`). Each stack must start with a named brand-appropriate family before generic fallback, for example Georgia/Cambria/Palatino for editorial artisanal, Trebuchet MS/Verdana for approachable, or Optima/Candara for warm lifestyle. A pure default stack such as system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Arial, sans-serif alone is invalid.\n"
            . "9. css_responsive MUST contain at least one `@media (max-width: 768px)` block and one `@media (max-width: 420px)` block. Inside each media block, stack split layouts and set containers/media/form fields to width:100%; max-width:100%; min-width:0; box-sizing:border-box. CTA wrappers may be full-width; the actual CTA button should not become a desktop full-width bar. Empty css_responsive is INVALID for content blocks.\n"
            . "10. CSS syntax rule: every declaration is complete `property:value;`. Use stable property names. Keep braces balanced. Function notation (linear-gradient, radial-gradient, rgba, clamp, min, max, calc, color-mix) IS allowed when the parentheses are balanced and the values parse; never emit a bare `(` without matching `)`. The only valid box-sizing value is `border-box`; never output `box-sizing:border`.\n"
            . "11. Responsive layout rule: desktop uses fluid grid/flex flow; tablet (<=900px) stacks split layouts; mobile (<=420px) is single column. Apply width:100%; max-width:100%; min-width:0; box-sizing:border-box to layout containers, media, cards, and form fields. Prefer action/actions for CTA wrappers so wrapper CSS is not mistaken for button CSS. Never push forms/cards outside the viewport with negative margins, translateX, fixed side offsets, or absolute side panels.\n"
            . "12. Image slot rule: if a verified image template/final_url is supplied, copy that concrete `<img>` into html_content and keep src, data-pb-ai-image-role, and data-pb-ai-asset-slot exactly. Do not invent image URLs. Do not place image URLs in css_extra via url(...); image assets belong in real <img> tags. If no verified image exists, use CSS surfaces only.\n"
            . "13. Hero/media readability rule: if text sits over media, the html_content skeleton must include a scrim div and a text-panel div using the exact component prefix provided below, and css_extra must include matching scoped scrim/text-panel selectors. If you cannot include those two classes correctly, do not place text over media; use a normal side-by-side layout instead.\n"
            . "14. Content rule: visible copy must be target-locale visitor copy derived from this block's page_goal/block_goal/content_plan and CTX_BLOCK_QA_CONTRACT.must_include_facts. Do not render why_this_block, page_goal, block_goal, data_contract, visual_contract, runtime_context, selected_skill_codes, template fragments, raw HTML tag source, or CSS source. Visible copy must not contain double quote characters because they often break JSON strings.\n"
            . "14a. CSS brace rule: css_extra must be a series of well-formed selector blocks like `#componentId .pb-c-name{property:value;}`. @media blocks belong in css_responsive ONLY and must be one complete `@media (...) { selector{...} }` body each. No `}}` glued without a `{` between, no comma selectors that span unrelated regions. Count opening and closing braces before returning; counts must match exactly.\n"
            . "15. Size budget: html_content <= 2400 chars, css_extra <= 2400 chars, css_responsive <= 900 chars. If close to budget, simplify selector list but never drop visual_depth / responsive_signals required by CTX_BLOCK_QA_CONTRACT; never truncate JSON.\n"
            . "16. Final self-check before output: JSON parses; HTML tags/quotes are balanced; CSS braces and parentheses are balanced; CSS selectors match HTML classes exactly; theme palette hex tokens are used; css_extra includes non-default brand font-family declarations for `#componentId .pb-c-title` and one body/root selector; gradient + @media + clamp signals each appear at least once; no raw text after the JSON object.\n";
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
            . "3. Visible copy must be final customer-facing text in the target locale. Never print prompts, task labels, contract keys, page_type, section_code, runtime_context, or why-this-block explanations.\n"
            . $minimalRule
            . "4. Do not output image tags in shared components. Logo/image rendering is handled by framework config.\n"
            . "5. css_extra ownership rule: shared header/footer visual shell, palette, spacing, shadows, gradients, hover/focus states, and mobile behavior are owned by framework config by default. Only when the latest user request explicitly asks to restyle this shared {$region}, css_extra may contain a small scoped override using confirmed theme tokens; otherwise it must be an empty string.\n"
            . "6. HTML fragments only: no PHP, no style/script tags, no framework wrappers, no malformed attributes, no orphan closing tags. Footer html_extra_column/html_extra must stay empty.\n"
            . "7. Keep the shared output tiny. Shared components must not block page section generation with long CSS or duplicated navigation/link markup.\n"
            . "8. Schema-only JSON shape: {$minimalExample}\n"
            . "9. Do not copy example text or old examples from memory. For footer only, fill footer_extra_text with one synthesized target-locale sentence when the source context supports it; otherwise keep it empty. Never quote the customer brief/source objective/source truth/English brand summary verbatim. Never invent app-download, game, casino, reward, APK, install, or generic support-site text for unrelated briefs.\n";
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
     * @param array<string,mixed> $buildPlanTask
     * @param array<string,mixed> $scope
     */
    private function buildSharedBuildPlanTaskPromptAddon(array $buildPlanTask, string $contextLabel, array $scope = []): string
    {
        if ($buildPlanTask === []) {
            throw new \RuntimeException('Build prompt contract failed: missing stage-2 build-plan task context for ' . $contextLabel . '; scope-level prompt fallback is forbidden.');
        }
        $runtimeContext = \is_array($buildPlanTask['runtime_context'] ?? null) ? $buildPlanTask['runtime_context'] : [];
        $themeContext = \is_array($runtimeContext['theme_context_snapshot'] ?? null) ? $runtimeContext['theme_context_snapshot'] : [];
        $sharedPromptContext = \is_array($runtimeContext['shared_prompt_context'] ?? null) ? $runtimeContext['shared_prompt_context'] : [];
        if ($themeContext === [] || $sharedPromptContext === []) {
            throw new \RuntimeException('Build prompt contract failed: task runtime_context missing stage-2 theme/shared context for ' . $contextLabel . '.');
        }
        $planContext = \is_array($buildPlanTask['plan_context'] ?? null) ? $buildPlanTask['plan_context'] : [];
        $taskScript = \is_array($buildPlanTask['task_script'] ?? null) ? $buildPlanTask['task_script'] : [];
        $blockTask = \is_array($buildPlanTask['block_task'] ?? null) ? $buildPlanTask['block_task'] : [];
        $siteContext = \is_array($runtimeContext['site_context'] ?? null) ? $runtimeContext['site_context'] : [];
        $assetContext = \is_array($runtimeContext['asset_context'] ?? null) ? $runtimeContext['asset_context'] : [];
        $contentLocale = \trim((string)($runtimeContext['content_locale'] ?? $this->resolveScopePrimaryLocale($scope)));

        return "Shared build-plan task context for {$contextLabel}:\n"
            . "- task_key: " . (string)($buildPlanTask['task_key'] ?? '') . "\n"
            . "- block_goal: " . (string)($planContext['block_goal'] ?? $blockTask['task_goal'] ?? '') . "\n"
            . "- story_goal: " . (string)($taskScript['story_goal'] ?? '') . "\n"
            . "- content_locale: " . ($contentLocale !== '' ? $contentLocale : 'not provided') . "\n"
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
            . "- Field php_variables: MUST be an empty string for this virtual-theme build. The framework already provides variables and config.\n"
            . "- In php_variables, every array literal must be complete: e.g. \$x = ['k' => 'v']; with all [, ], (, ), quotes, and semicolons balanced. Never paste JavaScript object literals or JSON blobs here. The PHP token => must appear only inside valid PHP array syntax, never loose in HTML/CSS.\n"
            . "- Do not redeclare or break framework-provided variables (\$page, \$getConfig, \$componentId, \$cls, \$parseLinks, \$navItems, etc.) unless you know exactly how; prefer using them read-only.\n"
            . "- extra_fields and js_content: MUST be empty strings unless the task explicitly requires them.\n"
            . "- html_extra, html_extra_column, html_content: static HTML fragments only. No PHP tags, no <style>, no <script>, no @component_start/@fields_start metadata.\n"
            . "- HTML_IN_JSON: html_content must be parsed markup, not visible source text. The decoded html_content string should begin with `<section`; do not output legacy skeleton labels, raw `<section ...>` examples, `class='...'`, CSS declarations, or escaped `&lt;section` as visitor-visible copy.\n"
            . "- Visitor text node contract: h2/p/small/span/div text may contain only final customer-facing copy in the target locale. It must never contain JSON keys, prompt labels, slot ids, raw tag snippets, CSS source, or framework/build-plan identifiers.\n"
            . "- HTML fragments must be balanced and embeddable: close every non-void tag, do not output full <html>/<head>/<body> documents, and do not leave stray closing tags.\n"
            . "- Closing-tag grammar: never merge adjacent closing tags into one token. `</p></a>`, `</small></div>`, and `</div></section>` are valid; `</pa>`, `</smalldiv>`, `</pdiv>`, and `</divsection>` are invalid build-breaking HTML.\n"
            . "- Heading grammar: heading elements must close with the same exact element name, and inline tags inside headings must close first. Valid: `<h3><span>Safe APK</span></h3>`. Invalid: `</h>`, `</spanh2>`, `</h3div>`, or closing a parent while span/strong remains open.\n"
            . "- Disclosure markup discipline: do not use `<details>` or `<summary>` for generated FAQ/accordion sections unless explicitly requested. These tags often produce malformed attributes in model output; use static div/h3/p groups styled by scoped CSS instead.\n"
            . "- HTML quote discipline: use single quotes for attributes inside html_content/css strings, including required generated image <img> attributes. Do not put raw double-quoted HTML attributes inside JSON string values.\n"
            . "- HTML fragment discipline: every `<` in html_content must begin a valid tag name or be escaped as text, all attribute quotes must close, and all non-void tags must be balanced before returning JSON.\n"
            . "- HTML scope discipline: html_content is already nested inside the framework root. Never output `id='componentId'`, `id=\"componentId\"`, or any inner wrapper id that pretends to be the framework root. Use class-only wrappers with the component prefix.\n"
            . "- HTML attribute discipline: never output malformed attributes such as `<span='...'>`, `<div='...'>`, `<a ... class='x'href='...'>`, or `<span='class-name'>`. Every attribute needs a name, equals sign, quoted value, and whitespace before the next attribute.\n"
            . "- css_extra, css_responsive: CSS only. No <? ... ?> and no PHP. These fields own the component's visual quality and responsive behavior; the renderer will not inject compatibility CSS/JS or beautify weak output after generation.\n"
            . "- Responsive layout: ship a real responsive solution. Base css_extra defines desktop, tablet (<=900px) stacks split layouts, mobile (<=420px) is single column. css_responsive MUST contain at least one `@media (max-width: 768px)` and one `@media (max-width: 420px)` block. Inside each, set stacked children to width:100%; max-width:100%; min-width:0; box-sizing:border-box; and rebalance typography/spacing.\n"
            . "- Visual depth: scoped CSS should use confirmed theme palette tokens with layered backgrounds (linear-gradient/radial-gradient permitted), pseudo-elements for ornament, hover/focus states, box-shadow elevation, border-radius, padding/gap rhythm, type scale, and transition. Every selector rule and @media block must have balanced { } braces.\n"
            . "- CSS grammar discipline: no empty declarations (`content:;`), no malformed properties (`-index`), no bare percent values (`height:%`), no merged declarations (`position:relativez-index:1`), no extra `}`. Function notation (linear-gradient, radial-gradient, rgba, clamp, min, max, calc, color-mix) IS allowed when parentheses are balanced and the values parse.\n"
            . "- CSS reliability mode: every declaration must be `property: value;`; put a semicolon before the next property. Use scoped selectors with the supplied component prefix only. `box-sizing` must be exactly `border-box`. Avoid mask/clip-path/filter chains unless trivially correct. Do not introduce CSS comments. When in doubt about a complex value, fall back to a safer hex/shadow/spacing token rather than truncating mid-function.\n"
            . "- Typography hard gate: css_extra must explicitly style both `#componentId .pb-c-title` and at least one body/root selector (`#componentId .pb-c-root`, `#componentId .pb-c-copy`, or `#componentId .pb-c-text`) with non-default brand font-family stacks. Use a named family first, then generic fallback. Pure system-ui/-apple-system/Segoe UI/Roboto/Arial/sans-serif stacks are invalid.\n"
            . "- Color contrast: never pair dark foreground text with dark backgrounds or light foreground text with light backgrounds; define readable text/CTA/focus states in CSS before returning.\n"
            . "- Content links: generated content blocks should not use `<a>` or `<button>` unless a real URL/form action is supplied. Use a component-prefixed div such as `<div class='pb-c-cta'>` for CTA-looking controls. Never wrap a card/div/grid/panel/section inside an `<a>` or `<button>`.\n"
            . "- Page hierarchy: do not make the section one flat theme-color slab. Use palette roles, surface elevation, dividers, texture, cards, or spacing to distinguish this block from adjacent blocks.\n"
            . "- CSS class names: never use generic selectors like .card, .icon, .btn, .title, .item, .panel, .row, .container, .section, .text, .image, or .active. Use only the exact component prefix supplied later, shaped like pb-c-element, scope selectors with #componentId, and keep CSS selectors and HTML class attributes in sync. The html_content fragment is nested inside #componentId, so CSS must use descendant selectors such as `#componentId .pb-c-root`; do not write `#componentId.pb-c-root` unless the framework root itself has that class, which it will not.\n"
            . "- Images: never output broken image placeholders. If no verified asset URL is provided, create visual rhythm with CSS-only shapes/pseudo-elements inside html_content; do not use <svg>, data:image/svg+xml, empty src, example.com, placeholder services, stock-photo services, external CDN/stock URLs, or unverified .jpg/.png/.webp URLs. If a verified asset URL is provided, put it only in the copied <img> tag, not in css_extra url(...).\n"
            . "- Decorations: never put standalone symbols such as arrows, suit marks, stars, bullets, plus signs, or divider glyphs in visitor-visible HTML. Render decoration with CSS borders, gradients, pseudo-elements, background layers, or box-shadow instead.\n"
            . "- External image ban: never use Unsplash, Pexels, Pixabay, Freepik, Shutterstock, Adobe Stock, source.unsplash.com, images.unsplash.com, picsum.photos, loremflickr.com, scheme-less URLs like ://... or //..., or any image URL that is not supplied as a verified final_url in this prompt.\n"
            . "- IMAGE_SRC_SELF_CHECK before returning: scan every <img src> and every CSS url(...). Each image URL must exactly equal one value from verified_asset_src_allowlist. If a URL is not on the allowlist, remove it or replace it with the matching final_url before returning JSON.\n"
            . "- AI image editability: required generated image slots must be real <img> elements in html_content, not CSS-only background-image references. Copy the prompt's concrete editable image template so the same <img> carries the supplied final_url, data-pb-ai-asset-slot, and data-pb-ai-image-role='generated-asset'. Replace alt='...' with a short visitor-facing image description in the target locale; never copy task labels, slot labels, prompt briefs, or instruction sentences into alt/title/aria text. Symbolic URL or slot placeholder strings are invalid. Use CSS object-fit/absolute positioning when the image needs to look like a banner background. For hero/banner images, the same class on the <img> must be styled by matching CSS as an absolute cover layer; a class spelling mismatch is invalid.\n"
            . "- No post-render visitor-copy cleanup exists. If labels, copy, layout, or image-slot binding are wrong, fix this JSON output instead of assuming PHP will repair it later.\n"
            . "- js_content: MUST be an empty string for this virtual-theme build.\n";
    }

    /**
     * @param array<string,string> $verifiedAssets
     */
    private function buildVerifiedAssetPromptContract(array $verifiedAssets): string
    {
        $allowlist = [];
        $templates = [];
        foreach ($verifiedAssets as $slotId => $finalUrl) {
            $slotId = \str_replace(["\r", "\n"], '', \trim((string)$slotId));
            $finalUrl = \str_replace(["\r", "\n"], '', \trim((string)$finalUrl));
            if ($slotId === '' || $finalUrl === '') {
                continue;
            }
            $allowlist[] = "  * {$finalUrl}";
            $templates[] = "  * <img src='" . $finalUrl . "' alt='...' data-pb-ai-image-role='generated-asset' data-pb-ai-asset-slot='" . $slotId . "'>";
        }
        if ($allowlist === []) {
            return '';
        }

        return "- verified_asset_src_allowlist (CLOSED SET - the only legal image src/url values for this component):\n" . \implode("\n", \array_values(\array_unique($allowlist))) . "\n"
            . "- copyable_verified_asset_img_template (copy/adapt one concrete template per required slot):\n" . \implode("\n", \array_values(\array_unique($templates))) . "\n"
            . "- REQUIRED EXACT IMAGE TAG: copy one concrete template above for each required slot and keep src, data-pb-ai-image-role, and data-pb-ai-asset-slot on the same <img>. If a REQUIRED_HTML_IMG_TO_COPY_VERBATIM or REQUIRED_IMAGE_STRUCTURE_CONTRACT appears in the same prompt, preserve that exact image binding and required role placement instead of reconstructing it from memory. Do not output data-pb-ai-asset-slot on an <img> whose src is missing or different from the matching final_url. Do not return symbolic placeholder strings for URL or slot values.\n"
            . "- Slot placement: the copied/adapted <img> must be inside html_content for this component, not only in CSS, config, comments, or alt text. Put the copied <img> as a direct child of this component's root wrapper or inside the first real media wrapper under that root, before decorative-only layers. This rule applies even for testimonial, FAQ, trust, or category sections.\n"
            . "- Image URL exclusivity: the src for each editable image slot must be copied exactly from the matching final_url above. Do not add, shorten, translate, encode, prefix, trim query strings from, or replace that URL.\n"
            . "- URL character fidelity: treat each final_url as an opaque literal string. Copy every slash, dash, underscore, extension, and fingerprint character exactly; never retype from memory, normalize separators, remove dashes before hashes, or concatenate path segments.\n"
            . "- URL derivation ban: never construct an image path from target_domain, slot_id, section_code, filename, or folder conventions. Do not replace `/domain/page-...` with `domain-page...`; that is a broken invented URL. Paste the exact src from the concrete template.\n"
            . "- URL exactness gate: an image URL is valid only if it matches an allowlist value character-for-character. If the allowlist value is relative, keep it relative; if it is absolute, keep its host exactly. A missing slash, changed folder name, removed `generated`, inserted or removed domain fragment, dropped dot, or one-character hash difference is invalid. Do not repair by guessing or reconstructing a path; copy the literal template URL again.\n"
            . "- Image alt text: replace the template alt='...' with concise visitor-facing image alt text in the website content locale. Never copy slot labels, task labels, component labels, prompt briefs, or action/instruction sentences into alt/title/aria attributes. Invalid examples: 'Introduce brand story and mission', 'Showcase testimonials', 'Answer common questions', 'licenses, security certifications'. Valid examples are concrete visual descriptions such as 'Players at a premium Teen Patti table' or 'Secure APK download badge cluster'.\n"
            . "- IMAGE_SRC_SELF_CHECK: before returning JSON, inspect every <img src> and every CSS url(...). If any image URL is not exactly one value in verified_asset_src_allowlist, the output is invalid; replace it with the correct final_url or remove the image reference. Prefer removing CSS url(...) entirely because generated image assets must be editable <img> elements. Do not leave stock-photo URLs in failed-payload repairs.\n"
            . "- External image ban: never use Unsplash, Pexels, Pixabay, Freepik, Shutterstock, Adobe Stock, source.unsplash.com, images.unsplash.com, picsum.photos, loremflickr.com, scheme-less URLs like ://... or //..., or any image URL not listed in verified_asset_src_allowlist.\n";
    }

    private function resolvePromptStyleCode(array $scope, string $pageType): string
    {
        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        $styleCode = \trim((string)($virtualPage['style_code'] ?? $scope['style_code'] ?? 'default'));

        return $styleCode !== '' ? $styleCode : 'default';
    }

    private function describeStyleDirection(string $styleCode): string
    {
        return match ($styleCode) {
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
            . "- CTA intent contract: cta.text/content.cta_text are authoritative business-action hints. Do not replace a download/install/reward/play action with generic consult/contact labels. A visible CTA label such as Consult now, Contact us, or 立即咨询 is valid only for contact/support/lead-capture blocks.\n"
            . "- theme-development: use confirmed theme palette tokens and CSS variables/inline scoped styles; no CDN, no global selectors, no unrelated hardcoded brand colors, no duplicate pixel/tracking snippets.\n"
            . "- frontend-components: generate one reusable component/block with editable fields and visitor-facing copy; do not emit full-page HTML, static placeholder sections, internal prompt text, generic substitute content, or page-type labels as visible eyebrow text.\n"
            . "- frontend ownership: all aesthetics, layout rhythm, responsive rules, and any tiny allowed interaction belong in the AI JSON fields. Do not rely on PageRenderService or a global compatibility stylesheet to make the result attractive or mobile-safe.\n"
            . "- page-design-plan: for page-owned blocks, page_design_plan is the design brief. Preserve its color_layering, section_flow, interaction_notes, and anti_monotony_rule in the final visual hierarchy.\n"
            . "- asset-rule: when a visual/image is needed but no verified uploaded asset URL exists, create a theme-colored CSS-only visual directly. Never render <svg> or a broken <img>.\n"
            . "- shared-logo-rule: header and footer are shared brand surfaces. If logo.image/logo.url/brand.logo provides a verified logo asset, use the same logo asset in both header and footer by default; do not replace it with section photos, trust badges, category imagery, or generated decorative art.\n"
            . "- shared-logo-variant-rule: only use a separate light/dark/monochrome logo treatment when the approved design plan or latest user instruction requires it for contrast against different header/footer backgrounds. Preserve the same brand shape/name, change only the visual treatment needed for legibility, and keep the alt/aria label as the localized brand name.\n"
            . "- logo-fallback-rule: when no verified logo asset exists, create one consistent typographic/CSS brand mark and reuse that identity in both header and footer. Do not invent unrelated logo concepts per block.\n"
            . "- ai-module-development: this is an audited AI scenario result; include only content that follows the provided stage-1 theme context and current build-plan task contract.\n"
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

        return "Visible copy governance:\n"
            . "- content_locale/default_locale: " . ($contentLocale !== '' ? $contentLocale : 'not provided') . ($defaultLocale !== '' && $defaultLocale !== $contentLocale ? " (default_locale {$defaultLocale})" : '') . "\n"
            . "- plan_locale: " . ($planLocale !== '' ? $planLocale : 'not provided') . " is internal only; never use it as visitor-facing copy language unless it equals content_locale.\n"
            . "- Visitor-visible copy includes headings, paragraphs, cards, nav, CTA labels, form labels, placeholders, alt/title/aria, footer text, badges, metrics, and link text. All must be rewritten into content_locale/default_locale.\n"
            . "- Customer brief, source objective, source truth, brand summary, and build-plan notes are intent sources only. Never render their full sentences verbatim as visible copy; synthesize concise target-locale visitor copy instead.\n"
            . "- For non-English target locales, long Latin/English prose is invalid visible copy except for short brand/proper nouns such as a site name.\n"
            . "- Build-plan/task/slot/schema labels are metadata, not copy. Never render page_type, section_code, task_key, runtime_context, shared:, page:, content/, app/code/, var/, content_contract, design_contract, data_contract, visual_contract, page_goal, block_goal, why_this_block, selected_skill_codes, build_blueprint, or build_tasks.\n"
            . "- Instruction-shaped phrases such as Introduce, Showcase, Answer, Reassure, Remove, Educate, Encourage, Close, Visitors see, or Visitors can review must be rewritten into finished customer-facing website copy.\n"
            . "- Do not output raw target_domain as brand text unless it is the intended visible domain. Use the locked site title/brand and localized business copy.\n"
            . "- Industry-scope gate: do not reuse old app-download, game, casino, reward, APK, install, or generic support-site copy unless the approved brief explicitly asks for that industry. Phrases like safe download, play now, bonus, secure install, 安全下载, 安心畅玩, 立即下载, 开始畅玩, 奖励, or 金币 are invalid for non-app/non-game sites.\n"
            . "- Number spacing must read naturally: do not glue numbers to words in metrics, badges, steps, or CTAs.\n"
            . "- Renderer will not repair copy after generation; return final customer-specific copy only.\n";

        return "Visible copy governance:\n"
            . "- content_locale/default_locale: " . ($contentLocale !== '' ? $contentLocale : 'not provided') . ($defaultLocale !== '' && $defaultLocale !== $contentLocale ? " (default_locale {$defaultLocale})" : '') . "\n"
            . "- plan_locale: " . ($planLocale !== '' ? $planLocale : 'not provided') . " is only an internal planning language hint, never a visitor-facing language source.\n"
            . "- Visitor-visible copy must use content_locale/default_locale. Do not use plan_locale unless it is the same locale.\n"
            . "- Visitor-facing attributes are copy too: alt, title, aria-label, placeholder, value, button labels, nav labels, footer labels, and form labels must be rewritten into content_locale/default_locale before output.\n"
            . "- Planned content is not exempt: if task_script, block_task.content_plan, field samples, nav labels, CTA labels, SEO snippets, or stage-1 plan text use another language, translate/rewrite them into content_locale/default_locale before rendering html_content/footer/header text.\n"
            . "- Brand/profile normalization: never output schema/object names such as websiteProfile, Website Profile, site profile, profile, or raw target_domain as a brand/site title. If the stored site_title/SEO/logo text is in another language than content_locale/default_locale, rewrite it into a concise visitor-facing brand label in the target locale using the business category and market from the brief.\n"
            . "- For non-CJK content locales, Chinese/Japanese/Korean brand names, meta snippets, logo text, badges, and alt text are internal source material only unless the user explicitly requested that script as visible copy. Rewrite them into the target locale before output.\n"
            . "- Task labels, component labels, section labels, image-slot labels, queue/build-plan labels, and data-contract role labels are internal metadata. Never render them verbatim as headings, card titles, badges, CTA text, alt/title/aria text, or body copy; rewrite them into final customer-facing copy.\n"
            . "- Instruction-shaped English copy is forbidden as visible copy. Sentences or alt text starting with Introduce, Showcase, Answer, Reassure, Remove, Educate, Encourage, or Close are task instructions; rewrite them into concrete customer-facing copy before output.\n"
            . "- Rewrite planning/observation sentences into direct marketing copy before rendering. Do not visibly output phrases like \"Visitors see...\", \"Visitors can review...\", \"访客看到...\", or \"访客可以...\".\n"
            . "- Never output blueprint meta-copy: page/current-section highlight headings, planning observations, design instructions, task scripts, or sentences that tell the AI to display hierarchy, trust proof, primary actions, flow, risks, or content grouping. Those are instructions, not website copy.\n"
            . "- Contract-field leak ban: never render content_contract, design_contract, implementation_contract, data_contract, visual_contract, page_goal, block_goal, why_this_block, planning_reason, selected_skill_codes, skill_snapshots, build_blueprint, build_tasks, or qa_report_contract as visible copy. These are build-time context only.\n"
            . "- Never render internal identifiers or paths as visible copy: plan_locale, page_type, section_code, task_key, block_key, runtime_context, app/code paths, var/ paths, content/... component paths, shared:* keys, or page:* keys.\n"
            . "- Renderer will not repair visitor copy after generation; the JSON you return must already contain final customer-specific visible copy.\n"
            . "- Never output internal list labels shaped like schema role + number, for example card/category/badge/step placeholders. Each card/list item needs a customer-specific title and description from the brief, build plan, or verified content data.\n"
            . "- Internal identifier rewrite: if any candidate visible text still contains home_page, about_page, contact_page, page_type, section_code, task_key, plan_locale, runtime_context, shared:, page:, content/, app/code/, or var/, treat it as leaked metadata and rewrite it into natural customer-facing copy before returning.\n"
            . "- Number-label spacing audit: check every metric, badge, stat, step, timeline chip, CTA, and nav label in every script. If a number is immediately followed by Latin or Cyrillic letters in visible copy, insert a readable space or rewrite the phrase into natural copy before returning. Examples: use `10 млн`, `4.8 звезды`, `24 часа`; never `10млн`, `4.8звезды`, or `24часа`.\n"
            . "- Never render broken image placeholders. If a verified uploaded asset URL is absent, create the visual with CSS-only shapes/pseudo-elements; never use <svg>.\n";
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
            . "- Internal planning language is not output language. stage-1/build-plan/story/task samples are intent only.\n"
            . "- Before composing html_content/html_extra/footer text/nav labels, normalize every candidate sentence to source_of_truth_locale.\n"
            . $languageRule
            . "- Proofread the final visible copy in source_of_truth_locale before returning: fix misspelled words, broken word fragments, and missing spaces between sentence text and CTA/link labels. Do not concatenate two labels or a paragraph plus CTA without whitespace or punctuation.\n"
            . "- Numeric label spacing: write metric labels with a visible space or line break between numbers and words, e.g. `4.8 звезды`, never `4.8ЗВЕЗДЫ`.\n"
            . "- Final self-check before returning JSON: if any visitor-visible sentence is not in source_of_truth_locale, rewrite it now and then return.\n";
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
            throw new \RuntimeException('Build prompt contract failed: confirmed stage-1 theme palette is missing; style-code visual fallback is forbidden.');
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

        return "Confirmed visual contract from the approved stage-1 theme and build plan:\n"
            . "- theme_name: " . (string)($contract['name'] ?? '') . "\n"
            . "- visual_tone: " . (string)($contract['visual_tone'] ?? '') . "\n"
            . "- font_family: " . (string)($contract['font_family'] ?? '') . "\n"
            . "- style_signature: " . (string)($contract['style_signature'] ?? '') . "\n"
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
            'footer' => '- Footer quality floor: css_extra must create a distinct closing surface with grouped information rhythm, trust/support emphasis, texture or shape detail, and mobile spacing; a flat link list is invalid. html_extra/html_extra_column must include concrete grouped links and support/legal labels, not empty wrappers.' . "\n",
            default => '- Section quality floor: html_content must include a component-specific wrapper plus at least two visible design devices such as a generated-image media frame, CSS-only motif, trust/metric strip, timeline/process rail, comparison band, badge cluster, or editorial callout; css_extra must style those devices. Do not use inline SVG.' . "\n",
        };

        return "Visual excellence system prompt for {$componentScope}:\n"
            . "- Produce a polished customer preview, not a placeholder template. Use the approved brief/theme; do not leak plan/schema/task text.\n"
            . "- Specificity gate: every visible block must carry at least three brief-derived anchors through visitor copy, media subject, motifs, proof chips, CTA wording, or layout affordances. A block that would fit another business after changing the logo is invalid.\n"
            . "- First-impression gate: the page should read as a finished premium website at screenshot distance, with deliberate scale, focal hierarchy, non-flat surfaces, and a clear conversion path. Do not output wireframe-like panels, loose content dumps, or generic decorative backgrounds.\n"
            . "- Avoid generic sameness: each block needs a role-specific composition, not the same centered title plus three cards.\n"
            . "- Repetition repair rule: if the natural draft uses the same card grid, metric row, gradient orb, icon list, or CTA treatment as another likely section, change the composition before returning JSON.\n"
            . "- Own the final rendering in the returned JSON: scoped html_content/css_extra must carry aesthetics, contrast, and hover/focus polish; css_responsive carries explicit `@media (max-width: 768px)` and `@media (max-width: 420px)` blocks. Do not return an empty css_responsive for content blocks.\n"
            . "- CTA shape contract: CTA-looking controls should read as intentional buttons in the default layout with display:inline-flex, width:auto, max-width around 280px, and flex:0 0 auto. Use a full-width wrapper if the composition needs a band.\n"
            . "- CTA label contract: CTA copy must match the page/block job. Download/app/APK/reward/game blocks use download/play/reward language; blog/learning blocks use reading/explore language; only contact/support blocks use consult/contact language.\n"
            . "- Empty-surface contract: a large flat dark/light rectangle with only a logo, one sentence, or one CTA is not premium. Use content density, surface elevation, borders, shadows, media, and proof/support copy so every large panel earns its space.\n"
            . "- Brief-specific motif contract: convert the current business brief into visible art direction through safe palette choices, shaped surfaces, copy labels, badges, media treatment, and spacing rhythm. Generic casino/gaming/business panels are invalid when the brief provides richer nouns or audience context.\n"
            . "- Responsive structure is mandatory: root shell, inner container, copy panel, media/CSS visual panel, action/form panel, and decorative layers with component-prefixed classes.\n"
            . "- At <=900px stack split layouts; at <=420px use one column. Containers/media/forms may use width:100%, max-width:100%, box-sizing:border-box, min-width:0; keep the desktop/default CTA button visually compact and avoid making it a page-width bar. Prefer action/actions for CTA wrappers so wrapper CSS is not mistaken for button CSS.\n"
            . "- If image plus form/card appears, keep both in normal grid/flex flow. Do not absolutely push cards outside the root or overlap inputs/text with media/decorations.\n"
            . "- Text over photos or busy textures needs a real scrim/panel/veil layer. Never put body copy directly on a busy image.\n"
            . "- Use readable palette pairings, surface depth, shadows, texture, dividers, and accessible focus states. Do not use fragile CSS tricks when simple scoped CSS works.\n"
            . "- HTML fields must be valid fragments with one component-owned root, balanced tags/quotes, no framework wrappers, no neighboring block HTML, and no PHP.\n"
            . "- Before returning, mentally check 1440/1024/768/390px. Rewrite any layout that would overflow, crop text, lose contrast, or overlap content.\n"
            . $scopeRule
            . "- If no real asset is available, create CSS-only visual language that matches the brief; never leave blank media and never use <svg>.\n";
    }

    private function buildPremiumSectionDesignContractPromptAddon(string $pageType, string $sectionKey, string $sectionTemplate): string
    {
        $identity = \strtolower($pageType . ' ' . $sectionKey . ' ' . $sectionTemplate);
        $isHero = \preg_match('/\b(hero|banner|opening|above[-_ ]?fold)\b/i', $identity) === 1;
        $recipe = 'Use a section-specific composition, not the same card grid used by other blocks.';
        if (\preg_match('/trust|security|safe|license|proof|review|testimonial/i', $identity) === 1) {
            $recipe = 'Trust/security/social-proof recipe: use a badge wall, credential seal, quote rail, metric strip, or verification timeline. Do not use a generic three-card row plus one image.';
        } elseif (\preg_match('/game|feature|showcase|product|suite|service/i', $identity) === 1) {
            $recipe = 'Game/showcase recipe: use the approved focused role outline when the component contract supplies FEATURE_ROLE_MODE; express game variety and benefits through polished paragraph copy instead of cards, carousels, image tiles, or repeated game panels.';
        } elseif (\preg_match('/faq|accordion|question|support|help/i', $identity) === 1) {
            $recipe = 'FAQ/support recipe: use accordion/list rhythm with an asymmetric help panel or support badge cluster. It must not look like testimonials, trust badges, or a feature grid.';
        } elseif (\preg_match('/cta|download|conversion|final|action/i', $identity) === 1) {
            $recipe = 'CTA/download recipe: use a cinematic full-bleed band, strong overlay copy, device/download affordance, and one unmistakable action path. Do not render another neutral card group.';
        } elseif (\preg_match('/story|about|mission|journey|timeline/i', $identity) === 1) {
            $recipe = 'Story/about recipe: use editorial split layout, timeline, founder/mission panel, or layered narrative cards. It must not reuse the hero/card-grid composition.';
        }

        $heroRule = $isHero
            ? "- HERO/BANNER DEFAULT BASELINE: unless the user's latest block-adjustment instruction or approved design plan explicitly conflicts, render a real premium 1920x750-style banner. When a verified generated image exists, it must be a real editable <img> cover layer with object-fit:cover and the required slot attributes; content sits inside a floating text-safe panel on top of an image-wide scrim/gradient veil. The text panel must have its own readable background/scrim, padding, max-width, and foreground colors so paragraphs remain readable on busy photos. If no verified image is available, the CSS-only media layer must still read as deliberate art direction through palette, shadow, border, overlay, and text-panel composition; it must express a subject-matter stage or editorial surface from the approved brief, not generic overlapping circles, blobs, a blank slab, or a centered card on empty background. The primary CTA should read as a compact button in the default layout, not a full-width bar; wrapper bands can be full width. Do NOT create a small side image, isolated centered card, narrow media frame, CSS background-image-only hero, huge empty side gutters, or dark body text directly over a detailed photo as the default. If the user explicitly asks for another hero composition, follow it while preserving premium generated imagery, strong hierarchy, and readable overlay treatment.\n"
            : "- NON-HERO HARD RULE: this block needs its own layout purpose and visual rhythm. Do not copy the hero structure or the previous block's card/media arrangement.\n";

        return "Premium site design contract for this section:\n"
            . "- Base prompt precedence: this section recipe is the default quality baseline. If the latest user refinement or approved block plan explicitly asks for a different composition, use that composition while preserving the same premium quality bar, content relevance, image-slot usage, contrast, and anti-placeholder constraints.\n"
            . $heroRule
            . "- Section recipe: {$recipe}\n"
            . "- Anti-monotony rule: adjacent blocks must not share the same three-card row, same image position, same copy labels, or same pale background/card treatment. Change composition, scale, motif, and spacing per section role.\n"
            . "- Rejected output patterns: centered title plus three small cards plus the same image; tiny cartoon/SVG-looking media used as a substitute for a real generated image; repeated generic CTA/category labels across blocks; hero built as a boxed card next to a small image; CTA stretched into a page-width bar; large empty dark panel with only a logo or one button; CSS-only hero made only from overlapping circles/blobs with no subject-matter stage.\n"
            . "- If a verified generated image exists, use it prominently and naturally as a real editable <img>. For hero, the <img> is a cover layer behind the overlay copy; for non-hero it is a purposeful media surface, not a thumbnail afterthought.\n";
    }

    /**
     * 生成「已验证图片插槽」的 prompt 工件。
     *
     * 设计变更（强契约改造）：
     *   旧版本会硬编码一整套 hero / 非 hero 的 SAFE CSS（包括 #111827、#f59e0b 等
     *   与站点主题完全无关的颜色），导致 AI 即便照抄也产出一个「与品牌脱节」的
     *   通用骨架。
     *
     *   现在只输出三件事：
     *     1) img_template —— 必须逐字精确复用的 <img> 标签（含 src/data 属性）；
     *     2) skeleton_outline —— 一份不含具体颜色 / 字号的结构标签清单，告诉 AI
     *        应该有哪些 .pb-c-* 节点、嵌套顺序以及 <img> 该放在哪个位置；
     *     3) palette_role_map —— 直接从当前 scope themePalette 提取出来的 hex
     *        token 角色映射（surface / text / accent / cta_bg / cta_text /
     *        scrim 等），AI 必须用这些 hex 写 css_extra，从而保证审美与品牌一致。
     *
     * @param array<string,mixed> $visualContract
     * @param array<string,string> $themePalette role => #hex（来自 scope 主题色板）
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
        $isHeroPlacement = \in_array((string)($visualContract['slot_type'] ?? ''), ['hero_image'], true)
            || \in_array($usage, ['section_background_cover'], true)
            || \in_array($placement, ['background_layer'], true);
        $contractIdentity = \mb_strtolower(\implode(' ', \array_map('strval', [
            $visualContract['page_type'] ?? '',
            $visualContract['section_code'] ?? '',
            $visualContract['section_key'] ?? '',
            $visualContract['section_template'] ?? '',
            $visualContract['subject'] ?? '',
        ])));
        $isContactSupportContract = \preg_match('/\b(?:contact|support|privacy_contact|help|faq)\b|联系|客服|支持|隐私联系/iu', $contractIdentity) === 1;

        $requiredImgTemplate = '';
        $skeletonOutline = '';
        $cssStructuralHints = '';
        $roleMap = [];

        if ($required && $slotId !== '' && $finalUrl !== '') {
            $imgClass = $isHeroPlacement ? 'pb-c-hero-img' : 'pb-c-img';
            $requiredImgTemplate = "<img class='{$imgClass}' src='"
                . \htmlspecialchars($finalUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . "' alt='REPLACE_WITH_LOCALIZED_ALT' data-pb-ai-image-role='generated-asset' data-pb-ai-asset-slot='"
                . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . "'>";

            $roleMap = $this->buildPaletteRoleMapFromThemePalette($themePalette, $isHeroPlacement);

            if ($isHeroPlacement) {
                $skeletonOutline = "section.pb-c-root\n"
                    . "  img.pb-c-hero-img  <-- 这里粘贴 REQUIRED_HTML_IMG_TO_COPY_VERBATIM\n"
                    . "  div.pb-c-scrim     <-- 覆盖在 img 之上的渐变/纯色蒙层\n"
                    . "  div.pb-c-inner\n"
                    . "    div.pb-c-copy\n"
                    . "      h2.pb-c-title\n"
                    . "      p.pb-c-text\n"
                    . "      div.pb-c-action\n"
                    . "        div.pb-c-cta";
                $cssStructuralHints = "结构层 CSS 必填规则（颜色由 palette_role_map 决定，禁止凭空发明色值）：\n"
                    . "  .pb-c-root: position:relative; overflow:hidden; min-height:520px; padding:72px 24px; box-sizing:border-box; background= palette_role_map.surface 或 linear-gradient(palette_role_map.surface->palette_role_map.surface_alt)\n"
                    . "  .pb-c-hero-img: position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:center\n"
                    . "  .pb-c-scrim: position:absolute; inset:0; background= palette_role_map.scrim (含 rgba 或 linear-gradient)\n"
                    . "  .pb-c-inner: position:relative; z-index:1; max-width:1180px; margin:0 auto; display:flex; align-items:center; min-height:420px\n"
                    . "  .pb-c-copy: max-width:620px; padding:32px; border-radius:24px; background= palette_role_map.copy_panel_bg; color= palette_role_map.copy_panel_text; box-shadow:0 28px 80px palette_role_map.shadow\n"
                    . "  .pb-c-title: margin:0 0 16px; font-size: clamp(32px, 5vw, 52px); line-height:1.1; color= palette_role_map.copy_panel_text\n"
                    . "  .pb-c-text: margin:0 0 22px; line-height:1.7; color= palette_role_map.muted_text\n"
                    . "  .pb-c-cta: display:inline-flex; width:auto; max-width:280px; padding:12px 20px; border-radius:999px; background= palette_role_map.cta_bg; color= palette_role_map.cta_text; transition:transform .2s, box-shadow .2s\n"
                    . "  .pb-c-cta:hover: transform:translateY(-2px); box-shadow:0 12px 24px palette_role_map.shadow";
            } else {
                $skeletonOutline = "section.pb-c-root\n"
                    . "  div.pb-c-inner\n"
                    . "    div.pb-c-media\n"
                    . "      img.pb-c-img    <-- 这里粘贴 REQUIRED_HTML_IMG_TO_COPY_VERBATIM\n"
                    . "    div.pb-c-copy\n"
                    . "      h2.pb-c-title\n"
                    . "      p.pb-c-text\n"
                    . ($isContactSupportContract
                        ? "      div.pb-c-cards\n"
                            . "        div.pb-c-card > strong + span\n"
                            . "        div.pb-c-card > strong + span\n"
                            . "        div.pb-c-card > strong + span\n"
                        : '')
                    . "      div.pb-c-action\n"
                    . "        div.pb-c-cta"
                    . ($isContactSupportContract ? "\n        small.pb-c-note" : '');
                $cssStructuralHints = "结构层 CSS 必填规则（颜色由 palette_role_map 决定，禁止凭空发明色值）：\n"
                    . "  .pb-c-root: position:relative; overflow:hidden; padding:72px 24px; box-sizing:border-box; background= palette_role_map.surface\n"
                    . "  .pb-c-inner: max-width:1180px; margin:0 auto; display:flex; flex-wrap:wrap; gap:32px; align-items:center\n"
                    . "  .pb-c-media: flex:1 1 360px; min-width:0; overflow:hidden; border-radius:24px; box-shadow:0 24px 64px palette_role_map.shadow\n"
                    . "  .pb-c-img: display:block; width:100%; height:360px; object-fit:cover; object-position:center\n"
                    . "  .pb-c-copy: flex:1 1 320px; min-width:0\n"
                    . "  .pb-c-title: margin:0 0 16px; font-size: clamp(28px, 4vw, 42px); line-height:1.1; color= palette_role_map.text\n"
                    . "  .pb-c-text: margin:0 0 22px; line-height:1.7; color= palette_role_map.muted_text\n"
                    . ($isContactSupportContract
                        ? "  .pb-c-cards: display:flex; flex-wrap:wrap; gap:14px; margin:0 0 22px\n"
                            . "  .pb-c-card: flex:1 1 160px; min-width:0; padding:16px; border-radius:18px; background= palette_role_map.surface_alt; box-shadow=0 12px 30px palette_role_map.shadow\n"
                        : '')
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
     * 从 scope themePalette 提取 hero / 非 hero 场景需要的角色色字典。
     *
     * 不再返回任何「与品牌无关的兜底色」；palette 为空时返回空数组，由 prompt
     * 路径告知 AI 必须从 CTX_CONFIRMED_THEME 现场推导，而不是引用任何模板色。
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

        $ctaBg = $accent !== '' ? $accent : ($primary !== '' ? $primary : $text);
        $ctaText = $ctaBg !== '' ? $this->resolveReadableTextColor($ctaBg, $surface !== '' ? $surface : $text) : '';

        $copyPanelBg = $isHero ? ($surface !== '' ? $surface : $primary) : ($surfaceAlt !== '' ? $surfaceAlt : $surface);
        $copyPanelText = $copyPanelBg !== '' ? $this->resolveReadableTextColor($copyPanelBg, $text) : $text;

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
        $requiredAssetContract = ($required && $slotId !== '' && $finalUrl !== '')
            ? $this->buildVerifiedAssetPromptContract([$slotId => $finalUrl])
            : '';
        $requiredImgTemplate = (string)($artifacts['img_template'] ?? '');
        $skeletonOutline = (string)($artifacts['skeleton_outline'] ?? '');
        $cssStructuralHints = (string)($artifacts['css_structural_hints'] ?? '');
        $roleMap = \is_array($artifacts['palette_role_map'] ?? null) ? $artifacts['palette_role_map'] : [];
        $roleMapLine = $roleMap === []
            ? ''
            : '{' . \implode(', ', \array_map(static fn(string $k, string $v): string => $k . '=' . $v, \array_keys($roleMap), \array_values($roleMap))) . '}';
        if ($required && $slotId !== '' && $finalUrl === '') {
            return "Block visual contract:\n"
                . "- visual_contract: " . $this->jsonEncodeForPrompt($visualContract, 1600) . "\n"
                . "- image_required: yes\n"
                . "- Pending required image slot {$slotId}: a real generated asset must be produced or reused before final component output. CSS-only media, SVG drawings, stock URLs, and placeholder image services do not satisfy this contract.\n"
                . "- If a later FINAL REQUIRED IMAGE CONTRACT supplies an exact image URL/template, that later contract wins and the <img> binding is mandatory. If no verified final_url/template is available, fail this block instead of inventing a visual asset.\n";
        }

        return "Block visual contract:\n"
            . "- visual_contract: " . $this->jsonEncodeForPrompt($visualContract, 1600) . "\n"
            . "- image_required: " . ($required ? 'yes' : 'no') . "\n"
            . ($required
                ? "- Required image slot {$slotId}: use the exact generated asset for this block. Usage={$usage}; placement={$placement}; subject={$subject}; style={$style}.\n"
                    . ($finalUrl !== '' ? "- Required final_url for this slot: {$finalUrl}\n" : '')
                    . ($requiredImgTemplate !== '' ? "- REQUIRED_HTML_IMG_TO_COPY_VERBATIM: {$requiredImgTemplate}\n" : '')
                    . ($skeletonOutline !== '' ? "- REQUIRED_ROLE_OUTLINE (required roles/classes, not a byte-for-byte skeleton; additional scoped wrappers are allowed only when they improve this block and remain valid):\n{$skeletonOutline}\n" : '')
                    . ($roleMapLine !== '' ? "- REQUIRED_PALETTE_ROLE_MAP (HARD：css_extra 中所有颜色必须来自该字典；禁止凭空发明色值):\n{$roleMapLine}\n" : "- REQUIRED_PALETTE_ROLE_MAP 当前 scope 未提供 themePalette，请直接从 CTX_CONFIRMED_THEME.palette 中提取 hex token，禁止使用任何兜底色（#111827 / #f59e0b 等都属于无效模板色）。\n")
                    . ($cssStructuralHints !== '' ? "- REQUIRED_CSS_ROLE_CONTRACT (style required roles using palette role values; layout rhythm and composition remain design-owned by this block):\n{$cssStructuralHints}\n" : '')
                    . $requiredAssetContract
                    . "- Required slot HTML rule: the exact REQUIRED_HTML_IMG_TO_COPY_VERBATIM tag must appear in html_content. You may only replace the alt text with localized visitor alt text. Do not remove or rename class, src, data-pb-ai-image-role, or data-pb-ai-asset-slot. The validator checks html_content, not css_extra, so CSS background-image alone will fail.\n"
                    . "- Required structure rule: html_content 必须包含 REQUIRED_ROLE_OUTLINE 中的核心角色与 exact image binding；允许更精致的品牌化构图，但不得删除 required image、root、copy/title/text/CTA 等核心角色。可见文本与 alt 必须本地化、与品牌相关，禁止保留 REPLACE_WITH_LOCALIZED_ALT 占位字符串。\n"
                    . "- Required palette rule: css_extra 必须使用 REQUIRED_PALETTE_ROLE_MAP 中的 hex（至少 4 个不同角色），禁止以下硬编码模板色：#111827、#f59e0b、#92400e、#f8fafc、#0f172a、#cbd5e1（这些是历史模板，已被禁用）。\n"
                    . "- Required URL copy rule: do not type, infer, concatenate, shorten, or normalize the image path. Do not build a path from slot_id, target_domain, filename, or folder patterns. Paste the src value already present inside REQUIRED_HTML_IMG_TO_COPY_VERBATIM. Do not use css_extra url(...) for this asset.\n"
                    . "- Required URL anti-memory rule: never write `/pub/media/page-build/ai-generated/` paths from memory. Copy the src exactly from the copied template. If the copied template is relative, keep it relative; if it includes a domain, keep the entire domain segment exactly as shown. Any missing slash, domain fragment, dot, or hash character is invalid.\n"
                    . "- Required slot placement rule: place that copied <img> before overlay/text layers inside this component's root wrapper or first media wrapper. If the block is hero/background_cover, make that same <img> the full-cover media layer with CSS position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:center. Do not satisfy this with a CSS background-image, side thumbnail, or decorative second image.\n"
                    . "- Required slot self-check: before returning JSON, search html_content for the exact final_url and exact slot_id. If either is absent from an <img> tag with data-pb-ai-image-role='generated-asset', rewrite the output. Do not rely on section config, CSS url(...), comments, alt text, or prose to satisfy the image contract.\n"
                    . "- If the slot is hero/background_cover and the user's latest block-adjustment instruction does not explicitly request another composition, build the block as a real 1920x750-style banner: full-cover image layer, solid scrim/veil overlay, floating content container. A side thumbnail or cartoon-like illustration panel is invalid as the default baseline.\n"
                : "- Optional image slot: do not invent image URLs. If no verified generated image is supplied, design with CSS-only motif/pseudo-elements; no <svg> and no placeholder service.\n");
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
        foreach ([
            $this->resolveBuildPlanTaskRoot($scope),
            [
                'theme_design' => \is_array($scope['theme_design'] ?? null) ? $scope['theme_design'] : [],
                'theme_style' => \is_array($scope['theme_style'] ?? null) ? $scope['theme_style'] : [],
                'palette' => \is_array($scope['palette'] ?? null) ? $scope['palette'] : [],
            ],
            \is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [],
            \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [],
            \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [],
        ] as $candidate) {
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
            . "- Available variables: \$blog_posts, \$blog_categories, \$recent_posts, \$related_posts, \$current_post, \$current_category, \$category_posts.\n"
            . "- {$roleHint}\n"
            . "- Example post data: " . \json_encode($postPreview, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- Example category data: " . \json_encode($categoryPreview, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- Use loops over real variables such as foreach ((\$blog_posts ?? []) as \$post); do not hardcode fake posts.\n";
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

        $defaultConfig = $this->applyBuildPlanDefaults($defaultConfig, $this->resolveSharedBuildPlanTask($scope, 'header'), $locale, $scope);
        $defaultConfig = $this->pinCustomerSiteTitleOnSharedDefaultConfig($defaultConfig, $websiteProfile, $scope, $locale);
        $defaultConfig = $this->normalizeSharedDefaultConfigLinksAgainstRouteContract($defaultConfig, $scope, $locale);
        $defaultConfig['logo.image'] = $effectiveLogo;
        $defaultConfig['logo.url'] = $effectiveLogo;
        $defaultConfig['identity.shared_logo_asset'] = $effectiveLogo;
        $defaultConfig['runtime.shared_region'] = 'header';
        $defaultConfig['runtime.content_locale'] = $locale;

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

        $defaultConfig = $this->applyBuildPlanDefaults($defaultConfig, $this->resolveSharedBuildPlanTask($scope, 'footer'), $locale, $scope);
        $defaultConfig = $this->pinCustomerSiteTitleOnSharedDefaultConfig($defaultConfig, $websiteProfile, $scope, $locale);
        $defaultConfig = $this->normalizeSharedDefaultConfigLinksAgainstRouteContract($defaultConfig, $scope, $locale);
        $defaultConfig['brand.logo'] = $effectiveLogo;
        $defaultConfig['identity.shared_logo_asset'] = $effectiveLogo;
        $defaultConfig['runtime.shared_region'] = 'footer';
        $defaultConfig['runtime.content_locale'] = $locale;

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
        $isHeroSectionTpl = \in_array($sectionTpl, ['hero', 'banner'], true);

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
            $resolvedImageSlotId = $this->resolveSectionAssetSlotId($scope, $sectionImageUrl);
            $sectionImageSlotId = $resolvedImageSlotId !== '' ? $resolvedImageSlotId : $sectionImageSlotId;
            if ($sectionImageSlotId === '') {
                $sectionImageSlotId = 'page:' . $pageType . ':' . \str_replace('/', '-', (string)($section['code'] ?? ''));
            }
        }
        $defaultConfig = \array_replace($defaultConfig, $this->resolveThemeStyleDefaults($scope, 'content'));

        $buildPlanTask = $this->resolveSectionBuildPlanTask(
            $scope,
            $pageType,
            (string)($section['code'] ?? ''),
            (string)($section['key'] ?? '')
        );

        $defaultConfig = $this->applyBuildPlanDefaults($defaultConfig, $buildPlanTask, $locale, $scope);
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

        // 寮鸿濂戠害锛氭妸褰撳墠 section 鐨勬ā鏉?椤甸潰/璧勪骇绛夊厓淇℃伅鍐欏叆 runtime.* 鍐呴儴閿紝
        // stub/fallback 鐢ㄥ畠鐩存帴鍛戒腑姝ｇ‘鍙樹綋骞剁湡姝ｄ娇鐢?verified_assets锛岃€屼笉鏄€€鍖栦负鍗犱綅 SVG銆?        // runtime.* 鍓嶇紑浼氬湪 stripInternalComponentConfig 涓鍓旈櫎锛屼笉浼氭薄鏌撴渶缁堜繚瀛樼殑缁勪欢閰嶇疆銆?        $defaultConfig['runtime.section_template'] = (string)($section['template'] ?? '');
        $defaultConfig['runtime.section_page_type'] = $pageType;
        $defaultConfig['runtime.section_code'] = (string)($section['code'] ?? '');
        $defaultConfig['runtime.section_key'] = (string)($section['key'] ?? '');
        $defaultConfig['runtime.task_key'] = (string)($buildPlanTask['task_key'] ?? '');
        $defaultConfig['runtime.content_locale'] = $locale;
        if (\trim((string)($defaultConfig['runtime.content_copy_rows'] ?? '')) === '') {
            $sectionRows = $this->resolveExecutionBlueprintSectionRows($scope, $pageType, $section);
            if ($sectionRows !== []) {
                $defaultConfig['runtime.content_copy_rows'] = \json_encode(
                    $sectionRows,
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
                );
            }
        }
        if ($sectionImageSlotId !== '') {
            $defaultConfig['runtime.section_image_slot_id'] = $sectionImageSlotId;
        }
        if ($sectionImageUrl !== '') {
            $defaultConfig['runtime.section_image_url'] = $sectionImageUrl;
            $defaultConfig['runtime.section_image_alt'] = $sectionImageAlt;
        }

        return $defaultConfig;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $section
     * @return list<array{field:string,copy:string}>
     */
    private function resolveExecutionBlueprintSectionRows(array $scope, string $pageType, array $section): array
    {
        $code = \strtolower(\str_replace(['/', '_'], '-', \trim((string)($section['code'] ?? ''))));
        $key = \strtolower(\str_replace('_', '-', \trim((string)($section['key'] ?? ''))));
        $blocks = $this->resolveExecutionBlueprintBlocksForPage($scope, $pageType);
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockKey = \strtolower(\str_replace('_', '-', \trim((string)($block['block_key'] ?? $block['key'] ?? ''))));
            if ($blockKey === '') {
                continue;
            }
            if ($key !== '' && $key !== $blockKey && !\str_contains($code, $blockKey)) {
                continue;
            }
            $description = $this->clipText($this->sanitizeVisibleCopy((string)($block['content'] ?? $block['goal'] ?? '')), 180);
            $fieldPlan = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
            return $this->buildExecutionBlueprintContentCopyRows(
                $this->pickExecutionBlueprintBlockLabel($block, $fieldPlan, $blockKey),
                $description,
                $fieldPlan
            );
        }

        return [];
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
     * 鍙嶅悜鏌ユ壘锛氱敤 final_url 鎵惧洖 manifest 涓殑 slot_id銆?     * @param array<string,mixed> $scope
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

    /**
     * @param array<string,mixed> $scope
     * @return list<string>
     */
    private function collectStageOneVisibleSamplesForPage(array $scope, string $pageType): array
    {
        $samples = [];
        $pages = \is_array($scope['execution_blueprint']['pages'] ?? null) ? $scope['execution_blueprint']['pages'] : [];
        $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
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
            $scope['build_plan_v2'] ?? null,
            $scope['plan_projection'] ?? null,
            $scope['content_manifest'] ?? null,
            $scope['build_blueprint'] ?? null,
            $scope['build_tasks'] ?? null,
            $scope['execution_blueprint'] ?? null,
            $scope['plan_json'] ?? null,
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
    private function resolveBuildPlanTaskRoot(array $scope): array
    {
        return $this->buildPlanTaskRootFromBuildBlueprint($scope);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildPlanTaskRootFromBuildBlueprint(array $scope): array
    {
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        if ((string)($buildBlueprint['source'] ?? '') !== 'build_plan_v2') {
            return $this->buildPlanTaskRootFromExecutionBlueprint($scope);
        }

        $sharedTasks = [];
        $pageTasks = [];
        foreach (\is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [] as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $pageType = \trim((string)($task['page_type'] ?? ''));
            $taskType = \trim((string)($task['task_type'] ?? ''));
            if ($pageType === '' || $taskType === 'shared_component') {
                $sharedTasks[] = $task;
                continue;
            }
            $pageTasks[$pageType][] = $task;
        }

        if ($sharedTasks === [] && $pageTasks === []) {
            return [];
        }

        return [
            'signature' => (string)($buildBlueprint['build_plan_signature'] ?? $buildBlueprint['signature'] ?? ''),
            'shared_tasks' => $sharedTasks,
            'page_tasks' => $pageTasks,
        ];
    }

    /**
     * The refactored stage-1 blueprint is the source of truth for generation.
     * Convert its page_plans/shared chrome shape into the task root consumed by
     * section prompts and default-config derivation.
     *
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildPlanTaskRootFromExecutionBlueprint(array $scope): array
    {
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        if ($executionBlueprint === []) {
            return [];
        }

        $sharedPromptContext = $this->normalizeSharedPromptContextFromExecutionBlueprint($scope);
        $themeContext = $this->resolveExecutionBlueprintThemeContext($scope);
        $contentLocale = $this->resolveScopePrimaryLocale($scope);
        $sharedTasks = [];
        foreach (['header', 'footer'] as $region) {
            $plan = $this->resolveExecutionBlueprintSharedPlan($scope, $region);
            if ($plan === []) {
                continue;
            }
            $sharedTasks[] = [
                'task_key' => 'shared:' . $region,
                'task_type' => 'shared_component',
                'region' => $region,
                'label' => $this->pickString($plan['title'] ?? null, $region),
                'sort_order' => $region === 'header' ? 10 : 20,
                'plan_context' => [
                    'block_goal' => (string)($plan['goal'] ?? ''),
                    'field_plan' => $this->sanitizeExecutionBlueprintFieldPlanForGeneration(
                        \is_array($plan['field_plan'] ?? null) ? $plan['field_plan'] : [],
                        $contentLocale
                    ),
                ],
                'task_script' => [
                    'story_goal' => (string)($plan['goal'] ?? ''),
                    'field_content_requirements' => $this->buildSharedPlanFieldRequirements($plan, $region),
                ],
                'block_task' => [
                    'task_goal' => (string)($plan['goal'] ?? ''),
                    'content_plan' => [
                        'content_copy' => $this->buildSharedPlanContentCopyRows($plan, $region),
                    ],
                    'style_plan' => \is_array($plan['style_brief'] ?? null) ? $plan['style_brief'] : [],
                    'realtime_content' => \is_array($plan['realtime_content'] ?? null) ? $plan['realtime_content'] : [],
                ],
                'runtime_context' => [
                    'theme_context_snapshot' => $themeContext,
                    'shared_prompt_context' => $sharedPromptContext,
                    'content_locale' => $contentLocale,
                ],
            ];
        }

        $pagePlans = \is_array($executionBlueprint['page_plans'] ?? null) ? $executionBlueprint['page_plans'] : [];
        if ($pagePlans === [] && \is_array($executionBlueprint['pages'] ?? null)) {
            $pagePlans = $executionBlueprint['pages'];
        }

        $pageTasks = [];
        foreach (\array_keys($pagePlans) as $pageType) {
            if (!\is_string($pageType) || \trim($pageType) === '') {
                continue;
            }
            foreach ($this->buildSectionTasksFromExecutionBlueprint($pageType, $scope) as $task) {
                $pageTasks[$pageType][] = $task;
            }
        }

        if ($sharedTasks === [] && $pageTasks === []) {
            return [];
        }

        return [
            'signature' => (string)($executionBlueprint['signature'] ?? ''),
            'shared_tasks' => $sharedTasks,
            'page_tasks' => $pageTasks,
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveSharedBuildPlanTask(array $scope, string $region): array
    {
        $region = \trim($region);
        if ($region === '') {
            return [];
        }

        $root = $this->resolveBuildPlanTaskRoot($scope);
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
    private function resolveSectionBuildPlanTask(array $scope, string $pageType, string $sectionCode, string $sectionKey = ''): array
    {
        $root = $this->resolveBuildPlanTaskRoot($scope);
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
     * @param array<string,mixed> $buildPlanTask
     */
    private function buildBuildPlanTaskPromptAddon(array $buildPlanTask, string $contextLabel, array $scope = []): string
    {
        if ($buildPlanTask === []) {
            throw new \RuntimeException('Build prompt contract failed: missing stage-2 build-plan task context for ' . $contextLabel . '; scope-level prompt fallback is forbidden.');
        }

        $planContext = \is_array($buildPlanTask['plan_context'] ?? null) ? $buildPlanTask['plan_context'] : [];
        $taskScript = \is_array($buildPlanTask['task_script'] ?? null) ? $buildPlanTask['task_script'] : [];
        $implementationContract = \is_array($buildPlanTask['implementation_contract'] ?? null) ? $buildPlanTask['implementation_contract'] : [];
        $runtimeContext = \is_array($buildPlanTask['runtime_context'] ?? null) ? $buildPlanTask['runtime_context'] : [];
        $blockTask = \is_array($buildPlanTask['block_task'] ?? null) ? $buildPlanTask['block_task'] : [];
        $themeContext = \is_array($runtimeContext['theme_context_snapshot'] ?? null) ? $runtimeContext['theme_context_snapshot'] : [];
        if ($themeContext === [] && \is_array($buildPlanTask['theme_context_snapshot'] ?? null)) {
            $themeContext = $buildPlanTask['theme_context_snapshot'];
        }
        $sharedPromptContext = \is_array($runtimeContext['shared_prompt_context'] ?? null) ? $runtimeContext['shared_prompt_context'] : [];
        if ($sharedPromptContext === [] && \is_array($buildPlanTask['shared_prompt_context'] ?? null)) {
            $sharedPromptContext = $buildPlanTask['shared_prompt_context'];
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
        $pageDesignPlan = \is_array($planContext['page_design_plan'] ?? null)
            ? $planContext['page_design_plan']
            : (\is_array($stylePlan['page_design_plan'] ?? null) ? $stylePlan['page_design_plan'] : []);
        $contentLocale = \trim((string)($runtimeContext['content_locale'] ?? $this->resolveScopePrimaryLocale($scope)));
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
            ? "- stage3 locale gate: source_of_truth_locale={$contentLocale} ({$localeHint}). build-plan text is intent only; rewrite any non-{$contentLocale} planned sentence before it becomes visible copy.\n"
            : '';
        $verifiedAssets = $this->extractVerifiedAssetsForBuildPlanTask($scope, $buildPlanTask);
        if ($verifiedAssets === [] && \is_array($runtimeContext['asset_manifest'] ?? null)) {
            $verifiedAssets = $this->extractVerifiedAssetsForBuildPlanTask(
                ['asset_manifest' => $runtimeContext['asset_manifest']],
                $buildPlanTask
            );
        }
        $verifiedAssetRule = $verifiedAssets !== []
            ? "- verified_assets: " . $this->jsonEncodeForPrompt($verifiedAssets, 1800) . "\n"
                . "- HARD CONTRACT: every verified_asset final_url for this section MUST appear as a real editable <img> in html_content by copying the concrete template below. Do not skip any. Unused generated images waste API tokens.\n"
                . "- Rules: use the supplied final_url value without changing it; match the asset by slot_id context. If no asset matches this section, render CSS-only decorative structure; never use <svg>.\n"
                . "- Slot placement contract: place the editable <img> for the matching slot inside this component's root wrapper before decorative-only layers. For non-hero sections, use it as a media card, portrait/avatar rail, proof image, or editorial visual; never omit it because the section is text-heavy.\n"
                . "- Exact tag contract: html_content must contain a real <img> tag copied from copyable_verified_asset_img_template. That same <img> must keep the concrete src, data-pb-ai-asset-slot, and data-pb-ai-image-role='generated-asset' values from the copied template. You may change only alt text and add a component-prefixed class.\n"
                . "- Required slot shape: for hero/background designs, make that same copied <img> a cover layer with CSS object-fit/absolute/inset/width/height, then place overlay text above it; do not replace the editable <img> with CSS background-image only. Add a component-prefixed hero image class to the <img> and style the exact same selector with position:absolute; inset:0; width:100%; height:100%; object-fit:cover.\n"
                . "- Image contract self-check: before returning JSON, verify that html_content includes every final_url and every matching slot_id inside an <img> tag. If a final_url appears only in CSS, config, comments, or text, the output is invalid.\n"
                . $this->buildVerifiedAssetPromptContract($verifiedAssets)
            : "- verified_assets: []\n"
                . "- NO_VERIFIED_IMAGE_MODE: no verified real image URL is available for this task. html_content must not contain <img>, src=, data-pb-ai-image-role, data-pb-ai-asset-slot, CSS url(...), `/pub/media/`, stock-photo URLs, or guessed file paths.\n"
                . "- CSS-only hero/media rule: if this is a hero/banner/media block, satisfy the HERO_ROLE_OUTLINE from the component-specific contract. The `.pb-c-media` element must contain `.pb-c-media-stage`, `.pb-c-media-subject`, `.pb-c-media-detail`, and `.pb-c-media-label` children; never leave `.pb-c-media` empty. Style `.pb-c-media` with position:absolute; inset:0; and a confirmed palette hex background; style `.pb-c-media-stage`, `.pb-c-media-subject`, `.pb-c-media-detail`, `.pb-c-media-label`, `.pb-c-motif`, and `.pb-c-orbit` as structured editorial/product surfaces, not generic circles/blobs; the label text must name a concrete subject from the approved brief; style `.pb-c-overlay` with a confirmed dark/brand overlay token and opacity; and style `.pb-c-text-panel` with a readable confirmed palette panel background.\n"
                . "- verified asset rule: render visual media as CSS-only shapes/pseudo-elements and do not invent image URLs or use <svg>.\n";
        $ctaResponsiveOverride = "- CTA responsive override: if any frozen task/context/design field says CTA, button, or CTA/form controls should fill available width, apply full-width behavior to bands, rows, forms, inputs, or mobile containers first. Prefer action/actions for wrappers so wrapper CSS is not mistaken for button CSS. The actual CTA button should remain a recognizable button in the default layout and must not be styled as a desktop page-width bar.\n";

        return "Frozen Stage-2 task context for this {$contextLabel} (use these fields only; do not read conversation history or broad scope fallbacks):\n"
            . "1 task_identity: " . $this->jsonEncodeForPrompt([
                'task_key' => (string)($buildPlanTask['task_key'] ?? ''),
                'page_type' => (string)($buildPlanTask['page_type'] ?? $planContext['page_type'] ?? ''),
                'block_key' => (string)($buildPlanTask['block_key'] ?? $planContext['block_key'] ?? ''),
                'section_code' => (string)($buildPlanTask['section_code'] ?? $planContext['section_code'] ?? ''),
                'context_refs' => $contextRefs,
            ], 900) . "\n"
            . "2 site_context: " . $this->jsonEncodeForPrompt([
                'site' => $siteContext,
                'shared_prompt_context' => $sharedPromptContext,
                'policy_context' => $policyContext,
            ], 1600) . "\n"
            . "3 theme_context: " . $this->jsonEncodeForPrompt([
                'theme_context_snapshot' => $themeContext,
                'stage1_theme_summary' => (string)($planContext['stage1_theme_summary'] ?? ''),
                'stage1_style_direction' => (string)($planContext['stage1_style_direction'] ?? ''),
            ], 1400) . "\n"
            . "4 page_context: " . $this->jsonEncodeForPrompt([
                'page_goal' => (string)($planContext['page_goal'] ?? ''),
                'page_flow_role' => (string)($planContext['page_flow_role'] ?? $stylePlan['page_flow_role'] ?? ''),
                'page_design_plan' => $pageDesignPlan,
            ], 1600) . "\n"
            . "5 current_block_context: " . $this->jsonEncodeForPrompt([
                'block_goal' => (string)($planContext['block_goal'] ?? ''),
                'stage1_block_content' => (string)($planContext['stage1_block_content'] ?? ''),
                'story_goal' => (string)($taskScript['story_goal'] ?? ''),
                'stage3_directive' => (string)($taskScript['stage3_directive'] ?? ''),
                'content_plan' => $contentPlan,
                'style_plan' => $stylePlan,
                'implementation_detail' => (string)($blockTask['implementation_detail'] ?? ''),
                'field_content_requirements' => $fieldRequirements,
                'data_contract' => \array_replace_recursive($implementationDataContract, $taskScriptDataContract),
                'acceptance' => $acceptance,
            ], 2600) . "\n"
            . "6 skill_and_reference_context: " . $this->jsonEncodeForPrompt([
                'skill_context' => $skillContext,
                'reference_context' => $referenceContext,
                'design_tags' => \is_array($blockTask['design_tags'] ?? null) ? $blockTask['design_tags'] : [],
                'realtime_content' => \is_array($blockTask['realtime_content'] ?? null) ? $blockTask['realtime_content'] : [],
            ], 1300) . "\n"
            . "7 asset_context: " . $this->jsonEncodeForPrompt([
                'verified_assets' => $verifiedAssets,
                'asset_contract' => $verifiedAssets === []
                    ? 'no verified assets for this task; pending/unresolved asset slots are not legal image URLs'
                    : 'use only the verified_assets listed here',
            ], 1400) . "\n"
            . "- design execution rule: apply page_design_plan and theme_context first, then current_block_context. Generate only this task's block, not why the block exists.\n"
            . "- CSS execution rule: write fewer complete selectors instead of many fragile selectors. If a decoration is hard to express safely, omit the decoration and keep the layout valid.\n"
            . "- responsive execution rule: normal grid/flex flow only; stack at <=900px and use one column at <=420px with min-width:0 and max-width:100% for layout containers, media, cards, forms, and inputs only. Apply the compact CTA override only to the actual clickable CTA button/anchor element; full-width bands, layout containers, and form wrappers may remain responsive. Prefer action/actions for wrappers so wrapper CSS is not mistaken for button CSS.\n"
            . $ctaResponsiveOverride
            . "- build-plan language rule: build-plan text is intent only. Rewrite it into final visitor copy in source_of_truth_locale before placing it in HTML.\n"
            . $stage3LocaleRule
            . $verifiedAssetRule;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $buildPlanTask
     * @return array<string,mixed>
     */
    private function applyBuildPlanDefaults(array $defaultConfig, array $buildPlanTask, string $locale = '', array $scope = []): array
    {
        if ($buildPlanTask === []) {
            return $defaultConfig;
        }

        $taskScript = \is_array($buildPlanTask['task_script'] ?? null) ? $buildPlanTask['task_script'] : [];
        $blockTask = \is_array($buildPlanTask['block_task'] ?? null) ? $buildPlanTask['block_task'] : [];
        $planContext = \is_array($buildPlanTask['plan_context'] ?? null) ? $buildPlanTask['plan_context'] : [];
        $fieldRequirements = \is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [];
        foreach ($fieldRequirements as $requirement) {
            if (!\is_array($requirement)) {
                continue;
            }
            $field = $this->normalizeBuildPlanRequirementField($requirement['field'] ?? '');
            $isLinkField = \str_contains($field, 'navigation') || \str_contains($field, 'featured_links') || \str_contains($field, 'policy_links');
            $sample = $this->normalizeBuildPlanRequirementSample($requirement['sample'] ?? '', $isLinkField);
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
                $defaultConfig = $this->applyBuildPlanLinkFieldDefaults($defaultConfig, $field, $sample, $locale, $scope);
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

        $storyGoal = $this->sanitizeVisibleCopy((string)($taskScript['story_goal'] ?? ''));
        if ($locale !== '') {
            $storyGoal = $this->filterVisibleCopyForLocale($storyGoal, $locale);
        }
        if ($storyGoal !== '' && \trim((string)($defaultConfig['content.title'] ?? '')) === '') {
            $defaultConfig['content.title'] = $this->clipText($storyGoal, 72);
        }

        $defaultConfig = $this->applyBuildPlanContentPlanDefaults($defaultConfig, $blockTask, $locale);

        $visibleSummary = $this->resolveVisibleBuildTaskSummary($taskScript, $blockTask, $planContext);
        if ($locale !== '') {
            $visibleSummary = $this->filterVisibleCopyForLocale($visibleSummary, $locale);
        }
        if ($visibleSummary !== '' && \trim((string)($defaultConfig['content.description'] ?? '')) === '') {
            $defaultConfig['content.description'] = $this->clipText($visibleSummary, 180);
        }

        return $this->sanitizeDefaultConfigVisibleCopy(
            $this->applyBuildPlanDataContractDefaults($defaultConfig, $buildPlanTask, $locale),
            $locale
        );
    }

    /**
     * BuildPlan 样本不得覆盖客户填写的站点名称。
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
    private function applyBuildPlanContentPlanDefaults(array $defaultConfig, array $blockTask, string $locale = ''): array
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
            $field = $this->normalizeBuildPlanRequirementField($row['field'] ?? '');
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
                if ($current === '') {
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
    private function applyBuildPlanLinkFieldDefaults(array $defaultConfig, string $field, string $sample, string $locale = '', array $scope = []): array
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
     * @param array<string,mixed> $buildPlanTask
     * @return array<string,mixed>
     */
    private function applyBuildPlanDataContractDefaults(array $defaultConfig, array $buildPlanTask, string $locale = ''): array
    {
        foreach ($this->extractBuildPlanDataContractLines($buildPlanTask) as $line) {
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

    private function normalizeBuildPlanRequirementField(mixed $fieldRaw): string
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

    private function normalizeBuildPlanRequirementSample(mixed $sampleRaw, bool $isLinkField): string
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
        $template = \strtolower(\trim((string)($config['runtime.section_template'] ?? '')));
        if (\in_array($template, ['hero', 'banner'], true)) {
            return true;
        }

        $visualContract = $this->decodeRuntimeVisualContract($config);
        $slotType = \strtolower(\trim((string)($visualContract['slot_type'] ?? $visualContract['usage'] ?? '')));

        return \str_contains($slotType, 'hero') || \str_contains($slotType, 'banner');
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
        if (\in_array($normalized, ['棣栭〉', '涓婚〉', '鍏充簬鎴戜滑', '鍏充簬', 'home', 'home page', 'about', 'about page', 'about us'], true)) {
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
            'generated page plan',
            '优先沿用第一阶段',
            '第一阶段确认',
            '输出必须',
            '访客可见内容',
            '先讲清',
            '为什么值得继续浏览',
            '页面需要围绕',
            '围绕页面目标',
            '组织完整内容',
            '页面意图',
            '文案语气延续',
            '整体表达延续',
            '整体表达强调',
            '保持面向访客',
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
        if ($trimmed === '' || $trimmed !== \mb_strtolower($trimmed)) {
            return false;
        }
        if (\preg_match('/^[a-z0-9]+(?:[ _-]+[a-z0-9]+){1,6}$/', $trimmed) !== 1) {
            return false;
        }

        $tokens = \preg_split('/[ _-]+/', $trimmed) ?: [];
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
        if (\preg_match('/\s[-–—]\s*(?<copy>.+)$/u', $value, $matches) !== 1) {
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
            '访客看到',
            '访客可以',
            '信任感增强',
            '优先沿用第一阶段',
            '第一阶段确认',
            '输出必须',
            '访客可见内容',
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
            '/^(?:показать|представить|подчеркнуть|укрепить|сформировать|объяснить|рассказать|помочь|закрыть)\b.{0,120}[.!?。]?\s*$/iu',
            '/(?:这个|本|当前)?(?:页面|区块|模块|page|section|block).{0,24}(?:核心|关键|主要|key|core).{0,16}(?:亮点|卖点|重点|highlights?|selling\s+points?)/iu',
            '/(?:用|使用|通过|以|use|using|display|show|present).{0,28}(?:卡片层级|视觉层级|内容层级|card\s+hierarchy|visual\s+hierarchy).{0,90}(?:展示|呈现|强调|卖点|差异|信任|selling\s+points?|differences?|trust)/iu',
            '/(?:把|将|让|put|place|make).{0,22}(?:主行动|主要行动|主按钮|primary\s+action|main\s+action|cta).{0,60}(?:卡片|按钮|button|card).{0,60}(?:减少|降低|避免|reduce|avoid|hesitation|犹豫)/iu',
            '/(?:避免|不要|防止|avoid|do\s+not|don\'t).{0,36}(?:内容|视觉|区块|模块|blocks?|sections?|content).{0,48}(?:挤成|同一种视觉|相同视觉|same\s+visual|same\s+layout|feel\s+like\s+one)/iu',
            '/(?:访客|用户|visitors?|users?).{0,28}(?:看到|可以看到|see|can\s+review|can\s+verify|understand|ready\s+to).{0,100}(?:信任|下载|发布|证明|reviewable|before\s+publishing|proof|cta|action)/iu',
            '/(?:rewrite|render|use|provide|present|include|output)\s+(?:concrete|visitor-facing|download|category|trust|proof|cta|feature).{0,90}(?:copy|language|labels?|path|cards?)/iu',
            '/(?:首页|页面|区块|模块).{0,18}(?:讲清|说明|呈现|组织|承接).{0,80}(?:核心价值|主要亮点|下一步动作|页面目标|栏目方向|文章重点|继续阅读路径)/iu',
            '/(?:让|帮助).{0,16}(?:访客|用户).{0,80}(?:为什么|理解栏目方向|快速建立信任|继续深入浏览|发起联系前的犹豫)/iu',
            '/(?:文案语气|表达).{0,40}(?:延续|保持).{0,40}(?:品牌口吻|面向访客)/iu',
        ];
        foreach ($patterns as $pattern) {
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
     * @param array<string,mixed> $buildPlanTask
     * @return list<string>
     */
    private function extractBuildPlanDataContractLines(array $buildPlanTask): array
    {
        $sources = [];
        foreach (['task_script', 'implementation_contract'] as $rootKey) {
            $root = \is_array($buildPlanTask[$rootKey] ?? null) ? $buildPlanTask[$rootKey] : [];
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
        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        $refinements = \is_array($virtualPage['section_refinements'] ?? null) ? $virtualPage['section_refinements'] : [];

        if ($sectionCode !== '' && \is_scalar($refinements[$sectionCode] ?? null)) {
            return \trim((string)$refinements[$sectionCode]);
        }
        if ($fallbackKey !== '' && \is_scalar($refinements[$fallbackKey] ?? null)) {
            return \trim((string)$refinements[$fallbackKey]);
        }

        return '';
    }

    /**
     * HTML 杞ㄥ叡浜〉澶?椤佃剼寰皟锛氬彲鑳藉啓鍦?shared_component_refinements 鎴栧悇椤?section_refinements锛堝 *-site-header锛夈€?     *
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
        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        foreach ($virtualPages as $virtualPage) {
            if (!\is_array($virtualPage)) {
                continue;
            }
            $refinements = \is_array($virtualPage['section_refinements'] ?? null) ? $virtualPage['section_refinements'] : [];
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
        if ($current === '' || (!$contactIntent && ($this->isGenericConsultCtaLabel($current) || $this->isGenericCtaContactLabel($current)))) {
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
        if (\in_array($pageType, [Page::TYPE_BLOG_LIST, Page::TYPE_BLOG_CATEGORY, Page::TYPE_BLOG], true)
            && \preg_match('/\b(?:download|apk|app|install|newsletter|cta)\b/iu', $sectionNeedle) !== 1
        ) {
            return $this->localizeBuildText('explore_more', $locale);
        }
        if (\preg_match('/\b(?:reward|bonus|coin|coupon|promotion|offer|gift|prize|cashback)\b|奖励|金币|礼包|福利|优惠|活动/iu', $needle) === 1) {
            return $this->localizeBuildText('claim_bonus', $locale);
        }
        if (\preg_match('/\b(?:download|apk|app|install)\b|下载|安装/iu', $needle) === 1) {
            return $this->localizeBuildText('download_now', $locale);
        }
        if (\in_array($pageType, [Page::TYPE_BLOG_LIST, Page::TYPE_BLOG_CATEGORY, Page::TYPE_BLOG], true)
            || \preg_match('/\b(?:blog|article|guide|learn|resource|news|reading)\b|博客|攻略|学习|文章|资讯/iu', $needle) === 1
        ) {
            return $this->localizeBuildText('explore_more', $locale);
        }
        if (\preg_match('/\b(?:game|play|table|poker|rummy|blackjack|ludo|casino)\b|游戏|棋牌|玩法|畅玩/iu', $needle) === 1) {
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
        return \preg_match('/\b(?:contact|support|help|service|form|mail|phone|whatsapp|lead|inquiry|enquiry)\b|联系|客服|咨询|表单|电话|邮箱|帮助|支持/iu', $needle) === 1;
    }

    private function isGenericConsultCtaLabel(string $label): bool
    {
        return \preg_match('/\b(?:consult|contact us|enquire|inquire|talk to us|get in touch)\b|立即咨询|马上咨询|联系我们|联系客服|咨询/iu', $label) === 1;
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
        if (\preg_match('/\b(?:download|apk|app|install)\b|下载|安装/iu', $primaryNeedle) === 1) {
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

        $fallback = $this->firstAvailableRoutePath($scope, $locale, [Page::TYPE_CONTACT, Page::TYPE_BLOG_LIST, Page::TYPE_ABOUT, Page::TYPE_HOME]);

        return $fallback !== '' ? $fallback : '#contact';
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
                $scope['execution_blueprint']['shared_prompt_context']['site_display_name'] ?? null,
                $scope['execution_blueprint']['theme_context_snapshot']['site_display_name'] ?? null,
                $scope['plan_workbench']['confirmed']['shared_prompt_context']['site_display_name'] ?? null,
                $scope['plan_workbench']['confirmed']['theme_context_snapshot']['site_display_name'] ?? null,
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

    /**
     * @deprecated 浠呯敤浜庢棦鏈変緷璧栨鏌ワ紱鏂颁唬鐮佸繀椤昏蛋 {@see self::callAiOperation()}锛?     *             浠ラ伒瀹?unified-query-provider 瑙勮寖锛堟ā鍧楅棿閫氳繃 w_query('ai', ...) 瑙﹁揪 AI锛夈€?     */
    private function getAiService(): AiService
    {
        return $this->aiService ?? ObjectManager::getInstance(AiService::class);
    }

    private function getBuildTaskService(): AiSiteBuildTaskService
    {
        return ObjectManager::getInstance(AiSiteBuildTaskService::class);
    }

    private function getScopeCompatibilityService(): AiSiteScopeCompatibilityService
    {
        return $this->scopeCompatibilityService ?? ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
    }

    /**
     * 缁熶竴鐨勮法妯″潡 AI 璋冪敤鍏ュ彛锛?     *  - 鏋勯€犳敞鍏ヤ簡 {@see AiService} 鏃剁洿鎺ュ鐢紝鍏煎鏃㈡湁 mock 娴嬭瘯锛?     *  - 鍚﹀垯璧?`w_query('ai', $operation, $params)`锛岀敱 {@see \Weline\Ai\Extends\Module\Weline_Framework\Query\AiQueryProvider} 鎺ョ銆?     *
     * 鏀寔鐨?operation锛?     *  - generate(prompt, model_code?, scenario_code?, locale?, params?, user_id?, is_backend?) -> string
     *  - generateStream(prompt, on_chunk, model_code?, scenario_code?, locale?, params?) -> ['status'=>'fulfilled']
     *
     * @param array<string, mixed> $params
     */
    private function callAiOperation(string $operation, array $params): mixed
    {
        if (isset($params['prompt']) && \is_string($params['prompt'])) {
            $params['prompt'] = $this->getSkillRegistry()->prependPromptGuide($params['prompt'], 'stage3');
        }

        if ($this->aiService !== null) {
            return $this->dispatchAiOperationViaInjectedService($this->aiService, $operation, $params);
        }

        return w_query('ai', $operation, $params);
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
        foreach ([
            $scope['content_locale'] ?? null,
            $websiteProfile['content_locale'] ?? null,
            $scope['website_profile']['content_locale'] ?? null,
            $scope['execution_blueprint']['content_locale'] ?? null,
            $scope['plan_json']['content_locale'] ?? null,
            $scope['plan_json']['i18n']['content_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $scope['default_language'] ?? null,
            $websiteProfile['default_locale'] ?? null,
            $websiteProfile['default_language'] ?? null,
            $scope['website_profile']['default_locale'] ?? null,
            $scope['website_profile']['default_language'] ?? null,
            $websiteProfile['locales'][0] ?? null,
            $scope['website_profile']['locales'][0] ?? null,
            $scope['plan_generated_locale'] ?? null,
            $scope['plan_workbench']['confirmed']['plan_generated_locale'] ?? null,
            $scope['plan_structured']['plan_generated_locale'] ?? null,
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
            $this->normalizeSharedPromptContextFromExecutionBlueprint($scope),
            $this->extractSharedPromptContextFromTask($this->resolveSharedBuildPlanTask($scope, 'header')),
            $this->extractSharedPromptContextFromTask($this->resolveSharedBuildPlanTask($scope, 'footer')),
            \is_array($scope['execution_blueprint']['shared_prompt_context'] ?? null) ? $scope['execution_blueprint']['shared_prompt_context'] : [],
            \is_array($scope['plan_workbench']['confirmed']['shared_prompt_context'] ?? null) ? $scope['plan_workbench']['confirmed']['shared_prompt_context'] : [],
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
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function normalizeSharedPromptContextFromExecutionBlueprint(array $scope): array
    {
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        if ($executionBlueprint === []) {
            return [];
        }

        $headerPlan = $this->resolveExecutionBlueprintSharedPlan($scope, 'header');
        $footerPlan = $this->resolveExecutionBlueprintSharedPlan($scope, 'footer');
        $candidate = [];
        if ($headerPlan !== []) {
            $candidate['header_plan'] = $headerPlan;
        }
        if ($footerPlan !== []) {
            $candidate['footer_plan'] = $footerPlan;
        }
        foreach ([
            $headerPlan['content_locale'] ?? null,
            $footerPlan['content_locale'] ?? null,
            $executionBlueprint['content_locale'] ?? null,
            $scope['website_profile']['content_locale'] ?? null,
            $scope['website_profile']['default_locale'] ?? null,
            $scope['default_locale'] ?? null,
        ] as $localeCandidate) {
            if (\is_scalar($localeCandidate) && \trim((string)$localeCandidate) !== '') {
                $candidate['content_locale'] = \trim((string)$localeCandidate);
                break;
            }
        }

        return $candidate !== [] ? $this->normalizeSharedPromptContextCandidate($candidate) : [];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveExecutionBlueprintSharedPlan(array $scope, string $region): array
    {
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $sharedComponents = \is_array($executionBlueprint['shared_components'] ?? null) ? $executionBlueprint['shared_components'] : [];
        $candidates = $region === 'header'
            ? [
                $sharedComponents['header'] ?? null,
                $executionBlueprint['navigation_plan'] ?? null,
                $executionBlueprint['header_plan'] ?? null,
            ]
            : [
                $sharedComponents['footer'] ?? null,
                $executionBlueprint['footer_plan'] ?? null,
            ];

        foreach ($candidates as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveExecutionBlueprintThemeContext(array $scope): array
    {
        foreach ([
            $scope['execution_blueprint']['theme_context_snapshot'] ?? null,
            $scope['plan_workbench']['confirmed']['theme_context_snapshot'] ?? null,
            $scope['content_manifest']['theme_context_snapshot'] ?? null,
            $scope['plan_projection']['theme_context_snapshot'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
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
        return $fallback !== '' ? $fallback : 'Section';
    }

    private function filterVisibleCopyForLocale(string $value, string $locale): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        if ($locale !== '' && $this->isNonCjkLocale($locale) && $this->hasMeaningfulCjkContent($value)) {
            return '';
        }
        if ($locale !== '' && $this->isCjkLocale($locale) && $this->hasDominantLatinProseForCjkLocale($value)) {
            return '';
        }
        if ($locale !== '' && !$this->isEnglishLocale($locale) && $this->hasDominantLatinProseForNonEnglishLocale($value, $locale)) {
            return '';
        }
        if ($locale !== '' && !$this->isEnglishLocale($locale) && $this->containsEnglishBoilerplateVisibleCopy($value)) {
            return '';
        }
        if ($this->containsPlanningObservationCopy($value)) {
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
        if ($this->isRussianLocale($locale)) {
            return match ($pageType) {
                Page::TYPE_HOME => 'Главная',
                Page::TYPE_ABOUT => 'О нас',
                Page::TYPE_CONTACT => 'Контакты',
                Page::TYPE_BLOG_LIST, Page::TYPE_BLOG => 'Блог',
                Page::TYPE_PRIVACY_POLICY => 'Политика конфиденциальности',
                Page::TYPE_TERMS_OF_SERVICE => 'Условия использования',
                Page::TYPE_REFUND_POLICY => 'Политика возврата',
                Page::TYPE_SHIPPING_POLICY => 'Доставка',
                Page::TYPE_COOKIE_POLICY => 'Политика Cookie',
                default => '',
            };
        }

        if ($this->isChineseLocale($locale)) {
            return match ($pageType) {
                Page::TYPE_HOME => '首页',
                Page::TYPE_ABOUT => '关于我们',
                Page::TYPE_CONTACT => '联系我们',
                Page::TYPE_BLOG_LIST, Page::TYPE_BLOG => '博客',
                Page::TYPE_PRIVACY_POLICY => '隐私政策',
                Page::TYPE_TERMS_OF_SERVICE => '服务条款',
                Page::TYPE_REFUND_POLICY => '退款政策',
                Page::TYPE_SHIPPING_POLICY => '配送政策',
                Page::TYPE_COOKIE_POLICY => 'Cookie 政策',
                default => '',
            };
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
        if ($key === 'brand_summary' && !$this->isEnglishLocale($locale)) {
            return '';
        }
        if ($this->isRussianLocale($locale) && $key === 'brand_summary') {
            return 'Надёжный источник карточных игр и APK для игроков в Индии.';
        }
        if ($key === 'site_name_fallback') {
            if ($this->isRussianLocale($locale)) {
                return 'Сайт';
            }
            return $this->isChineseLocale($locale) ? "\u{7AD9}\u{70B9}" : 'Website';
        }

        if ($this->isRussianLocale($locale)) {
            return match ($key) {
                'policy_info' => 'Правовая информация',
                'featured_pages' => 'Основные разделы',
                'all_pages' => 'Все разделы',
                'all_rights_reserved' => 'Все права защищены.',
                'contact_us' => 'Связаться с нами',
                'explore_more' => 'Подробнее',
                'get_started' => 'Начать',
                'download_now' => 'Скачать сейчас',
                'claim_bonus' => 'Получить бонус',
                'start_playing' => 'Начать игру',
                default => $key,
            };
        }

        if ($this->isChineseLocale($locale)) {
            return match ($key) {
                'policy_info' => '政策信息',
                'featured_pages' => '重点页面',
                'all_pages' => '全部页面',
                'all_rights_reserved' => '保留所有权利。',
                'contact_us' => '联系我们',
                'explore_more' => '查看更多',
                'get_started' => '立即开始',
                'download_now' => "\u{7ACB}\u{5373}\u{4E0B}\u{8F7D}",
                'claim_bonus' => "\u{9886}\u{53D6}\u{91D1}\u{5E01}",
                'start_playing' => "\u{5F00}\u{59CB}\u{7545}\u{73A9}",
                default => $key,
            };
        }

        return match ($key) {
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
            default => $key,
        };
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
        $raw = \is_array($scope['page_route_contract'] ?? null)
            ? $scope['page_route_contract']
            : (\is_array($scope['stage1_contract']['page_route_contract'] ?? null) ? $scope['stage1_contract']['page_route_contract'] : []);

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
            . "- header/footer/CTA hrefs must use only exact path values from allowed_internal_paths, and header/footer fields must obey their exact link_groups allowed_paths; no domains, query strings, hashes, or anchors. Do not invent studio, product, preorder, gift, service, singular/plural, anchor, or campaign paths unless the exact path is listed here.\n"
            . "- If a desired destination is not in the route contract, omit that link and use the nearest listed page type instead; never create a new internal URL in html_extra, footer_extra_text, navigation.items, links.*_items, or cta.url.\n";
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
            $defaultConfig['cta.url'] = $ctaUrl !== '' ? $ctaUrl : '#';
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
                $label = $this->humanizeIdentifier($routeType);
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
                $label = $this->humanizeIdentifier($type);
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

    private function hasDominantLatinProseForCjkLocale(string $text): bool
    {
        $text = \html_entity_decode($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $text = (string)\preg_replace('/https?:\/\/\S+|\/pub\/media\/\S+/iu', ' ', $text);
        $segments = \preg_split('/[\r\n。！？!?；;|]+/u', $text) ?: [$text];

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
        $segments = \preg_split('/[\r\n.!?;:銆傦紒锛??锛?|]+/u', $text) ?: [$text];

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
                continue;
            }
            if (\preg_match_all('/\b(?:[A-Z][A-Za-z0-9\'-]{1,}|[A-Z0-9]{2,})\b/u', $source, $matches) < 1) {
                foreach ($this->latinIdentityVariants($source) as $variant) {
                    $allowed[$variant] = true;
                }
                continue;
            }
            foreach (\array_merge($matches[0] ?? [], $this->latinIdentityVariants($source)) as $word) {
                $normalized = \strtolower(\trim((string)$word, " \t\n\r\0\x0B'\"-"));
                if (\strlen($normalized) >= 2) {
                    $allowed[$normalized] = true;
                }
            }
        }

        return $allowed;
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
        if ($source !== '' && !$this->isLongLatinProseSource($source)) {
            $sources[] = $source;
        }
    }

    private function isAllowedLatinTermSourcePath(string $path): bool
    {
        return \preg_match(
            '/(?:^|\.)(?:site_title|site_name|brand|brand_name|business_name|product|product_name|game|game_name|service|service_name|platform|platform_name|app_name|keyword|keywords|domain|target_domain|name|title|tagline)(?:$|\.)/i',
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
        $locale = \trim((string)($renderContext['_content_locale'] ?? ''));
        $contentLocaleExplicit = !\array_key_exists('_content_locale_explicit', $renderContext)
            || (bool)$renderContext['_content_locale_explicit'];
        if (!$contentLocaleExplicit) {
            return;
        }
        if ($locale === '') {
            return;
        }

        $visibleText = $this->stripAllowedVisibleIdentitySnippets(
            $this->extractVisibleHtmlText($html),
            $renderContext
        );
        if ($visibleText === '') {
            return;
        }

        if ($this->isCjkLocale($locale) && $this->hasDominantLatinProseForCjkLocale($visibleText)) {
            throw new \RuntimeException(
                'Generated component website content locale contract failed: '
                . $this->summarizeNonTargetLatinCopyForLocaleError($visibleText, $this->collectAllowedLatinTermsFromRenderContext($renderContext))
            );
        }

        if ($this->isNonCjkLocale($locale) && $this->hasLargeCjkVisibleCopyForNonCjkLocale($visibleText)) {
            throw new \RuntimeException((string)__(
                '生成组件未满足网站内容语言契约：当前网站内容语言为 %{locale}，但组件可见文案含大量中文。请把阶段一方案中的访客可见文案改为英文，或将工作台「内容语言」改为中文后再重试。',
                ['locale' => $locale]
            ));
        }

        $allowedTerms = $this->collectAllowedLatinTermsFromRenderContext($renderContext);
        if ($this->hasDominantLatinProseForNonEnglishLocale($visibleText, $locale, $allowedTerms)) {
            throw new \RuntimeException(
                'Generated component website content locale contract failed: '
                . $this->summarizeNonTargetLatinCopyForLocaleError($visibleText, $allowedTerms)
            );
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

    private function assertRenderedHtmlPassesBuildQualityGate(string $componentCode, string $html): void
    {
        if (\trim($html) === '') {
            return;
        }
        // 鏍囩闂悎鏍￠獙锛氭娴嬮潪鑷棴鍚堟爣绛炬槸鍚︾己灏戦棴鏍囩
        $malformedReason = $this->detectMalformedGeneratedHtmlReason($html);
        if ($malformedReason !== null) {
            throw new \RuntimeException('Generated HTML structure invalid: ' . $malformedReason);
        }
        $unclosedTags = $this->detectUnclosedHtmlTags($html);
        if ($unclosedTags !== []) {
            throw new \RuntimeException(
                'Generated HTML has unclosed tags: ' . \implode(', ', \array_map(
                    static fn(string $tag, int $count): string => "{$tag}(x{$count})",
                    \array_keys($unclosedTags),
                    $unclosedTags
                ))
            );
        }
        $visualReason = $this->detectComponentVisualContractViolation($componentCode, $html);
        if ($visualReason !== null) {
            throw new \RuntimeException('Generated component visual contract failed: ' . $visualReason);
        }
        $contract = [
            'payload' => [
                'page_type_layouts' => [
                    '_component_build' => [
                        'content' => [
                            [
                                'code' => $componentCode,
                                'html' => $html,
                            ],
                        ],
                    ],
                ],
            ],
        ];
        foreach ((new RenderDataQualityLinter())->lint($contract) as $finding) {
            if (($finding['severity'] ?? '') !== 'error') {
                continue;
            }
            throw new \RuntimeException(\trim((string)($finding['message'] ?? 'Component HTML failed render-data quality gate.')));
        }
    }

    private function detectComponentVisualContractViolation(string $componentCode, string $html): ?string
    {
        $classIdentity = '';
        if (\preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/is', $html, $matches) > 0) {
            $classIdentity = \implode(' ', \array_map('strval', $matches[2] ?? []));
        }
        $identity = \strtolower($componentCode . ' ' . $classIdentity);
        if (\preg_match('/\b(hero|banner|opening|above[-_ ]?fold)\b/i', $identity) === 1) {
            return $this->detectHeroBannerQualityViolation($html);
        }
        if (\preg_match('/\b(contact|support|help|form|inquiry|enquiry|faq)\b/i', $identity) === 1) {
            return $this->detectContactSupportQualityViolation($html);
        }

        return null;
    }

    private function detectContactSupportQualityViolation(string $html): ?string
    {
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
            $signals += \preg_match_all('/\b(?:address|office|hours|support|sales|phone|email|whatsapp|faq)\b|客服|邮箱|电话|热线|地址|办公|营业|常见问题|孟买/iu', $text) ?: 0;
            if ($signals >= 2) {
                return 'contact/support details are compressed into a long paragraph; split channels into cards or form rows';
            }
        }

        return null;
    }

    /**
     * 妫€娴?HTML 鐗囨涓湭闂悎鐨勬爣绛撅紙蹇界暐 void 鍏冪礌鍜岃嚜闂悎鏍囩锛夈€?     *
     * @return array<string,int> tagName => openCount
     */
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
            // void 鍏冪礌鎴栬嚜闂悎鏍囩
            if (isset($voidTags[$tagName]) || \preg_match('/\/\s*>$/', $tagText) === 1) {
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
            $sse->sendEvent('warning', [
                'region' => $region,
                'component_code' => $componentCode,
                'message' => 'AI component generation did not pass quality validation; deterministic local fallback is forbidden. Reason: ' . $this->clipText($reason, 180),
                'component_fallback_forbidden' => true,
            ]);
        } catch (\Throwable) {
        }
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
}
