<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteExecutionBlueprintService
{
    public const VERSION = 1;
    /** @var array<string, array<string, mixed>|null> */
    private array $appendInstructionDecisionCache = [];

    public function __construct(
        private readonly AiSitePageBlueprintService $pageBlueprintService,
        private readonly ?AiService $aiService = null,
        private readonly ?AiResponseJsonParser $responseJsonParser = null,
    ) {
    }

    private function getResponseJsonParser(): AiResponseJsonParser
    {
        return $this->responseJsonParser ?? ObjectManager::getInstance(AiResponseJsonParser::class);
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<array<string, mixed>> $conversation
     */
    public function buildSourceSignature(array $scope, array $conversation = []): string
    {
        $userConversation = [];
        foreach ($conversation as $entry) {
            if (!\is_array($entry) || (string)($entry['role'] ?? '') !== 'user') {
                continue;
            }
            $content = \trim((string)($entry['content'] ?? ''));
            if ($content !== '') {
                $userConversation[] = $content;
            }
        }

        return \sha1((string)\json_encode([
            'site_title' => \trim((string)($scope['site_title'] ?? '')),
            'site_tagline' => \trim((string)($scope['site_tagline'] ?? '')),
            'target_domain' => \strtolower(\trim((string)($scope['target_domain'] ?? ''))),
            'brief_description' => \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? '')),
            'default_locale' => \trim((string)($scope['default_locale'] ?? $scope['default_language'] ?? '')),
            'plan_locale' => \trim((string)($scope['plan_locale'] ?? $scope['default_locale'] ?? $scope['default_language'] ?? '')),
            'page_types' => \array_values(\array_map('strval', \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [])),
            'conversation' => $userConversation,
        ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array{
     *   plan_json:array<string, mixed>,
     *   structured:array<string, mixed>,
     *   execution_blueprint:array<string, mixed>,
     *   derived_scope_patch:array<string, mixed>,
     *   markdown:string
     * }
     */
    public function buildPlanArtifacts(array $scope, array $websiteProfile, array $payload = []): array
    {
        $pageTypes = $this->expandPageTypes($scope);
        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? ''));
        $planningScope = \array_replace($scope, [
            'page_types' => $pageTypes,
            AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY => 1,
            'plan_instruction' => $instruction,
            'plan_target_scope' => $targetScope,
            'plan_locale' => $planLocale,
        ]);

        $siteDisplayName = $this->pageBlueprintService->resolveSiteDisplayName($websiteProfile, $planningScope);
        $siteSummary = $this->pageBlueprintService->buildSiteMarketingSummary($websiteProfile, $planningScope);
        $palette = $this->buildPalettePlan($planningScope, $websiteProfile, $instruction);
        $themeStyle = $this->buildThemeStyle($planningScope, $websiteProfile, $palette, $instruction);
        $navigationPlan = $this->buildNavigationPlan($pageTypes);
        $footerPlan = $this->buildFooterPlan($pageTypes);
        $seoStrategy = $this->buildSeoStrategy($siteDisplayName, $planningScope, $pageTypes, $instruction);

        $tasks = [
            $this->buildSharedTask('header', $siteDisplayName, $navigationPlan, $palette, $themeStyle, $seoStrategy),
            $this->buildSharedTask('footer', $siteDisplayName, $footerPlan, $palette, $themeStyle, $seoStrategy),
        ];

        $pages = [];
        $pageBlueprints = [];
        foreach ($pageTypes as $pageType) {
            $pageBlueprint = $this->pageBlueprintService->buildPageBlueprint($pageType, $planningScope, $websiteProfile);
            $pageBlueprints[$pageType] = $pageBlueprint;
            $pagePlan = $this->buildPagePlan(
                $pageType,
                $pageBlueprint,
                $pageTypes,
                $siteDisplayName,
                $palette,
                $themeStyle,
                $instruction,
                $targetScope,
                $planLocale
            );
            $pages[$pageType] = $pagePlan;
            foreach ($pagePlan['blocks'] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $tasks[] = $this->buildPageTask($pageType, $pagePlan, $block);
            }
        }

        $executionBlueprint = [
            'version' => self::VERSION,
            'workspace_track' => (string)($planningScope['workspace_track'] ?? AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME),
            'page_types' => $pageTypes,
            'page_blueprints' => $pageBlueprints,
            'shared_components' => [
                'header' => $tasks[0],
                'footer' => $tasks[1],
            ],
            'pages' => $pages,
            'tasks' => $tasks,
        ];
        $executionBlueprint['signature'] = $this->buildExecutionBlueprintSignature($executionBlueprint);

        $structured = [
            'i18n' => $this->ensurePlanI18nSection([], $planLocale, $this->isEnglishLocale($planLocale)),
            'site_strategy' => [
                'site_display_name' => $siteDisplayName,
                'summary' => $siteSummary,
                'theme_style' => $themeStyle,
                'palette' => $palette,
                'instruction' => $instruction,
                'target_scope' => $targetScope,
                'plan_locale' => $planLocale,
            ],
            'seo_strategy' => $seoStrategy,
            'palette' => $palette,
            'theme_style' => $themeStyle,
            'navigation_plan' => $navigationPlan,
            'footer_plan' => $footerPlan,
            'page_types' => $pageTypes,
            'pages' => $pages,
            'execution_steps' => $this->buildExecutionSteps($tasks),
        ];
        $planJson = $this->buildPlanJson($structured);

        return [
            'plan_json' => $planJson,
            'structured' => $structured,
            'execution_blueprint' => $executionBlueprint,
            'derived_scope_patch' => $this->buildDerivedScopePatch($planningScope, $websiteProfile, $structured, $executionBlueprint),
            'markdown' => $this->buildMarkdownPlan($planJson, $planLocale),
        ];
    }

    /**
     * 真实 AI 流式生成阶段一方案；失败时回退到本地规划器。
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $payload
     * @param callable(string):void|null $onChunk
     * @return array{
     *   plan_json:array<string, mixed>,
     *   structured:array<string, mixed>,
     *   execution_blueprint:array<string, mixed>,
     *   derived_scope_patch:array<string, mixed>,
     *   markdown:string,
     *   ai_generated?:int,
     *   ai_fallback?:int
     * }
     */
    public function buildPlanArtifactsByAiStream(array $scope, array $websiteProfile, array $payload = [], ?callable $onChunk = null): array
    {
        $pageTypes = $this->expandPageTypes($scope);
        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? ''));
        $siteDisplayName = $this->pageBlueprintService->resolveSiteDisplayName($websiteProfile, $scope);
        $siteSummary = $this->pageBlueprintService->buildSiteMarketingSummary($websiteProfile, $scope);

        $prompt = $this->buildAiPlanPrompt($scope, $websiteProfile, $pageTypes, $planLocale, $instruction, $targetScope, $siteDisplayName, $siteSummary);
        $fullContent = '';

        try {
            $this->getAiService()->generateStream(
                $prompt,
                static function (string $chunk) use (&$fullContent, $onChunk): bool {
                    $fullContent .= $chunk;
                    if (\is_callable($onChunk) && $chunk !== '') {
                        $onChunk($chunk);
                    }
                    return true;
                },
                null,
                'pagebuilder_plan_generation',
                null,
                [
                    'allow_zero_balance_provider' => true,
                    'temperature' => 0.35,
                    'max_tokens' => 16000,
                    'timeout' => 240,
                    'response_format' => ['type' => 'json_object'],
                ]
            );

            $parser = $this->getResponseJsonParser();
            $decoded = $parser->extractAndDecode($fullContent);
            if (!\is_array($decoded)) {
                throw new \RuntimeException('invalid ai json: ' . \substr(\preg_replace('/\s+/', ' ', $fullContent), 0, 200));
            }

            $artifacts = $this->mapAiPlanToArtifacts($scope, $websiteProfile, $decoded, $pageTypes, $planLocale, $instruction, $targetScope);
            $artifacts['ai_generated'] = 1;
            $artifacts['ai_fallback'] = 0;
            return $artifacts;
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'AI plan generation failed: ' . $throwable->getMessage(),
                (int)$throwable->getCode(),
                $throwable
            );
        }
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $payload
     * @return array{
     *   plan_json:array<string, mixed>,
     *   structured:array<string, mixed>,
     *   execution_blueprint:array<string, mixed>,
     *   derived_scope_patch:array<string, mixed>,
     *   markdown:string,
     *   change_scope_report:array<string, mixed>
     * }
     */
    public function refineDraftPlan(array $scope, array $websiteProfile, array $payload, ?callable $onChunk = null): array
    {
        $artifacts = $this->buildPlanArtifactsByAiStream($scope, $websiteProfile, $payload, $onChunk);
        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $round = \max(1, (int)($payload['round'] ?? 1));
        $artifacts = $this->applyRefineAccumulativePolicy($scope, $artifacts, $instruction);
        $report = [
            'mode' => 'refine',
            'round' => $round,
            'target_scope' => $targetScope,
            'instruction' => $instruction,
            'updated_at' => \date('Y-m-d H:i:s'),
            'changes' => [
                [
                    'target' => $targetScope !== '' ? $targetScope : 'plan',
                    'reason' => $instruction !== '' ? $instruction : '局部优化当前方案',
                ],
            ],
        ];
        $artifacts['structured']['change_scope_report'] = $report;
        $artifacts['plan_json']['change_scope_report'] = $report;
        $artifacts['execution_blueprint']['signature'] = $this->buildExecutionBlueprintSignature($artifacts['execution_blueprint']);
        $artifacts['change_scope_report'] = $report;

        return $artifacts;
    }

    /**
     * 微调策略：默认累加（保留历史追加区块），仅当用户明确“删除/移除”时执行删除。
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $artifacts
     * @return array<string, mixed>
     */
    private function applyRefineAccumulativePolicy(array $scope, array $artifacts, string $instruction): array
    {
        $currentPlanJson = \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [];
        if (!\is_array($currentPlanJson['pages'] ?? null)) {
            return $artifacts;
        }
        $existingPlanJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        if (!\is_array($existingPlanJson['pages'] ?? null)) {
            return $artifacts;
        }

        $isDeleteIntent = $this->isDeleteInstruction($instruction);
        $deleteKeyword = $isDeleteIntent ? $this->extractDeleteKeyword($instruction) : '';
        $pages = $currentPlanJson['pages'];
        foreach ($pages as $pageType => $pagePlan) {
            if (!\is_array($pagePlan) || !\is_array($pagePlan['blocks'] ?? null)) {
                continue;
            }
            $currentBlocks = $pagePlan['blocks'];
            $existingBlocks = \is_array($existingPlanJson['pages'][$pageType]['blocks'] ?? null)
                ? $existingPlanJson['pages'][$pageType]['blocks']
                : [];
            if ($existingBlocks === []) {
                continue;
            }
            if ($isDeleteIntent) {
                $currentBlocks = $this->filterBlocksByDeleteInstruction($currentBlocks, $deleteKeyword);
            } else {
                $currentBlocks = $this->mergeAccumulativeCustomBlocks($existingBlocks, $currentBlocks);
            }
            $pages[$pageType]['blocks'] = \array_values($currentBlocks);
        }
        $currentPlanJson['pages'] = $pages;
        $artifacts['plan_json'] = $currentPlanJson;
        $planLocale = \trim((string)($scope['plan_locale'] ?? ''));
        $artifacts['markdown'] = $this->buildMarkdownPlan($currentPlanJson, $planLocale);
        if (\is_array($artifacts['structured'] ?? null)) {
            $artifacts['structured']['pages'] = $pages;
        }

        return $artifacts;
    }

    /**
     * @param list<array<string, mixed>> $existingBlocks
     * @param list<array<string, mixed>> $currentBlocks
     * @return list<array<string, mixed>>
     */
    private function mergeAccumulativeCustomBlocks(array $existingBlocks, array $currentBlocks): array
    {
        $knownFingerprints = [];
        foreach ($currentBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $knownFingerprints[$this->buildBlockFingerprint($block)] = true;
        }
        $merged = $currentBlocks;
        foreach ($existingBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if (\mb_strtolower(\trim((string)($block['block_key'] ?? ''))) !== 'custom') {
                continue;
            }
            $fp = $this->buildBlockFingerprint($block);
            if (isset($knownFingerprints[$fp])) {
                continue;
            }
            $merged[] = $block;
            $knownFingerprints[$fp] = true;
        }
        \usort($merged, static fn(array $left, array $right): int => ((int)($left['order'] ?? 0)) <=> ((int)($right['order'] ?? 0)));
        return \array_values($merged);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function filterBlocksByDeleteInstruction(array $blocks, string $deleteKeyword): array
    {
        if ($deleteKeyword === '') {
            return $blocks;
        }
        $keyword = \mb_strtolower($deleteKeyword);
        return \array_values(\array_filter($blocks, static function ($block) use ($keyword): bool {
            if (!\is_array($block)) {
                return true;
            }
            $serialized = \mb_strtolower((string)(\json_encode($block, \JSON_UNESCAPED_UNICODE) ?: ''));
            return !\str_contains($serialized, $keyword);
        }));
    }

    private function isDeleteInstruction(string $instruction): bool
    {
        $text = \mb_strtolower(\trim($instruction));
        if ($text === '') {
            return false;
        }
        return $this->containsAny($text, ['删除', '移除', '去掉', '删掉', 'remove', 'delete']);
    }

    private function extractDeleteKeyword(string $instruction): string
    {
        $text = \trim($instruction);
        if ($text === '') {
            return '';
        }
        if (\preg_match('/(?:删除|移除|去掉|删掉)\s*(?:一个|一块|一段|个)?\s*([^，。,.]{1,20})/u', $text, $matches)) {
            return \trim((string)($matches[1] ?? ''));
        }
        if (\preg_match('/(?:remove|delete)\s+([a-zA-Z0-9_\-\s]{1,30})/i', $text, $matches)) {
            return \trim((string)($matches[1] ?? ''));
        }
        return '';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function buildBlockFingerprint(array $block): string
    {
        $blockKey = \mb_strtolower(\trim((string)($block['block_key'] ?? '')));
        $sectionCode = \mb_strtolower(\trim((string)($block['section_code'] ?? '')));
        $titleSample = '';
        if (\is_array($block['field_plan'] ?? null) && isset($block['field_plan'][0]) && \is_array($block['field_plan'][0])) {
            $titleSample = \mb_strtolower(\trim((string)($block['field_plan'][0]['sample'] ?? '')));
        }
        return \md5($blockKey . '|' . $sectionCode . '|' . $titleSample);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $payload
     * @return array{
     *   plan_json:array<string, mixed>,
     *   structured:array<string, mixed>,
     *   execution_blueprint:array<string, mixed>,
     *   derived_scope_patch:array<string, mixed>,
     *   markdown:string,
     *   rebuild_summary:array<string, mixed>
     * }
     */
    public function rebuildDraftPlan(array $scope, array $websiteProfile, array $payload, ?callable $onChunk = null): array
    {
        $artifacts = $this->buildPlanArtifactsByAiStream($scope, $websiteProfile, $payload, $onChunk);
        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $round = \max(1, (int)($payload['round'] ?? 1));
        $summary = [
            'mode' => 'rebuild',
            'round' => $round,
            'instruction' => $instruction,
            'task_count' => \count(\is_array($artifacts['execution_blueprint']['tasks'] ?? null) ? $artifacts['execution_blueprint']['tasks'] : []),
            'page_type_count' => \count(\is_array($artifacts['execution_blueprint']['page_types'] ?? null) ? $artifacts['execution_blueprint']['page_types'] : []),
            'updated_at' => \date('Y-m-d H:i:s'),
        ];
        $artifacts['structured']['rebuild_summary'] = $summary;
        $artifacts['plan_json']['rebuild_summary'] = $summary;
        $artifacts['execution_blueprint']['signature'] = $this->buildExecutionBlueprintSignature($artifacts['execution_blueprint']);
        $artifacts['rebuild_summary'] = $summary;

        return $artifacts;
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function buildAiPlanPrompt(
        array $scope,
        array $websiteProfile,
        array $pageTypes,
        string $planLocale,
        string $instruction,
        string $targetScope,
        string $siteDisplayName,
        string $siteSummary
    ): string {
        $baseline = $this->buildPlanArtifacts($scope, $websiteProfile, [
            'instruction' => $instruction,
            'target_scope' => $targetScope,
        ]);
        $baselinePlanJson = \is_array($baseline['plan_json'] ?? null) ? $baseline['plan_json'] : [];
        $baselineText = $baselinePlanJson === []
            ? '{}'
            : (\json_encode($baselinePlanJson, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}');

        $outputLanguage = $planLocale !== '' ? $planLocale : 'zh_Hans_CN';
        $pageTypeText = $pageTypes === [] ? '-' : \implode(', ', $pageTypes);

        return \implode("\n", [
            '你是一个：网站执行蓝图生成器（Structured Web Blueprint Engine）。',
            '你的任务：根据一句话需求，输出完整网站执行蓝图（第一阶段，Markdown结构化）。',
            'Return STRICT JSON only. No markdown fence. No prose outside JSON.',
            'JSON schema:',
            '{',
            '  "markdown":"string",',
            '  "plan_json": {',
            '    "i18n":{"locale":"string","labels":{"title":"string","site":"string","summary":"string","site_structure":"string","shared_global_plan":"string","page_details":"string"}},',
            '    "site_strategy":{"site_display_name":"string","summary":"string","website_type":"string","core_goal":"string","target_users":"string","conversion_path":"string"},',
            '    "theme_style":{"name":"string","visual_tone":"string","font_family":"string"},',
            '    "palette":{"name":"string","primary":"#hex","accent":"#hex","surface":"#hex","text":"#hex"},',
            '    "navigation_plan":{"header_items":[]},',
            '    "footer_plan":{"featured":[],"policies":[]},',
            '    "seo_strategy":{"core_intent":"string","primary_keywords":["string"],"keyword_page_map":[{"keyword":"string","page_type":"string"}],"content_strategy":"string","internal_linking":"string","url_structure":"string"},',
            '    "page_types":["home_page"],',
            '    "pages":{"home_page":{"page_goal":"string","primary_keywords":["string"],"secondary_keywords":["string"],"blocks":[{"block_key":"string","goal":"string","keywords":["string"],"content":"string","field_plan":[{"field":"string","sample":"string","reason":"string"}],"execution_script":{"feature_points":["string"],"core_copy":"string","typography":"string","style_tone":"string","background_direction":"string","media_assets":["string"]},"reusable":"yes|no","seo_impact":"high|medium|low"}]}},',
            '    "execution_steps":[{"step":1,"task_key":"string","task_type":"string","status":"pending"}],',
            '    "stage2_task_hints":[{"page":"string","block":"string","task_types":["copywriting","ui_design","frontend_dev"]}]',
            '  }',
            '}',
            '强制规则（必须遵守）：',
            '- markdown and all text fields must use locale: ' . $outputLanguage,
            '- 必须使用 Markdown 层级（# / ## / ### / ####）。',
            '- 不允许解释，不允许写“为什么”。',
            '- 不允许抽象描述；所有字段必须可执行、可开发、可部署。',
            '- 每个 Block 必须是可执行施工脚本，且不能出现空字段。',
            '- page_types 只能使用 selected_page_types。',
            '- 信息不足时允许补全，但必须标记前缀“[假设]”。',
            '- 即便是 refine/rebuild/translation，也必须输出完整整案，不得片段。',
            '严格遵循文档约束（app/code/GuoLaiRen/PageBuilder/doc/建站中台/AI建站中台-计划.md 第一阶段MUST）：',
            '- 第一阶段只输出方案（plan），禁止任何 build 执行语义或日志语义。',
            '- 输出必须覆盖全部选中页面类型，不得遗漏页面。',
            '- 输出必须可拆解为第二阶段任务蓝图；并保持字段契约完整。',
            '- 输出需确保 Markdown 方案与结构化蓝图一致。',
            'Markdown 输出模板（必须严格按结构填写，不得缺段）：',
            $this->buildPageMarkdownTemplate($pageTypes),
            'Input context:',
            'site_display_name: ' . $siteDisplayName,
            'site_summary: ' . ($siteSummary !== '' ? $siteSummary : '-'),
            'selected_page_types: ' . $pageTypeText,
            'target_scope: ' . ($targetScope !== '' ? $targetScope : 'full_plan'),
            'instruction: ' . ($instruction !== '' ? $instruction : '-'),
            'baseline_plan_json:',
            $baselineText,
        ]);
    }

    /**
     * 根据选择的页面类型动态生成 Markdown 模板
     *
     * @param list<string> $pageTypes
     * @return string
     */
    private function buildPageMarkdownTemplate(array $pageTypes): string
    {
        $lines = [
            '# Site Blueprint',
            '## Global',
            '### 路由结构（URL）',
            '### Header',
            '### Footer',
            '### 全站字体',
            '### 全站色彩',
            '### CTA体系',
            '### SEO结构规则',
        ];

        // 各页面类型的 Block 模板映射
        $pageBlockTemplates = [
            Page::TYPE_HOME => [
                'label' => 'Home',
                'blocks' => [
                    '## Page: Home',
                    '### 页面信息',
                    '### 转化节奏',
                    '### Block: Hero',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                    '### Block: Feature',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                    '### Block: Process',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                    '### Block: CTA',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                ],
            ],
            Page::TYPE_ABOUT => [
                'label' => 'About',
                'blocks' => [
                    '## Page: About',
                    '### 页面信息',
                    '### Block: Hero',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                    '### Block: Brand',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                    '### Block: Trust',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                    '### Block: CTA',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                ],
            ],
            Page::TYPE_CONTACT => [
                'label' => 'Contact',
                'blocks' => [
                    '## Page: Contact',
                    '### 页面信息',
                    '### Block: Hero',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                    '### Block: Contact Form',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                    '### Block: CTA',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                ],
            ],
            Page::TYPE_PRIVACY_POLICY => [
                'label' => 'Privacy Policy',
                'blocks' => [
                    '## Page: Privacy Policy',
                    '### 页面信息',
                    '### Block: Content',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                ],
            ],
            Page::TYPE_TERMS_OF_SERVICE => [
                'label' => 'Terms of Service',
                'blocks' => [
                    '## Page: Terms of Service',
                    '### 页面信息',
                    '### Block: Content',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                ],
            ],
            Page::TYPE_REFUND_POLICY => [
                'label' => 'Refund Policy',
                'blocks' => [
                    '## Page: Refund Policy',
                    '### 页面信息',
                    '### Block: Content',
                    '#### Block ID',
                    '#### 功能',
                    '#### 内容',
                    '#### 内容结构（字段）',
                    '#### SEO关键词',
                    '#### SEO结构',
                    '#### CTA使用',
                    '#### 字体',
                    '#### 色彩',
                    '#### 背景',
                    '#### 布局',
                    '#### 动效',
                    '#### 交互',
                    '#### 响应式',
                    '#### 素材',
                    '#### 图片规范',
                    '#### 数据埋点',
                    '#### 可复用性',
                ],
            ],
        ];

        // 根据选择的页面类型生成模板
        foreach ($pageTypes as $pageType) {
            if (isset($pageBlockTemplates[$pageType])) {
                $template = $pageBlockTemplates[$pageType];
                foreach ($template['blocks'] as $blockLine) {
                    $lines[] = $blockLine;
                }
            }
        }

        return \implode("\n", $lines);
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function mapAiPlanToArtifacts(
        array $scope,
        array $websiteProfile,
        array $decoded,
        array $pageTypes,
        string $planLocale,
        string $instruction,
        string $targetScope
    ): array {
        $fallback = $this->buildPlanArtifacts($scope, $websiteProfile, [
            'instruction' => $instruction,
            'target_scope' => $targetScope,
        ]);
        $isEn = $this->isEnglishLocale($planLocale);
        $fallbackPlanJson = \is_array($fallback['plan_json'] ?? null) ? $fallback['plan_json'] : [];
        $planJson = \is_array($decoded['plan_json'] ?? null) ? $decoded['plan_json'] : [];
        $mergedPlanJson = $fallbackPlanJson;
        if ($planJson !== []) {
            foreach (['site_strategy', 'theme_style', 'palette', 'navigation_plan', 'footer_plan', 'seo_strategy'] as $sectionKey) {
                if (!\is_array($planJson[$sectionKey] ?? null)) {
                    continue;
                }
                $mergedPlanJson[$sectionKey] = \array_replace_recursive(
                    \is_array($mergedPlanJson[$sectionKey] ?? null) ? $mergedPlanJson[$sectionKey] : [],
                    $planJson[$sectionKey]
                );
            }
            // 页面与区块层是执行蓝图核心，默认以本地规划器为准，避免 AI 文案漂移破坏任务契约。
            // 仅在英文方案下允许 AI 对 pages 做受控覆盖；中文方案保持本地结构化基线。
            if ($isEn && \is_array($planJson['pages'] ?? null)) {
                $basePages = \is_array($mergedPlanJson['pages'] ?? null) ? $mergedPlanJson['pages'] : [];
                foreach ($pageTypes as $pageType) {
                    if (!\is_array($basePages[$pageType] ?? null)) {
                        continue;
                    }
                    if (!\is_array($planJson['pages'][$pageType] ?? null)) {
                        continue;
                    }
                    $basePages[$pageType] = \array_replace_recursive($basePages[$pageType], $planJson['pages'][$pageType]);
                }
                $mergedPlanJson['pages'] = $basePages;
            }
        }
        $mergedPlanJson['page_types'] = $pageTypes;
        $mergedPlanJson = $this->enforcePlanJsonBaseline($mergedPlanJson, $fallbackPlanJson, $pageTypes, $isEn);
        $mergedPlanJson['i18n'] = $this->ensurePlanI18nSection(
            \is_array($mergedPlanJson['i18n'] ?? null) ? $mergedPlanJson['i18n'] : [],
            $planLocale,
            $isEn
        );
        $planBlocks = $this->normalizePlanBlocks(\is_array($planJson['plan_blocks'] ?? null) ? $planJson['plan_blocks'] : []);
        if ($planBlocks === []) {
            $planBlocks = $this->buildPlanBlocksFromPlanJson($mergedPlanJson, $planLocale);
        }
        $mergedPlanJson['plan_blocks'] = $planBlocks;
        $fallback['plan_json'] = $mergedPlanJson;
        $fallback['structured'] = \array_replace(
            \is_array($fallback['structured'] ?? null) ? $fallback['structured'] : [],
            [
                'site_strategy' => \is_array($mergedPlanJson['site_strategy'] ?? null) ? $mergedPlanJson['site_strategy'] : [],
                'theme_style' => \is_array($mergedPlanJson['theme_style'] ?? null) ? $mergedPlanJson['theme_style'] : [],
                'palette' => \is_array($mergedPlanJson['palette'] ?? null) ? $mergedPlanJson['palette'] : [],
                'navigation_plan' => \is_array($mergedPlanJson['navigation_plan'] ?? null) ? $mergedPlanJson['navigation_plan'] : [],
                'footer_plan' => \is_array($mergedPlanJson['footer_plan'] ?? null) ? $mergedPlanJson['footer_plan'] : [],
                'seo_strategy' => \is_array($mergedPlanJson['seo_strategy'] ?? null) ? $mergedPlanJson['seo_strategy'] : [],
                'page_types' => $pageTypes,
                'pages' => \is_array($mergedPlanJson['pages'] ?? null) ? $mergedPlanJson['pages'] : [],
                'plan_blocks' => $planBlocks,
            ]
        );

        // 统一由本地模板按 plan_locale 渲染 Markdown，避免 AI 返回中英混排。
        if (\is_array($fallback['plan_json'] ?? null)) {
            $fallback['markdown'] = $this->buildMarkdownPlan($fallback['plan_json'], $planLocale);
        }

        return $fallback;
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $fallbackPlanJson
     * @return array<string, mixed>
     */
    private function enforcePlanJsonBaseline(array $planJson, array $fallbackPlanJson, array $pageTypes, bool $isEn): array
    {
        $normalized = $planJson;
        $normalized['page_types'] = $pageTypes;
        if (!\is_array($normalized['pages'] ?? null)) {
            $normalized['pages'] = [];
        }
        $fallbackPages = \is_array($fallbackPlanJson['pages'] ?? null) ? $fallbackPlanJson['pages'] : [];
        foreach ($pageTypes as $pageType) {
            if (!\is_array($normalized['pages'][$pageType] ?? null)) {
                $normalized['pages'][$pageType] = \is_array($fallbackPages[$pageType] ?? null) ? $fallbackPages[$pageType] : [];
                continue;
            }
            if (!\is_array($fallbackPages[$pageType] ?? null)) {
                continue;
            }
            // 非英文方案下，强制使用本地页面块文案，确保遵守计划语言与结构约束。
            if (!$isEn) {
                $normalized['pages'][$pageType] = $fallbackPages[$pageType];
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $i18n
     * @return array<string, mixed>
     */
    private function ensurePlanI18nSection(array $i18n, string $planLocale, bool $isEn): array
    {
        $defaultLabels = $isEn
            ? [
                'title' => 'Stage 1 Execution Plan (Full Blueprint)',
                'site' => 'Site',
                'summary' => 'Summary',
                'site_structure' => 'Site Structure',
                'shared_global_plan' => 'Shared Global Plan',
                'page_details' => 'Page And Block Execution Details',
            ]
            : [
                'title' => '阶段一执行蓝图（完整规划）',
                'site' => '站点',
                'summary' => '摘要',
                'site_structure' => '全站结构',
                'shared_global_plan' => '全站共享规划',
                'page_details' => '页面与区块执行细化',
            ];

        $labels = \is_array($i18n['labels'] ?? null) ? $i18n['labels'] : [];
        foreach ($defaultLabels as $key => $value) {
            $candidate = \trim((string)($labels[$key] ?? ''));
            $labels[$key] = $candidate !== '' ? $candidate : $value;
        }

        return [
            'locale' => \trim((string)($i18n['locale'] ?? '')) !== '' ? (string)$i18n['locale'] : $planLocale,
            'labels' => $labels,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return list<string>
     */
    private function expandPageTypes(array $scope): array
    {
        $rawPageTypes = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [];
        $pageTypes = [];
        foreach ($rawPageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '' && isset(Page::getPageTypes()[$pageType])) {
                $pageTypes[] = $pageType;
            }
        }
        if ($pageTypes === []) {
            $pageTypes = [Page::TYPE_HOME];
        }

        return \array_values(\array_unique($pageTypes));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    private function buildPalettePlan(array $scope, array $websiteProfile, string $instruction = ''): array
    {
        $brief = \mb_strtolower(\trim((string)($websiteProfile['brief_description'] ?? $scope['brief_description'] ?? $scope['user_description'] ?? '')));
        $instructionLower = \mb_strtolower($instruction);
        if ($this->containsAny($instructionLower, ['midnight ember', '深色', '暗色', '霓虹', '高对比'])) {
            return [
                'name' => 'Midnight Ember',
                'primary' => '#111827',
                'accent' => '#f59e0b',
                'secondary' => '#dc2626',
                'surface' => '#1f2937',
                'text' => '#f9fafb',
                'reason' => '用户在本轮提示词中明确要求深色高对比路线，采用 Midnight Ember 以强化 CTA 与关键转化入口可见性。',
            ];
        }
        if ($this->containsAny($brief, ['棋牌', 'casino', 'rummy', 'ludo', 'poker', 'aviator', 'satta', 'game', 'gaming'])) {
            return [
                'name' => 'Midnight Ember',
                'primary' => '#111827',
                'accent' => '#f59e0b',
                'secondary' => '#dc2626',
                'surface' => '#1f2937',
                'text' => '#f9fafb',
                'reason' => '需求偏流量转化与强视觉刺激，深底色搭配琥珀与红色更适合突出 CTA、信任徽章与下载入口。',
            ];
        }
        if ($this->containsAny($brief, ['saas', 'finance', 'fintech', 'payment', 'data', 'dashboard', '软件', '金融', '支付'])) {
            return [
                'name' => 'Signal Blue',
                'primary' => '#0f172a',
                'accent' => '#0ea5e9',
                'secondary' => '#14b8a6',
                'surface' => '#e0f2fe',
                'text' => '#0f172a',
                'reason' => '需求偏可信与专业表达，蓝青组合适合 SEO 落地页、数据说明和长期品牌识别。',
            ];
        }
        if ($this->containsAny($brief, ['fitness', 'health', 'wellness', 'sport', '健身', '健康', '运动'])) {
            return [
                'name' => 'Active Green',
                'primary' => '#052e16',
                'accent' => '#22c55e',
                'secondary' => '#84cc16',
                'surface' => '#f0fdf4',
                'text' => '#14532d',
                'reason' => '需求偏活力与正向激励，绿色系更适合强调成长、行动与持续转化。',
            ];
        }

        return [
            'name' => 'Ocean Slate',
            'primary' => '#0f172a',
            'accent' => '#2563eb',
            'secondary' => '#14b8a6',
            'surface' => '#f8fafc',
            'text' => '#0f172a',
            'reason' => '默认采用偏稳健的蓝灰色体系，兼顾信息层级、SEO 内容承载与多行业适配性。',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $palette
     * @return array<string, mixed>
     */
    private function buildThemeStyle(array $scope, array $websiteProfile, array $palette, string $instruction = ''): array
    {
        $brief = \trim((string)($websiteProfile['brief_description'] ?? $scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $reason = '该风格先保证信息结构可读与可执行，再通过关键区块强化转化动作，适配两阶段方案驱动流程。';
        if ($instruction !== '') {
            $reason = '本轮按用户提示词进行方案决策：' . $this->clipText($instruction, 80);
        }
        return [
            'name' => 'Plan-Driven Hybrid',
            'visual_tone' => '先用内容结构建立 SEO 可信度，再用重点区域强化转化动作。',
            'header_style' => '信息密度适中，优先保证导航清晰、品牌识别稳定、移动端菜单易用。',
            'footer_style' => '围绕品牌说明、政策页、站内入口和补充信任信息组织。',
            'responsive_rule' => '首屏与 CTA 在移动端优先保留可见，导航收纳、卡片堆叠、政策与博客信息延后。',
            'palette_name' => (string)($palette['name'] ?? ''),
            'brief_basis' => $brief,
            'reason' => $reason,
        ];
    }

    /**
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildNavigationPlan(array $pageTypes): array
    {
        $labels = Page::getPageTypes();
        $allItems = [];
        foreach ($pageTypes as $pageType) {
            $allItems[] = [
                'type' => $pageType,
                'label' => (string)($labels[$pageType] ?? $pageType),
                'href' => $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType),
            ];
        }

        $headerItems = [];
        foreach ([Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_BLOG_LIST, Page::TYPE_CONTACT] as $type) {
            foreach ($allItems as $item) {
                if ((string)($item['type'] ?? '') === $type) {
                    $headerItems[] = $item;
                    break;
                }
            }
        }
        foreach ($allItems as $item) {
            if (\count($headerItems) >= 5) {
                break;
            }
            $exists = false;
            foreach ($headerItems as $headerItem) {
                if ((string)($headerItem['type'] ?? '') === (string)($item['type'] ?? '')) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $headerItems[] = $item;
            }
        }

        return [
            'header_items' => $headerItems,
            'all_items' => $allItems,
            'why' => 'Header 导航优先承接首页、品牌说明、内容入口和联系动作；其余页面通过页脚与站内链接承接，避免头部过载。',
        ];
    }

    /**
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildFooterPlan(array $pageTypes): array
    {
        $labels = Page::getPageTypes();
        $featured = [];
        $policies = [];
        $allItems = [];
        foreach ($pageTypes as $pageType) {
            $item = [
                'type' => $pageType,
                'label' => (string)($labels[$pageType] ?? $pageType),
                'href' => $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType),
            ];
            $allItems[] = $item;
            if (\in_array($pageType, [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT, Page::TYPE_BLOG_LIST], true)) {
                $featured[] = $item;
            }
            if (\in_array($pageType, [Page::TYPE_PRIVACY_POLICY, Page::TYPE_TERMS_OF_SERVICE, Page::TYPE_COOKIE_POLICY, Page::TYPE_REFUND_POLICY, Page::TYPE_SHIPPING_POLICY], true)) {
                $policies[] = $item;
            }
        }

        return [
            'featured' => $featured !== [] ? $featured : \array_slice($allItems, 0, 4),
            'policies' => $policies,
            'all_items' => $allItems,
            'why' => 'Footer 负责补足政策、信任、补充入口和站内全量链接，兼顾 SEO 爬取与用户兜底导航。',
        ];
    }

    /**
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildSeoStrategy(string $siteDisplayName, array $scope, array $pageTypes, string $instruction = ''): array
    {
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $baseKeywords = \array_values(\array_filter([
            $siteDisplayName,
            $brief !== '' ? $this->clipText($brief, 20) : '',
            $siteDisplayName !== '' ? ($siteDisplayName . ' 官网') : '',
            $siteDisplayName !== '' ? ($siteDisplayName . ' 下载') : '',
        ], static fn(string $value): bool => \trim($value) !== ''));

        return [
            'core_intent' => '先用首页与品牌页承接核心词，再用政策页和内容页补足长尾词与站内结构完整性。',
            'primary_keywords' => \array_values(\array_unique($baseKeywords)),
            'page_type_count' => \count($pageTypes),
            'meta_rule' => '首页突出品牌+核心价值，其余页面突出页面目标+品牌名；meta description 优先概括收益、信任和下一步动作。',
            'reason' => $instruction !== ''
                ? 'SEO 策略同步对齐本轮用户提示词：' . $this->clipText($instruction, 80)
                : 'SEO 策略与页面类型集合保持一致，仅围绕已选择页面构建关键词与内链。',
        ];
    }

    /**
     * @param array<string, mixed> $pageBlueprint
     * @param list<string> $pageTypes
     * @param array<string, mixed> $palette
     * @param array<string, mixed> $themeStyle
     * @return array<string, mixed>
     */
    private function buildPagePlan(
        string $pageType,
        array $pageBlueprint,
        array $pageTypes,
        string $siteDisplayName,
        array $palette,
        array $themeStyle,
        string $instruction = '',
        string $targetScope = '',
        string $locale = ''
    ): array {
        $pageLabel = (string)($pageBlueprint['page_label'] ?? (Page::getPageTypes()[$pageType] ?? $pageType));
        $pageTitle = (string)($pageBlueprint['page_title'] ?? $pageLabel);
        $pageGoal = $this->resolvePageGoal($pageType, $pageLabel, $locale);
        $blocks = [];
        foreach (\is_array($pageBlueprint['sections'] ?? null) ? $pageBlueprint['sections'] : [] as $section) {
            if (!\is_array($section)) {
                continue;
            }
            $blocks[] = $this->buildBlockPlan($pageType, $pageLabel, $pageGoal, $section, $palette, $themeStyle, $siteDisplayName, $locale);
        }

        $appendInstruction = $this->resolveAppendBlockInstruction($instruction, $targetScope, $pageType, $blocks);
        if ($appendInstruction !== null) {
            $appendBlock = $this->buildAppendedBlockPlan($appendInstruction, $pageType, $pageLabel, $pageGoal, $palette, $themeStyle, $locale);
            $insertAfter = \trim((string)($appendInstruction['insert_after'] ?? ''));
            if ($insertAfter !== '') {
                $inserted = false;
                foreach ($blocks as $idx => $block) {
                    if (!\is_array($block)) {
                        continue;
                    }
                    $blockKey = \mb_strtolower(\trim((string)($block['block_key'] ?? '')));
                    $sectionCode = \mb_strtolower(\trim((string)($block['section_code'] ?? '')));
                    $componentKind = \mb_strtolower(\trim((string)($block['component_kind'] ?? '')));
                    if ($blockKey === $insertAfter || $sectionCode === $insertAfter || $componentKind === $insertAfter) {
                        \array_splice($blocks, $idx + 1, 0, [$appendBlock]);
                        $inserted = true;
                        break;
                    }
                }
                if (!$inserted) {
                    $blocks[] = $appendBlock;
                }
            } else {
                $blocks[] = $appendBlock;
            }
            \usort($blocks, static fn(array $left, array $right): int => ((int)($left['order'] ?? 0)) <=> ((int)($right['order'] ?? 0)));
        }

        $internalLinks = [];
        foreach ($pageTypes as $candidateType) {
            if ($candidateType === $pageType) {
                continue;
            }
            $internalLinks[] = [
                'type' => $candidateType,
                'label' => (string)(Page::getPageTypes()[$candidateType] ?? $candidateType),
                'href' => $candidateType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($candidateType),
            ];
            if (\count($internalLinks) >= 4) {
                break;
            }
        }

        return [
            'page_type' => $pageType,
            'page_label' => $pageLabel,
            'page_title' => $pageTitle,
            'page_goal' => $pageGoal,
            'why' => $this->resolvePageWhy($pageType, $pageLabel, $locale),
            'decision_reason' => $instruction !== ''
                ? '页面策略按本轮提示词对齐：' . $this->clipText($instruction, 80)
                : '页面策略基于已选页面类型与站点目标自动生成。',
            'slug' => $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType),
            'nav_label' => $pageLabel,
            'meta_title' => (string)($pageBlueprint['meta_title'] ?? $pageTitle),
            'meta_description' => (string)($pageBlueprint['meta_description'] ?? ''),
            'primary_keywords' => $this->buildPageKeywords($pageTitle, $pageLabel, $siteDisplayName),
            'secondary_keywords' => $this->buildSecondaryKeywords($pageType, $pageLabel),
            'internal_links' => $internalLinks,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    private function resolveAppendBlockInstruction(string $instruction, string $targetScope, string $pageType, array $blocks): ?array
    {
        $appendType = null;
        $aiDecision = $this->resolveAppendInstructionByAi($instruction, $targetScope);
        if ($this->isAiAppendDecisionMatchPage($aiDecision, $pageType)) {
            $appendType = \trim((string)($aiDecision['append_type'] ?? ''));
            if (!\in_array($appendType, ['partner', 'about_intro', 'why_choose_us', 'custom'], true)) {
                $appendType = 'custom';
            }
        } elseif ($this->shouldAppendPartnerBlock($instruction, $targetScope, $pageType)) {
            $appendType = 'partner';
        } elseif ($this->shouldAppendAboutBlock($instruction, $targetScope, $pageType)) {
            $appendType = 'about_intro';
        } elseif ($this->shouldAppendWhyChooseUsBlock($instruction, $targetScope, $pageType)) {
            $appendType = 'why_choose_us';
        }
        if ($appendType !== null) {
            foreach ($blocks as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockKey = \mb_strtolower(\trim((string)($block['block_key'] ?? '')));
                $sectionCode = \mb_strtolower(\trim((string)($block['section_code'] ?? '')));
                $componentKind = \mb_strtolower(\trim((string)($block['component_kind'] ?? '')));
                if (
                    ($appendType === 'partner' && ($blockKey === 'partner' || $sectionCode === 'partner' || $componentKind === 'partner'))
                    || ($appendType === 'about_intro' && (
                        \str_contains($blockKey, 'about')
                        || \str_contains($sectionCode, 'about')
                        || \str_contains($componentKind, 'about')
                    ))
                    || ($appendType === 'why_choose_us' && (
                        \str_contains($blockKey, 'why_choose')
                        || \str_contains($sectionCode, 'why_choose')
                        || \str_contains($componentKind, 'why_choose')
                        || \str_contains($blockKey, 'advantage')
                        || \str_contains($sectionCode, 'advantage')
                        || \str_contains($componentKind, 'advantage')
                    ))
                ) {
                    return null;
                }
            }

            return [
                'type' => $appendType,
                'instruction' => $instruction,
                'insert_after' => $this->containsAny(\mb_strtolower($instruction), ['hero下面', 'hero 下', 'hero后', 'hero 后', 'after hero']) ? 'hero' : '',
            ];
        }

        return null;
    }

    /**
     * 由 AI 判定微调指令的真实意图，避免把“新增模块”逻辑硬编码在关键词表里。
     *
     * @return array<string, mixed>|null
     */
    private function resolveAppendInstructionByAi(string $instruction, string $targetScope): ?array
    {
        $instruction = \trim($instruction);
        if ($instruction === '') {
            return null;
        }
        $cacheKey = \md5($instruction . '|' . \trim($targetScope));
        if (\array_key_exists($cacheKey, $this->appendInstructionDecisionCache)) {
            return $this->appendInstructionDecisionCache[$cacheKey];
        }

        $prompt = \implode("\n", [
            'You are an intent classifier for PageBuilder refine instructions.',
            'Return STRICT JSON only. Do not output markdown.',
            'Schema:',
            '{',
            '  "action": "append_block|none",',
            '  "append_type": "about_intro|why_choose_us|partner|custom|none",',
            '  "target_page_type": "home_page|about_page|contact_page|all|auto",',
            '  "confidence": 0.0,',
            '  "reason": "string"',
            '}',
            'Rules:',
            '- If user asks to add/insert/join a section/module/block, action must be append_block.',
            '- Infer append_type by semantics, not fixed keywords only.',
            '- If target page is not explicit, use auto.',
            '- confidence in [0,1].',
            'Instruction: ' . $instruction,
            'Target scope: ' . (\trim($targetScope) !== '' ? $targetScope : '-'),
        ]);

        try {
            $raw = (string)$this->getAiService()->generate(
                $prompt,
                null,
                'pagebuilder_plan_generation',
                null,
                [
                    'allow_zero_balance_provider' => true,
                    'temperature' => 0.0,
                    'max_tokens' => 500,
                    'timeout' => 60,
                    'response_format' => ['type' => 'json_object'],
                ]
            );
            $decoded = \json_decode($raw, true);
            if (!\is_array($decoded)) {
                $this->appendInstructionDecisionCache[$cacheKey] = null;
                return null;
            }
            $action = \trim((string)($decoded['action'] ?? ''));
            if ($action !== 'append_block') {
                $this->appendInstructionDecisionCache[$cacheKey] = null;
                return null;
            }
            $this->appendInstructionDecisionCache[$cacheKey] = $decoded;
            return $decoded;
        } catch (\Throwable) {
            $this->appendInstructionDecisionCache[$cacheKey] = null;
            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $decision
     */
    private function isAiAppendDecisionMatchPage(?array $decision, string $pageType): bool
    {
        if (!$decision) {
            return false;
        }
        $targetPageType = \trim((string)($decision['target_page_type'] ?? 'auto'));
        if ($targetPageType === '' || $targetPageType === 'auto' || $targetPageType === 'all') {
            return $pageType === Page::TYPE_HOME;
        }
        return $targetPageType === $pageType;
    }

    /**
     * @param array<string, mixed> $appendInstruction
     * @param array<string, mixed> $palette
     * @param array<string, mixed> $themeStyle
     * @return array<string, mixed>
     */
    private function buildAppendedBlockPlan(
        array $appendInstruction,
        string $pageType,
        string $pageLabel,
        string $pageGoal,
        array $palette,
        array $themeStyle,
        string $locale = ''
    ): array {
        $appendType = \trim((string)($appendInstruction['type'] ?? ''));
        if ($appendType === 'partner') {
            return $this->buildPartnerBlockPlan($pageType, $pageLabel, $palette);
        }
        if ($appendType === 'about_intro') {
            return $this->buildAboutIntroBlockPlan($pageType, $pageLabel, $palette, $locale);
        }
        if ($appendType === 'why_choose_us') {
            return $this->buildWhyChooseUsBlockPlan($pageType, $pageLabel, $palette, $locale);
        }

        return [
            'block_key' => 'custom',
            'section_code' => 'custom',
            'region' => 'content',
            'component_kind' => 'content',
            'order' => 980,
            'goal' => $pageGoal,
            'why' => $this->isEnglishLocale($locale)
                ? ('Append custom block for ' . $pageLabel . ' by latest instruction.')
                : ('按最新指令为 ' . $pageLabel . ' 追加自定义区块。'),
            'style_brief' => [
                'visual_tone' => (string)($themeStyle['visual_tone'] ?? ''),
                'layout_rule' => $this->resolveLayoutRule('content', $locale),
                'responsive_rule' => $this->resolveResponsiveRule('content', $locale),
            ],
            'palette_usage' => [
                'background' => (string)($palette['surface'] ?? '#ffffff'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'text' => (string)($palette['text'] ?? '#0f172a'),
                'reason' => $this->isEnglishLocale($locale)
                    ? 'Fallback block reuses current theme palette to keep consistency.'
                    : '兜底区块沿用当前主题色盘，保证视觉一致性。',
            ],
            'seo_brief' => [
                'intent' => $pageGoal,
                'keywords' => [],
                'anchors' => ['#custom'],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'content_brief' => [
                'goal' => $pageGoal,
                'why' => $this->isEnglishLocale($locale)
                    ? 'Keep appended block concise and conversion-oriented.'
                    : '追加区块保持信息简洁并服务转化目标。',
                'headline_direction' => $this->resolveCustomAppendHeadlineDirection($appendInstruction, $locale),
                'body_direction' => $this->resolveCustomAppendBodyDirection($appendInstruction, $locale),
                'cta_direction' => $this->resolveCtaDirection('content', $pageLabel, $locale),
            ],
            'field_plan' => [
                [
                    'field' => 'title',
                    'sample' => $this->resolveCustomAppendTitleSample($appendInstruction),
                    'reason' => $this->isEnglishLocale($locale)
                        ? 'Title helps users identify appended content purpose quickly.'
                        : '标题用于快速说明新增内容目的。',
                ],
                [
                    'field' => 'description',
                    'sample' => '',
                    'reason' => $this->isEnglishLocale($locale)
                        ? 'Description delivers actionable details for users.'
                        : '描述补充可执行的信息细节。',
                ],
            ],
            'result_ref' => [],
        ];
    }

    /**
     * @param array<string, mixed> $appendInstruction
     */
    private function resolveCustomAppendTitleSample(array $appendInstruction): string
    {
        $instruction = \trim((string)($appendInstruction['instruction'] ?? ''));
        if ($instruction === '') {
            return '';
        }
        if (\preg_match('/(?:加|新增|添加)(?:一个|一块|一段|个)?\\s*([^，。,.\\s]{2,20}(?:区|模块|板块|区域))/u', $instruction, $matches)) {
            return \trim((string)($matches[1] ?? ''));
        }
        return '';
    }

    /**
     * @param array<string, mixed> $appendInstruction
     */
    private function resolveCustomAppendHeadlineDirection(array $appendInstruction, string $locale): string
    {
        $titleSample = $this->resolveCustomAppendTitleSample($appendInstruction);
        if ($titleSample !== '') {
            return $this->isEnglishLocale($locale)
                ? ('Use "' . $titleSample . '" as section headline.')
                : ('新增区块标题使用“' . $titleSample . '”。');
        }
        return $this->isEnglishLocale($locale) ? 'Add one clear value headline.' : '补充一个明确价值标题。';
    }

    /**
     * @param array<string, mixed> $appendInstruction
     */
    private function resolveCustomAppendBodyDirection(array $appendInstruction, string $locale): string
    {
        $instruction = \trim((string)($appendInstruction['instruction'] ?? ''));
        if ($instruction !== '') {
            return $this->isEnglishLocale($locale)
                ? ('Follow this refine instruction strictly: ' . $this->clipText($instruction, 100))
                : ('严格按用户微调指令生成该区块：' . $this->clipText($instruction, 100));
        }
        return $this->isEnglishLocale($locale) ? 'Explain added value in short paragraph.' : '用短段落解释新增价值。';
    }

    private function shouldAppendPartnerBlock(string $instruction, string $targetScope, string $pageType): bool
    {
        $instructionLower = \mb_strtolower(\trim($instruction));
        if (!$this->containsAny($instructionLower, ['合作伙伴', '合作品牌', '合作方', 'partner', 'brand logo', 'logo wall'])) {
            return false;
        }
        if ($targetScope === '') {
            return $pageType === Page::TYPE_HOME;
        }
        $scope = \mb_strtolower($targetScope);
        if (\str_contains($scope, $pageType)) {
            return true;
        }

        return $pageType === Page::TYPE_HOME && \str_contains($scope, 'page');
    }

    private function shouldAppendAboutBlock(string $instruction, string $targetScope, string $pageType): bool
    {
        $instructionLower = \mb_strtolower(\trim($instruction));
        if (!$this->containsAny($instructionLower, ['关于我们', 'about us', 'about', 'company intro', '品牌介绍'])) {
            return false;
        }
        if (!$this->containsAny($instructionLower, ['添加', '新增', '加入', 'append', 'add', 'insert'])) {
            return false;
        }
        if ($targetScope === '') {
            return $pageType === Page::TYPE_HOME;
        }
        $scope = \mb_strtolower($targetScope);
        if (\str_contains($scope, $pageType)) {
            return true;
        }

        return $pageType === Page::TYPE_HOME && (\str_contains($scope, 'home') || \str_contains($scope, '首页') || \str_contains($scope, 'page'));
    }

    private function shouldAppendWhyChooseUsBlock(string $instruction, string $targetScope, string $pageType): bool
    {
        $instructionLower = \mb_strtolower(\trim($instruction));
        if (!$this->containsAny($instructionLower, [
            '为什么选择我们',
            '为什么选我们',
            '选择我们',
            '我们的优势',
            '核心优势',
            'why choose us',
            'why us',
            'our advantages',
            'key advantages',
        ])) {
            return false;
        }
        if (!$this->containsAny($instructionLower, ['添加', '新增', '加入', '加一个', 'append', 'add', 'insert'])) {
            return false;
        }
        if ($targetScope === '') {
            return $pageType === Page::TYPE_HOME;
        }
        $scope = \mb_strtolower($targetScope);
        if (\str_contains($scope, $pageType)) {
            return true;
        }
        return $pageType === Page::TYPE_HOME && (\str_contains($scope, 'home') || \str_contains($scope, '首页') || \str_contains($scope, 'page'));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPartnerBlockPlan(string $pageType, string $pageLabel, array $palette): array
    {
        return [
            'block_key' => 'partner',
            'section_code' => 'partner',
            'region' => 'content',
            'component_kind' => 'partner',
            'order' => 990,
            'goal' => '展示合作伙伴与品牌背书，提升页面信任度和转化意愿。',
            'why' => $pageLabel . ' 页增加合作伙伴模块，用真实合作品牌与 Logo 增强可信度，降低用户决策门槛。',
            'style_brief' => [
                'visual_tone' => 'Logo 墙采用低干扰排布，突出品牌识别且不抢主 CTA。',
                'layout_rule' => '默认 4-6 列网格，移动端降为 2 列堆叠。',
                'responsive_rule' => '优先保证品牌 Logo 清晰可见，名称说明可折叠。',
            ],
            'palette_usage' => [
                'background' => (string)($palette['surface'] ?? '#ffffff'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'text' => (string)($palette['text'] ?? '#0f172a'),
                'reason' => '合作伙伴区域以中性背景承载 Logo，避免色彩冲突并保持品牌识别。',
            ],
            'seo_brief' => [
                'intent' => '合作品牌背书与可信信息补充',
                'keywords' => ['合作伙伴', '品牌合作', '合作案例'],
                'anchors' => ['#partner'],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'content_brief' => [
                'goal' => '通过合作伙伴背书增强可信与转化。',
                'why' => '用户在转化前常需信任证据，合作伙伴模块可直接提供品牌背书。',
                'headline_direction' => '展示已合作品牌与生态伙伴',
                'body_direction' => '补充合作类型、合作范围与价值说明',
                'cta_direction' => '提供“查看合作详情/联系合作”入口',
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => '合作伙伴', 'reason' => '模块标题需要明确背书主题。'],
                ['field' => 'partners', 'sample' => '', 'reason' => '合作伙伴列表是本模块核心数据。'],
                ['field' => 'description', 'sample' => '', 'reason' => '说明合作范围与合作价值。'],
            ],
            'result_ref' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAboutIntroBlockPlan(string $pageType, string $pageLabel, array $palette, string $locale = ''): array
    {
        $isEn = $this->isEnglishLocale($locale);
        return [
            'block_key' => 'about_intro',
            'section_code' => 'about_intro',
            'region' => 'content',
            'component_kind' => 'about',
            'order' => 985,
            'goal' => $isEn ? 'Add an about-us section to strengthen trust and explain brand background.' : '补充关于我们模块，增强信任并说明品牌背景。',
            'why' => $isEn
                ? ($pageLabel . ' needs a concise about-us block to connect brand story with conversion intent.')
                : ($pageLabel . ' 增加关于我们区块，可将品牌故事与转化目标衔接。'),
            'style_brief' => [
                'visual_tone' => $isEn ? 'Trust-building, concise, and story-driven.' : '可信、简洁、叙事导向。',
                'layout_rule' => $isEn ? 'Two-column text+image on desktop, single column on mobile.' : '桌面端图文双栏，移动端单列排布。',
                'responsive_rule' => $isEn ? 'Keep headline and brand highlights visible first.' : '优先展示标题和品牌亮点。',
            ],
            'palette_usage' => [
                'background' => (string)($palette['surface'] ?? '#ffffff'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'text' => (string)($palette['text'] ?? '#0f172a'),
                'reason' => $isEn ? 'Use existing palette to keep visual consistency with other homepage blocks.' : '沿用现有色盘，确保与首页其它区块视觉一致。',
            ],
            'seo_brief' => [
                'intent' => $isEn ? 'Brand credibility and company introduction' : '品牌可信背书与公司介绍',
                'keywords' => $isEn ? ['about us', 'company intro', 'brand story'] : ['关于我们', '品牌介绍', '公司简介'],
                'anchors' => ['#about-us'],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'content_brief' => [
                'goal' => $isEn ? 'Introduce who we are, what we do, and why users can trust us.' : '介绍我们是谁、做什么、为何可信。',
                'why' => $isEn ? 'About-us content improves trust and conversion readiness.' : '关于我们内容有助于提升信任与转化准备度。',
                'headline_direction' => $isEn ? 'Use a clear headline describing brand mission.' : '标题明确表达品牌使命。',
                'body_direction' => $isEn ? 'Use 2-3 short paragraphs for background, strengths, and service promise.' : '用 2-3 个短段说明背景、优势与服务承诺。',
                'cta_direction' => $isEn ? 'End with a trust-oriented CTA leading to contact or product pages.' : '结尾提供信任导向 CTA，引导到联系页或产品页。',
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => '', 'reason' => $isEn ? 'Express mission clearly.' : '明确表达使命定位。'],
                ['field' => 'description', 'sample' => '', 'reason' => $isEn ? 'Summarize story and strengths.' : '概述品牌故事与能力优势。'],
                ['field' => 'button_text', 'sample' => '', 'reason' => $isEn ? 'Provide next-step action.' : '提供下一步行动入口。'],
            ],
            'result_ref' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWhyChooseUsBlockPlan(string $pageType, string $pageLabel, array $palette, string $locale = ''): array
    {
        $isEn = $this->isEnglishLocale($locale);
        return [
            'block_key' => 'why_choose_us',
            'section_code' => 'why_choose_us',
            'region' => 'content',
            'component_kind' => 'why_choose_us',
            'order' => 988,
            'goal' => $isEn ? 'Add a Why Choose Us section to convert hesitation into trust.' : '补充“为什么选择我们”区块，把用户犹豫转为信任。',
            'why' => $isEn
                ? ($pageLabel . ' needs explicit advantages to support conversion decisions.')
                : ($pageLabel . ' 需要明确优势说明，帮助用户更快做出转化决策。'),
            'style_brief' => [
                'visual_tone' => $isEn ? 'Trust-focused, concise, and comparison-friendly.' : '信任导向、简洁、便于对比理解。',
                'layout_rule' => $isEn ? 'Use 3-4 advantage cards with icons and short statements.' : '采用 3-4 个优势卡片，配图标和短句说明。',
                'responsive_rule' => $isEn ? 'Stack cards vertically on mobile with clear spacing.' : '移动端优势卡片纵向堆叠并保持清晰间距。',
            ],
            'palette_usage' => [
                'background' => (string)($palette['surface'] ?? '#ffffff'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'text' => (string)($palette['text'] ?? '#0f172a'),
                'reason' => $isEn ? 'Highlight key advantages with accent color for quick scanning.' : '通过强调色突出核心优势，便于快速扫读。',
            ],
            'seo_brief' => [
                'intent' => $isEn ? 'Trust reasons and competitive differentiation' : '信任理由与差异化优势',
                'keywords' => $isEn ? ['why choose us', 'advantages', 'trusted service'] : ['为什么选择我们', '核心优势', '值得信赖'],
                'anchors' => ['#why-choose-us'],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'content_brief' => [
                'goal' => $isEn ? 'State 3-4 concrete reasons users should choose us.' : '明确给出 3-4 条用户应选择我们的理由。',
                'why' => $isEn ? 'Concrete reasons improve trust and reduce decision friction.' : '具体理由能增强信任并降低决策阻力。',
                'headline_direction' => $isEn ? 'Use a direct Why Choose Us headline.' : '标题直接表达“为什么选择我们”。',
                'body_direction' => $isEn ? 'Each card explains one measurable advantage or guarantee.' : '每个卡片说明一条可感知的优势或保障。',
                'cta_direction' => $isEn ? 'End with a CTA to contact or start now.' : '结尾用 CTA 引导咨询或立即开始。',
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => '', 'reason' => $isEn ? 'Clearly name the trust section.' : '明确区块主题名称。'],
                ['field' => 'advantages', 'sample' => '', 'reason' => $isEn ? 'Advantage list is the core decision content.' : '优势列表是核心决策内容。'],
                ['field' => 'button_text', 'sample' => '', 'reason' => $isEn ? 'Guide next-step conversion action.' : '承接下一步转化动作。'],
            ],
            'result_ref' => [],
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @param array<string, mixed> $palette
     * @param array<string, mixed> $themeStyle
     * @return array<string, mixed>
     */
    private function buildBlockPlan(
        string $pageType,
        string $pageLabel,
        string $pageGoal,
        array $section,
        array $palette,
        array $themeStyle,
        string $siteDisplayName,
        string $locale = ''
    ): array {
        $sectionKey = \trim((string)($section['key'] ?? 'block'));
        $sectionCode = \trim((string)($section['code'] ?? $sectionKey));
        $template = \trim((string)($section['template'] ?? 'content'));
        $sectionName = \trim((string)($section['name'] ?? $sectionCode));
        $config = \is_array($section['config'] ?? null) ? $section['config'] : [];

        return [
            'block_key' => $sectionKey,
            'section_code' => $sectionCode,
            'region' => 'content',
            'component_kind' => $template,
            'order' => (int)($section['sort_order'] ?? 0),
            'goal' => $this->resolveBlockGoal($template, $pageGoal, $locale),
            'why' => $this->isEnglishLocale($locale)
                ? ($sectionName . ' breaks the page goal of "' . $pageLabel . '" into actionable, scannable, and linkable content.')
                : ($sectionName . ' 用来把“' . $pageLabel . '”页面目标拆成可浏览、可转化、可内链的实际信息块。'),
            'style_brief' => [
                'visual_tone' => (string)($themeStyle['visual_tone'] ?? ''),
                'layout_rule' => $this->resolveLayoutRule($template, $locale),
                'responsive_rule' => $this->resolveResponsiveRule($template, $locale),
            ],
            'palette_usage' => [
                'background' => $template === 'cta' ? (string)($palette['primary'] ?? '') : (string)($palette['surface'] ?? ''),
                'accent' => (string)($palette['accent'] ?? ''),
                'text' => $template === 'cta' ? (string)($palette['text'] ?? '#ffffff') : '#0f172a',
                'reason' => $template === 'hero'
                    ? '首屏需要最高对比度来承接核心关键词与 CTA。'
                    : '内容区块使用更轻的底色，便于承载 SEO 文案和可读信息层级。',
            ],
            'seo_brief' => [
                'intent' => $pageGoal,
                'keywords' => $this->buildBlockKeywords($siteDisplayName, $pageLabel, $template, $sectionName),
                'anchors' => ['#' . $this->slugify($sectionKey !== '' ? $sectionKey : $sectionCode)],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'keywords' => $this->buildBlockKeywords($siteDisplayName, $pageLabel, $template, $sectionName),
            'content_brief' => [
                'goal' => $pageGoal,
                'why' => $sectionName . ' 要同时服务信息理解和下一步行动。',
                'headline_direction' => $this->resolveHeadlineDirection($config, $sectionName, $pageLabel, $locale),
                'body_direction' => $this->resolveBodyDirection($config, $pageGoal, $locale),
                'cta_direction' => $this->resolveCtaDirection($template, $pageLabel, $locale),
            ],
            'field_plan' => $this->buildFieldPlan($config, $sectionName, $pageGoal, $template, $locale, $siteDisplayName),
            'execution_script' => $this->buildBlockExecutionScript($template, $sectionName, $pageGoal, $siteDisplayName, $locale),
            'result_ref' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildSharedTask(
        string $component,
        string $siteDisplayName,
        array $payload,
        array $palette,
        array $themeStyle,
        array $seoStrategy
    ): array {
        return [
            'task_key' => 'shared:' . $component,
            'task_type' => 'shared_component',
            'component' => $component,
            'goal' => $component === 'header' ? '构建全站统一导航与品牌入口。' : '构建全站统一页脚、政策入口和补充导航。',
            'site_display_name' => $siteDisplayName,
            'style_brief' => [
                'palette' => $palette,
                'theme_style' => $themeStyle,
            ],
            'seo_brief' => $seoStrategy,
            'payload' => $payload,
            'status' => 'pending',
        ];
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildPageTask(string $pageType, array $pagePlan, array $block): array
    {
        $blockKey = \trim((string)($block['block_key'] ?? 'block'));

        return [
            'task_key' => 'page:' . $pageType . ':' . $blockKey,
            'task_type' => 'page_block',
            'page_type' => $pageType,
            'page_label' => (string)($pagePlan['page_label'] ?? $pageType),
            'slug' => (string)($pagePlan['slug'] ?? '/'),
            'block' => $block,
            'status' => 'pending',
        ];
    }

    /**
     * @param array<string, mixed> $executionBlueprint
     */
    private function buildExecutionBlueprintSignature(array $executionBlueprint): string
    {
        return \sha1((string)\json_encode($executionBlueprint, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function buildExecutionSteps(array $tasks): array
    {
        $steps = [];
        foreach ($tasks as $index => $task) {
            $steps[] = [
                'step' => $index + 1,
                'task_key' => (string)($task['task_key'] ?? 'task:' . ($index + 1)),
                'task_type' => (string)($task['task_type'] ?? 'unknown'),
                'status' => 'pending',
            ];
        }

        return $steps;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $executionBlueprint
     * @return array<string, mixed>
     */
    private function buildDerivedScopePatch(
        array $scope,
        array $websiteProfile,
        array $structured,
        array $executionBlueprint
    ): array {
        return [
            'site_title' => (string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? ''),
            'site_tagline' => (string)($scope['site_tagline'] ?? $websiteProfile['site_tagline'] ?? ''),
            'page_types' => \array_values(\array_map('strval', \is_array($executionBlueprint['page_types'] ?? null) ? $executionBlueprint['page_types'] : [])),
            'theme_style' => $structured['theme_style'] ?? [],
            'palette' => $structured['palette'] ?? [],
            'execution_blueprint_signature' => (string)($executionBlueprint['signature'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $structured
     */
    private function buildPlanJson(array $structured): array
    {
        $pages = \is_array($structured['pages'] ?? null) ? $structured['pages'] : [];
        $pageBlocks = [];
        foreach ($pages as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $blockRows = [];
            foreach (\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $contentParts = \array_values(\array_filter([
                    \trim((string)($block['content_brief']['headline_direction'] ?? '')),
                    \trim((string)($block['content_brief']['body_direction'] ?? '')),
                    \trim((string)($block['content_brief']['cta_direction'] ?? '')),
                ], static fn(string $value): bool => $value !== ''));
                $blockRows[] = [
                    'block_key' => (string)($block['block_key'] ?? $block['section_code'] ?? 'block'),
                    'content' => $contentParts !== [] ? \implode(' | ', $contentParts) : \trim((string)($block['goal'] ?? '')),
                    'why' => \trim((string)($block['why'] ?? '')),
                    'keywords' => \array_values(\array_filter(\array_map(
                        static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
                        \is_array($block['keywords'] ?? null)
                            ? $block['keywords']
                            : (\is_array($block['seo_brief']['keywords'] ?? null) ? $block['seo_brief']['keywords'] : [])
                    ), static fn(string $value): bool => $value !== '')),
                    'field_plan' => \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [],
                    'execution_script' => \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [],
                ];
            }
            $pageBlocks[(string)$pageType] = [
                'page_goal' => \trim((string)($pagePlan['page_goal'] ?? '')),
                'why' => \trim((string)($pagePlan['why'] ?? '')),
                'primary_keywords' => \array_values(\array_filter(\array_map(
                    static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
                    \is_array($pagePlan['primary_keywords'] ?? null) ? $pagePlan['primary_keywords'] : []
                ), static fn(string $value): bool => $value !== '')),
                'secondary_keywords' => \array_values(\array_filter(\array_map(
                    static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
                    \is_array($pagePlan['secondary_keywords'] ?? null) ? $pagePlan['secondary_keywords'] : []
                ), static fn(string $value): bool => $value !== '')),
                'blocks' => $blockRows,
            ];
        }

        return [
            'i18n' => \is_array($structured['i18n'] ?? null) ? $structured['i18n'] : [],
            'site_strategy' => \is_array($structured['site_strategy'] ?? null) ? $structured['site_strategy'] : [],
            'theme_style' => \is_array($structured['theme_style'] ?? null) ? $structured['theme_style'] : [],
            'palette' => \is_array($structured['palette'] ?? null) ? $structured['palette'] : [],
            'navigation_plan' => \is_array($structured['navigation_plan'] ?? null) ? $structured['navigation_plan'] : [],
            'footer_plan' => \is_array($structured['footer_plan'] ?? null) ? $structured['footer_plan'] : [],
            'seo_strategy' => \is_array($structured['seo_strategy'] ?? null) ? $structured['seo_strategy'] : [],
            'page_types' => \is_array($structured['page_types'] ?? null) ? $structured['page_types'] : [],
            'pages' => $pageBlocks,
            'plan_blocks' => $this->buildPlanBlocksFromPlanJson([
                'site_strategy' => \is_array($structured['site_strategy'] ?? null) ? $structured['site_strategy'] : [],
                'theme_style' => \is_array($structured['theme_style'] ?? null) ? $structured['theme_style'] : [],
                'palette' => \is_array($structured['palette'] ?? null) ? $structured['palette'] : [],
                'navigation_plan' => \is_array($structured['navigation_plan'] ?? null) ? $structured['navigation_plan'] : [],
                'footer_plan' => \is_array($structured['footer_plan'] ?? null) ? $structured['footer_plan'] : [],
                'seo_strategy' => \is_array($structured['seo_strategy'] ?? null) ? $structured['seo_strategy'] : [],
                'page_types' => \is_array($structured['page_types'] ?? null) ? $structured['page_types'] : [],
                'pages' => $pageBlocks,
            ], (string)($structured['i18n']['locale'] ?? '')),
            'execution_steps' => \is_array($structured['execution_steps'] ?? null) ? $structured['execution_steps'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function buildMarkdownPlan(array $planJson, string $locale = ''): string
    {
        $isEn = $this->isEnglishLocale($locale);
        $i18n = $this->ensurePlanI18nSection(
            \is_array($planJson['i18n'] ?? null) ? $planJson['i18n'] : [],
            $locale,
            $isEn
        );
        $labels = \is_array($i18n['labels'] ?? null) ? $i18n['labels'] : [];
        $site = \trim((string)($planJson['site_strategy']['site_display_name'] ?? ''));
        $summary = \trim((string)($planJson['site_strategy']['summary'] ?? ''));
        $pageTypes = \is_array($planJson['page_types'] ?? null) ? $planJson['page_types'] : [];
        $themeName = \trim((string)($planJson['theme_style']['name'] ?? ''));
        $paletteName = \trim((string)($planJson['palette']['name'] ?? ''));
        $navigationPlan = \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [];
        $footerPlan = \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [];
        $seoStrategy = \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $planBlocks = $this->normalizePlanBlocks(\is_array($planJson['plan_blocks'] ?? null) ? $planJson['plan_blocks'] : []);
        if ($planBlocks !== []) {
            return $this->renderMarkdownFromPlanBlocks($planBlocks, $locale, $site !== '' ? $site : ($isEn ? 'Untitled site' : '未命名站点'));
        }

        $lines = [];
        $lines[] = '# ' . (string)($labels['title'] ?? ($isEn ? 'Stage 1 Execution Plan (Full Blueprint)' : '阶段一执行蓝图（完整规划）'));
        $lines[] = '';
        $lines[] = '- ' . (string)($labels['site'] ?? ($isEn ? 'Site' : '站点')) . ($isEn ? ': ' : '：') . ($site !== '' ? $site : ($isEn ? 'Untitled site' : '未命名站点'));
        $lines[] = '- ' . (string)($labels['summary'] ?? ($isEn ? 'Summary' : '摘要')) . ($isEn ? ': ' : '：') . ($summary !== '' ? $summary : ($isEn ? 'Pending details' : '待补充'));
        $lines[] = ($isEn ? '- Theme Style: ' : '- 主题风格：') . ($themeName !== '' ? $themeName : 'Plan-Driven Hybrid');
        $lines[] = ($isEn ? '- Palette: ' : '- 色盘：') . ($paletteName !== '' ? $paletteName : 'Ocean Slate');
        $lines[] = ($isEn ? '- Page Count: ' : '- 页面数量：') . (string)\count($pageTypes);
        $lines[] = '';
        $lines[] = '## ' . (string)($labels['site_structure'] ?? ($isEn ? 'Site Structure' : '全站结构'));
        foreach ($pageTypes as $pageType) {
            $lines[] = '- ' . (string)$pageType;
        }
        $lines[] = '';
        $lines[] = '## ' . (string)($labels['shared_global_plan'] ?? ($isEn ? 'Shared Global Plan' : '全站共享规划'));
        $lines[] = ($isEn ? '- Header Navigation: ' : '- Header 导航：') . $this->buildLinkSummary(\is_array($navigationPlan['header_items'] ?? null) ? $navigationPlan['header_items'] : [], $locale);
        $lines[] = ($isEn ? '- Footer Sections: ' : '- Footer 栏目：') . $this->buildLinkSummary(\is_array($footerPlan['featured'] ?? null) ? $footerPlan['featured'] : [], $locale);
        $lines[] = ($isEn ? '- Footer Policies: ' : '- Footer 政策：') . $this->buildLinkSummary(\is_array($footerPlan['policies'] ?? null) ? $footerPlan['policies'] : [], $locale);
        $lines[] = ($isEn ? '- SEO Core Strategy: ' : '- SEO 主策略：') . \trim((string)($seoStrategy['core_intent'] ?? ($isEn ? 'not set' : '未设置')));
        $lines[] = '';
        $lines[] = '## ' . (string)($labels['page_details'] ?? ($isEn ? 'Page And Block Execution Details' : '页面与区块执行细化'));

        foreach ($pageTypes as $pageType) {
            $pageType = (string)$pageType;
            $pagePlan = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
            if ($pagePlan === []) {
                $lines[] = '### ' . $pageType;
                $lines[] = $isEn ? '- Missing page plan: please provide page blueprint.' : '- 页面规划缺失：请补充 page blueprint。';
                $lines[] = '';
                continue;
            }

            $lines[] = '### ' . $pageType;
            $lines[] = ($isEn ? '- Page Goal: ' : '- 页面目标：') . \trim((string)($pagePlan['page_goal'] ?? ''));
            $lines[] = ($isEn ? '- Primary Keywords: ' : '- 主关键词：') . $this->buildKeywordSummary(
                \is_array($pagePlan['primary_keywords'] ?? null) ? $pagePlan['primary_keywords'] : [],
                $locale
            );
            $lines[] = ($isEn ? '- Secondary Keywords: ' : '- 补充关键词：') . $this->buildKeywordSummary(
                \is_array($pagePlan['secondary_keywords'] ?? null) ? $pagePlan['secondary_keywords'] : [],
                $locale
            );
            $lines[] = ($isEn ? '- Block Count: ' : '- 区块数量：') . (string)\count(\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : []);
            $lines[] = '';

            foreach (\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [] as $index => $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockOrder = $index + 1;
                $lines[] = ($isEn ? '#### Block ' : '#### 区块 ') . $blockOrder . ($isEn ? ': ' : '：') . (string)($block['block_key'] ?? 'block');
                $lines[] = ($isEn ? '- Block Direction (Stage 1 Blueprint): ' : '- 区块方向（阶段一蓝图）：') . \trim((string)($block['content'] ?? ''));
                $lines[] = ($isEn ? '- Content Keywords: ' : '- 内容关键词：') . $this->buildKeywordSummary(
                    \is_array($block['keywords'] ?? null) ? $block['keywords'] : [],
                    $locale
                );
                $lines[] = ($isEn ? '- Field Content Samples: ' : '- 字段内容示例：') . $this->buildFieldSampleSummary(
                    \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [],
                    $locale
                );
                $script = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
                $featurePoints = \is_array($script['feature_points'] ?? null) ? $script['feature_points'] : [];
                if ($featurePoints !== []) {
                    $lines[] = ($isEn ? '- 功能：' : '- 功能：');
                    foreach ($featurePoints as $point) {
                        $pointText = \is_scalar($point) ? \trim((string)$point) : '';
                        if ($pointText !== '') {
                            $lines[] = '  - ' . $pointText;
                        }
                    }
                }
                $coreCopy = \trim((string)($script['core_copy'] ?? ''));
                if ($coreCopy !== '') {
                    $lines[] = ($isEn ? '- 内容：' : '- 内容：') . $coreCopy;
                }
                $typography = \trim((string)($script['typography'] ?? ''));
                if ($typography !== '') {
                    $lines[] = ($isEn ? '- 字体：' : '- 字体：') . $typography;
                }
                $styleTone = \trim((string)($script['style_tone'] ?? ''));
                if ($styleTone !== '') {
                    $lines[] = ($isEn ? '- 格调：' : '- 格调：') . $styleTone;
                }
                $backgroundDirection = \trim((string)($script['background_direction'] ?? ''));
                if ($backgroundDirection !== '') {
                    $lines[] = ($isEn ? '- 背景：' : '- 背景：') . $backgroundDirection;
                }
                $mediaAssets = \is_array($script['media_assets'] ?? null) ? $script['media_assets'] : [];
                if ($mediaAssets !== []) {
                    $lines[] = ($isEn ? '- 素材：' : '- 素材：');
                    foreach ($mediaAssets as $asset) {
                        $assetText = \is_scalar($asset) ? \trim((string)$asset) : '';
                        if ($assetText !== '') {
                            $lines[] = '  - ' . $assetText;
                        }
                    }
                }
                $lines[] = '';
            }
        }

        return \implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $rawBlocks
     * @return list<array<string, mixed>>
     */
    private function normalizePlanBlocks(array $rawBlocks): array
    {
        $blocks = [];
        foreach ($rawBlocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockId = \trim((string)($block['block_id'] ?? ''));
            $region = \trim((string)($block['region'] ?? 'body'));
            $type = \trim((string)($block['type'] ?? 'section'));
            $title = \trim((string)($block['title'] ?? ''));
            $content = \trim((string)($block['content'] ?? ''));
            $items = \is_array($block['items'] ?? null) ? $block['items'] : [];
            if ($blockId === '') {
                $blockId = 'plan_block_' . ($index + 1);
            }
            if ($title === '' && $content === '' && $items === []) {
                continue;
            }
            $blocks[] = [
                'block_id' => $blockId,
                'region' => $region !== '' ? $region : 'body',
                'type' => $type !== '' ? $type : 'section',
                'title' => $title !== '' ? $title : ('Block ' . ($index + 1)),
                'content' => $content,
                'items' => $items,
            ];
        }
        return $blocks;
    }

    /**
     * @param array<string, mixed> $planJson
     * @return list<array<string, mixed>>
     */
    private function buildPlanBlocksFromPlanJson(array $planJson, string $locale = ''): array
    {
        $isEn = $this->isEnglishLocale($locale);
        $site = \trim((string)($planJson['site_strategy']['site_display_name'] ?? ''));
        $summary = \trim((string)($planJson['site_strategy']['summary'] ?? ''));
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $headerItems = \is_array($planJson['navigation_plan']['header_items'] ?? null) ? $planJson['navigation_plan']['header_items'] : [];
        $footerPolicies = \is_array($planJson['footer_plan']['policies'] ?? null) ? $planJson['footer_plan']['policies'] : [];
        $contentItems = [];
        foreach ($pages as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $contentItems[] = [
                'title' => (string)$pageType,
                'goal' => (string)($pagePlan['page_goal'] ?? ''),
                'blocks' => \is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [],
            ];
        }
        return [
            [
                'block_id' => 'plan_header_001',
                'region' => 'header',
                'type' => 'title',
                'title' => $isEn ? 'Site Blueprint Header' : '方案头部',
                'content' => ($site !== '' ? $site : ($isEn ? 'Untitled site' : '未命名站点')) . ' - ' . ($summary !== '' ? $summary : ($isEn ? 'Blueprint overview' : '方案概述')),
                'items' => [],
            ],
            [
                'block_id' => 'plan_background_001',
                'region' => 'body',
                'type' => 'background',
                'title' => $isEn ? 'Planning Background' : '规划背景',
                'content' => $summary !== '' ? $summary : ($isEn ? 'Use selected page types and conversion goals as baseline.' : '以当前页面类型与转化目标作为规划基线。'),
                'items' => [
                    'header_navigation' => $headerItems,
                    'footer_policies' => $footerPolicies,
                ],
            ],
            [
                'block_id' => 'plan_catalog_001',
                'region' => 'catalog',
                'type' => 'content_catalog',
                'title' => $isEn ? 'Page Block Catalog' : '页面区块目录',
                'content' => $isEn ? 'Extractable block catalog for stage-2 task planning.' : '用于第二阶段任务提取的区块目录。',
                'items' => $contentItems,
            ],
            [
                'block_id' => 'plan_footer_001',
                'region' => 'footer',
                'type' => 'summary',
                'title' => $isEn ? 'Blueprint Tail' : '方案尾部',
                'content' => $isEn ? 'This blueprint is ready for stage-2 task extraction and execution.' : '该蓝图可直接进入第二阶段任务提取与执行。',
                'items' => [],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $planBlocks
     */
    private function renderMarkdownFromPlanBlocks(array $planBlocks, string $locale = '', string $siteName = ''): string
    {
        $isEn = $this->isEnglishLocale($locale);
        $lines = [];
        $lines[] = '# Site Blueprint';
        $lines[] = '';
        $lines[] = '- ' . ($isEn ? 'Site: ' : '站点：') . ($siteName !== '' ? $siteName : ($isEn ? 'Untitled site' : '未命名站点'));
        $lines[] = '';
        foreach ($planBlocks as $block) {
            $title = \trim((string)($block['title'] ?? ''));
            $blockId = \trim((string)($block['block_id'] ?? ''));
            $type = \trim((string)($block['type'] ?? 'section'));
            $region = \trim((string)($block['region'] ?? 'body'));
            $content = \trim((string)($block['content'] ?? ''));
            $lines[] = '## ' . ($title !== '' ? $title : ($isEn ? 'Block' : '区块'));
            $lines[] = '- Block ID: ' . ($blockId !== '' ? $blockId : '-');
            $lines[] = '- Region: ' . ($region !== '' ? $region : 'body');
            $lines[] = '- Type: ' . ($type !== '' ? $type : 'section');
            if ($content !== '') {
                $lines[] = '- Content: ' . $content;
            }
            $items = \is_array($block['items'] ?? null) ? $block['items'] : [];
            if ($items !== []) {
                $lines[] = '### Items';
                foreach ($items as $key => $item) {
                    if (\is_string($key) && $key !== '') {
                        $lines[] = '- ' . $key . ': ' . (\is_scalar($item) ? (string)$item : (\json_encode($item, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''));
                    } else {
                        $lines[] = '- ' . (\is_scalar($item) ? (string)$item : (\json_encode($item, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''));
                    }
                }
            }
            $lines[] = '';
        }
        return \implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function buildLinkSummary(array $items, string $locale = ''): string
    {
        $isEn = $this->isEnglishLocale($locale);
        if ($items === []) {
            return $isEn ? 'none' : '无';
        }
        $parts = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $label = \trim((string)($item['label'] ?? $item['type'] ?? ''));
            $href = \trim((string)($item['href'] ?? ''));
            $parts[] = $href !== '' ? ($label . '(' . $href . ')') : $label;
            if (\count($parts) >= 8) {
                break;
            }
        }

        return $parts !== [] ? \implode($isEn ? ', ' : '、', $parts) : ($isEn ? 'none' : '无');
    }

    /**
     * @param list<string> $keywords
     */
    private function buildKeywordSummary(array $keywords, string $locale = ''): string
    {
        $isEn = $this->isEnglishLocale($locale);
        $normalized = \array_values(\array_filter(\array_map(
            static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
            $keywords
        ), static fn(string $value): bool => $value !== ''));
        if ($normalized === []) {
            return $isEn ? 'none' : '无';
        }
        return \implode($isEn ? ', ' : '、', \array_slice($normalized, 0, 8));
    }

    /**
     * @param list<array<string, mixed>> $fieldPlan
     */
    private function buildFieldSampleSummary(array $fieldPlan, string $locale = ''): string
    {
        $isEn = $this->isEnglishLocale($locale);
        $parts = [];
        foreach ($fieldPlan as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $field = \trim((string)($item['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            $sample = \trim((string)($item['sample'] ?? ''));
            if ($sample === '') {
                continue;
            }
            $parts[] = $field . '=>' . $sample;
            if (\count($parts) >= 5) {
                break;
            }
        }
        return $parts !== [] ? \implode($isEn ? '; ' : '；', $parts) : ($isEn ? 'pending field samples' : '待补充字段示例');
    }

    private function resolvePageGoal(string $pageType, string $pageLabel, string $locale = ''): string
    {
        if ($this->isEnglishLocale($locale)) {
            return match ($pageType) {
                Page::TYPE_HOME => 'Capture core intent, explain value, and surface primary conversion actions.',
                Page::TYPE_ABOUT => 'Build trust by explaining brand background and delivery capability.',
                Page::TYPE_CONTACT => 'Reduce friction and collect qualified leads quickly.',
                default => $pageLabel . ' should clearly serve page intent and lead users to next actions.',
            };
        }
        return match ($pageType) {
            Page::TYPE_HOME => '承接主关键词、首屏价值和关键转化入口。',
            Page::TYPE_ABOUT => '解释品牌背景与能力边界，建立信任。',
            Page::TYPE_CONTACT => '降低咨询门槛，收集有效线索。',
            default => $pageLabel . ' 页面围绕页面意图输出清晰信息并承接下一步动作。',
        };
    }

    private function resolvePageWhy(string $pageType, string $pageLabel, string $locale = ''): string
    {
        if ($this->isEnglishLocale($locale)) {
            return match ($pageType) {
                Page::TYPE_HOME => 'Home is the core traffic entry and must unify value narrative and navigation.',
                Page::TYPE_ABOUT => 'About builds trust and improves conversion decisions.',
                Page::TYPE_CONTACT => 'Contact captures inquiries and shortens the conversion path.',
                default => $pageLabel . ' completes site structure and supports long-tail search coverage.',
            };
        }
        return match ($pageType) {
            Page::TYPE_HOME => '首页作为全站流量主入口，需要统一价值陈述与导航分发。',
            Page::TYPE_ABOUT => '品牌说明页用于增强可信度并提升转化决策效率。',
            Page::TYPE_CONTACT => '联系页用于承接咨询和售前沟通，缩短转化路径。',
            default => $pageLabel . ' 用于补足站点结构完整性与长尾关键词覆盖。',
        };
    }

    /**
     * @return list<string>
     */
    private function buildPageKeywords(string $pageTitle, string $pageLabel, string $siteDisplayName): array
    {
        return \array_values(\array_unique(\array_filter([
            \trim($pageTitle),
            \trim($pageLabel),
            $siteDisplayName !== '' ? ($siteDisplayName . ' ' . $pageLabel) : '',
        ], static fn(string $keyword): bool => $keyword !== '')));
    }

    /**
     * @return list<string>
     */
    private function buildSecondaryKeywords(string $pageType, string $pageLabel): array
    {
        $keywords = [$pageLabel . ' 指南', $pageLabel . ' 常见问题'];
        if ($pageType === Page::TYPE_HOME) {
            $keywords[] = '品牌介绍';
            $keywords[] = '核心优势';
        }

        return \array_values(\array_unique($keywords));
    }

    private function resolveBlockGoal(string $template, string $pageGoal, string $locale = ''): string
    {
        if ($this->isEnglishLocale($locale)) {
            return match ($template) {
                'hero' => 'Explain value quickly above the fold and drive a primary action.',
                'cta' => 'Focus on one action to reduce decision friction.',
                'features' => 'Present capabilities in a structured and scannable format.',
                default => $pageGoal,
            };
        }
        return match ($template) {
            'hero' => '在首屏快速解释价值并引导关键动作。',
            'cta' => '聚焦单一动作，降低用户决策成本。',
            'features' => '结构化说明能力点，增强对比与理解。',
            default => $pageGoal,
        };
    }

    private function resolveLayoutRule(string $template, string $locale = ''): string
    {
        if ($this->isEnglishLocale($locale)) {
            return match ($template) {
                'hero' => 'Prefer two-column or centered above-the-fold layout with headline and CTA visible together.',
                'features' => 'Use card grid layout for fast scanning.',
                default => 'Mobile-first single column, then expand by content density on desktop.',
            };
        }
        return match ($template) {
            'hero' => '首屏优先双栏或居中布局，标题与 CTA 同屏出现。',
            'features' => '卡片栅格布局，保证扫描效率。',
            default => '移动端单列优先，桌面端按内容密度扩展。',
        };
    }

    private function resolveResponsiveRule(string $template, string $locale = ''): string
    {
        if ($this->isEnglishLocale($locale)) {
            return match ($template) {
                'hero', 'cta' => 'On mobile, keep headline and primary CTA visible first; collapse secondary details.',
                default => 'Stack to single column on small screens to preserve reading order.',
            };
        }
        return match ($template) {
            'hero', 'cta' => '移动端优先保留标题与主按钮，次级说明折叠到后续区域。',
            default => '断点下按单列堆叠，保证阅读顺序与触达效率。',
        };
    }

    /**
     * @return list<string>
     */
    private function buildBlockKeywords(string $siteDisplayName, string $pageLabel, string $template, string $sectionName): array
    {
        $keywords = [
            $pageLabel . ' ' . $sectionName,
            $template . ' ' . $sectionName,
        ];
        if ($siteDisplayName !== '') {
            $keywords[] = $siteDisplayName . ' ' . $sectionName;
        }

        return \array_values(\array_unique(\array_filter($keywords, static fn(string $keyword): bool => \trim($keyword) !== '')));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveHeadlineDirection(array $config, string $sectionName, string $pageLabel, string $locale = ''): string
    {
        $headline = \trim((string)($config['title'] ?? ''));
        if ($headline !== '') {
            return $this->isEnglishLocale($locale)
                ? ('Center headline around "' . $headline . '" to reinforce value and benefit.')
                : ('围绕“' . $headline . '”强化页面主题与用户收益。');
        }
        return $this->isEnglishLocale($locale)
            ? ('Use headline to express "' . $sectionName . '" value under "' . $pageLabel . '".')
            : ('标题围绕“' . $pageLabel . '”中的“' . $sectionName . '”说明核心价值（阶段一仅给蓝图方向，具体文案在方向确认后由第二阶段生成）。');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveBodyDirection(array $config, string $pageGoal, string $locale = ''): string
    {
        $description = \trim((string)($config['description'] ?? ''));
        if ($description !== '') {
            return $this->isEnglishLocale($locale)
                ? ('Use concise readable paragraphs and explain: ' . $this->clipText($description, 40))
                : ('正文保持可读短段落，重点解释：' . $this->clipText($description, 40) . '（阶段一仅给蓝图方向，具体文案在方向确认后由第二阶段生成）。');
        }
        return $this->isEnglishLocale($locale)
            ? ('Structure body content by page goal: ' . $pageGoal)
            : ('正文围绕页面目标组织信息层级：' . $pageGoal);
    }

    private function resolveCtaDirection(string $template, string $pageLabel, string $locale = ''): string
    {
        if ($template === 'cta' || $template === 'hero') {
            return $this->isEnglishLocale($locale)
                ? 'Keep CTA to one primary action: Contact / Start Now / Learn More.'
                : 'CTA 保持单一动作，优先“立即咨询/立即开始/了解更多”之一（阶段一仅给蓝图方向，具体文案在方向确认后由第二阶段生成）。';
        }
        return $this->isEnglishLocale($locale)
            ? ($pageLabel . ' should use secondary CTA to support, not compete with, primary CTA.')
            : ($pageLabel . ' 页以次级 CTA 承接，避免与主 CTA 竞争。');
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function buildFieldPlan(array $config, string $sectionName, string $pageGoal, string $template, string $locale = '', string $siteDisplayName = ''): array
    {
        $fields = [];
        foreach (['title', 'subtitle', 'description', 'button_text', 'button_link', 'image'] as $field) {
            if (!\array_key_exists($field, $config)) {
                continue;
            }
            $sample = \trim((string)($config[$field] ?? ''));
            if ($sample === '') {
                $sample = $this->resolveFieldSample($field, $template, $sectionName, $pageGoal, $siteDisplayName, $locale);
            }
            $fields[] = [
                'field' => $field,
                'sample' => $sample,
                'reason' => $this->isEnglishLocale($locale)
                    ? ($sectionName . ' needs this field to support goal "' . $pageGoal . '".')
                    : ($sectionName . ' 需要该字段支撑“' . $pageGoal . '”目标。'),
            ];
        }

        if ($fields === []) {
            $fields[] = [
                'field' => 'description',
                'sample' => $this->resolveFieldSample('description', $template, $sectionName, $pageGoal, $siteDisplayName, $locale),
                'reason' => $this->isEnglishLocale($locale)
                    ? 'Keep description field by default for readability and SEO text coverage.'
                    : '默认至少保留描述字段，保证内容可读与 SEO 文本承载。',
            ];
            if ($template === 'hero' || $template === 'cta') {
                $fields[] = [
                    'field' => 'button_text',
                    'sample' => $this->resolveFieldSample('button_text', $template, $sectionName, $pageGoal, $siteDisplayName, $locale),
                    'reason' => $this->isEnglishLocale($locale)
                        ? 'Hero/CTA blocks should expose an actionable entry by default.'
                        : '首屏/CTA 模块默认需要可执行入口。',
                ];
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBlockExecutionScript(string $template, string $sectionName, string $pageGoal, string $siteDisplayName, string $locale = ''): array
    {
        $siteName = $siteDisplayName !== '' ? $siteDisplayName : ($this->isEnglishLocale($locale) ? 'your brand' : '你的站点');
        if ($template === 'hero') {
            return [
                'feature_points' => $this->isEnglishLocale($locale)
                    ? ['Primary CTA button for APK download', 'Secondary CTA button for rules and policy', 'Hero carousel with 2-3 slides']
                    : ['设置两个大按钮：一个「查看游戏规则与政策」，一个「下载 APK」', '首屏保留主副 CTA 的清晰层级', '使用 2-3 张轮播图承接欢迎与规则说明'],
                'core_copy' => $this->isEnglishLocale($locale)
                    ? ('Welcome to ' . $siteName . ', everything starts from downloading the APK.')
                    : ('欢迎来到 ' . $siteName . ' 的棋牌世界，这里有你想要的一切，一切从下载 APK 开始。'),
                'typography' => $this->isEnglishLocale($locale) ? 'Prefer Songti-style serif for CN headings; body with readable sans-serif.' : '标题可用宋体风格，正文使用高可读无衬线字体。',
                'style_tone' => $this->isEnglishLocale($locale) ? 'Curry-inspired premium look: yellow + gold with luxury accents.' : '咖喱味高级调性：主色偏黄色与金色，突出奢华感。',
                'background_direction' => $this->isEnglishLocale($locale) ? 'Use high-contrast wealthy-jackpot themed background image.' : '背景建议使用“大满贯富豪”氛围图，强化冲击力。',
                'media_assets' => $this->isEnglishLocale($locale) ? ['Slide 1: Welcome visual', 'Slide 2: Rules and policy visual', 'Optional Slide 3: APK benefits visual'] : ['轮播图 1：欢迎氛围图', '轮播图 2：游戏规则说明图', '轮播图 3（可选）：APK 下载收益图'],
            ];
        }

        return [
            'feature_points' => $this->isEnglishLocale($locale)
                ? ['Keep one clear user action per block', 'Ensure text hierarchy supports quick scan']
                : ['每个区块只承载一个核心动作', '信息层级清晰，3 秒内可扫读'],
            'core_copy' => $this->isEnglishLocale($locale)
                ? ('This section supports: ' . $pageGoal)
                : ('本区块围绕以下目标展开：' . $pageGoal),
            'typography' => $this->isEnglishLocale($locale) ? 'Heading medium-bold, body regular for readability.' : '标题中粗体，正文常规字重，优先保证可读性。',
            'style_tone' => $this->isEnglishLocale($locale) ? 'Consistent with site palette and trust-building tone.' : '沿用全站色盘，保持可信且有转化导向的语气。',
            'background_direction' => $this->isEnglishLocale($locale) ? 'Use low-noise background to avoid distracting CTA.' : '背景尽量低干扰，避免与 CTA 竞争注意力。',
            'media_assets' => $this->isEnglishLocale($locale) ? ['One supporting visual for this block intent'] : ['建议至少 1 张与区块意图一致的配图'],
        ];
    }

    private function resolveFieldSample(string $field, string $template, string $sectionName, string $pageGoal, string $siteDisplayName, string $locale = ''): string
    {
        $siteName = $siteDisplayName !== '' ? $siteDisplayName : ($this->isEnglishLocale($locale) ? 'your brand' : '本站');
        if ($field === 'button_text') {
            return $template === 'hero'
                ? ($this->isEnglishLocale($locale) ? 'Download APK Now' : '立即下载 APK')
                : ($this->isEnglishLocale($locale) ? 'Learn More' : '了解更多');
        }
        if ($field === 'button_link') {
            return $template === 'hero' ? '/download-apk' : '/rules-policy';
        }
        if ($field === 'title') {
            return $template === 'hero'
                ? ($this->isEnglishLocale($locale) ? ('Welcome to ' . $siteName) : ('欢迎来到 ' . $siteName . ''))
                : ($this->isEnglishLocale($locale) ? $sectionName : ($sectionName !== '' ? $sectionName : '核心内容'));
        }
        if ($field === 'description') {
            return $this->isEnglishLocale($locale)
                ? ('This section delivers: ' . $pageGoal)
                : ('该区块用于实现：' . $pageGoal);
        }
        if ($field === 'subtitle') {
            return $this->isEnglishLocale($locale) ? 'Rules, trust, and quick action' : '规则透明、体验清晰、立即行动';
        }
        if ($field === 'image') {
            return $template === 'hero' ? 'hero-jackpot-bg.jpg' : 'block-supporting-visual.jpg';
        }
        return '';
    }

    /**
     * @param list<string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        if (\trim($haystack) === '') {
            return false;
        }
        foreach ($needles as $needle) {
            if ($needle !== '' && \mb_stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function clipText(string $text, int $maxLength): string
    {
        $text = \trim($text);
        if ($text === '' || $maxLength <= 0) {
            return '';
        }
        if (\mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return \mb_substr($text, 0, $maxLength) . '…';
    }

    private function slugify(string $text): string
    {
        $text = \mb_strtolower(\trim($text));
        if ($text === '') {
            return 'section';
        }
        $text = (string)\preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
        $text = \trim($text, '-');

        return $text !== '' ? $text : 'section';
    }

    private function getAiService(): AiService
    {
        return $this->aiService ?? ObjectManager::getInstance(AiService::class);
    }

    private function isEnglishLocale(string $locale): bool
    {
        $locale = \strtolower(\trim($locale));
        if ($locale === '') {
            return false;
        }

        return \str_starts_with($locale, 'en');
    }

    /**
     * T5: 方案 Markdown 章节完整性校验器
     * 验证生成的方案 Markdown 是否包含所有必须章节
     *
     * @param string $markdown 方案 Markdown 文本
     * @param string $locale 方案语言
     * @return array{valid: bool, missing_sections: list<string>, warnings: list<string>}
     */
    public function validateMarkdownSections(string $markdown, string $locale = ''): array
    {
        $isEn = $this->isEnglishLocale($locale);
        $markdown = \trim($markdown);
        $missingSections = [];
        $warnings = [];

        // 必须包含的章节（双语对照）
        $requiredSections = [
            'style_overview' => [
                'zh' => ['风格总览', '主题风格', '风格总览'],
                'en' => ['Style Overview', 'Theme Style', 'style overview'],
            ],
            'palette' => [
                'zh' => ['颜色色系', '色系', '调色板', '调色盘', '色彩'],
                'en' => ['Color Palette', 'Palette', 'color palette', '色系'],
            ],
            'header' => [
                'zh' => ['Header 设计', '头部设计', '页头设计'],
                'en' => ['Header Design', 'header design'],
            ],
            'footer' => [
                'zh' => ['Footer 设计', '底部设计', '页脚设计'],
                'en' => ['Footer Design', 'footer design'],
            ],
            'page_types_overview' => [
                'zh' => ['页面类型设计', '页面设计总览', '全站结构'],
                'en' => ['Page Type Design', 'Page Design Overview', 'Site Structure'],
            ],
            'page_block_details' => [
                'zh' => ['页面与区块执行细化', '分页面块级设计', '区块设计'],
                'en' => ['Page And Block Execution Details', 'Block Details'],
            ],
            'execution_order' => [
                'zh' => ['执行顺序', '任务蓝图摘要', '执行顺序与任务蓝图'],
                'en' => ['Execution Order', 'task blueprint'],
            ],
        ];

        // 检查每个必须章节
        foreach ($requiredSections as $sectionKey => $sectionLabels) {
            $labels = $isEn ? $sectionLabels['en'] : $sectionLabels['zh'];
            $found = false;
            foreach ($labels as $label) {
                if (\mb_stripos($markdown, $label) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missingSections[] = $sectionKey;
            }
        }

        // 额外警告检查
        if (\mb_stripos($markdown, '为什么这样设计') === false
            && \mb_stripos($markdown, 'why') === false
            && \mb_stripos($markdown, 'reason') === false
        ) {
            $warnings[] = $isEn
                ? 'Warning: Design rationale (why) not found in plan.'
                : '警告：方案中未找到设计理由（为什么这样设计）。';
        }

        // 检查是否包含页面覆盖清单
        if (\mb_stripos($markdown, '页面覆盖清单') === false
            && \mb_stripos($markdown, 'Page Coverage') === false
            && \mb_stripos($markdown, 'Selected Pages') === false
        ) {
            $warnings[] = $isEn
                ? 'Warning: Page coverage checklist not found.'
                : '警告：未找到页面覆盖清单。';
        }

        return [
            'valid' => $missingSections === [],
            'missing_sections' => $missingSections,
            'warnings' => $warnings,
        ];
    }

    /**
     * 检查方案 Markdown 是否包含指定页面类型的规划
     *
     * @param string $markdown 方案 Markdown
     * @param list<string> $selectedPageTypes 用户选择的页面类型
     * @param string $locale 方案语言
     * @return array{valid: bool, missing_pages: list<string>, extra_pages: list<string>}
     */
    public function validatePageCoverage(string $markdown, array $selectedPageTypes, string $locale = ''): array
    {
        $isEn = $this->isEnglishLocale($locale);
        $markdownLower = \mb_strtolower($markdown);
        $missingPages = [];
        $extraPages = [];

        // 常见页面类型关键词映射
        $pageTypeKeywords = [
            'home_page' => ['首页', 'home page', 'homepage'],
            'about_page' => ['关于页面', 'about', '关于我们'],
            'contact_page' => ['联系页面', 'contact', '联系我们'],
            'product_page' => ['产品页面', 'product', '产品列表'],
            'blog_page' => ['博客页面', 'blog', '博客'],
            'service_page' => ['服务页面', 'service', '服务'],
            'privacy_page' => ['隐私政策', 'privacy'],
            'terms_page' => ['服务条款', 'terms'],
        ];

        // 检查每个已选页面是否有规划
        foreach ($selectedPageTypes as $pageType) {
            $keywords = $pageTypeKeywords[$pageType] ?? [$pageType, \str_replace('_', ' ', $pageType)];
            $found = false;
            foreach ($keywords as $keyword) {
                if (\mb_stripos($markdownLower, \mb_strtolower($keyword)) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missingPages[] = $pageType;
            }
        }

        return [
            'valid' => $missingPages === [],
            'missing_pages' => $missingPages,
            'extra_pages' => $extraPages,
        ];
    }
}
