<?php
declare(strict_types=1);

namespace Weline\Seo\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Seo\Service\SeoWebsiteDirectory;
use Weline\Seo\Service\SitemapRefreshService;
use Weline\Seo\Service\UrlSubmitService;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/** Stores and channels inherit the website's search-engine account bindings. */
class ScopeSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly SeoWebsiteDirectory $websites,
        private readonly StoreCatalogInterface $stores,
        private readonly SitemapRefreshService $sitemaps,
        private readonly UrlSubmitService $submissions,
    ) {}

    public function execute(Event &$event): void
    {
        $websiteId = (int)($event->getData('website_id') ?? -1);
        if ($websiteId < 0) {
            return;
        }
        $channel = $event->getData('channel');
        $isChannel = is_array($channel);
        $current = $isChannel ? $channel : (array)$event->getData('store');
        $before = (array)$event->getData('before');
        // Scope changes can alter homepage and product visibility together.
        $this->sitemaps->enqueue($websiteId, '');
        $storeMode = $isChannel
            ? ($this->stores->byId((int)($current['store_id'] ?? 0))?->storeMode ?? 'normal')
            : ($current['store_mode'] ?? 'normal');
        if ((new \Weline\Seo\Service\StoreModeSeoHardGate())->isHardNoIndexMode((string)$storeMode)) {
            return;
        }
        $website = $this->websites->getWebsiteById($websiteId) ?? [];
        $fallback = $this->websites->effectivePublicBaseUrl($website);
        $currentUrl = $this->url($current, $isChannel, $fallback);
        $previousUrl = $this->url($before, $isChannel, $fallback);
        $scope = $isChannel ? 'channel' : 'store';
        $context = ['module' => 'Weline_Websites', 'subject_type' => $scope,
            'subject_id' => (int)($current[$scope . '_id'] ?? 0),
            'store_id' => (int)($current['store_id'] ?? 0), 'action' => 'upsert'];
        $dedicatedStoreDisabled = !$isChannel && !empty($current['url'])
            && $currentUrl !== rtrim($fallback, '/')
            && (isset($current['enabled']) && !$current['enabled']);
        if ($currentUrl !== '') {
            $this->submissions->enqueueTargets([['website_id' => $websiteId, 'url' => $currentUrl]], $scope,
                array_replace($context, ['action' => $dedicatedStoreDisabled ? 'delete' : 'upsert']));
        }
        if ($previousUrl !== '' && $previousUrl !== $currentUrl && $previousUrl !== $fallback) {
            $this->submissions->enqueueTargets([['website_id' => $websiteId, 'url' => $previousUrl]], $scope,
                array_replace($context, ['action' => 'delete']));
        }
    }

    private function url(array $scope, bool $isChannel, string $fallback): string
    {
        $url = $isChannel
            ? ($this->stores->byId((int)($scope['store_id'] ?? 0))?->url ?? '')
            : ($scope['url'] ?? '');
        return rtrim(trim((string)$url) ?: $fallback, '/');
    }
}
