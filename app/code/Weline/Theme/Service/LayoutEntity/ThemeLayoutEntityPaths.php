<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Theme\Api\Version\ThemeVersionIdentity;

/**
 * Disk layout for baked theme layout entities (version-isolated tree).
 *
 *   var/runtime/theme-layout-entities/
 *     {theme_id}/{area}/{scope_key}/tv{V}/{formal|draft}/
 *       chrome/structures|configs|bindings|rendered/…
 *       pages/{layout_identity_hash}/structures|configs|bindings/…
 *
 * No current pointer file, no r/d/s segments, no scope-level shared structure bags.
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

        return \rtrim((string)BP, '/\\') . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR
            . 'runtime' . \DIRECTORY_SEPARATOR . self::ROOT_SEGMENT . \DIRECTORY_SEPARATOR;
    }

    /**
     * Deterministic scope_key: full SHA-256 of canonical_scope + store_mode.
     * Binding stores original values separately.
     */
    public function scopeKey(string $canonicalScope, string $storeMode = 'normal'): string
    {
        $canonicalScope = \trim($canonicalScope);
        $storeMode = \trim($storeMode);
        if ($storeMode === '') {
            $storeMode = 'normal';
        }
        if ($canonicalScope === '') {
            return \hash('sha256', "\0" . $storeMode);
        }

        return \hash('sha256', $canonicalScope . "\0" . $storeMode);
    }

    /** Full resource identity hash — never truncated. */
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
            . $identity->scopeKey() . \DIRECTORY_SEPARATOR;
    }

    public function versionModeDir(ThemeVersionIdentity $identity): string
    {
        if ($identity->themeVersionId < 1) {
            throw new \InvalidArgumentException('theme_layout_version_id_required');
        }
        $modeDir = $identity->mode === ThemeVersionIdentity::MODE_DRAFT ? 'draft' : 'formal';

        return $this->ownerDir($identity)
            . 'tv' . $identity->themeVersionId . \DIRECTORY_SEPARATOR
            . $modeDir . \DIRECTORY_SEPARATOR;
    }

    public function chromeRoot(ThemeVersionIdentity $identity): string
    {
        return $this->versionModeDir($identity) . 'chrome' . \DIRECTORY_SEPARATOR;
    }

    public function pageRoot(ThemeVersionIdentity $identity, string $layoutIdentityHash): string
    {
        return $this->versionModeDir($identity)
            . 'pages' . \DIRECTORY_SEPARATOR
            . $this->identityKey($layoutIdentityHash) . \DIRECTORY_SEPARATOR;
    }

    public function pageStructureDir(ThemeVersionIdentity $identity, string $layoutIdentityHash, string $structureKey): string
    {
        return $this->pageRoot($identity, $layoutIdentityHash)
            . 'structures' . \DIRECTORY_SEPARATOR
            . 'v' . $identity->themeVersionId . \DIRECTORY_SEPARATOR
            . $this->normalizeStructureKey($structureKey) . \DIRECTORY_SEPARATOR;
    }

    public function pagePhtml(ThemeVersionIdentity $identity, string $layoutIdentityHash, string $structureKey): string
    {
        return $this->pageStructureDir($identity, $layoutIdentityHash, $structureKey) . 'layout.phtml';
    }

    public function shellPhtml(ThemeVersionIdentity $identity, string $layoutIdentityHash, string $structureKey): string
    {
        return $this->pageStructureDir($identity, $layoutIdentityHash, $structureKey) . 'shell.phtml';
    }

    public function pageStructureJson(ThemeVersionIdentity $identity, string $layoutIdentityHash, string $structureKey): string
    {
        return $this->pageStructureDir($identity, $layoutIdentityHash, $structureKey) . 'structure.json';
    }

    public function pageConfigDir(ThemeVersionIdentity $identity, string $layoutIdentityHash, string $configKey): string
    {
        return $this->pageRoot($identity, $layoutIdentityHash)
            . 'configs' . \DIRECTORY_SEPARATOR
            . 'v' . $identity->themeVersionId . \DIRECTORY_SEPARATOR
            . $this->normalizeConfigKey($configKey) . \DIRECTORY_SEPARATOR;
    }

    public function pageConfigJson(ThemeVersionIdentity $identity, string $layoutIdentityHash, string $configKey): string
    {
        return $this->pageConfigDir($identity, $layoutIdentityHash, $configKey) . 'config.json';
    }

    public function pageAssetsJson(ThemeVersionIdentity $identity, string $layoutIdentityHash, string $configKey): string
    {
        return $this->pageConfigDir($identity, $layoutIdentityHash, $configKey) . 'assets.json';
    }

    public function pageBindingsDir(ThemeVersionIdentity $identity, string $layoutIdentityHash): string
    {
        return $this->pageRoot($identity, $layoutIdentityHash)
            . 'bindings' . \DIRECTORY_SEPARATOR
            . $this->revisionBindingSegment($identity) . \DIRECTORY_SEPARATOR;
    }

    public function pageBindingJson(ThemeVersionIdentity $identity, string $layoutIdentityHash): string
    {
        return $this->pageBindingsDir($identity, $layoutIdentityHash) . 'binding.json';
    }

    public function pageArtifactBindingJson(
        ThemeVersionIdentity $identity,
        string $layoutIdentityHash,
        string $artifactKey,
    ): string {
        return $this->pageBindingsDir($identity, $layoutIdentityHash)
            . $this->normalizePathSegment($artifactKey, 'artifact') . '.json';
    }

    public function chromeStructureDir(ThemeVersionIdentity $identity, string $structureKey): string
    {
        return $this->chromeRoot($identity)
            . 'structures' . \DIRECTORY_SEPARATOR
            . 'v' . $identity->themeVersionId . \DIRECTORY_SEPARATOR
            . $this->normalizeStructureKey($structureKey) . \DIRECTORY_SEPARATOR;
    }

    public function chromePhtml(ThemeVersionIdentity $identity, string $structureKey): string
    {
        return $this->chromeStructureDir($identity, $structureKey) . 'chrome.phtml';
    }

    public function chromeConfigDir(ThemeVersionIdentity $identity, string $configKey): string
    {
        return $this->chromeRoot($identity)
            . 'configs' . \DIRECTORY_SEPARATOR
            . 'v' . $identity->themeVersionId . \DIRECTORY_SEPARATOR
            . $this->normalizeConfigKey($configKey) . \DIRECTORY_SEPARATOR;
    }

    public function chromeConfigJson(ThemeVersionIdentity $identity, string $configKey): string
    {
        return $this->chromeConfigDir($identity, $configKey) . 'config.json';
    }

    public function chromeAssetsJson(ThemeVersionIdentity $identity, string $configKey): string
    {
        return $this->chromeConfigDir($identity, $configKey) . 'assets.json';
    }

    public function chromeBindingsDir(ThemeVersionIdentity $identity): string
    {
        return $this->chromeRoot($identity)
            . 'bindings' . \DIRECTORY_SEPARATOR
            . $this->revisionBindingSegment($identity) . \DIRECTORY_SEPARATOR;
    }

    public function chromeBindingJson(ThemeVersionIdentity $identity): string
    {
        return $this->chromeBindingsDir($identity) . 'binding.json';
    }

    public function chromeArtifactBindingJson(ThemeVersionIdentity $identity, string $artifactKey): string
    {
        return $this->chromeBindingsDir($identity)
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

    public function revisionBindingSegment(ThemeVersionIdentity $identity): string
    {
        if ($identity->contentRevision < 1) {
            throw new \InvalidArgumentException('theme_layout_content_revision_required');
        }

        return 'v' . $identity->themeVersionId . '-g' . $identity->contentRevision;
    }

    /**
     * Delete the entire layout-entity bake tree under var/runtime/theme-layout-entities/.
     *
     * @return int Number of filesystem nodes deleted (files + directories)
     */
    public function purgeAllEntities(): int
    {
        return $this->purgeEntityTree($this->root());
    }

    /**
     * Enumerate tv{V}/{formal|draft} derived roots under the entity tree.
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
        $themeDirs = @\scandir($root) ?: [];
        foreach ($themeDirs as $themeName) {
            if ($themeName === '.' || $themeName === '..' || !\ctype_digit($themeName)) {
                continue;
            }
            $themeId = (int)$themeName;
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
                    if ($scopeKey === '.' || $scopeKey === '..' || !\preg_match('/^[a-f0-9]{64}$/D', $scopeKey)) {
                        continue;
                    }
                    $scopePath = $areaPath . \DIRECTORY_SEPARATOR . $scopeKey;
                    if (!\is_dir($scopePath)) {
                        continue;
                    }
                    foreach (@\scandir($scopePath) ?: [] as $tv) {
                        if ($tv === '.' || $tv === '..' || !\preg_match('/^tv(\d+)$/', $tv, $m)) {
                            continue;
                        }
                        $versionId = (int)$m[1];
                        $tvPath = $scopePath . \DIRECTORY_SEPARATOR . $tv;
                        if (!\is_dir($tvPath)) {
                            continue;
                        }
                        foreach (ThemeVersionIdentity::MODES as $mode) {
                            $modePath = $tvPath . \DIRECTORY_SEPARATOR . $mode;
                            if (!\is_dir($modePath)) {
                                continue;
                            }
                            $out[] = [
                                'path' => $modePath,
                                'theme_id' => $themeId,
                                'area' => $area,
                                'scope_key' => $scopeKey,
                                'theme_version_id' => $versionId,
                                'mode' => $mode,
                            ];
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Delete one tv{V}/{mode} derived tree. Must stay under the entity root.
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
        if (!\in_array($base, ThemeVersionIdentity::MODES, true)) {
            throw new \RuntimeException('theme_layout_entity_purge_version_mode_mismatch');
        }
        $tvBase = \basename(\dirname($targetReal));
        if (!\preg_match('/^tv\d+$/', $tvBase)) {
            throw new \RuntimeException('theme_layout_entity_purge_version_tv_mismatch');
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

        $varRoot = \realpath($bp . \DIRECTORY_SEPARATOR . 'var');
        if ($varRoot === false || !\is_dir($varRoot)) {
            throw new \RuntimeException('theme_layout_entity_purge_var_unresolved');
        }

        $resolved = \realpath($absoluteDirectory);
        if ($resolved === false) {
            throw new \RuntimeException('theme_layout_entity_purge_path_unresolved');
        }

        if (\is_link($absoluteDirectory)) {
            throw new \RuntimeException('theme_layout_entity_purge_symlink_root_forbidden');
        }

        $varPrefix = \strtolower(\rtrim(\str_replace('\\', '/', $varRoot), '/') . '/');
        $resolvedNorm = \strtolower(\str_replace('\\', '/', $resolved));
        if ($resolvedNorm === \rtrim($varPrefix, '/')
            || $resolvedNorm === \strtolower(\str_replace('\\', '/', $bp))
            || !\str_starts_with($resolvedNorm . '/', $varPrefix)
        ) {
            throw new \RuntimeException('theme_layout_entity_purge_outside_var');
        }

        if (\basename($resolved) !== self::ROOT_SEGMENT) {
            throw new \RuntimeException('theme_layout_entity_purge_basename_mismatch');
        }

        $expectedProduction = \realpath($this->root());
        if ($expectedProduction !== false && $resolved === $expectedProduction) {
            return $resolved;
        }

        if (!\str_starts_with($resolvedNorm . '/', $varPrefix)) {
            throw new \RuntimeException('theme_layout_entity_purge_outside_var');
        }

        return $resolved;
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

    private function normalizeStructureKey(string $structureKey): string
    {
        $structureKey = \strtolower(\trim($structureKey));
        if ($structureKey === '') {
            throw new \InvalidArgumentException('theme_layout_structure_key_empty');
        }
        // Accept both bare sha256 and legacy s{sha256} prefixes by normalizing to full key segment.
        if (\preg_match('/^s([a-f0-9]{64})$/D', $structureKey, $m) === 1) {
            $structureKey = $m[1];
        }
        if (\preg_match('/^[a-f0-9]{64}$/D', $structureKey) !== 1) {
            throw new \InvalidArgumentException('theme_layout_structure_key_invalid');
        }

        return $structureKey;
    }

    private function normalizeConfigKey(string $configKey): string
    {
        $configKey = \strtolower(\trim($configKey));
        if (\preg_match('/^[a-f0-9]{64}$/D', $configKey) !== 1) {
            throw new \InvalidArgumentException('theme_layout_config_key_invalid');
        }

        return $configKey;
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
