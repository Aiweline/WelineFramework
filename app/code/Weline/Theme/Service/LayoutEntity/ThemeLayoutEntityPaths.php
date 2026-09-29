<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Theme\Api\Version\ThemeVersionIdentity;

/**
 * Pure derived PHTML source tree, never compiled or rendered HTML.
 * {theme}/{area}/scope/{reversible canonical segments}/mode/{store mode}/draft|vN/
 * pages/[targets/{type}/{id}/]layouts/{layoutType}/{option}.phtml
 * pages/[targets/{type}/{id}/]layouts/{layoutType}/{option}/sources/{Module}/{source}.phtml
 * theme/partials/{type}/{option}.phtml
 * Legacy sidecar path helpers are cleanup compatibility only; writers must not use them.
 */
final class ThemeLayoutEntityPaths
{
    public const ROOT_SEGMENT = 'theme-layout-entities';
    public const SCHEMA_BINDING = 'theme-layout-entity.v3';

    public function __construct(
        private readonly ?string $rootOverride = null,
    ) {
    }

    public function root(): string
    {
        if ($this->rootOverride !== null && $this->rootOverride !== '') {
            return \rtrim($this->rootOverride, '/\\') . \DIRECTORY_SEPARATOR;
        }

        $generated = \defined('\Weline\Framework\App\Env::GENERATED_DIR')
            ? (string)\Weline\Framework\App\Env::GENERATED_DIR
            : (\rtrim((string)BP, '/\\') . \DIRECTORY_SEPARATOR . 'generated');

        return \rtrim($generated, '/\\') . \DIRECTORY_SEPARATOR . self::ROOT_SEGMENT . \DIRECTORY_SEPARATOR;
    }

    /** Pre-R6 disk root (read/migrate/purge only — never the default write root). */
    public function legacyVarRoot(): string
    {
        return \rtrim((string)BP, '/\\') . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR
            . 'runtime' . \DIRECTORY_SEPARATOR . self::ROOT_SEGMENT . \DIRECTORY_SEPARATOR;
    }

    /**
     * One-shot: when generated root is empty and legacy var tree exists, move it.
     * Returns nodes moved estimate (1 if rename succeeded, else copy count, else 0).
     *
     * @deprecated Prefer purge + full re-solidify (C-RP-07); do not treat migrate as primary cutover.
     */
    public function migrateLegacyVarTreeToGenerated(): int
    {
        if ($this->rootOverride !== null && $this->rootOverride !== '') {
            return 0;
        }
        $dest = \rtrim($this->root(), '/\\');
        $src = \rtrim($this->legacyVarRoot(), '/\\');
        if (!\is_dir($src)) {
            return 0;
        }
        if (\is_dir($dest)) {
            $children = @\scandir($dest) ?: [];
            $nonDot = \array_values(\array_filter($children, static fn(string $n): bool => $n !== '.' && $n !== '..'));
            if ($nonDot !== []) {
                return 0;
            }
            @\rmdir($dest);
        }
        $parent = \dirname($dest);
        if (!\is_dir($parent) && !@\mkdir($parent, 0775, true) && !\is_dir($parent)) {
            throw new \RuntimeException('theme_layout_entity_migrate_generated_parent_failed');
        }
        if (@\rename($src, $dest)) {
            return 1;
        }
        $copied = $this->copyTreeRecursive($src, $dest);
        $this->purgeEntityTree($src);

        return $copied;
    }

    /**
     * Readable scope directory segment (e.g. default_default_default).
     * Forbidden: SHA-256 digest as the directory name.
     */
    public function scopeKey(string $canonicalScope, string $storeMode = 'normal'): string
    {
        return $this->readableScopeSegment($canonicalScope, $storeMode);
    }

    /**
     * @see scopeKey()
     */
    public function readableScopeSegment(string $canonicalScope, string $storeMode = 'normal'): string
    {
        $canonicalScope = \trim($canonicalScope);
        if ($canonicalScope === '') {
            throw new \InvalidArgumentException('theme_layout_scope_empty');
        }

        return \implode('/', \array_map(ThemeVersionIdentity::encodePathIdentity(...), \explode('.', $canonicalScope)));
    }

    /**
     * Normalize original layout route path (e.g. checkout/success, cart/index, homepage).
     * This is the source layout route — not a hash identity.
     */
    public function layoutRoutePath(string $layoutRoute): string
    {
        $layoutRoute = \trim(\str_replace('\\', '/', $layoutRoute));
        $layoutRoute = \trim($layoutRoute, '/');
        if ($layoutRoute === '') {
            throw new \InvalidArgumentException('theme_layout_route_empty');
        }
        if (\str_contains($layoutRoute, '..') || \str_starts_with($layoutRoute, '.')) {
            throw new \InvalidArgumentException('theme_layout_route_invalid');
        }
        // Full SHA as route = old hash-tree identity — forbidden as path segment.
        if (\preg_match('/^[a-f0-9]{64}$/Di', $layoutRoute) === 1) {
            throw new \InvalidArgumentException('theme_layout_route_hash_forbidden');
        }
        if (\preg_match('#^[a-zA-Z0-9][a-zA-Z0-9/_.-]*$#', $layoutRoute) !== 1) {
            throw new \InvalidArgumentException('theme_layout_route_invalid');
        }
        $parts = [];
        foreach (\explode('/', $layoutRoute) as $part) {
            $part = \trim($part);
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \InvalidArgumentException('theme_layout_route_invalid');
            }
            $parts[] = $part;
        }

        return \implode('/', $parts);
    }

    /**
     * @deprecated Hash identity is no longer a disk directory segment (C-RP-05).
     *             Prefer {@see layoutRoutePath()}. Kept only for callers that still
     *             validate a 64-hex digest for non-path uses.
     */
    public function identityKey(string $identityHash): string
    {
        $identityHash = \strtolower(\trim($identityHash));
        if ($identityHash === '' || !\preg_match('/^[a-f0-9]{64}$/D', $identityHash)) {
            throw new \InvalidArgumentException('theme_layout_identity_hash_invalid');
        }

        return $identityHash;
    }

    public function ownerDir(ThemeVersionIdentity $identity): string
    {
        return $this->root()
            . $identity->themeId . \DIRECTORY_SEPARATOR
            . $identity->area . \DIRECTORY_SEPARATOR
            . 'scope' . \DIRECTORY_SEPARATOR . $identity->scopeKey() . \DIRECTORY_SEPARATOR
            . 'mode' . \DIRECTORY_SEPARATOR . $identity->storeModeKey() . \DIRECTORY_SEPARATOR;
    }

    /**
     * formal → v{N}/；draft → draft/（与 v{N} 平级，不再 tv{V}/formal|draft）.
     */
    public function versionModeDir(ThemeVersionIdentity $identity): string
    {
        if ($identity->mode === ThemeVersionIdentity::MODE_DRAFT) {
            return $this->ownerDir($identity) . 'draft' . \DIRECTORY_SEPARATOR;
        }
        if ($identity->themeVersionId < 1) {
            throw new \InvalidArgumentException('theme_layout_version_id_required');
        }

        return $this->ownerDir($identity)
            . 'v' . $identity->themeVersionId . \DIRECTORY_SEPARATOR;
    }

    /** Chrome / theme chrome info root (not the storefront main product). */
    public function chromeRoot(ThemeVersionIdentity $identity): string
    {
        return $this->versionModeDir($identity) . 'theme' . \DIRECTORY_SEPARATOR;
    }

    /** @deprecated Alias of {@see chromeRoot()} — directory is now `theme/`. */
    public function themeRoot(ThemeVersionIdentity $identity): string
    {
        return $this->chromeRoot($identity);
    }

    /**
     * Unique storefront main product: wrapped original-layout template.
     * Path: …/pages/layouts/{原布局路由}.phtml
     */
    public function pageLayoutPhtml(
        ThemeVersionIdentity $identity,
        string $layoutType,
        string $layoutOption = 'default',
        string $targetType = 'global',
        int|string|null $targetId = null,
    ): string {
        $route = $this->layoutRoutePath($layoutType);
        $option = $this->layoutRoutePath($layoutOption);
        $targetType = \trim($targetType);
        if ($targetType === '' || \str_contains($targetType, '/')) {
            throw new \InvalidArgumentException('theme_layout_target_invalid');
        }
        $target = '';
        if ($targetType !== 'global' || ($targetId !== null && (string)$targetId !== '' && (string)$targetId !== '0')) {
            $target = 'targets' . \DIRECTORY_SEPARATOR
                . 't-' . ThemeVersionIdentity::encodePathIdentity($targetType) . \DIRECTORY_SEPARATOR
                . 'i-' . ThemeVersionIdentity::encodePathIdentity((string)($targetId ?? '0')) . \DIRECTORY_SEPARATOR;
        }

        return $this->versionModeDir($identity) . 'pages' . \DIRECTORY_SEPARATOR
            . $target . 'layouts' . \DIRECTORY_SEPARATOR
            . \str_replace('/', \DIRECTORY_SEPARATOR, $route . '/' . $option) . '.phtml';
    }

    public function partialPhtml(ThemeVersionIdentity $identity, string $type, string $option = 'default'): string
    {
        return $this->chromeRoot($identity) . 'partials' . \DIRECTORY_SEPARATOR
            . \str_replace('/', \DIRECTORY_SEPARATOR, $this->layoutRoutePath($type) . '/' . $this->layoutRoutePath($option)) . '.phtml';
    }

    public function pageSourceRoot(ThemeVersionIdentity $identity, string $layoutType, string $layoutOption = 'default', string $targetType = 'global', int|string|null $targetId = null): string
    {
        return substr($this->pageLayoutPhtml($identity, $layoutType, $layoutOption, $targetType, $targetId), 0, -6)
            . DIRECTORY_SEPARATOR . 'sources' . DIRECTORY_SEPARATOR;
    }

    /** A layout's ordinary module template dependencies keep their readable source paths. */
    public function pageSourcePhtml(ThemeVersionIdentity $identity, string $layoutType, string $layoutOption, string $logicalPath, string $targetType = 'global', int|string|null $targetId = null): string
    {
        if (substr_count($logicalPath, '::') !== 1 || !str_ends_with($logicalPath, '.phtml')) {
            throw new \InvalidArgumentException('theme_layout_dependency_path_invalid');
        }
        [$module, $relative] = explode('::', $logicalPath, 2);
        return $this->pageSourceRoot($identity, $layoutType, $layoutOption, $targetType, $targetId)
            . str_replace('/', DIRECTORY_SEPARATOR, $this->layoutRoutePath($module . '/' . $relative));
    }

    /**
     * @deprecated Use {@see pageLayoutPhtml()} — single wrap file is the only storefront product.
     *             $structureKey ignored (no structure_key directory).
     */
    public function pagePhtml(ThemeVersionIdentity $identity, string $layoutRouteOrHash, string $structureKey = ''): string
    {
        unset($structureKey);

        return $this->pageLayoutPhtml($identity, $layoutRouteOrHash);
    }

    /**
     * @deprecated Dual-track shell removed — same path as {@see pageLayoutPhtml()}.
     */
    public function shellPhtml(ThemeVersionIdentity $identity, string $layoutRouteOrHash, string $structureKey = ''): string
    {
        unset($structureKey);

        return $this->pageLayoutPhtml($identity, $layoutRouteOrHash);
    }

    /** Page sidecar root (binding/config/assets) — not storefront structure truth. */
    public function pageMetaDir(ThemeVersionIdentity $identity, string $layoutRoute): string
    {
        $route = $this->layoutRoutePath($layoutRoute);
        $rel = \str_replace('/', \DIRECTORY_SEPARATOR, $route);

        return $this->versionModeDir($identity)
            . 'pages' . \DIRECTORY_SEPARATOR
            . 'meta' . \DIRECTORY_SEPARATOR
            . $rel . \DIRECTORY_SEPARATOR;
    }

    /**
     * @deprecated No structure_key directory; returns meta dir for call-site compatibility.
     */
    public function pageStructureDir(ThemeVersionIdentity $identity, string $layoutRouteOrHash, string $structureKey = ''): string
    {
        unset($structureKey);

        return $this->pageMetaDir($identity, $layoutRouteOrHash);
    }

    /**
     * @deprecated structure.json is not storefront truth (C-RP-03). Sidecar only under meta/.
     */
    public function pageStructureJson(ThemeVersionIdentity $identity, string $layoutRouteOrHash, string $structureKey = ''): string
    {
        unset($structureKey);

        return $this->pageMetaDir($identity, $layoutRouteOrHash) . 'structure.json';
    }

    public function pageConfigJson(ThemeVersionIdentity $identity, string $layoutRoute, string $configKey = ''): string
    {
        unset($configKey);

        return $this->pageMetaDir($identity, $layoutRoute) . 'config.json';
    }

    public function pageAssetsJson(ThemeVersionIdentity $identity, string $layoutRoute, string $configKey = ''): string
    {
        unset($configKey);

        return $this->pageMetaDir($identity, $layoutRoute) . 'assets.json';
    }

    public function pageBindingJson(ThemeVersionIdentity $identity, string $layoutRoute): string
    {
        return $this->pageMetaDir($identity, $layoutRoute) . 'binding.json';
    }

    /** @deprecated Artifact binding keys colocated under meta/. */
    public function pageArtifactBindingJson(
        ThemeVersionIdentity $identity,
        string $layoutRoute,
        string $artifactKey,
    ): string {
        return $this->pageMetaDir($identity, $layoutRoute)
            . $this->normalizePathSegment($artifactKey, 'artifact') . '.json';
    }

    /**
     * Flat chrome.phtml under theme/ — no structure_key directory.
     * $structureKey retained for call-site compatibility; ignored on disk.
     */
    public function chromePhtml(ThemeVersionIdentity $identity, string $structureKey = ''): string
    {
        unset($structureKey);

        return $this->chromeRoot($identity) . 'chrome.phtml';
    }

    public function chromeStructureDir(ThemeVersionIdentity $identity, string $structureKey = ''): string
    {
        unset($structureKey);

        return $this->chromeRoot($identity);
    }

    public function chromeConfigJson(ThemeVersionIdentity $identity, string $configKey = ''): string
    {
        unset($configKey);

        return $this->chromeRoot($identity) . 'config.json';
    }

    public function chromeAssetsJson(ThemeVersionIdentity $identity, string $configKey = ''): string
    {
        unset($configKey);

        return $this->chromeRoot($identity) . 'assets.json';
    }

    public function chromeBindingJson(ThemeVersionIdentity $identity): string
    {
        return $this->chromeRoot($identity) . 'binding.json';
    }

    public function chromeArtifactBindingJson(ThemeVersionIdentity $identity, string $artifactKey): string
    {
        return $this->chromeRoot($identity)
            . $this->normalizePathSegment($artifactKey, 'artifact') . '.json';
    }

    public function chromeRenderedHtml(
        ThemeVersionIdentity $identity,
        string $artifactKey,
        string $renderVaryKey,
    ): string {
        return $this->chromeRoot($identity)
            . 'rendered' . \DIRECTORY_SEPARATOR
            . $this->normalizePathSegment($artifactKey, 'artifact') . \DIRECTORY_SEPARATOR
            . $this->normalizePathSegment($renderVaryKey, 'vary') . '.html';
    }

    /**
     * @deprecated Revision segment no longer appears in disk paths; revision lives in binding JSON.
     */
    public function revisionBindingSegment(ThemeVersionIdentity $identity): string
    {
        if ($identity->contentRevision < 1) {
            throw new \InvalidArgumentException('theme_layout_content_revision_required');
        }

        return 'v' . $identity->themeVersionId . '-g' . $identity->contentRevision;
    }

    /**
     * Delete leftover pre-readable hash trees: {64hex}/tv{N}/… under the entity root.
     * Called after cutover bake so stale workers cannot leave storefront dual-truth.
     *
     * @return int Nodes deleted
     */
    public function purgeLegacyHashTreeResidues(): int
    {
        $root = \rtrim($this->root(), '/\\');
        if (!\is_dir($root)) {
            return 0;
        }
        $deleted = 0;
        $themeDirs = @\scandir($root) ?: [];
        foreach ($themeDirs as $themeName) {
            if ($themeName === '.' || $themeName === '..' || !\ctype_digit($themeName)) {
                continue;
            }
            $themePath = $root . \DIRECTORY_SEPARATOR . $themeName;
            if (!\is_dir($themePath)) {
                continue;
            }
            foreach (@\scandir($themePath) ?: [] as $area) {
                if ($area === '.' || $area === '..') {
                    continue;
                }
                if (!\in_array($area, ThemeVersionIdentity::AREAS, true)) {
                    continue;
                }
                $areaPath = $themePath . \DIRECTORY_SEPARATOR . $area;
                if (!\is_dir($areaPath)) {
                    continue;
                }
                foreach (@\scandir($areaPath) ?: [] as $scopeKey) {
                    if ($scopeKey === '.' || $scopeKey === '..') {
                        continue;
                    }
                    if (\preg_match('/^[a-f0-9]{64}$/D', $scopeKey) !== 1) {
                        continue;
                    }
                    $scopePath = $areaPath . \DIRECTORY_SEPARATOR . $scopeKey;
                    if (!\is_dir($scopePath) && !\is_link($scopePath)) {
                        continue;
                    }
                    $deleted += $this->deleteTreeRecursive(
                        \realpath($scopePath) ?: $scopePath
                    );
                }
            }
        }

        return $deleted;
    }

    /** Retire every pre-scope-root derivative after replacement PHTML has been built. */
    public function purgeLegacyDerivedTrees(?int $themeId = null): int
    {
        $root = \rtrim($this->root(), '/\\');
        if (!\is_dir($root)) {
            return 0;
        }
        $root = $this->assertPurgeableEntityRoot($root);
        $deleted = 0;
        foreach (@\scandir($root) ?: [] as $themeName) {
            if (!\ctype_digit($themeName) || ($themeId !== null && (int)$themeName !== $themeId)) {
                continue;
            }
            $themePath = $root . \DIRECTORY_SEPARATOR . $themeName;
            if (!\is_dir($themePath) || \is_link($themePath)) {
                continue;
            }
            foreach (ThemeVersionIdentity::AREAS as $area) {
                $areaPath = $themePath . \DIRECTORY_SEPARATOR . $area;
                if (!\is_dir($areaPath) || \is_link($areaPath)) {
                    continue;
                }
                foreach (@\scandir($areaPath) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..' || $entry === 'scope') {
                        continue;
                    }
                    $path = $areaPath . \DIRECTORY_SEPARATOR . $entry;
                    if (\is_link($path) || \is_file($path)) {
                        if (!@\unlink($path)) {
                            throw new \RuntimeException('theme_layout_entity_purge_unlink_failed:' . $path);
                        }
                        ++$deleted;
                    } elseif (\is_dir($path)) {
                        $deleted += $this->deleteTreeRecursive($path);
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Delete the entire layout-entity bake tree under generated/theme-layout-entities/.
     *
     * @return int Number of filesystem nodes deleted (files + directories)
     */
    public function purgeAllEntities(): int
    {
        return $this->purgeEntityTree($this->root());
    }

    /**
     * Delete legacy var/runtime/theme-layout-entities/ after migration (no-op if absent).
     *
     * @return int Nodes deleted
     */
    public function purgeLegacyVarEntities(): int
    {
        $legacy = \rtrim($this->legacyVarRoot(), '/\\');
        if (!\is_dir($legacy) && !\is_link($legacy)) {
            return 0;
        }

        return $this->purgeEntityTree($legacy);
    }

    /**
     * Enumerate v{N}/ and draft/ roots under the readable entity tree.
     *
     * @return list<array{
     *   path:string,
     *   theme_id:int,
     *   area:string,
     *   scope_key:string,
     *   theme_version_id:int,
     *   mode:string
     * }>
     */
    public function listVersionModeDirectories(): array
    {
        $root = \rtrim($this->root(), '/\\');
        if (!\is_dir($root)) {
            return [];
        }
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item->isDir() || $item->isLink()) {
                continue;
            }
            $relative = \str_replace('\\', '/', \substr($item->getPathname(), \strlen($root) + 1));
            if (!\preg_match('#^(\d+)/(frontend|backend)/scope/(.+)/mode/([^/]+)/(draft|v[1-9][0-9]*)$#D', $relative, $match)) {
                continue;
            }
            $out[] = [
                'path' => $item->getPathname(), 'theme_id' => (int)$match[1],
                'area' => $match[2], 'scope_key' => $match[3], 'store_mode_key' => $match[4],
                'canonical_scope' => $this->decodeScopePath($match[3]),
                'store_mode' => $this->decodePathValue($match[4]),
                'theme_version_id' => $match[5] === 'draft' ? 0 : (int)\substr($match[5], 1),
                'mode' => $match[5] === 'draft' ? ThemeVersionIdentity::MODE_DRAFT : ThemeVersionIdentity::MODE_FORMAL,
            ];
        }

        return $out;
    }

    /**
     * Delete one v{N}/ or draft/ derived tree. Must stay under the entity root.
     *
     * @return int Nodes deleted
     */
    public function purgeVersionModeDirectory(string $absoluteDirectory): int
    {
        $absoluteDirectory = \rtrim(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, \trim($absoluteDirectory)), "/\\");
        if ($absoluteDirectory === '' || !\is_dir($absoluteDirectory)) {
            return 0;
        }
        $rootReal = \realpath($this->root());
        $targetReal = \realpath($absoluteDirectory);
        if ($rootReal === false || $targetReal === false) {
            return 0;
        }
        $rootNorm = \strtolower(\rtrim(\str_replace('\\', '/', $rootReal), '/') . '/');
        $targetNorm = \strtolower(\str_replace('\\', '/', $targetReal));
        if (!\str_starts_with($targetNorm . '/', $rootNorm)) {
            throw new \RuntimeException('theme_layout_entity_purge_version_outside_root');
        }
        $base = \basename($targetReal);
        if ($base !== ThemeVersionIdentity::MODE_DRAFT && !\preg_match('/^v\d+$/', $base)) {
            throw new \RuntimeException('theme_layout_entity_purge_version_mode_mismatch');
        }

        return $this->deleteTreeRecursive($targetReal);
    }

    /**
     * @return int Number of filesystem nodes deleted (files + directories)
     */
    public function purgeEntityTree(string $absoluteDirectory): int
    {
        $absoluteDirectory = \rtrim(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, \trim($absoluteDirectory)), "/\\");
        if ($absoluteDirectory === '') {
            throw new \InvalidArgumentException('theme_layout_entity_purge_empty_path');
        }

        if (!\is_dir($absoluteDirectory) && !\is_link($absoluteDirectory)) {
            return 0;
        }

        $resolved = $this->assertPurgeableEntityRoot($absoluteDirectory);

        return $this->deleteTreeRecursive($resolved);
    }

    /**
     * @return string Realpath of a purgeable entity root
     */
    public function assertPurgeableEntityRoot(string $absoluteDirectory): string
    {
        $bp = \realpath((string)BP);
        if ($bp === false) {
            throw new \RuntimeException('theme_layout_entity_purge_bp_unresolved');
        }

        $resolved = \realpath($absoluteDirectory);
        if ($resolved === false) {
            if (\is_dir($absoluteDirectory)) {
                $resolved = $absoluteDirectory;
            } else {
                throw new \RuntimeException('theme_layout_entity_purge_path_unresolved');
            }
        }

        if (\is_link($absoluteDirectory)) {
            throw new \RuntimeException('theme_layout_entity_purge_symlink_root_forbidden');
        }

        if (\basename($resolved) !== self::ROOT_SEGMENT) {
            throw new \RuntimeException('theme_layout_entity_purge_basename_mismatch');
        }

        $resolvedNorm = \strtolower(\str_replace('\\', '/', $resolved));
        $bpNorm = \strtolower(\str_replace('\\', '/', $bp));
        if ($resolvedNorm === $bpNorm) {
            throw new \RuntimeException('theme_layout_entity_purge_outside_allowed_root');
        }

        $allowedPrefixes = [];
        $generated = \defined('\Weline\Framework\App\Env::GENERATED_DIR')
            ? (string)\Weline\Framework\App\Env::GENERATED_DIR
            : ($bp . \DIRECTORY_SEPARATOR . 'generated');
        $generatedReal = \realpath($generated);
        if ($generatedReal !== false) {
            $allowedPrefixes[] = \strtolower(\rtrim(\str_replace('\\', '/', $generatedReal), '/') . '/');
        } else {
            $allowedPrefixes[] = \strtolower(\rtrim(\str_replace('\\', '/', $generated), '/') . '/');
        }
        $varRoot = \realpath($bp . \DIRECTORY_SEPARATOR . 'var');
        if ($varRoot !== false) {
            $allowedPrefixes[] = \strtolower(\rtrim(\str_replace('\\', '/', $varRoot), '/') . '/');
        }
        if ($this->rootOverride !== null && $this->rootOverride !== '') {
            $overrideParent = \dirname(\rtrim($this->rootOverride, '/\\'));
            $overrideReal = \realpath($overrideParent);
            if ($overrideReal !== false) {
                $allowedPrefixes[] = \strtolower(\rtrim(\str_replace('\\', '/', $overrideReal), '/') . '/');
            }
        }

        $ok = false;
        foreach ($allowedPrefixes as $prefix) {
            if (\str_starts_with($resolvedNorm . '/', $prefix)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new \RuntimeException('theme_layout_entity_purge_outside_allowed_root');
        }

        return $resolved;
    }

    private function copyTreeRecursive(string $src, string $dest): int
    {
        $src = \rtrim($src, '/\\');
        $dest = \rtrim($dest, '/\\');
        if (!\is_dir($dest) && !@\mkdir($dest, 0775, true) && !\is_dir($dest)) {
            throw new \RuntimeException('theme_layout_entity_migrate_mkdir_failed:' . $dest);
        }
        $copied = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $rel = \substr($item->getPathname(), \strlen($src));
            $target = $dest . $rel;
            if ($item->isDir()) {
                if (!\is_dir($target) && !@\mkdir($target, 0775, true) && !\is_dir($target)) {
                    throw new \RuntimeException('theme_layout_entity_migrate_mkdir_failed:' . $target);
                }
                continue;
            }
            $parent = \dirname($target);
            if (!\is_dir($parent) && !@\mkdir($parent, 0775, true) && !\is_dir($parent)) {
                throw new \RuntimeException('theme_layout_entity_migrate_mkdir_failed:' . $parent);
            }
            if (!@\copy($item->getPathname(), $target)) {
                throw new \RuntimeException('theme_layout_entity_migrate_copy_failed:' . $item->getPathname());
            }
            ++$copied;
        }

        return $copied;
    }

    private function deleteTreeRecursive(string $resolvedRoot): int
    {
        $deleted = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolvedRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $real = \realpath($path) ?: $path;
            $rootNorm = \strtolower(\rtrim(\str_replace('\\', '/', $resolvedRoot), '/') . '/');
            $realNorm = \strtolower(\str_replace('\\', '/', $real));
            if ($realNorm !== \rtrim($rootNorm, '/') && !\str_starts_with($realNorm . '/', $rootNorm)) {
                throw new \RuntimeException('theme_layout_entity_purge_escape:' . $path);
            }
            if ($item->isLink()) {
                if (!@\unlink($path)) {
                    throw new \RuntimeException('theme_layout_entity_purge_unlink_failed:' . $path);
                }
                ++$deleted;
                continue;
            }
            if ($item->isDir()) {
                if (!@\rmdir($path)) {
                    throw new \RuntimeException('theme_layout_entity_purge_rmdir_failed:' . $path);
                }
                ++$deleted;
                continue;
            }
            if (!@\unlink($path)) {
                throw new \RuntimeException('theme_layout_entity_purge_unlink_failed:' . $path);
            }
            ++$deleted;
        }

        if (!@\rmdir($resolvedRoot)) {
            throw new \RuntimeException('theme_layout_entity_purge_rmdir_root_failed:' . $resolvedRoot);
        }

        return $deleted + 1;
    }

    private function decodeScopePath(string $path): string
    {
        $parts = explode('/', $path);
        $decoded = [];
        for ($i = 0, $count = count($parts); $i < $count; ++$i) {
            $part = $parts[$i];
            if (preg_match('/^\+(\d+)$/D', $part, $match)) {
                $length = (int)$match[1];
                $part = implode('', array_slice($parts, $i + 1, $length));
                $i += $length;
            }
            $decoded[] = $this->decodePathValue($part);
        }
        return implode('.', $decoded);
    }

    private function decodePathValue(string $value): string
    {
        if ($value === '!') { return ''; }
        $value = (string)preg_replace_callback('/~([a-z])/', static fn(array $m): string => strtoupper($m[1]), $value);
        return rawurldecode($value);
    }

    private function normalizePathSegment(string $segment, string $fallback): string
    {
        $segment = \trim($segment);
        if ($segment === '') {
            return $fallback;
        }
        $safe = \preg_replace('/[^a-zA-Z0-9._-]+/', '_', $segment) ?? '';
        $safe = \trim($safe, '._-');

        return $safe !== '' ? $safe : $fallback;
    }
}
