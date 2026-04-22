<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteQualityGateService
{
    private readonly AiSiteBuildTaskService $buildTaskService;
    private readonly AiSiteScopeCompatibilityService $scopeCompatibilityService;
    private readonly PageRenderService $pageRenderService;
    private readonly Page $pageModel;

    public function __construct(
        ?AiSiteBuildTaskService $buildTaskService = null,
        ?AiSiteScopeCompatibilityService $scopeCompatibilityService = null,
        ?PageRenderService $pageRenderService = null,
        ?Page $pageModel = null
    ) {
        $this->buildTaskService = $buildTaskService ?? ObjectManager::getInstance(AiSiteBuildTaskService::class);
        $this->scopeCompatibilityService = $scopeCompatibilityService ?? ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
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
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $pagesByType = $this->resolvePagesByType($scope);
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

            if ($html === '' && $pageId > 0) {
                try {
                    $page = clone $this->pageModel;
                    $page->clearData()->clearQuery()->load($pageId);
                    if ($page->getId() > 0) {
                        $layout = $page->getAiLayoutArray();
                        $html = $this->pageRenderService->render($page);
                    }
                } catch (\Throwable $throwable) {
                    $renderError = $throwable->getMessage();
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
            $allContentClean = $allContentClean && (bool)($report['content_clean'] ?? false);
            $allStageOneContentVisible = $allStageOneContentVisible && (bool)($report['stage1_content_visible'] ?? false);
            $allThemeVisible = $allThemeVisible && (bool)($report['theme_visible'] ?? false);
            $allVisualsSafe = $allVisualsSafe && (bool)($report['visuals_safe'] ?? false);
            $allVisualDepth = $allVisualDepth && (bool)($report['visual_depth_ok'] ?? false);
            $sharedBlocksReady = $sharedBlocksReady && (bool)($report['shared_blocks_ready'] ?? false);
        }

        $items = [
            $this->buildItem('build_tasks_done', '构建任务全部完成', $allTasksDone, $this->buildTaskService->summarize($scope)),
            $this->buildItem('required_pages_render', '关键页面可渲染', $requiredPagesReady, \array_keys($pageReports)),
            $this->buildItem('shared_blocks_ready', '共享 Header/Footer 已就绪', $sharedBlocksReady, $this->extractPageValues($pageReports, 'shared_blocks')),
            $this->buildItem('content_quality', '页面无内部标识/方案说明/demo 文案', $allContentClean, $this->extractPageValues($pageReports, 'bad_matches')),
            $this->buildItem('stage1_content_visible', '页面包含阶段一确认内容', $allStageOneContentVisible, $this->extractPageValues($pageReports, 'stage1_hits')),
            $this->buildItem('theme_visible', '页面包含阶段一主题色/视觉 token', $allThemeVisible, $this->extractPageValues($pageReports, 'theme_hits')),
            $this->buildItem('visual_assets_safe', '图片/视觉资源无破图且有 SVG/CSS 视觉', $allVisualsSafe, $this->extractPageValues($pageReports, 'visuals')),
            $this->buildItem('visual_depth', '页面块具备视觉层次与美术分层', $allVisualDepth, $this->extractPageValues($pageReports, 'visual_depth_signals')),
        ];

        $passed = true;
        foreach ($items as $item) {
            if (empty($item['ok'])) {
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
        $badMatches = $this->matchBadContent($html);
        $brokenImages = $this->matchBrokenImages($combined);
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
     * @return list<string>
     */
    private function matchBrokenImages(string $text): array
    {
        $matches = [];
        if (\preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/iu', $text, $found, \PREG_SET_ORDER) > 0) {
            foreach ($found as $row) {
                $src = \trim((string)($row[2] ?? ''));
                if ($this->isBrokenImageSource($src)) {
                    $matches[] = $src === '' ? '<empty img src>' : $src;
                }
            }
        }
        if (\preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/iu', $text, $found, \PREG_SET_ORDER) > 0) {
            foreach ($found as $row) {
                $src = \trim((string)($row[2] ?? ''));
                if ($this->isBrokenImageSource($src)) {
                    $matches[] = $src;
                }
            }
        }

        return \array_slice(\array_values(\array_unique($matches)), 0, 20);
    }

    private function isBrokenImageSource(string $src): bool
    {
        $src = \trim(\html_entity_decode($src, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
        if ($src === '' || $src === '#') {
            return true;
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
}
