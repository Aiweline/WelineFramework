<?php
declare(strict_types=1);

namespace Weline\Cdn\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/** Clear the public host/path ranges changed by an existing store/channel save event. */
final class ScopeChanged implements ObserverInterface
{
    public function __construct(
        private readonly StoreCatalogInterface $stores,
        private readonly WebsiteCatalogInterface $websites,
        private readonly CdnRequest $cdnRequest,
    ) {
    }

    public function execute(Event &$event): void
    {
        $websiteId = $event->getData('website_id');
        if ($websiteId === null || $websiteId === '' || !is_numeric($websiteId)) {
            return;
        }
        $websiteId = (int)$websiteId;
        $urls = [];
        try {
            $store = $event->getData('store');
            $before = $event->getData('before');
            if (is_array($store)) {
                $urls[] = $this->publicUrl($websiteId, $store['url'] ?? null);
                if (is_array($before)) {
                    $urls[] = $this->publicUrl($websiteId, $before['url'] ?? null);
                }
            } else {
                $storeIds = [$event->getData('store_id')];
                if (is_array($before) && array_key_exists('store_id', $before)) {
                    $storeIds[] = $before['store_id'];
                }
                foreach ($storeIds as $storeId) {
                    if ($storeId === null || $storeId === '') {
                        continue;
                    }
                    $summary = $this->stores->byId((int)$storeId);
                    if ($summary !== null && $summary->websiteId === $websiteId) {
                        $urls[] = $this->publicUrl($websiteId, $summary->url);
                    }
                }
            }
            $urls = array_values(array_unique(array_filter($urls, static fn(string $url): bool => preg_match('#^https?://#i', $url) === 1)));
            if ($urls === []) {
                return;
            }
            $request = new Event(['data' => ['action' => 'purge_scope', 'website_id' => $websiteId, 'data' => ['urls' => $urls]]]);
            $this->cdnRequest->execute($request);
            $event->setData('cdn_result', $request->getData('response'));
        } catch (\Throwable $e) {
            $event->setData('cdn_result', ['success' => false, 'message' => __('CDN 资源变更清理失败')]);
            w_log_error('CDN scope URL purge failed: ' . $e->getMessage());
        }
    }

    private function publicUrl(int $websiteId, ?string $storeUrl): string
    {
        if (trim((string)$storeUrl) !== '') {
            return trim((string)$storeUrl);
        }
        foreach ($this->websites->all() as $website) {
            if ($website->id === $websiteId) {
                return trim($website->url);
            }
        }
        return '';
    }
}
