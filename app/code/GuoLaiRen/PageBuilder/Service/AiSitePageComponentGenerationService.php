<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use GuoLaiRen\PageBuilder\Service\AI\CodeFixer;
use GuoLaiRen\PageBuilder\Service\AI\CodeValidator;
use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;
use GuoLaiRen\PageBuilder\Service\AI\PreviewRenderer;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\SchedulerSystem;

final class AiSitePageComponentGenerationService
{
    private const REQUEST_CTX_AI_CHUNK_FORWARDER = 'pagebuilder.ai.chunk.forwarder';
    public const REQUEST_KEY_FORCE_REAL_AI_IN_TEST = 'pagebuilder.ai.force_real_in_test';
    public const REQUEST_KEY_ALLOW_STUB_AI_IN_TEST = 'pagebuilder.ai.allow_stub_in_test';
    private const JSON_REPAIR_MAX_ATTEMPTS = 3;
    private const SYNTAX_FIX_MAX_ATTEMPTS = 2;
    private const COMPONENT_GENERATION_MAX_ATTEMPTS = 3;
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
            $sections[] = [
                'key' => (string)($section['key'] ?? ''),
                'code' => $sectionCode,
                'name' => (string)($section['name'] ?? $sectionCode),
                'region' => 'content',
                'sort_order' => (int)($section['sort_order'] ?? 0),
                'prompt' => $this->buildSectionGenerationPrompt($pageType, $section, $blueprint, $websiteProfile, $scope),
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

            $component = $this->generateComponent(
                (string)$section['code'],
                (string)$section['name'],
                (string)$section['region'],
                (string)$section['prompt'],
                \is_array($section['default_config'] ?? null) ? $section['default_config'] : [],
                \is_array($section['render_context'] ?? null) ? $section['render_context'] : []
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
        if ($tasks === []) {
            return $blueprint;
        }

        $sections = \array_values(\array_filter(
            \is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : [],
            static fn($section): bool => \is_array($section)
        ));
        $known = [];
        foreach ($sections as $section) {
            foreach (['code', 'key', 'source_block_key'] as $field) {
                $value = \trim((string)($section[$field] ?? ''));
                if ($value !== '') {
                    $known[$value] = true;
                }
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
            if (isset($known[$sectionCode]) || isset($known[$sectionKey])) {
                continue;
            }

            $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
            $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
            $blockTask = \is_array($task['block_task'] ?? null) ? $task['block_task'] : [];
            $implementationContract = \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [];
            $label = $this->pickString(
                $task['label'] ?? null,
                $planContext['block_goal'] ?? null,
                $blockTask['task_goal'] ?? null,
                $sectionKey
            );
            $description = $this->pickString(
                $taskScript['story_goal'] ?? null,
                $taskScript['content_fill_rule'] ?? null,
                $blockTask['task_goal'] ?? null,
                $planContext['block_goal'] ?? null,
                $implementationContract['implementation_detail'] ?? null,
                $label
            );

            $sections[] = [
                'key' => $sectionKey,
                'code' => $sectionCode,
                'name' => $label !== '' ? $label : $sectionCode,
                'template' => $this->inferBuildTaskSectionTemplate($task, $sectionKey, \count($sections)),
                'config' => [
                    'section_title' => $label !== '' ? $label : $sectionKey,
                    'description' => $description,
                    'section_intro' => $description,
                ],
                'sort_order' => (int)($task['sort_order'] ?? (1000 + \count($sections) * 10)),
                'source_block_key' => $blockKey !== '' ? $blockKey : $sectionKey,
            ];
            $known[$sectionCode] = true;
            if ($sectionKey !== '') {
                $known[$sectionKey] = true;
            }
        }

        \usort($sections, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));
        $blueprint['sections'] = $sections;

        return $blueprint;
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

        // Fiber 不可用或测试环境 → 串行回退
        if ($this->isTestEnvironment() || !\class_exists(\Fiber::class)) {
            foreach ($components as $region => $spec) {
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

        /** @var array<string, \Fiber> $fibers */
        $fibers = [];
        foreach ($components as $region => $spec) {
            $fibers[$region] = new \Fiber(function () use ($spec): array {
                return $this->generateComponent(
                    $spec['componentCode'],
                    $spec['name'],
                    $spec['region'],
                    $spec['prompt'],
                    $spec['defaultConfig'],
                    $spec['renderContext']
                );
            });
        }

        // 启动所有 Fiber
        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        // 轮询直到全部完成
        $results = [];
        $errors = [];
        while (\count($results) + \count($errors) < \count($fibers)) {
            foreach ($fibers as $region => $fiber) {
                if (isset($results[$region]) || isset($errors[$region])) {
                    continue;
                }
                if ($fiber->isTerminated()) {
                    try {
                        $results[$region] = $fiber->getReturn();
                    } catch (\Throwable $e) {
                        $errors[$region] = $e;
                    }
                } elseif ($fiber->isSuspended()) {
                    try {
                        $fiber->resume();
                    } catch (\Throwable $e) {
                        $errors[$region] = $e;
                    }
                }
            }
            // 避免 CPU 空转，并让出当前调度片
            SchedulerSystem::yieldDelay(5);
        }

        // 按原始顺序 yield 结果
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

        /** @var array<string, \Fiber> $fibers */
        $fibers = [];
        foreach ($components as $componentKey => $spec) {
            $fibers[$componentKey] = new \Fiber(function () use ($spec): array {
                return $this->generateComponent(
                    $spec['componentCode'],
                    $spec['name'],
                    $spec['region'],
                    $spec['prompt'],
                    $spec['defaultConfig'],
                    $spec['renderContext']
                );
            });
        }

        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        $settled = [];
        while (\count($settled) < \count($fibers)) {
            $madeProgress = false;
            foreach ($fibers as $componentKey => $fiber) {
                if (isset($settled[$componentKey])) {
                    continue;
                }

                try {
                    if ($fiber->isTerminated()) {
                        $settled[$componentKey] = true;
                        $madeProgress = true;
                        yield $componentKey => [
                            'status' => 'fulfilled',
                            'result' => $fiber->getReturn(),
                        ];
                        continue;
                    }

                    if ($fiber->isSuspended()) {
                        $fiber->resume();
                        $madeProgress = true;

                        if ($fiber->isTerminated()) {
                            $settled[$componentKey] = true;
                            yield $componentKey => [
                                'status' => 'fulfilled',
                                'result' => $fiber->getReturn(),
                            ];
                        }
                    }
                } catch (\Throwable $throwable) {
                    $settled[$componentKey] = true;
                    $madeProgress = true;
                    yield $componentKey => [
                        'status' => 'rejected',
                        'error' => $throwable,
                    ];
                }
            }

            if (\count($settled) < \count($fibers)) {
                SchedulerSystem::yieldDelay($madeProgress ? 1 : 5);
            }
        }
    }

    /**
     * 并发生成 header + footer 共享组件
     *
     * @return array{header:array<string,mixed>, footer:array<string,mixed>}
     */
    public function generateSharedComponentsConcurrently(array $websiteProfile, array $scope): array
    {
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

        $result = ['header' => null, 'footer' => null];
        foreach ($this->generateComponentsConcurrently($components) as $region => $component) {
            $result[$region] = $component;
        }

        return $result;
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
    private function generateComponent(
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
            'description' => $prompt,
        ];
        $attemptPrompt = $this->appendComponentCssScopeInstruction($prompt, $componentCode);
        $lastThrowable = null;

        for ($attempt = 1; $attempt <= self::COMPONENT_GENERATION_MAX_ATTEMPTS; $attempt++) {
            $aiData = [];
            try {
                $aiData = $this->runAiGeneration($region, $attemptPrompt);
                $aiData = $this->ensureAiPayloadValid($aiData, $region, $componentCode);

                $phtml = $this->getFrameworkBuilder()->buildComponent($region, $componentInfo, $aiData);

                $syntaxCheck = $this->getCodeValidator()->checkSyntax($phtml);
                if (empty($syntaxCheck['valid'])) {
                    $phtml = $this->attemptSyntaxFix($phtml, $region, $componentInfo, $aiData, $syntaxCheck);
                }

                $html = $this->renderTemplateToHtml($phtml, $defaultConfig, $renderContext);
                $this->assertRenderedHtmlMatchesLocale($html, $renderContext);

                return [
                    'code' => $componentCode,
                    'name' => $name,
                    'region' => $region,
                    'phtml' => $phtml,
                    'html' => $html,
                    'default_config' => $defaultConfig,
                    'ai_data' => $aiData,
                ];
            } catch (\Throwable $throwable) {
                $lastThrowable = $throwable;
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
                    $attempt + 1
                );
                $this->emitComponentRetryNotice($region, $componentCode, $reason, $attempt + 1);
                \w_log_warning('[AI Site Component Retry] ' . $componentCode . ' (' . $region . ') attempt '
                    . ($attempt + 1) . '/' . self::COMPONENT_GENERATION_MAX_ATTEMPTS . ': ' . $reason);
            }
        }

        $finalReason = $this->summarizeThrowable($lastThrowable ?? new \RuntimeException('unknown'));
        $message = $this->shouldRetryComponentGeneration($lastThrowable ?? new \RuntimeException('unknown'))
            ? 'AI component generation failed after '
                . self::COMPONENT_GENERATION_MAX_ATTEMPTS
                . ' real-AI attempts: '
                . $finalReason
            : 'AI component generation failed: ' . $finalReason;

        throw new \RuntimeException($message, 0, $lastThrowable);

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
        string $componentCode = ''
    ): array
    {
        $aiData = $this->getCodeFixer()->fixAiData($aiData);
        $aiData = $this->applyStrictVirtualThemeComponentPolicy($aiData, $region);
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
        string $region
    ): array
    {
        $aiData['extra_fields'] = '';
        $aiData['php_variables'] = '';
        $aiData['js_content'] = '';

        foreach (['css_extra', 'css_responsive', 'css_content'] as $cssKey) {
            if (!\is_string($aiData[$cssKey] ?? null)) {
                continue;
            }
            $css = \trim((string)$aiData[$cssKey]);
            if ($css === '' || \str_contains($css, '<?') || \str_contains($css, '?>') || \str_contains($css, '@component_')) {
                $aiData[$cssKey] = '';
                continue;
            }
            $this->assertNoBrokenGeneratedImageReferences($css);
            $aiData[$cssKey] = $this->normalizeVirtualThemeCssForValidation($css, 1800);
        }

        if (\in_array($region, ['header', 'footer'], true)) {
            $aiData['html_extra'] = '';
            if ($region === 'footer') {
                $aiData['html_extra_column'] = '';
                $aiData['footer_extra_text'] = $this->cleanAiHtmlFragment((string)($aiData['footer_extra_text'] ?? ''));
            }

            return $aiData;
        }

        if (\is_string($aiData['html_content'] ?? null)) {
            $aiData['html_content'] = $this->cleanAiHtmlFragment((string)$aiData['html_content']);
        }

        if ($this->isLowQualityGeneratedSectionHtml((string)($aiData['html_content'] ?? ''))) {
            throw new \RuntimeException((string)__('AI 组件内容质量不足：缺少真实文案、视觉层次或有效内容。请重新生成。'));
        }

        return $aiData;
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

    private function cleanAiHtmlFragment(string $html): string
    {
        $html = \preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = \preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = $this->stripPhpFragmentsFromHtml($html);
        $html = \preg_replace('/@(?:component|fields)_(?:start|end)\b/i', '', $html) ?? $html;
        $html = \preg_replace('/<div([^>]*class="[^"]*(?:eyebrow|subtitle|kicker|badge)[^"]*"[^>]*)>\s*(首页|主页|关于我们|关于|Home|About|About Us)\s*<\/div>/iu', '', $html) ?? $html;
        $html = \preg_replace('/\b(?:AI_GENERATED_[A-Z0-9_]+|task_key|section_code|block_key|page_type|plan_locale|runtime_context|content\/[a-z0-9_\/-]+|app\/code\/[a-z0-9_\/-]+|var\/[a-z0-9_\/-]+|home_page|about_page|shared:[a-z0-9:_\/-]+|page:[a-z0-9:_\/-]+)\b/iu', '', $html) ?? $html;
        $html = \preg_replace('/(?:核心卖点|功能特性|把首页[^。！？.!?]{0,80}放出来|值得点击|页面类型|内容块)/u', '', $html) ?? $html;
        $this->assertNoBrokenGeneratedImageReferences($html);
        $html = \preg_replace('/\s{2,}/u', ' ', $html) ?? $html;
        $html = \trim($html);

        return $this->clipText($html, 5000);
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

        return \str_replace('?>', '', $html);
    }

    private function isLowQualityGeneratedSectionHtml(string $html): bool
    {
        $trimmed = \trim($html);
        if ($trimmed === '') {
            return true;
        }

        $plain = \trim((string)\preg_replace('/\s+/u', ' ', \strip_tags($trimmed)));
        if ($plain === '' || \mb_strlen($plain) < 18) {
            return true;
        }

        if (\preg_match('/AI content placeholder|ai-empty|placeholder|demo|example\.com/iu', $trimmed) === 1) {
            return true;
        }

        if (\preg_match('/<(h[1-6]|p|a)\b[^>]*>\s*<\/\1>/iu', $trimmed) === 1) {
            return true;
        }

        $hasVisual = \preg_match('/<svg\b|data:image\/svg\+xml|class=["\'][^"\']*(?:card|visual|panel|media|grid|badge)[^"\']*/iu', $trimmed) === 1;
        $hasRealCopy = \mb_strlen($plain) >= 32;

        return !$hasVisual && !$hasRealCopy;
    }

    private function assertNoBrokenGeneratedImageReferences(string $html): void
    {
        $broken = [];
        if (\preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/iu', $html, $found, \PREG_SET_ORDER) > 0) {
            foreach ($found as $row) {
                $src = \trim((string)($row[2] ?? ''));
                if ($this->isBrokenGeneratedImageSource($src)) {
                    $broken[] = $src === '' ? '<empty img src>' : $src;
                }
            }
        }
        if (\preg_match_all('/url\(\s*([\'\"]?)([^\'\")]*)\1\s*\)/iu', $html, $found, \PREG_SET_ORDER) > 0) {
            foreach ($found as $row) {
                $src = \trim((string)($row[2] ?? ''));
                if ($this->isBrokenGeneratedImageSource($src)) {
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

    private function isBrokenGeneratedImageSource(string $src): bool
    {
        $src = \trim(\html_entity_decode($src, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
        if ($src === '' || $src === '#') {
            return true;
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
    private function runAiGeneration(string $region, string $prompt): array
    {
        $forceRealAiInTest = (bool)RequestContext::get(self::REQUEST_KEY_FORCE_REAL_AI_IN_TEST, false);
        $allowStubAiInTest = (bool)RequestContext::get(self::REQUEST_KEY_ALLOW_STUB_AI_IN_TEST, false);
        if ($this->isTestEnvironment() && !$forceRealAiInTest && $allowStubAiInTest) {
            return $this->buildStubAiPayload($region, $prompt);
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

        $this->getAiService()->generateStream(
            $prompt,
            static function (string $chunk) use (&$fullContent, &$chunkBuffer, $flushChunkBuffer, $sse, $region): bool {
                $fullContent .= $chunk;
                $chunkBuffer .= $chunk;
                $flushChunkBuffer(false);
                // 实时转发 AI chunks 到 SSE 客户端，不等待完整响应
                if ($sse !== null) {
                    $sse->sendEvent('ai_chunk', [
                        'region' => $region,
                        'chunk' => $chunk,
                    ]);
                }
                return true;
            },
            null,
            'pagebuilder_component_generation',
            null,
            $this->buildAiRuntimeParams([
                'allow_zero_balance_provider' => true,
                'temperature' => 0.35,
                'max_tokens' => 4096,
                'timeout' => self::AI_REQUEST_TIMEOUT_SECONDS,
                'response_format' => ['type' => 'json_object'],
            ])
        );
        $flushChunkBuffer(true);

        $payload = $this->decodeComponentPayloadWithRepair($fullContent, $region);
        if ($payload === null) {
            throw new \RuntimeException((string)__('AI 未返回有效的组件 JSON 结果'));
        }

        return $this->normalizeComponentPayload($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildStubAiPayload(string $region, string $prompt): array
    {
        $summary = $this->clipText(\preg_replace('/\s+/u', ' ', \trim($prompt)) ?: '', 220);
        $title = $this->extractStubTitleFromPrompt($summary, $region);
        $body = $summary !== ''
            ? $summary
            : 'This section presents concrete website content, visible calls to action, and reusable trust signals for the generated page.';

        return match ($region) {
            'header' => [
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => '#<?= $componentId ?> { border-bottom: 1px solid rgba(15, 23, 42, 0.08); }'
                    . "\n" . '#<?= $componentId ?> .<?= $cls ?>-logo { letter-spacing: -0.02em; }'
                    . "\n" . '#<?= $componentId ?> .<?= $cls ?>-cta { box-shadow: 0 10px 24px rgba(37,99,235,0.22); }',
                'html_extra' => '',
                'js_content' => '',
            ],
            'footer' => [
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => '#<?= $componentId ?> { border-top: 1px solid rgba(148,163,184,0.18); }'
                    . "\n" . '#<?= $componentId ?> .<?= $cls ?>-bottom { font-size: 12px; }',
                'html_extra_column' => '',
                'html_extra' => '',
                'footer_extra_text' => 'Generated in test mode',
                'js_content' => '',
            ],
            default => [
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => '#<?= $componentId ?> .<?= $cls ?>-body { display:grid; gap:18px; }'
                    . "\n" . '#<?= $componentId ?> .ai-site-card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; }'
                    . "\n" . '#<?= $componentId ?> .ai-site-card { padding:20px; border-radius:20px; border:1px solid var(--section-border); background:rgba(255,255,255,0.72); text-align:left; }'
                    . "\n" . '#<?= $componentId ?> .ai-site-callout { padding:18px 20px; border-radius:18px; background:color-mix(in srgb, var(--section-primary) 10%, white); color:var(--section-heading); text-align:left; }',
                'css_responsive' => '#<?= $componentId ?> .ai-site-card-grid { grid-template-columns:1fr; }',
                'html_content' => '<div class="ai-site-card-grid">'
                    . '<article class="ai-site-card"><strong>' . \htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</strong><p>' . \htmlspecialchars($body, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p></article>'
                    . '<article class="ai-site-card"><strong>Visible CTA path</strong><p>Visitors see a clear next action, supporting proof, and page-specific content that can be reviewed before publishing.</p></article>'
                    . '<article class="ai-site-card"><strong>Trust content</strong><p>The section keeps real headings, body copy, and visual hierarchy so build and publish checks validate meaningful output.</p></article>'
                    . '</div>'
                    . '<div class="ai-site-callout"><p>Use this generated block as reviewable page content with editable copy, responsive cards, and a clear conversion path.</p></div>',
                'js_content' => '',
            ],
        };
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
            'hero' => 'Website hero content',
            'cta' => 'Conversion action block',
            default => 'Generated website section',
        };
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizeComponentPayload(array $data): array
    {
        $fieldMappings = [
            'extra_fields' => ['extra_fields', 'extraFields', 'fields'],
            'php_variables' => ['php_variables', 'phpVariables', 'php_vars', 'phpVars'],
            'css_extra' => ['css_extra', 'cssExtra', 'css', 'css_content', 'cssContent'],
            'css_responsive' => ['css_responsive', 'cssResponsive', 'responsive_css', 'responsiveCss'],
            'html_content' => ['html_content', 'htmlContent', 'html', 'content'],
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

        return $normalized ?: $data;
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

        $response = $this->getAiService()->generate(
            $prompt,
            null,
            'pagebuilder_component_generation',
            null,
            $this->buildAiRuntimeParams([
                'temperature' => 0.2,
                'max_tokens' => 8192,
                'timeout' => self::AI_REQUEST_TIMEOUT_SECONDS,
                'response_format' => ['type' => 'json_object'],
                'allow_zero_balance_provider' => true,
            ])
        );

        return \is_string($response) ? $response : null;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function buildAiRuntimeParams(array $params): array
    {
        if (\PHP_SAPI !== 'cli') {
            return $params;
        }

        $params['timeout'] = 0;
        $params['disable_cli_timeout'] = true;

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

        return \array_merge([
            'page' => $this->buildPreviewPageStub($pageType, $websiteProfile, $scope, $blogContext),
            'style_settings' => $styleSettings,
            'style' => $styleSettings,
            'component_config' => $defaultConfig,
            'is_preview' => true,
            '_content_locale' => $this->resolvePrimaryLocale($websiteProfile, $scope),
        ], $blogContext);
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
        $siteSummary = $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope);
        $pageTypes = $this->resolveScopedPageTypes($scope);
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
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
        $sharedRefinement = $this->resolveSharedComponentRefinement($scope, 'header');
        $taskPlanPromptAddon = $this->buildTaskPlanPromptAddon(
            $this->resolveSharedTaskPlanTask($scope, 'header'),
            'header',
            $scope
        );
        $themeContract = $this->buildThemeContractPromptAddon($scope);
        $skillContract = $this->buildWelineSkillContractPromptAddon();
        $visibleCopyRule = $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope);

        return $langRule
            . "You are generating a PageBuilder website header component.\n"
            . "Site name: {$siteDisplayName}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . $visibleCopyRule
            . $skillContract
            . $themeContract
            . "Selected pages: " . \implode(', ', $pageList) . "\n"
            . "Current navigation data: " . \json_encode($headerConfig['nav_items'] ?? [], \JSON_UNESCAPED_UNICODE) . "\n"
            . $taskPlanPromptAddon
            . ($sharedRefinement !== '' ? "Latest user refinement for this header: {$sharedRefinement}\n" : '')
            . "Rules:\n"
            . "1. Output only one header component, never a full page.\n"
            . "2. The copy must read like finished website copy for visitors.\n"
            . "3. Never expose internal wording such as customer brief, prompt text, page focus, requirements, or 'I want to build'.\n"
            . "4. Navigation must be compatible with real page links and the provided navigation data.\n"
            . "5. Keep the structure practical: logo area, navigation, optional CTA, mobile-friendly behavior.\n"
            . "6. Style should be inspired by the reference theme, but do not mention the theme name in visible copy.\n"
            . "7. The framework already provides fields/config/nav/CTA. Set extra_fields, php_variables, html_extra, and js_content to empty strings unless explicitly required.\n"
            . "8. Return compact JSON only. No markdown. No explanation. Keep css_extra under 1200 chars.\n"
            . "JSON fields: extra_fields, php_variables, css_extra, html_extra, js_content.\n"
            . $this->buildComponentJsonPhpSafetyRulesEn();
    }

    private function buildFooterGenerationPrompt(array $websiteProfile, array $scope, string $siteDisplayName, array $footerConfig): string
    {
        $siteSummary = $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope);
        $styleCode = $this->resolvePromptStyleCode($scope, Page::TYPE_HOME);
        $styleDirection = $this->describeStyleDirection($styleCode);
        $langRule = $this->buildPrimaryLanguageRuleEn($websiteProfile, $scope);
        $sharedRefinement = $this->resolveSharedComponentRefinement($scope, 'footer');
        $taskPlanPromptAddon = $this->buildTaskPlanPromptAddon(
            $this->resolveSharedTaskPlanTask($scope, 'footer'),
            'footer',
            $scope
        );
        $themeContract = $this->buildThemeContractPromptAddon($scope);
        $skillContract = $this->buildWelineSkillContractPromptAddon();
        $visibleCopyRule = $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope);

        return $langRule
            . "You are generating a PageBuilder website footer component.\n"
            . "Site name: {$siteDisplayName}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . $visibleCopyRule
            . $skillContract
            . $themeContract
            . $taskPlanPromptAddon
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
            . "6. Style should follow the reference theme direction without naming the theme in visible text.\n"
            . "7. The framework already provides brand/link/social/copyright fields. Set extra_fields, php_variables, html_extra_column, html_extra, and js_content to empty strings unless explicitly required.\n"
            . "8. Return compact JSON only. No markdown. No explanation. Keep footer_extra_text as one short visitor-facing sentence.\n"
            . "JSON fields: extra_fields, php_variables, css_extra, html_extra_column, html_extra, footer_extra_text, js_content.\n"
            . $this->buildComponentJsonPhpSafetyRulesEn();
    }

    private function buildSectionGenerationPrompt(string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): string
    {
        $siteSummary = $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope);
        $pageInstructionMap = Page::getPageTypePromptInstructionsMap();
        $pageInstruction = (string)($pageInstructionMap[$pageType] ?? '');
        $locale = $this->resolvePrimaryLocale($websiteProfile, $scope);
        $sectionKey = (string)($section['key'] ?? '');
        $taskPlanTask = $this->resolveSectionTaskPlanTask($scope, $pageType, (string)($section['code'] ?? ''), $sectionKey);
        $planContext = \is_array($taskPlanTask['plan_context'] ?? null) ? $taskPlanTask['plan_context'] : [];
        $blockTask = \is_array($taskPlanTask['block_task'] ?? null) ? $taskPlanTask['block_task'] : [];
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
        $sectionConfig = \json_encode($section['config'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        $refinement = $this->resolveSectionRefinement($scope, $pageType, (string)($section['code'] ?? ''), $sectionKey);
        $blogPrompt = $this->buildBlogPromptAddon($pageType, $sectionKey, $scope);
        $styleCode = $this->resolvePromptStyleCode($scope, $pageType);
        $styleDirection = $this->describeStyleDirection($styleCode);
        $langRule = $this->buildPrimaryLanguageRuleEn($websiteProfile, $scope);
        $taskPlanPromptAddon = $this->buildTaskPlanPromptAddon($taskPlanTask, 'section', $scope);
        $themeContract = $this->buildThemeContractPromptAddon($scope);
        $skillContract = $this->buildWelineSkillContractPromptAddon();
        $visibleCopyRule = $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope);
        $pageLabel = $this->normalizePromptVisibleLabel(
            (string)($blueprint['page_label'] ?? ''),
            $this->localizePageTypeTitle($pageType, $locale),
            $locale
        );

        return $langRule
            . "You are generating a PageBuilder content component.\n"
            . "Page type: " . $pageLabel . " ({$pageType})\n"
            . "Section name: {$sectionName}\n"
            . "Section role: {$sectionKey}\n"
            . "Suggested structure: {$sectionTemplate}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . "Page guidance: {$pageInstruction}\n"
            . "Suggested section config: {$sectionConfig}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . $visibleCopyRule
            . $skillContract
            . $themeContract
            . $taskPlanPromptAddon
            . ($refinement !== '' ? "Latest refine instruction for this section: {$refinement}\n" : '')
            . ($blogPrompt !== '' ? $blogPrompt . "\n" : '')
            . "Rules:\n"
            . "1. Output only one content component, never a full page document.\n"
            . "2. Write finished visitor-facing copy. Do not expose internal prompts, briefs, requirement wording, or phrases such as 'page focus' and 'site summary'.\n"
            . "3. The section must be meaningfully different for its page type and role; home, about, contact, policy, and blog sections should not read the same.\n"
            . "4. Use the style reference as visual/tone inspiration, but do not mention the style name in visible text.\n"
            . "5. Art direction is mandatory: do not output a flat one-color strip. Start from the provided page_design_plan/page_flow_role when present, then give this block a clear foreground/background relationship, card or panel layering, hover/focus states, and at least one inline SVG or CSS visual when no real asset is supplied.\n"
            . "6. Do not repeat the framework title/description in the body as empty h1/h2/p tags. The body must add useful content such as cards, trust points, game tiles, proof points, or CTA support.\n"
            . "7. Preserve page-level color layering: this block must have its own surface/contrast role and must not make the whole page feel like one solid theme color.\n"
            . "8. Implement like a UI/interaction designer handoff: section-specific visual hierarchy, spatial rhythm, motion restraint, hover/focus states, and mobile stacking must be visible in html_content/css_extra.\n"
            . "9. Set extra_fields, php_variables, and js_content to empty strings. Put final visible section body only in html_content.\n"
            . "10. Return compact JSON only. No markdown. No explanation. Keep html_content under 1800 chars and css_extra under 1200 chars.\n"
            . "11. JSON fields: extra_fields, php_variables, css_extra, css_responsive, html_content, js_content.\n"
            . "12. If real blog data variables are provided, prefer them over invented articles or categories.\n"
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
            . "- css_extra, css_responsive: CSS only. No <? ... ?> and no PHP. Prefer empty CSS because theme tokens are already applied through default_config. If CSS is used, every rule and @media block must have balanced { } braces and be short enough to fit completely.\n"
            . "- CSS class names: never use generic selectors like .card, .icon, .btn, .title, .item, .panel, .row, .container, .section, .text, .image, or .active. Use component-specific classes shaped like pb-{component-code}-{element}, scope selectors with #componentId, and keep CSS selectors and HTML class attributes in sync.\n"
            . "- Images: never output broken image placeholders. If no verified asset URL is provided, create the visual directly with inline SVG or CSS shapes inside html_content; do not use empty src, example.com, placeholder services, or unverified .jpg/.png/.webp URLs.\n"
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
            . "- asset-rule: when a visual/image is needed but no verified uploaded asset URL exists, create a theme-colored inline SVG or CSS visual directly. Never render a broken <img>.\n"
            . "- ai-module-development: this is an audited AI scenario result; include only content that follows the provided stage-1 theme context and current stage-2 task contract.\n"
            . "- queue-usage/sse-streaming: long generation is already queued; return the final component JSON only, not progress narration or markdown.\n";
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
            . "- Never render internal identifiers or paths as visible copy: plan_locale, page_type, section_code, task_key, block_key, runtime_context, app/code paths, var/ paths, content/... component paths, shared:* keys, or page:* keys.\n"
            . "- Never render broken image placeholders. If a verified uploaded asset URL is absent, create the visual with inline SVG or CSS shapes.\n";
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function buildThemeContractPromptAddon(array $scope): string
    {
        $contract = $this->resolveThemeContract($scope);
        $palette = \is_array($contract['palette'] ?? null) ? $contract['palette'] : [];
        if ($palette === []) {
            return '';
        }
        $themeContext = \is_array($contract['raw_context'] ?? null) ? $contract['raw_context'] : [];

        return "Confirmed visual contract from the approved stage-1 theme and stage-2 task plan:\n"
            . "- theme_name: " . (string)($contract['name'] ?? '') . "\n"
            . "- visual_tone: " . (string)($contract['visual_tone'] ?? '') . "\n"
            . "- font_family: " . (string)($contract['font_family'] ?? '') . "\n"
            . "- palette: " . \json_encode($palette, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- full_theme_context: " . $this->jsonEncodeForPrompt($themeContext, 9000) . "\n"
            . "- Use these exact palette tokens for generated CSS and extra fields. Do not invent unrelated accent colors.\n";
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
            return \array_filter([
                'style.bg_color' => $surface !== '' ? $surface : $primary,
                'style.text_color' => $text,
                'style.link_color' => $text,
                'style.link_hover_color' => $accent,
                'style.accent_color' => $accent,
            ], static fn(string $value): bool => $value !== '');
        }

        if ($region === 'footer') {
            return \array_filter([
                'style.bg_color' => $surface !== '' ? $surface : $primary,
                'style.text_color' => $text,
                'style.title_color' => $text,
                'style.link_color' => $text,
                'style.link_hover_color' => $accent !== '' ? $accent : $secondary,
                'style.accent_color' => $accent !== '' ? $accent : $secondary,
            ], static fn(string $value): bool => $value !== '');
        }

        return \array_filter([
            'style.bg_color' => $background !== '' ? $background : '#ffffff',
            'style.text_color' => $text,
            'style.title_color' => $text,
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
            $this->resolveTaskPlanRoot($scope),
            \is_array($scope['stage2_context_snapshot'] ?? null) ? $scope['stage2_context_snapshot'] : [],
            \is_array($scope['theme_context_snapshot'] ?? null) ? $scope['theme_context_snapshot'] : [],
            \is_array($scope['confirmed_stage1_plan_book'] ?? null) ? $scope['confirmed_stage1_plan_book'] : [],
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

        return [
            'name' => (string)($themeContext['name'] ?? $visualDirection['name'] ?? $palette['name'] ?? ''),
            'visual_tone' => (string)($themeContext['visual_tone'] ?? $themeContext['content_tone'] ?? ''),
            'font_family' => (string)($themeContext['font_family'] ?? $visualDirection['font_family'] ?? $typography['font_family'] ?? ''),
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
        $navItems = $this->buildHeaderNavigationPages($scope);
        $navTextLines = [];
        foreach ($navItems as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $navTextLines[] = \trim((string)($item['title'] ?? '')) . '=>' . \trim((string)($item['url'] ?? '#'));
        }

        $defaultConfig = [
            'logo.display' => 'yes',
            'logo.text' => $siteDisplayName,
            'logo.image' => (string)($websiteProfile['logo'] ?? ''),
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

        return $this->applyTaskPlanDefaults($defaultConfig, $this->resolveSharedTaskPlanTask($scope, 'header'), $locale);
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

        return $this->applyTaskPlanDefaults($defaultConfig, $this->resolveSharedTaskPlanTask($scope, 'footer'), $locale);
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

        $bgType = 'color';
        $bgColor = '#ffffff';
        if ((string)($section['template'] ?? '') === 'hero') {
            $bgType = 'gradient';
        } elseif ((string)($section['template'] ?? '') === 'cta') {
            $bgColor = '#0f172a';
        }

        $defaultConfig = [
            'content.title' => $title,
            'content.subtitle' => $subtitle,
            'content.description' => $description,
            'layout.container_width' => '1200',
            'layout.padding_top' => (string)(((string)($section['template'] ?? '') === 'hero') ? 96 : 72),
            'layout.padding_bottom' => (string)(((string)($section['template'] ?? '') === 'cta') ? 96 : 72),
            'layout.text_align' => ((string)($section['template'] ?? '') === 'checklist') ? 'left' : 'center',
            'style.bg_type' => $bgType,
            'style.bg_color' => $bgColor,
            'style.text_color' => ((string)($section['template'] ?? '') === 'cta') ? '#e2e8f0' : '#334155',
            'style.title_color' => ((string)($section['template'] ?? '') === 'cta') ? '#ffffff' : '#0f172a',
            'style.accent_color' => '#2563eb',
        ];
        $defaultConfig = \array_replace($defaultConfig, $this->resolveThemeStyleDefaults($scope, 'content'));

        $taskPlanTask = $this->resolveSectionTaskPlanTask(
            $scope,
            $pageType,
            (string)($section['code'] ?? ''),
            (string)($section['key'] ?? '')
        );

        return $this->applyTaskPlanDefaults($defaultConfig, $taskPlanTask, $locale);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveTaskPlanRoot(array $scope): array
    {
        $structured = \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [];
        if (
            $structured !== []
            && (int)($scope['task_plan_confirmed'] ?? 0) === 1
            && $this->taskPlanRootHasTasks($structured)
        ) {
            return $structured;
        }

        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $confirmed = \is_array($virtualThemePlan['confirmed'] ?? null) ? $virtualThemePlan['confirmed'] : [];
        if (
            $confirmed !== []
            && (int)($scope['task_plan_confirmed'] ?? 0) === 1
            && $this->taskPlanRootHasTasks($confirmed)
        ) {
            return $confirmed;
        }

        return (int)($scope['task_plan_confirmed'] ?? 0) === 1
            ? $this->buildTaskPlanRootFromBuildBlueprint($scope)
            : [];
    }

    /**
     * @param array<string,mixed> $root
     */
    private function taskPlanRootHasTasks(array $root): bool
    {
        if (\is_array($root['shared_tasks'] ?? null) && $root['shared_tasks'] !== []) {
            return true;
        }
        foreach (\is_array($root['page_tasks'] ?? null) ? $root['page_tasks'] : [] as $tasks) {
            if (\is_array($tasks) && $tasks !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildTaskPlanRootFromBuildBlueprint(array $scope): array
    {
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        if ((string)($buildBlueprint['source'] ?? '') !== 'stage2_confirmed_task_plan') {
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
            'signature' => (string)($buildBlueprint['task_plan_signature'] ?? $buildBlueprint['signature'] ?? ''),
            'shared_tasks' => $sharedTasks,
            'page_tasks' => $pageTasks,
        ];
    }

    /**
     * @param array<string,mixed> $confirmed
     */
    private function confirmedTaskPlanHasExecutionBlueprint(array $confirmed): bool
    {
        $executionBlueprint = \is_array($confirmed['execution_blueprint'] ?? null)
            ? $confirmed['execution_blueprint']
            : [];
        $tasks = \is_array($executionBlueprint['tasks'] ?? null) ? $executionBlueprint['tasks'] : [];

        return $tasks !== [];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveSharedTaskPlanTask(array $scope, string $region): array
    {
        $region = \trim($region);
        if ($region === '') {
            return [];
        }

        $root = $this->resolveTaskPlanRoot($scope);
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
    private function resolveSectionTaskPlanTask(array $scope, string $pageType, string $sectionCode, string $sectionKey = ''): array
    {
        $root = $this->resolveTaskPlanRoot($scope);
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
     * @param array<string,mixed> $taskPlanTask
     */
    private function buildTaskPlanPromptAddon(array $taskPlanTask, string $contextLabel, array $scope = []): string
    {
        if ($taskPlanTask === []) {
            return '';
        }

        $planContext = \is_array($taskPlanTask['plan_context'] ?? null) ? $taskPlanTask['plan_context'] : [];
        $taskScript = \is_array($taskPlanTask['task_script'] ?? null) ? $taskPlanTask['task_script'] : [];
        $implementationContract = \is_array($taskPlanTask['implementation_contract'] ?? null) ? $taskPlanTask['implementation_contract'] : [];
        $runtimeContext = \is_array($taskPlanTask['runtime_context'] ?? null) ? $taskPlanTask['runtime_context'] : [];
        $blockTask = \is_array($taskPlanTask['block_task'] ?? null) ? $taskPlanTask['block_task'] : [];
        $themeContext = \is_array($runtimeContext['theme_context_snapshot'] ?? null) ? $runtimeContext['theme_context_snapshot'] : [];
        if ($themeContext === [] && \is_array($taskPlanTask['theme_context_snapshot'] ?? null)) {
            $themeContext = $taskPlanTask['theme_context_snapshot'];
        }
        $sharedPromptContext = \is_array($runtimeContext['shared_prompt_context'] ?? null) ? $runtimeContext['shared_prompt_context'] : [];
        if ($sharedPromptContext === [] && \is_array($taskPlanTask['shared_prompt_context'] ?? null)) {
            $sharedPromptContext = $taskPlanTask['shared_prompt_context'];
        }
        $stage2Context = \is_array($runtimeContext['stage2_context_snapshot'] ?? null) ? $runtimeContext['stage2_context_snapshot'] : [];
        if ($stage2Context === [] && \is_array($scope['stage2_context_snapshot'] ?? null)) {
            $stage2Context = $scope['stage2_context_snapshot'];
        }
        if ($stage2Context === [] && \is_array($scope['build_blueprint']['stage2_context_snapshot'] ?? null)) {
            $stage2Context = $scope['build_blueprint']['stage2_context_snapshot'];
        }
        if ($stage2Context === [] && \is_array($scope['virtual_theme_plan']['confirmed']['stage2_context_snapshot'] ?? null)) {
            $stage2Context = $scope['virtual_theme_plan']['confirmed']['stage2_context_snapshot'];
        }
        if ($themeContext === [] && \is_array($stage2Context['theme_context_snapshot'] ?? null)) {
            $themeContext = $stage2Context['theme_context_snapshot'];
        }
        if ($themeContext === [] && \is_array($scope['theme_context_snapshot'] ?? null)) {
            $themeContext = $scope['theme_context_snapshot'];
        }
        if ($themeContext === [] && \is_array($scope['execution_blueprint']['theme_context_snapshot'] ?? null)) {
            $themeContext = $scope['execution_blueprint']['theme_context_snapshot'];
        }
        if ($sharedPromptContext === [] && \is_array($stage2Context['shared_prompt_context'] ?? null)) {
            $sharedPromptContext = $stage2Context['shared_prompt_context'];
        }
        if ($sharedPromptContext === [] && \is_array($scope['shared_prompt_context'] ?? null)) {
            $sharedPromptContext = $scope['shared_prompt_context'];
        }
        if ($sharedPromptContext === [] && \is_array($scope['execution_blueprint']['shared_prompt_context'] ?? null)) {
            $sharedPromptContext = $scope['execution_blueprint']['shared_prompt_context'];
        }
        if ($sharedPromptContext === [] && \is_array($scope['confirmed_stage1_plan_book']['shared_prompt_context'] ?? null)) {
            $sharedPromptContext = $scope['confirmed_stage1_plan_book']['shared_prompt_context'];
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

        return "Stage-2 task context for this {$contextLabel}:\n"
            . "- task_key: " . (string)($taskPlanTask['task_key'] ?? '') . "\n"
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
            . "- stage1.theme_context_snapshot: " . $this->jsonEncodeForPrompt($themeContext, 7000) . "\n"
            . "- stage1.shared_prompt_context: " . $this->jsonEncodeForPrompt($sharedPromptContext, 5000) . "\n"
            . "- stage2.task_script: " . $this->jsonEncodeForPrompt($taskScript, 7000) . "\n"
            . "- stage2.block_task: " . $this->jsonEncodeForPrompt($blockTask, 7000) . "\n"
            . "- block_task.content_plan: " . \json_encode($contentPlan, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- block_task.style_plan: " . \json_encode($stylePlan, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- block_task.planning_reason: " . (string)($blockTask['planning_reason'] ?? '') . "\n"
            . "- design execution rule: apply page_design_plan.color_layering and section_flow before local block styling; this block must contrast with adjacent blocks through surfaces/cards/gradients/dividers/illustration while staying inside the confirmed palette.\n"
            . "- stage2 language rule: treat stage-2 planned text as source intent, not copy authority; rewrite any planned text that is not in the website content language before placing it in visible component output.\n"
            . "- theme_context_snapshot: " . $this->jsonEncodeForPrompt($themeContext, 7000) . "\n"
            . "- stage2_context_snapshot: " . $this->jsonEncodeForPrompt($stage2Context, 5000) . "\n"
            . "- acceptance: " . \json_encode($acceptance, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- runtime_context: " . \json_encode($runtimeContext, \JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $taskPlanTask
     * @return array<string,mixed>
     */
    private function applyTaskPlanDefaults(array $defaultConfig, array $taskPlanTask, string $locale = ''): array
    {
        if ($taskPlanTask === []) {
            return $defaultConfig;
        }

        $taskScript = \is_array($taskPlanTask['task_script'] ?? null) ? $taskPlanTask['task_script'] : [];
        $fieldRequirements = \is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [];
        foreach ($fieldRequirements as $requirement) {
            if (!\is_array($requirement)) {
                continue;
            }
            $field = \strtolower(\trim((string)($requirement['field'] ?? '')));
            $sample = $this->sanitizeVisibleCopy((string)($requirement['sample'] ?? ''));
            if ($field === '' || $sample === '') {
                continue;
            }
            if (\in_array(\strtolower($sample), ['header', 'footer'], true)) {
                continue;
            }
            if (\str_contains($field, 'navigation') || \str_contains($field, 'featured_links') || \str_contains($field, 'policy_links')) {
                $defaultConfig = $this->applyTaskPlanLinkFieldDefaults($defaultConfig, $field, $sample, $locale);
                continue;
            }

            $candidateKeys = match (true) {
                \str_contains($field, 'brand'), \str_contains($field, 'platform'), \str_contains($field, 'site_title'), \str_contains($field, 'logo_text') => ['logo.text', 'brand.name'],
                \str_contains($field, 'title'), \str_contains($field, 'headline') => ['content.title'],
                \str_contains($field, 'subtitle'), \str_contains($field, 'eyebrow') => ['content.subtitle'],
                \str_contains($field, 'description'), \str_contains($field, 'body'), \str_contains($field, 'text') => ['content.description', 'brand.description'],
                \str_contains($field, 'button_text'), \str_contains($field, 'cta') => ['cta.text'],
                \str_contains($field, 'button_url'), \str_contains($field, 'url') => ['cta.url'],
                default => [],
            };
            foreach ($candidateKeys as $candidateKey) {
                if (\array_key_exists($candidateKey, $defaultConfig)) {
                    $defaultConfig[$candidateKey] = $sample;
                    break;
                }
            }
        }

        $storyGoal = $this->sanitizeVisibleCopy((string)($taskScript['story_goal'] ?? ''));
        if ($storyGoal !== '' && \trim((string)($defaultConfig['content.title'] ?? '')) === '') {
            $defaultConfig['content.title'] = $this->clipText($storyGoal, 72);
        }

        $fillRule = $this->sanitizeVisibleCopy((string)($taskScript['content_fill_rule'] ?? ''));
        if ($fillRule !== '' && \trim((string)($defaultConfig['content.description'] ?? '')) === '') {
            $defaultConfig['content.description'] = $this->clipText($fillRule, 160);
        }

        return $this->sanitizeDefaultConfigVisibleCopy(
            $this->applyTaskPlanDataContractDefaults($defaultConfig, $taskPlanTask)
        );
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function applyTaskPlanLinkFieldDefaults(array $defaultConfig, string $field, string $sample, string $locale = ''): array
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
     * @param array<string,mixed> $taskPlanTask
     * @return array<string,mixed>
     */
    private function applyTaskPlanDataContractDefaults(array $defaultConfig, array $taskPlanTask): array
    {
        foreach ($this->extractTaskPlanDataContractLines($taskPlanTask) as $line) {
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

        return $defaultConfig;
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

        return $defaultConfig;
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

        $normalized = \mb_strtolower($value);
        if (\in_array($normalized, ['首页', '主页', '关于我们', '关于', 'home', 'home page', 'about', 'about page', 'about us'], true)) {
            return '';
        }
        foreach (['核心卖点', '功能特性', '把首页', '值得点击', '放出来', '页面类型', '内容块', '需要作为一次独立', '共享任务只生成一次'] as $marker) {
            if ($marker !== '' && \mb_stripos($normalized, \mb_strtolower($marker)) !== false) {
                return '';
            }
        }

        if (\preg_match('/\b(?:AI_GENERATED_[A-Z0-9_]+|task_key|section_code|block_key|page_type|plan_locale|runtime_context|content\/[a-z0-9_\/-]+|app\/code\/[a-z0-9_\/-]+|var\/[a-z0-9_\/-]+|home_page|about_page|shared:[a-z0-9:_\/-]+|page:[a-z0-9:_\/-]+)\b/iu', $value)) {
            return '';
        }

        return $this->clipText($value, 220);
    }

    /**
     * @param array<string,mixed> $taskPlanTask
     * @return list<string>
     */
    private function extractTaskPlanDataContractLines(array $taskPlanTask): array
    {
        $sources = [];
        foreach (['task_script', 'implementation_contract'] as $rootKey) {
            $root = \is_array($taskPlanTask[$rootKey] ?? null) ? $taskPlanTask[$rootKey] : [];
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

    private function isTestEnvironment(): bool
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

    private function getAiService(): AiService
    {
        return $this->aiService ?? ObjectManager::getInstance(AiService::class);
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
            $this->extractSharedPromptContextFromTask($this->resolveSharedTaskPlanTask($scope, 'header')),
            $this->extractSharedPromptContextFromTask($this->resolveSharedTaskPlanTask($scope, 'footer')),
            \is_array($scope['stage2_context_snapshot']['shared_prompt_context'] ?? null) ? $scope['stage2_context_snapshot']['shared_prompt_context'] : [],
            \is_array($scope['build_blueprint']['stage2_context_snapshot']['shared_prompt_context'] ?? null) ? $scope['build_blueprint']['stage2_context_snapshot']['shared_prompt_context'] : [],
            \is_array($scope['virtual_theme_plan']['confirmed']['stage2_context_snapshot']['shared_prompt_context'] ?? null) ? $scope['virtual_theme_plan']['confirmed']['stage2_context_snapshot']['shared_prompt_context'] : [],
            \is_array($scope['execution_blueprint']['shared_prompt_context'] ?? null) ? $scope['execution_blueprint']['shared_prompt_context'] : [],
            \is_array($scope['confirmed_stage1_plan_book']['shared_prompt_context'] ?? null) ? $scope['confirmed_stage1_plan_book']['shared_prompt_context'] : [],
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
        if ($locale === '' || !$this->isNonCjkLocale($locale)) {
            return;
        }

        $plain = \trim((string)\preg_replace('/\s+/u', ' ', \strip_tags($html)));
        if ($plain !== '' && $this->hasMeaningfulCjkContent($plain)) {
            throw new \RuntimeException('Rendered component visible copy does not match website content locale.');
        }
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
                'message' => (string)__('AI 组件生成未通过校验，正在使用 AI 简化方案重试（第 %{1} 轮）：%{2}', [
                    $attempt,
                    $reason,
                ]),
                'retry_attempt' => $attempt,
            ]);
        } catch (\Throwable) {
        }
    }

    private function buildRetryGenerationPrompt(
        string $region,
        string $componentCode,
        string $basePrompt,
        string $reason,
        int $attempt
    ): string {
        $cssPrefix = $this->normalizeComponentCssPrefix($componentCode);

        return $basePrompt
            . "\n\nRetry mode (attempt {$attempt}/" . self::COMPONENT_GENERATION_MAX_ATTEMPTS . "):"
            . "\n- The previous AI output failed validation because: {$reason}"
            . "\n- Regenerate the SAME component with a safer implementation that is still production-quality and visually layered."
            . "\n- Keep the structure compact, but include real visitor-facing copy, panel/card depth, button states, and an inline SVG or CSS visual if no real asset URL is provided."
            . "\n- Keep `extra_fields`, `php_variables`, and `js_content` empty unless absolutely necessary."
            . "\n- Do not use generic CSS classes such as .card, .icon, .btn, .title, .item, .panel, .row, .container, .section, .text, .image, or .active; use `{$cssPrefix}-...` classes in both CSS and HTML, and scope CSS selectors with #componentId."
            . "\n- Keep CSS short and syntactically complete: every selector and @media block must close its braces before the JSON field ends."
            . "\n- Prefer one small section/root wrapper, practical cards/proof points, one short paragraph, and one optional CTA."
            . "\n- Avoid loops, complex PHP, embedded arrays, dynamic calculations, markdown fences, and long CSS."
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
