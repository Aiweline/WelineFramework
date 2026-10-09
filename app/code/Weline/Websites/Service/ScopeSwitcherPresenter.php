<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\Value\CanonicalStorefrontUrl;

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
                    // Stored channel/store/website entry URLs are absolute and omit
                    // the active currency/lang segments; rebuild via getFrontendUrl.
                    'url' => $clickable ? $this->localizeStorefrontEntryUrl(trim((string)$channelEntry)) : '',
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

    /**
     * Convert a stored absolute (or root-relative) channel/store entry URL into a
     * visitor href that carries the current storefront currency/language prefix.
     *
     * Absolute http(s) URLs bypass Url::getFrontendUrl prefixing (isLink), so the
     * path must be extracted first — same contract as menu localizeUrl / getUrl.
     */
    private function localizeStorefrontEntryUrl(string $entryUrl): string
    {
        $entryUrl = trim($entryUrl);
        if ($entryUrl === '') {
            return '';
        }

        $route = $this->storefrontRouteFromEntryUrl($entryUrl);
        if ($route === null) {
            return $entryUrl;
        }

        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);

            return (string)$request->getUrlBuilder()->getFrontendUrl($route);
        } catch (\Throwable) {
            try {
                /** @var Url $url */
                $url = ObjectManager::getInstance(Url::class);

                return (string)$url->getFrontendUrl($route);
            } catch (\Throwable) {
                return $entryUrl;
            }
        }
    }

    /**
     * Relative route for getFrontendUrl: '/' for site root, else path without leading slash.
     */
    private function storefrontRouteFromEntryUrl(string $entryUrl): ?string
    {
        $path = '';
        if (preg_match('#^https?://#i', $entryUrl) === 1) {
            try {
                $path = CanonicalStorefrontUrl::fromStoreUrl($entryUrl)->path;
            } catch (\InvalidArgumentException) {
                $parsed = parse_url($entryUrl);
                $path = is_array($parsed) ? (string)($parsed['path'] ?? '/') : '';
            }
        } elseif (str_starts_with($entryUrl, '/')) {
            $path = $entryUrl;
        } else {
            // Bare relative storefront path (rare for channel.url; keep usable).
            $path = '/' . ltrim(str_replace('\\', '/', $entryUrl), '/');
        }

        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return null;
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        // Drop query/fragment if a relative entry ever carries them.
        $question = strpos($path, '?');
        if ($question !== false) {
            $path = substr($path, 0, $question);
        }
        $hash = strpos($path, '#');
        if ($hash !== false) {
            $path = substr($path, 0, $hash);
        }

        $path = CanonicalStorefrontUrl::canonicalPath($path);
        if ($path === '/') {
            return '/';
        }

        return ltrim($path, '/');
    }
}
