<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * Disk layout for baked theme layout entities under var/runtime/theme-layout-entities/.
 *
 * Chrome:
 *   {theme_id}/{scope_key}/tv{theme_version_id}/chrome/chrome.phtml
 *   {theme_id}/{scope_key}/tv{theme_version_id}/chrome/chrome-config.json
 *
 * Page:
 *   {theme_id}/{scope_key}/pages/{identity_key}/{structure_or_release}/layout.phtml
 *   {theme_id}/{scope_key}/pages/{identity_key}/{structure_or_release}/shell.phtml  (wave8-8s5: chrome+page 整壳)
 *   {theme_id}/{scope_key}/pages/{identity_key}/{structure_or_release}/page-config.json
 *   {theme_id}/{scope_key}/pages/{identity_key}/{structure_or_release}/page-assets.json
 *   {theme_id}/{scope_key}/pages/{identity_key}/{structure_or_release}/structure.json
 *   {theme_id}/{scope_key}/tv{theme_version_id}/chrome/chrome-assets.json
 */
final class ThemeLayoutEntityPaths
{
    public const ROOT_SEGMENT = 'theme-layout-entities';

    public function root(): string
    {
        return \rtrim((string)BP, '/\\') . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR
            . 'runtime' . \DIRECTORY_SEPARATOR . self::ROOT_SEGMENT . \DIRECTORY_SEPARATOR;
    }

    public function scopeKey(string $scope): string
    {
        $scope = \trim($scope);
        if ($scope === '') {
            return 'empty';
        }
        // Keep trailing underscores — storage sentinels end with __ (e.g. __channel__).
        $sanitized = \preg_replace('/[^a-zA-Z0-9._-]+/', '_', $scope) ?? '';
        $sanitized = \trim($sanitized, '.-');
        if ($sanitized !== '' && \strlen($sanitized) <= 120) {
            return $sanitized;
        }

        return \sha1($scope);
    }

    public function identityKey(string $identityHash): string
    {
        $identityHash = \strtolower(\trim($identityHash));
        if ($identityHash === '') {
            return 'unknown';
        }

        return \substr($identityHash, 0, 16);
    }

    public function themeScopeDir(int $themeId, string $scope): string
    {
        return $this->root()
            . $themeId . \DIRECTORY_SEPARATOR
            . $this->scopeKey($scope) . \DIRECTORY_SEPARATOR;
    }

    public function chromeDir(int $themeId, string $scope, int $themeVersionId): string
    {
        return $this->themeScopeDir($themeId, $scope)
            . 'tv' . $themeVersionId . \DIRECTORY_SEPARATOR
            . 'chrome' . \DIRECTORY_SEPARATOR;
    }

    public function chromePhtml(int $themeId, string $scope, int $themeVersionId): string
    {
        return $this->chromeDir($themeId, $scope, $themeVersionId) . 'chrome.phtml';
    }

    public function chromeConfigJson(int $themeId, string $scope, int $themeVersionId): string
    {
        return $this->chromeDir($themeId, $scope, $themeVersionId) . 'chrome-config.json';
    }

    public function chromeAssetsJson(int $themeId, string $scope, int $themeVersionId): string
    {
        return $this->chromeDir($themeId, $scope, $themeVersionId) . 'chrome-assets.json';
    }

    /**
     * Request-time snapshot of renderCurrent() output. Invalidated when chrome.phtml
     * or chrome-config.json is newer (see ThemeLayoutEntityChrome).
     *
     * Prefer locale-keyed files: chrome.rendered.{locale}.html. The bare
     * chrome.rendered.html name is legacy (locale-agnostic) and must not be reused.
     */
    public function chromeRenderedHtml(
        int $themeId,
        string $scope,
        int $themeVersionId,
        ?string $locale = null,
    ): string {
        $dir = $this->chromeDir($themeId, $scope, $themeVersionId);
        $locale = \trim((string)$locale);
        if ($locale !== ''
            && \preg_match('/^[a-z]{2,3}_[A-Za-z0-9]+(?:_[A-Za-z0-9]+)?$/', $locale) === 1
        ) {
            return $dir . 'chrome.rendered.' . $locale . '.html';
        }

        // Materializer invalidation helper: pattern prefix (callers glob).
        return $dir . 'chrome.rendered.html';
    }

    /**
     * Absolute paths of all chrome.rendered*.html snapshots under a chrome dir
     * (legacy bare file + every locale-keyed variant).
     *
     * @return list<string>
     */
    public function chromeRenderedHtmlSnapshots(int $themeId, string $scope, int $themeVersionId): array
    {
        $dir = $this->chromeDir($themeId, $scope, $themeVersionId);
        if (!\is_dir($dir)) {
            return [];
        }

        $matches = \glob($dir . 'chrome.rendered*.html') ?: [];
        $out = [];
        foreach ($matches as $path) {
            if (\is_string($path) && $path !== '' && \is_file($path)) {
                $out[] = $path;
            }
        }

        return $out;
    }

    public function pageIdentityDir(int $themeId, string $scope, string $identityKey): string
    {
        return $this->themeScopeDir($themeId, $scope)
            . 'pages' . \DIRECTORY_SEPARATOR
            . $this->normalizePathSegment($identityKey, 'identity') . \DIRECTORY_SEPARATOR;
    }

    /**
     * Points at the current solidified page segment. Config writes must not rewrite this file's target phtml.
     */
    public function pageCurrentJson(int $themeId, string $scope, string $identityKey): string
    {
        return $this->pageIdentityDir($themeId, $scope, $identityKey) . 'current.json';
    }

    public function pageDir(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): string {
        return $this->pageIdentityDir($themeId, $scope, $identityKey)
            . $this->normalizePathSegment($structureOrRelease, 'structure') . \DIRECTORY_SEPARATOR;
    }

    public function pagePhtml(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): string {
        return $this->pageDir($themeId, $scope, $identityKey, $structureOrRelease) . 'layout.phtml';
    }

    /**
     * wave8-8s5: published whole-shell bake (chrome.phtml source + layout.phtml source).
     * Storefront includes this file once — header/chrome already in the shell.
     */
    public function shellPhtml(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): string {
        return $this->pageDir($themeId, $scope, $identityKey, $structureOrRelease) . 'shell.phtml';
    }

    public function pageConfigJson(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): string {
        return $this->pageDir($themeId, $scope, $identityKey, $structureOrRelease) . 'page-config.json';
    }

    public function pageAssetsJson(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): string {
        return $this->pageDir($themeId, $scope, $identityKey, $structureOrRelease) . 'page-assets.json';
    }

    public function pageStructureJson(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): string {
        return $this->pageDir($themeId, $scope, $identityKey, $structureOrRelease) . 'structure.json';
    }

    public function pageStructureOrRelease(string $structureKey, bool $published, ?int $releaseId): string
    {
        if ($published && $releaseId !== null && $releaseId > 0) {
            return 'r' . $releaseId;
        }
        $structureKey = \strtolower(\trim($structureKey));
        if ($structureKey === '') {
            return 'sempty';
        }

        return 's' . $structureKey;
    }

    /** 结构模板保留 s 身份；草稿与发布实体只保存绑定。 */
    public function pageBindingJson(int $themeId, string $scope, string $identityKey, string $entityKey): string
    {
        return $this->pageDir($themeId, $scope, $identityKey, $entityKey) . 'binding.json';
    }

    public function pageConfigBundleDir(int $themeId, string $scope, string $identityKey, string $configKey): string
    {
        return $this->pageIdentityDir($themeId, $scope, $identityKey)
            . 'configs/' . $this->normalizePathSegment($configKey, 'config') . '/';
    }

    public function chromeStructureDir(int $themeId, string $scope, string $structureKey): string
    {
        return $this->themeScopeDir($themeId, $scope) . 'chrome/'
            . $this->normalizePathSegment($structureKey, 'structure') . '/';
    }

    public function chromeConfigBundleDir(int $themeId, string $scope, string $configKey): string
    {
        return $this->themeScopeDir($themeId, $scope) . 'chrome/configs/'
            . $this->normalizePathSegment($configKey, 'config') . '/';
    }

    public function chromeBindingJson(int $themeId, string $scope, int $versionId): string
    {
        return $this->chromeDir($themeId, $scope, $versionId) . 'binding.json';
    }

    /**
     * Delete the entire layout-entity bake tree under var/runtime/theme-layout-entities/.
     * Used on setup:upgrade so storefront dynamicSolidify rebuilds from current source templates
     * (never rematerialize stale DB structure as the upgrade recovery path).
     *
     * Missing root is idempotent success (returns 0). Unsafe paths throw.
     *
     * @return int Number of filesystem nodes deleted (files + directories)
     */
    public function purgeAllEntities(): int
    {
        return $this->purgeEntityTree($this->root());
    }

    /**
     * Delete one absolute entity-root directory after safety checks.
     * Production callers use {@see purgeAllEntities()}; tests may pass a sibling
     * `…/var/…/theme-layout-entities` tree under BP.
     *
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

        // Allow unit-test sibling trees under BP/var/**/theme-layout-entities only.
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
