<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteQualityGateService
{
    /**
     * @var array<string, array{label:string,page_report_field?:string}>
     */
    private const QUALITY_ITEM_SPECS = [
        'build_plan_blocks_done' => ['label' => 'BuildPlan blocks completed'],
        'required_pages_render' => ['label' => 'Required pages render'],
        'shared_blocks_ready' => ['label' => 'Shared header/footer ready', 'page_report_field' => 'shared_blocks'],
        'render_data_quality' => ['label' => 'Render data structure valid'],
    ];

    private readonly AiSiteBuildTaskService $buildTaskService;
    private readonly AiSiteScopeCompatibilityService $scopeCompatibilityService;
    private readonly AiSiteVirtualLayoutService $virtualLayoutService;
    private readonly PageRenderService $pageRenderService;
    private readonly Page $pageModel;

    public function __construct(
        ?AiSiteBuildTaskService $buildTaskService = null,
        ?AiSiteScopeCompatibilityService $scopeCompatibilityService = null,
        ?AiSiteVirtualLayoutService $virtualLayoutService = null,
        ?PageRenderService $pageRenderService = null,
        ?Page $pageModel = null
    ) {
        $this->buildTaskService = $buildTaskService ?? ObjectManager::getInstance(AiSiteBuildTaskService::class);
        $this->scopeCompatibilityService = $scopeCompatibilityService ?? ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
        $this->virtualLayoutService = $virtualLayoutService ?? ObjectManager::getInstance(AiSiteVirtualLayoutService::class);
        $this->pageRenderService = $pageRenderService ?? ObjectManager::getInstance(PageRenderService::class);
        $this->pageModel = $pageModel ?? ObjectManager::getInstance(Page::class);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, string> $renderedHtmlByPageType Optional test/runtime override.
     * @return array{passed:bool,items:list<array<string,mixed>>,page_reports:array<string,array<string,mixed>>}
     */
    public function inspectScope(array $scope, array $renderedHtmlByPageType = []): array
    {
        $pageTypes = $this->resolveRequiredPageTypes($scope);
        $pagesByType = $this->resolvePagesByType($scope);
        $renderVirtualThemeId = $this->resolveRenderVirtualThemeId($scope);
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $pageReports = [];

        $completionGate = $this->buildTaskService->inspectBuildCompletionGate($scope);
        $summary = \is_array($completionGate['summary'] ?? null)
            ? $completionGate['summary']
            : $this->buildTaskService->summarize($scope);
        $summary['completion_gate'] = \array_diff_key($completionGate, ['summary' => true]);

        $allBlocksDone = !empty($completionGate['passed']);
        $requiredPagesReady = $pageTypes !== [];
        $sharedBlocksReady = true;

        foreach ($pageTypes as $pageType) {
            $pageId = (int)($pagesByType[$pageType]['page_id'] ?? 0);
            $html = (string)($renderedHtmlByPageType[$pageType] ?? '');
            $layout = [];
            $renderError = '';

            if (
                $html === ''
                && $workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
                && $renderVirtualThemeId > 0
            ) {
                try {
                    $virtualRender = $this->renderVirtualThemePageForInspection($pageType, $scope, $renderVirtualThemeId);
                    $html = $virtualRender['html'];
                    $layout = $virtualRender['layout'];
                } catch (\Throwable $throwable) {
                    $renderError = $throwable->getMessage();
                }
            }

            if ($html === '' && $pageId > 0) {
                try {
                    $page = clone $this->pageModel;
                    $page->clearData()->clearQuery()->load($pageId);
                    if ($page->getId() > 0) {
                        $layout = $page->getAiLayoutArray();
                        $renderMode = $page->isAiHtmlRenderMode()
                            ? PageRenderService::MODE_PREVIEW
                            : PageRenderService::MODE_LIVE;
                        $html = $this->pageRenderService->render(
                            $page,
                            $renderMode,
                            null,
                            null,
                            $renderVirtualThemeId > 0 ? $renderVirtualThemeId : null
                        );
                        $renderError = '';
                    }
                } catch (\Throwable $throwable) {
                    if ($renderError === '') {
                        $renderError = $throwable->getMessage();
                    }
                }
            }

            if ($layout === [] && \is_array($pagesByType[$pageType]['ai_layout'] ?? null)) {
                $layout = $pagesByType[$pageType]['ai_layout'];
            }
            if ($layout === [] && \is_array($scope['virtual_pages_by_type'][$pageType]['blocks'] ?? null)) {
                $layout = ['blocks' => $scope['virtual_pages_by_type'][$pageType]['blocks']];
            }

            $report = $this->inspectRenderedPage($pageType, $html, $layout, $pageId, $renderError);
            $pageReports[$pageType] = $report;

            $hasRenderableVirtualDraft = $workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
                && $renderVirtualThemeId > 0
                && \is_array($scope['virtual_pages_by_type'][$pageType] ?? null);
            $requiredPagesReady = $requiredPagesReady
                && $html !== ''
                && $renderError === ''
                && ($pageId > 0 || $hasRenderableVirtualDraft || \array_key_exists($pageType, $renderedHtmlByPageType));
            $sharedBlocksReady = $sharedBlocksReady && (bool)($report['shared_blocks_ready'] ?? false);
            unset($html, $layout, $report);
        }

        $renderData = $this->collectStructuralRenderDataFindings($scope);
        foreach ($pageReports as $pageType => $report) {
            foreach (\is_array($report['structure_issues'] ?? null) ? $report['structure_issues'] : [] as $issue) {
                $renderData['findings'][] = [
                    'severity' => 'error',
                    'category' => 'structure',
                    'rule' => 'design.malformed_html',
                    'message' => (string)$pageType . ': ' . (string)$issue,
                    'target_path' => 'rendered_html.' . (string)$pageType,
                ];
                ++$renderData['error_count'];
                $renderData['ok'] = false;
                $renderData['evaluated'] = true;
            }
        }
        $items = $this->normalizeQualityItems([
            $this->buildItem('build_plan_blocks_done', $allBlocksDone, $summary),
            $this->buildItem('required_pages_render', $requiredPagesReady, \array_keys($pageReports)),
            $this->buildItem('shared_blocks_ready', $sharedBlocksReady, $this->extractPageValues($pageReports, 'shared_blocks')),
            $this->buildItem('render_data_quality', $renderData['ok'], $renderData),
        ], $pageReports);

        $passed = true;
        foreach ($items as $item) {
            if (!empty($item['blocking']) && empty($item['ok'])) {
                $passed = false;
                break;
            }
        }

        return [
            'passed' => $passed,
            'items' => $items,
            'page_reports' => $pageReports,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, string> $renderedHtmlByPageType Optional test/runtime override.
     * @return array{passed:bool,items:list<array<string,mixed>>,page_reports:array<string,array<string,mixed>>,quality_gate:array<string,mixed>}
     */
    public function inspectBuildReadinessGate(array $scope, array $renderedHtmlByPageType = []): array
    {
        $qualityGate = $this->inspectScope($scope, $renderedHtmlByPageType);

        return [
            'passed' => (bool)($qualityGate['passed'] ?? false),
            'items' => \is_array($qualityGate['items'] ?? null) ? $qualityGate['items'] : [],
            'page_reports' => \is_array($qualityGate['page_reports'] ?? null) ? $qualityGate['page_reports'] : [],
            'quality_gate' => $qualityGate,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function renderPageHtmlForInspection(array $scope, string $pageType): string
    {
        $pageType = \trim($pageType);
        $pagesByType = $this->resolvePagesByType($scope);
        $renderVirtualThemeId = $this->resolveRenderVirtualThemeId($scope);
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $pageId = (int)($pagesByType[$pageType]['page_id'] ?? 0);
        $html = '';
        $renderError = '';

        if (
            $workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
            && $renderVirtualThemeId > 0
        ) {
            try {
                $html = $this->renderVirtualThemePageForInspection($pageType, $scope, $renderVirtualThemeId)['html'];
            } catch (\Throwable $throwable) {
                $renderError = $throwable->getMessage();
            }
        }

        if ($html === '' && $pageId > 0) {
            try {
                $page = clone $this->pageModel;
                $page->clearData()->clearQuery()->load($pageId);
                if ($page->getId() > 0) {
                    $renderMode = $page->isAiHtmlRenderMode()
                        ? PageRenderService::MODE_PREVIEW
                        : PageRenderService::MODE_LIVE;
                    $html = $this->pageRenderService->render(
                        $page,
                        $renderMode,
                        null,
                        null,
                        $renderVirtualThemeId > 0 ? $renderVirtualThemeId : null
                    );
                    $renderError = '';
                }
            } catch (\Throwable $throwable) {
                if ($renderError === '') {
                    $renderError = $throwable->getMessage();
                }
            }
        }

        if ($html === '' && $renderError !== '') {
            throw new \RuntimeException($renderError);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function resolveRequiredPageTypes(array $scope): array
    {
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if ($pageTypes !== []) {
            return $pageTypes;
        }

        $found = [];
        $buildPlan = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        foreach (\is_array($buildPlan['pages'] ?? null) ? $buildPlan['pages'] : [] as $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? $page['type'] ?? ''));
            if ($pageType !== '') {
                $found[$pageType] = true;
            }
        }
        foreach (\is_array($buildPlan['blocks'] ?? null) ? $buildPlan['blocks'] : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $pageType = \trim((string)($block['page_type'] ?? ''));
            if ($pageType !== '') {
                $found[$pageType] = true;
            }
        }

        return \array_keys($found);
    }

    /**
     * @return array{page_id:int,rendered:bool,render_error:string,structure_clean:bool,structure_issues:list<string>,shared_blocks_ready:bool,shared_blocks:array{header:bool,footer:bool}}
     */
    private function inspectRenderedPage(
        string $pageType,
        string $html,
        array $layout,
        int $pageId,
        string $renderError
    ): array {
        $sharedBlocks = $this->detectSharedBlocks($layout, $html);
        $structureIssues = $this->matchMalformedGeneratedHtml($html);

        return [
            'page_type' => $pageType,
            'page_id' => $pageId,
            'rendered' => $html !== '' && $renderError === '',
            'render_error' => $renderError,
            'structure_clean' => $structureIssues === [],
            'structure_issues' => $structureIssues,
            'shared_blocks_ready' => !empty($sharedBlocks['header']) && !empty($sharedBlocks['footer']),
            'shared_blocks' => $sharedBlocks,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{html:string,layout:array<string,mixed>}
     */
    private function renderVirtualThemePageForInspection(string $pageType, array $scope, int $virtualThemeId): array
    {
        $pageTypes = $this->resolveRequiredPageTypes($scope);
        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        if (!\is_array($virtualPages[$pageType] ?? null)) {
            $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope, false);
        }
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        if ($virtualPage === []) {
            throw new \RuntimeException('Virtual page is missing for quality inspection: ' . $pageType);
        }

        $layout = $this->virtualLayoutService->getResolvedLayout($virtualThemeId, $pageType);
        $scopeLayout = \is_array($scope['page_type_layouts'][$pageType] ?? null)
            ? $this->scopeCompatibilityService->normalizeLayoutConfig($scope['page_type_layouts'][$pageType], $pageType)
            : [];
        if ($this->shouldUseScopeLayoutForInspection($layout, $scopeLayout)) {
            $layout = $scopeLayout;
        }

        $styleCode = \trim((string)($virtualPage['style_code'] ?? 'default'));
        $styleCode = $styleCode !== '' ? $styleCode : 'default';
        $locale = \trim((string)($virtualPage['locale'] ?? \Weline\Framework\App\State::getLang()));
        $locale = $locale !== '' ? $locale : \Weline\Framework\App\State::getLang();
        $virtualBlocks = \is_array($virtualPage['blocks'] ?? null) ? $virtualPage['blocks'] : [];
        $renderMode = $virtualBlocks === [] ? Page::RENDER_MODE_THEME : Page::RENDER_MODE_AI_HTML;

        $page = ObjectManager::make(Page::class);
        $page->setData([
            Page::schema_fields_ID => 0,
            Page::schema_fields_WEBSITE_ID => (int)($scope['draft_website_id'] ?? ($scope['website_id'] ?? 0)),
            Page::schema_fields_PARENT_ID => $pageType === Page::TYPE_HOME ? 0 : 1,
            Page::schema_fields_LAYOUT_PAGE_ID => 0,
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
            Page::schema_fields_TITLE => (string)($virtualPage['title'] ?? ''),
            Page::schema_fields_NAME => (string)($virtualPage['title'] ?? ''),
            Page::schema_fields_HANDLE => (string)($virtualPage['handle'] ?? ''),
            Page::schema_fields_STYLE => $styleCode,
            Page::schema_fields_TYPE => $pageType,
            Page::schema_fields_CONTENT => '',
            Page::schema_fields_META_TITLE => (string)($virtualPage['meta_title'] ?? ''),
            Page::schema_fields_META_DESCRIPTION => (string)($virtualPage['meta_description'] ?? ''),
            Page::schema_fields_META_KEYWORDS => (string)($virtualPage['meta_keywords'] ?? ''),
            Page::schema_fields_AI_DESCRIPTION => (string)($virtualPage['ai_description'] ?? ''),
            Page::schema_fields_LOCALES => \json_encode([$locale], \JSON_UNESCAPED_UNICODE),
            Page::schema_fields_DEFAULT_LOCALE => $locale,
            Page::schema_fields_STYLE_SETTING => \json_encode([
                $styleCode => \is_array($virtualPage['style_settings'] ?? null) ? $virtualPage['style_settings'] : [],
            ], \JSON_UNESCAPED_UNICODE),
            Page::schema_fields_LAYOUT_CONFIG => \json_encode($layout, \JSON_UNESCAPED_UNICODE),
            Page::schema_fields_RENDER_MODE => $renderMode,
            Page::schema_fields_AI_LAYOUT => \json_encode(['blocks' => $virtualBlocks], \JSON_UNESCAPED_UNICODE),
        ]);
        $page->setData('virtual_theme_id', $virtualThemeId);
        $page->setData('virtual_page_type', $pageType);
        $page->setData('virtual_layout_config', $layout);
        $page->setData('virtual_pages_by_type', $virtualPages);

        return [
            'html' => $this->pageRenderService->render(
                $page,
                PageRenderService::MODE_PREVIEW,
                $locale,
                $styleCode,
                $virtualThemeId
            ),
            'layout' => $layout,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, array<string, mixed>>
     */
    private function resolvePagesByType(array $scope): array
    {
        foreach (['pagebuilder_pages_by_type', 'materialized_pages_by_type'] as $key) {
            if (\is_array($scope[$key] ?? null) && $scope[$key] !== []) {
                return $scope[$key];
            }
        }

        return [];
    }

    private function resolveRenderVirtualThemeId(array $scope): int
    {
        return \max(0, (int)($scope['virtual_theme_id'] ?? 0));
    }

    /**
     * @param array<string,mixed> $resolvedLayout
     * @param array<string,mixed> $scopeLayout
     */
    private function shouldUseScopeLayoutForInspection(array $resolvedLayout, array $scopeLayout): bool
    {
        $scopeContent = \is_array($scopeLayout['content'] ?? null) ? $scopeLayout['content'] : [];
        if ($scopeContent === []) {
            return false;
        }
        $resolvedContent = \is_array($resolvedLayout['content'] ?? null) ? $resolvedLayout['content'] : [];
        if (\count($resolvedContent) < \count($scopeContent)) {
            return true;
        }

        $resolvedCodes = [];
        foreach ($resolvedContent as $row) {
            if (\is_array($row)) {
                $code = \trim((string)($row['code'] ?? $row['component'] ?? ''));
                if ($code !== '') {
                    $resolvedCodes[$code] = true;
                }
            }
        }
        foreach ($scopeContent as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = \trim((string)($row['code'] ?? $row['component'] ?? ''));
            if ($code !== '' && !isset($resolvedCodes[$code])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $layout
     * @return array{header:bool,footer:bool}
     */
    private function detectSharedBlocks(array $layout, string $html): array
    {
        $blocks = \is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];
        if ($blocks === [] && \is_array($layout['content'] ?? null)) {
            $blocks = $layout['content'];
        }
        $header = false;
        $footer = false;
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $identity = \strtolower(\implode(' ', [
                (string)($block['block_id'] ?? ''),
                (string)($block['type'] ?? ''),
                (string)($block['code'] ?? ''),
                (string)($block['component'] ?? ''),
                (string)($block['region'] ?? ''),
            ]));
            $header = $header || \str_contains($identity, 'header');
            $footer = $footer || \str_contains($identity, 'footer');
        }

        return [
            'header' => $header || \stripos($html, '<header') !== false || \stripos($html, 'data-region="header"') !== false,
            'footer' => $footer || \stripos($html, '<footer') !== false || \stripos($html, 'data-region="footer"') !== false,
        ];
    }

    /**
     * @return list<string>
     */
    private function matchMalformedGeneratedHtml(string $html): array
    {
        if (\trim($html) === '') {
            return ['empty_html'];
        }

        $patterns = [
            'malformed_opening_tag' => '/<\s+(?:class|id|style|href|src|alt|title|role|aria-[a-z0-9_-]+|data-[a-z0-9_-]+)\s*=/iu',
            'invalid_class_tag' => '/<\/class>/iu',
            'malformed_aria_or_data_attribute' => '/["\']-(?:hidden|label|expanded|controls|pressed|selected|current|describedby|labelledby)\s*=/iu',
        ];
        $matches = [];
        foreach ($patterns as $label => $pattern) {
            if (\preg_match($pattern, $html) === 1) {
                $matches[] = $label;
            }
        }

        return $matches;
    }

    /**
     * @param array<string,array<string,mixed>> $pageReports
     * @return array<string,mixed>
     */
    private function extractPageValues(array $pageReports, string $key): array
    {
        $values = [];
        foreach ($pageReports as $pageType => $report) {
            $values[(string)$pageType] = $report[$key] ?? null;
        }

        return $values;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildItem(string $key, bool $ok, mixed $value): array
    {
        return [
            'key' => $key,
            'label' => (string)(self::QUALITY_ITEM_SPECS[$key]['label'] ?? $key),
            'ok' => $ok,
            'blocking' => true,
            'level' => $ok ? 'pass' : 'error',
            'value' => $value,
            'detail' => $this->qualityItemDetail($key, $ok, $value),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string,array<string,mixed>> $pageReports
     * @return list<array<string,mixed>>
     */
    private function normalizeQualityItems(array $items, array $pageReports): array
    {
        $byKey = [];
        foreach ($items as $item) {
            if (\is_array($item) && isset(self::QUALITY_ITEM_SPECS[(string)($item['key'] ?? '')])) {
                $byKey[(string)$item['key']] = $item;
            }
        }

        $normalized = [];
        foreach (self::QUALITY_ITEM_SPECS as $key => $spec) {
            $item = \is_array($byKey[$key] ?? null) ? $byKey[$key] : $this->buildItem($key, false, null);
            $pageReportField = \trim((string)($spec['page_report_field'] ?? ''));
            if ($pageReportField !== '') {
                $item['value'] = $this->extractPageValues($pageReports, $pageReportField);
            }
            $item['label'] = (string)$spec['label'];
            $item['blocking'] = true;
            $item['level'] = !empty($item['ok']) ? 'pass' : 'error';
            $item['detail'] = $this->qualityItemDetail($key, !empty($item['ok']), $item['value'] ?? null);
            $normalized[] = $item;
        }

        return $normalized;
    }

    private function qualityItemDetail(string $key, bool $ok, mixed $value): string
    {
        if ($ok) {
            return match ($key) {
                'build_plan_blocks_done' => 'All BuildPlan blocks are complete.',
                'required_pages_render' => 'All required pages rendered.',
                'shared_blocks_ready' => 'Shared header and footer are present.',
                'render_data_quality' => 'Render data passed structure checks.',
                default => '',
            };
        }

        return match ($key) {
            'build_plan_blocks_done' => $this->formatBuildPlanBlocksFailureDetail(\is_array($value) ? $value : []),
            'required_pages_render' => 'At least one required page is missing or failed to render.',
            'shared_blocks_ready' => 'Generated pages must include shared header and footer blocks.',
            'render_data_quality' => $this->formatStructuralFindingsFailureDetail(\is_array($value) ? $value : []),
            default => '',
        };
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function formatBuildPlanBlocksFailureDetail(array $summary): string
    {
        $total = (int)($summary['total'] ?? 0);
        $done = (int)($summary['done'] ?? 0);
        $pending = (int)($summary['pending'] ?? 0);
        $running = (int)($summary['running'] ?? 0);
        $failed = (int)($summary['failed'] ?? 0);
        if ($failed > 0) {
            return "BuildPlan has {$failed}/{$total} failed block(s).";
        }
        if ($pending > 0 || $running > 0) {
            return "BuildPlan is not finished: {$done}/{$total} done, {$pending} pending, {$running} running.";
        }

        return 'BuildPlan has no completed executable block evidence.';
    }

    /**
     * @param array<string,mixed> $bucket
     */
    private function formatStructuralFindingsFailureDetail(array $bucket): string
    {
        $messages = [];
        foreach (\is_array($bucket['findings'] ?? null) ? $bucket['findings'] : [] as $finding) {
            if (!\is_array($finding) || \mb_strtolower((string)($finding['severity'] ?? '')) !== 'error') {
                continue;
            }
            $message = \trim((string)($finding['message'] ?? $finding['rule'] ?? $finding['target_path'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return $messages === []
            ? 'Render data structure has blocking issues.'
            : \implode('; ', \array_slice(\array_values(\array_unique($messages)), 0, 4));
    }

    /**
     * @param array<string,mixed> $scope
     * @return array{ok:bool,error_count:int,warning_count:int,findings:list<array<string,mixed>>,evaluated:bool}
     */
    private function collectStructuralRenderDataFindings(array $scope): array
    {
        $preflightError = \trim((string)($scope['quality_gate_preflight_error'] ?? ''));
        $qaReport = \is_array($scope['qa_report_contract'] ?? null) ? $scope['qa_report_contract'] : [];
        $payload = \is_array($qaReport['payload'] ?? null) ? $qaReport['payload'] : [];
        $structureQuality = \is_array($payload['structure_quality'] ?? null) ? $payload['structure_quality'] : [];
        $findings = \is_array($structureQuality['findings'] ?? null) ? $structureQuality['findings'] : [];

        if ($preflightError !== '') {
            $findings[] = [
                'severity' => 'error',
                'category' => 'structure',
                'rule' => 'render_data.preflight_failed',
                'message' => $preflightError,
                'target_path' => 'quality_gate_preflight_error',
            ];
        }

        $structural = [];
        foreach ($findings as $finding) {
            if (\is_array($finding) && $this->isStructuralRenderDataFinding($finding)) {
                $structural[] = $finding;
            }
        }

        $errorCount = 0;
        $warningCount = 0;
        foreach ($structural as $finding) {
            $severity = \mb_strtolower(\trim((string)($finding['severity'] ?? '')));
            if ($severity === 'error') {
                ++$errorCount;
            } elseif ($severity === 'warning') {
                ++$warningCount;
            }
        }

        return [
            'ok' => $errorCount === 0,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'findings' => $structural,
            'evaluated' => $preflightError !== ''
                || ($structureQuality['status'] ?? 'not_evaluated') !== 'not_evaluated'
                || $findings !== [],
        ];
    }

    /**
     * @param array<string, mixed> $finding
     */
    private function isStructuralRenderDataFinding(array $finding): bool
    {
        $rule = \mb_strtolower(\trim((string)($finding['rule'] ?? '')));
        $path = \mb_strtolower(\trim((string)($finding['path'] ?? $finding['target_path'] ?? '')));
        $category = \mb_strtolower(\trim((string)($finding['category'] ?? '')));

        return $category === 'structure'
            || \str_starts_with($rule, 'structure.')
            || \str_starts_with($rule, 'design.missing_page_layouts')
            || \str_starts_with($rule, 'design.empty_page_layout')
            || \str_starts_with($rule, 'design.missing_section_identity')
            || \str_starts_with($rule, 'design.malformed_html')
            || \str_starts_with($rule, 'render_data.preflight_failed')
            || \str_starts_with($path, 'payload.page_type_layouts');
    }
}
