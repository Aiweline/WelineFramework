<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/** Compatibility facade; ordinary partial fetches consume the same pinned PHTML sources. */
final class ThemeLayoutEntityChrome
{
    public function __construct(
        private readonly ThemeLayoutEntityPointerResolver $pointers,
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ?\Weline\Framework\Cache\Service\StorefrontScopeHotCache $hotCache = null,
        private readonly ?\Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface $scopes = null,
    ) {}

    public function renderCurrent(int $themeId, string $scope, ?int $themeVersionId = null, bool $preview = false): string
    {
        $html = '';
        foreach ($this->resolveRenderSources($themeId, $scope, $preview) as $source) {
            if ($themeVersionId !== null && $source['version_id'] !== $themeVersionId) { continue; }
            $html .= $this->renderPinned($source['path']);
        }
        return $html;
    }

    public function resolveRenderSource(int $themeId, string $scope, ?int $themeVersionId = null, bool $preview = false): array
    {
        foreach ($this->resolveRenderSources($themeId, $scope, $preview) as $source) {
            if ($themeVersionId === null || $source['version_id'] === $themeVersionId) { return $source; }
        }
        return ['path' => '', 'binding' => null, 'scope' => $scope, 'version_id' => $themeVersionId, 'preview' => $preview];
    }

    public function resolveRenderSources(int $themeId, string $scope, bool $preview = false): array
    {
        $snapshot = ThemeLayoutSourceSnapshot::current();
        if ($snapshot === null || $snapshot->identity->themeId !== $themeId || $snapshot->identity->canonicalScope !== $scope) { return []; }
        $out = [];
        foreach (['header', 'footer', 'sidebar'] as $type) {
            $path = $snapshot->partialPath($type);
            if ($path !== null) {
                $out[] = ['path' => $path, 'binding' => null, 'scope' => $scope,
                    'version_id' => $snapshot->identity->themeVersionId, 'preview' => $snapshot->identity->mode === 'draft'];
            }
        }
        return $out;
    }

    public function scopeFallbackChain(string $scope): array { return [$scope]; }
    public function seedPublishedHotCacheEager(int $themeId, string $scope): array { return ['seeded' => 0, 'skipped' => true]; }
    public function forceResolidifyRenderedSnapshot(string $chromePhtmlPath, string $locale = '', ?EntityRenderBinding $binding = null): string
    {
        return $this->renderPinned($chromePhtmlPath);
    }
    public function purgeLegacyChromeRenderedSnapshotsBeside(string $chromePhtmlPath): int
    {
        $count = 0;
        foreach (glob(dirname($chromePhtmlPath) . '/chrome.rendered.*') ?: [] as $path) {
            if (is_file($path) && !is_link($path) && unlink($path)) { $count++; }
        }
        return $count;
    }
    private function renderPinned(string $path): string
    {
        $snapshot = ThemeLayoutSourceSnapshot::current();
        $source = $snapshot?->source($path);
        if ($source === null) { return ''; }
        $template = ObjectManager::getInstance(Template::class);
        return $template->fetchSourceHtml($path, $source['bytes'], $source['origin'], $snapshot->identity->cacheKey());
    }
}
