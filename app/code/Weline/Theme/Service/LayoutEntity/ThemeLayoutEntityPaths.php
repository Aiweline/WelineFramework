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
 *   {theme_id}/{scope_key}/pages/{identity_key}/{structure_or_release}/page-config.json
 *   {theme_id}/{scope_key}/pages/{identity_key}/{structure_or_release}/structure.json
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

    /**
     * @param string $structureOrRelease structure_key hash or release segment (e.g. r123)
     */
    public function pageDir(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): string {
        return $this->themeScopeDir($themeId, $scope)
            . 'pages' . \DIRECTORY_SEPARATOR
            . $this->normalizePathSegment($identityKey, 'identity') . \DIRECTORY_SEPARATOR
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

    public function pageConfigJson(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): string {
        return $this->pageDir($themeId, $scope, $identityKey, $structureOrRelease) . 'page-config.json';
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
