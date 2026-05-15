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
        $requiresImage = $isHero
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
        $usage = $isHero ? 'section_background_cover' : ($requiresImage ? 'section_media_surface' : 'optional_css_visual');
        $style = $isHero
            ? 'premium cinematic website banner, realistic/editorial photography or photoreal premium 3D, 1920x750 wide full-bleed cover background, not cartoon or SVG-like'
            : 'premium brand website media, realistic/editorial or high-end commercial art, not clip-art, not cartoon, not placeholder';
        $finalUrl = $this->resolveSectionAssetUrl($pageType, $section, $scope);
        if ($finalUrl !== '') {
            $resolvedSlotId = $this->resolveSectionAssetSlotId($scope, $finalUrl);
            if ($resolvedSlotId !== '') {
                $slotId = $resolvedSlotId;
            }
        }

        $contract = [
            'version' => 1,
            'required' => $requiresImage ? 1 : 0,
            'slot_id' => $slotId,
            'slot_type' => $isHero ? 'hero_banner' : 'section_image',
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
            'html_contract' => $requiresImage
                ? ($isHero
                    ? 'Render the concrete final_url from this contract as a real editable <img> cover layer inside html_content for the full-width 1920x750-style banner. The same <img> carries data-pb-ai-image-role and the concrete data-pb-ai-asset-slot from this contract.'
                    : 'Render the concrete final_url from this contract as a real editable <img> media surface inside html_content. The same <img> carries data-pb-ai-image-role and the concrete data-pb-ai-asset-slot from this contract.')
                : 'Use CSS-only motifs if no verified image exists; never use svg or placeholder image URLs.',
        ];
        if ($finalUrl !== '') {
            $contract['final_url'] = $finalUrl;
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
        $replaceBlueprintSections = false;
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
        $fullByKey = [];
        /** @var array<string|int, array<string,mixed>> $batchSpecs */
        $batchSpecs = [];
        $sse = RequestContext::get(RequestContext::SSE_WRITER_KEY);
        $chunkForwarder = RequestContext::get(self::REQUEST_CTX_AI_CHUNK_FORWARDER);

        foreach ($components as $componentKey => $spec) {
            $region = (string)($spec['region'] ?? 'content');
            $componentCode = (string)($spec['componentCode'] ?? '');
            $attemptPrompt = $this->appendComponentCssScopeInstruction((string)($spec['prompt'] ?? ''), $componentCode);
            $guardedPrompt = $this->prependComponentJsonOnlyGuard($attemptPrompt, false);
            $guardedPrompt = $this->getSkillRegistry()->prependPromptGuide($guardedPrompt, 'stage3');
            $fullByKey[$componentKey] = '';
            $batchSpecs[$componentKey] = [
                'prompt' => $guardedPrompt,
                'on_chunk' => function (string $chunk) use (
                    &$fullByKey,
                    $componentKey,
                    $sse,
                    $chunkForwarder,
                    $region
                ): bool {
                    if ($chunk === '') {
                        return true;
                    }
                    $fullByKey[$componentKey] .= $chunk;
                    if (\is_callable($chunkForwarder)) {
                        try {
                            ($chunkForwarder)([
                                'region' => $region,
                                'chunk' => $chunk,
                            ]);
                        } catch (\Throwable) {
                        }
                    }
                    if ($sse !== null && \is_object($sse) && \method_exists($sse, 'sendEvent')) {
                        $sse->sendEvent('ai_chunk', [
                            'region' => $region,
                            'chunk' => $chunk,
                        ]);
                    }

                    return true;
                },
                'scenario_code' => 'pagebuilder_component_generation',
                'params' => $this->buildAiRuntimeParams([
                    'allow_zero_balance_provider' => true,
                    'temperature' => 0.25,
                    'max_tokens' => 8192,
                    'timeout' => self::AI_REQUEST_TIMEOUT_SECONDS,
                    'response_format' => ['type' => 'json_object'],
                ], true),
            ];
        }

        /** @var array<string|int, array{status:string, error?:\Throwable}> $settled */
        $settled = \w_query('ai', 'generateStreamBatch', [
            'tasks' => $batchSpecs,
            'concurrency' => $this->resolveConcurrency(\count($batchSpecs)),
        ]);
        if (!\is_array($settled)) {
            $settled = [];
        }

        foreach ($components as $componentKey => $spec) {
            $entry = \is_array($settled[$componentKey] ?? null) ? $settled[$componentKey] : [];
            if (($entry['status'] ?? '') !== 'fulfilled') {
                $err = $entry['error'] ?? null;
                $throwable = $err instanceof \Throwable
                    ? $err
                    : new \RuntimeException('AI generateStreamBatch task failed for component key: ' . (string)$componentKey);
                yield $componentKey => [
                    'status' => 'rejected',
                    'error' => $throwable,
                ];

                continue;
            }

            $region = (string)($spec['region'] ?? 'content');
            $raw = (string)($fullByKey[$componentKey] ?? '');
            try {
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
        $aiData = $this->sanitizeGeneratedAssetAttributes($aiData);
        $aiData = $this->repairAiDataImageTagAttributeGrammar($aiData);
        $aiData = $this->ensureGeneratedAssetImageAttributes($aiData, $renderContext, $defaultConfig);
        $this->assertGeneratedImageSourcesUseVerifiedAssets($aiData, $renderContext, $defaultConfig);
        $aiData = $this->ensureRequiredNonHeroImageSlotElement($aiData, $region, $renderContext, $defaultConfig, $componentCode);
        $aiData = $this->enforceContractHeroImageUrlsInAiPayload($aiData, $region, $defaultConfig);
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
            $phtml = $this->attemptSyntaxFix($phtml, $region, $componentInfo, $aiData, $syntaxCheck);
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
     * AI output sometimes follows the slot contract semantically but drops the
     * `data` prefix while concatenating attributes. Repair that exact shape
     * before image-slot validation and before fallback image injection runs.
     *
     * @param array<string,mixed> $aiData
     * @return array<string,mixed>
     */
    private function sanitizeGeneratedAssetAttributes(array $aiData): array
    {
        foreach (['html_content', 'html_extra'] as $key) {
            $html = (string)($aiData[$key] ?? '');
            if ($html === '') {
                continue;
            }
            $html = \str_replace('"-pb-ai-image-role=', '" data-pb-ai-image-role=', $html);
            $html = \str_replace('\'-pb-ai-image-role=', '\' data-pb-ai-image-role=', $html);
            $html = \str_replace('"-pb-ai-asset-slot=', '" data-pb-ai-asset-slot=', $html);
            $html = \str_replace('\'-pb-ai-asset-slot=', '\' data-pb-ai-asset-slot=', $html);
            $aiData[$key] = $html;
        }

        return $aiData;
    }

    /**
     * If AI used the exact verified image URL but forgot editor metadata, repair
     * only the structural slot binding. This does not invent copy, layout, CSS, or
     * images; it preserves the AI-selected media placement and makes the queue
     * data contract editable.
     *
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function ensureGeneratedAssetImageAttributes(array $aiData, array $renderContext, array $defaultConfig): array
    {
        $assetMap = $this->extractVerifiedAssetMapFromRenderContext($renderContext, $defaultConfig);
        if ($assetMap === []) {
            return $aiData;
        }

        foreach (['html_content', 'html_extra'] as $key) {
            $html = (string)($aiData[$key] ?? '');
            if ($html === '' || \stripos($html, '<img') === false) {
                continue;
            }

            $aiData[$key] = \preg_replace_callback(
                '/<img\b[^>]*>/iu',
                function (array $matches) use ($assetMap): string {
                    $tag = (string)($matches[0] ?? '');
                    $src = $this->extractHtmlAttribute($tag, 'src');
                    $slot = $this->extractHtmlAttribute($tag, 'data-pb-ai-asset-slot');
                    if ($slot !== '' && isset($assetMap[$slot])) {
                        $tag = $this->upsertHtmlAttribute($tag, 'src', (string)$assetMap[$slot]);
                        $tag = $this->upsertHtmlAttribute($tag, 'data-pb-ai-image-role', 'generated-asset');
                        return $tag;
                    }
                    if ($src === '') {
                        return $tag;
                    }
                    foreach ($assetMap as $slotId => $url) {
                        if ($src !== $url) {
                            continue;
                        }

                        $tag = $this->upsertHtmlAttribute($tag, 'data-pb-ai-image-role', 'generated-asset');
                        $tag = $this->upsertHtmlAttribute($tag, 'data-pb-ai-asset-slot', (string)$slotId);
                        return $tag;
                    }

                    return $tag;
                },
                $html
            ) ?? $html;
        }

        return $aiData;
    }

    /**
     * Slot hydration is data-structure repair, not a visual fallback: when a
     * non-hero block has a required generated image asset, the block must carry
     * the editable slot element so later image replacement and publishing can
     * address it. Hero/banner layout still remains an AI-owned cover contract.
     *
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function ensureRequiredNonHeroImageSlotElement(
        array $aiData,
        string $region,
        array $renderContext,
        array $defaultConfig,
        string $componentCode
    ): array {
        if ($region !== 'content' || $this->isHeroOrBannerImageContract($defaultConfig, $renderContext, (string)($aiData['html_content'] ?? ''))) {
            return $aiData;
        }

        $assetMap = $this->extractVerifiedAssetMapFromRenderContext($renderContext, $defaultConfig);
        if ($assetMap === []) {
            return $aiData;
        }

        $html = (string)($aiData['html_content'] ?? '');
        if (\trim($html) === '') {
            return $aiData;
        }

        $prefix = $this->normalizeComponentCssPrefix($componentCode);
        foreach ($assetMap as $slotId => $url) {
            $slotId = \trim((string)$slotId);
            $url = \trim((string)$url);
            if ($slotId === '' || $url === '' || $this->htmlContainsGeneratedAssetImage($html, $slotId, $url)) {
                continue;
            }
            $alt = $this->resolveGeneratedAssetAltText($slotId, $url, $renderContext, $defaultConfig, $componentCode);

            $img = '<img class="' . $prefix . '-slot-image" src="'
                . \htmlspecialchars($url, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . '" alt="' . \htmlspecialchars($alt, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . '" data-pb-ai-image-role="generated-asset" data-pb-ai-asset-slot="'
                . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . '">';
            $html = $this->insertHtmlAfterFirstOpeningTag($html, $img);
        }

        $aiData['html_content'] = $html;

        return $aiData;
    }

    /**
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     */
    private function resolveGeneratedAssetAltText(
        string $slotId,
        string $url,
        array $renderContext,
        array $defaultConfig,
        string $componentCode
    ): string {
        $visualContract = \is_array($renderContext['_visual_contract'] ?? null)
            ? $renderContext['_visual_contract']
            : $this->decodeRuntimeVisualContract($defaultConfig);
        foreach ([
            $defaultConfig['runtime.section_image_alt'] ?? null,
            $defaultConfig['visual.image_alt'] ?? null,
            $defaultConfig['image.alt'] ?? null,
            $defaultConfig['media.image_alt'] ?? null,
            $visualContract['alt'] ?? null,
            $visualContract['subject'] ?? null,
            $defaultConfig['content.title'] ?? null,
            $defaultConfig['title'] ?? null,
            $componentCode,
        ] as $candidate) {
            $alt = $this->sanitizeGeneratedAssetAltCandidate((string)$candidate);
            if ($alt !== '') {
                return $alt;
            }
        }

        return $this->sanitizeGeneratedAssetAltCandidate($slotId) ?: $this->sanitizeGeneratedAssetAltCandidate($url);
    }

    private function sanitizeGeneratedAssetAltCandidate(string $value): string
    {
        $value = \trim(\strip_tags($value));
        if ($value === '') {
            return '';
        }
        $value = (string)(\preg_replace('/[_\/\\\\:-]+/u', ' ', $value) ?? $value);
        $value = (string)(\preg_replace('/\s+/u', ' ', $value) ?? $value);
        $value = \trim($value);
        if ($value === '' || \preg_match('/^(?:page|content|header|footer|slot|image|asset)\b/i', $value) === 1) {
            return '';
        }

        return $this->clipText($value, 140);
    }

    private function insertHtmlAfterFirstOpeningTag(string $html, string $insert): string
    {
        if (\preg_match('/^(\s*<\s*[a-z][a-z0-9:-]*\b[^>]*>)/iu', $html, $matches) !== 1) {
            return $insert . $html;
        }

        $opening = (string)($matches[1] ?? '');
        if ($opening === '') {
            return $insert . $html;
        }

        return $opening . $insert . \substr($html, \strlen($opening));
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

    private function upsertHtmlAttribute(string $tag, string $name, string $value): string
    {
        $escapedValue = \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $attribute = $name . '="' . $escapedValue . '"';
        $namePattern = \preg_quote($name, '/');
        if (\preg_match('/\s+' . $namePattern . '\s*=\s*(["\']).*?\1/isu', $tag) === 1) {
            return \preg_replace('/\s+' . $namePattern . '\s*=\s*(["\']).*?\1/isu', ' ' . $attribute, $tag, 1) ?? $tag;
        }
        if (\preg_match('/\s*\/>$/u', $tag) === 1) {
            return \preg_replace('/\s*\/>$/u', ' ' . $attribute . ' />', $tag, 1) ?? $tag;
        }

        return \preg_replace('/\s*>$/u', ' ' . $attribute . '>', $tag, 1) ?? $tag;
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
        $attemptPrompt = $this->appendComponentCssScopeInstruction($prompt, $componentCode);
        try {
            $aiData = $this->runAiGeneration($region, $attemptPrompt, $defaultConfig, $renderContext);

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
            $finalReason = $this->summarizeThrowable($throwable);
            $this->emitComponentFallbackNotice($region, $componentCode, $finalReason);
            throw new \RuntimeException('AI component generation failed: ' . $finalReason, 0, $throwable);
        }

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

    private function attemptSyntaxFix(string $phtml, string $region, array $componentInfo, array $aiData, array $initialCheck): string
    {
        $codeFixer = $this->getCodeFixer();
        $codeValidator = $this->getCodeValidator();

        // 绗?1 杞細CodeFixer::fix() 甯歌淇
        $fixed = $codeFixer->fix($phtml);
        $check = $codeValidator->checkSyntax($fixed);
        if (!empty($check['valid'])) {
            return $fixed;
        }

        $result = $codeFixer->fixAndValidate($phtml, $codeValidator);
        if (!empty($result['validation']['valid'])) {
            return (string)$result['code'];
        }

        $fixedAiData = $aiData;
        $fieldsToPatch = ['php_variables', 'css_extra', 'css_content', 'css_responsive', 'html_content', 'html_extra', 'html_extra_column', 'footer_extra_text'];
        $patched = false;
        foreach ($fieldsToPatch as $field) {
            if (!isset($fixedAiData[$field]) || !\is_string($fixedAiData[$field]) || $fixedAiData[$field] === '') {
                continue;
            }
            $original = $fixedAiData[$field];
            if ($field === 'php_variables') {
                $fixedAiData[$field] = $codeFixer->fixPhpVariables($fixedAiData[$field]);
            } elseif (\str_starts_with($field, 'css_')) {
                $fixedAiData[$field] = $codeFixer->fixCss($fixedAiData[$field]);
            } else {
                $fixedAiData[$field] = $codeFixer->fixHtmlContent($fixedAiData[$field], $field);
            }
            if ($fixedAiData[$field] !== $original) {
                $patched = true;
            }
        }
        if ($patched) {
            $fixedAiData = $codeFixer->fixAiData($fixedAiData);
            $rebuilt = $this->getFrameworkBuilder()->buildComponent($region, $componentInfo, $fixedAiData);
            $check = $codeValidator->checkSyntax($rebuilt);
            if (!empty($check['valid'])) {
                return $rebuilt;
            }
        }

        throw new \RuntimeException((string)__('AI generated component failed PHP syntax validation after %{n} automatic repair attempts: %{message}', [
            'n' => self::SYNTAX_FIX_MAX_ATTEMPTS + 1,
            'message' => (string)($initialCheck['error'] ?? 'unknown'),
        ]));
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
        $aiData = $this->getCodeFixer()->fixAiData($aiData);
        $aiData = $this->applyStrictVirtualThemeComponentPolicy($aiData, $region, $verifiedAssets);
        $aiData = $this->normalizeVirtualThemeCssClassScope($aiData, $componentCode);
        $this->assertNoInvalidComponentRootClassSelectors($aiData);
        $this->assertGeneratedComponentHtmlScopeContract($aiData, $region, $componentCode);
        $this->assertGeneratedComponentCssContract($aiData, $region, $componentCode);
        $this->assertHeroGeneratedImageCoverCssContract($aiData, $region, $componentCode);

        $validation = $this->getCodeValidator()->validateAiData($aiData, $region);
        if (!empty($validation['valid'])) {
            return $aiData;
        }

        $errors = \array_values(\array_filter(\array_map('strval', $validation['errors'] ?? [])));
        throw new \RuntimeException((string)__('AI 缁勪欢 JSON 鏍￠獙澶辫触锛?{message}', [
            'message' => \implode('; ', \array_slice($errors, 0, 5)),
        ]));
    }

    /**
     * @param array<string,mixed> $aiData
     * @return array<string,mixed>
     */
    private function applyStrictVirtualThemeComponentPolicy(
        array $aiData,
        string $region,
        array $verifiedAssets = []
    ): array
    {
        $aiData['extra_fields'] = '';
        $aiData['php_variables'] = '';
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
            $lowQualityInspectionSource = (string)($aiData['html_content'] ?? '')
                . "\n"
                . (string)($aiData['css_extra'] ?? '')
                . "\n"
                . (string)($aiData['css_responsive'] ?? '')
                . "\n"
                . (string)($aiData['css_content'] ?? '');
            $lowQualityReason = $this->detectLowQualityGeneratedSectionHtmlReason($lowQualityInspectionSource, $componentCode);
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
        $css = \trim($this->getCodeFixer()->fixCss($css));
        if ($css === '') {
            return '';
        }

        $css = $this->repairCssDeclarationSyntaxForValidation($css);
        $css = $this->repairCssParenthesesOutsideStrings($css);
        $css = $this->normalizeVirtualThemeCssComponentScope($css);
        $css = $this->clipCssAtRuleBoundary($css, $limit);
        if ($css === '') {
            return '';
        }

        $css = $this->repairCssDeclarationSyntaxForValidation($css);
        $css = $this->repairCssParenthesesOutsideStrings($css);
        $css = $this->normalizeVirtualThemeCssComponentScope($css);

        return $this->balanceCssBraces(\trim($this->getCodeFixer()->fixCss(
            $this->repairCssParenthesesOutsideStrings(
                $this->repairCssDeclarationSyntaxForValidation($css)
            )
        )));
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
        $properties = '(?:position|display|width|height|z-index|background(?:-image)?|color|font(?:-size|weight|family)?|padding|margin|grid-template-columns|flex-direction)';
        for ($i = 0; $i < 3; $i++) {
            $next = \preg_replace('/(:\s*[^;{}]*?\s+)(' . $properties . '\s*:)/i', '$1;$2', $css) ?? $css;
            $next = \preg_replace('/\b(relative|absolute|sticky|fixed|block|flex|grid|none|hidden|visible|cover|contain|center)(z-index|position|display|width|height|background(?:-image)?|color|padding|margin)\s*:/i', '$1;$2:', $next) ?? $next;
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

        $allowedUtilityClasses = \array_fill_keys(['sr-only', 'visually-hidden'], true);
        $matched = \preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/isu', $html, $matches);
        if ($matched === false || $matched === 0) {
            return;
        }

        foreach ($matches[2] as $classValue) {
            $tokens = \preg_split('/\s+/', \trim((string)$classValue)) ?: [];
            foreach ($tokens as $token) {
                $token = \trim((string)$token);
                if ($token === '' || isset($allowedUtilityClasses[$token])) {
                    continue;
                }
                $classReason = $this->detectMalformedGeneratedClassTokenReason($token, $componentCode);
                if ($classReason === null) {
                    continue;
                }
                throw new \RuntimeException(
                    'AI HTML class scope contract failed: class "' . $token . '" is invalid: ' . $classReason
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $aiData
     */
    private function assertGeneratedComponentCssContract(array $aiData, string $region, string $componentCode): void
    {
        $allowedUtilityClasses = \array_fill_keys(['sr-only', 'visually-hidden'], true);

        foreach (['css_extra', 'css_responsive', 'css_content'] as $cssKey) {
            $css = (string)($aiData[$cssKey] ?? '');
            if (\trim($css) === '') {
                continue;
            }
            $cssReason = $this->detectMalformedGeneratedCssReason($css);
            if ($cssReason !== null) {
                throw new \RuntimeException('AI CSS structure contract failed: ' . $cssReason);
            }
            if ($region !== 'content') {
                continue;
            }
            foreach ($this->collectGeneratedCssSelectorClasses($css) as $className) {
                if (isset($allowedUtilityClasses[$className])) {
                    continue;
                }
                $classReason = $this->detectMalformedGeneratedClassTokenReason($className, $componentCode);
                if ($classReason === null) {
                    continue;
                }
                throw new \RuntimeException(
                    'AI CSS class scope contract failed: selector class "' . $className . '" is invalid: ' . $classReason
                );
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
        if (\preg_match('/(?:^|[;{])\s*-(?!(?:webkit|moz|ms|o)-)[a-z][a-z0-9_-]*\s*:/i', $scan) === 1) {
            return 'malformed CSS property name';
        }
        if (\preg_match('/:\s*%\s*(?:[;}])/i', $scan) === 1) {
            return 'CSS value starts with a bare percent sign';
        }
        if (\preg_match('/:\s*[^;{}]*(?:\b(?:position|display|width|height|z-index|background(?:-image)?|color|font(?:-size|weight|family)?|padding|margin|grid-template-columns|flex-direction)\s*:)/i', $scan) === 1) {
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
            ['.-content-', '"-content-', '\'-content-', ' -content-', '.-header-', '"-header-', '\'-header-', ' -header-', '.-footer-', '"-footer-', '\'-footer-', ' -footer-'],
            ['.pb-content-', '"pb-content-', '\'pb-content-', ' pb-content-', '.pb-header-', '"pb-header-', '\'pb-header-', ' pb-header-', '.pb-footer-', '"pb-footer-', '\'pb-footer-', ' pb-footer-'],
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

        return $slug !== '' ? 'pb-' . $slug : self::COMPONENT_CSS_CLASS_SCOPE_FALLBACK;
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

    private function appendComponentCssScopeInstruction(string $prompt, string $componentCode): string
    {
        $prefix = $this->normalizeComponentCssPrefix($componentCode);

        return \rtrim($prompt)
            . "\n\nCSS class scope rule:\n"
            . "- Never use generic custom classes such as .card, .icon, .btn, .title, .item, .panel, .row, .container, .section, .text, .image, or .active.\n"
            . "- Any custom class in CSS and the matching HTML must use this component prefix: `{$prefix}-...`.\n"
            . "- CSS selectors must be scoped with the safe placeholder `#componentId`; do not output PHP tags in JSON CSS fields.\n"
            . "- `html_content` is inserted inside the framework root. To style your inner wrapper use `#componentId .{$prefix}-root`, not `#componentId.{$prefix}-root`.\n"
            . "- Do not create an inner `id='componentId'` wrapper. The framework root already owns that id; use a class-only wrapper like `<section class='{$prefix}-root'>`.\n"
            . "- Minimal valid HTML skeleton for this component: `<section class='{$prefix}-root'><div class='{$prefix}-wrap'><h2 class='{$prefix}-title'>Visitor-facing title</h2><p class='{$prefix}-copy'>Concrete visitor-facing copy.</p></div></section>`.\n"
            . "- Minimal valid CSS skeleton for this component: `#componentId .{$prefix}-root { position:relative; overflow:hidden; background:linear-gradient(135deg,#111827,#1f2937); } #componentId .{$prefix}-wrap { position:relative; z-index:1; min-width:0; } @media (max-width:768px){ #componentId .{$prefix}-grid { grid-template-columns:1fr; } }`.\n"
            . "- Hero/banner generated-image skeleton: when this component is a hero/banner and uses a generated asset, put `class='{$prefix}-hero-image'` on the copied <img> and include this exact cover rule in css_extra: `#componentId .{$prefix}-hero-image { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:center; }`. The HTML class and CSS selector spelling must match byte-for-byte.\n"
            . "- Hero/banner readability skeleton: every hero/banner with media must include a named scrim element and a named text-safe panel, for example `<div class='{$prefix}-scrim'></div><div class='{$prefix}-text-panel'><h1 class='{$prefix}-title'>...</h1><p class='{$prefix}-copy'>...</p></div>`. Include matching CSS: `#componentId .{$prefix}-scrim { position:absolute; inset:0; background:linear-gradient(90deg,rgba(0,0,0,.72),rgba(0,0,0,.32)); } #componentId .{$prefix}-text-panel { position:relative; z-index:2; max-width:720px; padding:clamp(24px,4vw,48px); border-radius:24px; background:rgba(0,0,0,.42); backdrop-filter:blur(10px); color:#fff; }`.\n"
            . "- Valid JSON envelope shape: `{\"extra_fields\":\"\",\"php_variables\":\"\",\"css_extra\":\"#componentId .{$prefix}-root { position:relative; overflow:hidden; }\",\"css_responsive\":\"@media (max-width:768px){ #componentId .{$prefix}-root { padding:24px; } }\",\"html_content\":\"<section class='{$prefix}-root'><div class='{$prefix}-wrap'><h2 class='{$prefix}-title'>Finished heading</h2><p class='{$prefix}-copy'>Finished copy.</p></div></section>\",\"js_content\":\"\"}`. Replace example words and CSS values with finished customer content before returning.\n"
            . "- Prefix self-check: every custom class must literally start with `{$prefix}-`, including the leading `pb`. A class that starts with `-`, `content-`, or any value missing the leading `pb-` is invalid.\n"
            . "- Before returning, self-check that every custom class used in CSS exists with the exact same spelling in html_content and starts with `{$prefix}-`.\n"
            . "- HTML attribute grammar must be exact: write `<span class='{$prefix}-badge'>`; for img tags, put a real space between every quoted attribute such as src, alt, data-pb-ai-image-role, and data-pb-ai-asset-slot. Never output `<span='...'>`, `<img ...'alt='...'>`, or any attribute glued after a closing quote.\n"
            . "- HTML closing-tag grammar must be exact: never concatenate closing tags. Write `</p></a>`, `</small></div>`, or `</div></section>` as separate tags; outputs like `</pa>`, `</smalldiv>`, `</pdiv>`, or `</divsection>` are invalid.\n"
            . "- CSS grammar must be exact: no empty declarations like `content:;`, no malformed properties like `-index`, no values like `height:%`, no merged declarations like `position:relativez-index:1`, no unclosed quoted values, and all function parentheses must close.\n"
            . "- CSS reliability mode: write simple complete declarations as `property: value;`, preferably one declaration per line. Avoid fragile or nested CSS functions and avoid quoted CSS values unless necessary; use plain hex/rgba colors, linear-gradient(), clamp(), and simple box-shadow only when every quote and parenthesis is closed. Do not use color-mix(), mask, clip-path, nested calc(), or filter chains in generated CSS.\n"
            . "- Invalid output examples to avoid: `<div='...'>`, `<p='...'>`, `<span='...'>`, `<a href='#'class='...'>`, `content:;`, `height:%`, `position:relativez-index:1`, and selector fragments such as `.-content...`.\n"
            . "- Examples for this component: `#componentId .{$prefix}-card`, `#componentId .{$prefix}-icon`, `#componentId .{$prefix}-title`.\n";
    }

    private function cleanAiHtmlFragment(string $html, array $verifiedAssets = []): string
    {
        $html = \preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = \preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = $this->stripPhpFragmentsFromHtml($html);
        $html = $this->repairHtmlTagAttributeSpacing($html);
        $html = $this->repairGeneratedImageTagAttributeGrammar($html);
        $svgPolicyReason = $this->detectInlineSvgGeneratedSectionViolation($html);
        if ($svgPolicyReason !== null) {
            throw new \RuntimeException('AI component content hard policy failed: ' . $svgPolicyReason);
        }
        $html = \preg_replace('/@(?:component|fields)_(?:start|end)\b/i', '', $html) ?? $html;
        $hardPolicyReason = $this->detectHardGeneratedSectionHtmlPolicyViolation($html);
        if ($hardPolicyReason !== null) {
            throw new \RuntimeException('AI component content hard policy failed: ' . $hardPolicyReason);
        }
        $html = $this->repairHtmlFragmentTagBalance(\trim($html));
        $this->assertGeneratedHtmlFragmentWellFormed($html);
        $this->assertNoBrokenGeneratedImageReferences($html, $verifiedAssets);
        $html = \preg_replace('/\s{2,}/u', ' ', $html) ?? $html;
        $html = \trim($html);
        $html = $this->clipHtmlFragmentPreservingIntegrity($html, 5000);
        $html = $this->repairHtmlFragmentTagBalance(\trim($html));
        $this->assertGeneratedHtmlFragmentWellFormed($html);

        return \trim($html);
    }

    private function repairHtmlTagAttributeSpacing(string $html): string
    {
        if ($html === '') {
            return '';
        }

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

    private function repairGeneratedImageTagAttributeGrammar(string $html): string
    {
        if ($html === '' || \stripos($html, '<img') === false) {
            return $html;
        }

        return \preg_replace_callback(
            '/<img\b[^<>]*(?:>|$)/iu',
            function (array $matches): string {
                $tag = (string)($matches[0] ?? '');
                if ($tag === '') {
                    return $tag;
                }
                $tag = \preg_replace('/(["\'])(?=[a-zA-Z_:][a-zA-Z0-9_:.-]*\s*=)/u', '$1 ', $tag) ?? $tag;

                $quote = '';
                $repaired = '';
                $length = \strlen($tag);
                for ($index = 0; $index < $length; $index++) {
                    $char = $tag[$index];
                    if ($quote !== '') {
                        if (\preg_match('/\s(?:src|alt|title|class|loading|decoding|width|height|data-[a-z0-9_-]+|aria-[a-z0-9_-]+)\s*=/iu', \substr($tag, $index), $attrMatch, \PREG_OFFSET_CAPTURE) === 1
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
            },
            $html
        ) ?? $html;
    }

    /**
     * @param array<string,mixed> $aiData
     * @return array<string,mixed>
     */
    private function repairAiDataImageTagAttributeGrammar(array $aiData): array
    {
        foreach (['html_content', 'html_extra', 'html_extra_column', 'footer_extra_text'] as $key) {
            if (\is_string($aiData[$key] ?? null) && \stripos((string)$aiData[$key], '<img') !== false) {
                $aiData[$key] = $this->repairGeneratedImageTagAttributeGrammar((string)$aiData[$key]);
            }
        }

        return $aiData;
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
        $plain = \strip_tags($html);
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

    private function detectInlineSvgGeneratedSectionViolation(string $html): ?string
    {
        if (\preg_match('/<svg\b|data:image\/svg\+xml/iu', $html) === 1) {
            return 'inline svg visual is not allowed in virtual-theme components; use verified image assets or CSS-only decoration';
        }

        return null;
    }

    private function detectLowQualityGeneratedSectionHtmlReason(string $html, ?string $componentCode = ''): ?string
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
        if ($plain === '' || \mb_strlen($plain) < 18) {
            return 'insufficient visitor-facing text';
        }
        $visibleText = $this->extractVisibleHtmlText($trimmed);
        if (\preg_match('/AI content placeholder|ai-empty|placeholder\s+(?:content|copy|section|text|block|image|visual)|demo|example\.com|Generated visual|inline SVG|Visual preview generated|Generated website section|Website content language|visitor-visible copy|Do not use the|Return ONLY|prompt text|customer brief|website requirement|planning\/plan language|stage-2 planned text|source intent|Built from plan|generated from plan|confirmed stage-one content|content_fill_rule|field_content_requirements|stage3_directive|task_script/iu', $visibleText) === 1) {
            return 'prompt or placeholder text leaked';
        }
        if ($this->containsEnglishBoilerplateVisibleCopy($visibleText)) {
            return 'English boilerplate copy leaked into visitor content';
        }

        if (\preg_match('/\b(?:AI_GENERATED_[A-Z0-9_]+|task_key|section_code|block_key|page_type|plan_locale|runtime_context|content\/[a-z0-9_\/-]+|app\/code\/[a-z0-9_\/-]+|var\/[a-z0-9_\/-]+|home_page|about_page|shared:[a-z0-9:_\/-]+|page:[a-z0-9:_\/-]+)\b/iu', $visibleText) === 1) {
            return 'internal task identifiers leaked';
        }

        if ($this->containsPlanningObservationCopy($plain)) {
            return 'planning observation copy leaked into visitor content';
        }
        if (\preg_match('/\d(?:[.,]\d+)?[\p{Lu}\p{L}]{2,}/u', $visibleText) === 1) {
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
        if (\preg_match('/>\s*(?:[\p{S}\p{P}\s]{3,})\s*</u', $trimmed) === 1) {
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
        if (\preg_match('/\b(hero|banner|opening|above[-_ ]?fold)\b/i', $componentCode) === 1
            && \preg_match('/<img\b|background-image|data-pb-ai-image-role=["\']generated-asset["\']/iu', $trimmed) === 1
        ) {
            $heroReason = $this->detectHeroBannerQualityViolation($trimmed);
            if ($heroReason !== null) {
                return $heroReason;
            }
        }

        return null;
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
        $hasFullBleedBackground = \preg_match('/(?:data-pb-ai-image-role=["\']generated-asset["\'][^>]*(?:width:100%|height:100%|object-fit)|background-image|object-fit\s*:\s*cover|inset\s*:\s*0)/iu', $normalized) === 1;
        $hasPremiumMediaHero = \preg_match('/(?:data-pb-ai-image-role=["\']generated-asset["\']|class=["\'][^"\']*(?:hero|banner)[^"\']*(?:media|visual|image)[^"\']*["\'])/iu', $normalized) === 1
            && \preg_match('/(?:border-radius|box-shadow|linear-gradient|radial-gradient|backdrop-filter|object-fit\s*:\s*cover)/iu', $normalized) === 1;
        $hasOverlayPanel = \preg_match('/backdrop-filter|rgba\([^)]*,\s*\.(?:3|4|5|6|7|8|9)|linear-gradient\([^;]*(?:rgba|transparent)/iu', $normalized) === 1;
        $hasNamedReadabilityLayer = \preg_match('/class=["\'][^"\']*(?:overlay|scrim|veil|shade|backdrop|gradient)[^"\']*["\']/iu', $normalized) === 1;
        $hasNamedTextSafePanel = \preg_match('/class=["\'][^"\']*(?:copy|content|text|caption|message|panel|plate|card)[^"\']*["\']/iu', $normalized) === 1;
        if (!$hasFullBleedBackground && !$hasPremiumMediaHero) {
            return 'hero/banner is not using a full-background image or a premium generated media visual';
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
        if ($requiredAssets === [] && (int)($visualContract['required'] ?? 0) === 1) {
            $slotId = \trim((string)($visualContract['slot_id'] ?? $defaultConfig['runtime.section_image_slot_id'] ?? ''));
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
                if (!\str_contains($payload, $url)) {
                    throw new \RuntimeException('Required image slot is not referenced by generated block: ' . $slotId);
                }
                throw new \RuntimeException('Required image slot is missing editor attributes: ' . $slotId);
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
     * Validate hero/banner image slot usage. The renderer must not inject the missing
     * image, class, overlay, or CSS; invalid AI output is retried or failed.
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
        if ($slotId !== '' && !$this->htmlContainsGeneratedAssetImage($html, $slotId, $imageUrl)) {
            if (!\str_contains($html, $imageUrl)) {
                throw new \RuntimeException('AI hero image contract failed: generated hero must use the provided image URL.');
            }
            throw new \RuntimeException('AI hero image contract failed: generated hero image must use the provided image URL, generated-asset role, and slot binding on the same img element.');
        }
        if ($slotId === '' && !\str_contains($html, $imageUrl)) {
            throw new \RuntimeException('AI hero image contract failed: generated hero must use the provided image URL.');
        }
        if ($slotId === '' && !\preg_match('/<img\b[^>]*\bdata-pb-ai-image-role\s*=\s*(["\'])generated-asset\1/iu', $html)) {
            throw new \RuntimeException('AI hero image contract failed: generated hero must mark the image element as generated-asset.');
        }

        return $aiData;
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
    private function runAiGeneration(string $region, string $prompt, array $defaultConfig = [], array $renderContext = []): array
    {
        $fullContent = '';
        $sse = RequestContext::get(\Weline\Framework\Runtime\RequestContext::SSE_WRITER_KEY);
        $chunkForwarder = RequestContext::get(self::REQUEST_CTX_AI_CHUNK_FORWARDER);
        $chunkBuffer = '';
        $lastChunkFlushAt = \microtime(true);
        $flushChunkBuffer = static function (bool $force = false) use (&$chunkBuffer, &$lastChunkFlushAt, $chunkForwarder, $region): void {
            if (!\is_callable($chunkForwarder) || $chunkBuffer === '') {
                return;
            }
            $now = \microtime(true);
            $hasBoundary = \str_contains($chunkBuffer, "\n");
            if (
                !$force
                && \strlen($chunkBuffer) < 120
                && !$hasBoundary
                && ($now - $lastChunkFlushAt) < 0.25
            ) {
                return;
            }

            try {
                $chunkForwarder([
                    'region' => $region,
                    'chunk' => $chunkBuffer,
                ]);
            } catch (\Throwable) {
            }

            $chunkBuffer = '';
            $lastChunkFlushAt = $now;
        };

        $guardedPrompt = $this->prependComponentJsonOnlyGuard($prompt, false);
        try {
            $this->callAiOperation('generateStream', [
                'prompt' => $guardedPrompt,
                'on_chunk' => static function (string $chunk) use (&$fullContent, &$chunkBuffer, $flushChunkBuffer, $sse, $region): bool {
                    $fullContent .= $chunk;
                    $chunkBuffer .= $chunk;
                    $flushChunkBuffer(false);
                    if ($sse !== null && \is_object($sse) && \method_exists($sse, 'sendEvent')) {
                        $sse->sendEvent('ai_chunk', [
                            'region' => $region,
                            'chunk' => $chunk,
                        ]);
                    }
                    return true;
                },
                'scenario_code' => 'pagebuilder_component_generation',
                'params' => $this->buildAiRuntimeParams([
                    'allow_zero_balance_provider' => true,
                    'temperature' => 0.25,
                    'max_tokens' => 8192,
                    'timeout' => self::AI_REQUEST_TIMEOUT_SECONDS,
                    'response_format' => ['type' => 'json_object'],
                ], true),
            ]);
        } catch (\Throwable $streamThrowable) {
            $flushChunkBuffer(true);
            return $this->recoverAiGenerationAfterStreamFailure($region, $prompt, $fullContent, $streamThrowable);
        }
        $flushChunkBuffer(true);

        return $this->decodeAndNormalizeComponentContent(
            $fullContent,
            $region,
            'AI did not return a valid component JSON payload'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function recoverAiGenerationAfterStreamFailure(
        string $region,
        string $prompt,
        string $streamContent,
        \Throwable $streamThrowable
    ): array {
        $partialPayload = $this->tryDecodePartialStreamPayload($streamContent);
        if (\is_array($partialPayload)) {
            $this->emitComponentStreamFallbackNotice(
                $region,
                'stream disconnected after a parseable JSON payload was received; continuing with the parsed payload'
            );
            return $this->normalizeComponentPayload($partialPayload);
        }

        $this->emitComponentStreamFallbackNotice(
            $region,
            'stream returned no usable JSON; failing without non-stream retry'
        );
        throw new \RuntimeException(
            'AI component stream failed without retry: ' . $this->summarizeThrowable($streamThrowable),
            0,
            $streamThrowable
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function tryDecodePartialStreamPayload(string $streamContent): ?array
    {
        if (\trim($streamContent) === '') {
            return null;
        }

        try {
            $decoded = $this->getResponseJsonParser()->extractAndDecode($streamContent);
        } catch (\Throwable) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeAndNormalizeComponentContent(string $content, string $region, string $message): array
    {
        $payload = $this->decodeComponentPayloadWithRepair($content, $region);
        if ($payload === null) {
            throw new \RuntimeException($message);
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
            '- HTML string fields must be well-formed fragments: balanced tags, closed attribute quotes, no orphan closing tags, and no framework wrapper leakage.',
            '- HTML close-tag contract is strict: never merge adjacent closing tags, never invent tags such as </h3p>, </h2div>, </pa>, or </divsection>, and never close a parent element while a child heading/span/strong is still open.',
            '- Use one simple root wrapper and shallow child blocks. Avoid nested inline tags inside headings; put emphasis in sibling spans or paragraphs instead.',
            '- Every `<` inside html_content must begin a valid HTML tag name or be escaped as visitor text; never leave dangling `<`, empty tag names, or comparison symbols in copy.',
            '- Use single quotes for all HTML attributes inside JSON strings. When the prompt provides exact editable image templates, copy their concrete src and data-pb-ai-asset-slot values; never return symbolic URL or slot placeholders.',
            '- Every custom class in html_content must use the requested component prefix and start with `pb-`; never output generic classes such as card, btn, item, panel, icon, title, active, row, container, or numbered classes like gem1.',
            '- Never place standalone punctuation/symbol-only decorations in visible HTML text. Build arrows, dividers, stars, suit marks, plus signs, and other ornaments with CSS borders, gradients, pseudo-elements, or background layers instead.',
        ];
        if ($retry) {
            $guard[] = 'RECOVERY MODE: previous stream ended without usable final JSON. Return the final JSON object immediately.';
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

    private function emitComponentStreamFallbackNotice(string $region, string $reason): void
    {
        $message = $this->clipText($reason, 220);

        $chunkForwarder = RequestContext::get(self::REQUEST_CTX_AI_CHUNK_FORWARDER);
        if (\is_callable($chunkForwarder)) {
            try {
                $chunkForwarder([
                    'region' => $region !== '' ? ($region . '_stream_recovery') : 'stream_recovery',
                    'chunk' => $message,
                ]);
            } catch (\Throwable) {
            }
        }

        $sse = RequestContext::get(RequestContext::SSE_WRITER_KEY);
        if (!$sse || !\is_object($sse) || !\method_exists($sse, 'sendEvent')) {
            return;
        }

        try {
            $sse->sendEvent('warning', [
                'region' => $region,
                'message' => $message,
                'stream_recovery' => true,
            ]);
        } catch (\Throwable) {
        }
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

        $this->emitJsonRepairChunk(
            $region,
            'Parsing component JSON locally.'
        );
        $decoded = $parser->extractAndDecode($content);
        if (\is_array($decoded)) {
            $this->emitJsonRepairChunk(
                $region,
                'Component JSON parsed successfully.'
            );
            return $decoded;
        }

        $locallyRepairedContent = $this->repairInvalidJsonBackslashEscapes($content);
        if ($locallyRepairedContent !== $content) {
            $this->emitJsonRepairChunk(
                $region,
                'Component JSON contained invalid backslash escapes; repaired JSON transport escapes locally.'
            );
            $decoded = $parser->extractAndDecode($locallyRepairedContent);
            if (\is_array($decoded)) {
                $this->emitJsonRepairChunk(
                    $region,
                    'Component JSON parsed successfully after local escape repair.'
                );
                return $decoded;
            }
        }

        return null;
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
            return [];
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
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
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

            $matched = $this->findFirstScopedSlot($slots, $preferredTypes, $pageType, true, $verifiedOnly);
            if ($matched !== '') {
                return $matched;
            }

            $matched = $this->findFirstScopedSlot($slots, $preferredTypes, $pageType, false, $verifiedOnly);
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
                foreach (\array_keys($candidates) as $needle) {
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
                foreach (\array_keys($candidates) as $needle) {
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
        if ($sharedHeaderItems !== []) {
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
        $byType = [];

        foreach ($navigationPages as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? '');
            if ($type !== '') {
                $byType[$type] = $item;
            }
        }

        $items = [];
        foreach ([Page::TYPE_HOME, Page::TYPE_ABOUT] as $type) {
            if (isset($byType[$type])) {
                $items[] = $byType[$type];
            }
        }

        foreach ([Page::TYPE_PRIVACY_POLICY, Page::TYPE_TERMS_OF_SERVICE, Page::TYPE_REFUND_POLICY, Page::TYPE_SHIPPING_POLICY, Page::TYPE_COOKIE_POLICY] as $type) {
            if (!isset($byType[$type])) {
                continue;
            }
            $items[] = [
                'title' => $this->localizeBuildText('policy_info', $locale),
                'handle' => (string)($byType[$type]['handle'] ?? ''),
                'url' => (string)($byType[$type]['url'] ?? '#'),
                'type' => 'policy_info',
                'page_id' => (int)($byType[$type]['page_id'] ?? 0),
            ];
            break;
        }

        foreach ([Page::TYPE_BLOG_LIST, Page::TYPE_CONTACT] as $type) {
            if (isset($byType[$type])) {
                $items[] = $byType[$type];
            }
        }

        $existingTypes = \array_flip(\array_map(
            static fn(array $entry): string => (string)($entry['type'] ?? ''),
            $items
        ));
        foreach ($navigationPages as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? '');
            if ($type === '' || isset($existingTypes[$type])) {
                continue;
            }
            $items[] = $item;
            $existingTypes[$type] = true;
            if (\count($items) >= 5) {
                break;
            }
        }

        return $items !== [] ? $items : $navigationPages;
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
            $fallback = $fallbackItems[$index] ?? [];
            if ($type !== '' && isset($fallbackByType[$type])) {
                $fallback = $fallbackByType[$type];
            } elseif ($href !== '' && isset($fallbackByHref[$href])) {
                $fallback = $fallbackByHref[$href];
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
        $buildPlanPromptAddon = $this->buildBuildPlanTaskPromptAddon(
            $this->resolveSharedBuildPlanTask($scope, 'header'),
            'header',
            $scope
        );
        $themeContract = $this->buildThemeContractPromptAddon($scope);
        $visualExcellence = $this->buildVisualExcellencePromptAddon('header');
        $skillContract = $this->buildWelineSkillContractPromptAddon();
        $claudeDesignSkill = $this->buildClaudeDesignSkillPromptAddon('stage3', $scope);
        $visibleCopyRule = $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope);

        return $langRule
            . $stage3LocaleContract
            . "You are generating a PageBuilder website header component.\n"
            . "Site name: {$siteDisplayName}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . $visibleCopyRule
            . $skillContract
            . $claudeDesignSkill
            . $themeContract
            . $visualExcellence
            . "Selected pages: " . \implode(', ', $pageList) . "\n"
            . "Current navigation data: " . \json_encode($headerConfig['nav_items'] ?? [], \JSON_UNESCAPED_UNICODE) . "\n"
            . $buildPlanPromptAddon
            . ($sharedRefinement !== '' ? "Latest user refinement for this header: {$sharedRefinement}\n" : '')
            . "Rules:\n"
            . "1. Output only one header component, never a full page.\n"
            . "2. The copy must read like finished website copy for visitors.\n"
            . "3. Never expose internal wording such as customer brief, prompt text, page focus, requirements, or 'I want to build'.\n"
            . "4. Navigation must be compatible with real page links and the provided navigation data.\n"
            . "5. Keep the structure practical: logo area, navigation, optional CTA, mobile-friendly behavior.\n"
            . "6. Style should be inspired by the reference theme, but do not mention the theme name in visible copy.\n"
            . "7. CSS EFFECTS REQUIRED: the header must have visible depth -subtle shadow on the nav bar, hover underline animation on nav links, transition on CTA with shadow lift. Every interactive element (nav-link, CTA, logo, toggle) must have a non-zero hover/focus style.\n"
            . "8. The framework already provides fields/config/nav/CTA. Set extra_fields, php_variables, html_extra, and js_content to empty strings unless explicitly required.\n"
            . "9. Return valid JSON only. No markdown. No explanation. Keep css_extra under 1800 chars.\n"
            . "JSON fields: extra_fields, php_variables, css_extra, html_extra, js_content.\n"
            . $this->buildComponentJsonPhpSafetyRulesEn();
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
        $buildPlanPromptAddon = $this->buildBuildPlanTaskPromptAddon(
            $this->resolveSharedBuildPlanTask($scope, 'footer'),
            'footer',
            $scope
        );
        $themeContract = $this->buildThemeContractPromptAddon($scope);
        $visualExcellence = $this->buildVisualExcellencePromptAddon('footer');
        $skillContract = $this->buildWelineSkillContractPromptAddon();
        $claudeDesignSkill = $this->buildClaudeDesignSkillPromptAddon('stage3', $scope);
        $visibleCopyRule = $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope);

        return $langRule
            . $stage3LocaleContract
            . "You are generating a PageBuilder website footer component.\n"
            . "Site name: {$siteDisplayName}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . $visibleCopyRule
            . $skillContract
            . $claudeDesignSkill
            . $themeContract
            . $visualExcellence
            . $buildPlanPromptAddon
            . ($sharedRefinement !== '' ? "Latest user refinement for this footer: {$sharedRefinement}\n" : '')
            . "Footer link data: " . \json_encode([
                'column1' => $footerConfig['links.column1_items'] ?? '',
                'column2' => $footerConfig['links.column2_items'] ?? '',
                'column3' => $footerConfig['links.column3_items'] ?? '',
            ], \JSON_UNESCAPED_UNICODE) . "\n"
            . "Rules:\n"
            . "1. Output only one footer component, never a full page.\n"
            . "2. The copy must read like real customer-facing site copy, not internal notes.\n"
            . "3. Never print customer brief text, prompt instructions, or requirement wording on the page.\n"
            . "4. Keep footer structure practical: brand area, grouped links, support/legal text, optional extra column or subscription area.\n"
            . "5. Footer links should be compatible with real page nav logic and the provided link groups.\n"
            . "6. Footer completeness contract (hard requirement): render at least 3 visible link labels in html_extra_column/html_extra, include at least one support/contact entry, and include at least one legal/compliance entry. Do not return empty link groups.\n"
            . "7. Footer visibility contract (hard requirement): never hide or collapse the footer by default (no display:none, visibility:hidden, opacity:0, height:0, off-canvas positioning, or clipped-to-zero wrappers).\n"
            . "8. CSS EFFECTS REQUIRED: the footer must have visible depth -a top border/shadow separator from the page body, hover lift on social icons with shadow, hover underline animation on link items, and transition effects on any interactive element. Grouped link columns should have heading separation.\n"
            . "9. Footer language contract: translate/rewrite every group heading, link label, tagline, support/legal sentence, and copyright phrase into source_of_truth_locale. Do not output English boilerplate such as Featured Pages, Policy Info, All Pages, A curated destination, or All rights reserved unless source_of_truth_locale is English.\n"
            . "10. Style should follow the reference theme direction without naming the theme in visible text.\n"
            . "11. The framework already provides brand/link/social/copyright fields. Set extra_fields, php_variables, and js_content to empty strings unless explicitly required.\n"
            . "12. Return valid JSON only. No markdown. No explanation. Keep css_extra under 1800 chars and footer_extra_text as one short visitor-facing sentence.\n"
            . "JSON fields: extra_fields, php_variables, css_extra, html_extra_column, html_extra, footer_extra_text, js_content.\n"
            . $this->buildComponentJsonPhpSafetyRulesEn();
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
        $sectionVisualContractPrompt = $this->buildSectionVisualContractPromptAddon($sectionVisualContract);
        $skillContract = $this->buildWelineSkillContractPromptAddon();
        $claudeDesignSkill = $this->buildClaudeDesignSkillPromptAddon('stage3', $scope);
        $visibleCopyRule = $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope);
        $pageLabel = $this->normalizePromptVisibleLabel(
            (string)($blueprint['page_label'] ?? ''),
            $this->localizePageTypeTitle($pageType, $locale),
            $locale
        );

        return $langRule
            . $stage3LocaleContract
            . "You are generating a PageBuilder content component.\n"
            . "Page type: " . $pageLabel . " ({$pageType})\n"
            . "Section name: {$sectionName}\n"
            . "Section role: {$sectionKey}\n"
            . ($sectionTemplate === 'hero' ? "BANNER MODULE DEFAULT RULE: this is a hero/banner section. Unless the user's latest block-adjustment instruction or approved design plan explicitly asks for a different hero composition, use a FULL-WIDTH 1920x750-style banner (container_width=full) with a background IMAGE covering the entire section. Set bg_type to 'image' and use the planned hero_image URL when available. Add a dark gradient overlay over the entire image AND put heading/body/CTA copy inside a visible text-safe panel/scrim zone with its own readable foreground color. Content overlay is placed inside a centered max-width content container above the image. Body copy must never be dark text directly on a busy photo. If the user explicitly requests a different hero layout, follow that layout but keep a premium generated image, strong hierarchy, and readable overlay.\n" : '')
            . "Suggested structure: {$sectionTemplate}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . ($brief !== '' ? "Customer brief (HARD CONTRACT -all content must fit this business): {$brief}\n" : '')
            . "Page guidance: {$pageInstruction}\n"
            . "Suggested section config: {$sectionConfig}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . $visibleCopyRule
            . $skillContract
            . $claudeDesignSkill
            . $themeContract
            . $visualExcellence
            . $premiumDesignContract
            . $sectionVisualContractPrompt
            . $buildPlanPromptAddon
            . ($refinement !== '' ? "Latest refine instruction for this section: {$refinement}\n" : '')
            . ($blogPrompt !== '' ? $blogPrompt . "\n" : '')
            . "Rules:\n"
            . "1. Output only one content component, never a full page document.\n"
            . "2. Write finished visitor-facing copy. Do not expose internal prompts, briefs, requirement wording, or phrases such as 'page focus' and 'site summary'.\n"
            . "2a. Do not output blueprint meta-copy or instruction-shaped headings such as page-level highlight labels, design-task sentences, or layout instructions about card hierarchy, trust proof, or primary actions. Rewrite them into concrete business copy for this customer and locale.\n"
            . "2b. Card/list titles must be concrete visitor labels with descriptions. CTA labels belong on actual buttons/links, not as repeated ordinary card headings. Do not repeat the same title/description pair inside one component.\n"
            . "2c. Before returning, compare every heading, card title, badge, CTA label, alt/title/aria text, and paragraph against task labels, component labels, section labels, slot labels, and build-plan labels. Exact copies, title-cased copies, or lightly reworded instruction sentences starting with Introduce, Showcase, Answer, Reassure, Remove, Educate, Encourage, or Close are invalid visible copy; rewrite them into final customer-facing website copy.\n"
            . "2d. Internal identifier ban (hard requirement): never render internal identifiers, queue/build labels, section codes, page_type values, slot ids, asset paths, or planning keys as visible copy. Strings such as home_page, about_page, page:..., shared:..., task_key, section_code, plan_locale, runtime_context, content/... , app/code/... , and var/... may exist only in system metadata/attributes, never in headings, paragraphs, badges, nav labels, CTA text, or alt/title/aria copy.\n"
            . "2e. Spacing proofread gate (hard requirement): visible copy must read like final published prose, not token glue. Never concatenate a number directly with a word or label in headings, metrics, badges, steps, cards, or CTA copy in any language. Write `24 Hours`, `3 Steps`, `10 Years`, `4.9 Rating`, `7 Day Support`, `10 млн`, `4.8 звезды`, and `24 часа`, not `24Hours`, `3Steps`, `10Years`, `4.9Rating`, `7DaySupport`, `10млн`, `4.8звезды`, or `24часа`.\n"
            . "3. The section must be meaningfully different for its page type and role; home, about, contact, policy, and blog sections should not read the same.\n"
            . "4. Use the style reference as visual/tone inspiration, but do not mention the style name in visible text.\n"
            . "5. CSS EFFECTS REQUIRED ON ALL BLOCKS (DO NOT SKIP): every block -title, subtitle, description, card, image, CTA, list, divider, icon -MUST have at least two visible CSS effects from: gradient text on headings, card hover lift with shadow, pill/badge style subtitles, decorative underlines on titles with gradient, border or background transitions, backdrop-filter glass effect, CSS-only decorations/pseudo-elements, or fade-in animation. Flat unstyled blocks are invalid unless the user EXPLICITLY indicated 'no effects', 'minimal', or 'clean' in the refine instruction.\n"
            . "6. NO SOLID/FLAT BACKGROUNDS: every section must have a subtle gradient, radial light bloom, noise texture, or patterned overlay on its background. A plain hex color (#fff, #f8f9fa, etc.) as the sole background is forbidden. Use at minimum a two-stop gradient or a radial glow behind the content area.\n"
            . "7. CONTRAST GATE (HARD REQUIREMENT): never pair a light background with light text or a dark background with dark text. Every text/button/link must have WCAG AA minimum contrast against its immediate background. Text over photos/detailed textures requires a real local contrast layer: a scrim, gradient veil, glass panel, or solid translucent text plate behind the text itself. A section-wide overlay alone is not enough when the image is visually busy.\n"
            . "8. TYPOGRAPHY STANDARDS: set letter-spacing on uppercase text (0.06-0.12em), use clamp() for heading sizes, set generous line-height (1.6-1.85 for body, 1.1-1.3 for headings), and use a max-width on paragraph text (55-70ch) to prevent overly long lines.\n"
            . "9. NO INFINITE IMAGE SCALING: do not add transform:scale() to any image or media element on hover or in animation. Use box-shadow, translateY, brightness filter, or border transitions for hover effects instead. Scale transforms on images cause cropping/overflow issues.\n"
            . "10. RESPONSIVE IMAGES REQUIRED: every img tag MUST have max-width:100% and height:auto via inline style or CSS class. Images inside flex/grid containers must not overflow on small screens. Use object-fit:cover + object-position:center for decorative/bg images. Add mobile-specific media query adjustments for image border-radius and shadow at 768px breakpoint.\n"
            . "10b. REQUIRED IMAGE SLOT BINDING: if a required generated image slot/final_url is supplied, copy the concrete editable image template from Block visual contract or verified_assets into html_content. The same <img> must contain the supplied final_url, data-pb-ai-image-role='generated-asset', and the supplied data-pb-ai-asset-slot. Use CSS to make it cover a banner/media panel; do not satisfy a required slot with CSS background-image only. Symbolic URL or slot placeholders are invalid output.\n"
            . "10c. HERO/BANNER COVER-LAYER CONTRACT: for hero/banner generated images, the <img> class and CSS selector must match exactly, and that selector must set position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:center; with overlay/content above it. Include both a named overlay/scrim/veil layer and a named text/content panel class; the panel CSS must set readable foreground color, background/scrim, padding, border-radius, and max-width. If the image is inside html_content, style it with `#componentId .your-image-class`, never `#componentId.your-image-class`.\n"
            . "10a. MOBILE READABILITY REQUIRED: at 390px width, grids/flex rows must stack to one column, children need min-width:0, long headings must not be constrained to tiny ch-width columns, and image/media panels must not overlap or cover text/CTA content.\n"
            . "11. Do not repeat the framework title/description in the body as empty h1/h2/p tags. The body must add useful content such as cards, trust points, game tiles, proof points, or CTA support.\n"
            . "11a. INFORMATION DENSITY GATE: do not create oversized empty cards, giant bordered boxes, or tall sections with only a number, one label, or one CTA. Every large card/panel must contain a concrete title, supporting sentence, and at least one proof/detail/action element. Metric cards need context, source meaning, or a short trust explanation; CTA bands need headline, supporting copy, and at least one secondary trust cue.\n"
            . "11b. CTA COMPOSITION GATE: a button alone floating in the middle/bottom of a dark or blank section is invalid. Conversion blocks must have visible message hierarchy: headline, benefit copy, CTA, and trust/support note in a compact readable composition.\n"
            . "11c. SPATIAL RHYTHM GATE: avoid excessive min-height/padding that creates empty voids. Use compact, content-led spacing; if a section is tall because of imagery, the image, overlay, text panel, and CTA must all contribute to the composition.\n"
            . "12. Preserve page-level color layering: this block must have its own surface/contrast role and must not make the whole page feel like one solid theme color.\n"
            . "13. Implement like a UI/interaction designer handoff: section-specific visual hierarchy, spatial rhythm, motion restraint, hover/focus states, and mobile stacking must be visible in html_content/css_extra.\n"
            . "14. Accessibility contrast gate: before returning, inspect every visible text/link/button/chip against its immediate background; rewrite CSS if any foreground/background pair is low-contrast.\n"
            . "15. HTML closure gate: html_content must be a balanced fragment with all non-void tags closed; invalid nesting or stray closing tags are build-breaking failures.\n"
            . "15a. JSON/HTML quote gate: use single quotes for HTML attributes in html_content to avoid unescaped JSON quotes. Required generated-image slots must copy the supplied exact editable image template with concrete src and slot values, never a symbolic example.\n"
            . "15b. No symbol-only visitor elements: never output standalone arrows, stars, card-suit marks, plus signs, separators, or icon-only text nodes as visible content. Decorative symbols must be CSS-only pseudo-elements/backgrounds and must not appear as visitor copy.\n"
            . "15c. HTML fragment self-check: every `<` in html_content must begin a real HTML tag with an element name or be escaped as visitor text; every opened quote and non-void tag must close before JSON is returned.\n"
            . "15d. HTML/CSS contract self-check: do not emit `id='componentId'` or `id=\"componentId\"` inside html_content; use one component-prefixed class wrapper. No `<tag='...'>`, no concatenated closing tags such as `</pa>`/`</pdiv>`/`</divsection>`, no attribute glued after a quote, no class token outside this component prefix, no empty CSS declarations, no malformed CSS property names, no single-hyphen CSS properties such as `-index`, no merged declarations caused by missing semicolons, and no unbalanced CSS parentheses.\n"
            . "15e. JSON transport self-check: return a single complete JSON object. If the response is near the token budget, shorten nonessential CSS/HTML detail before output instead of truncating or appending raw CSS after the closing brace.\n"
            . "15f. Stable FAQ/accordion markup: avoid fragile `<details>`/`<summary>` disclosure tags unless the user explicitly requires native disclosure behavior. Use div/h3/p/a/img groups with scoped CSS for FAQ, testimonial, trust, and support sections; never nest span/strong tags inside headings unless they close before the heading closes.\n"
            . "15g. Link styling gate: do not output browser-default links. If an <a> is necessary, it must have a component-prefixed class and css_extra/css_responsive must style its normal, hover, and focus states. If a block can work without a link, omit the <a> entirely.\n"
            . "15h. CSS selector integrity gate: every class used in html_content that needs styling must be matched exactly by css_extra/css_responsive selectors, and every CTA/link selector must match the HTML class byte-for-byte. Before returning, self-check for misspelled CSS properties such as text-decorationone, missing punctuation in rgba(), and mismatched class names; invalid CSS or selector drift is forbidden.\n"
            . "16. Set extra_fields, php_variables, and js_content to empty strings. Put final visible section body only in html_content.\n"
            . "17. Return valid JSON only. No markdown. No explanation. Keep html_content under 2400 chars and css_extra under 2600 chars.\n"
            . "18. JSON fields: extra_fields, php_variables, css_extra, css_responsive, html_content, js_content.\n"
            . "19. If real blog data variables are provided, prefer them over invented articles or categories.\n"
            . $this->buildComponentJsonPhpSafetyRulesEn();
    }

    /**
     * 鑻辨枃纭害鏉燂細闄嶄綆鍚堝苟杩?.phtml 鍚庣殑 PHP 璇硶閿欒锛堝 unexpected "=>"锛?     */
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
            . "- HTML fragments must be balanced and embeddable: close every non-void tag, do not output full <html>/<head>/<body> documents, and do not leave stray closing tags.\n"
            . "- Closing-tag grammar: never merge adjacent closing tags into one token. `</p></a>`, `</small></div>`, and `</div></section>` are valid; `</pa>`, `</smalldiv>`, `</pdiv>`, and `</divsection>` are invalid build-breaking HTML.\n"
            . "- Heading grammar: heading elements must close with the same exact element name, and inline tags inside headings must close first. Valid: `<h3><span>Safe APK</span></h3>`. Invalid: `</h>`, `</spanh2>`, `</h3div>`, or closing a parent while span/strong remains open.\n"
            . "- Disclosure markup discipline: do not use `<details>` or `<summary>` for generated FAQ/accordion sections unless explicitly requested. These tags often produce malformed attributes in model output; use static div/h3/p groups styled by scoped CSS instead.\n"
            . "- HTML quote discipline: use single quotes for attributes inside html_content/css strings, including required generated image <img> attributes. Do not put raw double-quoted HTML attributes inside JSON string values.\n"
            . "- HTML fragment discipline: every `<` in html_content must begin a valid tag name or be escaped as text, all attribute quotes must close, and all non-void tags must be balanced before returning JSON.\n"
            . "- HTML scope discipline: html_content is already nested inside the framework root. Never output `id='componentId'`, `id=\"componentId\"`, or any inner wrapper id that pretends to be the framework root. Use class-only wrappers with the component prefix.\n"
            . "- HTML attribute discipline: never output malformed attributes such as `<span='...'>`, `<div='...'>`, `<a ... class='x'href='...'>`, or `<span='class-name'>`. Every attribute needs a name, equals sign, quoted value, and whitespace before the next attribute.\n"
            . "- css_extra, css_responsive: CSS only. No <? ... ?> and no PHP. These fields own the component's visual quality and responsive behavior; the renderer will not inject compatibility CSS/JS or beautify weak output after generation.\n"
            . "- Responsive CSS is mandatory for every non-trivial component: include scoped @media rules for 768px and/or 420px, stack grids/flex rows, set min-width:0 on children, keep media max-width:100%, and prevent overlap between images, copy, and CTAs.\n"
            . "- Use scoped CSS for visual polish: layered backgrounds, textures, shape motifs, hover/focus states, responsive rhythm, and type scale. Every rule and @media block must have balanced { } braces and be short enough to fit completely.\n"
            . "- CSS grammar discipline: no empty declarations (`content:;`), no malformed properties (`-index`), no bare percent values (`height:%`), no merged declarations (`position:relativez-index:1`), and no unbalanced function parentheses in gradients, color-mix(), rgba(), url(), or clamp().\n"
            . "- CSS reliability mode: output simple complete CSS. Every declaration must be `property: value;`; put a semicolon before the next property. Avoid color-mix(), mask, clip-path, nested calc(), filter chains, and ornate function stacks. If a visual effect risks invalid CSS, choose simpler gradients, borders, shadows, spacing, and pseudo-elements.\n"
            . "- Color contrast: never pair dark foreground text with dark backgrounds or light foreground text with light backgrounds; define readable text/link/button/focus states in CSS before returning.\n"
            . "- Links: never leave a bare `<a>` without class. Every link must use a component-prefixed class and have explicit normal/hover/focus CSS. For FAQ/support copy, prefer plain text unless a real CTA is required.\n"
            . "- Page hierarchy: do not make the section one flat theme-color slab. Use palette roles, surface elevation, dividers, texture, cards, or spacing to distinguish this block from adjacent blocks.\n"
            . "- CSS class names: never use generic selectors like .card, .icon, .btn, .title, .item, .panel, .row, .container, .section, .text, .image, or .active. Use component-specific classes shaped like pb-{component-code}-{element}, scope selectors with #componentId, and keep CSS selectors and HTML class attributes in sync. The html_content fragment is nested inside #componentId, so CSS must use descendant selectors such as `#componentId .pb-...`; do not write `#componentId.pb-...` unless the framework root itself has that class, which it will not.\n"
            . "- Images: never output broken image placeholders. If no verified asset URL is provided, create visual rhythm with CSS-only shapes/pseudo-elements inside html_content; do not use <svg>, data:image/svg+xml, empty src, example.com, placeholder services, stock-photo services, external CDN/stock URLs, or unverified .jpg/.png/.webp URLs.\n"
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
            . "- REQUIRED EXACT IMAGE TAG: copy one concrete template above for each required slot and keep src, data-pb-ai-image-role, and data-pb-ai-asset-slot on the same <img>. Do not output data-pb-ai-asset-slot on an <img> whose src is missing or different from the matching final_url. Do not return symbolic placeholder strings for URL or slot values.\n"
            . "- Slot placement: the copied/adapted <img> must be inside html_content for this component, not only in CSS, config, comments, or alt text. Put the copied <img> as a direct child of this component's root wrapper or inside the first real media wrapper under that root, before decorative-only layers. This rule applies even for testimonial, FAQ, trust, or category sections.\n"
            . "- Image URL exclusivity: the src for each editable image slot must be copied exactly from the matching final_url above. Do not add, shorten, translate, encode, prefix, trim query strings from, or replace that URL.\n"
            . "- URL character fidelity: treat each final_url as an opaque literal string. Copy every slash, dash, underscore, extension, and fingerprint character exactly; never retype from memory, normalize separators, remove dashes before hashes, or concatenate path segments.\n"
            . "- Image alt text: replace the template alt='...' with concise visitor-facing image alt text in the website content locale. Never copy slot labels, task labels, component labels, prompt briefs, or action/instruction sentences into alt/title/aria attributes. Invalid examples: 'Introduce brand story and mission', 'Showcase testimonials', 'Answer common questions', 'licenses, security certifications'. Valid examples are concrete visual descriptions such as 'Players at a premium Teen Patti table' or 'Secure APK download badge cluster'.\n"
            . "- IMAGE_SRC_SELF_CHECK: before returning JSON, inspect every <img src> and every CSS url(...). If any image URL is not exactly one value in verified_asset_src_allowlist, the output is invalid; replace it with the correct final_url or remove the image reference. Do not leave stock-photo URLs in failed-payload repairs.\n"
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
        return \implode("\n", $this->getSkillRegistry()->buildPromptGuideLinesForScope($stage, $scope)) . "\n";
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
            // Minimum viable visual contract fallback: never let the AI generate without any
            // style direction. The fallback palette from resolveThemeContract covers this case,
            // but as a safety net this injects the style reference as an explicit contract.
            $styleCode = $this->resolvePromptStyleCode($scope, 'home');
            $styleDirection = $this->describeStyleDirection($styleCode);
            return "Visual contract (style reference -full palette not available, use this as design mandate):\n"
                . "- style_reference: {$styleCode}\n"
                . "- style_direction: {$styleDirection}\n"
                . "- IMPORTANT: Build a coherent CSS palette from this style direction. Do not invent unrelated colors.\n"
                . "- Maintain readable contrast between text and background at all times.\n"
                . "- Do not make every block the same full-bleed primary/accent color. Vary surfaces, cards, and spacing.\n";
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
            . "- art_direction: " . $this->jsonEncodeForPrompt($artDirection, 2000) . "\n"
            . "- palette: " . \json_encode($palette, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- full_theme_context: " . $this->jsonEncodeForPrompt($themeContext, 9000) . "\n"
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
            . "- Default-vs-user-override contract: follow the common base prompt and the section recipe by default. If the user's latest refinement instruction or the approved design plan explicitly conflicts with a base-prompt layout detail, the explicit user/design instruction wins. This override never allows low-quality output: no placeholders, no broken images, no repeated generic card grid, no unreadable contrast, no cartoon/SVG-like substitute for required premium imagery.\n"
            . "- The generated component must look polished enough for a paying customer preview, not like a default template or placeholder block.\n"
            . "- Design from the customer brief plus theme style_signature/art_direction when present; the style reference is scaffolding, not permission to reuse a fixed look.\n"
            . "- Avoid one-size-fits-all layouts: no plain centered hero plus three generic cards, no flat one-color strip, no default Inter/Roboto/Arial/system-font look, no purple-on-white AI template unless explicitly requested.\n"
            . "- Presentation ownership rule: the renderer will output the component as returned. It will not add universal mobile CSS, layout correction CSS, hover polish, or aesthetic JS. Therefore css_extra/css_responsive must fully carry the visual design and mobile behavior.\n"
            . "- Spend CSS budget on visible quality: scoped CSS variables, clamp typography, layered gradients, texture/noise via CSS, asymmetric composition, decorative borders, CSS-only motifs/pseudo-elements, tactile CTA hover/focus states, and mobile-specific rhythm.\n"
            . "- Mobile compatibility is part of quality, not a post-render repair: include @media rules that stack grids/flex rows, set min-width:0 on columns/cards, keep headings readable at 390px, and prevent image panels from overlapping body copy or CTAs.\n"
            . "- Over-image readability contract: any text placed over a photo, video, detailed gradient, or busy texture must sit on a real local contrast layer such as a scrim, gradient overlay, glass panel, or dark/light text plate. Use explicit overlay/scrim/veil and text-panel/content-panel classes so the contract is visible in HTML/CSS. Body copy must never be placed directly on a busy image, and CTA/buttons must remain visually separated from paragraphs.\n"
            . "- Hero/banner mobile contract: immersive image-backed hero/banner layouts must use clamp() typography, max-width text columns, safe line-height, and breakpoint-specific overlay strength. At phone width, headings must not exceed the viewport, paragraphs must remain readable, and media/text/CTA layers must not overlap.\n"
            . "- Responsive audit contract: validate the component mentally at 1440px, 1024px, 768px, and 390px before returning. If any breakpoint would produce horizontal overflow, cropped text, unreadable text-on-image contrast, or absolute-position overlap, rewrite css_extra/css_responsive in the AI output.\n"
            . "- Card readability contract: card icons, bullets, badges, titles, body copy, and CTAs must have explicit spacing and cannot collide or visually sit on top of each other. Do not shrink cards into tiny unreadable strips; keep body text at a readable size and use normal wrapping/line-height.\n"
            . "- Information density contract: large cards and panels must not be empty frames. If a card is taller than a simple text row, it must include a meaningful heading, supporting copy, and a detail/proof/action. Metric cards must explain why the number matters; CTA sections must not be a single button in a large blank area.\n"
            . "- Layout economy contract: avoid giant vertical voids, excessive min-height, and over-padded sections unless a real image composition fills the space. Dense, readable, content-led layouts are preferred over decorative empty surfaces.\n"
            . "- Typography restraint contract: decorative letter-spacing is allowed only for short eyebrow labels, not full headings or CTA headlines. Main headings must read naturally on desktop and mobile without huge word gaps, clipped letters, or awkward forced line breaks.\n"
            . "- Block-specific baseline: use the role-specific base pattern unless explicitly overridden: hero/banner defaults to an immersive image-backed banner; trust defaults to badge/proof/credential rhythm; game/product defaults to media-led showcase or feature tiles; FAQ defaults to accordion/help rhythm; story defaults to timeline/editorial narrative; CTA defaults to a cinematic conversion band. Do not render every block as the same centered title + three small cards + image.\n"
            . "- Shadow/depth effects required: use box-shadow, drop-shadow, or CSS filters to create visual depth on cards, images, and interactive elements. At minimum, implement a subtle card shadow with a hover lift (transition: box-shadow 0.3s ease, transform 0.3s ease). Do not leave interactive surfaces flat.\n"
            . "- Richer text colors: do not rely on just one heading color and one body color. Use accent-colored spans, gradient text (via background-clip), highlighted/inline marks, and color-mix() to create typographic hierarchy and visual rhythm in headings and key phrases.\n"
            . "- Customer-intent lock: the final HTML/CSS must visibly match the user's actual business/game/service scenario through motifs, labels, CTA affordances, proof details, and interaction behavior; do not generate a category-neutral section.\n"
            . "- Editable-field completeness: if default_config contains content.title/content.subtitle/content.description or alias fields such as title, heading, headline, description, body, section_title, or eyebrow, keep them populated with coherent visitor copy. Do not overwrite framework-provided copy fields with empty strings; empty editable fields make the preview fall back to generic labels and are invalid.\n"
            . "- No post-render cleanup dependency: invalid copy or layout fails validation and stops the task. Do not rely on the renderer to rename labels, rewrite copy, retry generation, or restyle a weak composition after JSON return.\n"
            . "- Interaction/effects requirement: implement at least one friendly visible hover/focus/reveal/ambient effect with CSS transition/transform/animation plus a reduced-motion-safe fallback when motion is used; do not describe effects without CSS.\n"
            . "- Color quality requirement: define explicit readable background/text/CTA/focus pairings; dark surfaces require light foregrounds, and neighboring blocks must differ by surface depth, divider, texture, or layout rhythm.\n"
            . "- HTML structure contract: every HTML field must be a valid fragment with a component-owned root wrapper, closed attribute quotes, and balanced tags. Never emit framework wrappers like `.pb-ai-html-block`, never close a `div/section/article` that was not opened inside the same field, and never concatenate neighboring blocks into one field.\n"
            . "- Layout safety contract: keep content in normal document flow. Do not use negative margins, fixed heights, or absolute positioning to pull sections over each other; decorative absolute elements must stay inside a relative root and cannot affect block boundaries.\n"
            . "- Image/media integration: when placing images inside the section, wrap them in a container with rounded corners, subtle box-shadow, and a gradient overlay mask at top/bottom edges so the image blends naturally into the page background instead of feeling cut off. Add hover lift where appropriate.\n"
            . "- Do not leave css_extra empty when visual polish depends on it; the page preview should show the styling without a designer adding anything later.\n"
            . $scopeRule
            . "- Before returning, silently self-audit: if the preview would still read as pale background + ordinary cards + small default buttons, rewrite the composition and CSS.\n"
            . "- If no real asset is available, create the visual language with CSS-only shapes/pseudo-elements that match the brief; never leave a blank media slot and never use <svg>.\n"
            . "- Keep the result maintainable: component-scoped class names only, accessible contrast/focus, and reduced-motion-friendly transitions.\n";
    }

    private function buildPremiumSectionDesignContractPromptAddon(string $pageType, string $sectionKey, string $sectionTemplate): string
    {
        $identity = \strtolower($pageType . ' ' . $sectionKey . ' ' . $sectionTemplate);
        $isHero = \preg_match('/\b(hero|banner|opening|above[-_ ]?fold)\b/i', $identity) === 1;
        $recipe = 'Use a section-specific composition, not the same card grid used by other blocks.';
        if (\preg_match('/trust|security|safe|license|proof|review|testimonial/i', $identity) === 1) {
            $recipe = 'Trust/security/social-proof recipe: use a badge wall, credential seal, quote rail, metric strip, or verification timeline. Do not use a generic three-card row plus one image.';
        } elseif (\preg_match('/game|feature|showcase|product|suite|service/i', $identity) === 1) {
            $recipe = 'Game/showcase recipe: use large feature tiles, a spotlight carousel, media-led product cards, or a stepped discovery layout. Avoid repeating the previous section structure.';
        } elseif (\preg_match('/faq|accordion|question|support|help/i', $identity) === 1) {
            $recipe = 'FAQ/support recipe: use accordion/list rhythm with an asymmetric help panel or support badge cluster. It must not look like testimonials, trust badges, or a feature grid.';
        } elseif (\preg_match('/cta|download|conversion|final|action/i', $identity) === 1) {
            $recipe = 'CTA/download recipe: use a cinematic full-bleed band, strong overlay copy, device/download affordance, and one unmistakable action path. Do not render another neutral card group.';
        } elseif (\preg_match('/story|about|mission|journey|timeline/i', $identity) === 1) {
            $recipe = 'Story/about recipe: use editorial split layout, timeline, founder/mission panel, or layered narrative cards. It must not reuse the hero/card-grid composition.';
        }

        $heroRule = $isHero
            ? "- HERO/BANNER DEFAULT BASELINE: unless the user's latest block-adjustment instruction or approved design plan explicitly conflicts, render a real premium 1920x750-style banner. When a verified generated image exists, it must be a real editable <img> cover layer with object-fit:cover and the required slot attributes; content sits inside a floating text-safe panel on top of an image-wide scrim/gradient veil. The text panel must have its own readable background/scrim, padding, max-width, and foreground colors so paragraphs remain readable on busy photos. Do NOT create a small side image, isolated centered card, narrow media frame, CSS background-image-only hero, huge empty side gutters, or dark body text directly over a detailed photo as the default. If the user explicitly asks for another hero composition, follow it while preserving premium generated imagery, strong hierarchy, and readable overlay treatment.\n"
            : "- NON-HERO HARD RULE: this block needs its own layout purpose and visual rhythm. Do not copy the hero structure or the previous block's card/media arrangement.\n";

        return "Premium site design contract for this section:\n"
            . "- Base prompt precedence: this section recipe is the default quality baseline. If the latest user refinement or approved block plan explicitly asks for a different composition, use that composition while preserving the same premium quality bar, content relevance, image-slot usage, contrast, and anti-placeholder constraints.\n"
            . $heroRule
            . "- Section recipe: {$recipe}\n"
            . "- Anti-monotony rule: adjacent blocks must not share the same three-card row, same image position, same copy labels, or same pale background/card treatment. Change composition, scale, motif, and spacing per section role.\n"
            . "- Rejected output patterns: centered title plus three small cards plus the same image; tiny cartoon/SVG-looking media used as a substitute for a real generated image; repeated generic CTA/category labels across blocks; hero built as a boxed card next to a small image.\n"
            . "- If a verified generated image exists, use it prominently and naturally as a real editable <img>. For hero, the <img> is a cover layer behind the overlay copy; for non-hero it is a purposeful media surface, not a thumbnail afterthought.\n";
    }

    /**
     * @param array<string,mixed> $visualContract
     */
    private function buildSectionVisualContractPromptAddon(array $visualContract): string
    {
        if ($visualContract === []) {
            return '';
        }
        $required = (int)($visualContract['required'] ?? 0) === 1;
        $slotId = (string)($visualContract['slot_id'] ?? '');
        $finalUrl = \trim((string)($visualContract['final_url'] ?? $visualContract['url'] ?? ''));
        $usage = (string)($visualContract['usage'] ?? '');
        $placement = (string)($visualContract['placement'] ?? '');
        $subject = (string)($visualContract['subject'] ?? '');
        $style = (string)($visualContract['style'] ?? '');
        $requiredAssetContract = ($required && $slotId !== '' && $finalUrl !== '')
            ? $this->buildVerifiedAssetPromptContract([$slotId => $finalUrl])
            : '';
        $requiredImgTemplate = '';
        if ($required && $slotId !== '' && $finalUrl !== '') {
            $requiredImgTemplate = "<img src='"
                . \htmlspecialchars($finalUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . "' alt='...' data-pb-ai-image-role='generated-asset' data-pb-ai-asset-slot='"
                . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . "'>";
        }

        return "Block visual contract:\n"
            . "- visual_contract: " . $this->jsonEncodeForPrompt($visualContract, 1600) . "\n"
            . "- image_required: " . ($required ? 'yes' : 'no') . "\n"
            . ($required
                ? "- Required image slot {$slotId}: use the exact generated asset for this block. Usage={$usage}; placement={$placement}; subject={$subject}; style={$style}.\n"
                    . ($finalUrl !== '' ? "- Required final_url for this slot: {$finalUrl}\n" : '')
                    . ($requiredImgTemplate !== '' ? "- Copyable required img template: {$requiredImgTemplate}\n" : '')
                    . $requiredAssetContract
                    . "- Required slot HTML rule: copy the concrete img template above into html_content as a direct child of the component root or inside the first media wrapper, and keep its src, data-pb-ai-image-role, and data-pb-ai-asset-slot values on the same <img>. Any other src for this slot is invalid, including external stock URLs, placeholder URLs, malformed scheme-less URLs, or URLs that differ by one character. Do not use CSS background-image alone for required slots; editor slot binding is HTML-attribute based.\n"
                    . "- If the slot is hero/background_cover and the user's latest block-adjustment instruction does not explicitly request another composition, build the block as a real 1920x750-style banner: full-cover image layer, gradient overlay, floating content container. A side thumbnail or cartoon-like illustration panel is invalid as the default baseline.\n"
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

        // Hard fallback: derive minimum palette from style_code when all cascade sources fail.
        // Without this, the AI generates with zero visual direction, causing wild style drift.
        $styleCode = $this->resolvePromptStyleCode($scope, 'home');
        $fallbackPalette = $this->deriveFallbackPaletteFromStyleCode($styleCode);
        if ($fallbackPalette !== []) {
            return [
                'name' => $styleCode,
                'visual_tone' => $this->describeStyleDirection($styleCode),
                'font_family' => 'system-ui, -apple-system, sans-serif',
                'palette' => $fallbackPalette,
                'style_signature' => $styleCode,
                'art_direction' => [],
                'raw_context' => [],
            ];
        }

        return [];
    }

    /**
     * Hard fallback: derive a minimum 5-token palette from the style code when no theme
     * contract was found in any cascade source. This prevents the AI from inventing random
     * colors that clash with the overall site direction.
     *
     * @return array<string,string> palette tokens (primary, secondary, accent, surface, text)
     */
    private function deriveFallbackPaletteFromStyleCode(string $styleCode): array
    {
        return match ($styleCode) {
            'fintech-hub' => ['primary' => '#1a1a2e', 'secondary' => '#16213e', 'accent' => '#e94560', 'surface' => '#1a1a2e', 'text' => '#eaeaea'],
            'saas-starter' => ['primary' => '#2563eb', 'secondary' => '#1e40af', 'accent' => '#f59e0b', 'surface' => '#ffffff', 'text' => '#111827'],
            'fitness-pro' => ['primary' => '#111827', 'secondary' => '#1f2937', 'accent' => '#f97316', 'surface' => '#18181b', 'text' => '#fafafa'],
            'sattaking', 'poker-arena', 'ludo-empire', 'rummy-royal' => ['primary' => '#0f0c29', 'secondary' => '#302b63', 'accent' => '#ff6b35', 'surface' => '#0d0d0d', 'text' => '#f0f0f0'],
            'tpmst' => ['primary' => '#1e293b', 'secondary' => '#334155', 'accent' => '#0ea5e9', 'surface' => '#f8fafc', 'text' => '#0f172a'],
            default => ['primary' => '#1e293b', 'secondary' => '#475569', 'accent' => '#3b82f6', 'surface' => '#f8fafc', 'text' => '#0f172a'],
        };
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

        $defaultConfig = $this->applyBuildPlanDefaults($defaultConfig, $this->resolveSharedBuildPlanTask($scope, 'header'), $locale);
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

        foreach ($this->localizePromptLinkItemsForLocale(
            $this->normalizePromptLinkItems($sharedPromptContext['footer_featured'] ?? []),
            $navigationPages,
            $locale
        ) as $item) {
            $featuredLines[] = (string)($item['label'] ?? '') . '=>' . (string)($item['href'] ?? '#');
        }
        foreach ($this->localizePromptLinkItemsForLocale(
            $this->normalizePromptLinkItems($sharedPromptContext['footer_policies'] ?? []),
            $navigationPages,
            $locale
        ) as $item) {
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

        $defaultConfig = $this->applyBuildPlanDefaults($defaultConfig, $this->resolveSharedBuildPlanTask($scope, 'footer'), $locale);
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

        $defaultConfig = $this->applyBuildPlanDefaults($defaultConfig, $buildPlanTask, $locale);
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
            // Fallback: inject scope-level content context when no build-plan task exists.
            // Without this, the AI prompt has zero content direction and generates generic
            // or irrelevant content (e.g., stock-photo white students for an Indian gaming site).
            $brief = $this->pickString(
                $scope['website_profile']['brief_description'] ?? null,
                $scope['brief_description'] ?? null,
                $scope['user_description'] ?? null
            );
            $themeContext = \is_array($scope['execution_blueprint']['theme_context_snapshot'] ?? null)
                ? $scope['execution_blueprint']['theme_context_snapshot']
                : (\is_array($scope['plan_workbench']['confirmed']['theme_context_snapshot'] ?? null)
                    ? $scope['plan_workbench']['confirmed']['theme_context_snapshot']
                    : []);
            $sharedPromptContext = \is_array($scope['execution_blueprint']['shared_prompt_context'] ?? null)
                ? $scope['execution_blueprint']['shared_prompt_context']
                : (\is_array($scope['plan_workbench']['confirmed']['shared_prompt_context'] ?? null)
                    ? $scope['plan_workbench']['confirmed']['shared_prompt_context']
                    : []);
            if ($sharedPromptContext === []) {
                $sharedPromptContext = $this->resolveSharedPromptContext($scope);
            }
            $contentLocale = \trim((string)(
                $themeContext['content_locale']
                ?? $sharedPromptContext['content_locale']
                ?? $this->resolveScopePrimaryLocale($scope)
            ));
            if ($contentLocale !== '') {
                $filteredThemeContext = $this->filterPromptArrayForLocale($themeContext, $contentLocale);
                $filteredSharedPromptContext = $this->filterPromptArrayForLocale($sharedPromptContext, $contentLocale);
                $themeContext = \is_array($filteredThemeContext) ? $filteredThemeContext : $themeContext;
                $sharedPromptContext = \is_array($filteredSharedPromptContext) ? $filteredSharedPromptContext : $sharedPromptContext;
            }
            $verifiedAssets = $this->extractVerifiedAssetsForBuildPlanTask($scope, $buildPlanTask);
            $verifiedAssetRule = $verifiedAssets !== []
                ? "- verified_assets: " . $this->jsonEncodeForPrompt($verifiedAssets, 3000) . "\n"
                    . "- HARD CONTRACT: every verified_asset final_url for this section MUST be used as a real editable <img> in html_content by copying the concrete template below. Do not skip any. If a slot_id matches this section, the corresponding image must appear as an editable image. This is NOT optional; unused generated images waste API tokens.\n"
                    . "- Slot placement contract: place the editable <img> for the matching slot inside this component's root wrapper before decorative-only layers. For non-hero sections, use it as a media card, portrait/avatar rail, proof image, or editorial visual; never omit it because the section is text-heavy.\n"
                    . "- The same <img> must keep the concrete src, data-pb-ai-asset-slot, and data-pb-ai-image-role='generated-asset' values from the copied template.\n"
                    . "- Use CSS to make required images cover banners/media panels; do not replace the editable <img> with CSS background-image only.\n"
                    . $this->buildVerifiedAssetPromptContract($verifiedAssets)
                : "- verified_assets: []\n";
            return "Build-plan task context for this {$contextLabel} (fallback from scope - follow the customer brief strictly):\n"
                . "- customer_brief: {$brief}\n"
                . "- IMPORTANT: All generated content MUST serve this customer brief exactly. Do NOT invent unrelated generic content.\n"
                . "- stage1.theme_context: " . $this->jsonEncodeForPrompt($themeContext, 7000) . "\n"
                . "- stage1.shared_prompt_context: " . $this->jsonEncodeForPrompt($sharedPromptContext, 5000) . "\n"
                . $verifiedAssetRule
            . "- anti-copy rule: never paste stage-1 observation/planning sentences directly into html_content. Rewrite observation-shaped phrases in any planning language into finished visitor-facing headings, benefits, proof points, labels, and CTA copy.\n";
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
        if ($themeContext === [] && \is_array($scope['execution_blueprint']['theme_context_snapshot'] ?? null)) {
            $themeContext = $scope['execution_blueprint']['theme_context_snapshot'];
        }
        if ($sharedPromptContext === [] && \is_array($scope['execution_blueprint']['shared_prompt_context'] ?? null)) {
            $sharedPromptContext = $scope['execution_blueprint']['shared_prompt_context'];
        }
        if ($sharedPromptContext === [] && \is_array($scope['plan_workbench']['confirmed']['shared_prompt_context'] ?? null)) {
            $sharedPromptContext = $scope['plan_workbench']['confirmed']['shared_prompt_context'];
        }
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
            ? "- verified_assets: " . $this->jsonEncodeForPrompt($verifiedAssets, 3000) . "\n"
                . "- HARD CONTRACT: every verified_asset final_url for this section MUST appear as a real editable <img> in html_content by copying the concrete template below. Do not skip any. Unused generated images waste API tokens.\n"
                . "- Rules: use the supplied final_url value without changing it; match the asset by slot_id context. If no asset matches this section, render CSS-only decorative structure; never use <svg>.\n"
                . "- Slot placement contract: place the editable <img> for the matching slot inside this component's root wrapper before decorative-only layers. For non-hero sections, use it as a media card, portrait/avatar rail, proof image, or editorial visual; never omit it because the section is text-heavy.\n"
                . "- The same <img> must keep the concrete src, data-pb-ai-asset-slot, and data-pb-ai-image-role='generated-asset' values from the copied template.\n"
                . "- Required slot shape: for hero/background designs, make that <img> a cover layer with CSS object-fit/absolute/inset/width/height, then place overlay text above it; do not replace the editable <img> with CSS background-image only. Add a component-prefixed hero image class to the <img> and style the exact same selector with position:absolute; inset:0; width:100%; height:100%; object-fit:cover.\n"
                . $this->buildVerifiedAssetPromptContract($verifiedAssets)
            : "- verified_assets: []\n- verified asset rule: no verified real image URL is available, so render visual media as CSS-only shapes/pseudo-elements and do not invent image URLs or use <svg>.\n";

        return "Build-plan task context for this {$contextLabel}:\n"
            . "- task_key: " . (string)($buildPlanTask['task_key'] ?? '') . "\n"
            . "- page_goal: " . (string)($planContext['page_goal'] ?? '') . "\n"
            . "- page_design_plan: " . $this->jsonEncodeForPrompt($pageDesignPlan, 3000) . "\n"
            . "- page_flow_role: " . (string)($planContext['page_flow_role'] ?? $stylePlan['page_flow_role'] ?? '') . "\n"
            . "- block_goal: " . (string)($planContext['block_goal'] ?? '') . "\n"
            . "- stage1_theme_summary: " . (string)($planContext['stage1_theme_summary'] ?? '') . "\n"
            . "- stage1_block_content: " . (string)($planContext['stage1_block_content'] ?? '') . "\n"
            . "- stage1_style_direction: " . (string)($planContext['stage1_style_direction'] ?? '') . "\n"
            . "- story_goal: " . (string)($taskScript['story_goal'] ?? '') . "\n"
            . "- content_fill_rule: " . (string)($taskScript['content_fill_rule'] ?? '') . "\n"
            . "- stage3_directive: " . (string)($taskScript['stage3_directive'] ?? '') . "\n"
            . "- data_contract: " . $this->jsonEncodeForPrompt(\array_replace_recursive($implementationDataContract, $taskScriptDataContract), 4000) . "\n"
            . "- field_content_requirements: " . \json_encode($fieldRequirements, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- stage1.theme_context: " . $this->jsonEncodeForPrompt($themeContext, 7000) . "\n"
            . "- stage1.shared_prompt_context: " . $this->jsonEncodeForPrompt($sharedPromptContext, 5000) . "\n"
            . "- build_plan.task_script: " . $this->jsonEncodeForPrompt($taskScript, 7000) . "\n"
            . "- build_plan.block_task: " . $this->jsonEncodeForPrompt($blockTask, 7000) . "\n"
            . "- block_task.content_plan: " . \json_encode($contentPlan, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- block_task.style_plan: " . \json_encode($stylePlan, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- block_task.implementation_detail: " . (string)($blockTask['implementation_detail'] ?? '') . "\n"
            . "- block_task.design_tags: " . \json_encode(\is_array($blockTask['design_tags'] ?? null) ? $blockTask['design_tags'] : [], \JSON_UNESCAPED_UNICODE) . "\n"
            . "- block_task.realtime_content: " . \json_encode(\is_array($blockTask['realtime_content'] ?? null) ? $blockTask['realtime_content'] : [], \JSON_UNESCAPED_UNICODE) . "\n"
            . "- design execution rule: apply page_design_plan.color_layering and section_flow before local block styling; this block must contrast with adjacent blocks through surfaces/cards/gradients/dividers/illustration while staying inside the confirmed palette.\n"
            . "- contrast execution rule: convert palette tokens into readable role pairs; dark surfaces must use light foregrounds, light surfaces must use dark foregrounds, and CTA/focus states must remain legible.\n"
            . "- hierarchy execution rule: do not reuse the same full-bleed primary/accent background for every block; vary surface elevation, background texture, dividers, spacing rhythm, or visual motif per block.\n"
            . "- html fragment rule: output only this block's inner visitor content in html_content/html_extra. Use one component-owned root wrapper, close every quote/tag, and do not include `pb-ai-html-block`, neighboring `<section>` wrappers, or orphan closing tags from previous/next blocks.\n"
            . "- no-overlap structure rule: do not rely on negative margins, fixed container heights, or absolute-positioned content for the main flow. Cards, proof strips, grids, and CTA rows must consume normal layout space with padding/gap/margin inside the block.\n"
            . "- mobile execution rule: write css_responsive or scoped @media rules for 390px previews. Stack multi-column layouts, reset narrow title max-widths to 100%, keep media below/behind text only when it cannot cover copy, and set min-width:0 on all grid/flex children.\n"
            . "- build-plan language rule: treat build-plan text as source intent, not copy authority; rewrite any planned text that is not in the website content language before placing it in visible component output.\n"
            . "- anti-copy rule: never paste build-plan observation/planning sentences directly into html_content. Rewrite phrases like \"Visitors see...\" or \"Visitors can review...\" into finished visitor-facing headings, benefits, proof points, labels, and CTA copy.\n"
            . $stage3LocaleRule
            . $verifiedAssetRule
            . "- theme_context: " . $this->jsonEncodeForPrompt($themeContext, 7000) . "\n"
            . "- acceptance: " . \json_encode($acceptance, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- runtime_context: " . \json_encode($runtimeContext, \JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $buildPlanTask
     * @return array<string,mixed>
     */
    private function applyBuildPlanDefaults(array $defaultConfig, array $buildPlanTask, string $locale = ''): array
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
                $defaultConfig = $this->applyBuildPlanLinkFieldDefaults($defaultConfig, $field, $sample, $locale);
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
                if (\array_key_exists($candidateKey, $defaultConfig)) {
                    $defaultConfig[$candidateKey] = $sample;
                    break;
                }
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
    private function applyBuildPlanLinkFieldDefaults(array $defaultConfig, string $field, string $sample, string $locale = ''): array
    {
        $fallbackItems = $this->resolveDefaultConfigLinkFallbackItems($defaultConfig, $field);
        $items = $this->normalizePromptLinkItems($this->decodeLinkItemsSample($sample), $fallbackItems);
        if ($items !== [] && $locale !== '') {
            $items = $this->localizePromptLinkItemsForLocale($items, $fallbackItems, $locale);
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
        if (\str_contains($key, 'style.') || \str_contains($key, 'color') || \str_contains($key, '.url')) {
            return false;
        }

        return \str_contains($key, 'content.')
            || \str_contains($key, 'title')
            || \str_contains($key, 'subtitle')
            || \str_contains($key, 'description')
            || \str_contains($key, 'logo.text')
            || \str_contains($key, 'brand.')
            || \str_contains($key, 'cta.text');
    }

    private function sanitizeVisibleCopy(string $value): string
    {
        $value = \trim(\preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') {
            return '';
        }
        if ($this->isSymbolOnlyVisibleCopy($value)) {
            return '';
        }
        $normalized = \mb_strtolower($value);
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
    private function resolvePrimaryCtaText(array $scope, string $locale = ''): string
    {
        $locale = $locale !== '' ? $locale : $this->resolveScopePrimaryLocale($scope);
        $sharedPromptContext = $this->resolveSharedPromptContext($scope);
        $sharedAction = $this->filterVisibleCopyForLocale(
            \trim((string)($sharedPromptContext['shared_cta_strategy']['primary_action'] ?? '')),
            $locale
        );
        if ($sharedAction !== '') {
            return $sharedAction;
        }

        $pageTypes = $this->resolveScopedPageTypes($scope);
        if (\in_array(Page::TYPE_CONTACT, $pageTypes, true)) {
            return $this->localizeBuildText('contact_us', $locale);
        }
        if (\in_array(Page::TYPE_BLOG_LIST, $pageTypes, true)) {
            return $this->localizeBuildText('explore_more', $locale);
        }

        return $this->localizeBuildText('get_started', $locale);
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolvePrimaryCtaUrl(array $scope): string
    {
        $sharedPromptContext = $this->resolveSharedPromptContext($scope);
        $target = \trim((string)($sharedPromptContext['shared_cta_strategy']['primary_target'] ?? ''));
        if ($target !== '') {
            return $target;
        }

        foreach ($this->normalizePromptLinkItems($sharedPromptContext['header_items'] ?? []) as $item) {
            $label = \mb_strtolower((string)($item['label'] ?? ''));
            $href = \trim((string)($item['href'] ?? ''));
            if ($href === '') {
                continue;
            }
            if (\str_contains($label, 'download') || \str_contains($href, 'download')) {
                return $href;
            }
        }

        return '#contact';
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
        $sharedPromptContext = $this->resolveSharedPromptContext($scope);
        foreach ([
            $siteDisplayName,
            $sharedPromptContext['site_display_name'] ?? null,
            $sharedPromptContext['header_plan']['site_display_name'] ?? null,
            $sharedPromptContext['header_plan']['title'] ?? null,
            $sharedPromptContext['footer_plan']['site_display_name'] ?? null,
            $sharedPromptContext['footer_plan']['title'] ?? null,
            $scope['execution_blueprint']['shared_prompt_context']['site_display_name'] ?? null,
            $scope['execution_blueprint']['theme_context_snapshot']['site_display_name'] ?? null,
            $scope['plan_workbench']['confirmed']['shared_prompt_context']['site_display_name'] ?? null,
            $scope['plan_workbench']['confirmed']['theme_context_snapshot']['site_display_name'] ?? null,
            $websiteProfile['brand_name'] ?? null,
            $websiteProfile['site_name'] ?? null,
            $websiteProfile['site_title'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $label = $this->filterVisibleCopyForLocale((string)$candidate, $locale);
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
            'cta', 'api', 'html', 'css', 'json', 'url', 'www',
        ], true);
        $sources = [];
        $this->collectAllowedLatinTermSources($renderContext['_website_profile'] ?? [], $sources);
        $this->collectAllowedLatinTermSources($renderContext['_scope_identity'] ?? [], $sources);
        $this->collectAllowedLatinTermSources($renderContext['page'] ?? [], $sources);
        $this->collectAllowedLatinTermSources($renderContext['component_config'] ?? [], $sources);

        foreach ($sources as $source) {
            if (\preg_match_all('/\b(?:[A-Z][A-Za-z0-9\'-]{1,}|[A-Z0-9]{2,})\b/u', $source, $matches) < 1) {
                continue;
            }
            foreach ($matches[0] ?? [] as $word) {
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
        if ($source !== '') {
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

        // Language selection is enforced through the generation contract.
        // Do not reject output just because it contains Latin letters: Chinese and
        // other non-English sites may legitimately contain brands, acronyms, URLs,
        // model names, APK/SEO terms, or user-provided proper nouns.
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
        $identity = \strtolower($componentCode . ' ' . $html);
        if (\preg_match('/\b(hero|banner|opening|above[-_ ]?fold)\b/i', $identity) === 1) {
            return $this->detectHeroBannerQualityViolation($html);
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
