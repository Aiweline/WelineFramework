<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Theme\Helper\WidgetI18n;

/**
 * Storefront/preview chrome render via baked entity phtml (hard fail if missing).
 *
 * Heavy chrome scopes (store/website with footer-container) can take tens of seconds
 * to render on a cold worker. Channel overlays only bake footer-extras; injectChromeSlots
 * must still pull footer from ancestors. Without a durable snapshot, ancestor renders
 * time out / soft-skip and every page keeps an empty weline-footer--shell.
 *
 * Snapshot file is always colocated with the resolved chrome.phtml (not the request
 * scope). Published pointers often inherit an ancestor version — keying cache by the
 * leaf request scope would poison sibling directories with the wrong HTML.
 *
 * Snapshots are ALSO keyed by storefront locale: chrome.rendered.{locale}.html.
 * A single locale-agnostic chrome.rendered.html froze zh labels into every EN/HI/… page.
 */
final class ThemeLayoutEntityChrome
{
    public function __construct(
        private readonly ThemeLayoutEntityPointerResolver $pointers,
        private readonly ThemeLayoutEntityPaths $paths,
    ) {
    }

    /**
     * Render shared chrome for theme+scope. Prefer published unless $preview.
     * Optional $themeVersionId forces a specific version path when set.
     *
     * @throws \RuntimeException when chrome entity is missing
     */
    public function renderCurrent(
        int $themeId,
        string $scope,
        ?int $themeVersionId = null,
        bool $preview = false,
    ): string {
        if ($themeId < 1 || \trim($scope) === '') {
            throw new \RuntimeException('theme_layout_entity_chrome_invalid_identity');
        }

        $path = null;
        if ($themeVersionId !== null && $themeVersionId > 0) {
            $path = $this->paths->chromePhtml($themeId, $scope, $themeVersionId);
        } else {
            $pointer = $preview
                ? $this->pointers->resolveCurrentChrome($themeId, $scope)
                : $this->pointers->resolvePublishedChrome($themeId, $scope);
            $path = \is_array($pointer) ? (string)($pointer['path'] ?? '') : '';
        }

        if ($path === '' || !\is_file($path)) {
            throw new \RuntimeException(
                'theme_layout_entity_chrome_missing: theme=' . $themeId
                . ' scope=' . $scope
                . ($themeVersionId ? (' tv=' . $themeVersionId) : '')
            );
        }

        // Preview/draft always renders live so editors see unpublished chrome nodes.
        if (!$preview) {
            $cached = $this->readRenderedCache($path);
            if ($cached !== null) {
                return $cached;
            }
        }

        \ob_start();
        try {
            include $path;
            $html = (string)\ob_get_clean();
        } catch (\Throwable $e) {
            if (\ob_get_level() > 0) {
                \ob_end_clean();
            }
            throw new \RuntimeException(
                'theme_layout_entity_chrome_render_failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!$preview && $html !== '') {
            $this->writeRenderedCache($path, $html);
        }

        return $html;
    }

    private function renderedCachePath(string $chromePhtmlPath): string
    {
        $locale = $this->normalizeLocaleSegment(WidgetI18n::storefrontLocale());

        return \dirname($chromePhtmlPath)
            . \DIRECTORY_SEPARATOR
            . 'chrome.rendered.'
            . $locale
            . '.html';
    }

    private function normalizeLocaleSegment(string $locale): string
    {
        $locale = \trim($locale);
        if ($locale === ''
            || \preg_match('/^[a-z]{2,3}_[A-Za-z0-9]+(?:_[A-Za-z0-9]+)?$/', $locale) !== 1
        ) {
            return 'zh_Hans_CN';
        }

        return $locale;
    }

    private function readRenderedCache(string $chromePhtmlPath): ?string
    {
        $cachePath = $this->renderedCachePath($chromePhtmlPath);
        if (!\is_file($cachePath)) {
            return null;
        }

        $cacheMtime = @\filemtime($cachePath);
        $phtmlMtime = @\filemtime($chromePhtmlPath);
        if ($cacheMtime === false || $phtmlMtime === false || $cacheMtime < $phtmlMtime) {
            return null;
        }

        $configPath = \dirname($chromePhtmlPath) . \DIRECTORY_SEPARATOR . 'chrome-config.json';
        if (\is_file($configPath)) {
            $configMtime = @\filemtime($configPath);
            if ($configMtime !== false && $cacheMtime < $configMtime) {
                return null;
            }
        }

        $html = @\file_get_contents($cachePath);
        if (!\is_string($html) || $html === '') {
            return null;
        }

        return $html;
    }

    private function writeRenderedCache(string $chromePhtmlPath, string $html): void
    {
        $cachePath = $this->renderedCachePath($chromePhtmlPath);
        $dir = \dirname($cachePath);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return;
        }

        $tmp = $cachePath . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, $html) === false) {
            return;
        }
        if (!@\rename($tmp, $cachePath)) {
            @\unlink($tmp);
        }
    }
}
