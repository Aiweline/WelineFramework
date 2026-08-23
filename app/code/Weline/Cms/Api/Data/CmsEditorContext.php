<?php

declare(strict_types=1);

namespace Weline\Cms\Api\Data;

use Weline\Framework\Runtime\ScopeIdentity;

/** Explicit request-scoped CMS editor context. Never retain it on worker singletons. */
final readonly class CmsEditorContext
{
    public function __construct(
        public int $websiteId,
        public string $websiteCode,
        public int $storeId,
        public string $storeCode,
        public string $storeName,
        public string $storeMode,
        public string $localeCode,
        public string $canonicalScope,
        public ScopeIdentity $scopeIdentity,
        public bool $defaultStore,
        public bool $storeEnabled,
        public string $storeLifecycleStatus,
    ) {
        if (
            $websiteId < 0
            || $storeId < 1
            || $scopeIdentity->scopeKind !== ScopeIdentity::KIND_STORE
            || $scopeIdentity->websiteId !== $websiteId
            || !hash_equals((string)$scopeIdentity->websiteCode, $websiteCode)
            || !hash_equals((string)$scopeIdentity->storeCode, $storeCode)
            || !hash_equals((string)$scopeIdentity->storeMode, $storeMode)
            || trim($storeName) === ''
            || preg_match('//u', $storeName) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $storeName) === 1
            || strlen($storeName) > 255
            || preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/D', $localeCode) !== 1
            || strlen($localeCode) > 16
            || $canonicalScope === ''
            || strlen($canonicalScope) > 400
            || preg_match('/[\x00-\x1F\x7F]/', $canonicalScope) === 1
            || $storeLifecycleStatus !== 'active'
        ) {
            throw new \InvalidArgumentException((string)__('CMS 编辑上下文无效。'));
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'website_id' => $this->websiteId,
            'website_code' => $this->websiteCode,
            'store_id' => $this->storeId,
            'store_code' => $this->storeCode,
            'store_name' => $this->storeName,
            'store_mode' => $this->storeMode,
            'locale_code' => $this->localeCode,
            'scope' => $this->canonicalScope,
            'scope_identity' => $this->scopeIdentity->toArray(),
            'is_default_store' => $this->defaultStore,
            'store_enabled' => $this->storeEnabled,
            'store_lifecycle_status' => $this->storeLifecycleStatus,
        ];
    }
}
