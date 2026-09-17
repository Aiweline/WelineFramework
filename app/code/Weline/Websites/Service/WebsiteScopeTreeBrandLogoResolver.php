<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;

/**
 * Soft-resolve Theme brand logo for Website→Store→Channel tree thumbs.
 * Theme missing → empty string; never hard-require Weline_Theme.
 */
final class WebsiteScopeTreeBrandLogoResolver
{
    /** @var array<string, string> */
    private array $memo = [];

    public function forWebsite(int $websiteId, string $websiteCode): string
    {
        $websiteCode = trim($websiteCode);
        if ($websiteCode === '') {
            return '';
        }
        try {
            return $this->resolve(ScopeIdentity::website($websiteId, $websiteCode));
        } catch (\Throwable) {
            return '';
        }
    }

    public function forStore(
        int $websiteId,
        string $websiteCode,
        string $storeCode,
        string $storeMode = ScopeIdentity::MODE_NORMAL,
    ): string {
        $websiteCode = trim($websiteCode);
        $storeCode = trim($storeCode);
        if ($websiteCode === '' || $storeCode === '') {
            return '';
        }
        $mode = $storeMode !== '' ? $storeMode : ScopeIdentity::MODE_NORMAL;
        try {
            return $this->resolve(ScopeIdentity::store($websiteId, $websiteCode, $storeCode, $mode));
        } catch (\Throwable) {
            return '';
        }
    }

    public function forChannel(
        int $websiteId,
        string $websiteCode,
        string $storeCode,
        string $channelCode,
        string $storeMode = ScopeIdentity::MODE_NORMAL,
    ): string {
        $websiteCode = trim($websiteCode);
        $storeCode = trim($storeCode);
        $channelCode = trim($channelCode);
        if ($websiteCode === '' || $storeCode === '' || $channelCode === '') {
            return '';
        }
        $mode = $storeMode !== '' ? $storeMode : ScopeIdentity::MODE_NORMAL;
        try {
            return $this->resolve(ScopeIdentity::channel(
                $websiteId,
                $websiteCode,
                $storeCode,
                $channelCode,
                $mode,
            ));
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolve(ScopeIdentity $identity): string
    {
        $memoKey = $identity->canonicalKey();
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        $resolverClass = 'Weline\\Theme\\Service\\ThemeBrandResolver';
        $siteBrandClass = 'Weline\\Theme\\Helper\\SiteBrand';
        if (!class_exists($resolverClass) || !class_exists($siteBrandClass)) {
            return $this->memo[$memoKey] = '';
        }

        try {
            /** @var ScopeHierarchyInterface $scopes */
            $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);
            /** @var ScopeIdentityCatalogInterface $catalog */
            $catalog = ObjectManager::getInstance(ScopeIdentityCatalogInterface::class);
            $authoritative = $catalog->authoritativeIdentity($identity);
            $context = $scopes->contextFromIdentity($authoritative);
            $resolver = ObjectManager::getInstance($resolverClass);
            $brand = $resolver->resolvePublishedBrand('frontend', null, $context);
            $path = trim((string)($brand['logo_light'] ?? ''));
            if ($path === '') {
                $path = trim((string)($brand['favicon'] ?? ''));
            }
            if ($path === '') {
                return $this->memo[$memoKey] = '';
            }
            $siteBrand = ObjectManager::getInstance($siteBrandClass);
            $url = trim((string)$siteBrand->resolvePathToUrl($path, 40, 40));

            return $this->memo[$memoKey] = $url;
        } catch (\Throwable) {
            return $this->memo[$memoKey] = '';
        }
    }
}
