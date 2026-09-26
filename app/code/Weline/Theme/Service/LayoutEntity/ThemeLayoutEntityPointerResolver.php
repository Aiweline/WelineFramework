<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\ThemeScopeVersionService;

/**
 * Cheap chrome/page entity pointers via CachePolicy HotCache only.
 * Never loads full workspace payloads; never keys on RequestContext::getId().
 * Resolves typed ThemeVersionIdentity — no current.json / r-d-s segments.
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
        return $this->resolveChromePointer($themeId, $scope, true);
    }

    /**
     * @return array{version_id:int,path:string,scope:string,identity:?ThemeVersionIdentity}|null
     */
    public function resolveCurrentChrome(int $themeId, string $scope): ?array
    {
        return $this->resolveChromePointer($themeId, $scope, false);
    }

    /**
     * @return array{path:string,structure_key:string,identity:?ThemeVersionIdentity}|null
     */
    public function resolvePageEntity(
        ThemeVersionIdentity $identity,
        string $layoutIdentityHash,
        string $structureKey = '',
    ): ?array {
        $logicalKey = 'page|' . $identity->ownerKey() . '|'
            . $identity->themeVersionId . '|' . $identity->mode . '|' . $identity->contentRevision . '|'
            . \strtolower(\trim($layoutIdentityHash)) . '|' . \strtolower(\trim($structureKey));

        return $this->remember($logicalKey, function () use ($identity, $layoutIdentityHash, $structureKey): ?array {
            $layoutKey = $this->paths->identityKey($layoutIdentityHash);
            $binding = ObjectManager::getInstance(ThemeLayoutEntityBindingStore::class)
                ->readPageBinding($identity, $layoutKey);
            $resolvedStructure = $binding?->structureKey
                ?? ($structureKey !== '' ? $this->normalizeStructureKey($structureKey) : '');
            $path = $binding?->templatePath
                ?? ($resolvedStructure !== ''
                    ? $this->paths->pagePhtml($identity, $layoutKey, $resolvedStructure)
                    : '');
            if ($path === '' || !\is_file($path)) {
                return null;
            }

            return [
                'path' => $path,
                'structure_key' => $resolvedStructure !== '' ? $resolvedStructure : ($binding?->structureKey ?? ''),
                'identity' => $identity,
            ];
        });
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
        $logicalKey = $this->chromeKey($themeId, $scope, $published);
        $value = [
            'version_id' => $versionId,
            'path' => $path,
            'scope' => $scope,
        ];
        $this->warm($logicalKey, $value);
    }

    public function rememberPagePointer(
        ThemeVersionIdentity $identity,
        string $layoutIdentityHash,
        string $structureKey,
        string $path,
    ): void {
        $logicalKey = 'page|' . $identity->ownerKey() . '|'
            . $identity->themeVersionId . '|' . $identity->mode . '|' . $identity->contentRevision . '|'
            . \strtolower(\trim($layoutIdentityHash)) . '|' . \strtolower(\trim($structureKey));
        $this->warm($logicalKey, [
            'path' => $path,
            'structure_key' => $structureKey,
            'identity' => $identity,
        ]);
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

    /**
     * @return array{version_id:int,path:string,scope:string,identity:?ThemeVersionIdentity}|null
     */
    private function resolveChromePointer(int $themeId, string $scope, bool $published): ?array
    {
        $logicalKey = $this->chromeKey($themeId, $scope, $published);

        return $this->remember($logicalKey, function () use ($themeId, $scope, $published): ?array {
            $version = $published
                ? $this->scopeVersions->getPublished($themeId, $scope)
                : $this->scopeVersions->getCurrent($themeId, $scope);
            if ($version === null || $version->getVersionId() < 1) {
                return null;
            }
            $identity = $version->toVersionIdentity();
            if ($published && $identity->mode !== ThemeVersionIdentity::MODE_FORMAL) {
                $identity = $identity->withVersion(
                    $identity->themeVersionId,
                    ThemeVersionIdentity::MODE_FORMAL,
                    \max(1, $identity->contentRevision),
                );
            }
            $binding = ObjectManager::getInstance(ThemeLayoutEntityBindingStore::class)
                ->readChromeBinding($identity);
            $path = $binding?->templatePath ?? '';
            if ($path === '' || !\is_file($path)) {
                return null;
            }

            return [
                'version_id' => $version->getVersionId(),
                'path' => $path,
                'scope' => $version->getScope(),
                'identity' => $identity,
            ];
        });
    }

    private function chromeKey(int $themeId, string $scope, bool $published): string
    {
        return 'chrome|' . ($published ? 'pub' : 'cur') . '|' . $themeId . '|' . \trim($scope);
    }

    private function normalizeStructureKey(string $structureKey): string
    {
        $structureKey = \strtolower(\trim($structureKey));
        if (\preg_match('/^s([a-f0-9]{64})$/D', $structureKey, $m) === 1) {
            return $m[1];
        }
        if (\preg_match('/^[a-f0-9]{64}$/D', $structureKey) === 1) {
            return $structureKey;
        }

        return $structureKey;
    }

    /**
     * @template T
     * @param callable():(?T) $builder
     * @return T|null
     */
    private function remember(string $logicalKey, callable $builder): mixed
    {
        $hotCache = $this->resolveHotCache();
        if ($hotCache instanceof StorefrontScopeHotCache) {
            $value = $hotCache->rememberPolicy(
                self::pointerCachePolicy(),
                $logicalKey,
                static function () use ($builder): mixed {
                    return $builder();
                },
            );

            return \is_array($value) ? $value : null;
        }

        $value = $builder();

        return \is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function warm(string $logicalKey, array $value): void
    {
        $hotCache = $this->resolveHotCache();
        if (!$hotCache instanceof StorefrontScopeHotCache) {
            return;
        }
        try {
            $hotCache->rememberPolicy(
                self::pointerCachePolicy(),
                $logicalKey,
                static fn(): array => $value,
            );
        } catch (\Throwable) {
        }
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
