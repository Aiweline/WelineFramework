<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ThemeScopeVersionService;

/**
 * Cheap chrome/page entity pointers via CachePolicy HotCache only.
 * Never loads full workspace payloads; never keys on RequestContext::getId().
 * wave6-6s: removed parallel process static — rememberPolicy is the sole bag.
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
     * @return array{version_id:int,path:string}|null
     */
    public function resolvePublishedChrome(int $themeId, string $scope): ?array
    {
        return $this->resolveChromePointer($themeId, $scope, true);
    }

    /**
     * @return array{version_id:int,path:string}|null
     */
    public function resolveCurrentChrome(int $themeId, string $scope): ?array
    {
        return $this->resolveChromePointer($themeId, $scope, false);
    }

    /**
     * @return array{path:string,structure_key:string}|null
     */
    public function resolvePageEntity(
        int $themeId,
        string $scope,
        string $identityHash,
        string $structureKey,
        bool $published = true,
        ?int $releaseId = null,
    ): ?array {
        $logicalKey = 'page|' . $themeId . '|' . \trim($scope) . '|'
            . \strtolower(\trim($identityHash)) . '|' . \strtolower(\trim($structureKey))
            . '|' . ($published ? '1' : '0') . '|' . (int)($releaseId ?? 0);

        return $this->remember($logicalKey, function () use (
            $themeId,
            $scope,
            $identityHash,
            $structureKey,
            $published,
            $releaseId,
        ): ?array {
            $identityKey = $this->paths->identityKey($identityHash);
            $structureOrRelease = $this->paths->pageStructureOrRelease($structureKey, $published, $releaseId);
            $path = $this->paths->pagePhtml($themeId, $scope, $identityKey, $structureOrRelease);
            $binding = ObjectManager::getInstance(ThemeLayoutEntityBindingStore::class)
                ->readPageBinding($themeId, $scope, $identityKey, $structureOrRelease);
            $path = $binding?->templatePath ?? $path;
            if (!\is_file($path)) {
                return null;
            }

            return [
                'path' => $path,
                'structure_key' => $structureKey,
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
        int $themeId,
        string $scope,
        string $identityHash,
        string $structureKey,
        string $path,
        bool $published = true,
        ?int $releaseId = null,
    ): void {
        $logicalKey = 'page|' . $themeId . '|' . \trim($scope) . '|'
            . \strtolower(\trim($identityHash)) . '|' . \strtolower(\trim($structureKey))
            . '|' . ($published ? '1' : '0') . '|' . (int)($releaseId ?? 0);
        $this->warm($logicalKey, [
            'path' => $path,
            'structure_key' => $structureKey,
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
                // Best-effort; callers may also bump theme generation.
            }
        }
    }

    public function invalidatePage(
        int $themeId,
        string $scope,
        string $identityHash,
        string $structureKey,
        bool $published = true,
        ?int $releaseId = null,
    ): void {
        $logicalKey = 'page|' . $themeId . '|' . \trim($scope) . '|'
            . \strtolower(\trim($identityHash)) . '|' . \strtolower(\trim($structureKey))
            . '|' . ($published ? '1' : '0') . '|' . (int)($releaseId ?? 0);
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
     * @return array{version_id:int,path:string}|null
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
            $path = $this->paths->chromePhtml($themeId, $version->getScope(), $version->getVersionId());
            $binding = ObjectManager::getInstance(ThemeLayoutEntityBindingStore::class)
                ->readChromeBinding($themeId, $version->getScope(), $version->getVersionId());
            $path = $binding?->templatePath ?? $path;
            if (!\is_file($path)) {
                return null;
            }

            return [
                'version_id' => $version->getVersionId(),
                'path' => $path,
                'scope' => $version->getScope(),
            ];
        });
    }

    private function chromeKey(int $themeId, string $scope, bool $published): string
    {
        return 'chrome|' . ($published ? 'pub' : 'cur') . '|' . $themeId . '|' . \trim($scope);
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
