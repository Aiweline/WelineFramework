<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\WebsiteDomain;

class AiSitePublishService
{
    private readonly AiHtmlSanitizerService $htmlSanitizer;
    private readonly ?VirtualTheme $virtualThemeModel;
    private readonly Page $pageModel;
    private readonly WebsiteDomain $websiteDomainModel;
    private readonly DomainPool $domainPoolModel;
    private readonly AiSitePublishVerificationService $publishVerificationService;

    public function __construct(
        private readonly AiSiteMaterializationService $materializationService,
        private readonly AiSiteVisualUrlService $visualUrlService,
        ?VirtualTheme $virtualThemeModel = null,
        ?AiHtmlSanitizerService $htmlSanitizer = null,
        ?Page $pageModel = null,
        ?WebsiteDomain $websiteDomainModel = null,
        ?DomainPool $domainPoolModel = null,
        ?AiSitePublishVerificationService $publishVerificationService = null,
    ) {
        $this->virtualThemeModel = $virtualThemeModel;
        $this->htmlSanitizer = $htmlSanitizer ?? ObjectManager::getInstance(AiHtmlSanitizerService::class);
        $this->pageModel = $pageModel ?? ObjectManager::getInstance(Page::class);
        $this->websiteDomainModel = $websiteDomainModel ?? ObjectManager::getInstance(WebsiteDomain::class);
        $this->domainPoolModel = $domainPoolModel ?? ObjectManager::getInstance(DomainPool::class);
        $this->publishVerificationService = $publishVerificationService ?? ObjectManager::getInstance(AiSitePublishVerificationService::class);
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
            $verification = $this->publishVerificationService->assertPublishedPagesRenderable(
                $materialized['pagebuilder_pages_by_type'] ?? [],
                0,
                $workspaceTrack,
                $websiteProfile
            );
            $this->ensureWebsiteDomainBinding($websiteId, $websiteProfile);

            $previewPageId = (int)($materialized['preview_page_id'] ?? 0);

            return \array_replace(
                $materialized,
                [
                    'materialized_pages_by_type' => $materialized['pagebuilder_pages_by_type'] ?? [],
                    'publish_verification' => $verification,
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
        $this->applyPublishSnapshotsForMaterializedPages($materialized['pagebuilder_pages_by_type'] ?? []);
        $this->ensureWebsiteDomainBinding($websiteId, $websiteProfile);

        if ($virtualThemeId > 0 && $this->virtualThemeModel !== null) {
            $this->deactivateOtherActiveVirtualThemes($websiteId, $virtualThemeId);
            // 重新加载虚拟主题
            $theme = clone $this->virtualThemeModel;
            $theme->clearData()->clearQuery()->load($virtualThemeId);

            if ($theme->getId() > 0) {
                $config = $theme->getConfig();
                $config['published_at'] = \date('Y-m-d H:i:s');
                $config['published_virtual_theme_id'] = $virtualThemeId;
                $config['publish_workspace_track'] = $workspaceTrack;
                $config['materialized_pages_by_type'] = $materialized['pagebuilder_pages_by_type'] ?? [];

                // 链式 query->update()->fetch() 在部分 Model 委托路径下可能未执行；save() 走标准变更落库
                $theme->setWebsiteId($websiteId)
                    ->setIsActive(true)
                    ->setConfig($config)
                    ->save(true);
            }
        }
        $verification = $this->publishVerificationService->assertPublishedPagesRenderable(
            $materialized['pagebuilder_pages_by_type'] ?? [],
            $virtualThemeId,
            $workspaceTrack,
            $websiteProfile
        );

        $previewPageId = (int)($materialized['preview_page_id'] ?? 0);

        return \array_replace(
            $materialized,
            [
                'materialized_pages_by_type' => $materialized['pagebuilder_pages_by_type'] ?? [],
                'publish_verification' => $verification,
                'published_at' => \date('Y-m-d H:i:s'),
                'published_virtual_theme_id' => $virtualThemeId,
            ],
            $this->visualUrlService->resolveUrls($previewPageId, $virtualThemeId)
        );
    }

    private function deactivateOtherActiveVirtualThemes(int $websiteId, int $keepVirtualThemeId): void
    {
        if ($websiteId <= 0 || $keepVirtualThemeId <= 0) {
            return;
        }
        if ($this->virtualThemeModel === null) {
            return;
        }

        $activeThemes = clone $this->virtualThemeModel;
        $activeThemes->clearData()->clearQuery()
            ->where(VirtualTheme::schema_fields_WEBSITE_ID, $websiteId)
            ->where(VirtualTheme::schema_fields_SOURCE, VirtualTheme::SOURCE_PAGEBUILDER_AI)
            ->where(VirtualTheme::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        $items = $activeThemes->getItems();
        if (!\is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (!$item instanceof VirtualTheme) {
                continue;
            }
            if ((int)$item->getId() === $keepVirtualThemeId) {
                continue;
            }
            $item->setIsActive(false)->save(true);
        }
    }

    /**
     * @param array<string, mixed> $websiteProfile
     */
    private function ensureWebsiteDomainBinding(int $websiteId, array $websiteProfile): void
    {
        $domain = \strtolower(\trim((string)($websiteProfile['target_domain'] ?? '')));
        if ($websiteId <= 0 || $domain === '') {
            return;
        }

        $existing = clone $this->websiteDomainModel;
        $existing->clearData()->clearQuery()->loadByDomain($domain);
        if ((int)$existing->getDomainId() > 0) {
            if ((int)$existing->getWebsiteId() === $websiteId
                && $existing->getStatus() !== WebsiteDomain::STATUS_ACTIVE) {
                $existing->setStatus(WebsiteDomain::STATUS_ACTIVE)->save(true);
            }
            return;
        }

        $record = clone $this->websiteDomainModel;
        $record->clearData()->clearQuery();
        $record->setWebsiteId($websiteId)
            ->setDomain($domain)
            ->setSubPath('')
            ->setIsPrimary(false)
            ->setStatus(WebsiteDomain::STATUS_ACTIVE);

        $pool = clone $this->domainPoolModel;
        $pool->clearData()->clearQuery()->loadByDomain($domain);
        if ((int)$pool->getPoolId() > 0) {
            $record->setPoolId((int)$pool->getPoolId());
            $record->syncFromPool();
        }

        $record->save(true);
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
