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
            // 重新加载虚拟主题
            $theme = clone $this->virtualThemeModel;
            $theme->clearData()->clearQuery()->load($virtualThemeId);

            if ($theme->getId() > 0) {
                // 获取并更新配置
                $config = $theme->getConfig();
                $config['published_at'] = \date('Y-m-d H:i:s');
                $config['materialized_pages_by_type'] = $materialized['pagebuilder_pages_by_type'] ?? [];

                // 使用 Model 的 query builder 执行 UPDATE
                $theme->clearQuery()
                    ->where(VirtualTheme::schema_fields_ID, $virtualThemeId)
                    ->update([
                        VirtualTheme::schema_fields_WEBSITE_ID => $websiteId,
                        VirtualTheme::schema_fields_IS_ACTIVE => 1,
                        VirtualTheme::schema_fields_CONFIG => json_encode($config, JSON_UNESCAPED_UNICODE),
                    ]);
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
