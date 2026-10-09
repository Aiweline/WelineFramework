<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

/**
 * Frontend scope-switcher projection: channels under the current website (trigger shows channel only).
 */
final class ScopeSwitcherPresenter
{
    public function __construct(
        private readonly StoreCatalogInterface $stores,
        private readonly SalesChannelCatalogInterface $channels,
        private readonly Website $websiteModel,
        private readonly ScopeChannelUrlReader $channelUrls,
        private readonly ScopeDisplayTypeResolver $displayTypes,
    ) {
    }

    /**
     * @return array{
     *   visible:bool,
     *   website_id:int,
     *   website_name:string,
     *   current_store_id:int,
     *   current_channel_id:int,
     *   current_label:string,
     *   display_type:string,
     *   stores:list<array<string,mixed>>
     * }
     */
    public function presentCurrentWebsite(): array
    {
        $websiteId = RequestContext::getWelineWebsiteId();
        $currentStoreId = max(0, RequestContext::getWelineStoreId());
        $currentChannelId = max(0, RequestContext::getWelineChannelId());
        if ($websiteId < 0) {
            return $this->emptyPresentation($currentStoreId, $currentChannelId);
        }

        $websiteName = $this->websiteName($websiteId);
        $websiteUrl = $this->websiteEntryUrl($websiteId);
        $storesOut = [];
        $navigableCount = 0;
        $nonDefaultCount = 0;
        $currentLabel = '';

        foreach ($this->stores->byWebsite($websiteId) as $store) {
            if (!$store->enabled || $store->lifecycleStatus !== Store::LIFECYCLE_ACTIVE) {
                continue;
            }
            $storeEntry = $store->url !== null && trim($store->url) !== ''
                ? trim($store->url)
                : ($store->isDefault ? $websiteUrl : null);
            $storeLabel = self::displayName((string)$store->name, (string)$store->code);

            $channelRows = [];
            $channelUrlMap = $this->channelUrls->urlsByStore($store->id);
            foreach ($this->channels->byStore($store->id) as $channel) {
                if (!$channel->enabled || !$channel->effectiveEnabled) {
                    continue;
                }
                $channelEntry = $channelUrlMap[$channel->id] ?? null;
                if ($channelEntry === null && $channel->isDefault && $channel->code === SalesChannel::CODE_DEFAULT) {
                    $channelEntry = $storeEntry;
                }
                $clickable = is_string($channelEntry) && trim($channelEntry) !== '';
                if ($clickable) {
                    $navigableCount++;
                }
                if (!$channel->isDefault || $channel->code !== SalesChannel::CODE_DEFAULT) {
                    $nonDefaultCount++;
                }

                $channelLabel = self::displayName((string)$channel->name, (string)$channel->code);
                $current = $channel->id === $currentChannelId && $store->id === $currentStoreId;
                if ($current) {
                    $currentLabel = self::formatCurrentLabel($channelLabel);
                }

                $channelRows[] = [
                    'channel_id' => $channel->id,
                    'code' => $channel->code,
                    'name' => $channel->name,
                    'label' => $channelLabel,
                    'is_default' => $channel->isDefault,
                    'url' => $clickable ? trim((string)$channelEntry) : '',
                    'clickable' => $clickable,
                    'current' => $current,
                ];
            }

            if ($channelRows === []) {
                continue;
            }
            if (!$store->isDefault || $store->code !== Store::CODE_DEFAULT) {
                $nonDefaultCount++;
            }
            $storesOut[] = [
                'store_id' => $store->id,
                'code' => $store->code,
                'name' => $store->name,
                'label' => $storeLabel,
                'is_default' => $store->isDefault,
                'url' => is_string($storeEntry) ? $storeEntry : '',
                'channels' => $channelRows,
            ];
        }

        $visible = $storesOut !== [] && $nonDefaultCount > 0 && $navigableCount > 0;

        return [
            'visible' => $visible,
            'website_id' => $websiteId,
            'website_name' => $websiteName,
            'current_store_id' => $currentStoreId,
            'current_channel_id' => $currentChannelId,
            'current_label' => $currentLabel,
            'display_type' => $this->displayTypes->currentEffectiveDisplayType(),
            'stores' => $storesOut,
        ];
    }

    /** Prefer human name; fall back to code only when name empty. */
    public static function displayName(string $name, string $code): string
    {
        $name = trim($name);
        if ($name !== '') {
            return $name;
        }

        return trim($code);
    }

    /** Trigger text: current channel name only. */
    public static function formatCurrentLabel(string $channelLabel): string
    {
        return trim($channelLabel);
    }

    /**
     * @return array{visible:bool,website_id:int,website_name:string,current_store_id:int,current_channel_id:int,current_label:string,display_type:string,stores:list}
     */
    private function emptyPresentation(int $storeId, int $channelId): array
    {
        return [
            'visible' => false,
            'website_id' => -1,
            'website_name' => '',
            'current_store_id' => $storeId,
            'current_channel_id' => $channelId,
            'current_label' => '',
            'display_type' => '',
            'stores' => [],
        ];
    }

    private function websiteName(int $websiteId): string
    {
        $website = clone $this->websiteModel;
        $website->clear()->load($websiteId);
        $name = trim((string)$website->getName());
        if ($name !== '') {
            return $name;
        }
        $code = trim((string)$website->getData(Website::schema_fields_CODE));

        return $code !== '' ? $code : ('#' . $websiteId);
    }

    private function websiteEntryUrl(int $websiteId): ?string
    {
        $website = clone $this->websiteModel;
        $website->clear()->load($websiteId);
        $url = trim((string)$website->getData(Website::schema_fields_URL));

        return $url !== '' ? $url : null;
    }
}
