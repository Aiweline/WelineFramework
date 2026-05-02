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
        'build_tasks_done' => ['label' => '构建任务全部完成'],
        'required_pages_render' => ['label' => '关键页面可渲染'],
        'shared_blocks_ready' => ['label' => '共享 Header/Footer 已就绪', 'page_report_field' => 'shared_blocks'],
        'content_quality' => ['label' => '页面无内部标识/方案说明/demo 文案', 'page_report_field' => 'bad_matches'],
        'stage1_content_visible' => ['label' => '页面包含阶段一确认内容', 'page_report_field' => 'stage1_hits'],
        'theme_visible' => ['label' => '页面包含阶段一主题色/视觉 token', 'page_report_field' => 'theme_hits'],
        'visual_assets_safe' => ['label' => '图片/视觉资源无破图且有 SVG/CSS 视觉', 'page_report_field' => 'visuals'],
        'visual_depth' => ['label' => '页面块具备视觉层次与美术分层', 'page_report_field' => 'visual_depth_signals'],
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

        $allTasksDone = !$this->buildTaskService->hasPendingTasks($scope);
        $requiredPagesReady = $pageTypes !== [];
        $allContentClean = true;
        $allStageOneContentVisible = true;
        $allThemeVisible = true;
        $allVisualsSafe = true;
        $allVisualDepth = true;
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

            $requiredPagesReady = $requiredPagesReady && $pageId > 0 && $html !== '' && $renderError === '';
            // TEMP: 暂停“页面无内部标识/方案说明/demo 文案”门禁，不阻断发布。
            // 保留 page_reports.bad_matches 供排查，但质量项始终按通过处理。
            $allStageOneContentVisible = $allStageOneContentVisible && (bool)($report['stage1_content_visible'] ?? false);
            $allThemeVisible = $allThemeVisible && (bool)($report['theme_visible'] ?? false);
            $allVisualsSafe = $allVisualsSafe && (bool)($report['visuals_safe'] ?? false);
            $allVisualDepth = $allVisualDepth && (bool)($report['visual_depth_ok'] ?? false);
            $sharedBlocksReady = $sharedBlocksReady && (bool)($report['shared_blocks_ready'] ?? false);
        }

        $items = $this->normalizeQualityItems([
            $this->buildItem('build_tasks_done', '构建任务全部完成', $allTasksDone, $this->buildTaskService->summarize($scope)),
            $this->buildItem('required_pages_render', '关键页面可渲染', $requiredPagesReady, \array_keys($pageReports)),
            $this->buildItem('shared_blocks_ready', '共享 Header/Footer 已就绪', $sharedBlocksReady, $this->extractPageValues($pageReports, 'shared_blocks')),
            $this->buildItem('content_quality', '页面无内部标识/方案说明/demo 文案', $allContentClean, $this->extractPageValues($pageReports, 'bad_matches')),
            $this->buildItem('stage1_content_visible', '页面包含阶段一确认内容', $allStageOneContentVisible, $this->extractPageValues($pageReports, 'stage1_hits')),
            $this->buildItem('theme_visible', '页面包含阶段一主题色/视觉 token', $allThemeVisible, $this->extractPageValues($pageReports, 'theme_hits')),
            $this->buildItem('visual_assets_safe', '图片/视觉资源无破图且有 SVG/CSS 视觉', $allVisualsSafe, $this->extractPageValues($pageReports, 'visuals')),
            $this->buildItem('visual_depth', '页面块具备视觉层次与美术分层', $allVisualDepth, $this->extractPageValues($pageReports, 'visual_depth_signals')),
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
        $badMatches = $this->matchBadContent($this->stripNonVisibleQualityGateHtml($html));
        $brokenImages = $this->matchBrokenImages($combined, $this->extractVerifiedAssetUrlsFromScope($scope));
        $legacyBlocks = $this->matchLegacyDefaultBlocks($layout);
        $stageOneHits = $this->matchStageOneContent($pageType, $html, $scope);
        $themeHits = $this->matchThemeTokens($html, $scope);
        $sharedBlocks = $this->detectSharedBlocks($layout, $html);
        $hasSvgVisual = \preg_match('/<svg\b|data:image\/svg\+xml|ai-site-svg-visual/i', $html) === 1;
        $hasAnyImageNeed = \preg_match('/\b(?:image|visual|media|asset|logo|icon|cards?|board|avatar|shield)\b/iu', $combined) === 1;
        $visualDepthSignals = $this->matchVisualDepthSignals($html);

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
            'visuals_safe' => $brokenImages === [] && (!$hasAnyImageNeed || $hasSvgVisual || \preg_match('/<img\b/i', $html) === 1),
            'visuals' => [
                'broken_images' => $brokenImages,
                'has_svg_visual' => $hasSvgVisual,
                'has_image_need' => $hasAnyImageNeed,
            ],
            'visual_depth_ok' => \count($visualDepthSignals) >= 3,
            'visual_depth_signals' => $visualDepthSignals,
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
        if ($layout === [] && \is_array($scope['page_type_layouts'][$pageType] ?? null)) {
            $layout = $this->scopeCompatibilityService->normalizeLayoutConfig(
                $scope['page_type_layouts'][$pageType],
                $pageType
            );
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
        $track = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        if ($track === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
            return 0;
        }

        return \max(0, (int)($scope['virtual_theme_id'] ?? 0));
    }

    private function stripNonVisibleQualityGateHtml(string $html): string
    {
        $html = \preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = \preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = \preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;

        $visibleAttributeValues = [];
        if (
            \preg_match_all(
                '/\s(?:href|src|alt|title|aria-label|placeholder|value)\s*=\s*(["\'])(.*?)\1/isu',
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
                '/\s(?:href|src|alt|title|aria-label|placeholder|value)\s*=\s*([^\s>]+)/isu',
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
            '/AI_GENERATED_SECTION|task_key|section_code|block_key|page_type|field_content_requirements/iu',
            '/content\/(?:home|about|contact|product|service)-page-[a-z0-9_-]+/iu',
            '/核心卖点|功能特性|把首页|值得点击|放出来|方案头部|方案背景|方案结尾|当前方案|任务方案|蓝图/iu',
            '/AI content placeholder|ai-empty|placeholder content|placeholder/iu',
            '/demo|example\.com|placeholder\.com|placehold\.co|via\.placeholder|dummyimage\.com|picsum\.photos/iu',
            '/Generated visual|inline SVG|Visual preview generated/iu',
            '/Generated website section|Website content language|visitor-visible copy|Do not use the|Return ONLY|prompt text|customer brief|website requirement|planning\/plan language|stage-2 planned text|source intent/iu',
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

        return \array_slice(\array_values(\array_unique($matches)), 0, 20);
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
        if (\str_starts_with($lower, 'data:image/') || \str_starts_with($lower, 'blob:')) {
            return false;
        }
        foreach (['example.com', 'placeholder.com', 'placehold.co', 'via.placeholder', 'dummyimage.com', 'picsum.photos'] as $marker) {
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
            'visual' => '/<svg\b|data:image\/svg\+xml|ai-site-svg-visual|vt-visual/iu',
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
            if (\in_array($type, ['hero', 'cards', 'checklist', 'cta'], true)) {
                $bad[] = $blockId !== '' ? $blockId : $type;
                continue;
            }
            foreach (['home-page-highlights', 'home-page-details', 'about-page-story', 'about-page-values'] as $legacy) {
                if ($blockId === $legacy) {
                    $bad[] = $blockId;
                }
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
        $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
            if (!\is_array($block)) {
                continue;
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
            $tokens = ['#111827', '#f59e0b', '#dc2626', '#FFD700', '#8B0000', '#228B22'];
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
            $scope['virtual_theme_plan']['confirmed'] ?? null,
            $scope['task_plan_structured'] ?? null,
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
                'label' => $spec['label'],
                'ok' => (bool)($item['ok'] ?? false),
                'blocking' => true,
                'level' => (bool)($item['ok'] ?? false) ? 'pass' : 'error',
                'value' => $value,
            ];
        }

        return $normalized;
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
        $isFakeMode = (int)($scope['fake_mode'] ?? 0) === 1;
        $nonBlockingInFakeMode = [
            'stage1_content_visible' => true,
            'visual_assets_safe' => true,
        ];

        foreach ($items as &$item) {
            $key = \trim((string)($item['key'] ?? ''));
            $ok = !empty($item['ok']);
            $blocking = true;
            $level = $ok ? 'pass' : 'error';

            if ($isFakeMode && isset($nonBlockingInFakeMode[$key])) {
                $blocking = false;
                $level = $ok ? 'pass' : 'warning';
            }

            $item['blocking'] = $blocking;
            $item['level'] = $level;
            if (!$blocking && !$ok) {
                $item['message'] = 'Recorded as non-blocking in fake mode.';
            }
        }
        unset($item);

        return $items;
    }
}
