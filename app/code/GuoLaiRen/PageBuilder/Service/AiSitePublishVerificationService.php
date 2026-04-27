<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Manager\ObjectManager;

class AiSitePublishVerificationService
{
    private readonly Page $pageModel;
    private readonly PageRenderService $pageRenderService;

    public function __construct(
        ?Page $pageModel = null,
        ?PageRenderService $pageRenderService = null
    ) {
        $this->pageModel = $pageModel ?? ObjectManager::getInstance(Page::class);
        $this->pageRenderService = $pageRenderService ?? ObjectManager::getInstance(PageRenderService::class);
    }

    /**
     * @param array<string, array<string, mixed>> $pagesByType
     * @param array<string, mixed> $websiteProfile
     * @return array{passed:bool,pages:array<string,array<string,mixed>>}
     */
    public function assertPublishedPagesRenderable(
        array $pagesByType,
        int $virtualThemeId,
        string $workspaceTrack,
        array $websiteProfile = []
    ): array {
        if ($pagesByType === []) {
            throw new \RuntimeException((string)__('AI publish verification failed: no materialized pages were produced.'));
        }

        $track = $workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS
            ? AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS
            : AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME;
        $reports = [];

        foreach ($pagesByType as $pageType => $row) {
            $pageId = (int)($row['page_id'] ?? 0);
            if ($pageId <= 0) {
                throw new \RuntimeException((string)__('AI publish verification failed: page %{1} has no materialized page_id.', [$pageType]));
            }

            $page = $this->loadPage($pageId);
            if ((int)$page->getId() <= 0) {
                throw new \RuntimeException((string)__('AI publish verification failed: materialized page %{1} does not exist.', [$pageId]));
            }

            $html = $this->pageRenderService->render(
                $page,
                PageRenderService::MODE_LIVE,
                null,
                null,
                $track === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME && $virtualThemeId > 0
                    ? $virtualThemeId
                    : null
            );

            $report = $this->inspectRenderedPage(
                (string)$pageType,
                $pageId,
                $html,
                $track,
                $virtualThemeId,
                $websiteProfile,
                $page
            );
            $reports[(string)$pageType] = $report;

            if (empty($report['passed'])) {
                $reason = \implode('; ', \array_map('strval', $report['failures'] ?? []));
                throw new \RuntimeException((string)__(
                    'AI publish verification failed for %{1}: %{2}',
                    [$pageType, $reason !== '' ? $reason : 'rendered page did not pass publish checks']
                ));
            }
        }

        return [
            'passed' => true,
            'pages' => $reports,
        ];
    }

    private function loadPage(int $pageId): Page
    {
        $page = clone $this->pageModel;
        $page->clearData()->clearQuery()->load($pageId);

        return $page;
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    private function inspectRenderedPage(
        string $pageType,
        int $pageId,
        string $html,
        string $workspaceTrack,
        int $virtualThemeId,
        array $websiteProfile,
        Page $page
    ): array {
        $failures = [];
        $signals = [
            'html_length' => \strlen($html),
            'virtual_theme_marker' => $this->hasVirtualThemeMarker($html),
            'ai_site_marker' => $this->hasAiSiteMarker($html),
            'brand_visible' => $this->hasBrandSignal($html, $websiteProfile),
            'default_template_marker' => $this->hasDefaultTemplateMarker($html),
            'internal_planning_copy_marker' => $this->hasInternalPlanningCopyMarker($html),
            'ai_html_mode' => $page->isAiHtmlRenderMode(),
        ];

        if (\trim($html) === '' || \strlen($html) < 120) {
            $failures[] = 'rendered HTML is empty or too small';
        }

        if ($signals['default_template_marker']) {
            $failures[] = 'rendered HTML contains default template markers';
        }
        if ($signals['internal_planning_copy_marker']) {
            $failures[] = 'rendered HTML contains internal planning or visitor-observation copy';
        }

        if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME) {
            if ($virtualThemeId <= 0) {
                $failures[] = 'virtual theme publish track has no virtual_theme_id';
            }
            if (!$signals['virtual_theme_marker'] && !$signals['ai_site_marker']) {
                $failures[] = 'rendered HTML did not resolve AI virtual theme components';
            }
        } elseif (!$signals['ai_html_mode'] && !$signals['ai_site_marker']) {
            $failures[] = 'HTML-block publish track did not render AI HTML content';
        }

        if (!$signals['brand_visible']) {
            $failures[] = 'site brand is not visible in rendered HTML';
        }

        return [
            'passed' => $failures === [],
            'page_type' => $pageType,
            'page_id' => $pageId,
            'signals' => $signals,
            'failures' => $failures,
        ];
    }

    private function hasDefaultTemplateMarker(string $html): bool
    {
        foreach ([
            '欢迎访问',
            '默认页面模板',
            'Default Page Template',
            'default page template',
            'This is the default page',
        ] as $marker) {
            if (\stripos($html, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function hasVirtualThemeMarker(string $html): bool
    {
        return \stripos($html, 'resolved via Weline_Theme virtual theme') !== false
            || \stripos($html, 'data-weline-theme-component') !== false
            || \stripos($html, 'weline-theme-component') !== false;
    }

    private function hasInternalPlanningCopyMarker(string $html): bool
    {
        $visible = \preg_replace('/<!--.*?-->|<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>/isu', ' ', $html) ?? $html;
        $visible = \html_entity_decode((string)\preg_replace('/<[^>]+>/u', ' ', $visible), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $visible = \preg_replace('/\s+/u', ' ', $visible) ?? $visible;

        return \preg_match(
            '/访客看到|用户看到|让访客看到|从而产生|信任感增强|知道如何|Visitors?\s+(?:see|can review|can verify|understand how|ready to)|before publishing|reviewable page content/iu',
            $visible
        ) === 1;
    }

    private function hasAiSiteMarker(string $html): bool
    {
        return \stripos($html, 'pb-ai-site') !== false
            || \stripos($html, 'pb-ai-generated-section') !== false
            || \stripos($html, 'ai-site-') !== false
            || \stripos($html, 'data-pb-block') !== false;
    }

    /**
     * @param array<string, mixed> $websiteProfile
     */
    private function hasBrandSignal(string $html, array $websiteProfile): bool
    {
        $candidates = [
            (string)($websiteProfile['site_title'] ?? ''),
            (string)($websiteProfile['brand_name'] ?? ''),
            (string)($websiteProfile['name'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = \trim($candidate);
            if ($candidate !== '' && \mb_stripos($html, $candidate) !== false) {
                return true;
            }
        }

        return \trim(\implode('', $candidates)) === '';
    }
}
