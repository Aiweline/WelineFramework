<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;

/**
 * File + HotCache sidecar for layout entity node configs (keyed by node_uid).
 *
 * Disk is source of truth. Keys never include RequestContext::getId().
 */
final class ThemeLayoutEntityConfigStore
{
    private const CACHE_POOL = 'theme_layout';

    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ?StorefrontScopeHotCache $hotCache = null,
    ) {
    }

    public function readBoundConfig(EntityRenderBinding $binding): array
    {
        $key = 'theme.layout_entity.bound_config.' . $binding->cacheKey();
        $cached = \Weline\Framework\Runtime\RequestContext::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $config = $this->readWithHotCache('bound|' . $binding->cacheKey(), $binding->configPath);
        \Weline\Framework\Runtime\RequestContext::set($key, $config);
        return $config;
    }

    public function readBoundAssets(EntityRenderBinding $binding): array
    {
        return $this->readJsonFile($binding->assetsPath);
    }

    private function pageBindingForRead(int $themeId, string $scope, string $identityKey, string $entityKey): ?EntityRenderBinding
    {
        $key = 'theme.layout_entity.page_binding.' . hash('sha256', json_encode([$themeId, $scope, $identityKey, $entityKey], JSON_THROW_ON_ERROR));
        $binding = \Weline\Framework\Runtime\RequestContext::get($key);
        if (!$binding instanceof EntityRenderBinding) {
            $binding = (new ThemeLayoutEntityBindingStore($this->paths))->readPageBinding($themeId, $scope, $identityKey, $entityKey);
            if ($binding !== null) {
                \Weline\Framework\Runtime\RequestContext::set($key, $binding);
            }
        }
        return $binding;
    }

    private function chromeBindingForRead(int $themeId, string $scope, int $versionId): ?EntityRenderBinding
    {
        $key = 'theme.layout_entity.chrome_binding.' . hash('sha256', json_encode([$themeId, $scope, $versionId], JSON_THROW_ON_ERROR));
        $binding = \Weline\Framework\Runtime\RequestContext::get($key);
        if (!$binding instanceof EntityRenderBinding) {
            $binding = (new ThemeLayoutEntityBindingStore($this->paths))->readChromeBinding($themeId, $scope, $versionId);
            if ($binding !== null) {
                \Weline\Framework\Runtime\RequestContext::set($key, $binding);
            }
        }
        return $binding;
    }

    public static function cachePolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.layout_entity.config',
            pool: self::CACHE_POOL,
            scope: 'channel',
            vary: [],
            dependencies: ['theme'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
        );
    }

    /**
     * @param array<string, mixed> $configByUid
     */
    public function writePageConfig(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
        array $configByUid,
    ): string {
        $path = $this->paths->pageConfigJson($themeId, $scope, $identityKey, $structureOrRelease);
        $this->writeJsonFile($path, $configByUid);
        $this->warmCache($this->pageLogicalKey($themeId, $scope, $identityKey, $structureOrRelease), $configByUid);

        return $path;
    }

    /**
     * @param array<string, mixed> $configByUid
     */
    public function writeChromeConfig(
        int $themeId,
        string $scope,
        int $themeVersionId,
        array $configByUid,
    ): string {
        $path = $this->paths->chromeConfigJson($themeId, $scope, $themeVersionId);
        $this->writeJsonFile($path, $configByUid);
        $this->warmCache($this->chromeLogicalKey($themeId, $scope, $themeVersionId), $configByUid);

        return $path;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function writePageAssets(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
        array $manifest,
    ): string {
        $path = $this->paths->pageAssetsJson($themeId, $scope, $identityKey, $structureOrRelease);
        $this->writeJsonFile($path, $manifest);
        $this->warmCache(
            'assets|page|' . $themeId . '|' . $this->paths->scopeKey($scope) . '|' . $identityKey . '|' . $structureOrRelease,
            $manifest,
        );

        return $path;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function writeChromeAssets(
        int $themeId,
        string $scope,
        int $themeVersionId,
        array $manifest,
    ): string {
        $path = $this->paths->chromeAssetsJson($themeId, $scope, $themeVersionId);
        $this->writeJsonFile($path, $manifest);
        $this->warmCache(
            'assets|chrome|' . $themeId . '|' . $this->paths->scopeKey($scope) . '|tv' . $themeVersionId,
            $manifest,
        );

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function readPageAssets(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): array {
        $binding = $this->pageBindingForRead($themeId, $scope, $identityKey, $structureOrRelease);
        if ($binding !== null) {
            return $this->readJsonFile($binding->assetsPath);
        }
        // Asset sidecars are tiny; always disk-read so bake/CLI writes are visible
        // without depending on RequestContext-scoped HotCache key identity.
        return $this->readJsonFile(
            $this->paths->pageAssetsJson($themeId, $scope, $identityKey, $structureOrRelease)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function readChromeAssets(int $themeId, string $scope, int $themeVersionId): array
    {
        $binding = $this->chromeBindingForRead($themeId, $scope, $themeVersionId);
        if ($binding !== null) {
            return $this->readJsonFile($binding->assetsPath);
        }
        return $this->readJsonFile(
            $this->paths->chromeAssetsJson($themeId, $scope, $themeVersionId)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function readPageConfig(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): array {
        $binding = $this->pageBindingForRead($themeId, $scope, $identityKey, $structureOrRelease);
        if ($binding !== null) {
            return $this->readBoundConfig($binding);
        }
        $path = $this->paths->pageConfigJson($themeId, $scope, $identityKey, $structureOrRelease);
        $logicalKey = $this->pageLogicalKey($themeId, $scope, $identityKey, $structureOrRelease);

        return $this->readWithHotCache($logicalKey, $path);
    }

    /**
     * @return array<string, mixed>
     */
    public function readChromeConfig(int $themeId, string $scope, int $themeVersionId): array
    {
        $binding = $this->chromeBindingForRead($themeId, $scope, $themeVersionId);
        if ($binding !== null) {
            return $this->readBoundConfig($binding);
        }
        $path = $this->paths->chromeConfigJson($themeId, $scope, $themeVersionId);
        $logicalKey = $this->chromeLogicalKey($themeId, $scope, $themeVersionId);

        return $this->readWithHotCache($logicalKey, $path);
    }

    /**
     * Resolve one node config entry from page or chrome sidecar.
     *
     * @return array<string, mixed>
     */
    public function readNodeConfig(
        string $configSource,
        string $nodeUid,
        int $themeId,
        string $scopeKey,
        string $versionKey,
    ): array {
        $nodeUid = \strtolower(\trim($nodeUid));
        if ($nodeUid === '') {
            return [];
        }

        $map = $configSource === 'chrome'
            ? $this->readChromeConfigByKeys($themeId, $scopeKey, $versionKey)
            : $this->readPageConfigByKeys($themeId, $scopeKey, $versionKey);

        $entry = $map[$nodeUid] ?? null;
        if (!\is_array($entry)) {
            return [];
        }
        if ($configSource === 'page') {
            $entry = $this->hydratePageNodeFromStructure($entry, $nodeUid, $themeId, $scopeKey, $versionKey);
        }

        return $entry;
    }

    /**
     * Older bakes stored only widget params in page-config.json (no widget_module/code).
     * Structure.json still has identity — merge so storefront render cannot evaporate.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function hydratePageNodeFromStructure(
        array $entry,
        string $nodeUid,
        int $themeId,
        string $scopeKey,
        string $versionKey,
    ): array {
        $nodeUid = \strtolower(\trim($nodeUid));
        if ($nodeUid === '' || !$this->needsStructureHydration($entry)) {
            return $entry;
        }
        $meta = $this->structureNodeMeta($themeId, $scopeKey, $versionKey, $nodeUid);
        if ($meta === []) {
            return $entry;
        }
        foreach (['widget_module', 'widget_code', 'widget_type', 'slot_id', 'area', 'layout_source', 'source', 'source_position'] as $key) {
            $value = \trim((string)($meta[$key] ?? ''));
            if ($value !== '' && \trim((string)($entry[$key] ?? '')) === '') {
                $entry[$key] = $value;
            }
        }
        if (!\array_key_exists('is_active', $entry) && \array_key_exists('is_active', $meta)) {
            $entry['is_active'] = $meta['is_active'];
        }
        if (\trim((string)($entry['node_uid'] ?? '')) === '') {
            $entry['node_uid'] = $nodeUid;
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function needsStructureHydration(array $entry): bool
    {
        return \trim((string)($entry['widget_module'] ?? '')) === ''
            || \trim((string)($entry['widget_code'] ?? '')) === '';
    }

    /**
     * @return array<string, mixed>
     */
    private function structureNodeMeta(
        int $themeId,
        string $scopeKey,
        string $versionKey,
        string $nodeUid,
    ): array {
        $parts = \explode('/', \str_replace('\\', '/', $versionKey), 2);
        $identityKey = $parts[0] ?? '';
        $structureOrRelease = $parts[1] ?? '';
        if ($identityKey === '' || $structureOrRelease === '') {
            return [];
        }
        $path = $this->paths->pageStructureJson($themeId, $scopeKey, $identityKey, $structureOrRelease);
        $indexKey = 'theme.layout_entity.structure_index.' . hash('sha256', $path);
        $index = \Weline\Framework\Runtime\RequestContext::get($indexKey);
        if (!is_array($index)) {
            $index = [];
            foreach ($this->readStructureSlotsCached($path) as $widgets) {
                foreach (is_array($widgets) ? $widgets : [] as $widget) {
                    if (is_array($widget)) {
                        $uid = strtolower(trim((string)($widget['node_uid'] ?? '')));
                        if ($uid !== '') {
                            $index[$uid] = $widget;
                        }
                    }
                }
            }
            \Weline\Framework\Runtime\RequestContext::set($indexKey, $index);
        }
        return $index[$nodeUid] ?? [];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function readStructureSlotsCached(string $path): array
    {
        if ($path === '' || !\is_file($path)) {
            return [];
        }
        $logicalKey = 'structure|' . $path;
        $hotCache = $this->resolveHotCache();
        if ($hotCache instanceof StorefrontScopeHotCache) {
            $cached = $hotCache->rememberPolicy(
                self::cachePolicy(),
                $logicalKey,
                fn(): array => $this->decodeStructureSlots($path),
            );

            return \is_array($cached) ? $cached : [];
        }

        return $this->decodeStructureSlots($path);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function decodeStructureSlots(string $path): array
    {
        $decoded = \json_decode((string)\file_get_contents($path), true);
        $slots = \is_array($decoded['slots'] ?? null) ? $decoded['slots'] : [];
        $out = [];
        foreach ($slots as $slotId => $widgets) {
            if (\is_array($widgets)) {
                $out[(string)$slotId] = $widgets;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function readChromeConfigByKeys(int $themeId, string $scopeKey, string $versionKey): array
    {
        // versionKey for chrome is theme_version_id (numeric string) or tv{id}.
        $themeVersionId = (int)\preg_replace('/\D+/', '', $versionKey);
        $path = $this->paths->root()
            . $themeId . \DIRECTORY_SEPARATOR
            . $scopeKey . \DIRECTORY_SEPARATOR
            . 'tv' . $themeVersionId . \DIRECTORY_SEPARATOR
            . 'chrome' . \DIRECTORY_SEPARATOR
            . 'chrome-config.json';
        $logicalKey = 'chrome|' . $themeId . '|' . $scopeKey . '|tv' . $themeVersionId;

        return $this->readWithHotCache($logicalKey, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPageConfigByKeys(int $themeId, string $scopeKey, string $versionKey): array
    {
        // versionKey encodes identity_key/structure_or_release
        $parts = \explode('/', \str_replace('\\', '/', $versionKey), 2);
        $identityKey = $parts[0] ?? '';
        $structureOrRelease = $parts[1] ?? '';
        $path = $this->paths->root()
            . $themeId . \DIRECTORY_SEPARATOR
            . $scopeKey . \DIRECTORY_SEPARATOR
            . 'pages' . \DIRECTORY_SEPARATOR
            . $identityKey . \DIRECTORY_SEPARATOR
            . $structureOrRelease . \DIRECTORY_SEPARATOR
            . 'page-config.json';
        $logicalKey = 'page|' . $themeId . '|' . $scopeKey . '|' . $identityKey . '|' . $structureOrRelease;

        return $this->readWithHotCache($logicalKey, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function readWithHotCache(string $logicalKey, string $path): array
    {
        $hotCache = $this->resolveHotCache();
        if ($hotCache instanceof StorefrontScopeHotCache) {
            $cached = $hotCache->rememberPolicy(
                self::cachePolicy(),
                $logicalKey,
                fn(): array => $this->readJsonFile($path),
            );

            return \is_array($cached) ? $cached : [];
        }

        return $this->readJsonFile($path);
    }

    /**
     * @param array<string, mixed> $configByUid
     */
    private function warmCache(string $logicalKey, array $configByUid): void
    {
        $hotCache = $this->resolveHotCache();
        if (!$hotCache instanceof StorefrontScopeHotCache) {
            return;
        }
        // rememberPolicy keeps an existing HIT — forget first so disk writes are visible.
        $hotCache->forgetPolicy(self::cachePolicy(), $logicalKey);
        $hotCache->rememberPolicy(
            self::cachePolicy(),
            $logicalKey,
            static fn(): array => $configByUid,
        );
    }

    private function pageLogicalKey(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): string {
        return 'page|' . $themeId . '|' . $this->paths->scopeKey($scope)
            . '|' . $identityKey . '|' . $structureOrRelease;
    }

    private function chromeLogicalKey(int $themeId, string $scope, int $themeVersionId): string
    {
        return 'chrome|' . $themeId . '|' . $this->paths->scopeKey($scope) . '|tv' . $themeVersionId;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJsonFile(string $path, array $data): void
    {
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('Failed to create layout entity config dir: ' . $dir);
        }
        $json = \json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode layout entity config JSON.');
        }
        $temporary = tempnam($dir, '.entity-config-');
        if ($temporary === false) {
            throw new \RuntimeException('Failed to stage layout entity config: ' . $path);
        }
        try {
            if (file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $path)) {
                throw new \RuntimeException('Failed to write layout entity config: ' . $path);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (!\is_file($path)) {
            return [];
        }
        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
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
