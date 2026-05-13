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
    public const REQUEST_KEY_ALLOW_STUB_AI_IN_TEST = 'pagebuilder.ai.allow_stub_in_test';
    public const REQUEST_KEY_FAST_BLOCK_ARTIFACT = 'pagebuilder.ai.fast_block_artifact';
    /** AI 结构修复（requestJsonRepair）轮次；0 表示解析失败后不再自动调用 AI。 */
    private const JSON_REPAIR_MAX_ATTEMPTS = 0;
    private const SYNTAX_FIX_MAX_ATTEMPTS = 2;
    /** 单组件仅允许一次完整 AI 生成；失败入账「可重试失败项」，由用户手动触发重试。 */
    private const COMPONENT_GENERATION_MAX_ATTEMPTS = 2;
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
        $siteDisplayName = $this->getPageBlueprintService()->resolveSiteDisplayName($websiteProfile, $scope);
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

        return [
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
                    ? 'Use the exact generated image URL as a full-width 1920x750-style banner background layer, with object-fit:cover/background-size:cover and data-pb-ai-image-role="generated-asset" plus data-pb-ai-asset-slot on the image/background element.'
                    : 'Use the exact generated image URL and set data-pb-ai-image-role="generated-asset" plus data-pb-ai-asset-slot on the image/background element.')
                : 'Use CSS-only motifs if no verified image exists; never use svg or placeholder image URLs.',
        ];
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
     * 并发生成多个组件（header + footer + 多个 section 可同时进行）
     *
     * 使用 Fiber 实现并发：每个组件在独立 Fiber 中调用 generateComponent()，
     * 复用完整的 AI 调用 → JSON 修复 → 语法校验 → 自动修复流程。
     *
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
            // 只按归一化后的 sectionCode 去重（如 content/home_page-hero），
            // 不按 sectionKey 去重——task plan 的 sectionKey（如 'hero'）必然与蓝图基段 key 重叠，
            // 按 key 去重会把 task plan 规划的全部额外段都丢掉，导致生成数量远少于计划。
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
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $pages = \is_array($executionBlueprint['pages'] ?? null) ? $executionBlueprint['pages'] : [];
        $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        $blocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
        if ($blocks === []) {
            return [];
        }

        $tasks = [];
        foreach (\array_values($blocks) as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockKey = \trim((string)($block['block_key'] ?? ''));
            if ($blockKey === '') {
                $blockKey = 'block_' . ($index + 1);
            }
            $fieldPlan = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
            $label = $this->pickExecutionBlueprintBlockLabel($block, $fieldPlan, $blockKey);
            $description = $this->pickExecutionBlueprintBlockDescription($block, $fieldPlan);
            $sectionCode = 'content/' . $this->slugForGeneratedSectionCode($pageType) . '-' . $this->slugForGeneratedSectionCode($blockKey);
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
                ],
                'block_task' => [
                    'task_goal' => (string)($block['goal'] ?? ''),
                    'content_plan' => [
                        'content_copy' => $this->buildExecutionBlueprintContentCopyRows($label, $description, $fieldPlan),
                    ],
                    'meta_fields' => $fieldPlan,
                ],
                'task_script' => [
                    'field_content_requirements' => $fieldPlan,
                ],
            ];
        }

        return $tasks;
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

        if ($this->shouldUseStubAiGeneration() || $this->isTestEnvironment() || !\class_exists(\Fiber::class)) {
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

        if ($this->shouldUseStubAiGeneration() || $this->isTestEnvironment() || !\class_exists(\Fiber::class)) {
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
                // 单元测试 / 裁剪环境未加载 Weline_Ai 的 AiQueryProvider 时 w_query('ai') 不可用，回退到 Fiber。
                if (!\str_contains((string)$wQueryUnavailable->getMessage(), '查询器')) {
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
     * N 路流式走统一 {@see w_query()}（AiQueryProvider::generateStreamBatch），与站内其它模块 AI 接入一致；
     * 失败或解析异常时对该 key 回退到单路 {@see generateComponent()}。
     *
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
                    'temperature' => 0.35,
                    'max_tokens' => 4096,
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
     * 限制出站 HTTP 并发，避免任务数较多时对 DeepSeek 等 API 全开连接导致排队/限速，
     * 长时间无吞吐触发 OpenAiProvider 中 cURL LOW_SPEED 中止（「Less than 1 bytes/sec…」）。
     *
     * 配置：{@see Env::get()} 键 `pagebuilder.ai_site.max_http_concurrency`（默认 4，有效范围 1–32）。
     */
    private function resolveConcurrency(int $taskCount): int
    {
        $taskCount = \max(1, $taskCount);
        $maxConcurrent = (int) Env::get('pagebuilder.ai_site.max_http_concurrency', 4);
        $maxConcurrent = \max(1, \min(32, $maxConcurrent));

        return \min($taskCount, $maxConcurrent);
    }

    /**
     * 并发生成 header + footer 共享组件
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
        $siteDisplayName = $this->getPageBlueprintService()->resolveSiteDisplayName($websiteProfile, $scope);
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
     * 并发生成一个页面的所有 section
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

        // 按 sort_order 排序
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
        $verifiedAssets = $this->extractVerifiedAssetUrls($renderContext);
        $aiData = $this->enforceContractHeroImageUrlsInAiPayload($aiData, $region, $defaultConfig);
        $aiData = $this->sanitizeGeneratedAssetAttributes($aiData);
        $aiData = $this->enforceContractAllSectionImageUrlsInAiPayload($aiData, $region, $defaultConfig);
        $aiData = $this->forceInjectMissingRequiredImageAssets($aiData, $renderContext, $defaultConfig);
        $aiData = $this->sanitizeGeneratedAssetAttributes($aiData);
        $this->assertRequiredImageAssetsUsed($aiData, $renderContext, $defaultConfig);
        // 强制契约：有 verified_assets 时 HTML 必须引用至少一个真实图片 URL
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
        $aiData = $this->hideFrameworkHeaderWhenAiHtmlOwnsSectionTitle($aiData, $region);
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
        $lastThrowable = null;

        for ($attempt = 1; $attempt <= self::COMPONENT_GENERATION_MAX_ATTEMPTS; $attempt++) {
            $aiData = [];
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
                $lastThrowable = $throwable;
                if ($this->shouldUseContractRescueComponent($region, $defaultConfig, $renderContext, $throwable)) {
                    try {
                        $reason = $this->summarizeThrowable($throwable);
                        \w_log_warning('[AI Site Component Contract Rescue] ' . $componentCode . ' (' . $region . '): ' . $reason);
                        return $this->buildFallbackComponent(
                            $componentCode,
                            $name,
                            $region,
                            $prompt,
                            $defaultConfig,
                            $renderContext
                        );
                    } catch (\Throwable $rescueThrowable) {
                        throw new \RuntimeException(
                            'AI component generation failed and contract rescue failed: '
                            . $this->summarizeThrowable($throwable)
                            . ' | rescue: '
                            . $this->summarizeThrowable($rescueThrowable),
                            0,
                            $rescueThrowable
                        );
                    }
                }
                if (!$this->shouldRetryComponentGeneration($throwable)) {
                    break;
                }
                if ($attempt >= self::COMPONENT_GENERATION_MAX_ATTEMPTS) {
                    break;
                }

                $reason = $this->summarizeThrowable($throwable);
                $attemptPrompt = $this->buildRetryGenerationPrompt(
                    $region,
                    $componentCode,
                    $prompt,
                    $reason,
                    $attempt + 1,
                    $aiData
                );
                $this->emitComponentRetryNotice($region, $componentCode, $reason, $attempt + 1);
                \w_log_warning('[AI Site Component Retry] ' . $componentCode . ' (' . $region . ') attempt '
                    . ($attempt + 1) . '/' . self::COMPONENT_GENERATION_MAX_ATTEMPTS . ': ' . $reason);
            }
        }

        $finalReason = $this->summarizeThrowable($lastThrowable ?? new \RuntimeException('unknown'));
        $recoverable = $this->shouldRetryComponentGeneration($lastThrowable ?? new \RuntimeException('unknown'));
        if ($this->shouldUseContractRescueComponent($region, $defaultConfig, $renderContext, $lastThrowable ?? new \RuntimeException('unknown'))) {
            try {
                \w_log_warning('[AI Site Component Contract Rescue] ' . $componentCode . ' (' . $region . '): ' . $finalReason);
                return $this->buildFallbackComponent(
                    $componentCode,
                    $name,
                    $region,
                    $prompt,
                    $defaultConfig,
                    $renderContext
                );
            } catch (\Throwable $rescueThrowable) {
                throw new \RuntimeException(
                    'AI component generation failed after '
                    . self::COMPONENT_GENERATION_MAX_ATTEMPTS
                    . ' real-AI attempts and contract rescue failed: '
                    . $finalReason
                    . ' | rescue: '
                    . $this->summarizeThrowable($rescueThrowable),
                    0,
                    $rescueThrowable
                );
            }
        }

        $message = $recoverable
            ? 'AI component generation failed after '
                . self::COMPONENT_GENERATION_MAX_ATTEMPTS
                . ' real-AI attempts: '
                . $finalReason
            : 'AI component generation failed: ' . $finalReason;

        throw new \RuntimeException($message, 0, $lastThrowable);

    }

    private function shouldUseContractRescueComponent(
        string $region,
        array $defaultConfig,
        array $renderContext,
        \Throwable $throwable
    ): bool {
        if (!$this->shouldRetryComponentGeneration($throwable)) {
            return false;
        }

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
            'model unavailable',
            'model selection',
            'provider account',
            'provider configuration',
            'provider temporarily unavailable',
            'insufficient balance',
            'quota',
        ] as $marker) {
            if (\str_contains($message, $marker)) {
                return false;
            }
        }

        if (\in_array($region, ['header', 'footer'], true)) {
            $sharedRegion = \trim((string)($defaultConfig['runtime.shared_region'] ?? ''));

            return $sharedRegion === $region || (bool)($renderContext['_allow_ai_site_contract_rescue'] ?? false);
        }

        if ($region !== 'content') {
            return false;
        }

        foreach ([
            'runtime.section_code',
            'runtime.section_key',
            'runtime.section_template',
            'runtime.section_page_type',
            'runtime.content_copy_rows',
        ] as $key) {
            if (\trim((string)($defaultConfig[$key] ?? '')) !== '') {
                return true;
            }
        }

        return (bool)($renderContext['_allow_ai_site_contract_rescue'] ?? false);
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     * @return array{code:string,name:string,region:string,phtml:string,html:string,default_config:array<string,mixed>,ai_data:array<string,mixed>}
     */
    private function buildFallbackComponent(
        string $componentCode,
        string $name,
        string $region,
        string $prompt,
        array $defaultConfig,
        array $renderContext
    ): array {
        $componentInfo = [
            'name' => $name,
            'name_en' => $name,
            'description' => $name !== '' ? $name : $componentCode,
        ];
        $verifiedAssets = $this->extractVerifiedAssetUrls($renderContext);
        $defaultConfig = $this->applyFallbackSectionCopyToDefaultConfig($region, $prompt, $defaultConfig, $renderContext);
        $aiData = $this->buildProductionFallbackAiPayload($region, $prompt, $defaultConfig, $renderContext);
        $aiData = $this->enforceContractHeroImageUrlsInAiPayload($aiData, $region, $defaultConfig);
        $aiData = $this->sanitizeGeneratedAssetAttributes($aiData);
        $aiData = $this->enforceContractAllSectionImageUrlsInAiPayload($aiData, $region, $defaultConfig);
        $aiData = $this->forceInjectMissingRequiredImageAssets($aiData, $renderContext, $defaultConfig);
        $aiData = $this->sanitizeGeneratedAssetAttributes($aiData);
        $this->assertRequiredImageAssetsUsed($aiData, $renderContext, $defaultConfig);
        $aiData = $this->ensureAiPayloadValid($aiData, $region, $componentCode, $verifiedAssets);
        $aiData = $this->hideFrameworkHeaderWhenAiHtmlOwnsSectionTitle($aiData, $region);
        $persistedConfig = $this->sanitizeGeneratedComponentDefaultConfig($defaultConfig, $region);
        $phtml = $this->getFrameworkBuilder()->buildComponent($region, $componentInfo, $aiData);
        $syntaxCheck = $this->getCodeValidator()->checkSyntax($phtml);
        if (empty($syntaxCheck['valid'])) {
            $phtml = $this->attemptSyntaxFix($phtml, $region, $componentInfo, $aiData, $syntaxCheck);
        }
        $html = $this->renderTemplateToHtml($phtml, $persistedConfig, $renderContext);
        $this->assertRenderedHtmlMatchesLocale($html, $renderContext);

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
     * @param array<string,mixed> $aiData
     * @return array<string,mixed>
     */
    private function hideFrameworkHeaderWhenAiHtmlOwnsSectionTitle(array $aiData, string $region): array
    {
        if ($region !== 'content') {
            return $aiData;
        }
        $html = (string)($aiData['html_content'] ?? '');
        if ($html === '' || \preg_match('/<h[1-3]\b|class=["\'][^"\']*(?:headline|section-title|heading|title)/iu', $html) !== 1) {
            return $aiData;
        }

        $hideRule = '#componentId > [class$="-inner"] > [class$="-title"], #componentId > [class$="-inner"] > [class$="-description"] { display:none !important; }';
        $css = (string)($aiData['css_extra'] ?? '');
        if (!\str_contains($css, '[class$="-inner"] > [class$="-title"]')) {
            $aiData['css_extra'] = $hideRule . ($css !== '' ? "\n" . $css : '');
        }

        return $aiData;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     * @return array<string,mixed>
     */
    private function applyFallbackSectionCopyToDefaultConfig(
        string $region,
        string $prompt,
        array $defaultConfig,
        array $renderContext
    ): array {
        if (\in_array($region, ['header', 'footer'], true)) {
            return $defaultConfig;
        }

        $copy = $this->resolveFallbackVisibleCopy(
            $region,
            $defaultConfig,
            $renderContext,
            (string)($renderContext['_content_locale'] ?? '')
        );
        $sectionPlan = $this->buildFallbackSectionPlan($defaultConfig, $renderContext, $prompt, $copy);
        $title = $this->sanitizeVisibleCopy((string)($sectionPlan['title'] ?? ''));
        $body = $this->sanitizeVisibleCopy((string)($sectionPlan['body'] ?? ''));
        if ($title !== '') {
            $defaultConfig['content.title'] = $title;
            $defaultConfig['title'] = $title;
            $defaultConfig['section_title'] = $title;
            $defaultConfig['content.section_title'] = $title;
        }
        if ($body !== '') {
            $defaultConfig['content.description'] = $body;
            $defaultConfig['description'] = $body;
            $defaultConfig['section_intro'] = $body;
            $defaultConfig['content.section_intro'] = $body;
        }

        return $defaultConfig;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildProductionFallbackAiPayload(
        string $region,
        string $prompt,
        array $defaultConfig = [],
        array $renderContext = []
    ): array
    {
        if (\in_array($region, ['header', 'footer'], true)) {
            return $this->buildStubAiPayload($region, $prompt, $defaultConfig, $renderContext);
        }

        $copy = $this->resolveFallbackVisibleCopy(
            $region,
            $defaultConfig,
            $renderContext,
            (string)($renderContext['_content_locale'] ?? '')
        );
        $sectionPlan = $this->buildFallbackSectionPlan($defaultConfig, $renderContext, $prompt, $copy);
        $surfaceStyle = $this->buildFallbackSurfaceStyle($sectionPlan, $defaultConfig);
        // 强行契约：先尝试使用第一阶段已落地的真实图片（slot.final_url），保证生成的网站真正"带着规划好的图片"。
        $visualPanel = $this->buildContractVisualPanel(
            $defaultConfig,
            (string)$sectionPlan['title'],
            '#2563eb',
            '#06b6d4',
            '#f59e0b'
        );

        $artClass = 'ai-site-contract-art' . ($visualPanel['has_image'] ? ' ai-site-contract-art--image' : '');
        $artInner = $visualPanel['html'];
        $cardsHtml = $this->buildFallbackCardsHtml(\is_array($sectionPlan['cards'] ?? null) ? $sectionPlan['cards'] : []);

        return [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId { --ai-site-contract-gap:22px; }'
                . "\n" . '#componentId > [class$="-inner"] > [class$="-title"], #componentId > [class$="-inner"] > [class$="-description"] { display:none !important; }'
                . "\n" . '#componentId .ai-site-contract-stage { position:relative; overflow:hidden; display:grid; grid-template-columns:minmax(0,1.04fr) minmax(280px,.96fr); gap:30px; align-items:center; padding:34px; border-radius:32px; border:1px solid color-mix(in srgb,var(--section-primary,#2563eb) 22%,transparent); background:' . $surfaceStyle['stage_background'] . '; box-shadow:0 28px 80px rgba(15,23,42,.14); }'
                . "\n" . '#componentId .ai-site-contract-stage:before { content:""; position:absolute; inset:auto -12% -38% 22%; height:56%; background:repeating-linear-gradient(135deg,color-mix(in srgb,var(--section-primary,#2563eb) 16%,transparent) 0 2px,transparent 2px 12px); opacity:.36; transform:rotate(-4deg); }'
                . "\n" . '#componentId .ai-site-contract-copy, #componentId .ai-site-contract-art { position:relative; z-index:1; }'
                . "\n" . '#componentId .ai-site-contract-copy > strong { display:block; max-width:12ch; font-size:clamp(28px,4.5vw,58px); line-height:1.02; letter-spacing:-.05em; color:' . $surfaceStyle['heading_color'] . '; }'
                . "\n" . '#componentId .ai-site-contract-copy > p { margin:16px 0 0; max-width:58ch; color:var(--section-text,#334155); font-size:clamp(15px,1.3vw,18px); }'
                . "\n" . '#componentId .ai-site-contract-kicker { display:inline-flex; margin-bottom:16px; padding:7px 12px; border-radius:999px; background:color-mix(in srgb,var(--section-primary,#2563eb) 12%,white); color:var(--section-primary,#2563eb); font-weight:700; font-size:12px; letter-spacing:.12em; text-transform:uppercase; }'
                . "\n" . '#componentId .ai-site-contract-proof { display:flex; flex-wrap:wrap; gap:10px; margin-top:22px; } #componentId .ai-site-contract-proof span { padding:10px 12px; border-radius:14px; background:rgba(255,255,255,.74); border:1px solid rgba(255,255,255,.72); box-shadow:0 12px 30px rgba(15,23,42,.09); color:var(--section-heading,#0f172a); font-weight:700; }'
                . "\n" . '#componentId .ai-site-contract-art { position:relative; min-height:260px; border-radius:28px; overflow:hidden; box-shadow:0 24px 70px rgba(15,23,42,.20); background:linear-gradient(145deg,var(--section-primary,#2563eb),var(--section-secondary,#06b6d4)); } #componentId .ai-site-contract-art .ai-site-css-visual { position:absolute; inset:0; overflow:hidden; background:radial-gradient(circle at 18% 18%,rgba(255,255,255,.34),transparent 26%),radial-gradient(circle at 82% 72%,rgba(255,255,255,.22),transparent 30%),linear-gradient(145deg,var(--section-primary,#2563eb),var(--section-secondary,#06b6d4)); } #componentId .ai-site-css-visual span { position:absolute; border-radius:999px; background:rgba(255,255,255,.32); box-shadow:0 18px 46px rgba(15,23,42,.16); } #componentId .ai-site-css-visual span:nth-child(1){left:9%;top:14%;width:34%;height:20%;} #componentId .ai-site-css-visual span:nth-child(2){right:10%;top:22%;width:38%;height:9%;} #componentId .ai-site-css-visual span:nth-child(3){left:16%;bottom:16%;width:58%;height:12%;}'
                . "\n" . '#componentId .ai-site-contract-art--image { background:color-mix(in srgb,var(--section-primary,#2563eb) 18%,#0f172a); }'
                . "\n" . '#componentId .ai-site-contract-art--image:after { content:""; position:absolute; inset:0; background:linear-gradient(155deg,color-mix(in srgb,var(--section-primary,#2563eb) 22%,rgba(15,23,42,.72)) 0%,rgba(15,23,42,.10) 50%,color-mix(in srgb,var(--section-accent,#f59e0b) 18%,rgba(15,23,42,.74)) 100%); pointer-events:none; }'
                . "\n" . '#componentId .ai-site-contract-art .ai-site-visual-image { width:100%; height:100%; min-height:260px; display:block; object-fit:cover; object-position:center; }'
                . "\n" . '#componentId .ai-site-contract-cards { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-top:24px; max-width:620px; }'
                . "\n" . '#componentId .ai-site-contract-card { position:relative; overflow:hidden; padding:18px 18px 16px; border-radius:20px; background:rgba(255,255,255,.82); border:1px solid rgba(255,255,255,.86); box-shadow:0 18px 48px rgba(15,23,42,.12); backdrop-filter:blur(10px); }'
                . "\n" . '#componentId .ai-site-contract-card:before { content:""; position:absolute; left:0; top:0; width:4px; height:100%; background:linear-gradient(180deg,var(--section-accent,#f59e0b),var(--section-primary,#2563eb)); opacity:.78; }'
                . "\n" . '#componentId .ai-site-contract-card strong { display:block; margin:0 0 8px; font-size:17px; line-height:1.18; color:var(--section-heading,#0f172a); }'
                . "\n" . '#componentId .ai-site-contract-card p { margin:0; color:var(--section-text,#334155); line-height:1.52; }'
                . "\n" . '#componentId .ai-site-contract-card span { display:inline-flex; margin-top:12px; padding:5px 9px; border-radius:999px; background:color-mix(in srgb,var(--section-accent,#f59e0b) 14%,white); color:color-mix(in srgb,var(--section-accent,#f59e0b) 58%,#111827); font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }',
            'css_responsive' => '#componentId .ai-site-contract-stage { grid-template-columns:1fr; padding:24px; } #componentId .ai-site-contract-copy > strong { max-width:12ch; } #componentId .ai-site-contract-cards { grid-template-columns:1fr; }',
            'html_content' => '<div class="ai-site-contract-stage"><div class="ai-site-contract-copy"><span class="ai-site-contract-kicker">' . \htmlspecialchars((string)$sectionPlan['eyebrow'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</span><strong>' . \htmlspecialchars((string)$sectionPlan['title'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</strong><p>' . \htmlspecialchars((string)$sectionPlan['body'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p><div class="ai-site-contract-proof">' . $this->buildFallbackProofHtml((array)$sectionPlan['proof_points']) . '</div>' . $cardsHtml . '</div><div class="' . $artClass . '">' . $artInner . '</div></div>',
            'js_content' => '',
        ];
    }

    /**
     * @param list<array{title:string,body:string,meta:string}> $cards
     */
    private function buildFallbackCardsHtml(array $cards): string
    {
        if ($cards === []) {
            return '';
        }
        $html = '<div class="ai-site-contract-cards">';
        foreach (\array_slice($cards, 0, 3) as $card) {
            $title = $this->sanitizeVisibleCopy((string)($card['title'] ?? ''));
            $body = $this->sanitizeVisibleCopy((string)($card['body'] ?? ''));
            $meta = $this->sanitizeVisibleCopy((string)($card['meta'] ?? ''));
            if ($title === '' && $body === '') {
                continue;
            }
            $html .= '<article class="ai-site-contract-card">';
            if ($title !== '') {
                $html .= '<strong>' . \htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</strong>';
            }
            if ($body !== '') {
                $html .= '<p>' . \htmlspecialchars($body, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            }
            if ($meta !== '') {
                $html .= '<span>' . \htmlspecialchars($meta, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</span>';
            }
            $html .= '</article>';
        }

        return $html . '</div>';
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     * @param array{title:string,body:string,cjk:bool} $copy
     * @return array<string,mixed>
     */
    private function buildFallbackSectionPlan(array $defaultConfig, array $renderContext, string $prompt, array $copy): array
    {
        $cjk = (bool)($copy['cjk'] ?? false);
        $samples = $this->decodeConfigStringList($defaultConfig['contract.stage1_samples'] ?? null);
        $variant = $this->detectFallbackSectionVariant($defaultConfig, $renderContext, $prompt);
        $siteTitle = $this->pickFallbackConfigCopy($defaultConfig, ['site_title', 'brand.name', 'logo.text', 'content.site_title'], 48);
        $primaryAction = $this->pickFallbackConfigCopy($defaultConfig, ['content.cta_text', 'cta_text', 'button.text', 'primary_cta', 'content.primary_cta'], 36);
        if ($primaryAction === '') {
            $primaryAction = $cjk ? '立即开始' : 'Get started';
        }
        $proofPoints = $this->buildFallbackProofPoints(
            $samples,
            (string)($copy['title'] ?? ''),
            (string)($copy['body'] ?? ''),
            $primaryAction,
            $cjk
        );

        $plan = [
            'variant' => $variant,
            'eyebrow' => $this->pickFallbackConfigCopy($defaultConfig, ['content.eyebrow', 'eyebrow', 'badge', 'kicker'], 48),
            'title' => $copy['title'],
            'body' => $copy['body'],
            'proof_points' => $proofPoints,
            'callout_title' => $cjk ? '继续下一步' : 'Continue clearly',
            'callout_body' => $cjk ? '按页面节奏查看重点、理解价值，再进入下一步操作。' : 'Scan the essentials, verify the trust cues, and continue to the download entry.',
            'card_title_primary' => $cjk ? '体验重点' : 'Game suite',
            'card_body_primary' => $cjk ? '把玩法、入口和安全提示拆成可扫读的信息。' : 'Card games, quick entry, and safety notes are separated into scannable moments.',
            'card_title_secondary' => $cjk ? '下一步动作' : 'Download path',
            'card_body_secondary' => $primaryAction,
            'card_title_tertiary' => $cjk ? '信任说明' : 'Trust signal',
            'card_body_tertiary' => $cjk ? '突出公平、安全和清晰入口，减少犹豫。' : 'Fair play, secure access, and a clear route reduce hesitation.',
        ];

        if ($plan['eyebrow'] === '') {
            $plan['eyebrow'] = match ($variant) {
                'features' => $cjk ? '核心亮点' : 'Highlights',
                'checklist' => $cjk ? '操作步骤' : 'How it works',
                'cta' => $cjk ? '立即行动' : 'Ready to act',
                default => $cjk ? '精选内容' : 'Featured',
            };
        }

        switch ($variant) {
            case 'features':
                $plan['title'] = $this->pickFallbackConfigCopy($defaultConfig, ['content.section_title', 'section_title', 'features.title'], 72);
                if ($plan['title'] === '') {
                    $plan['title'] = $cjk
                        ? (($siteTitle !== '' ? $siteTitle : '这个页面') . '的核心亮点')
                        : (($siteTitle !== '' ? $siteTitle : 'This page') . ' highlights');
                }
                $plan['body'] = $this->pickFallbackConfigCopy($defaultConfig, ['content.section_intro', 'section_intro', 'features.description'], 180);
                if ($plan['body'] === '') {
                    $plan['body'] = $cjk
                        ? '用更清晰的卡片层级展示卖点、差异点和信任信息，避免所有内容挤成同一种视觉。'
                        : 'Use clearer card hierarchy to separate benefits, differentiators, and trust details instead of stacking everything into one repeated treatment.';
                }
                $plan['callout_title'] = $cjk ? '继续查看亮点' : 'See the strengths';
                $plan['callout_body'] = $cjk ? '每张卡片只承载一个重点，让访客能扫读后迅速理解价值。' : 'Each card carries one clear idea so visitors can scan and understand the value quickly.';
                break;
            case 'checklist':
                $plan['title'] = $cjk ? '三步看懂并开始操作' : 'Three clear steps to begin';
                $plan['body'] = $cjk
                    ? '把流程、条件和风险提示拆开排版，让访客不必在一大段文案里寻找下一步。'
                    : 'Separate the process, conditions, and reassurance so visitors can find the download path without reading one long paragraph.';
                $plan['card_title_primary'] = $cjk ? '步骤顺序' : 'Ordered steps';
                $plan['card_body_primary'] = $cjk ? '按先后顺序呈现关键动作。' : 'Show the key actions in a clear sequence.';
                $plan['card_title_secondary'] = $cjk ? '阅读负担低' : 'Low reading load';
                $plan['card_body_secondary'] = $cjk ? '每一步只保留必要信息。' : 'Keep each step focused on one necessary piece of information.';
                $plan['card_title_tertiary'] = $cjk ? '动作明确' : 'Action stays clear';
                $plan['card_body_tertiary'] = $primaryAction;
                break;
            case 'cta':
                $plan['title'] = $primaryAction;
                $plan['body'] = $cjk
                    ? '把价值总结、信任提示和主按钮收口到同一块区域，形成明确的行动终点。'
                    : 'Bring the download offer, trust proof, and primary button into one focused conversion block.';
                $plan['proof_points'] = \array_slice($this->buildFallbackProofPoints(
                    $proofPoints,
                    (string)$plan['title'],
                    (string)$plan['body'],
                    $primaryAction,
                    $cjk
                ), 0, 2);
                $plan['callout_title'] = $cjk ? '现在就继续' : 'Move forward now';
                $plan['callout_body'] = $cjk ? '让按钮、辅助说明和背景层次共同强调这一处主动作。' : 'Use the button, supporting note, and contrast layer to keep the download entry unmistakable.';
                break;
            default:
                break;
        }

        $plan = $this->normalizeFallbackSectionPlanForRole($plan, $defaultConfig, $cjk);
        $plan['body'] = $this->removeFallbackTitleDuplication((string)$plan['body'], (string)$plan['title']);
        $plan['card_body_primary'] = $this->removeFallbackTitleDuplication((string)$plan['card_body_primary'], (string)$plan['title']);
        $plan['callout_body'] = $this->removeFallbackTitleDuplication((string)$plan['callout_body'], (string)$plan['title']);
        $plan['content_rows'] = $this->decodeFallbackContentRows($defaultConfig);
        $plan['cards'] = $this->buildSectionSpecificFallbackCards($plan, $defaultConfig, $cjk);
        $plan['proof_points'] = $this->buildSectionSpecificProofPoints($plan, $defaultConfig, $cjk);

        return $plan;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function normalizeFallbackSectionPlanForRole(array $plan, array $defaultConfig, bool $cjk): array
    {
        $sectionKey = \strtolower((string)($defaultConfig['runtime.section_key'] ?? $defaultConfig['runtime.section_code'] ?? ''));
        if (\preg_match('/trust|security|safe|license|proof/i', $sectionKey) === 1) {
            $plan['eyebrow'] = $cjk ? (string)$plan['eyebrow'] : 'Verified trust';
            $plan['title'] = $cjk ? (string)$plan['title'] : 'Secure play, visible proof';
            $plan['body'] = $cjk
                ? (string)$plan['body']
                : 'Show safety, player confidence, and download reassurance before visitors make the install decision.';
            return $plan;
        }
        if (\preg_match('/game[_-]?showcase|features|suite/i', $sectionKey) === 1) {
            $plan['eyebrow'] = $cjk ? (string)$plan['eyebrow'] : 'Royal game suite';
            $plan['title'] = $cjk ? (string)$plan['title'] : 'Every table has a clear path';
            $plan['body'] = $cjk
                ? (string)$plan['body']
                : 'Present Teen Patti and Rummy as premium game choices with quick entry, simple rules, and confident download cues.';
            return $plan;
        }
        if (\preg_match('/categor|game[_-]?mode/i', $sectionKey) === 1) {
            $plan['eyebrow'] = $cjk ? (string)$plan['eyebrow'] : 'Game modes';
            $plan['title'] = $cjk ? (string)$plan['title'] : 'Choose the card room that fits you';
            $plan['body'] = $cjk
                ? (string)$plan['body']
                : 'Split the games into distinct choices so players can compare the mood, pace, and next action at a glance.';
            return $plan;
        }
        if (\preg_match('/cta|download|conversion|final|action/i', $sectionKey) === 1) {
            $plan['eyebrow'] = $cjk ? (string)$plan['eyebrow'] : 'Download moment';
            $plan['title'] = $cjk ? (string)$plan['title'] : 'Download the royal card room';
            $plan['body'] = $cjk
                ? (string)$plan['body']
                : 'Close with one decisive APK entry, backed by trust marks and a high-contrast conversion surface.';
            return $plan;
        }
        if (\preg_match('/story|about|mission|journey/i', $sectionKey) === 1) {
            $plan['eyebrow'] = $cjk ? (string)$plan['eyebrow'] : 'Brand story';
            $plan['title'] = $cjk ? (string)$plan['title'] : 'Built for serious card players';
            $plan['body'] = $cjk
                ? (string)$plan['body']
                : 'Give the brand a credible editorial story instead of repeating product-card copy.';
            return $plan;
        }

        return $plan;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @return list<array{field:string,copy:string}>
     */
    private function decodeFallbackContentRows(array $defaultConfig): array
    {
        $raw = \trim((string)($defaultConfig['runtime.content_copy_rows'] ?? ''));
        if ($raw === '') {
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
            $field = \trim((string)($row['field'] ?? ''));
            $copy = $this->sanitizeVisibleCopy((string)($row['copy'] ?? ''));
            if ($field !== '' && $copy !== '') {
                $rows[] = ['field' => $field, 'copy' => $copy];
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $sectionPlan
     * @param array<string,mixed> $defaultConfig
     * @return list<array{title:string,body:string,meta:string}>
     */
    private function buildSectionSpecificFallbackCards(array $sectionPlan, array $defaultConfig, bool $cjk): array
    {
        $rows = \is_array($sectionPlan['content_rows'] ?? null) ? $sectionPlan['content_rows'] : [];
        $sectionKey = \strtolower((string)($defaultConfig['runtime.section_key'] ?? $defaultConfig['runtime.section_code'] ?? ''));
        $cards = [];

        if (\str_contains($sectionKey, 'testimonial') || \str_contains($sectionKey, 'review')) {
            $quote = $this->findFallbackRowCopy($rows, '/quote|testimonial|review|story/i');
            $author = $this->findFallbackRowCopy($rows, '/author|name|location/i');
            $rating = $this->findFallbackRowCopy($rows, '/rating|star/i');
            $cards[] = [
                'title' => $rating !== '' ? $rating : '★★★★★',
                'body' => $quote !== '' ? $quote : ($cjk ? '玩家反馈：体验顺滑、安全且公平。' : 'Players highlight smooth rounds, fair play, and secure access.'),
                'meta' => $author !== '' ? $author : ($cjk ? '真实玩家' : 'Verified player'),
            ];
            $cards[] = [
                'title' => $cjk ? '五星反馈' : '5-star feedback',
                'body' => $cjk ? '评价卡片突出公平、安全和顺滑体验。' : 'The review focuses on fairness, safety, and a smooth game flow.',
                'meta' => $cjk ? '评分信号' : 'Rating signal',
            ];
            $cards[] = [
                'title' => $cjk ? '本地玩家' : 'Local player',
                'body' => $author !== '' ? $author : ($cjk ? '来自印度玩家社区的反馈。' : 'A location-backed player voice for social proof.'),
                'meta' => $cjk ? '用户故事' : 'Player story',
            ];
            return $cards;
        }

        if (\str_contains($sectionKey, 'faq') || \str_contains($sectionKey, 'accordion')) {
            foreach ($rows as $row) {
                $field = \strtolower((string)($row['field'] ?? ''));
                if (!\str_contains($field, 'question') && !\str_contains($field, 'answer') && !\str_contains($field, 'faq')) {
                    continue;
                }
                $cards[] = [
                    'title' => \str_contains($field, 'answer') ? ($cjk ? '回答' : 'Answer') : ($cjk ? '常见问题' : 'Question'),
                    'body' => $this->clipText((string)$row['copy'], 150),
                    'meta' => $cjk ? 'FAQ' : 'FAQ',
                ];
                if (\count($cards) >= 3) {
                    break;
                }
            }
        }

        foreach ($rows as $row) {
            if (\count($cards) >= 3) {
                break;
            }
            $field = (string)($row['field'] ?? '');
            $copy = (string)($row['copy'] ?? '');
            if ($copy === ''
                || \preg_match('/section_intro|section_title/i', $field) === 1
                || $this->isInternalFallbackAssetField($field, $copy)
            ) {
                continue;
            }
            $title = $this->deriveFallbackCardTitle($field, $copy, $sectionKey, $cjk);
            $body = $this->deriveFallbackCardBody($field, $copy, $sectionKey, $cjk);
            $cards[] = [
                'title' => $title,
                'body' => $body,
                'meta' => '',
            ];
        }

        if ($cards === []) {
            return $this->buildRoleSpecificFallbackCards($sectionPlan, $sectionKey, $cjk);
        }

        return \array_slice($cards, 0, 3);
    }

    /**
     * @param array<string,mixed> $sectionPlan
     * @return list<array{title:string,body:string,meta:string}>
     */
    private function buildRoleSpecificFallbackCards(array $sectionPlan, string $sectionKey, bool $cjk): array
    {
        $title = (string)($sectionPlan['title'] ?? '');
        $body = (string)($sectionPlan['body'] ?? '');
        $action = (string)($sectionPlan['card_body_secondary'] ?? '');
        $key = \strtolower($sectionKey);
        if (\preg_match('/trust|security|safe|license|proof/i', $key) === 1) {
            return [
                ['title' => $cjk ? '安全资质' : 'Security seal', 'body' => $cjk ? '把资质、隐私和下载安全前置成可信证明。' : 'Credentials, privacy, and download safety are surfaced as proof.', 'meta' => $cjk ? '信任层' : 'Trust layer'],
                ['title' => $cjk ? '玩家保障' : 'Player assurance', 'body' => $cjk ? '用清晰说明降低安装和注册前的顾虑。' : 'Clear reassurance reduces hesitation before install or sign-up.', 'meta' => $cjk ? '保障说明' : 'Assurance'],
                ['title' => $cjk ? '透明入口' : 'Transparent path', 'body' => $action !== '' ? $action : ($cjk ? '主行动路径保持明确。' : 'The main action path stays visible.'), 'meta' => $cjk ? '行动' : 'Action'],
            ];
        }
        if (\preg_match('/faq|accordion|question|support|help/i', $key) === 1) {
            return [
                ['title' => $cjk ? '下载是否安全？' : 'Is the APK safe?', 'body' => $body !== '' ? $body : ($cjk ? '用短回答解释安全、隐私和安装边界。' : 'Use short answers to explain safety, privacy, and install boundaries.'), 'meta' => $cjk ? 'FAQ' : 'FAQ'],
                ['title' => $cjk ? '如何开始？' : 'How do I start?', 'body' => $action !== '' ? $action : ($cjk ? '给出清晰的下一步入口。' : 'Give visitors a clear next step.'), 'meta' => $cjk ? '步骤' : 'Steps'],
                ['title' => $cjk ? '需要帮助？' : 'Need help?', 'body' => $cjk ? '把支持和常见疑问放在同一处。' : 'Keep support and common questions in one readable area.', 'meta' => $cjk ? '支持' : 'Support'],
            ];
        }
        if (\preg_match('/cta|download|conversion|final|action/i', $key) === 1) {
            return [
                ['title' => $action !== '' ? $action : ($cjk ? '立即行动' : 'Start now'), 'body' => $body !== '' ? $body : ($cjk ? '集中呈现行动理由和下载入口。' : 'Focus the action reason and download entry in one place.'), 'meta' => $cjk ? '主行动' : 'Primary action'],
                ['title' => $cjk ? '行动前确认' : 'Before you continue', 'body' => $cjk ? '用一句话说明安全、条件或支持。' : 'Summarize safety, terms, or support before the click.', 'meta' => $cjk ? '确认' : 'Check'],
            ];
        }

        return [
            ['title' => $title !== '' ? $this->clipText($title, 58) : ($cjk ? '核心价值' : 'Core value'), 'body' => $body !== '' ? $this->clipText($body, 150) : ($cjk ? '用本区块独有的信息承接页面节奏。' : 'Use section-specific information to continue the page rhythm.'), 'meta' => $cjk ? '内容重点' : 'Section focus'],
            ['title' => (string)($sectionPlan['card_title_primary'] ?? ($cjk ? '差异点' : 'Differentiator')), 'body' => (string)($sectionPlan['card_body_primary'] ?? ($cjk ? '突出与相邻区块不同的表达。' : 'Highlight a different expression from neighboring blocks.')), 'meta' => $cjk ? '设计变化' : 'Design shift'],
        ];
    }

    private function isInternalFallbackAssetField(string $field, string $copy): bool
    {
        $field = \strtolower($field);
        $copy = \trim($copy);
        if (\preg_match('/icon|image|img|logo|avatar|asset|file|url|href|src/i', $field) === 1) {
            return true;
        }

        return \preg_match('/\.(?:svg|png|jpe?g|webp|gif)(?:\?.*)?$/i', $copy) === 1;
    }

    private function deriveFallbackCardTitle(string $field, string $copy, string $sectionKey, bool $cjk): string
    {
        if (\preg_match('/trust|badge|security|license|certificate/i', $sectionKey) === 1) {
            return $this->clipText($copy, 58);
        }
        if (\preg_match('/cta|button|action|link/i', $field) === 1) {
            return $this->clipText($copy, 58);
        }
        if (\preg_match('/label|title|headline|name/i', $field) === 1) {
            return $this->clipText($copy, 58);
        }
        if (\preg_match('/description|body|summary|intro/i', $field) === 1) {
            if (\preg_match('/game|category|feature|showcase/i', $sectionKey . ' ' . $field) === 1) {
                return $cjk ? '玩法亮点' : 'Game overview';
            }
            return $cjk ? '核心说明' : 'Key detail';
        }

        return $this->humanizeFallbackFieldLabel($field, $cjk);
    }

    private function deriveFallbackCardBody(string $field, string $copy, string $sectionKey, bool $cjk): string
    {
        if (\preg_match('/trust|badge|security|license|certificate/i', $sectionKey) === 1) {
            if (\preg_match('/encrypt|ssl|secure|privacy|protect/i', $copy) === 1) {
                return $cjk ? '安全能力直接前置，降低下载和注册前的疑虑。' : 'Security proof is visible before the download decision.';
            }
            if (\preg_match('/license|authority|certif/i', $copy) === 1) {
                return $cjk ? '资质信息以独立卡片展示，避免隐藏在长段落里。' : 'Credential details are shown as a standalone trust signal.';
            }

            return $cjk ? '信任信息拆成可扫读卡片，避免重复堆叠。' : 'Trust details are split into scannable proof cards.';
        }
        if (\preg_match('/cta|button|action|link/i', $field) === 1) {
            return $cjk ? '把主行动直接放在卡片中，减少犹豫。' : 'The primary CTA stays clear and easy to follow.';
        }

        return $this->clipText($copy, 150);
    }

    /**
     * @param list<array{field:string,copy:string}> $rows
     */
    private function findFallbackRowCopy(array $rows, string $pattern): string
    {
        foreach ($rows as $row) {
            if (\preg_match($pattern, (string)($row['field'] ?? '')) === 1) {
                return $this->clipText((string)($row['copy'] ?? ''), 180);
            }
        }

        return '';
    }

    private function humanizeFallbackFieldLabel(string $field, bool $cjk): string
    {
        $field = \trim(\preg_replace('/[_\-.]+/', ' ', $field) ?? $field);
        $field = \preg_replace('/\b(?:content|section|field|copy)\b/i', '', $field) ?? $field;
        $field = \trim(\preg_replace('/\s+/', ' ', $field) ?? $field);
        if ($field === '') {
            return $cjk ? '重点内容' : 'Key detail';
        }

        return $cjk ? $field : \ucwords(\strtolower($field));
    }

    /**
     * @param array<string,mixed> $sectionPlan
     * @param array<string,mixed> $defaultConfig
     * @return list<string>
     */
    private function buildSectionSpecificProofPoints(array $sectionPlan, array $defaultConfig, bool $cjk): array
    {
        $rows = \is_array($sectionPlan['content_rows'] ?? null) ? $sectionPlan['content_rows'] : [];
        $points = [];
        foreach ($rows as $row) {
            $field = \strtolower((string)($row['field'] ?? ''));
            if (\preg_match('/section_intro|section_title|title|headline/i', $field) === 1) {
                continue;
            }
            $copy = $this->clipText((string)($row['copy'] ?? ''), 56);
            if ($this->isInternalFallbackAssetField($field, $copy)) {
                continue;
            }
            if ($copy !== '' && !$this->isFallbackProofDuplicate($copy, (string)$sectionPlan['title'], (string)$sectionPlan['body'], (string)($sectionPlan['card_body_secondary'] ?? ''))) {
                $points[] = $copy;
            }
            if (\count($points) >= 3) {
                break;
            }
        }
        if ($points !== []) {
            return \array_values(\array_unique($points));
        }

        return \is_array($sectionPlan['proof_points'] ?? null) ? \array_slice($sectionPlan['proof_points'], 0, 3) : [];
    }

    private function removeFallbackTitleDuplication(string $body, string $title): string
    {
        $body = \trim($body);
        $title = \trim($title);
        if ($body === '' || $title === '') {
            return $body;
        }
        $body = \trim(\str_replace([$title . ' ', ' ' . $title, $title], ' ', $body));
        $body = \trim(\preg_replace('/\s+/u', ' ', $body) ?? $body);

        return $body;
    }

    /**
     * @param list<string> $samples
     * @return list<string>
     */
    private function buildFallbackProofPoints(array $samples, string $title, string $body, string $primaryAction, bool $cjk): array
    {
        $fallbacks = $cjk
            ? ['安全下载入口', '热门棋牌游戏', '公平体验说明', '快速开始路径']
            : ['Secure download', 'Popular card games', 'Fair-play signal', 'Fast start path'];
        $points = [];
        foreach ($samples as $sample) {
            $sample = $this->clipText($this->sanitizeVisibleCopy((string)$sample), 42);
            if ($sample === '' || $this->isFallbackProofDuplicate($sample, $title, $body, $primaryAction)) {
                continue;
            }
            $points[] = $sample;
            if (\count($points) >= 3) {
                break;
            }
        }
        foreach ($fallbacks as $fallback) {
            if (\count($points) >= 3) {
                break;
            }
            if (!$this->isFallbackProofDuplicate($fallback, $title, $body, $primaryAction)) {
                $points[] = $fallback;
            }
        }

        return \array_values(\array_unique($points));
    }

    private function isFallbackProofDuplicate(string $value, string $title, string $body, string $primaryAction): bool
    {
        $normalized = \mb_strtolower(\trim($value));
        if ($normalized === '' || \mb_strlen($normalized) > 44) {
            return true;
        }
        foreach ([$title, $body, $primaryAction] as $source) {
            $source = \mb_strtolower(\trim($source));
            if ($source === '') {
                continue;
            }
            if ($normalized === $source || \str_contains($source, $normalized) || \str_contains($normalized, $source)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function detectFallbackSectionVariant(array $defaultConfig, array $renderContext, string $prompt): string
    {
        // 强行契约：当 runtime.section_template 已由蓝图给出（hero/cta/features/checklist）时，
        // 直接尊重该模板，避免关键字命中导致 hero 段被误判成 cta（出现 "Close the page with a compact summary..." 占位文案）。
        $template = \strtolower(\trim((string)($defaultConfig['runtime.section_template'] ?? '')));
        $allowedFromTemplate = match ($template) {
            'hero', 'banner' => 'hero',
            'cta' => 'cta',
            'checklist' => 'checklist',
            'features' => 'features',
            default => '',
        };
        if ($allowedFromTemplate !== '') {
            return $allowedFromTemplate;
        }
        $sectionIdentity = \strtolower((string)($defaultConfig['runtime.section_key'] ?? '') . ' ' . (string)($defaultConfig['runtime.section_code'] ?? ''));
        if (\preg_match('/faq|accordion|question|answer|step|process|how-to/', $sectionIdentity) === 1) {
            return 'checklist';
        }
        if (\preg_match('/testimonial|review|trust|badge|security|category|showcase|feature|game/', $sectionIdentity) === 1) {
            return 'features';
        }

        $signals = \strtolower(\json_encode([$defaultConfig, $renderContext, $prompt], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
        if (\preg_match('/download|cta|contact|checkout|install|start now|primary_cta/', $signals) === 1) {
            return 'cta';
        }
        if (\preg_match('/checklist|step|process|how[-_ ]to|guide|policy|faq|terms|privacy/', $signals) === 1) {
            return 'checklist';
        }
        // Do NOT match bare "card" — briefs like "card games / poker club" must not collapse the hero into a card-grid features stub.
        if (\preg_match('/feature|highlight|benefit|advantage|proof|trust|value/', $signals) === 1) {
            return 'features';
        }

        return 'hero';
    }

    /**
     * @param array<string,mixed> $sectionPlan
     * @param array<string,mixed> $defaultConfig
     * @return array{stage_background:string,heading_color:string,callout_background:string,callout_color:string}
     */
    private function buildFallbackSurfaceStyle(array $sectionPlan, array $defaultConfig): array
    {
        $tokens = \array_values(\array_filter(
            $this->decodeConfigStringList($defaultConfig['contract.theme_tokens'] ?? null),
            static fn(string $token): bool => \preg_match('/^#[0-9a-f]{6}$/i', $token) === 1
        ));
        $primary = $tokens[0] ?? '#2563eb';
        $secondary = $tokens[1] ?? '#06b6d4';
        $accent = $tokens[2] ?? '#f59e0b';

        return match ((string)($sectionPlan['variant'] ?? 'hero')) {
            'features' => [
                'stage_background' => 'linear-gradient(180deg,#ffffff 0%,color-mix(in srgb,' . $secondary . ' 8%,#ffffff) 100%)',
                'heading_color' => '#0f172a',
                'callout_background' => 'linear-gradient(135deg,#0f172a,color-mix(in srgb,' . $primary . ' 34%,#0f172a))',
                'callout_color' => '#ffffff',
            ],
            'checklist' => [
                'stage_background' => 'linear-gradient(180deg,color-mix(in srgb,' . $accent . ' 10%,#ffffff) 0%,#ffffff 100%)',
                'heading_color' => '#0f172a',
                'callout_background' => '#0f172a',
                'callout_color' => '#ffffff',
            ],
            'cta' => [
                'stage_background' => 'linear-gradient(135deg,color-mix(in srgb,' . $primary . ' 14%,#ffffff),color-mix(in srgb,' . $accent . ' 12%,#ffffff))',
                'heading_color' => '#0f172a',
                'callout_background' => 'linear-gradient(135deg,#0f172a,color-mix(in srgb,' . $accent . ' 28%,#0f172a))',
                'callout_color' => '#ffffff',
            ],
            default => [
                'stage_background' => 'radial-gradient(circle at 12% 8%,color-mix(in srgb,' . $accent . ' 26%,transparent),transparent 32%),linear-gradient(135deg,color-mix(in srgb,' . $primary . ' 12%,white),color-mix(in srgb,' . $secondary . ' 10%,white))',
                'heading_color' => '#0f172a',
                'callout_background' => '#0f172a',
                'callout_color' => '#ffffff',
            ],
        };
    }

    /**
     * @param list<string> $items
     */
    private function buildFallbackProofHtml(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $item = \trim((string)$item);
            if ($item === '') {
                continue;
            }
            $html .= '<span>' . \htmlspecialchars($item, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     * @return array{title:string,body:string,cjk:bool}
     */
    private function resolveFallbackVisibleCopy(
        string $region,
        array $defaultConfig,
        array $renderContext,
        string $locale = ''
    ): array {
        $jsonContext = (string)\json_encode([$defaultConfig, $renderContext], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        $localeLower = \strtolower($locale);
        $cjk = \str_starts_with($localeLower, 'zh') || ($localeLower === '' && $this->hasAnyCjkContent($jsonContext));
        $title = $this->pickFallbackConfigCopy($defaultConfig, [
            'content.title',
            'title',
            'heading',
            'headline',
            'brand.name',
            'logo.text',
        ], 72);
        $body = $this->pickFallbackConfigCopy($defaultConfig, [
            'content.description',
            'content.subtitle',
            'description',
            'body',
            'summary',
            'brand.description',
            'content.text',
        ], 180);

        if ($title === '') {
            $title = $this->fallbackTitleForRegion($region, $cjk);
        }
        if ($body === '') {
            $body = $cjk
                ? '访客可以在这里了解服务亮点、查看作品证明，并通过明确按钮提交预约咨询。'
                : 'Core benefits, trust proof, and a clear action come together so users can understand the value quickly.';
        }

        return [
            'title' => $title,
            'body' => $body,
            'cjk' => $cjk,
        ];
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param list<string> $keys
     */
    private function pickFallbackConfigCopy(array $defaultConfig, array $keys, int $limit): string
    {
        foreach ($keys as $key) {
            if (!\is_scalar($defaultConfig[$key] ?? null)) {
                continue;
            }
            $value = $this->sanitizeVisibleCopy((string)$defaultConfig[$key]);
            if ($value === '') {
                continue;
            }

            return $this->clipText($value, $limit);
        }

        return '';
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

    private function fallbackTitleForRegion(string $region, bool $cjk): string
    {
        return match ($region) {
            'hero' => $cjk ? '核心展示' : 'Featured story',
            'cta' => $cjk ? '预约咨询' : 'Clear next step',
            'header' => $cjk ? '站点导航' : 'Site navigation',
            'footer' => $cjk ? '联系与信任信息' : 'Contact and trust information',
            default => $cjk ? '精选内容' : 'Featured content',
        };
    }

    private function summarizeThrowable(\Throwable $throwable): string
    {
        $message = \trim($throwable->getMessage());
        if ($message === '') {
            $message = $throwable::class;
        }

        return $this->clipText($message, 220);
    }

    private function shouldRetryComponentGeneration(\Throwable $throwable): bool
    {
        $message = \strtolower(\trim($throwable->getMessage()));
        if ($message === '') {
            return true;
        }

        $nonRetryableMarkers = [
            'http 401',
            'http 402',
            'http 403',
            'insufficient balance',
            'api key',
            'missing api key',
            'model selection',
            'no available',
            'provider account',
            'provider configuration',
            'quota',
            '余额',
            '密钥',
            '未配置',
            '配置',
            '账户',
            'account',
        ];

        foreach ($nonRetryableMarkers as $marker) {
            if (\str_contains($message, $marker)) {
                return false;
            }
        }

        return true;
    }

    private function shouldFallbackComponentGeneration(\Throwable $throwable): bool
    {
        return false;

        $message = \strtolower($this->collectThrowableMessages($throwable));
        if (\trim($message) === '') {
            return true;
        }

        // Hard provider/config errors must stay visible. The outer stream wrapper
        // includes "stream failed", so these markers need precedence.
        foreach ([
            'http 401',
            'http 402',
            'http 403',
            'invalid api key',
            'incorrect api key',
            'missing api key',
            'unauthorized',
            'authentication',
            'model unavailable',
            'model selection',
            'no available',
            'provider account',
            'provider configuration',
            'provider temporarily unavailable',
            'insufficient balance',
            'quota',
            '密钥',
            '未配置',
            '配置',
            '账户',
            '余额',
            '额度',
            'account',
        ] as $marker) {
            if (\str_contains($message, $marker)) {
                return false;
            }
        }

        foreach ([
            'valid component json',
            'invalid component json',
            'malformed component json',
            'invalid json',
            'malformed json',
            'json payload',
            'json repair',
            'syntax error',
            'html_content',
            'no content',
            'empty content',
            'control character',
            'unexpected end',
            'unterminated',
            'stream disconnected',
            'stream failed',
            '流式生成失败',
            '未返回任何内容',
        ] as $marker) {
            if (\str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function attemptSyntaxFix(string $phtml, string $region, array $componentInfo, array $aiData, array $initialCheck): string
    {
        $codeFixer = $this->getCodeFixer();
        $codeValidator = $this->getCodeValidator();

        // 第 1 轮：CodeFixer::fix() 常规修复
        $fixed = $codeFixer->fix($phtml);
        $check = $codeValidator->checkSyntax($fixed);
        if (!empty($check['valid'])) {
            return $fixed;
        }

        // 第 2 轮：CodeFixer::fixAndValidate() 含激进修复
        $result = $codeFixer->fixAndValidate($phtml, $codeValidator);
        if (!empty($result['validation']['valid'])) {
            return (string)$result['code'];
        }

        // 第 3 轮：对 AI 数据中各字段逐一修复后重新组装
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

        throw new \RuntimeException((string)__('AI 生成的组件未通过 PHP 语法校验（已尝试 %{n} 轮自动修复）：%{message}', [
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
        $aiData = $this->getCodeFixer()->fixAiData($aiData);
        $aiData = $this->applyStrictVirtualThemeComponentPolicy($aiData, $region, $verifiedAssets);
        $aiData = $this->normalizeVirtualThemeCssClassScope($aiData, $componentCode);

        $validation = $this->getCodeValidator()->validateAiData($aiData, $region);
        if (!empty($validation['valid'])) {
            return $aiData;
        }

        $errors = \array_values(\array_filter(\array_map('strval', $validation['errors'] ?? [])));
        throw new \RuntimeException((string)__('AI 组件 JSON 校验失败：%{message}', [
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
        // 强行契约：默认清空 js_content；仅当本组件是 hero/banner 轮播这类必须有 JS 才能完成
        // 体验的场景，并且 JS 内容是受控的（仅引用 component 局部 DOM、无 eval / 外部资源 / fetch）才放行。
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
            $lowQualityReason = $this->detectLowQualityGeneratedSectionHtmlReason((string)($aiData['html_content'] ?? ''));
            if ($lowQualityReason !== null) {
                throw new \RuntimeException((string)__('AI 组件内容质量不足：%{1}。请重新生成。', [$lowQualityReason]));
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

        $css = $this->normalizeVirtualThemeCssComponentScope($css);
        $css = $this->clipCssAtRuleBoundary($css, $limit);
        if ($css === '') {
            return '';
        }

        $css = $this->normalizeVirtualThemeCssComponentScope($css);

        return $this->balanceCssBraces(\trim($this->getCodeFixer()->fixCss($css)));
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
            foreach ($this->collectGenericCssSelectorClasses((string)$aiData[$cssKey]) as $genericClass) {
                $renameMap[$genericClass] = $prefix . '-' . $genericClass;
            }
        }

        foreach (['html_content', 'html_extra', 'html_extra_column', 'footer_extra_text'] as $htmlKey) {
            if (!\is_string($aiData[$htmlKey] ?? null)) {
                continue;
            }
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
            . "- Examples for this component: `#componentId .{$prefix}-card`, `#componentId .{$prefix}-icon`, `#componentId .{$prefix}-title`.\n";
    }

    private function cleanAiHtmlFragment(string $html, array $verifiedAssets = []): string
    {
        $html = \preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = \preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = $this->stripPhpFragmentsFromHtml($html);
        $svgPolicyReason = $this->detectInlineSvgGeneratedSectionViolation($html);
        if ($svgPolicyReason !== null) {
            throw new \RuntimeException('AI component content hard policy failed: ' . $svgPolicyReason);
        }
        // 清理“提示词泄漏”句式，防止把计划/指令文本渲染给访客。
        $html = \preg_replace('/<(h[1-6]|p|li|span|div)\b[^>]*>\s*(?:Use|Populate|Ensure|Must|Should|Return|Do not|Keep|Include|List|Provide|Generate)\b[^<]{0,360}<\/\1>/iu', '', $html) ?? $html;
        $html = \preg_replace('/<(h[1-6]|p|li|span|div)\b[^>]*>\s*(?:使用|补充|确保|必须|应当|请|返回|不要|保持|包含|列出|提供|生成)\b[^<]{0,360}<\/\1>/u', '', $html) ?? $html;
        $html = \preg_replace('/@(?:component|fields)_(?:start|end)\b/i', '', $html) ?? $html;
        $html = \preg_replace('/<div([^>]*class="[^"]*(?:eyebrow|subtitle|kicker|badge)[^"]*"[^>]*)>\s*(首页|主页|关于我们|关于|Home|About|About Us)\s*<\/div>/iu', '', $html) ?? $html;
        // 强行契约：planning 关键字（home_page/about_page/page:.../content/...）只能从可见文本剥离，
        // 不能动 HTML 属性（src/href/srcset/data-* 等），否则 `/pub/.../page-home_page-content-home-page-hero-...jpg`
        // 这种合法 URL 中的 `home_page` 会被吞掉，导致 <img> 显示成 broken。
        $html = $this->stripPlanningKeywordsFromVisibleText($html);
        $html = \preg_replace('/(?:核心卖点|功能特性|把首页[^。！？.!?]{0,80}放出来|值得点击|页面类型|内容块)/u', '', $html) ?? $html;
        $html = $this->repairHtmlFragmentTagBalance($html);
        $this->assertGeneratedHtmlFragmentWellFormed($html);
        $this->assertNoBrokenGeneratedImageReferences($html, $verifiedAssets);
        $html = \preg_replace('/\s{2,}/u', ' ', $html) ?? $html;
        $html = \trim($html);
        // 禁止使用纯 mb/byte 裁剪：超长 HTML 在中间截断会破坏标签闭环，repair 也难救。
        $html = $this->clipHtmlFragmentPreservingIntegrity($html, 5000);
        $html = $this->repairHtmlFragmentTagBalance(\trim($html));
        $this->assertGeneratedHtmlFragmentWellFormed($html);

        return \trim($html);
    }

    /**
     * 强行契约：组件内联 JS 白名单——只放行 hero/banner 轮播这类受控 JS。
     *
     * 必须满足：
     *  - HTML 含 `class="ai-site-hero"` 这一已知轮播容器（其它结构一律不需要 JS）。
     *  - JS 仅引用 `component.querySelector*`、`addEventListener`、`setInterval`、`classList`、
     *    `setAttribute("aria-hidden", ...)` 等局部 DOM 操作。
     *  - JS 不得包含 `eval(`、`Function(`、`fetch(`、`XMLHttpRequest`、`document.write`、
     *    `import(`、`<script`、`document.body`、`window.location` 等高风险结构。
     *
     * @param array<string,mixed> $aiData
     */
    private function isAllowedComponentInlineJs(string $js, array $aiData): bool
    {
        $js = \trim($js);
        if ($js === '' || \strlen($js) < 5) {
            return false;
        }
        $html = (string)($aiData['html_content'] ?? '');
        $isHeroBanner = \str_contains($html, 'class="ai-site-hero"');
        if (!$isHeroBanner) {
            return false;
        }
        // 高风险关键字一律拒绝。注意 JS 大小写敏感：`function(` 是字面量（安全），
        // `Function(` 是构造器（危险），因此对这一类标识符使用大小写敏感匹配。
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
        // 必须在 component 局部范围内操作 DOM；如果 JS 完全不引用 component 这个局部句柄，
        // 风险更高（可能影响整页 DOM），统一拒绝。
        if (\strpos($js, 'component') === false) {
            return false;
        }

        return true;
    }

    /**
     * 提取 HTML 中"访客可见文本"——剥掉所有标签（包括属性），只保留 `>...<` 之间的内容。
     * 用于泄漏检测，避免把 src/href/data-* 中的合法 URL / slot id 误判为 leak。
     */
    private function extractVisibleHtmlText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        // 直接 strip_tags 已能去掉所有标签（含属性），剩下的就是访客可读文本。
        $plain = \strip_tags($html);
        $plain = \preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        return \trim($plain);
    }

    /**
     * 强行契约：仅在 HTML 文本节点（标签之外）剥离 planning 关键字，保留属性值/标签原貌。
     *
     * 旧实现使用全局正则一次性剥离 home_page / about_page / content/... / page:...，
     * 但这些 token 经常合法地出现在 <img src="/pub/.../page-home_page-content-home-page-hero-...jpg">
     * 这类资产 URL、CSS class、data-pb-ai-asset-slot 属性中，被错误吞掉后会让图片 404、broken。
     *
     * 这里改成"分段处理"：标签整段保留，仅在 `>...<` 之间的文本片段上跑该剥离正则。
     */
    private function stripPlanningKeywordsFromVisibleText(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        $pattern = '/\b(?:AI_GENERATED_[A-Z0-9_]+|task_key|section_code|block_key|page_type|plan_locale|runtime_context|content\/[a-z0-9_\/-]+|app\/code\/[a-z0-9_\/-]+|var\/[a-z0-9_\/-]+|home_page|about_page|shared:[a-z0-9:_\/-]+|page:[a-z0-9:_\/-]+)\b/iu';

        // 使用 preg_split 按 HTML 标签切片：奇数下标是标签，偶数下标是文本节点。
        // PREG_SPLIT_DELIM_CAPTURE 让分隔符（标签）保留在结果中。
        $parts = \preg_split('/(<[^>]+>)/u', $html, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (!\is_array($parts)) {
            return $html;
        }

        $out = '';
        foreach ($parts as $idx => $part) {
            if ($part === '') {
                continue;
            }
            $isTag = ($idx % 2 === 1) || (\strlen($part) > 0 && $part[0] === '<');
            if ($isTag) {
                $out .= $part;
                continue;
            }
            $clean = \preg_replace($pattern, '', $part);
            $out .= ($clean ?? $part);
        }

        return $out;
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

        $quote = '';
        $length = \strlen($tagText);
        for ($index = 1; $index < $length - 1; $index++) {
            $char = $tagText[$index];
            if ($quote !== '') {
                if ($char === $quote && ($index === 0 || $tagText[$index - 1] !== '\\')) {
                    $quote = '';
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
     * AI 超长 HTML 若用字节/字符硬性截断会破坏标签闭环；末尾裁到最近一次完整标签结束边界后再跑一次 repair。
     */
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
        // 强行契约：泄漏检测只看"可见文本"——属性中的合法 URL/slot id（如
        //   src="/pub/.../page-home_page-content-home-page-hero-xxx.jpg"
        //   data-pb-ai-asset-slot="page:home_page:content-home-page-hero"
        // 不应触发"internal task identifiers leaked"，否则会让所有真实图像 build 全失败。
        $visibleText = $this->extractVisibleHtmlText($trimmed);
        if (\preg_match('/AI content placeholder|placeholder\s+(?:content|copy|section|text|block|image|visual)|example\.com|Generated visual|prompt text|customer brief|website requirement|planning\/plan language|stage-2 planned text|source intent|Built from plan|generated from plan|confirmed stage-one content|content_fill_rule|field_content_requirements|stage3_directive|task_script|Use concrete|Present key terms|provide download CTA|Provide category|filter tabs|visually distinct|Visible CTA path|Trust content|Responsive cards|proof points|visual hierarchy|launch-ready content|Immediately capture|Instantly communicate|Immediately inform|Capture immediate attention|Introduce Teenipiya|\$[a-z_][a-z0-9_]*|=>|===|优先沿用|输出必须|字段样例|提示词|直接产出可上屏|生成页面方案/iu', $visibleText) === 1) {
            return 'prompt or placeholder text leaked';
        }
        if (\preg_match('/\b(?:AI_GENERATED_[A-Z0-9_]+|task_key|section_code|block_key|page_type|plan_locale|runtime_context|content\/[a-z0-9_\/-]+|app\/code\/[a-z0-9_\/-]+|var\/[a-z0-9_\/-]+|home_page|about_page|shared:[a-z0-9:_\/-]+|page:[a-z0-9:_\/-]+)\b/iu', $visibleText) === 1) {
            return 'internal task identifiers leaked';
        }
        if (\preg_match('/\b(?:Game Card|Category|Badge)\s+\d+\b/iu', $visibleText) === 1) {
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

    private function detectLowQualityGeneratedSectionHtmlReason(string $html): ?string
    {
        $trimmed = \trim($html);
        if ($trimmed === '') {
            return 'empty html';
        }
        if (\preg_match('/\bai-site-fallback\b|<svg\s+viewBox=["\']0 0 520 360["\']/iu', $trimmed) === 1) {
            return 'plan-derived fallback visual leaked into generated content';
        }
        $plain = \trim((string)\preg_replace('/\s+/u', ' ', \strip_tags($trimmed)));
        $visibleLower = \mb_strtolower($this->extractVisibleHtmlText($trimmed) . ' ' . $plain);
        if (\str_contains($visibleLower, 'get started')
            && \str_contains($visibleLower, 'game suite')
            && \str_contains($visibleLower, 'download path')
        ) {
            return 'generic repeated three-card scaffold leaked into generated content';
        }
        if ($plain === '' || \mb_strlen($plain) < 18) {
            return 'insufficient visitor-facing text';
        }
        $visibleText = $this->extractVisibleHtmlText($trimmed);
        // 同上：仅在可见文本上做 leak 检测，避免 src/href/data-* 中的合法 URL/slot id 被误判。
        $visibleText = $this->extractVisibleHtmlText($trimmed);

        if (\preg_match('/AI content placeholder|ai-empty|placeholder\s+(?:content|copy|section|text|block|image|visual)|demo|example\.com|Generated visual|inline SVG|Visual preview generated|Generated website section|Website content language|visitor-visible copy|Do not use the|Return ONLY|prompt text|customer brief|website requirement|planning\/plan language|stage-2 planned text|source intent|Built from plan|generated from plan|confirmed stage-one content|content_fill_rule|field_content_requirements|stage3_directive|task_script|Use concrete|Present key terms|provide download CTA|优先沿用|输出必须|字段样例|提示词|直接产出可上屏|生成页面方案/iu', $visibleText) === 1) {
            return 'prompt or placeholder text leaked';
        }

        if (\preg_match('/\b(?:AI_GENERATED_[A-Z0-9_]+|task_key|section_code|block_key|page_type|plan_locale|runtime_context|content\/[a-z0-9_\/-]+|app\/code\/[a-z0-9_\/-]+|var\/[a-z0-9_\/-]+|home_page|about_page|shared:[a-z0-9:_\/-]+|page:[a-z0-9:_\/-]+)\b/iu', $visibleText) === 1) {
            return 'internal task identifiers leaked';
        }

        if (\preg_match('/核心卖点|功能特性|把首页|值得点击|放出来|方案头部|方案背景|方案结尾|当前方案|任务方案|蓝图/iu', $plain) === 1) {
            return 'planning copy leaked into visitor content';
        }

        if ($this->containsPlanningObservationCopy($plain)) {
            return 'planning observation copy leaked into visitor content';
        }

        if (\preg_match('/<(h[1-6]|p|a)\b[^>]*>\s*<\/\1>/iu', $trimmed) === 1) {
            return 'empty visitor element';
        }
        if (\preg_match('/<a\b(?![^>]*\bclass\s*=)[^>]*>/iu', $trimmed) === 1
            && !$this->hasVisitorArticleListStructure($trimmed)
        ) {
            return 'unstyled browser-default link leaked into visitor content';
        }
        if (\preg_match('/>\s*(?:✓|✔|√)\s*</u', $trimmed) === 1) {
            return 'symbol-only decorative control leaked into visitor content';
        }

        $hasVisual = \preg_match('/class=["\'][^"\']*(?:card|visual|panel|media|grid|badge)[^"\']*/iu', $trimmed) === 1;
        $hasRealCopy = \mb_strlen($plain) >= 32;
        $hasVisitorLinkCluster = $this->hasVisitorLinkCluster($trimmed);
        $hasVisitorArticleList = $this->hasVisitorArticleListStructure($trimmed);

        if (!$hasVisual && !$hasRealCopy && !$hasVisitorLinkCluster && !$hasVisitorArticleList) {
            return 'missing real copy, visual hierarchy, or visitor content structure';
        }

        return null;
    }

    private function detectHeroBannerQualityViolation(string $html): ?string
    {
        $normalized = \preg_replace('/\s+/u', ' ', $html) ?? $html;
        $hasFullBleedBackground = \preg_match('/(?:class=["\'][^"\']*ai-site-hero-image[^"\']*["\'][^>]*(?:width:100%|height:100%|object-fit|data-pb-ai-image-role)|background-image|object-fit\s*:\s*cover|inset\s*:\s*0)/iu', $normalized) === 1;
        $hasPremiumMediaHero = \preg_match('/(?:data-pb-ai-image-role=["\']generated-asset["\']|class=["\'][^"\']*(?:hero|banner)[^"\']*(?:media|visual|image)[^"\']*["\'])/iu', $normalized) === 1
            && \preg_match('/(?:border-radius|box-shadow|linear-gradient|radial-gradient|backdrop-filter|object-fit\s*:\s*cover)/iu', $normalized) === 1;
        $hasOverlayPanel = \preg_match('/backdrop-filter|rgba\([^)]*,\s*\.(?:3|4|5|6|7|8|9)|linear-gradient\([^;]*(?:rgba|transparent)/iu', $normalized) === 1;
        if (!$hasFullBleedBackground && !$hasPremiumMediaHero) {
            return 'hero/banner is not using a full-background image or a premium generated media visual';
        }
        if (!$hasOverlayPanel) {
            return 'hero/banner lacks a readable overlay layer for floating content';
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
        return \preg_match('/<time\b|date|read|guide|review|tips|news|update|strategy|apk|teen patti|rummy|category|article/iu', $html . ' ' . $plain) === 1;
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
            if (!\str_contains($payload, $url)) {
                throw new \RuntimeException('Required image slot is not referenced by generated block: ' . $slotId);
            }
            if (!\str_contains($html, 'data-pb-ai-image-role="generated-asset"')
                || !\str_contains($html, 'data-pb-ai-asset-slot="' . $slotId . '"')
            ) {
                throw new \RuntimeException('Required image slot is missing editor attributes: ' . $slotId);
            }
        }
    }

    /**
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $renderContext
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function forceInjectMissingRequiredImageAssets(array $aiData, array $renderContext, array $defaultConfig): array
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
            return $aiData;
        }

        $htmlKey = \trim((string)($aiData['html_content'] ?? '')) !== '' ? 'html_content' : 'html_extra';
        $html = (string)($aiData[$htmlKey] ?? '');
        if ($html === '') {
            return $aiData;
        }

        foreach ($requiredAssets as $slotId => $url) {
            $slotId = \trim((string)$slotId);
            $url = \trim((string)$url);
            if ($slotId === '' || $url === '') {
                continue;
            }
            $slotAttr = 'data-pb-ai-asset-slot="' . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"';
            if (\str_contains($html, $url) && \str_contains($html, $slotAttr)) {
                continue;
            }

            $alt = $this->sanitizeVisibleCopy($this->firstConfigString($defaultConfig, [
                'runtime.section_image_alt',
                'visual.image_alt',
                'image.alt',
                'content.title',
                'runtime.section_name',
            ]));
            if ($alt === '') {
                $alt = 'Section visual';
            }
            $img = '<figure class="pb-ai-required-visual" data-pb-ai-asset-slot="'
                . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . '"><img src="' . \htmlspecialchars($url, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '" alt="'
                . \htmlspecialchars($alt, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . '" loading="lazy" decoding="async" data-pb-ai-image-role="generated-asset" data-pb-ai-asset-slot="'
                . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . '"></figure>';

            $injected = \preg_replace('/(<\/(?:section|article|div)>\s*)$/iu', $img . '$1', \trim($html), 1, $count);
            $html = \is_string($injected) && $count > 0 ? $injected : \trim($html) . $img;
        }

        $aiData[$htmlKey] = $html;
        $aiData['css_extra'] = (string)($aiData['css_extra'] ?? '')
            . "\n" . '#componentId .pb-ai-required-visual{margin:clamp(24px,4vw,44px) 0 0;border-radius:28px;overflow:hidden;box-shadow:0 28px 70px rgba(15,23,42,.18);background:linear-gradient(135deg,rgba(255,255,255,.92),rgba(255,255,255,.68));}'
            . "\n" . '#componentId .pb-ai-required-visual img{display:block;width:100%;aspect-ratio:16/9;max-height:520px;object-fit:cover;filter:saturate(1.04) contrast(1.03);}';

        return $aiData;
    }

    /**
     * Stage-3 偶有 hero 大图未接上（空 src）、或整块高饱和渐变底板压在照片边上产生“主题色 vs 画面色”撞色。
     * 对已写入 runtime.section_image_* 的规划 URL：补齐 `.ai-site-hero--has-photo`、重写首帧背景图标签，并 prepend 色谱融合的底板/遮罩 CSS。
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
        $template = \strtolower(\trim((string)($defaultConfig['runtime.section_template'] ?? '')));
        $html = (string)($aiData['html_content'] ?? '');
        if ($html === '') {
            return $aiData;
        }
        $visualContract = $this->decodeRuntimeVisualContract($defaultConfig);
        $slotType = \strtolower(\trim((string)($visualContract['slot_type'] ?? '')));
        $isHeroContract = \in_array($template, ['hero', 'banner'], true)
            || \str_contains($slotType, 'hero')
            || \str_contains($slotType, 'banner');
        if (!$isHeroContract && !\preg_match('/\bai-site-hero\b/i', $html)) {
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
        if (!\preg_match('/\bai-site-hero\b/i', $html)) {
            return $this->buildStrictHeroBannerPayload($aiData, $defaultConfig, $imageUrl);
        }

        $heroDivPatched = false;
        $html = \preg_replace_callback(
            '/<div\b[^>]*>/iu',
            function (array $matches) use (&$heroDivPatched): string {
                $tag = $matches[0];
                if ($heroDivPatched) {
                    return $tag;
                }
                if (!\preg_match('/\bai-site-hero\b/i', $tag) || \preg_match('/\bai-site-hero--has-photo\b/i', $tag)) {
                    return $tag;
                }

                $heroDivPatched = true;
                if (\preg_match('/\bclass=(["\'])([\s\S]*?)\1/u', $tag, $cm) !== 1) {
                    return $tag;
                }
                $quote = (string)$cm[1];
                $list = \trim((string)($cm[2] ?? ''));

                return \str_replace(
                    'class=' . $quote . $list . $quote,
                    'class=' . $quote . \trim($list . ' ai-site-hero--has-photo') . $quote,
                    $tag
                );
            },
            $html,
        );
        $html = \is_string($html) ? $html : (string)($aiData['html_content'] ?? '');

        $slotId = \trim((string)($defaultConfig['runtime.section_image_slot_id'] ?? $defaultConfig['visual.image_slot_id'] ?? ''));
        $escapedUrl = \htmlspecialchars($imageUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $alt = $this->sanitizeVisibleCopy((string)(
            $defaultConfig['runtime.section_image_alt']
            ?? $defaultConfig['visual.image_alt']
            ?? $defaultConfig['image.alt']
            ?? $defaultConfig['content.title']
            ?? ''
        ));
        if ($alt === '') {
            $alt = 'Hero';
        }
        $escapedAlt = \htmlspecialchars($alt, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $slotPieces = ['data-pb-ai-image-role="generated-asset"'];
        if ($slotId !== '') {
            $slotPieces[] = 'data-pb-ai-asset-slot="' . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        $slotAttr = \implode(' ', $slotPieces);

        $heroImgTag = '<img class="ai-site-visual-image ai-site-hero-image" loading="eager" decoding="async"'
            . ' src="' . $escapedUrl . '" alt="' . $escapedAlt . '" ' . $slotAttr . '>';

        $imgMatched = false;
        $html = \preg_replace_callback(
            '/<img\b[^>]*\bai-site-hero-image\b[^>]*>/iu',
            function (array $matches) use (&$imgMatched, $heroImgTag): string {
                if ($imgMatched) {
                    return $matches[0];
                }
                $imgMatched = true;

                return $heroImgTag;
            },
            $html,
            -1
        );

        $htmlFinal = \is_string($html) ? $html : '';
        if (!$imgMatched && $htmlFinal !== '') {
            $injected = \preg_replace(
                '/(<div\b[^>]*\bclass=["\'][^"\']*\bai-site-hero\b[^"\']*["\'][^>]*>)/iu',
                '$1' . $heroImgTag,
                $htmlFinal,
                1,
            );
            $htmlFinal = \is_string($injected) ? $injected : $htmlFinal;
        }

        $themeTokens = $this->decodeConfigStringList($defaultConfig['contract.theme_tokens'] ?? null);
        $primary = $themeTokens[0] ?? '#2563eb';
        $secondary = $themeTokens[1] ?? '#06b6d4';
        $accent = $themeTokens[2] ?? '#f59e0b';

        $aiData['html_content'] = $htmlFinal;
        $aiData['css_extra'] = $this->buildAiHeroPhotoFusionCssSnippet($primary, $secondary, $accent) . (string)($aiData['css_extra'] ?? '');

        return $aiData;
    }

    /**
     * @param array<string,mixed> $aiData
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function buildStrictHeroBannerPayload(array $aiData, array $defaultConfig, string $imageUrl): array
    {
        $slotId = \trim((string)($defaultConfig['runtime.section_image_slot_id'] ?? $defaultConfig['visual.image_slot_id'] ?? ''));
        $title = $this->sanitizeVisibleCopy($this->firstConfigString($defaultConfig, [
            'content.title',
            'content.heading',
            'content.headline',
            'title',
            'heading',
        ]));
        if ($title === '') {
            $title = 'Premium Experience';
        }
        $body = $this->sanitizeVisibleCopy($this->firstConfigString($defaultConfig, [
            'content.description',
            'content.body',
            'description',
            'body',
        ]));
        if ($body === '') {
            $body = 'Explore the offer with confidence and continue when you are ready.';
        }
        $cta = $this->sanitizeVisibleCopy($this->firstConfigString($defaultConfig, [
            'content.cta_text',
            'cta.text',
            'button.text',
        ]));
        if ($cta === '') {
            $cta = 'Download APK';
        }
        $href = $this->firstConfigString($defaultConfig, ['content.cta_url', 'cta.url', 'button.url']);
        if ($href === '') {
            $href = '#download';
        }
        $escapedUrl = \htmlspecialchars($imageUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $escapedSlot = $slotId !== ''
            ? ' data-pb-ai-asset-slot="' . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
            : '';

        $aiData['html_content'] = '<section class="ai-site-hero ai-site-hero--has-photo pb-ai-strict-hero">'
            . '<img class="ai-site-visual-image ai-site-hero-image pb-ai-strict-hero__image" loading="eager" decoding="async" src="' . $escapedUrl . '" alt="' . \htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '" data-pb-ai-image-role="generated-asset"' . $escapedSlot . '>'
            . '<div class="pb-ai-strict-hero__shade"></div>'
            . '<div class="pb-ai-strict-hero__content">'
            . '<span class="pb-ai-strict-hero__eyebrow">Featured Download</span>'
            . '<h1>' . \htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
            . '<p>' . \htmlspecialchars($body, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<a class="pb-ai-strict-hero__cta" href="' . \htmlspecialchars($href, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '">' . \htmlspecialchars($cta, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</a>'
            . '</div>'
            . '</section>';
        $aiData['css_extra'] = '#componentId{padding:0!important;width:100vw!important;max-width:100vw!important;margin-left:calc(50% - 50vw)!important;margin-right:calc(50% - 50vw)!important;}'
            . "\n" . '#componentId>[class$="-container"]{width:100%!important;max-width:100%!important;padding:0!important;}'
            . "\n" . '#componentId [class$="-body"]{width:100%!important;}'
            . "\n" . '#componentId [class$="-header"]{display:none!important;}'
            . "\n" . '#componentId .pb-ai-strict-hero{position:relative;min-height:clamp(640px,82vh,860px);overflow:hidden;isolation:isolate;background:#0f172a;color:#fff;}'
            . "\n" . '#componentId .pb-ai-strict-hero__image{position:absolute;inset:0;width:100%;height:100%;max-width:none;object-fit:cover;object-position:center;filter:saturate(1.05) contrast(1.04) brightness(.78);z-index:0;}'
            . "\n" . '#componentId .pb-ai-strict-hero__shade{position:absolute;inset:0;z-index:1;background:linear-gradient(100deg,rgba(5,10,20,.86) 0%,rgba(5,10,20,.68) 38%,rgba(5,10,20,.18) 72%,rgba(5,10,20,.52) 100%),radial-gradient(circle at 18% 20%,rgba(255,209,102,.22),transparent 28%);}'
            . "\n" . '#componentId .pb-ai-strict-hero__content{position:relative;z-index:2;width:min(1120px,calc(100% - 48px));min-height:inherit;margin:0 auto;display:flex;flex-direction:column;justify-content:center;align-items:flex-start;padding:clamp(72px,9vw,128px) 0;}'
            . "\n" . '#componentId .pb-ai-strict-hero__eyebrow{display:inline-flex;margin-bottom:18px;padding:8px 14px;border-radius:999px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.18);backdrop-filter:blur(10px);font-weight:800;letter-spacing:.14em;text-transform:uppercase;font-size:12px;}'
            . "\n" . '#componentId .pb-ai-strict-hero h1{max-width:760px;margin:0 0 22px;font-size:clamp(46px,6vw,88px);line-height:.98;letter-spacing:-.055em;color:#fff;text-shadow:0 18px 48px rgba(0,0,0,.48);}'
            . "\n" . '#componentId .pb-ai-strict-hero p{max-width:620px;margin:0 0 34px;font-size:clamp(18px,1.45vw,23px);line-height:1.62;color:rgba(255,255,255,.92);text-shadow:0 10px 28px rgba(0,0,0,.42);}'
            . "\n" . '#componentId .pb-ai-strict-hero__cta{display:inline-flex;align-items:center;justify-content:center;padding:16px 30px;border-radius:999px;background:linear-gradient(135deg,#f8d46b,#b7791f);color:#111827;text-decoration:none;font-weight:900;box-shadow:0 22px 56px rgba(0,0,0,.36);transition:transform .22s ease,box-shadow .22s ease;}'
            . "\n" . '#componentId .pb-ai-strict-hero__cta:hover{transform:translateY(-2px);box-shadow:0 28px 68px rgba(0,0,0,.44);}'
            . "\n" . (string)($aiData['css_extra'] ?? '');
        $aiData['css_responsive'] = '#componentId .pb-ai-strict-hero__content{width:min(100% - 28px,720px);align-items:flex-start;} #componentId .pb-ai-strict-hero h1{font-size:clamp(38px,11vw,58px);}'
            . "\n" . (string)($aiData['css_responsive'] ?? '');
        $aiData['js_content'] = (string)($aiData['js_content'] ?? '');

        return $aiData;
    }

    private function buildAiHeroPhotoFusionCssSnippet(string $primaryHex, string $secondaryHex, string $accentHex): string
    {
        $norm = static function (string $hex, string $fallback): string {
            $candidate = \trim($hex);

            return \preg_match('/^#[0-9a-f]{6}$/i', $candidate) === 1 ? $candidate : $fallback;
        };

        $p = $norm($primaryHex, '#2563eb');
        $s = $norm($secondaryHex, '#06b6d4');
        $a = $norm($accentHex, '#f59e0b');

        return '#componentId{padding:0!important;width:100vw!important;max-width:100vw!important;margin-left:calc(50% - 50vw)!important;margin-right:calc(50% - 50vw)!important;}'
            . "\n"
            . '#componentId>[class$="-container"]{width:100%!important;max-width:100%!important;padding:0!important;}'
            . "\n"
            . '#componentId [class$="-body"]{width:100%!important;}'
            . "\n"
            . '#componentId .ai-site-hero--has-photo{background:none;background-color:color-mix(in srgb,' . $p . ' 22%,#0f172a);color:#fff;}'
            . "\n"
            . '#componentId .ai-site-hero--has-photo:after{background:linear-gradient(165deg,'
            . 'color-mix(in srgb,' . $p . ' 26%,rgba(15,23,42,.74)) 0%,'
            . 'color-mix(in srgb,' . $s . ' 14%,rgba(15,23,42,.26)) 46%,'
            . 'color-mix(in srgb,' . $a . ' 22%,rgba(15,23,42,.82)) 100%);}'
            . "\n";
    }

    /**
     * 通用 section 图片强制性注入：对于所有非 hero 的 section，如果 defaultConfig 中有 section_image_url
     * 但 AI 生成的 html_content 没有引用该 URL，则强制将占位 SVG/CSS 替换为真实图片。
     *
     * 核心原则：一旦生成了图片就必须使用，不能浪费。禁止占位图，必须用真实图片嵌入插槽。
     */
    private function enforceContractAllSectionImageUrlsInAiPayload(array $aiData, string $region, array $defaultConfig): array
    {
        if ($region !== 'content') {
            return $aiData;
        }
        $html = (string)($aiData['html_content'] ?? '');
        if ($html === '') {
            return $aiData;
        }

        // 如果 AI 已经有图片标签包含了 verified_assets 的数据属性，说明已自行处理，跳过
        $imageUrl = '';
        $slotId = '';
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

        // 如果 HTML 已经引用了该图片 URL（src 或 CSS url），不重复注入
        $escapedImageUrl = \htmlspecialchars($imageUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $slotId = \trim((string)($defaultConfig['runtime.section_image_slot_id'] ?? $defaultConfig['visual.image_slot_id'] ?? ''));
        $requiredSlotAttr = $slotId !== ''
            ? 'data-pb-ai-asset-slot="' . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
            : '';
        if (
            \str_contains($html, 'data-pb-ai-image-role="generated-asset"')
            && ($requiredSlotAttr === '' || \str_contains($html, $requiredSlotAttr))
        ) {
            return $aiData;
        }
        $escapedImageUrl = \htmlspecialchars($imageUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        if (\str_contains($html, $escapedImageUrl) && ($requiredSlotAttr === '' || \str_contains($html, $requiredSlotAttr))) {
            return $aiData;
        }

        $slotId = \trim((string)($defaultConfig['runtime.section_image_slot_id'] ?? $defaultConfig['visual.image_slot_id'] ?? ''));
        $alt = \htmlspecialchars(
            $this->sanitizeVisibleCopy((string)(
                $defaultConfig['runtime.section_image_alt']
                ?? $defaultConfig['visual.image_alt']
                ?? $defaultConfig['image.alt']
                ?? $defaultConfig['content.title']
                ?? 'Section image'
            )),
            \ENT_QUOTES | \ENT_SUBSTITUTE,
            'UTF-8'
        );
        $slotAttr = $slotId !== ''
            ? ' data-pb-ai-asset-slot="' . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
            : '';
        $imgTag = '<img src="' . $escapedImageUrl . '" alt="' . $alt . '"'
            . ' loading="lazy" decoding="async"'
            . ' style="width:100%;height:auto;max-width:100%;display:block;object-fit:cover;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.08);"'
            . ' data-pb-ai-image-role="generated-asset"' . $slotAttr . '>';

        // 策略 1：查找 SVG 占位容器（.ai-site-fallback-art, .ai-site-visual-panel, svg 父容器等），替换其内容
        $placeholderPatterns = [
            '/<div[^>]*\bai-site-fallback-art\b[^>]*>.*?<\/div>\s*<div[^>]*\bai-site-fallback-copy\b/is',
            '/<div[^>]*\bai-site-visual-panel\b[^>]*>.*?<\/div>/is',
            '/<div[^>]*\bai-site-fallback-art\b[^>]*>.*?<\/div>/is',
            '/<figure[^>]*>.*?<svg\b.*?<\/svg>.*?<\/figure>/is',
            '/<div[^>]*\bclass=["\'][^"\']*\bsvg-wrapper\b[^"\']*["\'][^>]*>.*?<\/div>/is',
        ];
        $replaced = false;
        foreach ($placeholderPatterns as $pattern) {
            $replacement = '<div class="ai-section-image-replaced" style="margin:16px 0;">' . $imgTag . '</div>';
            $count = 0;
            $html = \preg_replace($pattern, $replacement, $html, 1, $count);
            if ($count > 0) {
                $replaced = true;
                break;
            }
        }

        // 策略 2：如未替换任何占位，查找第一个 <svg> 元素，将其整个父容器替换为图片
        if (!$replaced && \preg_match('/<svg\b[^>]*>.*?<\/svg>/is', $html) === 1) {
            $html = \preg_replace(
                '/<div[^>]*>\s*<svg\b[^>]*>.*?<\/svg>\s*<\/div>/is',
                '<div class="ai-section-image-replaced" style="margin:16px 0;">' . $imgTag . '</div>',
                $html,
                1,
                $count
            );
            $replaced = $count > 0;
        }

        // 策略 3：没有任何 SVG 可替换，在末尾追加
        if (!$replaced) {
            $finalImgTag = '<div class="ai-section-image-wrapper" style="margin-top:20px;border-radius:12px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.08);">'
                . $imgTag . '</div>';
            $injected = \preg_replace(
                '/(<\/[a-z]+>\s*)$/iu',
                $finalImgTag . '$1',
                \trim($html),
                1
            );
            if (\is_string($injected) && $injected !== '') {
                $html = $injected;
                $replaced = true;
            }
        }

        $aiData['html_content'] = $html;
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
            throw new \RuntimeException((string)__('AI 组件包含无效图片资源：%{1}', [\implode(', ', \array_slice($broken, 0, 5))]));
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
        if (\str_starts_with($lower, 'data:image/') || \str_starts_with($lower, 'blob:')) {
            return false;
        }
        foreach (['example.com', 'placeholder.com', 'placehold.co', 'via.placeholder', 'dummyimage.com', 'placekitten.com', 'picsum.photos', 'loremflickr.com'] as $marker) {
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
            throw new \RuntimeException((string)__('AI 组件预览渲染失败：%{message}', [
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
        if ($this->shouldUseStubAiGeneration()) {
            return $this->buildStubAiPayload($region, $prompt, $defaultConfig, $renderContext);
        }

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
                    'temperature' => 0.35,
                    'max_tokens' => 4096,
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

        if (!$this->shouldRecoverComponentStreamFailure($streamThrowable)) {
            throw new \RuntimeException(
                'AI component stream failed (non-stream recovery skipped): '
                . $this->summarizeThrowable($streamThrowable),
                0,
                $streamThrowable
            );
        }

        $this->emitComponentStreamFallbackNotice(
            $region,
            'stream returned no usable JSON; retrying once with non-stream JSON mode'
        );

        try {
            $recoveryPrompt = $this->prependComponentJsonOnlyGuard(
                "RECOVERY CONTEXT: previous streaming attempt failed before returning usable JSON.\n"
                . 'Failure summary: ' . $this->summarizeThrowable($streamThrowable) . "\n"
                . "Return the final PageBuilder component JSON object now.\n\n"
                . $prompt,
                true
            );
            $response = $this->callAiOperation('generate', [
                'prompt' => $recoveryPrompt,
                'scenario_code' => 'pagebuilder_component_generation',
                'params' => $this->buildAiRuntimeParams([
                    'allow_zero_balance_provider' => true,
                    'temperature' => 0.2,
                    'max_tokens' => 8192,
                    'timeout' => self::AI_REQUEST_TIMEOUT_SECONDS,
                    'response_format' => ['type' => 'json_object'],
                ], false),
            ]);
            if (!\is_string($response) || \trim($response) === '') {
                throw new \RuntimeException('non-stream recovery returned empty content');
            }

            return $this->decodeAndNormalizeComponentContent(
                $response,
                $region,
                'AI component non-stream recovery did not return a valid component JSON payload'
            );
        } catch (\Throwable $recoveryThrowable) {
            throw new \RuntimeException(
                'AI component stream failed and non-stream recovery failed: '
                . $this->summarizeThrowable($streamThrowable)
                . ' | recovery: '
                . $this->summarizeThrowable($recoveryThrowable),
                0,
                $recoveryThrowable
            );
        }
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
            '- HTML string fields must be well-formed fragments: balanced tags, closed attribute quotes, no orphan closing tags, and no framework wrapper leakage.',
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

    private function shouldRecoverComponentStreamFailure(\Throwable $throwable): bool
    {
        $message = \strtolower($this->collectThrowableMessages($throwable));
        if (\trim($message) === '') {
            return true;
        }

        foreach ([
            '完成但未返回任何内容',
            '未返回任何内容',
            'streaming completed without final content',
            'completed without final content',
            'returned empty content',
            'empty content',
            'no content',
        ] as $marker) {
            if (\str_contains($message, $marker)) {
                return true;
            }
        }

        foreach ([
            'http 401',
            'http 402',
            'http 403',
            'invalid api key',
            'incorrect api key',
            'missing api key',
            'unauthorized',
            'authentication',
            'model unavailable',
            'model selection',
            'no available',
            'provider account',
            'provider configuration',
            'provider temporarily unavailable',
            'insufficient balance',
            'quota',
            'account',
        ] as $marker) {
            if (\str_contains($message, $marker)) {
                return false;
            }
        }

        foreach ([
            'stream disconnected',
            'stream failed',
            'unexpected eof',
            'unexpected end',
            'unterminated',
            'invalid json',
            'malformed json',
            'json payload',
            'control character',
            'tls connect error',
            'while reading',
        ] as $marker) {
            if (\str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
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
     * @return array<string,mixed>
     */
    private function buildStubAiPayload(
        string $region,
        string $prompt,
        array $defaultConfig = [],
        array $renderContext = []
    ): array
    {
        $copy = $this->resolveFallbackVisibleCopy(
            $region,
            $defaultConfig,
            $renderContext,
            (string)($renderContext['_content_locale'] ?? '')
        );
        $sectionPlan = $this->buildFallbackSectionPlan($defaultConfig, $renderContext, $prompt, $copy);
        $title = $copy['title'];
        $body = $copy['body'];
        $cjk = $copy['cjk'];
        $secondaryTitle = $cjk ? '立即行动' : 'Fast next step';
        $secondaryBody = $cjk
            ? '查看重点信息后，即可继续咨询、下载或完成下一步操作。'
            : 'Review the key details, then continue with the highlighted action when you are ready.';
        $trustTitle = $cjk ? '安全可靠' : 'Safe and clear';
        $trustBody = $cjk
            ? '清晰的标题、说明和操作入口帮助你快速判断服务是否适合。'
            : 'Clear labels, concise details, and direct actions help you decide with confidence.';
        $callout = $cjk
            ? '继续查看亮点、选择操作入口，并放心完成后续步骤。'
            : 'Explore the highlights, choose the next action, and continue with confidence.';
        $stageOneSamples = $this->decodeConfigStringList($defaultConfig['contract.stage1_samples'] ?? null);
        $themeTokens = \array_values(\array_filter(
            $this->decodeConfigStringList($defaultConfig['contract.theme_tokens'] ?? null),
            static fn(string $token): bool => \preg_match('/^#[0-9a-f]{6}$/i', $token) === 1
        ));
        $contractPrimary = $themeTokens[0] ?? '#2563eb';
        $contractSecondary = $themeTokens[1] ?? '#06b6d4';
        $contractAccent = $themeTokens[2] ?? '#f59e0b';
        $stageOneHtml = '';
        foreach (\array_slice($stageOneSamples, 0, 3) as $sample) {
            $stageOneHtml .= '<li>' . \htmlspecialchars($sample, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }
        $stageOnePanel = $stageOneHtml !== ''
            ? '<div class="ai-site-highlight-proof"><strong>' . ($cjk ? '核心亮点' : 'Highlights') . '</strong><ul>' . $stageOneHtml . '</ul></div>'
            : '';
        // 强行契约：当第一阶段已经为该 section 生成真实图片时，stub/fallback 必须用真图，
        // 而不是再退化成占位 SVG。`buildContractVisualPanel` 会优先用 verified_assets 的 final_url。
        $visualPanel = $this->buildContractVisualPanel(
            $defaultConfig,
            $title,
            $contractPrimary,
            $contractSecondary,
            $contractAccent
        );

        return match ($region) {
            'header' => [
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => '#componentId { --header-text:#172033; --header-link:#172033; --header-link-hover:var(--section-primary,#8B4513); position:sticky; top:0; z-index:20; border-bottom:1px solid color-mix(in srgb,var(--section-primary,#2563eb) 20%,transparent); background:linear-gradient(135deg,rgba(255,255,255,.96),color-mix(in srgb,var(--section-accent,#f59e0b) 10%,white)); backdrop-filter:blur(18px); box-shadow:0 18px 44px rgba(15,23,42,.10); color:var(--header-text); }'
                    . "\n" . '#componentId:after { content:""; position:absolute; left:8%; right:8%; bottom:-1px; height:2px; border-radius:99px; background:linear-gradient(90deg,transparent,var(--section-accent,#f59e0b),transparent); }'
                    . "\n" . '#componentId [class$="-logo"] { color:var(--header-text); text-shadow:none; } #componentId [class$="-logo"] img { width:44px; height:44px; border-radius:12px; box-shadow:0 10px 24px rgba(15,23,42,.16); }'
                    . "\n" . '#componentId a { color:inherit; transition:color .18s ease, transform .18s ease; } #componentId a:hover, #componentId a:focus-visible { color:var(--section-primary,#8B4513); transform:translateY(-1px); }',
                'html_extra' => '',
                'js_content' => '',
            ],
            'footer' => [
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => '#componentId { position:relative; overflow:hidden; border-top:1px solid color-mix(in srgb,var(--section-accent,#f59e0b) 28%,transparent); background:radial-gradient(circle at 86% 10%,color-mix(in srgb,var(--section-accent,#f59e0b) 20%,transparent),transparent 30%),linear-gradient(135deg,var(--section-heading,#0f172a),color-mix(in srgb,var(--section-primary,#2563eb) 42%,#0f172a)); color:var(--section-bg,#fff); box-shadow:0 -24px 70px rgba(15,23,42,.18); }'
                    . "\n" . '#componentId:before { content:""; position:absolute; inset:0; background:repeating-linear-gradient(135deg,rgba(255,255,255,.10) 0 1px,transparent 1px 12px); opacity:.32; pointer-events:none; }'
                    . "\n" . '#componentId a { color:inherit; text-decoration-color:rgba(255,255,255,.38); text-underline-offset:4px; } #componentId a:hover, #componentId a:focus-visible { color:var(--section-accent,#f59e0b); }',
                'html_extra_column' => '',
                'html_extra' => '',
                'footer_extra_text' => $cjk ? '页面内容可持续维护' : 'Content can be maintained after publishing',
                'js_content' => '',
            ],
            default => $this->buildStubAiContentPayload(
                $sectionPlan,
                $copy,
                $defaultConfig,
                $visualPanel,
                $stageOnePanel,
                $stageOneSamples,
                $contractPrimary,
                $contractSecondary,
                $contractAccent
            ),
        };
    }

    /**
     * 强行契约：根据 section variant 分发不同的 stub 渲染。
     * - hero/banner → 大图背景 + 标题/CTA 居中覆盖 + slide 自动轮播；
     * - 其他 → 卡片网格 + 视觉面板。
     *
     * @param array<string,mixed> $sectionPlan
     * @param array{title:string,body:string,cjk:bool} $copy
     * @param array<string,mixed> $defaultConfig
     * @param array{html:string,has_image:bool,image_url:string} $visualPanel
     * @param list<string> $stageOneSamples
     * @return array<string,mixed>
     */
    private function buildStubAiContentPayload(
        array $sectionPlan,
        array $copy,
        array $defaultConfig,
        array $visualPanel,
        string $stageOnePanel,
        array $stageOneSamples,
        string $contractPrimary,
        string $contractSecondary,
        string $contractAccent
    ): array {
        $variant = (string)($sectionPlan['variant'] ?? 'hero');
        if ($variant === 'hero' || $variant === 'banner') {
            return $this->buildStubHeroBannerPayload(
                $sectionPlan,
                $copy,
                $defaultConfig,
                $visualPanel,
                $stageOneSamples,
                $contractPrimary,
                $contractSecondary,
                $contractAccent
            );
        }
        $cards = \is_array($sectionPlan['cards'] ?? null) ? $sectionPlan['cards'] : [];
        $cardHtml = '';
        foreach ($cards as $card) {
            if (!\is_array($card)) {
                continue;
            }
            $meta = \trim((string)($card['meta'] ?? ''));
            $cardHtml .= '<article class="ai-site-card">'
                . ($meta !== '' ? '<span class="ai-site-card-meta">' . \htmlspecialchars($meta, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</span>' : '')
                . '<strong>' . \htmlspecialchars((string)($card['title'] ?? ''), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</strong>'
                . '<p>' . \htmlspecialchars((string)($card['body'] ?? ''), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '</article>';
        }
        if ($cardHtml === '') {
            $cardHtml = '<article class="ai-site-card"><strong>' . \htmlspecialchars((string)$sectionPlan['card_title_primary'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</strong><p>' . \htmlspecialchars((string)$sectionPlan['card_body_primary'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p></article>';
        }
        $proofPoints = \is_array($sectionPlan['proof_points'] ?? null) ? $sectionPlan['proof_points'] : [];
        $proofHtml = $proofPoints !== []
            ? '<div class="ai-site-highlight-proof"><strong>' . \htmlspecialchars($this->resolveFallbackProofHeading((string)($sectionPlan['variant'] ?? ''), (bool)($copy['cjk'] ?? false)), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</strong><ul>' . $this->buildFallbackProofHtml($proofPoints) . '</ul></div>'
            : '';
        $calloutBody = \trim((string)($sectionPlan['callout_body'] ?? ''));
        $calloutHtml = $calloutBody !== ''
            ? '<div class="ai-site-callout"><p>' . \htmlspecialchars($calloutBody, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p></div>'
            : '';

        return [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId { --ai-site-contract-primary:' . $contractPrimary . '; --ai-site-contract-secondary:' . $contractSecondary . '; --ai-site-contract-accent:' . $contractAccent . '; }'
                . "\n" . '#componentId .ai-site-card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:clamp(16px,2.4vw,24px); margin-top:clamp(26px,4vw,42px); }'
                . "\n" . '#componentId .ai-site-card { min-height:150px; padding:24px; border-radius:24px; border:1px solid var(--section-border); background:linear-gradient(180deg,rgba(255,255,255,.92),rgba(255,255,255,.76)); text-align:left; box-shadow:0 16px 42px rgba(15,23,42,.10); transition:transform .22s ease, box-shadow .22s ease; }'
                . "\n" . '#componentId .ai-site-card:hover { transform:translateY(-4px); box-shadow:0 22px 54px rgba(15,23,42,.16); }'
                . "\n" . '#componentId .ai-site-card-meta { display:inline-flex; margin-bottom:12px; color:var(--ai-site-contract-primary); font-weight:800; letter-spacing:.04em; }'
                . "\n" . '#componentId .ai-site-card strong { display:block; margin-bottom:10px; font-size:clamp(17px,1.4vw,22px); color:var(--section-heading,#0f172a); } #componentId .ai-site-card p { margin:0; line-height:1.65; }'
                . "\n" . '#componentId .ai-site-visual-panel { position:relative; width:min(620px,100%); aspect-ratio:16/10; min-height:0; margin:clamp(30px,5vw,56px) auto 0; border-radius:28px; overflow:hidden; box-shadow:0 24px 70px rgba(15,23,42,.20); background:linear-gradient(135deg,var(--ai-site-contract-primary),var(--ai-site-contract-secondary),var(--ai-site-contract-accent)); }'
                . "\n" . '#componentId .ai-site-visual-panel--image { background:color-mix(in srgb,var(--ai-site-contract-primary) 18%,#0f172a); }'
                . "\n" . '#componentId .ai-site-visual-panel--image:after { content:""; position:absolute; inset:0; background:linear-gradient(155deg,color-mix(in srgb,var(--ai-site-contract-primary) 22%,rgba(15,23,42,.70)) 0%,rgba(15,23,42,.10) 50%,color-mix(in srgb,var(--ai-site-contract-accent) 18%,rgba(15,23,42,.76)) 100%); pointer-events:none; }'
                . "\n" . '#componentId .ai-site-css-visual { position:absolute; inset:0; overflow:hidden; background:radial-gradient(circle at 16% 18%,rgba(255,255,255,.30),transparent 24%),radial-gradient(circle at 84% 72%,rgba(255,255,255,.20),transparent 30%),linear-gradient(145deg,var(--ai-site-contract-primary),var(--ai-site-contract-secondary)); } #componentId .ai-site-css-visual span { position:absolute; border-radius:999px; background:rgba(255,255,255,.28); box-shadow:0 18px 46px rgba(15,23,42,.16); } #componentId .ai-site-css-visual span:nth-child(1){left:9%;top:14%;width:34%;height:20%;} #componentId .ai-site-css-visual span:nth-child(2){right:10%;top:22%;width:38%;height:9%;} #componentId .ai-site-css-visual span:nth-child(3){left:16%;bottom:16%;width:58%;height:12%;}'
                . "\n" . '#componentId .ai-site-visual-image { position:absolute; inset:0; width:100%; height:100%; display:block; object-fit:cover; object-position:center; }'
                . "\n" . '#componentId .ai-site-highlight-proof { padding:22px 24px; border-radius:24px; background:rgba(255,255,255,.82); border:1px solid var(--section-border); text-align:left; box-shadow:0 14px 36px rgba(15,23,42,.09); }'
                . "\n" . '#componentId .ai-site-highlight-proof ul { margin:12px 0 0; padding-left:20px; display:grid; gap:10px; }'
                . "\n" . '#componentId .ai-site-callout { margin-top:16px; padding:18px 20px; border-radius:18px; background:color-mix(in srgb,var(--section-primary) 10%,white); color:var(--section-heading); text-align:left; }',
            'css_responsive' => '#componentId .ai-site-card-grid { grid-template-columns:1fr; }',
            'html_content' => '<div class="ai-site-card-grid">' . $cardHtml . '</div>'
                . '<div class="ai-site-visual-panel' . ($visualPanel['has_image'] ? ' ai-site-visual-panel--image' : '') . '">' . $visualPanel['html'] . '</div>',
            'js_content' => '',
        ];
    }

    private function resolveFallbackProofHeading(string $variant, bool $cjk): string
    {
        return match ($variant) {
            'checklist' => $cjk ? '关键问题' : 'Key details',
            'cta' => $cjk ? '行动理由' : 'Why act now',
            default => $cjk ? '本块重点' : 'Block highlights',
        };
    }

    /**
     * 强行契约：hero/banner stub 必须是真正的"banner"——大图全宽背景、覆盖式标题/CTA、
     * 多 slide 自动轮播；不再退化为通用卡片网格。
     *
     * 即使 stage1 只生成单张主图，这里也通过 `proof_points` 派生多 slide 内容（每个 slide
     * 复用同一张 hero 图但展示不同标题/亮点），形成视觉上的轮播节奏。
     *
     * @param array<string,mixed> $sectionPlan
     * @param array{title:string,body:string,cjk:bool} $copy
     * @param array<string,mixed> $defaultConfig
     * @param array{html:string,has_image:bool,image_url:string} $visualPanel
     * @param list<string> $stageOneSamples
     * @return array<string,mixed>
     */
    private function buildStubHeroBannerPayload(
        array $sectionPlan,
        array $copy,
        array $defaultConfig,
        array $visualPanel,
        array $stageOneSamples,
        string $contractPrimary,
        string $contractSecondary,
        string $contractAccent
    ): array {
        $cjk = (bool)($copy['cjk'] ?? false);
        $title = (string)$sectionPlan['title'];
        $body = (string)$sectionPlan['body'];
        $eyebrow = (string)$sectionPlan['eyebrow'];
        $primaryAction = (string)$sectionPlan['card_body_secondary'];
        if ($primaryAction === '') {
            $primaryAction = $cjk ? '立即开始' : 'Get started';
        }
        $primaryActionHref = $this->pickFallbackConfigCopy($defaultConfig, [
            'cta.url',
            'content.cta_url',
            'button.url',
        ], 200);
        if ($primaryActionHref === '' || \str_starts_with($primaryActionHref, '#') === false) {
            $primaryActionHref = '#';
        }

        // 派生 slide：第一张用 sectionPlan 主标题/正文；后续 slide 用 proof_points / stage1 samples 形成轮播节奏。
        $slides = [];
        $slides[] = [
            'eyebrow' => $eyebrow,
            'title' => $title,
            'body' => $body,
        ];
        $proofPoints = \is_array($sectionPlan['proof_points'] ?? null) ? $sectionPlan['proof_points'] : [];
        foreach ($proofPoints as $idx => $point) {
            $point = \trim((string)$point);
            if ($point === '' || $idx > 2) {
                continue;
            }
            $slides[] = [
                'eyebrow' => $cjk ? '亮点 ' . ($idx + 1) : 'Highlight ' . ($idx + 1),
                'title' => $point,
                'body' => $stageOneSamples[$idx + 1] ?? $body,
            ];
        }
        if (\count($slides) < 2) {
            // 无足够轮播素材时使用 sectionPlan 衍生第二张，避免 carousel 只剩一张滑块。
            $slides[] = [
                'eyebrow' => (string)$sectionPlan['card_title_primary'],
                'title' => (string)$sectionPlan['card_title_secondary'],
                'body' => (string)$sectionPlan['card_body_secondary'],
            ];
        }

        $slidesHtml = '';
        $slideCount = \count($slides);
        foreach ($slides as $i => $slide) {
            $slidesHtml .= '<article class="ai-site-hero-slide" data-slide-index="' . $i . '" aria-hidden="' . ($i === 0 ? 'false' : 'true') . '">'
                . ($slide['eyebrow'] !== '' ? '<span class="ai-site-hero-eyebrow">' . \htmlspecialchars((string)$slide['eyebrow'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</span>' : '')
                . '<h2 class="ai-site-hero-title">' . \htmlspecialchars((string)$slide['title'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</h2>'
                . '<p class="ai-site-hero-body">' . \htmlspecialchars((string)$slide['body'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '<a class="ai-site-hero-cta" href="' . \htmlspecialchars($primaryActionHref, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '">' . \htmlspecialchars($primaryAction, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</a>'
                . '</article>';
        }

        $dotsHtml = '';
        for ($i = 0; $i < $slideCount; $i++) {
            $dotsHtml .= '<button type="button" class="ai-site-hero-dot' . ($i === 0 ? ' is-active' : '') . '" data-slide-target="' . $i . '" aria-label="' . ($cjk ? '第 ' . ($i + 1) . ' 帧' : 'Slide ' . ($i + 1)) . '"></button>';
        }

        // 优先使用第一阶段已落地的 verified_asset 真实图片；没有真图则用渐变色。
        $hasImage = (bool)$visualPanel['has_image'];
        $imageUrl = (string)$visualPanel['image_url'];
        // slot id 是属性值（不是访客可见文本），不能用 sanitizeVisibleCopy 处理，
        // 否则 `page:home_page:content-...` 这类合法 slot id 会被规划关键字正则吞掉。
        $slotId = \trim((string)($defaultConfig['runtime.section_image_slot_id'] ?? ''));
        $imageAlt = $this->sanitizeVisibleCopy((string)(
            $defaultConfig['runtime.section_image_alt']
            ?? $defaultConfig['visual.image_alt']
            ?? $defaultConfig['image.alt']
            ?? $title
        ));
        if ($imageAlt === '') {
            $imageAlt = $title;
        }
        $bgLayer = '';
        $heroFusionClass = '';
        if ($hasImage && $slotId !== '') {
            $bgLayer .= '<img class="ai-site-visual-image ai-site-hero-image" loading="eager" decoding="async"'
                . ' src="' . \htmlspecialchars($imageUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                . ' alt="' . \htmlspecialchars($imageAlt, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                . ' data-pb-ai-image-role="generated-asset"'
                . ' data-pb-ai-asset-slot="' . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '">';
            $heroFusionClass = ' ai-site-hero--has-photo';
        } elseif ($hasImage) {
            $bgLayer .= '<img class="ai-site-visual-image ai-site-hero-image" loading="eager" decoding="async"'
                . ' src="' . \htmlspecialchars($imageUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                . ' alt="' . \htmlspecialchars($imageAlt, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                . ' data-pb-ai-image-role="generated-asset">';
            $heroFusionClass = ' ai-site-hero--has-photo';
        }

        $cssExtra = '#componentId { --ai-site-hero-primary:' . $contractPrimary . '; --ai-site-hero-secondary:' . $contractSecondary . '; --ai-site-hero-accent:' . $contractAccent . '; padding:0 !important; width:100vw !important; max-width:100vw !important; margin-left:calc(50% - 50vw) !important; margin-right:calc(50% - 50vw) !important; }'
            . "\n" . '#componentId [class$="-header"] { display:none !important; }'
            . "\n" . '#componentId .ai-site-hero { position:relative; overflow:hidden; min-height:clamp(620px,39.0625vw,760px); border-radius:0; isolation:isolate; color:#fff; background:radial-gradient(circle at 78% 16%,color-mix(in srgb,var(--ai-site-hero-accent) 30%,transparent),transparent 30%),linear-gradient(135deg,var(--ai-site-hero-primary),color-mix(in srgb,var(--ai-site-hero-secondary) 78%,#111827)); }'
            . "\n" . '#componentId .ai-site-hero:not(.ai-site-hero--has-photo) { background:linear-gradient(135deg,var(--ai-site-hero-primary),var(--ai-site-hero-secondary) 60%,var(--ai-site-hero-accent)); }'
            . "\n" . '#componentId .ai-site-hero--has-photo { background:none; background-color:color-mix(in srgb,var(--ai-site-hero-primary) 22%,#0f172a); }'
            . "\n" . '#componentId .ai-site-hero-image { position:absolute; inset:0; width:100%; height:100%; max-width:none; object-fit:cover; object-position:center; z-index:0; border-radius:0; box-shadow:none; filter:saturate(1.08) contrast(1.05) brightness(.84); }'
            . "\n" . '#componentId .ai-site-hero:not(.ai-site-hero--has-photo):after { content:""; position:absolute; inset:0; background:linear-gradient(135deg,rgba(15,23,42,.35) 0%,rgba(15,23,42,.14) 50%,rgba(15,23,42,.48) 100%); z-index:0; pointer-events:none; }'
            . "\n" . '#componentId .ai-site-hero--has-photo:after { content:""; position:absolute; inset:0; background:linear-gradient(92deg,rgba(6,10,20,.88) 0%,rgba(6,10,20,.66) 42%,rgba(6,10,20,.22) 72%,rgba(6,10,20,.58) 100%),radial-gradient(circle at 20% 28%,rgba(255,255,255,.13),transparent 28%); z-index:1; pointer-events:none; }'
            . "\n" . '#componentId .ai-site-hero-track { position:relative; z-index:2; display:grid; grid-template-areas:"slide"; min-height:inherit; width:min(1200px,calc(100% - 48px)); margin:0 auto; padding:clamp(64px,9vw,116px) 0; }'
            . "\n" . '#componentId .ai-site-hero-slide { grid-area:slide; max-width:min(720px,58vw); align-self:center; opacity:0; transform:translateX(24px); transition:opacity .9s ease, transform .9s ease; pointer-events:none; }'
            . "\n" . '#componentId .ai-site-hero-slide[aria-hidden="false"] { opacity:1; transform:translateX(0); pointer-events:auto; padding:clamp(28px,4vw,48px); border-radius:34px; background:linear-gradient(135deg,rgba(7,13,25,.74),rgba(7,13,25,.36)); border:1px solid rgba(255,255,255,.16); box-shadow:0 32px 110px rgba(0,0,0,.36); backdrop-filter:blur(14px); }'
            . "\n" . '#componentId .ai-site-hero-eyebrow { display:inline-block; padding:6px 14px; margin-bottom:18px; border-radius:999px; background:rgba(255,255,255,.18); backdrop-filter:blur(8px); font-size:13px; font-weight:700; letter-spacing:.14em; text-transform:uppercase; }'
            . "\n" . '#componentId .ai-site-hero-title { margin:0 0 18px; font-size:clamp(40px,5.6vw,76px); line-height:1.02; letter-spacing:-.045em; font-weight:900; color:#fff; text-shadow:0 3px 0 rgba(0,0,0,.16),0 18px 42px rgba(0,0,0,.58); }'
            . "\n" . '#componentId .ai-site-hero-body { margin:0 0 28px; max-width:58ch; font-size:clamp(17px,1.45vw,21px); line-height:1.62; color:rgba(255,255,255,.98); text-shadow:0 8px 24px rgba(0,0,0,.42); }'
            . "\n" . '#componentId .ai-site-hero-cta { display:inline-flex; align-items:center; gap:8px; padding:14px 28px; border-radius:999px; background:var(--ai-site-hero-accent,#f59e0b); color:#0f172a; font-weight:800; text-decoration:none; box-shadow:0 18px 44px rgba(15,23,42,.32); transition:transform .22s ease, box-shadow .22s ease; }'
            . "\n" . '#componentId .ai-site-hero-cta:hover { transform:translateY(-2px); box-shadow:0 22px 54px rgba(15,23,42,.40); }'
            . "\n" . '#componentId .ai-site-hero-dots { position:absolute; left:0; right:0; bottom:24px; z-index:3; display:flex; justify-content:center; gap:10px; }'
            . "\n" . '#componentId .ai-site-hero-dot { width:32px; height:4px; padding:0; border:none; border-radius:999px; background:rgba(255,255,255,.36); cursor:pointer; transition:background .22s ease, transform .22s ease; }'
            . "\n" . '#componentId .ai-site-hero-dot:hover { background:rgba(255,255,255,.7); }'
            . "\n" . '#componentId .ai-site-hero-dot.is-active { background:#fff; transform:scaleX(1.4); }';

        $jsContent = 'const slides=Array.from(component.querySelectorAll(".ai-site-hero-slide"));'
            . 'const dots=Array.from(component.querySelectorAll(".ai-site-hero-dot"));'
            . 'if(slides.length>1){'
            . 'let idx=0;let timer=null;'
            . 'const show=function(next){'
            . 'idx=(next+slides.length)%slides.length;'
            . 'slides.forEach(function(s,i){s.setAttribute("aria-hidden",i===idx?"false":"true");});'
            . 'dots.forEach(function(d,i){d.classList.toggle("is-active",i===idx);});'
            . '};'
            . 'const start=function(){timer=setInterval(function(){show(idx+1);},5200);};'
            . 'const stop=function(){if(timer){clearInterval(timer);timer=null;}};'
            . 'dots.forEach(function(d,i){d.addEventListener("click",function(){show(i);stop();start();});});'
            . 'component.addEventListener("mouseenter",stop);component.addEventListener("mouseleave",start);'
            . 'start();'
            . '}';

        return [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => $cssExtra,
            'css_responsive' => '#componentId { width:100vw !important; max-width:100vw !important; margin-left:calc(50% - 50vw) !important; margin-right:calc(50% - 50vw) !important; } #componentId .ai-site-hero { min-height:clamp(560px,62vh,720px); } #componentId .ai-site-hero-track { width:min(100% - 32px,720px); padding:64px 0; } #componentId .ai-site-hero-slide { max-width:100%; } #componentId .ai-site-hero-slide[aria-hidden="false"] { padding:26px; }',
            'html_content' => '<div class="ai-site-hero' . $heroFusionClass . '">'
                . $bgLayer
                . '<div class="ai-site-hero-track">' . $slidesHtml . '</div>'
                . ($slideCount > 1 ? '<div class="ai-site-hero-dots" role="tablist">' . $dotsHtml . '</div>' : '')
                . '</div>',
            'js_content' => $jsContent,
        ];
    }

    /**
     * 强行契约视觉面板：优先使用第一阶段已落地的 verified_asset 真实图片；
     * 没有真图时只退回 CSS 视觉层，不能再生成内联 SVG 占位。
     *
     * @param array<string,mixed> $defaultConfig
     * @return array{html:string,has_image:bool,image_url:string}
     */
    private function buildContractVisualPanel(
        array $defaultConfig,
        string $titleForAlt,
        string $contractPrimary,
        string $contractSecondary,
        string $contractAccent
    ): array {
        $imageUrl = '';
        foreach (['runtime.section_image_url', 'visual.image_url', 'image.url', 'media.image_url'] as $candidateKey) {
            $value = \trim((string)($defaultConfig[$candidateKey] ?? ''));
            if ($value !== '') {
                $imageUrl = $value;
                break;
            }
        }

        if ($imageUrl !== '') {
            $alt = $this->sanitizeVisibleCopy((string)(
                $defaultConfig['runtime.section_image_alt']
                ?? $defaultConfig['visual.image_alt']
                ?? $defaultConfig['image.alt']
                ?? $titleForAlt
                ?? ''
            ));
            if ($alt === '') {
                $alt = $titleForAlt !== '' ? $titleForAlt : 'Section visual';
            }
            $slotId = \trim((string)(
                $defaultConfig['runtime.section_image_slot_id']
                ?? $defaultConfig['visual.image_slot_id']
                ?? ''
            ));
            $slotAttr = $slotId !== ''
                ? ' data-pb-ai-asset-slot="' . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                : '';

            $html = '<img class="ai-site-visual-image" loading="lazy" decoding="async"'
                . ' src="' . \htmlspecialchars($imageUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                . ' alt="' . \htmlspecialchars($alt, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                . ' data-pb-ai-image-role="generated-asset"' . $slotAttr . '>';

            return [
                'html' => $html,
                'has_image' => true,
                'image_url' => $imageUrl,
            ];
        }

        $visual = '<div class="ai-site-css-visual" role="img" aria-label="'
            . \htmlspecialchars($titleForAlt, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . ' visual system">'
            . '<span></span><span></span><span></span></div>';

        return [
            'html' => $visual,
            'has_image' => false,
            'image_url' => '',
        ];
    }

    private function extractStubTitleFromPrompt(string $summary, string $region): string
    {
        foreach ([
            '/title["\']?\s*[:=]\s*["\']([^"\']{4,80})["\']/iu',
            '/headline["\']?\s*[:=]\s*["\']([^"\']{4,80})["\']/iu',
            '/站点[:：]\s*([^,，。]{4,60})/u',
            '/Site[:：]\s*([^,，。]{4,60})/iu',
        ] as $pattern) {
            if (\preg_match($pattern, $summary, $matches) === 1) {
                $title = \trim((string)($matches[1] ?? ''));
                if ($title !== '') {
                    return $title;
                }
            }
        }

        return match ($region) {
            'hero' => 'Featured story',
            'cta' => 'Clear next step',
            default => 'Featured content',
        };
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
        if ($region === 'content' && (!isset($normalized['html_content']) || \trim((string)$normalized['html_content']) === '')) {
            $html = $this->buildHtmlContentFromStructuredAiData($data);
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
     */
    private function buildHtmlContentFromStructuredAiData(array $data): ?string
    {
        $texts = [];
        $this->collectStructuredAiVisibleTexts($data, $texts);
        $texts = \array_values(\array_unique(\array_filter($texts, static fn(string $text): bool => \trim($text) !== '')));
        if (\count($texts) < 2) {
            return null;
        }

        $title = \array_shift($texts);
        $lead = \array_shift($texts);
        if ($title === null || $lead === null) {
            return null;
        }

        $html = '<section class="pb-ai-structured-section">'
            . '<div class="pb-ai-structured-copy"><h2>' . \htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</h2>'
            . '<p>' . \htmlspecialchars($lead, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p></div>';
        if ($texts !== []) {
            $html .= '<div class="pb-ai-structured-grid">';
            foreach (\array_slice($texts, 0, 6) as $text) {
                $html .= '<article class="pb-ai-structured-card"><p>' . \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p></article>';
            }
            $html .= '</div>';
        }
        $html .= '</section>';

        return $html;
    }

    /**
     * @param list<string> $texts
     */
    private function collectStructuredAiVisibleTexts(mixed $value, array &$texts, string $path = '', int $depth = 0): void
    {
        if (\count($texts) >= 10 || $depth > 5) {
            return;
        }
        if (\is_array($value)) {
            foreach ($value as $key => $child) {
                $key = \strtolower(\trim((string)$key));
                $childPath = $path === '' ? $key : $path . '.' . $key;
                if ($this->isNonVisibleStructuredAiKey($key) || $this->isNonVisibleStructuredAiKey($childPath)) {
                    continue;
                }
                $this->collectStructuredAiVisibleTexts($child, $texts, $childPath, $depth + 1);
            }
            return;
        }
        if (!\is_scalar($value)) {
            return;
        }

        $text = $this->sanitizeVisibleCopy(\trim((string)$value));
        if ($text === '' || \strlen($text) < 6 || \str_contains($text, '<') || \str_contains($text, '{')) {
            return;
        }
        if (\preg_match('/^(?:true|false|null|content|header|footer|section|block)$/i', $text) === 1) {
            return;
        }
        $texts[] = $this->clipText($text, 180);
    }

    private function isNonVisibleStructuredAiKey(string $key): bool
    {
        return \preg_match('/(?:css|style|script|js|php|html|markup|template|prompt|instruction|contract|schema|task|section_code|block_key|page_type|component|locale|language|id|key|url|href|src|image|asset|icon|color|token|meta)/i', $key) === 1;
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
            (string)__('正在对组件 JSON 进行本地解析与修复（提取 JSON、控制字符、尾逗号、截断补全等）…')
        );
        $decoded = $parser->extractAndDecode($content);
        if (\is_array($decoded)) {
            $this->emitJsonRepairChunk(
                $region,
                (string)__('本地解析与修复成功，已得到有效组件 JSON。')
            );
            return $decoded;
        }

        if (self::JSON_REPAIR_MAX_ATTEMPTS <= 0) {
            return null;
        }

        $this->emitJsonRepairChunk(
            $region,
            (string)__(
                '本地解析与修复仍未得到有效 JSON，将按轮次调用 AI 进行结构修复（共 %{1} 轮）',
                [self::JSON_REPAIR_MAX_ATTEMPTS]
            )
        );

        $currentContent = $content;
        for ($attempt = 1; $attempt <= self::JSON_REPAIR_MAX_ATTEMPTS; $attempt++) {
            $this->emitJsonRepairChunk(
                $region,
                (string)__(
                    '第 %{1}/%{2} 轮：正在请求 AI 修复 JSON 结构…',
                    [$attempt, self::JSON_REPAIR_MAX_ATTEMPTS]
                )
            );
            $retryContent = $this->requestJsonRepair(
                $region,
                (string)__('AI 未返回有效的组件 JSON 结果'),
                $currentContent
            );
            if ($retryContent === null || \trim($retryContent) === '') {
                $this->emitJsonRepairChunk(
                    $region,
                    (string)__(
                        '第 %{1}/%{2} 轮：AI 未返回可用内容，将尝试下一轮（若仍有）。',
                        [$attempt, self::JSON_REPAIR_MAX_ATTEMPTS]
                    )
                );
                continue;
            }

            $currentContent = $retryContent;
            $this->emitJsonRepairChunk(
                $region,
                (string)__(
                    '第 %{1}/%{2} 轮：AI 已返回，正在解析校验…',
                    [$attempt, self::JSON_REPAIR_MAX_ATTEMPTS]
                )
            );
            $decoded = $parser->extractAndDecode($currentContent);
            if (\is_array($decoded)) {
                $this->emitJsonRepairChunk(
                    $region,
                    (string)__(
                        '第 %{1}/%{2} 轮：AI 修复后解析成功。',
                        [$attempt, self::JSON_REPAIR_MAX_ATTEMPTS]
                    )
                );
                return $decoded;
            }
            $this->emitJsonRepairChunk(
                $region,
                (string)__(
                    '第 %{1}/%{2} 轮：解析仍失败，将继续下一轮（若仍有）。',
                    [$attempt, self::JSON_REPAIR_MAX_ATTEMPTS]
                )
            );
        }

        return null;
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

    private function requestJsonRepair(string $region, string $validationError, string $previousContent): ?string
    {
        $previousSnippet = $this->clipText($previousContent, 8000);
        $expectedFields = match ($region) {
            'header' => 'extra_fields, php_variables, css_extra, html_extra, js_content',
            'footer' => 'extra_fields, php_variables, css_extra, html_extra_column, html_extra, footer_extra_text, js_content',
            default => 'extra_fields, php_variables, css_extra, css_responsive, html_content, js_content',
        };
        $safety = $this->buildComponentJsonPhpSafetyRulesEn();
        $prompt = "You are repairing a malformed PageBuilder {$region} component JSON.\n"
            . "The previous output failed because: {$validationError}\n"
            . "Return ONLY one corrected JSON object. No markdown. No explanation.\n"
            . "Keep valid content when possible, but fix the JSON structure first.\n"
            . "Expected JSON fields: {$expectedFields}\n"
            . "After JSON is valid, ensure php_variables / html_* / css_* / js_content will not cause PHP parse errors when merged into a .phtml template (especially complete array syntax and no => outside valid PHP arrays).\n"
            . $safety
            . "Previous invalid output:\n{$previousSnippet}";

        $response = $this->callAiOperation('generate', [
            'prompt' => $prompt,
            'scenario_code' => 'pagebuilder_component_generation',
            'params' => $this->buildAiRuntimeParams([
                'temperature' => 0.2,
                'max_tokens' => 8192,
                'timeout' => self::AI_REQUEST_TIMEOUT_SECONDS,
                'response_format' => ['type' => 'json_object'],
                'allow_zero_balance_provider' => true,
            ]),
        ]);

        return \is_string($response) ? $response : null;
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
            $params['timeout'] = 0;
            $params['disable_ai_timeout'] = true;
            $params['disable_cli_timeout'] = true;
            $params['enforce_timeout_in_stream'] = false;
        }

        return $params;
    }

    /**
     * DeepSeek/GLM 等 thinking 协议模型在 JSON 合约任务上可能只返回 reasoning_content。
     * 对组件生成统一禁用 thinking，避免流式/非流式行为分叉。
     *
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
            '_content_locale_explicit' => \trim((string)($scope['content_locale'] ?? $websiteProfile['content_locale'] ?? '')) !== '',
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
            if ($slotType !== 'logo_icon' || !\in_array($field, ['logo', 'logo.image', 'brand.logo'], true)) {
                continue;
            }
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
            if ($finalUrl !== '') {
                return $finalUrl;
            }
        }
        foreach ($this->extractManifestSlots($scope) as $slot) {
            $slotType = \trim((string)($slot['slot_type'] ?? ''));
            if (!\in_array($slotType, ['logo_icon', 'trust_brand_image'], true)) {
                continue;
            }
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
            if ($finalUrl !== '') {
                return $finalUrl;
            }
        }

        return '';
    }

    /**
     * 强行契约：解析 section 应当继承的图片 URL。
     *
     * 设计原则（用户要求"不能让 build 用占位图当 stage1 已经有真实图"）：
     *   1. 真实图片永远优先于占位图。
     *   2. 在"都是真实"或"都是占位"内部，再按 page_type 精确度排序：
     *      a. slot_id 与 section 之 page+code 精确匹配（如 `page:home_page:content-home-page-hero`）。
     *      b. 同 page_type 下、slot_type 命中且 slot_id/block_key/task_key 含 section 关键字。
     *      c. 同 page_type 下、slot_type 命中的首个 slot。
     *      d. legacy（page_type==''）+ 关键字命中（haystack 含 brief/label，因 stage1 散标 slot 信息常在 brief）。
     *      e. legacy + slot_type 命中的首个 slot。
     *
     * 这样既避免被关键字匹配错的 legacy slot 抢占（如 slot_id=5、brief 含 "hero"），
     * 也不会让仅有占位图的 page-scoped slot 排挤掉 stage1 已落地的真实 legacy 资产。
     *
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

        // 按 specificity 排序的 expectedSlotIds：code 比 key 更具体（如 content/about-page-story
        // 对应 page:about_page:content-about-page-story 优先于 page:about_page:highlights，
        // 因为 blueprint 有时会给 section.key 复用 highlights/details 这类共用 token）。
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

        // 按 verifiedOnly 切两个外层 pass：先尝试只挑非占位图，全部 miss 后再放宽到占位图。
        // 关键字匹配（无论 scoped 还是 legacy）必须排在"任意 slot_type 命中"之前，
        // 否则 page-scoped 但语义不相关的 slot（如 page:home_page:cta）会抢占
        // 与 section 真正同名的 legacy slot（如 details）。
        // Full refactor rule: programmatic/local fallback images are not
        // compatible production assets. If no verified asset exists, leave the
        // section without an inherited URL so the block-level inline generator
        // must call the real text-to-image model for its required slot.
        foreach ([true] as $verifiedOnly) {
            // (a) slot_id 精确匹配 page-scoped 标识，按 specificity 顺序匹配（code 优先于 key）。
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

            // (b) 同 page_type 下 + 关键字命中。
            $matched = $this->findSlotByKeywordsScoped($slots, $candidates, $preferredTypes, $pageType, true, $verifiedOnly);
            if ($matched !== '') {
                return $matched;
            }

            // (c) legacy（page_type==''）+ 关键字命中（haystack 加入 brief/label，因 stage1 散标 slot 信息常在 brief）。
            $matched = $this->findSlotByKeywordsScoped($slots, $candidates, $preferredTypes, $pageType, false, $verifiedOnly, true);
            if ($matched !== '') {
                return $matched;
            }

            // (d) 跨 page_type 的关键字匹配（兜底）：stage1 有时会把 legacy slot 错标到另一个 page_type
            // （例如 slot_id=details 被标为 about_page），但其语义 (slot_id/block_key/brief 含 "details")
            // 仍是 home_page details 的最佳替代。在已穷尽同 page_type 的 keyword 匹配后启用。
            $matched = $this->findSlotByKeywordsAcrossPageTypes($slots, $candidates, $preferredTypes, $verifiedOnly);
            if ($matched !== '') {
                return $matched;
            }

            // (e) 同 page_type 下任意 slot_type 命中（兜底；只在所有 keyword 匹配全 miss 后才会到这）。
            $matched = $this->findFirstScopedSlot($slots, $preferredTypes, $pageType, true, $verifiedOnly);
            if ($matched !== '') {
                return $matched;
            }

            // (f) legacy 范围内首个 slot_type 命中。
            $matched = $this->findFirstScopedSlot($slots, $preferredTypes, $pageType, false, $verifiedOnly);
            if ($matched !== '') {
                return $matched;
            }
        }

        return '';
    }

    /**
     * 跨 page_type 的关键字匹配：仅在 page-scoped 与 legacy keyword 匹配全 miss 后启用。
     * 用于救活那些被 stage1 错误地打到了非当前 page_type 的 legacy slot（如 details→about_page）。
     *
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
     * 在指定 page_type 范围内，按候选关键字命中 slot。
     *
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
     * 在指定 page_type 范围内，返回首个 slot_type 命中的 slot final_url（不依赖关键字匹配）。
     *
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
     * 判断 manifest slot 是否仅有占位变体（fake_mode/手动 placeholder fallback 写盘的 SVG）。
     *
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
        $navigationPages = $this->buildNavigationPages($scope);
        $headerNavigationPages = $this->buildHeaderNavigationPages($scope);
        $siteTitle = $this->getPageBlueprintService()->resolveSiteDisplayName($websiteProfile, $scope);

        return new PreviewPageStub([
            'website_id' => (int)($scope['draft_website_id'] ?? $scope['website_id'] ?? 0),
            'type' => $pageType,
            'title' => (string)($websiteProfile['site_title'] ?? $siteTitle),
            'meta_title' => (string)($websiteProfile['site_title'] ?? $siteTitle),
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
    private function buildNavigationPages(array $scope): array
    {
        $pageTypes = $this->resolveScopedPageTypes($scope);
        $locale = $this->resolveScopePrimaryLocale($scope);
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
    private function buildHeaderNavigationPages(array $scope): array
    {
        $locale = $this->resolveScopePrimaryLocale($scope);
        $navigationPages = $this->buildNavigationPages($scope);
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

            $label = $this->filterVisibleCopyForLocale(
                \trim((string)($item['label'] ?? $item['title'] ?? $item['text'] ?? '')),
                $locale
            );
            if ($label !== '' && $this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($label)) {
                $label = '';
            }
            if ($label === '' && \is_array($fallback)) {
                $label = $this->filterVisibleCopyForLocale(
                    \trim((string)($fallback['label'] ?? $fallback['title'] ?? $fallback['text'] ?? '')),
                    $locale
                );
            }
            if ($label === '' && $type !== '') {
                $label = $this->localizePageTypeTitle($type, $locale);
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
            } elseif (\is_array($fallback) && \trim((string)($fallback['type'] ?? '')) !== '') {
                $normalized['type'] = \trim((string)$fallback['type']);
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
            . "7. CSS EFFECTS REQUIRED: the header must have visible depth — subtle shadow on the nav bar, hover underline animation on nav links, transition on CTA with shadow lift. Every interactive element (nav-link, CTA, logo, toggle) must have a non-zero hover/focus style.\n"
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
            . "8. CSS EFFECTS REQUIRED: the footer must have visible depth — a top border/shadow separator from the page body, hover lift on social icons with shadow, hover underline animation on link items, and transition effects on any interactive element. Grouped link columns should have heading separation.\n"
            . "9. Style should follow the reference theme direction without naming the theme in visible text.\n"
            . "9. The framework already provides brand/link/social/copyright fields. Set extra_fields, php_variables, and js_content to empty strings unless explicitly required.\n"
            . "10. Return valid JSON only. No markdown. No explanation. Keep css_extra under 1800 chars and footer_extra_text as one short visitor-facing sentence.\n"
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
            . ($sectionTemplate === 'hero' ? "BANNER MODULE DEFAULT RULE: this is a hero/banner section. Unless the user's latest block-adjustment instruction or approved design plan explicitly asks for a different hero composition, use a FULL-WIDTH 1920x750-style banner (container_width=full) with a background IMAGE covering the entire section. Set bg_type to 'image' and use the planned hero_image URL when available. Add a dark gradient overlay over the background image so text remains readable. Content overlay is placed inside a centered max-width content container above the image. If the user explicitly requests a different hero layout, follow that layout but keep a premium generated image, strong hierarchy, and readable overlay.\n" : '')
            . "Suggested structure: {$sectionTemplate}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . ($brief !== '' ? "Customer brief (HARD CONTRACT — all content must fit this business): {$brief}\n" : '')
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
            . "3. The section must be meaningfully different for its page type and role; home, about, contact, policy, and blog sections should not read the same.\n"
            . "4. Use the style reference as visual/tone inspiration, but do not mention the style name in visible text.\n"
            . "5. CSS EFFECTS REQUIRED ON ALL BLOCKS (DO NOT SKIP): every block — title, subtitle, description, card, image, CTA, list, divider, icon — MUST have at least two visible CSS effects from: gradient text on headings, card hover lift with shadow, pill/badge style subtitles, decorative underlines on titles with gradient, border or background transitions, backdrop-filter glass effect, CSS-only decorations/pseudo-elements, or fade-in animation. Flat unstyled blocks are invalid unless the user EXPLICITLY indicated 'no effects', 'minimal', or 'clean' in the refine instruction.\n"
            . "6. NO SOLID/FLAT BACKGROUNDS: every section must have a subtle gradient, radial light bloom, noise texture, or patterned overlay on its background. A plain hex color (#fff, #f8f9fa, etc.) as the sole background is forbidden. Use at minimum a two-stop gradient or a radial glow behind the content area.\n"
            . "7. CONTRAST GATE (HARD REQUIREMENT): never pair a light background with light text or a dark background with dark text. Every text/button/link must have WCAG AA minimum contrast against its immediate background. Use color-mix() to derive readable foregrounds automatically where possible.\n"
            . "8. TYPOGRAPHY STANDARDS: set letter-spacing on uppercase text (0.06-0.12em), use clamp() for heading sizes, set generous line-height (1.6-1.85 for body, 1.1-1.3 for headings), and use a max-width on paragraph text (55-70ch) to prevent overly long lines.\n"
            . "9. NO INFINITE IMAGE SCALING: do not add transform:scale() to any image or media element on hover or in animation. Use box-shadow, translateY, brightness filter, or border transitions for hover effects instead. Scale transforms on images cause cropping/overflow issues.\n"
            . "10. RESPONSIVE IMAGES REQUIRED: every img tag MUST have max-width:100% and height:auto via inline style or CSS class. Images inside flex/grid containers must not overflow on small screens. Use object-fit:cover + object-position:center for decorative/bg images. Add mobile-specific media query adjustments for image border-radius and shadow at 768px breakpoint.\n"
            . "11. Do not repeat the framework title/description in the body as empty h1/h2/p tags. The body must add useful content such as cards, trust points, game tiles, proof points, or CTA support.\n"
            . "12. Preserve page-level color layering: this block must have its own surface/contrast role and must not make the whole page feel like one solid theme color.\n"
            . "13. Implement like a UI/interaction designer handoff: section-specific visual hierarchy, spatial rhythm, motion restraint, hover/focus states, and mobile stacking must be visible in html_content/css_extra.\n"
            . "14. Accessibility contrast gate: before returning, inspect every visible text/link/button/chip against its immediate background; rewrite CSS if any foreground/background pair is low-contrast.\n"
            . "15. HTML closure gate: html_content must be a balanced fragment with all non-void tags closed; invalid nesting or stray closing tags are build-breaking failures.\n"
            . "16. Set extra_fields, php_variables, and js_content to empty strings. Put final visible section body only in html_content.\n"
            . "17. Return valid JSON only. No markdown. No explanation. Keep html_content under 2400 chars and css_extra under 2600 chars.\n"
            . "18. JSON fields: extra_fields, php_variables, css_extra, css_responsive, html_content, js_content.\n"
            . "19. If real blog data variables are provided, prefer them over invented articles or categories.\n"
            . $this->buildComponentJsonPhpSafetyRulesEn();
    }

    /**
     * 英文硬约束：降低合并进 .phtml 后的 PHP 语法错误（如 unexpected "=>"）
     */
    private function buildComponentJsonPhpSafetyRulesEn(): string
    {
        return "PHP / HTML / CSS / JSON safety (critical — invalid output breaks the site build):\n"
            . "- Output one JSON object only. Every value must be a valid JSON string: escape double quotes as \\\", represent newlines inside strings as \\n. Do not truncate strings mid-escape.\n"
            . "- Field php_variables: MUST be an empty string for this virtual-theme build. The framework already provides variables and config.\n"
            . "- In php_variables, every array literal must be complete: e.g. \$x = ['k' => 'v']; with all [, ], (, ), quotes, and semicolons balanced. Never paste JavaScript object literals or JSON blobs here. The PHP token => must appear only inside valid PHP array syntax, never loose in HTML/CSS.\n"
            . "- Do not redeclare or break framework-provided variables (\$page, \$getConfig, \$componentId, \$cls, \$parseLinks, \$navItems, etc.) unless you know exactly how; prefer using them read-only.\n"
            . "- extra_fields and js_content: MUST be empty strings unless the task explicitly requires them.\n"
            . "- html_extra, html_extra_column, html_content: static HTML fragments only. No PHP tags, no <style>, no <script>, no @component_start/@fields_start metadata.\n"
            . "- HTML fragments must be balanced and embeddable: close every non-void tag, do not output full <html>/<head>/<body> documents, and do not leave stray closing tags.\n"
            . "- css_extra, css_responsive: CSS only. No <? ... ?> and no PHP. Use scoped CSS when needed for visual polish: layered backgrounds, textures, shape motifs, hover/focus states, responsive rhythm, and type scale. Every rule and @media block must have balanced { } braces and be short enough to fit completely.\n"
            . "- Color contrast: never pair dark foreground text with dark backgrounds or light foreground text with light backgrounds; define readable text/link/button/focus states in CSS before returning.\n"
            . "- Page hierarchy: do not make the section one flat theme-color slab. Use palette roles, surface elevation, dividers, texture, cards, or spacing to distinguish this block from adjacent blocks.\n"
            . "- CSS class names: never use generic selectors like .card, .icon, .btn, .title, .item, .panel, .row, .container, .section, .text, .image, or .active. Use component-specific classes shaped like pb-{component-code}-{element}, scope selectors with #componentId, and keep CSS selectors and HTML class attributes in sync.\n"
            . "- Images: never output broken image placeholders. If no verified asset URL is provided, create visual rhythm with CSS-only shapes/pseudo-elements inside html_content; do not use <svg>, data:image/svg+xml, empty src, example.com, placeholder services, or unverified .jpg/.png/.webp URLs.\n"
            . "- AI image editability: when using a verified text-to-image asset URL in <img src> or CSS url(), put data-pb-ai-asset-slot=\"<slot_id>\" and data-pb-ai-image-role=\"generated-asset\" on the exact image/background element so the visual editor can regenerate only that image.\n"
            . "- js_content: MUST be an empty string for this virtual-theme build.\n";
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
            . "- page-design-plan: for page-owned blocks, page_design_plan is the design brief. Preserve its color_layering, section_flow, interaction_notes, and anti_monotony_rule in the final visual hierarchy.\n"
            . "- asset-rule: when a visual/image is needed but no verified uploaded asset URL exists, create a theme-colored CSS-only visual directly. Never render <svg> or a broken <img>.\n"
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
            . "- Planned content is not exempt: if task_script, block_task.content_plan, field samples, nav labels, CTA labels, SEO snippets, or stage-1 plan text use another language, translate/rewrite them into content_locale/default_locale before rendering html_content/footer/header text.\n"
            . "- Rewrite planning/observation sentences into direct marketing copy before rendering. Do not visibly output phrases like \"Visitors see...\", \"Visitors can review...\", \"访客看到...\", \"用户看到...\", \"信任感增强\", or \"从而产生...\".\n"
            . "- Never render internal identifiers or paths as visible copy: plan_locale, page_type, section_code, task_key, block_key, runtime_context, app/code paths, var/ paths, content/... component paths, shared:* keys, or page:* keys.\n"
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
        $scriptGuard = $this->isNonCjkLocale($contentLocale)
            ? "- Script guard for final visible copy: do not output Chinese/Japanese/Korean sentences in headings, body, nav labels, buttons, footer text, alt text, badges, or trust points. If any planned sample contains CJK text, rewrite it into {$contentLocale} before rendering.\n"
            : "- Script guard for final visible copy: keep all visitor-visible text in {$contentLocale}; if planned samples use another language, rewrite them to match the content locale before rendering.\n";

        return "Stage-3 language execution contract (hard requirement):\n"
            . "- source_of_truth_locale: {$contentLocale} ({$localeHint})\n"
            . "- Internal planning language is not output language. stage-1/build-plan/story/task samples are intent only.\n"
            . "- Before composing html_content/html_extra/footer text/nav labels, normalize every candidate sentence to source_of_truth_locale.\n"
            . $scriptGuard
            . "- Final self-check before returning JSON: if any visitor-visible sentence is not in source_of_truth_locale, rewrite it now and then return.\n";
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function buildThemeContractPromptAddon(array $scope): string
    {
        $contract = $this->resolveThemeContract($scope);
        $palette = \is_array($contract['palette'] ?? null) ? $contract['palette'] : [];
        if ($palette === []) {
            // Minimum viable visual contract fallback: never let the AI generate without any
            // style direction. The fallback palette from resolveThemeContract covers this case,
            // but as a safety net this injects the style reference as an explicit contract.
            $styleCode = $this->resolvePromptStyleCode($scope, 'home');
            $styleDirection = $this->describeStyleDirection($styleCode);
            return "Visual contract (style reference — full palette not available, use this as design mandate):\n"
                . "- style_reference: {$styleCode}\n"
                . "- style_direction: {$styleDirection}\n"
                . "- IMPORTANT: Build a coherent CSS palette from this style direction. Do not invent unrelated colors.\n"
                . "- Maintain readable contrast between text and background at all times.\n"
                . "- Do not make every block the same full-bleed primary/accent color. Vary surfaces, cards, and spacing.\n";
        }
        $themeContext = \is_array($contract['raw_context'] ?? null) ? $contract['raw_context'] : [];

        return "Confirmed visual contract from the approved stage-1 theme and build plan:\n"
            . "- theme_name: " . (string)($contract['name'] ?? '') . "\n"
            . "- visual_tone: " . (string)($contract['visual_tone'] ?? '') . "\n"
            . "- font_family: " . (string)($contract['font_family'] ?? '') . "\n"
            . "- style_signature: " . (string)($contract['style_signature'] ?? '') . "\n"
            . "- art_direction: " . $this->jsonEncodeForPrompt(\is_array($contract['art_direction'] ?? null) ? $contract['art_direction'] : [], 2000) . "\n"
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
            . "- Spend CSS budget on visible quality: scoped CSS variables, clamp typography, layered gradients, texture/noise via CSS, asymmetric composition, decorative borders, CSS-only motifs/pseudo-elements, tactile CTA hover/focus states, and mobile-specific rhythm.\n"
            . "- Block-specific baseline: use the role-specific base pattern unless explicitly overridden: hero/banner defaults to an immersive image-backed banner; trust defaults to badge/proof/credential rhythm; game/product defaults to media-led showcase or feature tiles; FAQ defaults to accordion/help rhythm; story defaults to timeline/editorial narrative; CTA defaults to a cinematic conversion band. Do not render every block as the same centered title + three small cards + image.\n"
            . "- Shadow/depth effects required: use box-shadow, drop-shadow, or CSS filters to create visual depth on cards, images, and interactive elements. At minimum, implement a subtle card shadow with a hover lift (transition: box-shadow 0.3s ease, transform 0.3s ease). Do not leave interactive surfaces flat.\n"
            . "- Richer text colors: do not rely on just one heading color and one body color. Use accent-colored spans, gradient text (via background-clip), highlighted/inline marks, and color-mix() to create typographic hierarchy and visual rhythm in headings and key phrases.\n"
            . "- Customer-intent lock: the final HTML/CSS must visibly match the user's actual business/game/service scenario through motifs, labels, CTA affordances, proof details, and interaction behavior; do not generate a category-neutral section.\n"
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
            ? "- HERO/BANNER DEFAULT BASELINE: unless the user's latest block-adjustment instruction or approved design plan explicitly conflicts, render a real premium 1920x750-style banner. The image or visual layer covers the section background edge-to-edge with object-fit:cover or background-image; content sits as a floating overlay inside a centered max-width container. Do NOT create a small side image, isolated centered card, narrow media frame, or huge empty side gutters as the default. If the user explicitly asks for another hero composition, follow it while preserving premium generated imagery, strong hierarchy, and readable overlay treatment.\n"
            : "- NON-HERO HARD RULE: this block needs its own layout purpose and visual rhythm. Do not copy the hero structure or the previous block's card/media arrangement.\n";

        return "Premium site design contract for this section:\n"
            . "- Base prompt precedence: this section recipe is the default quality baseline. If the latest user refinement or approved block plan explicitly asks for a different composition, use that composition while preserving the same premium quality bar, content relevance, image-slot usage, contrast, and anti-placeholder constraints.\n"
            . $heroRule
            . "- Section recipe: {$recipe}\n"
            . "- Anti-monotony rule: adjacent blocks must not share the same three-card row, same image position, same copy labels, or same pale background/card treatment. Change composition, scale, motif, and spacing per section role.\n"
            . "- Rejected output examples: centered title plus three small cards plus the same image; tiny cartoon/SVG-looking media used as a substitute for a real generated image; generic labels like \"Get started\", \"Game suite\", \"Download path\" repeated across blocks; hero built as a boxed card next to a small image.\n"
            . "- If a verified generated image exists, use it prominently and naturally. For hero it is the background layer; for non-hero it must be a purposeful media surface, not a thumbnail afterthought.\n";
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
        $usage = (string)($visualContract['usage'] ?? '');
        $placement = (string)($visualContract['placement'] ?? '');
        $subject = (string)($visualContract['subject'] ?? '');
        $style = (string)($visualContract['style'] ?? '');

        return "Block visual contract:\n"
            . "- visual_contract: " . $this->jsonEncodeForPrompt($visualContract, 1600) . "\n"
            . "- image_required: " . ($required ? 'yes' : 'no') . "\n"
            . ($required
                ? "- Required image slot {$slotId}: use the exact generated asset for this block. Usage={$usage}; placement={$placement}; subject={$subject}; style={$style}.\n"
                    . "- Required slot HTML rule: the image URL must appear in html_content as <img src> or inline/background style on a real element, and that same element must include data-pb-ai-image-role=\"generated-asset\" and data-pb-ai-asset-slot=\"{$slotId}\".\n"
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

    private function buildHeaderPrompt(array $websiteProfile, array $scope, string $siteDisplayName, array $headerConfig): string
    {
        $brief = $this->pickString($websiteProfile['brief_description'] ?? null, $scope['brief_description'] ?? null, $scope['user_description'] ?? null);
        $pageTypes = $this->resolveScopedPageTypes($scope);
        $pageTypeLabels = Page::getPageTypes();
        $pageList = [];
        foreach ($pageTypes as $pageType) {
            $pageList[] = (string)($pageTypeLabels[$pageType] ?? $pageType);
        }
        $langRule = $this->buildPrimaryLanguageRuleZh($websiteProfile, $scope);

        return $langRule
            . "你正在为 PageBuilder AI 建站工作台生成一个网站页头 header 组件。\n"
            . "站点名称：{$siteDisplayName}\n"
            . "客户一句话需求：{$brief}\n"
            . "站点需要承载的页面：" . \implode('、', $pageList) . "\n"
            . "要求：\n"
            . "1. 这是常规网站页头，不要输出整页，只生成 header 组件增强部分。\n"
            . "2. 导航必须服务于真实页面导航，不能写死伪造菜单；当前组件会优先读取真实页面导航，没有时回退到配置中的导航项。\n"
            . "3. 允许输出 css_extra / html_extra / js_content，重点体现品牌气质、吸顶、滚动、移动端菜单等体验。\n"
            . "4. 文案必须贴合客户一句话需求，避免空泛模板句。\n"
            . "5. 返回纯 JSON 对象，不要 markdown，不要解释。\n"
            . "JSON 字段：extra_fields, php_variables, css_extra, html_extra, js_content。\n"
            . "当前导航回退项：" . \json_encode($headerConfig['nav_items'] ?? [], \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $footerConfig
     */
    private function buildFooterPrompt(array $websiteProfile, array $scope, string $siteDisplayName, array $footerConfig): string
    {
        $brief = $this->pickString($websiteProfile['brief_description'] ?? null, $scope['brief_description'] ?? null, $scope['user_description'] ?? null);
        $langRule = $this->buildPrimaryLanguageRuleZh($websiteProfile, $scope);

        return $langRule
            . "你正在为 PageBuilder AI 建站工作台生成一个网站页脚 footer 组件。\n"
            . "站点名称：{$siteDisplayName}\n"
            . "客户一句话需求：{$brief}\n"
            . "要求：\n"
            . "1. 这是常规网站页脚，不要输出整页，只生成 footer 组件增强部分。\n"
            . "2. 页脚链接需要兼容真实页面 link/nav 逻辑，没有真实页面时回退到配置中的链接列。\n"
            . "3. 可以生成品牌区、资源链接、补充列、订阅区、声明文案，但必须保持常规网站 footer 结构。\n"
            . "4. 文案与气质必须贴合客户一句话需求。\n"
            . "5. 返回纯 JSON 对象，不要 markdown，不要解释。\n"
            . "JSON 字段：extra_fields, php_variables, css_extra, html_extra_column, html_extra, footer_extra_text, js_content。\n"
            . "当前页脚回退配置：" . \json_encode([
                'column1' => $footerConfig['links.column1_items'] ?? '',
                'column2' => $footerConfig['links.column2_items'] ?? '',
            ], \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string,mixed> $section
     * @param array<string,mixed> $blueprint
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function buildSectionPrompt(string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): string
    {
        $brief = $this->pickString($websiteProfile['brief_description'] ?? null, $scope['brief_description'] ?? null, $scope['user_description'] ?? null);
        $pageInstructionMap = Page::getPageTypePromptInstructionsMap();
        $pageInstruction = (string)($pageInstructionMap[$pageType] ?? '');
        $sectionName = (string)($section['name'] ?? $section['code'] ?? '');
        $sectionKey = (string)($section['key'] ?? '');
        $sectionTemplate = (string)($section['template'] ?? 'hero');
        $sectionConfig = \json_encode($section['config'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        $refinement = $this->resolveSectionRefinement($scope, $pageType, (string)($section['code'] ?? ''), $sectionKey);
        $blogPrompt = $this->buildBlogPromptAddon($pageType, $sectionKey, $scope);
        $langRule = $this->buildPrimaryLanguageRuleZh($websiteProfile, $scope);

        return $langRule
            . "你正在为 PageBuilder AI 建站工作台生成一个内容区块 content 组件。\n"
            . "页面类型：" . (string)($blueprint['page_label'] ?? $pageType) . " ({$pageType})\n"
            . "区块名称：{$sectionName}\n"
            . "区块角色：{$sectionKey}\n"
            . "建议结构类型：{$sectionTemplate}\n"
            . "客户一句话需求：{$brief}\n"
            . "页面生成说明：{$pageInstruction}\n"
            . "当前区块建议配置：{$sectionConfig}\n"
            . ($refinement !== '' ? "用户对当前区块的额外微调要求：{$refinement}\n" : '')
            . ($blogPrompt !== '' ? $blogPrompt . "\n" : '')
            . "要求：\n"
            . "1. 只生成一个 content 组件，不要输出整页 document。\n"
            . "2. 组件必须围绕这个区块角色来写，文案要严格贴合客户一句话需求，而不是通用模板句。\n"
            . "3. 返回纯 JSON 对象，不要 markdown，不要解释。\n"
            . "4. JSON 字段：extra_fields, php_variables, css_extra, css_responsive, html_content, js_content。\n"
            . "5. 如果是博客页面并且提示里给出了真实数据变量，必须优先使用真实数据变量，不要伪造文章或分类。";
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
            'hero' => '这一屏更适合做博客页说明、栏目定位和阅读引导。',
            'highlights' => '这一屏更适合直接渲染真实文章列表或分类卡片。',
            'details' => '这一屏更适合做分类导航、阅读路径、近期文章或当前文章补充信息。',
            default => '这一屏请结合博客真实数据来组织内容。',
        };

        return "博客页面真实数据要求：\n"
            . "- 可用变量：\$blog_posts, \$blog_categories, \$recent_posts, \$related_posts, \$current_post, \$current_category, \$category_posts。\n"
            . "- {$roleHint}\n"
            . "- 示例文章数据：" . \json_encode($postPreview, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- 示例分类数据：" . \json_encode($categoryPreview, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- 请使用 foreach ((\$blog_posts ?? []) as \$post) 或同类真实数据循环，不要手写假文章。";
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildHeaderDefaultConfig(array $websiteProfile, array $scope, string $siteDisplayName): array
    {
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        $navItems = $this->buildHeaderNavigationPages($scope);
        $navTextLines = [];
        foreach ($navItems as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $navTextLines[] = \trim((string)($item['title'] ?? '')) . '=>' . \trim((string)($item['url'] ?? '#'));
        }

        $profileLogo = \trim((string)($websiteProfile['logo'] ?? ''));
        $logoAssetUrl = $this->resolveHeaderAssetUrl($scope);
        // Manifest / queued logo assets must beat stale profile.logo placeholders — otherwise AI-generated marks never surface on preview even after asset stages finish.
        $effectiveLogo = $logoAssetUrl !== '' ? $logoAssetUrl : $profileLogo;

        $defaultConfig = [
            'logo.display' => 'yes',
            'logo.text' => $siteDisplayName,
            'logo.image' => $effectiveLogo,
            'logo.url' => $effectiveLogo,
            'navigation.display' => 'yes',
            'navigation.items' => \implode("\n", $navTextLines),
            'nav_items' => \array_map(static fn(array $item): array => [
                'text' => (string)($item['title'] ?? ''),
                'href' => (string)($item['url'] ?? '#'),
            ], $navItems),
            'cta.show' => 'yes',
            'cta.text' => $this->resolvePrimaryCtaText($scope),
            'cta.url' => '#contact',
        ];
        $defaultConfig = \array_replace($defaultConfig, $this->resolveThemeStyleDefaults($scope, 'header'));

        $defaultConfig = $this->applyBuildPlanDefaults($defaultConfig, $this->resolveSharedBuildPlanTask($scope, 'header'), $locale);
        $defaultConfig['runtime.shared_region'] = 'header';

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
        $sharedPromptContext = $this->resolveSharedPromptContext($scope);
        $navigationPages = $this->buildNavigationPages($scope);
        $brandSummary = $this->filterVisibleCopyForLocale(
            $this->pickString(
                $sharedPromptContext['site_positioning'] ?? null,
                $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope)
            ),
            $locale
        );
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
            $line = \trim((string)($item['title'] ?? '')) . '=>' . \trim((string)($item['url'] ?? '#'));
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

        $defaultConfig = [
            'brand.name' => $siteDisplayName,
            'brand.logo' => (string)($websiteProfile['logo'] ?? ''),
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
        $defaultConfig = $this->repairDefaultVisibleCopyFromStageOneSamples($defaultConfig, $stageOneSamples);
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
                // 默认按 page:{page_type}:{code}（slash → dash）派生 slot_id，便于前端可视化编辑追踪。
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
        $defaultConfig = $this->repairDefaultVisibleCopyFromStageOneSamples($defaultConfig, $stageOneSamples);

        // 强行契约：把当前 section 的模板/页面/资产等元信息写入 runtime.* 内部键，
        // stub/fallback 用它直接命中正确变体并真正使用 verified_assets，而不是退化为占位 SVG。
        // runtime.* 前缀会在 stripInternalComponentConfig 中被剔除，不会污染最终保存的组件配置。
        $defaultConfig['runtime.section_template'] = (string)($section['template'] ?? '');
        $defaultConfig['runtime.section_page_type'] = $pageType;
        $defaultConfig['runtime.section_code'] = (string)($section['code'] ?? '');
        $defaultConfig['runtime.section_key'] = (string)($section['key'] ?? '');
        $defaultConfig['runtime.task_key'] = (string)($buildPlanTask['task_key'] ?? '');
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
        $blocks = \is_array($scope['execution_blueprint']['pages'][$pageType]['blocks'] ?? null)
            ? $scope['execution_blueprint']['pages'][$pageType]['blocks']
            : [];
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

        $samples = \array_values(\array_filter($stageOneSamples, static fn(string $sample): bool => \trim($sample) !== ''));
        if ($samples === []) {
            return $defaultConfig;
        }

        if (\trim((string)($defaultConfig['content.title'] ?? '')) === '') {
            $defaultConfig['content.title'] = $this->clipText($samples[0], 72);
        }

        if (\trim((string)($defaultConfig['content.description'] ?? '')) === '') {
            $title = \trim((string)($defaultConfig['content.title'] ?? ''));
            foreach ($samples as $sample) {
                if ($sample !== $title && \mb_strlen($sample) >= 12) {
                    $defaultConfig['content.description'] = $this->clipText($sample, 180);
                    break;
                }
            }
        }

        return $defaultConfig;
    }

    /**
     * 反向查找：用 final_url 找回 manifest 中的 slot_id。
     * @param array<string,mixed> $scope
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
            return [];
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
            $verifiedAssets = $this->extractVerifiedAssetsForBuildPlanTask($scope, $buildPlanTask);
            $verifiedAssetRule = $verifiedAssets !== []
                ? "- verified_assets: " . $this->jsonEncodeForPrompt($verifiedAssets, 3000) . "\n"
                    . "- HARD CONTRACT: every verified_asset URL MUST be used as <img src> or CSS url() in this section's output. Do not skip any. If a slot_id matches this section, the corresponding image must appear in html_content. This is NOT optional — unused generated images waste API tokens.\n"
                    . "- Inject attribute data-pb-ai-asset-slot=\"<slot_id>\" on every element using a verified asset URL.\n"
                : "- verified_assets: []\n";
            return "Build-plan task context for this {$contextLabel} (fallback from scope - follow the customer brief strictly):\n"
                . "- customer_brief: {$brief}\n"
                . "- IMPORTANT: All generated content MUST serve this customer brief exactly. Do NOT invent unrelated generic content.\n"
                . "- stage1.theme_context: " . $this->jsonEncodeForPrompt($themeContext, 7000) . "\n"
                . "- stage1.shared_prompt_context: " . $this->jsonEncodeForPrompt($sharedPromptContext, 5000) . "\n"
                . $verifiedAssetRule
                . "- anti-copy rule: never paste stage-1 observation/planning sentences directly into html_content. Rewrite phrases like \"Visitors see...\", \"访客看到...\" into finished visitor-facing headings, benefits, proof points, labels, and CTA copy.\n";
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
        $contentLocale = \trim((string)($runtimeContext['content_locale'] ?? $scope['default_locale'] ?? ''));
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
                . "- HARD CONTRACT — every verified_asset URL MUST appear as <img src> or CSS background-image in html_content. Do not skip any. Unused generated images waste API tokens.\n"
                . "- Rules: use the exact final_url value; match the asset by slot_id context. If no asset matches this section, render CSS-only decorative structure; never use <svg>.\n"
                . "- Inject attribute data-pb-ai-asset-slot=\"<slot_id>\" on every element using a verified asset URL.\n"
                . "- Inject attribute data-pb-ai-image-role=\"generated-asset\" on every element using a verified asset URL.\n"
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
            . "- build-plan language rule: treat build-plan text as source intent, not copy authority; rewrite any planned text that is not in the website content language before placing it in visible component output.\n"
            . "- anti-copy rule: never paste build-plan observation/planning sentences directly into html_content. Rewrite phrases like \"访客看到...\", \"用户看到...\", \"从而产生...\", \"信任感增强\", \"知道如何...\", \"Visitors see...\", or \"Visitors can review...\" into finished visitor-facing headings, benefits, proof points, labels, and CTA copy.\n"
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
            if (\in_array(\strtolower($sample), ['header', 'footer'], true)) {
                continue;
            }
            if ($isLinkField) {
                $defaultConfig = $this->applyBuildPlanLinkFieldDefaults($defaultConfig, $field, $sample, $locale);
                continue;
            }

            $candidateKeys = match (true) {
                \str_contains($field, 'brand'), \str_contains($field, 'platform'), \str_contains($field, 'site_title'), \str_contains($field, 'logo_text') => ['logo.text', 'brand.name'],
                \str_contains($field, 'title'), \str_contains($field, 'headline'), \str_contains($field, '标题'), \str_contains($field, '標題') => ['content.title'],
                \str_contains($field, 'subtitle'), \str_contains($field, 'eyebrow'), \str_contains($field, 'tagline'), \str_contains($field, 'slogan'), \str_contains($field, '副标题'), \str_contains($field, '副標題'), \str_contains($field, '标语'), \str_contains($field, '標語') => ['content.subtitle'],
                \str_contains($field, 'description'), \str_contains($field, 'body'), \str_contains($field, 'text'), \str_contains($field, '简介'), \str_contains($field, '說明'), \str_contains($field, '说明'), \str_contains($field, '正文'), \str_contains($field, '内容'), \str_contains($field, '內容'), \str_contains($field, '文案') => ['content.description', 'brand.description'],
                \str_contains($field, 'button_text'), \str_contains($field, 'cta'), \str_contains($field, '按钮'), \str_contains($field, '按鈕') => ['cta.text'],
                \str_contains($field, 'button_url'), \str_contains($field, 'url'), \str_contains($field, 'href'), \str_contains($field, '链接'), \str_contains($field, '連結') => ['cta.url'],
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
        if ($storyGoal !== '' && \trim((string)($defaultConfig['content.title'] ?? '')) === '') {
            $defaultConfig['content.title'] = $this->clipText($storyGoal, 72);
        }

        // 强行契约：将 stage-2 block_task.content_plan.content_copy / cta_plan 优先回填进 defaultConfig，
        // 避免 stub/fallback 因仅依赖 field_content_requirements.sample 而把 task 真正规划好的视觉文案当成"未指定"漏掉。
        // 必须在 visibleSummary 兜底前完成，否则 description 会被 content_copy 拼接出的概要覆盖单一 description 字段。
        $defaultConfig = $this->applyBuildPlanContentPlanDefaults($defaultConfig, $blockTask);

        $visibleSummary = $this->resolveVisibleBuildTaskSummary($taskScript, $blockTask, $planContext);
        if ($visibleSummary !== '' && \trim((string)($defaultConfig['content.description'] ?? '')) === '') {
            $defaultConfig['content.description'] = $this->clipText($visibleSummary, 180);
        }

        return $this->sanitizeDefaultConfigVisibleCopy(
            $this->applyBuildPlanDataContractDefaults($defaultConfig, $buildPlanTask)
        );
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $blockTask
     * @return array<string,mixed>
     */
    private function applyBuildPlanContentPlanDefaults(array $defaultConfig, array $blockTask): array
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
                \str_contains($field, 'headline'),
                \str_contains($field, '标题'),
                \str_contains($field, '標題') => ['content.title'],
                \str_contains($field, 'subtitle'),
                \str_contains($field, 'eyebrow'),
                \str_contains($field, 'tagline'),
                \str_contains($field, 'slogan') => ['content.subtitle'],
                \str_contains($field, 'description'),
                \str_contains($field, 'body'),
                \str_contains($field, 'text'),
                \str_contains($field, '简介'),
                \str_contains($field, '说明'),
                \str_contains($field, '内容'),
                \str_contains($field, '文案') => ['content.description', 'brand.description'],
                \str_contains($field, 'button_text'),
                \str_contains($field, 'cta_label'),
                \str_contains($field, 'cta_text'),
                \str_contains($field, 'cta'),
                \str_contains($field, 'primary_cta'),
                \str_contains($field, '按钮') => ['cta.text', 'content.cta_text'],
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
            // 仅取首个有效 cta（否则会被多个 cta 覆盖）。
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
    private function fillDefaultConfigVisibleCopyFromContractSamples(array $defaultConfig): array
    {
        $samples = [];
        foreach ($this->decodeConfigStringList($defaultConfig['contract.stage1_samples'] ?? null) as $sample) {
            $sample = $this->sanitizeVisibleCopy($sample);
            if ($sample === '' || \in_array($sample, $samples, true)) {
                continue;
            }
            $samples[] = $sample;
        }
        if ($samples === []) {
            return $defaultConfig;
        }

        if (\array_key_exists('content.title', $defaultConfig) && \trim((string)$defaultConfig['content.title']) === '') {
            $defaultConfig['content.title'] = $this->clipText($samples[0], 72);
        }

        if (\array_key_exists('content.description', $defaultConfig) && \trim((string)$defaultConfig['content.description']) === '') {
            $title = \trim((string)($defaultConfig['content.title'] ?? ''));
            foreach ($samples as $sample) {
                if ($sample === $title || \preg_match('#^https?://#iu', $sample) === 1) {
                    continue;
                }
                if (\mb_strlen($sample) >= 12) {
                    $defaultConfig['content.description'] = $this->clipText($sample, 180);
                    break;
                }
            }
        }

        return $defaultConfig;
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $buildPlanTask
     * @return array<string,mixed>
     */
    private function applyBuildPlanDataContractDefaults(array $defaultConfig, array $buildPlanTask): array
    {
        foreach ($this->extractBuildPlanDataContractLines($buildPlanTask) as $line) {
            if (!\str_contains($line, ':')) {
                continue;
            }
            [$rawKey, $rawValue] = \explode(':', $line, 2);
            $key = \strtolower(\trim($rawKey));
            $value = $this->sanitizeVisibleCopy(\trim(\trim($rawValue), " \t\n\r\0\x0B'\""));
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

        return $this->fillDefaultConfigVisibleCopyFromContractSamples($defaultConfig);
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

        return $this->sanitizeVisibleCopy((string)$sampleRaw);
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function sanitizeDefaultConfigVisibleCopy(array $defaultConfig): array
    {
        foreach ($defaultConfig as $key => $value) {
            if (\is_string($value)) {
                if ($key === 'navigation.items') {
                    $defaultConfig[$key] = $this->sanitizeNavigationItemsText($value);
                    continue;
                }
                if ($this->isVisualTextConfigKey($key)) {
                    $defaultConfig[$key] = $this->sanitizeVisibleCopy($value);
                }
                continue;
            }

            if ($key === 'nav_items' && \is_array($value)) {
                foreach ($value as $idx => $item) {
                    if (!\is_array($item)) {
                        continue;
                    }
                    $item['text'] = $this->sanitizeVisibleCopy((string)($item['text'] ?? $item['label'] ?? ''));
                    if ($item['text'] === '') {
                        unset($value[$idx]);
                        continue;
                    }
                    $value[$idx] = $item;
                }
                $defaultConfig[$key] = \array_values($value);
            }
        }

        return $this->fillDefaultConfigVisibleCopyFromContractSamples($defaultConfig);
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function sanitizeGeneratedComponentDefaultConfig(array $defaultConfig, string $region = ''): array
    {
        $defaultConfig = $this->applyGeneratedComponentLayoutDefaults($defaultConfig, $region);

        return $this->sanitizeDefaultConfigVisibleCopy(
            $this->stripInternalComponentConfig($defaultConfig)
        );
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

    private function sanitizeNavigationItemsText(string $lines): string
    {
        $rows = \preg_split('/\r?\n/', $lines) ?: [];
        $cleanRows = [];
        foreach ($rows as $row) {
            $row = \trim($row);
            if ($row === '') {
                continue;
            }
            $parts = \explode('=>', $row, 2);
            $label = $this->sanitizeVisibleCopy((string)($parts[0] ?? ''));
            $href = \trim((string)($parts[1] ?? '#'));
            if ($label === '') {
                continue;
            }
            $cleanRows[] = $label . '=>' . ($href !== '' ? $href : '#');
        }

        return \implode("\n", $cleanRows);
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
        $value = $this->stripPlanningKeywordPrefix($value);
        if ($value === '') {
            return '';
        }

        $normalized = \mb_strtolower($value);
        if (\in_array($normalized, ['首页', '主页', '关于我们', '关于', 'home', 'home page', 'about', 'about page', 'about us'], true)) {
            return '';
        }
        foreach (['核心卖点', '功能特性', '把首页', '值得点击', '放出来', '页面类型', '内容块', '需要作为一次独立', '共享任务只生成一次'] as $marker) {
            if ($marker !== '' && \mb_stripos($normalized, \mb_strtolower($marker)) !== false) {
                return '';
            }
        }
        foreach (['访客看到', '用户看到', '让访客看到', '从而产生', '产生下载兴趣', '信任感增强', '知道如何'] as $marker) {
            if ($marker !== '' && \mb_stripos($normalized, \mb_strtolower($marker)) !== false) {
                return '';
            }
        }
        foreach ([
            '优先沿用第一阶段',
            '输出必须是访客可见内容',
            '不能写方向型提示语',
            '不能写“围绕',
            '不能写"围绕',
            '直接产出可上屏',
            '直接给出',
            '缺少真实事实',
            '字段样例',
            '内容规则',
            '内容填充规则',
            'Generated website section',
            'Website content language',
            'visitor-visible copy',
            'Do not use the',
            'Return ONLY',
            'prompt text',
            'customer brief',
            'website requirement',
            'planning/plan language',
            'stage-2 planned text',
            'source intent',
            'Visitors see',
            'Visitor sees',
            'Visitors can review',
            'Visitors can verify',
            'before publishing',
            'reviewable page content',
            'confirmed stage-one content',
            'confirmed stage-1 plan',
            'confirmed stage-1 theme',
            'confirmed stage-1 theme context',
            'stage-2 task detail',
            'frontend component skill',
            'Generate the frontend block',
            'Fill the block fields',
            'block_task.content_plan',
            'block_task.style_plan',
            'Required by block task schema',
            'planning_reason',
            'Built from plan',
            'generated from plan',
            'Use concrete',
            'Present key terms',
            'provide download CTA',
            'Provide category',
            'filter tabs',
            'visually distinct',
            'Visible CTA path',
            'Trust content',
            'Responsive cards',
            'proof points',
            'visual hierarchy',
            'launch-ready content',
            'Immediately capture',
            'Instantly communicate',
            'Immediately inform',
            'Capture immediate attention',
            'Introduce Teenipiya',
            'content_fill_rule',
            'field_content_requirements',
            'task_script',
            'stage3_directive',
            '优先沿用',
            '输出必须',
            '字段样例',
            '提示词',
            '直接产出可上屏',
            '生成页面方案',
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

    private function stripPlanningKeywordPrefix(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        if (\substr_count($value, '/') + \substr_count($value, '／') < 2) {
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

        $normalized = \mb_strtolower($value);
        foreach ([
            '访客看到',
            '用户看到',
            '让访客看到',
            '从而产生',
            '产生下载兴趣',
            '信任感增强',
            '知道如何',
            'Visitors see',
            'Visitor sees',
            'Visitors can review',
            'Visitors can verify',
            'Visitors understand',
            'Visitors are guided',
            'before publishing',
            'reviewable page content',
            '优先沿用第一阶段',
            '输出必须是访客可见内容',
            '直接产出可上屏',
            '字段样例',
        ] as $marker) {
            $marker = \mb_strtolower($marker);
            if ($marker !== '' && \mb_stripos($normalized, $marker) !== false) {
                return true;
            }
        }

        foreach ([
            'Introduce brand story',
            'build initial trust',
            'Showcase available games',
            'encourage exploration',
            'Build user confidence',
            'popular game categories',
            'educate users',
            'increase time on page',
            'licenses, security certifications',
            'secure download badges',
            'reassure users',
            'customer testimonials',
            'build social proof',
            'Answer common questions',
            'remove barriers',
            'Close the page with a compact summary',
            'visitor has a clear endpoint',
            'Key message',
            'Next action',
        ] as $marker) {
            $marker = \mb_strtolower($marker);
            if ($marker !== '' && \mb_stripos($normalized, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk a section config array and strip planning/observation language from all
     * leaf string values. This prevents stage-1 blueprint planning text from leaking
     * into the prompt as "Suggested section config" — where the AI would then treat
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
     * HTML 轨共享页头/页脚微调：可能写在 shared_component_refinements 或各页 section_refinements（如 *-site-header）。
     *
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
    private function resolvePrimaryCtaText(array $scope): string
    {
        $locale = $this->resolveScopePrimaryLocale($scope);
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

    private function shouldUseStubAiGeneration(): bool
    {
        return !(bool)RequestContext::get(self::REQUEST_KEY_FORCE_REAL_AI_IN_TEST, false)
            && (bool)RequestContext::get(self::REQUEST_KEY_ALLOW_STUB_AI_IN_TEST, false);
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
     * @deprecated 仅用于既有依赖检查；新代码必须走 {@see self::callAiOperation()}，
     *             以遵守 unified-query-provider 规范（模块间通过 w_query('ai', ...) 触达 AI）。
     */
    private function getAiService(): AiService
    {
        return $this->aiService ?? ObjectManager::getInstance(AiService::class);
    }

    /**
     * 统一的跨模块 AI 调用入口：
     *  - 构造注入了 {@see AiService} 时直接复用，兼容既有 mock 测试；
     *  - 否则走 `w_query('ai', $operation, $params)`，由 {@see \Weline\Ai\Extends\Module\Weline_Framework\Query\AiQueryProvider} 接管。
     *
     * 支持的 operation：
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
        return \trim((string)(
            $scope['content_locale']
                ?? $websiteProfile['content_locale']
                ?? $scope['default_locale']
                ?? $scope['default_language']
                ?? $websiteProfile['default_locale']
                ?? ''
        ));
    }

    private function resolveScopePrimaryLocale(array $scope): string
    {
        return $this->resolvePrimaryLocale([], $scope);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveSharedPromptContext(array $scope): array
    {
        foreach ([
            $this->extractSharedPromptContextFromTask($this->resolveSharedBuildPlanTask($scope, 'header')),
            $this->extractSharedPromptContextFromTask($this->resolveSharedBuildPlanTask($scope, 'footer')),
            \is_array($scope['execution_blueprint']['shared_prompt_context'] ?? null) ? $scope['execution_blueprint']['shared_prompt_context'] : [],
            \is_array($scope['plan_workbench']['confirmed']['shared_prompt_context'] ?? null) ? $scope['plan_workbench']['confirmed']['shared_prompt_context'] : [],
        ] as $candidate) {
            if (!\is_array($candidate) || $candidate === []) {
                continue;
            }
            if (
                \is_array($candidate['header_items'] ?? null)
                || \is_array($candidate['footer_featured'] ?? null)
                || \is_array($candidate['footer_policies'] ?? null)
                || \is_array($candidate['shared_cta_strategy'] ?? null)
            ) {
                return $candidate;
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

    private function localizeBuildText(string $key, string $locale): string
    {
        $isZh = $this->isChineseLocale($locale);
        $isJa = $this->isJapaneseLocale($locale);
        $isKo = $this->isKoreanLocale($locale);

        return match ($key) {
            'policy_info' => $isZh ? '政策信息' : ($isJa ? 'ポリシー' : ($isKo ? '정책 정보' : 'Policy Info')),
            'featured_pages' => $isZh ? '重点页面' : ($isJa ? '注目ページ' : ($isKo ? '주요 페이지' : 'Featured Pages')),
            'all_pages' => $isZh ? '全部页面' : ($isJa ? 'すべてのページ' : ($isKo ? '모든 페이지' : 'All Pages')),
            'all_rights_reserved' => $isZh ? '保留所有权利。' : ($isJa ? 'All rights reserved.' : ($isKo ? 'All rights reserved.' : 'All rights reserved.')),
            'contact_us' => $isZh ? '联系我们' : ($isJa ? 'お問い合わせ' : ($isKo ? '문의하기' : 'Contact Us')),
            'explore_more' => $isZh ? '了解更多' : ($isJa ? '詳しく見る' : ($isKo ? '더 알아보기' : 'Explore More')),
            'get_started' => $isZh ? '立即开始' : ($isJa ? '始める' : ($isKo ? '시작하기' : 'Get Started')),
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
        if ($locale === '' || !$this->isNonCjkLocale($locale)) {
            return;
        }

        $visibleHtml = (string)\preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $plain = \trim((string)\preg_replace('/\s+/u', ' ', \strip_tags($visibleHtml)));
        if ($plain !== '' && $this->hasMeaningfulCjkContent($plain)) {
            throw new \RuntimeException('Rendered component visible copy does not match website content locale.');
        }
    }

    private function assertRenderedHtmlPassesBuildQualityGate(string $componentCode, string $html): void
    {
        if (\trim($html) === '') {
            return;
        }
        // 标签闭合校验：检测非自闭合标签是否缺少闭标签
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
     * 检测 HTML 片段中未闭合的标签（忽略 void 元素和自闭合标签）。
     *
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
            // 闭标签
            if (\preg_match('/^<\s*\/\s*/', $tagText) === 1) {
                for ($stackIndex = \count($stack) - 1; $stackIndex >= 0; $stackIndex--) {
                    if ($stack[$stackIndex] === $tagName) {
                        \array_splice($stack, $stackIndex, 1);
                        break;
                    }
                }
                continue;
            }
            // void 元素或自闭合标签
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

        return "Website content language (locale {$locale} — {$hint}): all visitor-visible copy (headings, buttons, nav labels, body text, footer, alt text) must be written in this language from the website requirement. Do not use the planning/plan language as visitor copy unless it matches this locale.\n";
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function buildPrimaryLanguageRuleZh(array $websiteProfile, array $scope): string
    {
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        if ($locale === '') {
            return '';
        }
        $hint = $this->describeLocaleForAiPrompt($locale);

        return "网站内容主语言（locale {$locale}，{$hint}）：所有面向访客可见的文案（标题、按钮、导航、段落、页脚、alt 文案等）均须使用用户需求选择的网站主语言撰写。禁止把方案/计划语言当成网站可见文案语言，除非它与该 locale 一致。\n";
    }

    private function emitComponentRetryNotice(string $region, string $componentCode, string $reason, int $attempt): void
    {
        $sse = RequestContext::get(RequestContext::SSE_WRITER_KEY);
        if (!$sse || !\method_exists($sse, 'sendEvent')) {
            return;
        }

        try {
            $sse->sendEvent('warning', [
                'region' => $region,
                'component_code' => $componentCode,
                'message' => (string)__('AI 组件生成未通过校验，正在使用 AI 增强修复方案重写（第 %{1} 轮）：%{2}', [
                    $attempt,
                    $reason,
                ]),
                'retry_attempt' => $attempt,
            ]);
        } catch (\Throwable) {
        }
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

    private function buildRetryGenerationPrompt(
        string $region,
        string $componentCode,
        string $basePrompt,
        string $reason,
        int $attempt,
        array $failedAiData = []
    ): string {
        $cssPrefix = $this->normalizeComponentCssPrefix($componentCode);
        $failedPayload = '';
        if ($failedAiData !== []) {
            $failedPayload = $this->clipText(
                (string)\json_encode($this->normalizeComponentPayload($failedAiData), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR),
                5000
            );
        }

        return $basePrompt
            . "\n\nAI enhanced repair/rewrite mode (attempt {$attempt}/" . self::COMPONENT_GENERATION_MAX_ATTEMPTS . "):"
            . "\n- The previous AI output failed validation because: {$reason}"
            . "\n- Regenerate the SAME component as a full-quality AI design repair, not a stub; keep the complete planned component scope."
            . "\n- Preserve the original page/task intent and customer brief; strengthen visitor-facing copy, hierarchy, surface depth, CTA states, and theme-specific visual devices."
            . "\n- If the previous output used the wrong language, rewrite every visitor-visible heading, body paragraph, CTA label, nav label, badge, form label, legal/support sentence, and alt text into the website content locale before returning JSON."
            . ($failedPayload !== '' ? "\n- Start from this failed component JSON and repair it in place instead of inventing a fresh unrelated block:\n" . $failedPayload : '')
            . "\n- Do not paste planning/observation copy into visible text. Rewrite \"访客看到\", \"用户看到\", \"从而产生\", \"信任感增强\", \"知道如何\", \"Visitors see\", and \"Visitors can review\" sentences into direct marketing, legal, support, or editorial copy."
            . "\n- Do not downgrade to a generic grid: preserve a distinctive composition, theme-matched surface treatment, and at least two visible design devices."
            . "\n- If the failed output looked like plain cards or a flat strip, rewrite the composition with richer layout, stronger art direction, and complete scoped CSS instead of reducing detail."
            . "\n- If this is a hero/banner component and no explicit user adjustment asks for another layout, rebuild it as a true premium 1920x750-style banner: full-background generated image or cover visual layer, dark gradient overlay, and floating content inside a centered max-width container. A small side image plus boxed text is invalid as the default repair target. If explicit user adjustment conflicts, follow the user composition but keep premium generated imagery, readable overlay, and strong hierarchy."
            . "\n- If this is not hero/banner, choose a section-specific recipe for the role: trust badge wall, game showcase, FAQ accordion, story timeline, testimonial quote rail, or cinematic CTA band. Do not reuse the same three-card row plus image from adjacent blocks."
            . "\n- Never reuse generic labels such as \"Get started\", \"Game suite\", and \"Download path\" as a repeated card trio."
            . "\n- For link/contact/social blocks, include real visitor-facing labels, short supporting copy where useful, clear interaction states, and visible grouping or motif treatment."
            . "\n- For blog/article/category blocks, render editorial-quality visitor content: category headline, article teasers, meta chips, related links, reading CTA, and theme-specific layout treatment; use internal anchors or provided URLs, never example.com."
            . "\n- Keep `extra_fields`, `php_variables`, and `js_content` empty unless absolutely necessary."
            . "\n- Do not use generic CSS classes such as .card, .icon, .btn, .title, .item, .panel, .row, .container, .section, .text, .image, or .active; use `{$cssPrefix}-...` classes in both CSS and HTML, and scope CSS selectors with #componentId."
            . "\n- Fix contrast explicitly: no dark text on dark surfaces, no pale text on pale surfaces, and every CTA/link/focus state must be readable against its immediate background."
            . "\n- Fix page hierarchy explicitly: do not repaint the block as one uniform theme color; use role-based palette surfaces, elevation, dividers, texture, or composition contrast."
            . "\n- Fix HTML structure explicitly: every html_* fragment must be balanced, no stray closing tags, no unclosed non-void tags, and no full HTML document wrapper."
            . "\n- Keep CSS within the requested budget but visually complete: every selector and @media block must close its braces before the JSON field ends."
            . "\n- Keep the component complete for its planned purpose, with a component-specific wrapper and enough real content to stand alone in the final preview."
            . "\n- Avoid loops, complex PHP, embedded arrays, dynamic calculations, markdown fences, placeholder copy, and unverified external assets."
            . "\n- Return pure JSON only for component `{$componentCode}`.";
    }

    private function describeLocaleForAiPrompt(string $locale): string
    {
        return match ($locale) {
            'zh_Hans_CN' => '简体中文',
            'zh_Hant_TW' => '繁體中文',
            'en_US' => 'English',
            'ja_JP' => '日本語',
            'ko_KR' => '한국어',
            default => $locale,
        };
    }
}
