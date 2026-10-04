<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\ThemeScopeVersionService;

/**
 * @deprecated Structural pointer readers/writers are retired.
 * Kept for existing setup and cache-invalidation callers; remove after those
 * callers migrate. Executable sources are selected by SolidifiedControllerTemplateResolver.
 */
final class ThemeLayoutEntityPointerResolver
{
    public function __construct(
        private readonly ThemeScopeVersionService $scopeVersions,
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ?StorefrontScopeHotCache $hotCache = null,
    ) {
    }

    public static function pointerCachePolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.layout_entity.pointer',
            pool: 'theme_layout',
            scope: 'channel',
            vary: [],
            dependencies: ['theme'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
        );
    }

    /**
     * @return array{version_id:int,path:string,scope:string,identity:?ThemeVersionIdentity}|null
     */
    public function resolvePublishedChrome(int $themeId, string $scope): ?array
    {
        // Legacy structural pointers cannot select a pure PHTML source.
        return null;
    }

    /**
     * @return array{version_id:int,path:string,scope:string,identity:?ThemeVersionIdentity}|null
     */
    public function resolveCurrentChrome(int $themeId, string $scope): ?array
    {
        // Legacy structural pointers cannot select a pure PHTML source.
        return null;
    }

    /**
     * @return array{path:string,structure_key:string,identity:?ThemeVersionIdentity}|null
     */
    public function resolvePageEntity(
        ThemeVersionIdentity $identity,
        string $layoutIdentityHash,
        string $structureKey = '',
    ): ?array {
        // Legacy structural pointers cannot select a pure PHTML source.
        return null;
    }

    /**
     * @param array{version_id?:int,path:string} $pointer
     */
    public function rememberChromePointer(
        int $themeId,
        string $scope,
        int $versionId,
        string $path,
        bool $published,
    ): void {
        // Compatibility only: setup callers must not republish structural pointers.
    }

    public function rememberPagePointer(
        ThemeVersionIdentity $identity,
        string $layoutIdentityHash,
        string $structureKey,
        string $path,
    ): void {
        // Compatibility only: setup callers must not republish structural pointers.
    }

    public function invalidateChrome(int $themeId, string $scope): void
    {
        $scope = \trim($scope);
        $hotCache = $this->resolveHotCache();
        if ($hotCache instanceof StorefrontScopeHotCache) {
            try {
                $hotCache->forgetPolicy(self::pointerCachePolicy(), $this->chromeKey($themeId, $scope, true));
                $hotCache->forgetPolicy(self::pointerCachePolicy(), $this->chromeKey($themeId, $scope, false));
            } catch (\Throwable) {
            }
        }
    }

    public function invalidatePage(ThemeVersionIdentity $identity, string $layoutIdentityHash, string $structureKey = ''): void
    {
        $logicalKey = 'page|' . $identity->ownerKey() . '|'
            . $identity->themeVersionId . '|' . $identity->mode . '|' . $identity->contentRevision . '|'
            . \strtolower(\trim($layoutIdentityHash)) . '|' . \strtolower(\trim($structureKey));
        $hotCache = $this->resolveHotCache();
        if ($hotCache instanceof StorefrontScopeHotCache) {
            try {
                $hotCache->forgetPolicy(self::pointerCachePolicy(), $logicalKey);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @deprecated wave6-6s Policy-only; kept for callers that expect a reset hook.
     */
    public static function clearProcessCache(): void
    {
        if (\class_exists(StorefrontScopeHotCache::class)) {
            StorefrontScopeHotCache::resetProcessCache();
        }
    }

    private function chromeKey(int $themeId, string $scope, bool $published): string
    {
        return 'chrome|' . ($published ? 'pub' : 'cur') . '|' . $themeId . '|' . \trim($scope);
    }



    private function resolveHotCache(): ?StorefrontScopeHotCache
    {
        if ($this->hotCache instanceof StorefrontScopeHotCache) {
            return $this->hotCache;
        }
        try {
            $resolved = ObjectManager::getInstance(StorefrontScopeHotCache::class);

            return $resolved instanceof StorefrontScopeHotCache ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
