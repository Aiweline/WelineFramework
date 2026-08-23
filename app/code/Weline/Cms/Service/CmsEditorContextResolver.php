<?php

declare(strict_types=1);

namespace Weline\Cms\Service;

use Weline\Cms\Api\Data\CmsEditorContext;
use Weline\Cms\Model\Page;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Layout\LayoutScopeNormalizerInterface;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class CmsEditorContextResolver
{
    public function __construct(
        private readonly StoreCatalogInterface $stores,
        private readonly ScopeHierarchyInterface $scopeHierarchy,
        private readonly LayoutScopeNormalizerInterface $themeScopes,
        private readonly PageLocaleService $locales,
    ) {
    }

    public function resolve(
        Page $page,
        ?int $requestedStoreId,
        string $requestedLocale,
        bool $forPublish = false,
    ): CmsEditorContext {
        $store = $requestedStoreId !== null && $requestedStoreId > 0
            ? $this->stores->byId($requestedStoreId)
            : $this->stores->defaultStore($page->getWebsiteId());
        if (!$store instanceof StoreSummary) {
            throw new \RuntimeException((string)__(
                '网站 %{1} 缺少默认店铺，请先执行 Websites 升级修复。',
                [$page->getWebsiteId()],
            ));
        }
        if ($store->websiteId !== $page->getWebsiteId()) {
            throw new \InvalidArgumentException((string)__('选择的店铺不属于当前 CMS 页面网站。'));
        }
        if ($store->lifecycleStatus !== 'active' || $store->tombstonedAt !== null) {
            throw new \InvalidArgumentException((string)__('墓碑店铺不能用于 CMS 页面编辑或发布。'));
        }
        if ($forPublish && !$store->enabled) {
            throw new \InvalidArgumentException((string)__('已停用店铺只能保留草稿，不能发布 CMS 页面变体。'));
        }

        $locale = trim($requestedLocale) !== ''
            ? $this->locales->assertWebsiteLocale($page->getWebsiteId(), $requestedLocale)
            : $this->locales->resolveSourceLocale($page);
        $identity = ScopeIdentity::store(
            $page->getWebsiteId(),
            $page->getWebsiteCode(),
            $store->code,
            $store->storeMode,
        );
        $storageScope = $this->scopeHierarchy->toStorageScope($identity);
        $canonicalScope = $this->themeScopes->encodeStorageScope($storageScope, $store->storeMode);

        return new CmsEditorContext(
            $page->getWebsiteId(),
            $page->getWebsiteCode(),
            $store->id,
            $store->code,
            $store->name,
            $store->storeMode,
            $locale,
            $canonicalScope,
            $identity,
            $store->isDefault,
            $store->enabled,
            $store->lifecycleStatus,
        );
    }

    /** @return list<array<string,mixed>> */
    public function activeStoreOptions(Page $page): array
    {
        $options = [];
        foreach ($this->stores->byWebsite($page->getWebsiteId()) as $store) {
            if ($store->lifecycleStatus !== 'active' || $store->tombstonedAt !== null) {
                continue;
            }
            $identity = ScopeIdentity::store(
                $page->getWebsiteId(),
                $page->getWebsiteCode(),
                $store->code,
                $store->storeMode,
            );
            $storageScope = $this->scopeHierarchy->toStorageScope($identity);
            $options[] = array_replace($store->toArray(), [
                // The browser must never reconstruct canonical Store scopes.
                // ScopeIdentity + the Theme normalizer remain the sole source
                // of truth for the three-part identity and mode suffix.
                'canonical_scope' => $this->themeScopes->encodeStorageScope(
                    $storageScope,
                    $store->storeMode,
                ),
            ]);
        }
        return $options;
    }

    public function defaultStore(Page $page): StoreSummary
    {
        $store = $this->stores->defaultStore($page->getWebsiteId());
        if (!$store instanceof StoreSummary) {
            throw new \RuntimeException((string)__(
                '网站 %{1} 缺少默认店铺，请先执行 Websites 升级修复。',
                [$page->getWebsiteId()],
            ));
        }
        return $store;
    }
}
