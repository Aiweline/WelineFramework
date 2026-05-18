<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthCoverageLinter;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteQualityGateService
{
    /**
     * @var array<string, array{label:string,page_report_field?:string}>
     */
    private const QUALITY_ITEM_SPECS = [
        'build_tasks_done' => ['label' => '构建任务全部完成'],
        'task_coverage' => ['label' => '已确认方案的所有区块已拆成构建任务'],
        'required_pages_render' => ['label' => '关键页面可渲染'],
        'shared_blocks_ready' => ['label' => '共享 Header/Footer 已就绪', 'page_report_field' => 'shared_blocks'],
        'source_truth_coverage' => ['label' => '必含事实/必需区块/禁忌规则均已覆盖'],
        'render_data_quality' => ['label' => '渲染数据契约通过结构/文案/SEO 校验'],
        'content_quality' => ['label' => '页面无内部标识/方案说明/demo 文案', 'page_report_field' => 'bad_matches'],
        'stage1_content_visible' => ['label' => '页面包含阶段一确认内容', 'page_report_field' => 'stage1_hits'],
        'theme_visible' => ['label' => '页面包含阶段一主题色/视觉 token', 'page_report_field' => 'theme_hits'],
        'visual_assets_safe' => ['label' => '图片资源无破图且真实图片插槽已生成并被页面使用', 'page_report_field' => 'visuals'],
        'visual_depth' => ['label' => '页面块具备视觉层次与美术分层', 'page_report_field' => 'visual_depth_signals'],
        'language_consistency' => ['label' => '每个块使用指定网站语言', 'page_report_field' => 'language_violations'],
        'responsive_support' => ['label' => '页面具备 AI 生成的响应式支持', 'page_report_field' => 'responsive_signals'],
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
        $isFakeMode = (int)($scope['fake_mode'] ?? 0) === 1;
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $pagesByType = $this->resolvePagesByType($scope);
        $renderVirtualThemeId = $this->resolveRenderVirtualThemeId($scope);
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $pageReports = [];

        $completionGate = $this->buildTaskService->inspectBuildCompletionGate($scope);
        $taskSummary = \is_array($completionGate['summary'] ?? null)
            ? $completionGate['summary']
            : $this->buildTaskService->summarize($scope);
        $taskSummary['completion_gate'] = \array_diff_key($completionGate, ['summary' => true]);
        $allTasksDone = !empty($completionGate['passed']);
        $requiredPagesReady = $pageTypes !== [];
        $allContentClean = true;
        $allStageOneContentVisible = true;
        $allThemeVisible = true;
        $allVisualsSafe = true;
        $allVisualDepth = true;
        $allLanguageConsistent = true;
        $allResponsive = true;
        $sharedBlocksReady = true;

        foreach ($pageTypes as $pageType) {
            $pageId = (int)($pagesByType[$pageType]['page_id'] ?? 0);
            $hasRenderedOverride = \array_key_exists($pageType, $renderedHtmlByPageType)
                && \trim((string)$renderedHtmlByPageType[$pageType]) !== '';
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

            if (
                ($html === '' || ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME && !$hasRenderedOverride))
                && $pageId > 0
            ) {
                try {
                    $page = clone $this->pageModel;
                    $page->clearData()->clearQuery()->load($pageId);
                    if ($page->getId() > 0) {
                        $layout = $page->getAiLayoutArray();
                        // Pre-publish checks must inspect the current AI draft, not a stale live snapshot.
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

            $report = $this->inspectRenderedPage($pageType, $html, $layout, $scope, $pageId, $renderError);
            $pageReports[$pageType] = $report;

            $hasRenderableVirtualDraft = $workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
                && $renderVirtualThemeId > 0
                && \is_array($scope['virtual_pages_by_type'][$pageType] ?? null);
            $requiredPagesReady = $requiredPagesReady && $html !== '' && $renderError === '' && ($pageId > 0 || $hasRenderableVirtualDraft);
            $allContentClean = $allContentClean && (bool)($report['content_clean'] ?? false);
            $allStageOneContentVisible = $allStageOneContentVisible && (bool)($report['stage1_content_visible'] ?? false);
            $allThemeVisible = $allThemeVisible && (bool)($report['theme_visible'] ?? false);
            $allVisualsSafe = $allVisualsSafe && (bool)($report['visuals_safe'] ?? false);
            $allVisualDepth = $allVisualDepth && (bool)($report['visual_depth_ok'] ?? false);
            $allLanguageConsistent = $allLanguageConsistent && (bool)($report['language_ok'] ?? false);
            $allResponsive = $allResponsive && (bool)($report['responsive_ok'] ?? false);
            $sharedBlocksReady = $sharedBlocksReady && (bool)($report['shared_blocks_ready'] ?? false);
        }

        $taskCoverageReport = $this->buildTaskCoverageReport($scope);
        if ($taskCoverageReport['evaluated']) {
            $sharedScheduled = \is_array($taskCoverageReport['shared'] ?? null) ? $taskCoverageReport['shared'] : [];
            if ($this->buildBlueprintExpectsSharedComponent($scope, 'header')) {
                $sharedBlocksReady = $sharedBlocksReady && !empty($sharedScheduled['header']);
            }
            if ($this->buildBlueprintExpectsSharedComponent($scope, 'footer')) {
                $sharedBlocksReady = $sharedBlocksReady && !empty($sharedScheduled['footer']);
            }
        }
        $qaReportFindings = $this->collectQaReportFindings($scope);

        $items = $this->normalizeQualityItems([
            $this->buildItem('build_tasks_done', '构建任务全部完成', $allTasksDone, $taskSummary),
            $this->buildItem('task_coverage', '已确认方案的所有区块已拆成构建任务', $taskCoverageReport['ok'], $taskCoverageReport),
            $this->buildItem('required_pages_render', '关键页面可渲染', $requiredPagesReady, \array_keys($pageReports)),
            $this->buildItem('shared_blocks_ready', '共享 Header/Footer 已就绪', $sharedBlocksReady, $this->extractPageValues($pageReports, 'shared_blocks')),
            $this->buildItem('source_truth_coverage', '必含事实/必需区块/禁忌规则均已覆盖', $qaReportFindings['source_truth_ok'], $qaReportFindings['source_truth']),
            $this->buildItem('render_data_quality', '渲染数据契约通过结构/文案/SEO 校验', $qaReportFindings['render_data_ok'], $qaReportFindings['render_data']),
            $this->buildItem('content_quality', '页面无内部标识/方案说明/demo 文案', $allContentClean, $this->extractPageValues($pageReports, 'bad_matches')),
            $this->buildItem('stage1_content_visible', '页面包含阶段一确认内容', $allStageOneContentVisible, $this->extractPageValues($pageReports, 'stage1_hits')),
            $this->buildItem('theme_visible', '页面包含阶段一主题色/视觉 token', $allThemeVisible, $this->extractPageValues($pageReports, 'theme_hits')),
            $this->buildItem('visual_assets_safe', '图片资源无破图且真实图片插槽已生成并被页面使用', $allVisualsSafe, $this->extractPageValues($pageReports, 'visuals')),
            $this->buildItem('visual_depth', '页面块具备视觉层次与美术分层', $allVisualDepth, $this->extractPageValues($pageReports, 'visual_depth_signals')),
            $this->buildItem('language_consistency', '每个块使用指定网站语言', $allLanguageConsistent, $this->extractPageValues($pageReports, 'language_violations')),
            $this->buildItem('responsive_support', '页面具备 AI 生成的响应式支持', $allResponsive, $this->extractPageValues($pageReports, 'responsive_signals')),
        ], $pageReports);

        $items = $this->finalizeQualityItems($items, $scope);

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
     * Inspect only the content-side quality gates. Image assets and visual depth
     * are intentionally excluded so content failures cannot be masked by images.
     *
     * @param array<string, mixed> $scope
     * @param array<string, string> $renderedHtmlByPageType Optional test/runtime override.
     * @return array{passed:bool,items:list<array<string,mixed>>,page_reports:array<string,array<string,mixed>>,quality_gate:array<string,mixed>}
     */
    public function inspectContentGate(array $scope, array $renderedHtmlByPageType = []): array
    {
        $qualityGate = $this->inspectScope($scope, $renderedHtmlByPageType);
        $contentKeys = [
            'build_tasks_done' => true,
            'task_coverage' => true,
            'required_pages_render' => true,
            'shared_blocks_ready' => true,
            'source_truth_coverage' => true,
            'render_data_quality' => true,
            'content_quality' => true,
            'stage1_content_visible' => true,
            'theme_visible' => true,
            'language_consistency' => true,
        ];
        $items = [];
        foreach (\is_array($qualityGate['items'] ?? null) ? $qualityGate['items'] : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $key = (string)($item['key'] ?? '');
            if (isset($contentKeys[$key])) {
                $items[] = $item;
            }
        }

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
            'page_reports' => \is_array($qualityGate['page_reports'] ?? null) ? $qualityGate['page_reports'] : [],
            'quality_gate' => $qualityGate,
        ];
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function buildTaskSummaryIsFullyComplete(array $summary): bool
    {
        $total = (int)($summary['total'] ?? 0);
        if ($total <= 0) {
            return false;
        }

        return (int)($summary['done'] ?? 0) >= $total
            && (int)($summary['pending'] ?? 0) === 0
            && (int)($summary['running'] ?? 0) === 0
            && (int)($summary['failed'] ?? 0) === 0
            && (int)($summary['cancelled'] ?? 0) === 0;
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function inspectRenderedPage(
        string $pageType,
        string $html,
        array $layout,
        array $scope,
        int $pageId,
        string $renderError
    ): array {
        $layoutJson = (string)\json_encode($layout, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        $combined = $html . "\n" . $layoutJson;
        $badMatches = \array_merge(
            $this->matchBadContent($this->stripNonVisibleQualityGateHtml($html)),
            $this->matchMalformedGeneratedHtml($html)
        );
        if (\preg_match('/\bai-site-fallback\b|<svg\s+[^>]*viewBox=["\']0 0 520 360["\']|Image Placeholder|Text-to-image is not connected yet/iu', $html) === 1) {
            $badMatches[] = 'plan-derived fallback visual';
        }
        $brokenImages = $this->matchBrokenImages($combined, $this->extractVerifiedAssetUrlsFromScope($scope));
        $legacyBlocks = $this->matchLegacyDefaultBlocks($layout);
        $stageOneHits = $this->matchStageOneContent($pageType, $html, $scope);
        $themeHits = $this->matchThemeTokens($html, $scope);
        $sharedBlocks = $this->detectSharedBlocks($layout, $html);
        $expectedLocale = $this->resolveExpectedContentLocale($scope, $pageType);
        $languageViolations = $this->matchLanguageViolations($html, $expectedLocale, $scope);
        $responsiveSignals = $this->matchResponsiveSignals($html);
        $hasSvgVisual = \preg_match('/<svg\b|data:image\/svg\+xml|ai-site-svg-visual/i', $html) === 1;
        $hasAnyImageNeed = \preg_match('/\b(?:image|visual|media|asset|logo|icon|cards?|board|avatar|shield)\b/iu', $combined) === 1;
        $visualDepthSignals = $this->matchVisualDepthSignals($html);
        $requiredRealImageSlots = $this->extractRequiredRealImageSlotsFromScope($scope, $pageType);
        $requiredRealImageSlotIds = \array_keys($requiredRealImageSlots);
        $usedRequiredImageSlotIds = $this->matchUsedRequiredImageSlotIds($html, $requiredRealImageSlots);
        $unresolvedRequiredImageSlotIds = $this->matchUnresolvedRequiredImageSlotIds($requiredRealImageSlots);
        $missingRequiredImageSlotIds = \array_values(\array_unique(\array_merge(
            \array_diff($requiredRealImageSlotIds, $usedRequiredImageSlotIds),
            $unresolvedRequiredImageSlotIds
        )));
        $realAssetUrls = $this->extractRealVerifiedAssetUrlsFromScope($scope, $pageType);
        $usedRealAssetUrls = $this->matchUsedVerifiedAssets($html, $realAssetUrls);
        $requiresRealImageAssets = $requiredRealImageSlotIds !== [];
        $visualsSafe = $brokenImages === []
            && (!$hasAnyImageNeed || $hasSvgVisual || \preg_match('/<img\b/i', $html) === 1)
            && (!$requiresRealImageAssets || $missingRequiredImageSlotIds === []);

        return [
            'page_id' => $pageId,
            'rendered' => $html !== '' && $renderError === '',
            'render_error' => $renderError,
            'content_clean' => $badMatches === [] && $legacyBlocks === [],
            'bad_matches' => \array_values(\array_unique(\array_merge($badMatches, $legacyBlocks))),
            'stage1_content_visible' => $stageOneHits !== [],
            'stage1_hits' => $stageOneHits,
            'theme_visible' => $themeHits !== [],
            'theme_hits' => $themeHits,
            'shared_blocks_ready' => !empty($sharedBlocks['header']) && !empty($sharedBlocks['footer']),
            'shared_blocks' => $sharedBlocks,
            'visuals_safe' => $visualsSafe,
            'visuals' => [
                'broken_images' => $brokenImages,
                'has_svg_visual' => $hasSvgVisual,
                'has_image_need' => $hasAnyImageNeed,
                'requires_real_image_assets' => $requiresRealImageAssets,
                'required_real_image_slot_ids' => $requiredRealImageSlotIds,
                'used_required_image_slot_ids' => $usedRequiredImageSlotIds,
                'unresolved_required_image_slot_ids' => $unresolvedRequiredImageSlotIds,
                'missing_required_image_slot_ids' => $missingRequiredImageSlotIds,
                'real_asset_urls' => $realAssetUrls,
                'used_real_asset_urls' => $usedRealAssetUrls,
            ],
            'visual_depth_ok' => \count($visualDepthSignals) >= 3,
            'visual_depth_signals' => $visualDepthSignals,
            'language_ok' => $languageViolations === [],
            'expected_locale' => $expectedLocale,
            'language_violations' => $languageViolations,
            'responsive_ok' => isset($responsiveSignals['media_query']) && \count($responsiveSignals) >= 4,
            'responsive_signals' => $responsiveSignals,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{html:string,layout:array<string,mixed>}
     */
    private function renderVirtualThemePageForInspection(string $pageType, array $scope, int $virtualThemeId): array
    {
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        if (!\is_array($virtualPages[$pageType] ?? null)) {
            $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope, false);
        }
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        if ($virtualPage === []) {
            throw new \RuntimeException('Virtual page is missing for publish quality inspection: ' . $pageType);
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

    private function stripNonVisibleQualityGateHtml(string $html): string
    {
        $html = \preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = \preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html) ?? $html;
        $html = \preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = \preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = \preg_replace('/<title\b[^>]*>.*?<\/title>/is', '', $html) ?? $html;

        $visibleAttributeValues = [];
        if (
            \preg_match_all(
                '/\s(?:alt|title|aria-label|placeholder|value)\s*=\s*(["\'])(.*?)\1/isu',
                $html,
                $quotedAttributes
            ) > 0
        ) {
            foreach ($quotedAttributes[2] ?? [] as $value) {
                $value = \trim((string)$value);
                if ($value !== '') {
                    $visibleAttributeValues[] = $value;
                }
            }
        }
        if (
            \preg_match_all(
                '/\s(?:alt|title|aria-label|placeholder|value)\s*=\s*([^\s>]+)/isu',
                $html,
                $unquotedAttributes
            ) > 0
        ) {
            foreach ($unquotedAttributes[1] ?? [] as $value) {
                $value = \trim((string)$value, " \t\n\r\0\x0B\"'");
                if ($value !== '') {
                    $visibleAttributeValues[] = $value;
                }
            }
        }

        $visibleText = \preg_replace('/<[^>]+>/u', ' ', $html) ?? $html;
        $combined = \trim($visibleText . ' ' . \implode(' ', $visibleAttributeValues));
        $combined = \html_entity_decode($combined, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return \preg_replace('/\s+/u', ' ', $combined) ?? $combined;
    }

    /**
     * @return list<string>
     */
    private function matchBadContent(string $text): array
    {
        $patterns = [
            '/\b(?:Introduce|Build|Answer|Close|Educate|Encourage|Reassure|Remove)\s+(?:brand|common|the|users?|visitors?|trust|confidence|barriers|questions?|categories|testimonials?|page|story|mission|support|risks?|options?)\b.{0,120}\b(?:trust|confidence|proof|barriers|endpoint|exploration|users?|visitors?|page|categories|testimonials?|questions?|support|story|mission)\b/iu',
            '/\bShowcase\s+(?:trust|confidence|proof|barriers|categories|testimonials?|questions?|features?|page|users?|visitors?)\b.{0,120}\b(?:trust|confidence|proof|barriers|endpoint|exploration|users?|visitors?|page|categories|testimonials?|questions?|features?)\b/iu',
            '/plan-derived fallback visual|ai-site-fallback|Image Placeholder|Text-to-image is not connected yet/iu',
            '/AI_GENERATED_SECTION|task_key|section_code|block_key|page_type|field_content_requirements/iu',
            '/\b(?:websiteProfile|Website\s+Profile|site\s+profile|target_domain)\b/iu',
            '/\b(?:Introduce|Answer|Reassure|Remove|Educate|Encourage|Close)\b.{0,120}\b(?:brand|story|mission|testimonials?|questions?|barriers?|licenses?|certifications?|badges?|trust|proof|support|download|CTA|users?|visitors?)\b/iu',
            '/content\/(?:home|about|contact|product|service)-page-[a-z0-9_-]+/iu',
            '/(?:方案|蓝图|任务|本区块|本模块|页面节奏|设计变化).{0,80}(?:头部|背景|结尾|卖点|功能|CTA|卡片|布局|信任|行动|内容|展示|规划)/iu',
            '/(?:把|将|用|通过|让).{0,24}(?:价值|资质|流程|条件|风险|选择|主行动|卖点|功能|卡片|页面节奏|信任).{0,80}(?:展示|收口|解释|放在|呈现|拆分|承载|增强)/iu',
            '/AI content placeholder|ai-empty|placeholder content|placeholder/iu',
            '/demo|example\.com|placeholder\.com|placehold\.co|via\.placeholder|dummyimage\.com|picsum\.photos/iu',
            '/Generated visual|inline SVG|Visual preview generated/iu',
            '/(?:<|&lt;)\s*\/?\s*[a-z0-9][^>\n]{0,120}(?:>|&gt;)|\bclass\s*=\s*(["\']).{1,120}\1/iu',
            '/Generated website section|Website content language|visitor-visible copy|Do not use the|Return ONLY|prompt text|customer brief|website requirement|planning\/plan language|stage-2 planned text|source intent|Built from plan|generated from plan|content_fill_rule|field_content_requirements|task_script|stage3_directive/iu',
            '/\b(?:planning_reason|why_this_block|why_add_this_block|block_reason|page_goal|block_goal|content_contract|design_contract|interaction_contract|implementation_contract|data_contract|render_contract|visual_contract|stage1_contract|stage2_context|plan_context|theme_context|contract_context|requirement_expansion|design_manifest|content_manifest|selected_skill_codes|skill_snapshots|qa_report_contract|build_blueprint|build_tasks)\b/iu',
            '/\b(?:page\s+contract|block\s+plan|block\s+contract|contract\s+description|implementation\s+notes?|design\s+notes?|plan\s+context|task\s+context|base\s+prompt|system\s+prompt)\b/iu',
            '/(?:为什么|为何).{0,40}(?:添加|加入|使用|设计|放置|这个|该|区块|模块)/u',
            '/(?:本区块|该区块|区块目标|模块目标|页面目标|设计意图|内容策略|执行蓝图|强契约|合同字段|契约字段)/u',
            '/欢迎访问|默认页面模板|Default Page Template|This is the default page/iu',
            '/访客看到|用户看到|让访客看到|从而产生|信任感增强|知道如何|Visitors?\s+(?:see|can review|can verify|understand how|ready to)|before publishing|reviewable page content/iu',
        ];
        $matches = [];
        foreach ($patterns as $pattern) {
            if (\preg_match_all($pattern, $text, $found) < 1) {
                continue;
            }
            foreach ($found[0] ?? [] as $match) {
                $matches[] = (string)$match;
            }
        }
        foreach ($this->matchPromptPlanningLeakText($text) as $match) {
            $matches[] = $match;
        }

        return \array_slice(\array_values(\array_unique($matches)), 0, 20);
    }

    /**
     * @return list<string>
     */
    private function matchPromptPlanningLeakText(string $text): array
    {
        $text = \trim($text);
        if ($text === '') {
            return [];
        }

        $matches = [];
        $patterns = [
            '/(?:这个|本|当前)?(?:页面|区块|模块|page|section|block).{0,24}(?:核心|关键|主要|key|core).{0,16}(?:亮点|卖点|重点|highlights?|selling\s+points?)/iu',
            '/(?:用|使用|通过|以|use|using|display|show|present).{0,28}(?:卡片层级|视觉层级|内容层级|card\s+hierarchy|visual\s+hierarchy).{0,90}(?:展示|呈现|强调|卖点|差异|信任|selling\s+points?|differences?|trust)/iu',
            '/(?:把|将|让|put|place|make).{0,22}(?:主行动|主要行动|主按钮|primary\s+action|main\s+action|cta).{0,60}(?:卡片|按钮|button|card).{0,60}(?:减少|降低|避免|reduce|avoid|hesitation|犹豫)/iu',
            '/(?:避免|不要|防止|avoid|do\s+not|don\'t).{0,36}(?:内容|视觉|区块|模块|blocks?|sections?|content).{0,48}(?:挤成|同一种视觉|相同视觉|same\s+visual|same\s+layout|feel\s+like\s+one)/iu',
            '/(?:访客|用户|visitors?|users?).{0,28}(?:看到|可以看到|see|can\s+review|can\s+verify|understand|ready\s+to).{0,100}(?:信任|下载|发布|证明|reviewable|before\s+publishing|proof|cta|action)/iu',
            '/(?:rewrite|render|use|provide|present|include|output)\s+(?:concrete|visitor-facing|download|category|trust|proof|cta|feature).{0,90}(?:copy|language|labels?|path|cards?)/iu',
        ];
        foreach ($patterns as $pattern) {
            if (\preg_match($pattern, $text, $found) === 1) {
                $matches[] = (string)($found[0] ?? $pattern);
            }
        }

        return \array_slice(\array_values(\array_unique($matches)), 0, 10);
    }

    private function resolveExpectedContentLocale(array $scope, string $pageType): string
    {
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $virtualPage = \is_array($scope['virtual_pages_by_type'][$pageType] ?? null) ? $scope['virtual_pages_by_type'][$pageType] : [];
        $pageRecord = \is_array($scope['pagebuilder_pages_by_type'][$pageType] ?? null) ? $scope['pagebuilder_pages_by_type'][$pageType] : [];
        foreach ([
            $scope['content_locale'] ?? null,
            $websiteProfile['content_locale'] ?? null,
            $virtualPage['content_locale'] ?? null,
            $scope['execution_blueprint']['content_locale'] ?? null,
            $scope['plan_json']['content_locale'] ?? null,
            $scope['plan_json']['i18n']['content_locale'] ?? null,
            $virtualPage['default_locale'] ?? null,
            $virtualPage['locale'] ?? null,
            $pageRecord['content_locale'] ?? null,
            $pageRecord['default_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $scope['default_language'] ?? null,
            $websiteProfile['default_locale'] ?? null,
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
     * Heuristic language gate. It intentionally only blocks large wrong-script
     * prose blocks, while allowing brand names, URLs, acronyms, product names,
     * and the site title to stay in their original spelling.
     *
     * @return list<string>
     */
    private function matchLanguageViolations(string $html, string $expectedLocale, array $scope = []): array
    {
        $expectedLocale = \strtolower(\str_replace('_', '-', \trim($expectedLocale)));
        if ($expectedLocale === '') {
            return [];
        }

        $text = $this->extractVisibleTextOnly($html);
        if ($text === '') {
            return [];
        }

        foreach ($this->buildLanguageAllowedVisibleSnippets($scope) as $snippet) {
            $text = \str_replace($snippet, ' ', $text);
        }
        $text = \preg_replace('/https?:\/\/\S+|www\.\S+|[a-z0-9.-]+\.[a-z]{2,}\S*/iu', ' ', $text) ?? $text;
        $text = \preg_replace('/\b(?:AI|API|APK|APP|CTA|FAQ|HTML|CSS|JS|SEO|URL|SSL|KYC|VIP|Android|iOS|SDK|SaaS|CRM|ERP)\b/u', ' ', $text) ?? $text;
        $text = \trim(\preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return [];
        }

        if ($this->isCjkLocale($expectedLocale)) {
            return $this->matchLargeLatinProseBlocks($text);
        }

        if ($this->isLatinScriptLocale($expectedLocale)) {
            return $this->matchLargeCjkProseBlocks($text);
        }

        return $this->matchLargeLatinProseBlocks($text);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function buildLanguageAllowedVisibleSnippets(array $scope): array
    {
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $snippets = [];
        foreach ([
            $scope['site_title'] ?? null,
            $scope['brand_name'] ?? null,
            $scope['target_domain'] ?? null,
            $websiteProfile['site_title'] ?? null,
            $websiteProfile['brand_name'] ?? null,
            $websiteProfile['target_domain'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $value = \trim((string)$candidate);
            if ($value !== '') {
                $snippets[] = $value;
            }
        }

        return \array_values(\array_unique($snippets));
    }

    private function isCjkLocale(string $locale): bool
    {
        return \preg_match('/^(zh|ja|ko)(?:-|$)/i', $locale) === 1;
    }

    private function isLatinScriptLocale(string $locale): bool
    {
        return \preg_match('/^(en|fr|de|es|pt|it|nl|id|ms|vi|tr|pl|ro|cs|sk|sl|hr|da|sv|no|fi)(?:-|$)/i', $locale) === 1;
    }

    /**
     * @return list<string>
     */
    private function matchLargeLatinProseBlocks(string $text): array
    {
        if (\preg_match_all('/\b[A-Za-z][A-Za-z\'-]+(?:(?:[\s,.;:!?&-]+)[A-Za-z][A-Za-z\'-]+){9,}\b/u', $text, $found) < 1) {
            return [];
        }

        $violations = [];
        foreach ($found[0] ?? [] as $match) {
            $match = \trim((string)$match);
            $letters = \preg_replace('/[^A-Za-z]+/u', '', $match) ?? '';
            $wordCount = \count(\preg_split('/\s+/u', $match, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
            $hasSentenceWords = \preg_match('/\b(?:the|and|with|for|from|that|this|your|our|into|before|after|because|visitors?|customers?|users?|website|section|content|support|service|trust|download|access)\b/iu', $match) === 1;
            if (($wordCount >= 10 || \strlen($letters) >= 80) && $hasSentenceWords) {
                $violations[] = 'large_foreign_language_block: ' . $this->excerptQualityGateText($match, 140);
            }
        }

        return \array_slice(\array_values(\array_unique($violations)), 0, 8);
    }

    /**
     * @return list<string>
     */
    private function matchLargeCjkProseBlocks(string $text): array
    {
        if (\preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}][\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\s，。！？、；：“”‘’（）《》]{19,}/u', $text, $found) < 1) {
            return [];
        }

        $violations = [];
        foreach ($found[0] ?? [] as $match) {
            $match = \trim((string)$match);
            if ($match !== '') {
                $violations[] = 'large_foreign_language_block: ' . $this->excerptQualityGateText($match, 140);
            }
        }

        return \array_slice(\array_values(\array_unique($violations)), 0, 8);
    }

    private function excerptQualityGateText(string $value, int $limit): string
    {
        $value = \trim(\preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (\mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return \mb_substr($value, 0, \max(0, $limit - 3), 'UTF-8') . '...';
    }

    private function extractVisibleTextOnly(string $html): string
    {
        $html = \preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = \preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html) ?? $html;
        $html = \preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = \preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = \preg_replace('/<title\b[^>]*>.*?<\/title>/is', '', $html) ?? $html;
        $text = \strip_tags($html);
        $text = \html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return \trim(\preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * @return array<string,bool>
     */
    private function matchResponsiveSignals(string $html): array
    {
        $signals = [];
        $patterns = [
            'media_query' => '/@media\s*\(\s*(?:max|min)-width\s*:/iu',
            'small_breakpoint' => '/@media\s*\(\s*max-width\s*:\s*(?:4[0-9]{2}|3[0-9]{2})px/iu',
            'single_column' => '/grid-template-columns\s*:\s*(?:minmax\(\s*0\s*,\s*)?1fr|flex-direction\s*:\s*column/iu',
            'min_width_reset' => '/min-width\s*:\s*0/iu',
            'media_responsive' => '/(?:max-width\s*:\s*100%|height\s*:\s*auto|object-fit\s*:\s*cover)/iu',
            'overflow_guard' => '/overflow-x\s*:\s*hidden|overflow-wrap\s*:\s*break-word/iu',
            'fluid_type_or_space' => '/clamp\(|min\(|max\(/iu',
            'motion_reduced' => '/prefers-reduced-motion/iu',
        ];
        foreach ($patterns as $key => $pattern) {
            if (\preg_match($pattern, $html) === 1) {
                $signals[$key] = true;
            }
        }

        return $signals;
    }

    /**
     * @return list<string>
     */
    private function matchMalformedGeneratedHtml(string $html): array
    {
        $patterns = [
            'malformed opening tag' => '/<\s+(?:class|id|style|href|src|alt|title|role|aria-[a-z0-9_-]+|data-[a-z0-9_-]+)\s*=/iu',
            'invalid class tag' => '/<\/class>/iu',
            'malformed aria/data attribute' => '/["\']-(?:hidden|label|expanded|controls|pressed|selected|current|describedby|labelledby)\s*=/iu',
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
     * @param array<string,mixed> $scope
     * @return list<string>
     */
    private function extractVerifiedAssetUrlsFromScope(array $scope): array
    {
        $urls = [];
        $verified = \is_array($scope['verified_assets'] ?? null) ? $scope['verified_assets'] : [];
        foreach ($verified as $value) {
            if (\is_scalar($value)) {
                $url = \trim((string)$value);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }
        $manifest = \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [];
        foreach (\is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [] as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $url = \trim((string)($slot['final_url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return \array_values(\array_unique($urls));
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<string>
     */
    private function extractRealVerifiedAssetUrlsFromScope(array $scope, string $pageType = ''): array
    {
        $urls = [];
        $manifestSlots = $this->normalizeManifestSlots($scope);
        if ($manifestSlots === []) {
            $verified = \is_array($scope['verified_assets'] ?? null) ? $scope['verified_assets'] : [];
            foreach ($verified as $value) {
                if (!\is_scalar($value)) {
                    continue;
                }
                $url = \trim((string)$value);
                if ($this->isRealImageAssetUrl($url)) {
                    $urls[] = $url;
                }
            }
        }

        foreach ($manifestSlots as $slot) {
            if (
                !\is_array($slot)
                || !$this->manifestSlotBelongsToPage($slot, $pageType)
                || !$this->isCanonicalPageImageSlot($slot, $pageType)
                || $this->isPlaceholderManifestSlot($slot)
                || $this->isFallbackOnlyManifestSlot($slot)
            ) {
                continue;
            }
            $slotType = \strtolower(\trim((string)($slot['slot_type'] ?? '')));
            if ($slotType === '') {
                continue;
            }
            $url = \trim((string)($slot['final_url'] ?? ''));
            if ($this->isRealImageAssetUrl($url)) {
                $urls[] = $url;
            }
        }

        return \array_values(\array_unique($urls));
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,array<string,mixed>>
     */
    private function extractRequiredRealImageSlotsFromScope(array $scope, string $pageType): array
    {
        $slots = [];
        foreach ($this->normalizeManifestSlots($scope) as $slot) {
            if (
                !\is_array($slot)
                || !$this->manifestSlotBelongsToPage($slot, $pageType)
                || !$this->isCanonicalPageImageSlot($slot, $pageType)
            ) {
                continue;
            }
            $slotType = \strtolower(\trim((string)($slot['slot_type'] ?? '')));
            if (!$this->slotRequiresRealImageAsset($slot)) {
                continue;
            }
            $slotId = \trim((string)($slot['slot_id'] ?? ''));
            if ($slotId === '') {
                continue;
            }
            $slots[$slotId] = $slot;
        }

        return $slots;
    }

    /**
     * @param array<string,array<string,mixed>> $slotsById
     * @return list<string>
     */
    private function matchUnresolvedRequiredImageSlotIds(array $slotsById): array
    {
        $unresolved = [];
        foreach ($slotsById as $slotId => $slot) {
            if (
                $this->isPlaceholderManifestSlot($slot)
                || $this->isFallbackOnlyManifestSlot($slot)
                || !$this->isRealImageAssetUrl((string)($slot['final_url'] ?? ''))
            ) {
                $unresolved[] = (string)$slotId;
            }
        }

        return \array_values(\array_unique($unresolved));
    }

    /**
     * @param array<string,array<string,mixed>> $slotsById
     * @return list<string>
     */
    private function matchUsedRequiredImageSlotIds(string $html, array $slotsById): array
    {
        if ($html === '' || $slotsById === []) {
            return [];
        }

        if (\preg_match_all('/<img\b[^>]*>/iu', $html, $matches) <= 0) {
            return [];
        }

        $used = [];
        foreach ($matches[0] as $tag) {
            $tag = (string)$tag;
            $slotId = $this->extractHtmlAttribute($tag, 'data-pb-ai-asset-slot');
            if ($slotId === '' || !isset($slotsById[$slotId])) {
                continue;
            }
            $expectedUrl = \trim((string)($slotsById[$slotId]['final_url'] ?? ''));
            $src = $this->extractHtmlAttribute($tag, 'src');
            if ($expectedUrl === '' || $src === '') {
                continue;
            }
            if ($this->normalizeVerifiedAssetUrl($src) !== $this->normalizeVerifiedAssetUrl($expectedUrl)) {
                continue;
            }

            $used[] = $slotId;
        }

        return $used;
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

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function normalizeManifestSlots(array $scope): array
    {
        $manifest = \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [];
        $rawSlots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        $slots = [];
        foreach ($rawSlots as $key => $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            if (\trim((string)($slot['slot_id'] ?? '')) === '' && \is_string($key) && \trim($key) !== '') {
                $slot['slot_id'] = \trim($key);
            }
            $slots[] = $slot;
        }

        return $slots;
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function manifestSlotBelongsToPage(array $slot, string $pageType): bool
    {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            return true;
        }
        foreach (['page_type', 'page_key', 'page'] as $key) {
            $slotPage = \trim((string)($slot[$key] ?? ''));
            if ($slotPage !== '') {
                return $slotPage === $pageType;
            }
        }
        $slotId = \trim((string)($slot['slot_id'] ?? ''));
        if ($slotId === '') {
            return true;
        }
        if (\str_starts_with($slotId, 'page:')) {
            return \str_starts_with($slotId, 'page:' . $pageType . ':');
        }

        return true;
    }

    /**
     * Refactored image slots are component-scoped: page:{page_type}:content-...
     * Old task/block-key slots are intentionally ignored instead of kept as a
     * compatibility surface, otherwise audits require images that the renderer
     * can no longer bind to a concrete generated component.
     *
     * @param array<string,mixed> $slot
     */
    private function isCanonicalPageImageSlot(array $slot, string $pageType): bool
    {
        $slotId = \strtolower(\trim((string)($slot['slot_id'] ?? '')));
        if (!\str_starts_with($slotId, 'page:')) {
            return true;
        }
        $prefix = 'page:' . \strtolower(\trim($pageType)) . ':';
        if ($prefix === 'page::' || !\str_starts_with($slotId, $prefix)) {
            return false;
        }
        $tail = \substr($slotId, \strlen($prefix));

        return \str_starts_with($tail, 'content-');
    }

    /**
     * @param list<string> $assetUrls
     * @return list<string>
     */
    private function matchUsedVerifiedAssets(string $html, array $assetUrls): array
    {
        $used = [];
        foreach ($assetUrls as $assetUrl) {
            $normalized = $this->normalizeVerifiedAssetUrl((string)$assetUrl);
            if ($normalized !== '' && \str_contains($html, $normalized)) {
                $used[] = (string)$assetUrl;
            }
        }

        return \array_values(\array_unique($used));
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function scopeRequiresRealImageAssets(array $scope): bool
    {
        foreach ($this->normalizeManifestSlots($scope) as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            if ($this->slotRequiresRealImageAsset($slot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function slotRequiresRealImageAsset(array $slot): bool
    {
        return (int)($slot['required'] ?? $slot['real_image_required'] ?? 0) === 1;
    }

    private function isRealImageAssetUrl(string $url): bool
    {
        $url = \trim($url);
        if ($url === '') {
            return false;
        }
        $lower = \strtolower($url);
        if (\str_contains($lower, 'placeholder') || \str_starts_with($lower, 'data:image/svg')) {
            return false;
        }
        if (\str_ends_with($lower, '.svg')) {
            return false;
        }

        return \preg_match('/\.(?:jpe?g|png|webp|gif)(?:[?#].*)?$/i', $url) === 1
            || \str_starts_with($lower, 'data:image/');
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function isPlaceholderManifestSlot(array $slot): bool
    {
        if ((int)($slot['placeholder'] ?? 0) === 1) {
            return true;
        }
        foreach (['source', 'mode', 'model'] as $key) {
            if (\strtolower(\trim((string)($slot[$key] ?? ''))) === 'placeholder') {
                return true;
            }
        }
        $url = \strtolower(\trim((string)($slot['final_url'] ?? '')));
        if ($url === '') {
            return false;
        }

        return \str_contains($url, 'placeholder') || \str_ends_with($url, '.svg');
    }

    /**
     * Local/programmatic fallback visuals are build diagnostics, not acceptable
     * final image assets. They must keep the visual gate red until real AI or
     * operator-provided imagery replaces them.
     *
     * @param array<string,mixed> $slot
     */
    private function isFallbackOnlyManifestSlot(array $slot): bool
    {
        foreach (['source', 'mode', 'model'] as $key) {
            if ($this->isFallbackOnlyAssetMarker((string)($slot[$key] ?? ''))) {
                return true;
            }
        }

        foreach (\is_array($slot['variants'] ?? null) ? $slot['variants'] : [] as $variant) {
            if (!\is_array($variant)) {
                continue;
            }
            if (\trim((string)($variant['generation_fallback_reason'] ?? '')) !== '') {
                return true;
            }
            foreach (['source', 'mode', 'model'] as $key) {
                if ($this->isFallbackOnlyAssetMarker((string)($variant[$key] ?? ''))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isFallbackOnlyAssetMarker(string $value): bool
    {
        $value = \strtolower(\trim($value));
        if ($value === '') {
            return false;
        }

        return \in_array($value, ['local_composed', 'local-premium-composition-v1'], true)
            || \str_contains($value, 'local_composition')
            || \str_contains($value, 'fallback');
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

    /**
     * @return list<string>
     */
    private function matchBrokenImages(string $text, array $verifiedAssets = []): array
    {
        $matches = [];
        if (\preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/iu', $text, $found, \PREG_SET_ORDER) > 0) {
            foreach ($found as $row) {
                $src = \trim((string)($row[2] ?? ''));
                if ($this->isBrokenImageSource($src, $verifiedAssets)) {
                    $matches[] = $src === '' ? '<empty img src>' : $src;
                }
            }
        }
        if (\preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/iu', $text, $found, \PREG_SET_ORDER) > 0) {
            foreach ($found as $row) {
                $src = \trim((string)($row[2] ?? ''));
                if ($this->isBrokenImageSource($src, $verifiedAssets)) {
                    $matches[] = $src;
                }
            }
        }

        return \array_slice(\array_values(\array_unique($matches)), 0, 20);
    }

    private function isBrokenImageSource(string $src, array $verifiedAssets = []): bool
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
        if (\preg_match('/^https?:\/\/.+\.(?:jpe?g|png|webp|gif)(?:[?#].*)?$/i', $src) === 1) {
            return true;
        }
        if (\preg_match('/^(?:\.{0,2}\/)?(?:images?|assets?|uploads?)\/.+\.(?:jpe?g|png|webp|gif)(?:[?#].*)?$/i', $src) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function matchVisualDepthSignals(string $html): array
    {
        $signals = [];
        $patterns = [
            'gradient' => '/linear-gradient|radial-gradient/iu',
            'shadow' => '/box-shadow|drop-shadow|filter:\s*drop-shadow/iu',
            'visual' => '/<img\b|data-pb-ai-image-role|background-image|url\(|vt-visual|css-only|pseudo-element/iu',
            'layout' => '/display\s*:\s*(?:grid|flex)|grid-template-columns/iu',
            'motion' => '/transition\s*:|animation\s*:|transform\s*:/iu',
            'surface' => '/border-radius|backdrop-filter|color-mix\(/iu',
        ];
        foreach ($patterns as $name => $pattern) {
            if (\preg_match($pattern, $html) === 1) {
                $signals[] = $name;
            }
        }

        return $signals;
    }

    /**
     * @param array<string, mixed> $layout
     * @return list<string>
     */
    private function matchLegacyDefaultBlocks(array $layout): array
    {
        $blocks = \is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];
        $bad = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockId = \trim((string)($block['block_id'] ?? ''));
            $type = \trim((string)($block['type'] ?? ''));
            $componentCode = \trim((string)($block['component_code'] ?? $block['code'] ?? ''));
            $targetScope = \trim((string)($block['target_scope'] ?? $block['scope'] ?? ''));
            $hasGeneratedContractShape = $componentCode !== ''
                || \str_starts_with($type, 'ai_generated_')
                || \str_contains($blockId, '/')
                || \str_starts_with($targetScope, 'page_type_layouts.')
                || \is_array($block['field_schema'] ?? null);
            if ($hasGeneratedContractShape) {
                continue;
            }

            $legacyKey = $blockId !== '' ? $blockId : $type;
            if (
                $legacyKey !== ''
                && \preg_match('/^(?:[a-z0-9]+(?:-[a-z0-9]+){1,}|[a-z0-9_]+)$/iu', $legacyKey) === 1
            ) {
                $bad[] = $legacyKey;
            }
        }

        return \array_values(\array_unique($bad));
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function matchStageOneContent(string $pageType, string $html, array $scope): array
    {
        $samples = $this->collectStageOneSamples($scope, $pageType);
        $hits = [];
        foreach ($samples as $sample) {
            if ($sample === '' || \mb_strlen($sample) < 4) {
                continue;
            }
            if (\mb_stripos($html, $sample) !== false) {
                $hits[] = $sample;
            }
        }

        return \array_slice(\array_values(\array_unique($hits)), 0, 8);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function collectStageOneSamples(array $scope, string $pageType): array
    {
        $samples = [];
        $pages = \is_array($scope['execution_blueprint']['pages'] ?? null) ? $scope['execution_blueprint']['pages'] : [];
        $pagePlans = \is_array($scope['execution_blueprint']['page_plans'] ?? null) ? $scope['execution_blueprint']['page_plans'] : [];
        $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        if ($page === [] && \is_array($pagePlans[$pageType] ?? null)) {
            $page = $pagePlans[$pageType];
        }
        $blocks = $this->collectCanonicalStageOnePageBlocks($page);
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            foreach (['title', 'heading', 'headline', 'label', 'summary', 'description'] as $key) {
                if (\is_scalar($block[$key] ?? null)) {
                    $samples[] = \trim((string)$block[$key]);
                }
            }
            foreach (\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [] as $field) {
                if (\is_array($field) && \is_scalar($field['sample'] ?? null)) {
                    $samples[] = \trim((string)$field['sample']);
                }
            }
            $realtime = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
            foreach (['headline'] as $key) {
                if (\is_scalar($realtime[$key] ?? null)) {
                    $samples[] = \trim((string)$realtime[$key]);
                }
            }
            foreach (\is_array($realtime['supporting_copy'] ?? null) ? $realtime['supporting_copy'] : [] as $copy) {
                if (\is_scalar($copy)) {
                    $samples[] = \trim((string)$copy);
                }
            }
        }

        return \array_values(\array_filter(\array_unique($samples), static fn(string $value): bool => $value !== ''));
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function matchThemeTokens(string $html, array $scope): array
    {
        $tokens = $this->collectThemeTokens($scope);
        if ($tokens === []) {
            return [];
        }

        $hits = [];
        foreach (\array_unique($tokens) as $token) {
            if ($this->isNeutralThemeToken($token)) {
                continue;
            }
            if (\stripos($html, $token) !== false) {
                $hits[] = $token;
            }
        }

        return \array_values(\array_unique($hits));
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function collectThemeTokens(array $scope): array
    {
        $tokens = [];
        foreach ([
            $scope['build_blueprint'] ?? null,
            $scope['build_tasks'] ?? null,
            $scope['execution_blueprint'] ?? null,
            $scope['plan_json'] ?? null,
        ] as $source) {
            if (\is_array($source)) {
                $this->collectThemeTokensRecursive($source, $tokens);
            }
        }

        return \array_values(\array_unique($tokens));
    }

    /**
     * @param array<string|int, mixed> $source
     * @param list<string> $tokens
     */
    private function collectThemeTokensRecursive(array $source, array &$tokens, int $depth = 0): void
    {
        if ($depth > 8) {
            return;
        }
        foreach ($source as $value) {
            if (\is_scalar($value)) {
                $color = \trim((string)$value);
                if (\preg_match('/^#[0-9a-f]{6}$/i', $color) === 1) {
                    $tokens[] = $color;
                }
                continue;
            }
            if (\is_array($value)) {
                $this->collectThemeTokensRecursive($value, $tokens, $depth + 1);
            }
        }
    }

    private function isNeutralThemeToken(string $token): bool
    {
        return \in_array(\strtolower($token), ['#ffffff', '#fff', '#000000', '#000'], true);
    }

    /**
     * @param array<string, mixed> $layout
     * @return array{header:bool,footer:bool}
     */
    private function detectSharedBlocks(array $layout, string $html): array
    {
        $blocks = \is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];
        $header = false;
        $footer = false;
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockId = \strtolower(\trim((string)($block['block_id'] ?? '')));
            $type = \strtolower(\trim((string)($block['type'] ?? '')));
            $header = $header || \str_contains($blockId, 'header') || \str_contains($type, 'header');
            $footer = $footer || \str_contains($blockId, 'footer') || \str_contains($type, 'footer');
        }

        return [
            'header' => $header || \stripos($html, 'header') !== false,
            'footer' => $footer || \stripos($html, 'footer') !== false,
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function buildItem(string $key, string $label, bool $ok, mixed $value): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'blocking' => true,
            'level' => $ok ? 'pass' : 'error',
            'value' => $value,
        ];
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
     * Keep the quality gate contract stable:
     * - if an item key/label drifts, map it back to the canonical item;
     * - if an item is not part of the contract, drop it;
     * - for page-report backed items, always reconnect to the canonical field.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string,array<string,mixed>> $pageReports
     * @return list<array<string,mixed>>
     */
    private function normalizeQualityItems(array $items, array $pageReports): array
    {
        $matchedItems = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $key = $this->resolveQualityItemKey($item);
            if ($key === '') {
                continue;
            }
            $matchedItems[$key] = $item;
        }

        $normalized = [];
        foreach (self::QUALITY_ITEM_SPECS as $key => $spec) {
            $item = \is_array($matchedItems[$key] ?? null) ? $matchedItems[$key] : [];
            $value = $item['value'] ?? null;
            $pageReportField = \trim((string)($spec['page_report_field'] ?? ''));
            if ($pageReportField !== '') {
                $value = $this->extractPageValues($pageReports, $pageReportField);
            }
            $normalized[] = [
                'key' => $key,
                'label' => $this->resolveQualityItemLabel($key, (string)($spec['label'] ?? $key)),
                'ok' => (bool)($item['ok'] ?? false),
                'blocking' => true,
                'level' => (bool)($item['ok'] ?? false) ? 'pass' : 'error',
                'value' => $value,
            ];
        }

        return $normalized;
    }

    private function resolveQualityItemLabel(string $key, string $fallback): string
    {
        return match ($key) {
            'stage1_content_visible' => '页面包含已确认方案内容',
            'theme_visible' => '页面包含已确认视觉 token',
            default => $fallback,
        };
    }

    /**
     * @param array<string,mixed> $item
     */
    private function resolveQualityItemKey(array $item): string
    {
        $candidateKey = \trim((string)($item['key'] ?? ''));
        if ($candidateKey !== '' && isset(self::QUALITY_ITEM_SPECS[$candidateKey])) {
            return $candidateKey;
        }

        $candidateLabel = \trim((string)($item['label'] ?? ''));
        if ($candidateLabel === '') {
            return '';
        }

        foreach (self::QUALITY_ITEM_SPECS as $key => $spec) {
            if ($candidateLabel === $spec['label']) {
                return $key;
            }
        }

        return '';
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function finalizeQualityItems(array $items, array $scope): array
    {
        foreach ($items as &$item) {
            $ok = !empty($item['ok']);
            $blocking = true;
            $level = $ok ? 'pass' : 'error';

            $item['blocking'] = $blocking;
            $item['level'] = $level;
            $detail = $this->formatQualityItemDetail($item, $scope);
            if ($detail !== '') {
                $item['detail'] = $detail;
            }
        }
        unset($item);

        return $items;
    }

    /**
     * 为发布预检弹窗生成简短中文说明（`detail` 字段），避免运营只看到 ok/false。
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $scope
     */
    private function formatQualityItemDetail(array $item, array $scope): string
    {
        $key = (string)($item['key'] ?? '');
        $ok = !empty($item['ok']);
        $value = $item['value'] ?? null;

        if ($ok) {
            return match ($key) {
                'task_coverage' => '已确认方案中的页面区块均已拆成构建任务。',
                'source_truth_coverage' => '必含事实、必需首页区块与禁忌规则均已覆盖。',
                'render_data_quality' => '渲染数据契约的结构、文案与 SEO 校验已通过。',
                'build_tasks_done' => '全部构建任务已完成。',
                default => '',
            };
        }

        return match ($key) {
            'task_coverage' => $this->formatTaskCoverageFailureDetail(\is_array($value) ? $value : []),
            'source_truth_coverage' => $this->formatQaFindingsFailureDetail(
                \is_array($value) ? $value : [],
                '请回到构建阶段补全缺失事实或必需区块，并重新生成相关页面块。'
            ),
            'render_data_quality' => $this->formatQaFindingsFailureDetail(
                \is_array($value) ? $value : [],
                '请修复渲染数据中的空文案、占位/demo 文案或 SEO 缺失后重新构建。'
            ),
            'build_tasks_done' => $this->formatBuildTasksFailureDetail(\is_array($value) ? $value : []),
            'content_quality' => $this->formatContentQualityFailureDetail(\is_array($value) ? $value : []),
            'shared_blocks_ready' => $this->formatSharedBlocksFailureDetail(\is_array($value) ? $value : [], $scope),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $report
     */
    private function formatTaskCoverageFailureDetail(array $report): string
    {
        $parts = [];
        foreach (\is_array($report['missing_blocks'] ?? null) ? $report['missing_blocks'] : [] as $pageType => $blocks) {
            $blockList = \array_values(\array_filter(\array_map('strval', \is_array($blocks) ? $blocks : [])));
            if ($blockList !== []) {
                $parts[] = (string)$pageType . ' 缺任务：' . \implode('、', \array_slice($blockList, 0, 6));
            }
        }
        foreach (\is_array($report['extra_blocks'] ?? null) ? $report['extra_blocks'] : [] as $pageType => $blocks) {
            $blockList = \array_values(\array_filter(\array_map('strval', \is_array($blocks) ? $blocks : [])));
            if ($blockList !== []) {
                $parts[] = (string)$pageType . ' 多余任务：' . \implode('、', \array_slice($blockList, 0, 6));
            }
        }
        $shared = \is_array($report['shared'] ?? null) ? $report['shared'] : [];
        if (empty($shared['header'])) {
            $parts[] = '缺少 shared:header 构建任务';
        }
        if (empty($shared['footer'])) {
            $parts[] = '缺少 shared:footer 构建任务';
        }
        if ($parts === []) {
            return '方案区块与构建任务不一致，请重新确认执行蓝图并同步构建任务。';
        }

        return \implode('；', \array_slice($parts, 0, 5));
    }

    /**
     * @param array<string, mixed> $bucket
     */
    private function formatQaFindingsFailureDetail(array $bucket, string $fallback): string
    {
        $messages = [];
        foreach (\is_array($bucket['findings'] ?? null) ? $bucket['findings'] : [] as $finding) {
            if (!\is_array($finding) || ($finding['severity'] ?? '') !== 'error') {
                continue;
            }
            $message = \trim((string)($finding['message'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
        }
        if ($messages === []) {
            return $fallback;
        }

        return \implode('；', \array_slice(\array_values(\array_unique($messages)), 0, 4));
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function formatBuildTasksFailureDetail(array $summary): string
    {
        $pending = (int)($summary['pending'] ?? 0);
        $running = (int)($summary['running'] ?? 0);
        $failed = (int)($summary['failed'] ?? 0);
        $total = (int)($summary['total'] ?? 0);
        $done = (int)($summary['done'] ?? 0);
        if ($failed > 0) {
            return "构建任务 {$failed}/{$total} 项失败，请先重试失败任务。";
        }
        if ($pending > 0 || $running > 0) {
            return "仍有 {$pending} 项待执行、{$running} 项执行中（已完成 {$done}/{$total}）。";
        }

        return '构建任务尚未全部完成，请等待构建结束后再发布。';
    }

    /**
     * @param array<string, mixed> $badMatchesByPage
     */
    private function formatContentQualityFailureDetail(array $badMatchesByPage): string
    {
        $samples = [];
        foreach ($badMatchesByPage as $pageType => $matches) {
            if (!\is_array($matches)) {
                continue;
            }
            foreach ($matches as $match) {
                $match = \trim((string)$match);
                if ($match !== '') {
                    $samples[] = (string)$pageType . ': ' . \mb_substr($match, 0, 80);
                }
                if (\count($samples) >= 3) {
                    break 2;
                }
            }
        }
        if ($samples === []) {
            return '页面仍含内部标识、方案说明或 demo/占位文案，请重新生成相关区块。';
        }

        return \implode('；', $samples);
    }

    /**
     * @param array<string, mixed> $sharedByPage
     * @param array<string, mixed> $scope
     */
    private function formatSharedBlocksFailureDetail(array $sharedByPage, array $scope): string
    {
        $missing = [];
        foreach ($sharedByPage as $pageType => $shared) {
            if (!\is_array($shared)) {
                continue;
            }
            if (empty($shared['header'])) {
                $missing[] = (string)$pageType . ' 缺 Header';
            }
            if (empty($shared['footer'])) {
                $missing[] = (string)$pageType . ' 缺 Footer';
            }
        }
        $parts = $missing;
        if ($this->buildBlueprintExpectsSharedComponent($scope, 'header')) {
            $parts[] = '构建任务缺少 shared:header';
        }
        if ($this->buildBlueprintExpectsSharedComponent($scope, 'footer')) {
            $parts[] = '构建任务缺少 shared:footer';
        }
        $parts = \array_values(\array_unique($parts));
        if ($parts === []) {
            return '共享 Header/Footer 未在页面布局或渲染结果中识别，请重新生成共享组件。';
        }

        return \implode('；', \array_slice($parts, 0, 5));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function buildBlueprintExpectsSharedComponent(array $scope, string $region): bool
    {
        $region = \strtolower(\trim($region));
        if ($region === '') {
            return false;
        }
        $taskKey = 'shared:' . $region;
        $blueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        foreach (\is_array($blueprint['tasks'] ?? null) ? $blueprint['tasks'] : [] as $task) {
            if (!\is_array($task)) {
                continue;
            }
            if ((string)($task['task_key'] ?? '') === $taskKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * 计算「已确认方案的所有区块是否都已拆成构建任务」：
     *  - expected = execution_blueprint.pages[*].blocks[*].block_key（Stage-1 确认方案的真相源）
     *  - scheduled = build_blueprint.tasks[*]（按 page_type 聚合的 block_key/section_code）；
     *    shared:header / shared:footer 单独检查共享块，不参与 per-page diff。
     *
     * 任何 expected 块在 scheduled 中找不到，或 scheduled 出现不属于 expected 的孤儿块，
     * 均报告并阻断发布；这能在发布前抓住「方案有块但任务漏拆 / 任务多拆 / page_type 错位」。
     *
     * @param array<string, mixed> $scope
     * @return array{
     *     ok: bool,
     *     missing_blocks: array<string, list<string>>,
     *     extra_blocks: array<string, list<string>>,
     *     expected_blocks: array<string, list<string>>,
     *     scheduled_blocks: array<string, list<string>>,
     *     shared: array{header: bool, footer: bool},
     *     evaluated: bool
     * }
     */
    private function buildTaskCoverageReport(array $scope): array
    {
        $expected = $this->collectExpectedBlocksFromExecutionBlueprint($scope);
        $scheduled = $this->collectScheduledBlocksFromBuildBlueprint($scope);
        $sharedHeader = $scheduled['__shared_header__'] ?? false;
        $sharedFooter = $scheduled['__shared_footer__'] ?? false;
        unset($scheduled['__shared_header__'], $scheduled['__shared_footer__']);
        $expected = $this->removeSharedBlocksFromPerPageCoverage($expected, $sharedHeader, $sharedFooter);

        // 蓝图未生成（例如方案尚未确认）时不阻断发布，避免与 build_tasks_done / required_pages_render 双重报错；
        // 此时报告 evaluated=false，由其它门禁覆盖。
        if ($expected === [] && $scheduled === []) {
            return [
                'ok' => true,
                'missing_blocks' => [],
                'extra_blocks' => [],
                'expected_blocks' => [],
                'scheduled_blocks' => [],
                'shared' => ['header' => $sharedHeader, 'footer' => $sharedFooter],
                'evaluated' => false,
            ];
        }

        $missing = [];
        $extra = [];
        $pageTypes = \array_values(\array_unique(\array_merge(\array_keys($expected), \array_keys($scheduled))));
        foreach ($pageTypes as $pageType) {
            $exp = \array_map('\strval', $expected[$pageType] ?? []);
            $sch = \array_map('\strval', $scheduled[$pageType] ?? []);
            $diffMissing = \array_values(\array_diff($exp, $sch));
            $diffExtra = \array_values(\array_diff($sch, $exp));
            if ($diffMissing !== []) {
                $missing[$pageType] = $diffMissing;
            }
            if ($diffExtra !== []) {
                $extra[$pageType] = $diffExtra;
            }
        }

        return [
            'ok' => $missing === [] && $extra === [],
            'missing_blocks' => $missing,
            'extra_blocks' => $extra,
            'expected_blocks' => $expected,
            'scheduled_blocks' => $scheduled,
            'shared' => ['header' => $sharedHeader, 'footer' => $sharedFooter],
            'evaluated' => true,
        ];
    }

    /**
     * Shared header/footer are produced by dedicated shared:* tasks and checked
     * by shared_blocks_ready. They are not missing per-page content blocks.
     *
     * @param array<string, list<string>> $expected
     * @return array<string, list<string>>
     */
    private function removeSharedBlocksFromPerPageCoverage(array $expected, bool $sharedHeader, bool $sharedFooter): array
    {
        if (!$sharedHeader && !$sharedFooter) {
            return $expected;
        }

        $sharedAliases = [];
        if ($sharedHeader) {
            $sharedAliases[] = 'header';
            $sharedAliases[] = 'shared_header';
        }
        if ($sharedFooter) {
            $sharedAliases[] = 'footer';
            $sharedAliases[] = 'shared_footer';
        }

        foreach ($expected as $pageType => $blocks) {
            $expected[$pageType] = \array_values(\array_filter(
                \array_map('\strval', $blocks),
                static fn(string $block): bool => !\in_array($block, $sharedAliases, true)
            ));
        }

        return $expected;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, list<string>>
     */
    private function collectExpectedBlocksFromExecutionBlueprint(array $scope): array
    {
        $blueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $sources = [];
        foreach (['pages', 'page_plans'] as $key) {
            if (\is_array($blueprint[$key] ?? null)) {
                $sources[] = $blueprint[$key];
            }
        }
        $expected = [];
        foreach ($sources as $pages) {
            foreach ($pages as $pageType => $page) {
                if (!\is_array($page)) {
                    continue;
                }
                $pageTypeKey = \trim((string)$pageType);
                if ($pageTypeKey === '') {
                    continue;
                }
                $blocks = $this->collectCanonicalStageOnePageBlocks($page);
                foreach ($blocks as $block) {
                    if (!\is_array($block)) {
                        continue;
                    }
                    $blockKeyValue = $this->normalizeBlockIdentifier(
                        (string)($block['block_key'] ?? $block['section_code'] ?? $block['key'] ?? '')
                    );
                    if ($blockKeyValue === '') {
                        continue;
                    }
                    $expected[$pageTypeKey] ??= [];
                    if (!\in_array($blockKeyValue, $expected[$pageTypeKey], true)) {
                        $expected[$pageTypeKey][] = $blockKeyValue;
                    }
                }
            }
        }

        return $expected;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, list<string>|bool>
     */
    private function collectScheduledBlocksFromBuildBlueprint(array $scope): array
    {
        $tasks = [];
        $blueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        if (\is_array($blueprint['tasks'] ?? null)) {
            $tasks = \array_merge($tasks, $blueprint['tasks']);
        }
        $scheduled = [
            '__shared_header__' => false,
            '__shared_footer__' => false,
        ];
        foreach ($tasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = (string)($task['task_key'] ?? '');
            if ($taskKey === 'shared:header') {
                $scheduled['__shared_header__'] = true;
                continue;
            }
            if ($taskKey === 'shared:footer') {
                $scheduled['__shared_footer__'] = true;
                continue;
            }
            $pageType = \trim((string)($task['page_type'] ?? ''));
            if ($pageType === '') {
                continue;
            }
            // 字段优先级：build_blueprint 真实任务带 block_key/section_key（line 620/936 in AiSiteBuildTaskService），
            // 兼容老 build_summary/老 task fixture 时退到 task_key 末段或 section_code 末段。
            $rawKey = (string)(
                $task['block_key']
                    ?? $task['section_key']
                    ?? $task['task_key']
                    ?? $task['section_code']
                    ?? ''
            );
            $blockKeyValue = $this->normalizeBlockIdentifier($rawKey);
            if ($blockKeyValue === '') {
                continue;
            }
            $scheduled[$pageType] ??= [];
            if (!\in_array($blockKeyValue, $scheduled[$pageType], true)) {
                $scheduled[$pageType][] = $blockKeyValue;
            }
        }

        return $scheduled;
    }

    /**
     * Stage-1 `display_blocks` already prepends shared header/footer onto page blocks.
     * Merging both arrays double-counts and makes build task coverage look wrong.
     *
     * @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private function collectCanonicalStageOnePageBlocks(array $page): array
    {
        $blocks = \is_array($page['blocks'] ?? null) ? \array_values($page['blocks']) : [];
        $blocks = \array_values(\array_filter($blocks, static fn(mixed $block): bool => \is_array($block)));
        if ($blocks !== []) {
            return $blocks;
        }

        $displayBlocks = \is_array($page['display_blocks'] ?? null) ? \array_values($page['display_blocks']) : [];
        $pageBlocks = [];
        foreach ($displayBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $displayRole = \trim((string)($block['display_role'] ?? ''));
            if ($displayRole !== '' && $displayRole !== 'page_block') {
                continue;
            }
            $pageBlocks[] = $block;
        }

        return $pageBlocks;
    }

    private function normalizeBlockIdentifier(string $value): string
    {
        $value = \mb_strtolower(\trim($value));
        if ($value === '') {
            return '';
        }
        // 去掉命名空间前缀（保留终段 block_key/section_code）：
        //  - task_key 形态：page:home_page:hero_banner → hero_banner
        //  - section_code 路径形态：content/home-page-hero-banner → home-page-hero-banner
        foreach ([':', '/'] as $separator) {
            if (\str_contains($value, $separator)) {
                $parts = \explode($separator, $value);
                $value = (string)\end($parts);
            }
        }

        // 末段命名风格归一：连字符 ↔ 下划线统一为下划线，便于与 execution_blueprint 的 block_key 对齐。
        return \str_replace('-', '_', $value);
    }

    /**
     * 从 `qa_report_contract.payload.content_quality.findings` 抽出已经存在但发布门禁未消费的
     * 内容侧 findings，把它们按来源分桶：
     *  - render_data：RenderDataQualityLinter（category=design/copy/seo）+ Visual/Asset 校验
     *  - source_truth：SourceTruthCoverageLinter（must_include_facts / required_home_blocks / 禁忌规则）
     *
     * 只把 severity=error 视为阻断信号，避免把已知的 warning（如 fallback_used）升级成发布阻断。
     * 如果 qa_report_contract 不存在或为空，按"未评估"处理 → ok=true，避免影响存量流程。
     *
     * @param array<string, mixed> $scope
     * @return array{
     *     source_truth: array{ok:bool, error_count:int, warning_count:int, findings:list<array<string,mixed>>, evaluated:bool},
     *     render_data: array{ok:bool, error_count:int, warning_count:int, findings:list<array<string,mixed>>, evaluated:bool},
     *     source_truth_ok: bool,
     *     render_data_ok: bool
     * }
     */
    private function collectQaReportFindings(array $scope): array
    {
        $preflightError = \trim((string)($scope['quality_gate_preflight_error'] ?? ''));
        $qaReport = \is_array($scope['qa_report_contract'] ?? null) ? $scope['qa_report_contract'] : [];
        $payload = \is_array($qaReport['payload'] ?? null) ? $qaReport['payload'] : [];
        $contentQuality = \is_array($payload['content_quality'] ?? null) ? $payload['content_quality'] : [];
        $findings = \is_array($contentQuality['findings'] ?? null) ? $contentQuality['findings'] : [];
        if ($preflightError !== '') {
            $findings[] = [
                'severity' => 'error',
                'category' => 'render_data',
                'rule' => 'render_data.preflight_failed',
                'message' => $preflightError,
                'target_path' => 'quality_gate_preflight_error',
            ];
        }
        $evaluated = $preflightError !== ''
            || ($contentQuality['status'] ?? 'not_evaluated') !== 'not_evaluated'
            || $findings !== [];

        $sourceTruthBucket = [];
        $renderDataBucket = [];
        foreach ($findings as $finding) {
            if (!\is_array($finding)) {
                continue;
            }
            if ($this->isSourceTruthFinding($finding)) {
                $sourceTruthBucket[] = $finding;
                continue;
            }
            $renderDataBucket[] = $finding;
        }
        $sourceTruthBucket = $this->filterStaleSourceTruthFindings($scope, $sourceTruthBucket);

        $sourceTruth = $this->summarizeFindingsBucket($sourceTruthBucket, $evaluated);
        $renderData = $this->summarizeFindingsBucket($renderDataBucket, $evaluated);

        return [
            'source_truth' => $sourceTruth,
            'render_data' => $renderData,
            'source_truth_ok' => $sourceTruth['ok'],
            'render_data_ok' => $renderData['ok'],
        ];
    }

    /**
     * The saved virtual layout can lag the latest scope after a concurrent batch
     * or repair run. Inspection should render the latest generated draft, while
     * the build completion gate separately requires persisted layout parity.
     *
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
     * Required-block coverage is cheap and deterministic from the current
     * build_blueprint task tree. Do not let an older qa_report snapshot keep a
     * missing-block error after the canonical task tree has moved on.
     *
     * @param list<array<string, mixed>> $findings
     * @return list<array<string, mixed>>
     */
    private function filterStaleSourceTruthFindings(array $scope, array $findings): array
    {
        $findings = $this->filterStaleSourceTruthRequiredBlockFindings($scope, $findings);
        $sourceTruth = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];
        $linter = new SourceTruthCoverageLinter();
        $visibleFactIds = [];
        $visibleFactsById = [];
        foreach ($linter->visibleMustIncludeFacts($sourceTruth) as $fact) {
            if (\is_array($fact)) {
                $id = \trim((string)($fact['id'] ?? ''));
                if ($id !== '') {
                    $visibleFactIds[$id] = true;
                    $visibleFactsById[$id] = \trim((string)($fact['text'] ?? ''));
                }
            }
        }
        $currentCoverageText = $this->collectSourceTruthCoverageTextFromScope($scope);
        if ($visibleFactIds === []) {
            return \array_values(\array_filter(
                $findings,
                static function (array $finding): bool {
                    $path = \trim((string)($finding['path'] ?? $finding['target_path'] ?? ''));
                    return !\str_starts_with($path, 'content_quality.missing_must_include_fact');
                }
            ));
        }

        $filtered = [];
        foreach ($findings as $finding) {
            $path = \trim((string)($finding['path'] ?? $finding['target_path'] ?? ''));
            if (!\str_starts_with($path, 'content_quality.missing_must_include_fact')) {
                $filtered[] = $finding;
                continue;
            }
            $message = (string)($finding['message'] ?? '');
            if (\preg_match('/Missing must-include fact \[([^\]]+)\]/u', $message, $match) !== 1) {
                $filtered[] = $finding;
                continue;
            }
            $factId = \trim((string)($match[1] ?? ''));
            if (!isset($visibleFactIds[$factId])) {
                continue;
            }
            $factText = (string)($visibleFactsById[$factId] ?? '');
            if ($currentCoverageText !== '' && $factText !== '' && $linter->textCoversFact($currentCoverageText, $factText)) {
                continue;
            }
            $filtered[] = $finding;
        }

        return $filtered;
    }

    private function collectSourceTruthCoverageTextFromScope(array $scope): string
    {
        $parts = [];
        foreach (['page_type_layouts', 'virtual_pages_by_type', 'shared_components'] as $key) {
            if (!\array_key_exists($key, $scope)) {
                continue;
            }
            $json = \json_encode($scope[$key], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (\is_string($json) && $json !== '' && $json !== 'null') {
                $parts[] = $json;
            }
        }

        return \trim((string)\preg_replace('/\s+/u', ' ', \strip_tags(\implode(' ', $parts))));
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return list<array<string, mixed>>
     */
    private function filterStaleSourceTruthRequiredBlockFindings(array $scope, array $findings): array
    {
        $sourceTruth = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];
        $required = \is_array($sourceTruth['required_home_blocks'] ?? null) ? $sourceTruth['required_home_blocks'] : [];
        if ($required === [] || $findings === []) {
            return $findings;
        }

        $blocks = [];
        $blueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        foreach (\is_array($blueprint['tasks'] ?? null) ? $blueprint['tasks'] : [] as $task) {
            if (!\is_array($task) || \trim((string)($task['page_type'] ?? '')) !== Page::TYPE_HOME) {
                continue;
            }
            $blockKey = \trim((string)($task['block_key'] ?? ''));
            if ($blockKey === '') {
                $sectionCode = \trim((string)($task['section_code'] ?? ''));
                $blockKey = $sectionCode !== ''
                    ? \basename(\str_replace('.', '/', $sectionCode))
                    : \trim((string)($task['task_key'] ?? ''));
            }
            if ($blockKey === '') {
                continue;
            }
            $blocks[] = [
                'block_key' => $blockKey,
                'content' => (string)($task['label'] ?? ''),
                'goal' => (string)($task['task_key'] ?? ''),
            ];
        }
        if ($blocks === []) {
            return $findings;
        }

        $lintSource = [
            'required_home_blocks' => $required,
            'must_include_facts' => [],
            'must_not_do' => [],
        ];
        $lint = (new SourceTruthCoverageLinter())->lint($lintSource, [], ['blocks' => $blocks]);
        if (\is_array($lint['missing_blocks'] ?? null) && $lint['missing_blocks'] !== []) {
            return $findings;
        }

        return \array_values(\array_filter(
            $findings,
            static function (array $finding): bool {
                $path = \trim((string)($finding['path'] ?? $finding['target_path'] ?? ''));
                return !\str_starts_with($path, 'content_quality.missing_required_block');
            }
        ));
    }

    /**
     * @param array<string, mixed> $finding
     */
    private function isSourceTruthFinding(array $finding): bool
    {
        $contractType = \mb_strtolower(\trim((string)($finding['contract_type'] ?? '')));
        if ($contractType === 'source_truth') {
            return true;
        }
        $path = \mb_strtolower(\trim((string)($finding['path'] ?? $finding['target_path'] ?? '')));
        if ($path === '') {
            return false;
        }

        return \str_starts_with($path, 'content_quality.missing_must_include_fact')
            || \str_starts_with($path, 'content_quality.missing_required_block')
            || \str_starts_with($path, 'content_quality.forbidden_style_violation');
    }

    /**
     * @param list<array<string, mixed>> $bucket
     * @return array{ok:bool, error_count:int, warning_count:int, findings:list<array<string,mixed>>, evaluated:bool}
     */
    private function summarizeFindingsBucket(array $bucket, bool $evaluated): array
    {
        $errorCount = 0;
        $warningCount = 0;
        foreach ($bucket as $finding) {
            $severity = \mb_strtolower(\trim((string)($finding['severity'] ?? '')));
            if ($severity === 'error') {
                ++$errorCount;
            } elseif ($severity === 'warning') {
                ++$warningCount;
            }
        }

        return [
            // 未评估时（qa_report_contract 还未生成）默认放行，由 build_tasks_done / required_pages_render 等门禁兜底；
            // 一旦 qa 报告生成，error 级 finding 即阻断发布；warning 仅记录，不阻断。
            'ok' => $errorCount === 0,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'findings' => \array_values($bucket),
            'evaluated' => $evaluated,
        ];
    }
}
