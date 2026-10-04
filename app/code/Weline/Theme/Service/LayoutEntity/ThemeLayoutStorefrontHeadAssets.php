<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\ThemeScopeVersionService;

/**
 * Legacy head adapter. Runtime manifest reads are retired; explicit asset
 * merge utilities remain for callers supplying their own resources.
 */
final class ThemeLayoutStorefrontHeadAssets
{
    public const CTX_PTR = 'theme.layout_entity.page_assets_ptr.v1';

    public function __construct(
        private readonly ThemeLayoutEntityConfigStore $configStore,
        private readonly ThemeLayoutEntityAssetCollector $collector,
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ?ThemeScopeVersionService $scopeVersions = null,
    ) {
    }

    /**
     * @param array{theme_id:int,scope:string,identity_key:string,structure_or_release?:string,binding?:?EntityRenderBinding} $ptr
     */
    public static function rememberPointer(array $ptr): void
    {
        RequestContext::set(self::CTX_PTR, $ptr);
    }

    /**
     * @deprecated Sidecar asset manifests are retired. Generated PHTML and its
     * component templates register resources through the normal template flow.
     */
    public function renderHtmlForCurrentRequest(): string
    {
        return '';
    }

    /**
     * @param array<string, mixed> $chrome
     * @param array<string, mixed> $page
     */
    public function mergeAndEmit(array $chrome, array $page): string
    {
        return $this->collector->emitHtml($this->mergeManifests($chrome, $page));
    }

    public function mergeManifests(array $chrome, array $page): array
    {
        $merged = [
            'layout_css' => $this->uniqueMerge($chrome['layout_css'] ?? [], $page['layout_css'] ?? []),
            'layout_js' => $this->uniqueMerge($chrome['layout_js'] ?? [], $page['layout_js'] ?? []),
            'source_css' => $this->uniqueMerge($chrome['source_css'] ?? [], $page['source_css'] ?? []),
            'source_js' => $this->uniqueMerge($chrome['source_js'] ?? [], $page['source_js'] ?? []),
            'source_positions' => $this->mergePositions($this->positionsForManifest($chrome), $this->positionsForManifest($page)),
        ];
        $layoutSeen = [];
        foreach (\array_merge($merged['layout_css'], $merged['layout_js']) as $p) {
            $layoutSeen[\strtolower((string)$p)] = true;
        }
        $merged['source_css'] = \array_values(\array_filter(
            $merged['source_css'],
            static fn(string $p): bool => !isset($layoutSeen[\strtolower($p)]),
        ));
        $merged['source_js'] = \array_values(\array_filter(
            $merged['source_js'],
            static fn(string $p): bool => !isset($layoutSeen[\strtolower($p)]),
        ));
        $merged['fp'] = \hash('sha256', \json_encode([
            $merged['layout_css'],
            $merged['layout_js'],
            $merged['source_css'],
            $merged['source_js'],
        ], JSON_UNESCAPED_SLASHES) ?: '');

        return $merged;
    }

    private function positionsForManifest(array $manifest): array
    {
        $positions = [];
        foreach (array_merge($manifest['source_css'] ?? [], $manifest['source_js'] ?? []) as $path) {
            $positions[$path] = $this->collector->normalizePosition((string)($manifest['source_positions'][$path] ?? 'head'));
        }

        return $positions;
    }

    private function mergePositions(array $a, array $b): array
    {
        $rank = ['head' => 0, 'footer' => 1, 'body' => 2];
        foreach ($b as $path => $position) {
            $position = $this->collector->normalizePosition((string)$position);
            if (!isset($a[$path]) || $rank[$position] < $rank[$this->collector->normalizePosition((string)$a[$path])]) {
                $a[$path] = $position;
            }
        }

        return $a;
    }

    private function uniqueMerge(array $a, array $b): array
    {
        $out = [];
        $seen = [];
        foreach (\array_merge($a, $b) as $item) {
            $s = \trim((string)$item);
            if ($s === '') {
                continue;
            }
            $k = \strtolower($s);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $s;
        }

        return $out;
    }
}
