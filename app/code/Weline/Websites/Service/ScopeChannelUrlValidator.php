<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\Value\CanonicalStorefrontUrl;

/**
 * Validates SalesChannel.url: same Origin as parent Store (or Website), path extends store entry.
 */
final class ScopeChannelUrlValidator
{
    public function __construct(
        private readonly Store $storeModel,
        private readonly Website $websiteModel,
    ) {
    }

    public function normalizeAndAssert(?string $rawUrl, int $storeId): ?string
    {
        $raw = trim((string)$rawUrl);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^https?:\/\//i', $raw)) {
            $raw = 'http://' . $raw;
        }

        try {
            $channelUrl = CanonicalStorefrontUrl::fromStoreUrl($raw);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException(
                (string)__('渠道入口 URL 无效'),
                0,
                $exception,
            );
        }

        $baseUrl = $this->resolveParentEntryUrl($storeId);
        if ($baseUrl === null) {
            throw new \InvalidArgumentException((string)__('父店铺缺少可用入口 URL，无法配置渠道入口'));
        }

        try {
            $parentUrl = CanonicalStorefrontUrl::fromStoreUrl($baseUrl);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException(
                (string)__('父店铺入口 URL 无效，无法配置渠道入口'),
                0,
                $exception,
            );
        }

        if (!$channelUrl->sameOrigin($parentUrl)) {
            throw new \InvalidArgumentException((string)__('渠道入口 URL 必须与父店铺同 Origin'));
        }

        $parentPath = $parentUrl->path;
        $channelPath = $channelUrl->path;
        if ($parentPath === '/') {
            if ($channelPath === '/') {
                throw new \InvalidArgumentException((string)__('渠道入口路径不能与站点根路径完全相同'));
            }
        } elseif ($channelPath === $parentPath || !str_starts_with($channelPath, $parentPath . '/')) {
            throw new \InvalidArgumentException((string)__('渠道入口路径必须是父店铺入口路径的严格延伸'));
        }

        return $channelUrl->toString();
    }

    private function resolveParentEntryUrl(int $storeId): ?string
    {
        $store = clone $this->storeModel;
        $store->clear()->load($storeId);
        if ((int)$store->getData(Store::schema_fields_ID) !== $storeId
            && !($storeId === Store::ID_DEFAULT && $store->hasData(Store::schema_fields_CODE))
        ) {
            return null;
        }
        $storeUrl = trim((string)$store->getData(Store::schema_fields_URL));
        if ($storeUrl !== '') {
            return $storeUrl;
        }

        $websiteId = (int)$store->getData(Store::schema_fields_WEBSITE_ID);
        $website = clone $this->websiteModel;
        $website->clear()->load($websiteId);
        $websiteUrl = trim((string)$website->getData(Website::schema_fields_URL));

        return $websiteUrl !== '' ? $websiteUrl : null;
    }
}
