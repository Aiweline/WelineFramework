<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\LocalDomainPolicy;
use Weline\Websites\Observer\DetectWebsite;
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
        $this->virtualThemeModel = $virtualThemeModel ?? ObjectManager::getInstance(VirtualTheme::class);
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
        $pageTypeLayouts = $this->resolveVirtualThemePublishLayouts(
            $virtualThemeId,
            $pageTypes,
            $pageTypeLayouts
        );
        $materialized = $this->materializationService->materialize(
            $websiteId,
            $websiteProfile,
            $pageTypes,
            $pageTypeLayouts,
            $virtualPagesByType
        );
        $this->sanitizeAiLayoutsForMaterializedPages($materialized['pagebuilder_pages_by_type'] ?? []);
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
        $visualUrls = $this->visualUrlService->normalizeUrlsToLocalBase(
            $this->visualUrlService->resolveUrls($previewPageId, $virtualThemeId),
            ['website_profile' => $websiteProfile]
        );

        return \array_replace(
            $materialized,
            [
                'materialized_pages_by_type' => $materialized['pagebuilder_pages_by_type'] ?? [],
                'publish_verification' => $verification,
                'published_at' => \date('Y-m-d H:i:s'),
                'published_virtual_theme_id' => $virtualThemeId,
            ],
            $visualUrls
        );
    }

    /**
     * The generated virtual theme is the source of truth at publish time. Older
     * callers may still pass pre-generation layouts, which would
     * materialize default/stale components instead of the AI site components.
     *
     * @param list<string> $pageTypes
     * @param array<string, array<string, mixed>> $incomingLayouts
     * @return array<string, array<string, mixed>>
     */
    private function resolveVirtualThemePublishLayouts(int $virtualThemeId, array $pageTypes, array $incomingLayouts): array
    {
        if ($virtualThemeId <= 0 || $this->virtualThemeModel === null) {
            return $incomingLayouts;
        }

        $theme = clone $this->virtualThemeModel;
        $theme->clearData()->clearQuery()->load($virtualThemeId);
        if ((int)$theme->getId() <= 0) {
            return $incomingLayouts;
        }

        $config = $theme->getConfig();
        $virtualLayouts = \is_array($config['virtual_page_layouts'] ?? null)
            ? $config['virtual_page_layouts']
            : [];
        if ($virtualLayouts === []) {
            return $incomingLayouts;
        }

        $resolved = $incomingLayouts;
        foreach ($pageTypes as $pageType) {
            if (!\is_array($virtualLayouts[$pageType] ?? null)) {
                continue;
            }
            $resolved[$pageType] = $virtualLayouts[$pageType];
        }

        return $resolved;
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
        $domain = $this->normalizeTargetDomain($websiteProfile['target_domain'] ?? '');
        if ($websiteId <= 0 || $domain === '') {
            return;
        }

        $existing = $this->findRootDomainBindingForPublish($domain, $websiteId);
        if ($existing !== null && (int)$existing->getDomainId() > 0) {
            $changed = false;
            if ((int)$existing->getWebsiteId() !== $websiteId) {
                $existing->setWebsiteId($websiteId);
                $changed = true;
            }
            if ($existing->getSubPath() !== '') {
                $existing->setSubPath('');
                $changed = true;
            }
            if (!$existing->isPrimary()) {
                $existing->setIsPrimary(true);
                $changed = true;
            }
            if ($existing->getStatus() !== WebsiteDomain::STATUS_ACTIVE) {
                $existing->setStatus(WebsiteDomain::STATUS_ACTIVE);
                $changed = true;
            }
            if ($changed) {
                $existing->save(true);
            }
            $this->disableDuplicateRootDomainBindings($domain, (int)$existing->getDomainId());
            $this->refreshWebsiteDetectionCaches();
            return;
        }

        $record = clone $this->websiteDomainModel;
        $record->clearData()->clearQuery();
        $record->setWebsiteId($websiteId)
            ->setDomain($domain)
            ->setSubPath('')
            ->setIsPrimary(true)
            ->setStatus(WebsiteDomain::STATUS_ACTIVE);

        $pool = clone $this->domainPoolModel;
        $pool->clearData()->clearQuery()->loadByDomain($domain);
        if ((int)$pool->getPoolId() > 0) {
            $record->setPoolId((int)$pool->getPoolId());
            $record->syncFromPool();
        }

        $record->save(true);
        $this->disableDuplicateRootDomainBindings($domain, (int)$record->getDomainId());
        $this->refreshWebsiteDetectionCaches();
    }

    private function findRootDomainBindingForPublish(string $domain, int $websiteId): ?WebsiteDomain
    {
        $query = clone $this->websiteDomainModel;
        $query->clearData()->clearQuery()
            ->where(WebsiteDomain::schema_fields_DOMAIN, $domain)
            ->select()
            ->fetch();

        $best = null;
        $bestScore = -1;
        foreach ($query->getItems() ?: [] as $item) {
            if (!$item instanceof WebsiteDomain || !$this->isRootDomainSubPath($item->getSubPath())) {
                continue;
            }

            $score = 0;
            if ((string)$item->getSubPath() === '') {
                $score += 8;
            }
            if ((int)$item->getWebsiteId() === $websiteId) {
                $score += 4;
            }
            if ($item->getStatus() === WebsiteDomain::STATUS_ACTIVE) {
                $score += 2;
            }
            if ($item->isPrimary()) {
                $score += 1;
            }

            if ($best === null || $score > $bestScore) {
                $best = $item;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function disableDuplicateRootDomainBindings(string $domain, int $keepDomainId): void
    {
        if ($keepDomainId <= 0) {
            return;
        }

        $query = clone $this->websiteDomainModel;
        $query->clearData()->clearQuery()
            ->where(WebsiteDomain::schema_fields_DOMAIN, $domain)
            ->select()
            ->fetch();

        foreach ($query->getItems() ?: [] as $item) {
            if (!$item instanceof WebsiteDomain || (int)$item->getDomainId() === $keepDomainId) {
                continue;
            }
            if (!$this->isRootDomainSubPath($item->getSubPath())) {
                continue;
            }
            if ($item->getStatus() !== WebsiteDomain::STATUS_DISABLED) {
                $item->setStatus(WebsiteDomain::STATUS_DISABLED)->save(true);
            }
        }
    }

    private function isRootDomainSubPath(mixed $subPath): bool
    {
        $normalized = \trim((string)$subPath);
        return $normalized === '' || $normalized === '/';
    }

    private function normalizeTargetDomain(mixed $targetDomain): string
    {
        $targetDomain = \strtolower(\trim((string)$targetDomain));
        if ($targetDomain === '') {
            return '';
        }

        $host = LocalDomainPolicy::normalizeDomain($targetDomain);
        if (\str_starts_with($host, 'www.')) {
            $host = (string)\substr($host, 4);
        }

        return LocalDomainPolicy::isStandardProjectHost($host) ? '' : $targetDomain;
    }

    private function refreshWebsiteDetectionCaches(): void
    {
        try {
            w_cache('website')->clear();
            Url::bumpWebsiteParserSitesVersion();
            DetectWebsite::clearProcessCache();
        } catch (\Throwable) {
            // Cache refresh is best-effort; the domain row remains the source of truth.
        }
    }

    /**
     * @param array<string, array<string, mixed>> $pagesByType
     */
    private function sanitizeAiLayoutsForMaterializedPages(array $pagesByType): void
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
            $page->setData(Page::schema_fields_AI_PUBLISH_SNAPSHOTS, null);
            $page->setAiLayoutArray($sanitized);
            $page->save(true);
        }
    }
}
