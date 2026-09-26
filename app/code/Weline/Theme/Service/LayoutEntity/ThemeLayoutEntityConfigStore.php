<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Version\ThemeVersionIdentity;

/**
 * File + HotCache sidecar for layout entity node configs (keyed by node_uid).
 *
 * Prefer BindingStore + readBoundConfig/readBoundAssets. Missing binding = [].
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

    private function pageBindingForRead(ThemeVersionIdentity $identity, string $layoutIdentityHash): ?EntityRenderBinding
    {
        $layoutIdentityHash = \strtolower(\trim($layoutIdentityHash));
        $key = 'theme.layout_entity.page_binding.v3.' . hash('sha256', json_encode([
            $identity->toArray(),
            $layoutIdentityHash,
        ], JSON_THROW_ON_ERROR));
        $binding = \Weline\Framework\Runtime\RequestContext::get($key);
        if (!$binding instanceof EntityRenderBinding) {
            $binding = (new ThemeLayoutEntityBindingStore($this->paths))
                ->readPageBinding($identity, $layoutIdentityHash);
            if ($binding !== null) {
                \Weline\Framework\Runtime\RequestContext::set($key, $binding);
            }
        }

        return $binding;
    }

    private function chromeBindingForRead(ThemeVersionIdentity $identity): ?EntityRenderBinding
    {
        $key = 'theme.layout_entity.chrome_binding.v3.' . hash('sha256', json_encode(
            $identity->toArray(),
            JSON_THROW_ON_ERROR,
        ));
        $binding = \Weline\Framework\Runtime\RequestContext::get($key);
        if (!$binding instanceof EntityRenderBinding) {
            $binding = (new ThemeLayoutEntityBindingStore($this->paths))
                ->readChromeBinding($identity);
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
     * @return array<string, mixed>
     */
    public function readPageAssets(ThemeVersionIdentity $identity, string $layoutIdentityHash): array
    {
        $binding = $this->pageBindingForRead($identity, $layoutIdentityHash);
        if ($binding === null) {
            return [];
        }

        return $this->readJsonFile($binding->assetsPath);
    }

    /**
     * @return array<string, mixed>
     */
    public function readChromeAssets(ThemeVersionIdentity $identity): array
    {
        $binding = $this->chromeBindingForRead($identity);
        if ($binding === null) {
            return [];
        }

        return $this->readJsonFile($binding->assetsPath);
    }

    /**
     * @return array<string, mixed>
     */
    public function readPageConfig(ThemeVersionIdentity $identity, string $layoutIdentityHash): array
    {
        $binding = $this->pageBindingForRead($identity, $layoutIdentityHash);
        if ($binding === null) {
            return [];
        }

        return $this->readBoundConfig($binding);
    }

    /**
     * @return array<string, mixed>
     */
    public function readChromeConfig(ThemeVersionIdentity $identity): array
    {
        $binding = $this->chromeBindingForRead($identity);
        if ($binding === null) {
            return [];
        }

        return $this->readBoundConfig($binding);
    }

    /**
     * Resolve one node config entry. Without a binding, flat legacy keys are a cache miss.
     *
     * @return array<string, mixed>
     */
    public function readNodeConfig(
        string $configSource,
        string $nodeUid,
        int $themeId,
        string $scopeKey,
        string $versionKey,
        ?EntityRenderBinding $binding = null,
    ): array {
        $nodeUid = \strtolower(\trim($nodeUid));
        if ($nodeUid === '') {
            return [];
        }
        if ($binding !== null) {
            $entry = $this->readBoundConfig($binding)[$nodeUid] ?? null;
            if (!\is_array($entry)) {
                return [];
            }
            if ($configSource === 'page' && $this->needsStructureHydration($entry)) {
                $entry = $this->hydratePageNodeFromStructure($entry, $nodeUid, $binding->structurePath);
            }

            return $entry;
        }
        unset($themeId, $scopeKey, $versionKey, $configSource);

        // No binding → miss. Do not invent r/d/s or flat legacy sidecar paths.
        return [];
    }

    /**
     * Older bakes stored only widget params in config.json (no widget_module/code).
     * Structure.json still has identity — merge so storefront render cannot evaporate.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function hydratePageNodeFromStructure(
        array $entry,
        string $nodeUid,
        string $structurePath = '',
    ): array {
        $nodeUid = \strtolower(\trim($nodeUid));
        if ($nodeUid === '' || !$this->needsStructureHydration($entry)) {
            return $entry;
        }
        $meta = $this->structureNodeMetaFromPath($structurePath, $nodeUid);
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
    private function structureNodeMetaFromPath(string $structurePath, string $nodeUid): array
    {
        if ($structurePath === '' || !\is_file($structurePath)) {
            return [];
        }
        $indexKey = 'theme.layout_entity.structure_index.' . hash('sha256', $structurePath);
        $index = \Weline\Framework\Runtime\RequestContext::get($indexKey);
        if (!is_array($index)) {
            $index = [];
            foreach ($this->readStructureSlotsCached($structurePath) as $widgets) {
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
