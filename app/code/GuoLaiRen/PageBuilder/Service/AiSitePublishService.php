<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use Weline\Framework\Manager\ObjectManager;

class AiSitePublishService
{
    private readonly AiHtmlSanitizerService $htmlSanitizer;
    private readonly Page $pageModel;

    public function __construct(
        private readonly AiSiteMaterializationService $materializationService,
        private readonly AiSiteVisualUrlService $visualUrlService,
        private readonly VirtualTheme $virtualThemeModel,
        ?AiHtmlSanitizerService $htmlSanitizer = null,
        ?Page $pageModel = null,
    ) {
        $this->htmlSanitizer = $htmlSanitizer ?? ObjectManager::getInstance(AiHtmlSanitizerService::class);
        $this->pageModel = $pageModel ?? ObjectManager::getInstance(Page::class);
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param list<string> $pageTypes
     * @param array<string, array<string, mixed>> $pageTypeLayouts
     * @param array<string, array<string, mixed>> $virtualPagesByType
     * @return array<string, mixed>
     */
    public function publish(
        int $websiteId,
        int $virtualThemeId,
        array $websiteProfile,
        array $pageTypes,
        array $pageTypeLayouts,
        array $virtualPagesByType = [],
        string $workspaceTrack = AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
    ): array {
        if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
            $materialized = $this->materializationService->materializeHtml(
                $websiteId,
                $websiteProfile,
                $pageTypes,
                $virtualPagesByType
            );
            $this->applyPublishSnapshotsForMaterializedPages($materialized['pagebuilder_pages_by_type'] ?? []);

            $previewPageId = (int)($materialized['preview_page_id'] ?? 0);

            return \array_replace(
                $materialized,
                [
                    'materialized_pages_by_type' => $materialized['pagebuilder_pages_by_type'] ?? [],
                    'published_at' => \date('Y-m-d H:i:s'),
                ],
                $this->visualUrlService->resolveUrls($previewPageId, 0)
            );
        }

        $materialized = $this->materializationService->materialize(
            $websiteId,
            $websiteProfile,
            $pageTypes,
            $pageTypeLayouts,
            $virtualPagesByType
        );

        if ($virtualThemeId > 0) {
            // 重新加载虚拟主题
            $theme = clone $this->virtualThemeModel;
            $theme->clearData()->clearQuery()->load($virtualThemeId);

            if ($theme->getId() > 0) {
                $config = $theme->getConfig();
                $config['published_at'] = \date('Y-m-d H:i:s');
                $config['materialized_pages_by_type'] = $materialized['pagebuilder_pages_by_type'] ?? [];

                // 链式 query->update()->fetch() 在部分 Model 委托路径下可能未执行；save() 走标准变更落库
                $theme->setWebsiteId($websiteId)
                    ->setIsActive(true)
                    ->setConfig($config)
                    ->save(true);
            }
        }

        $previewPageId = (int)($materialized['preview_page_id'] ?? 0);

        return \array_replace(
            $materialized,
            [
                'materialized_pages_by_type' => $materialized['pagebuilder_pages_by_type'] ?? [],
                'published_at' => \date('Y-m-d H:i:s'),
            ],
            $this->visualUrlService->resolveUrls($previewPageId, $virtualThemeId)
        );
    }

    /**
     * @param array<string, array<string, mixed>> $pagesByType
     */
    private function applyPublishSnapshotsForMaterializedPages(array $pagesByType): void
    {
        foreach ($pagesByType as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $pageId = (int)($row['page_id'] ?? 0);
            if ($pageId <= 0) {
                continue;
            }
            $page = clone $this->pageModel;
            $page->clearData()->clearQuery()->load($pageId);
            if (!$page->getId() || !$page->isAiHtmlRenderMode()) {
                continue;
            }
            $draft = $page->getAiLayoutArray();
            $sanitized = $this->htmlSanitizer->sanitizeAiLayout($draft);
            $page->appendAiPublishSnapshot($sanitized);
            $page->setAiLayoutArray($sanitized);
            $page->save(true);
        }
    }
}
