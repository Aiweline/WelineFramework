<?php

declare(strict_types=1);

namespace Weline\Websites\Integration\SystemConfig;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;
use Weline\Websites\Api\Catalog\Data\WebsiteSummary;

/** Official Websites catalogs projected into the generic SystemConfig boundary. */
final class ScopeIdentityCatalog implements ScopeIdentityCatalogInterface
{
    public function __construct(
        private readonly WebsiteCatalogInterface $websites,
        private readonly StoreCatalogInterface $stores,
        private readonly SalesChannelCatalogInterface $channels,
    ) {
    }

    public function websiteIdForCode(string $websiteCode): int
    {
        $website = $this->websiteByCode($websiteCode);
        if (!$website instanceof WebsiteSummary) {
            throw new \InvalidArgumentException('system_config_website_scope_not_found');
        }

        return $website->id;
    }

    public function authoritativeIdentity(ScopeIdentity $candidate): ScopeIdentity
    {
        if ($candidate->isGlobal()) {
            return ScopeIdentity::global($candidate->contextVersion);
        }

        $website = $this->websiteByCode((string)$candidate->websiteCode);
        if (!$website instanceof WebsiteSummary || $website->id !== $candidate->websiteId) {
            throw new \InvalidArgumentException('system_config_scope_claim_identity_mismatch');
        }
        if ($candidate->scopeKind === ScopeIdentity::KIND_WEBSITE) {
            return ScopeIdentity::website($website->id, $website->code, $candidate->contextVersion);
        }

        $store = $this->stores->byCode($website->id, (string)$candidate->storeCode);
        if ($store === null || $store->websiteId !== $website->id) {
            throw new \InvalidArgumentException('system_config_store_scope_not_found');
        }
        if ($candidate->storeMode !== $store->storeMode) {
            throw new \InvalidArgumentException('system_config_scope_claim_identity_mismatch');
        }
        if ($candidate->scopeKind === ScopeIdentity::KIND_STORE) {
            return ScopeIdentity::store(
                $website->id,
                $website->code,
                $store->code,
                $store->storeMode,
                $candidate->contextVersion,
            );
        }

        $channel = $this->channels->byCode($store->id, (string)$candidate->channelCode);
        if ($channel === null || $channel->websiteId !== $website->id || $channel->storeId !== $store->id) {
            throw new \InvalidArgumentException('system_config_channel_scope_not_found');
        }

        return ScopeIdentity::channel(
            $website->id,
            $website->code,
            $store->code,
            $channel->code,
            $store->storeMode,
            $candidate->contextVersion,
        );
    }

    public function options(): array
    {
        $out = [];
        foreach ($this->websites->all() as $website) {
            $stores = [];
            foreach ($this->stores->byWebsite($website->id) as $store) {
                $channels = [];
                foreach ($this->channels->byStore($store->id) as $channel) {
                    $channels[] = [
                        'id' => $channel->id,
                        'code' => $channel->code,
                        'name' => $channel->name,
                    ];
                }
                $stores[] = [
                    'id' => $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'store_mode' => $store->storeMode,
                    'channels' => $channels,
                ];
            }
            $out[] = [
                'code' => $website->code,
                'name' => $website->name,
                'website_id' => $website->id,
                'stores' => $stores,
            ];
        }

        return $out;
    }

    private function websiteByCode(string $websiteCode): ?WebsiteSummary
    {
        $websiteCode = \strtolower(\trim($websiteCode));
        foreach ($this->websites->all() as $website) {
            if (\strtolower($website->code) === $websiteCode) {
                return $website;
            }
        }

        return null;
    }
}
