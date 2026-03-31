<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\VirtualTheme;

class AiSitePublishService
{
    public function __construct(
        private readonly AiSiteMaterializationService $materializationService,
        private readonly AiSiteVisualUrlService $visualUrlService,
        private readonly VirtualTheme $virtualThemeModel,
    ) {
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param list<string> $pageTypes
     * @param array<string, array<string, mixed>> $pageTypeLayouts
     * @return array<string, mixed>
     */
    public function publish(
        int $websiteId,
        int $virtualThemeId,
        array $websiteProfile,
        array $pageTypes,
        array $pageTypeLayouts
    ): array {
        $materialized = $this->materializationService->materialize(
            $websiteId,
            $websiteProfile,
            $pageTypes,
            $pageTypeLayouts
        );

        if ($virtualThemeId > 0) {
            $theme = clone $this->virtualThemeModel;
            $theme->clearData()->clearQuery()->load($virtualThemeId);
            if ($theme->getId()) {
                $config = $theme->getConfig();
                $config['published_at'] = \date('Y-m-d H:i:s');
                $config['materialized_pages_by_type'] = $materialized['pagebuilder_pages_by_type'] ?? [];
                $theme->setWebsiteId($websiteId)
                    ->setIsActive(true)
                    ->setConfig($config)
                    ->save();
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
}
