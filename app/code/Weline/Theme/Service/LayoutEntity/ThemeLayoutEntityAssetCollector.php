<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;
use Weline\Widget\Service\WidgetRegistry;

/**
 * Bake-time collector for w:widget layout-source / source (and @widget.* registry defaults).
 */
final class ThemeLayoutEntityAssetCollector
{
    public const BUCKET_LAYOUT = 'layout';
    public const BUCKET_SOURCE = 'source';

    /** @var list<string> */
    private const LAYOUT_TYPES = ['header', 'footer', 'navigation', 'nav', 'chrome'];

    /** @var list<string> */
    private const LAYOUT_CODE_ALLOWLIST = [
        'header', 'footer', 'default-header', 'default-footer',
        'header-container', 'footer-container', 'category-menu',
        'mini-cart-icon', 'account', 'language-switcher', 'currency-switcher',
    ];

    public function __construct(
        private readonly ?WidgetRegistry $registry,
    ) {
    }

    /**
     * @param array<string|int, mixed> $nodes
     * @return array{
     *   layout_css: list<string>,
     *   layout_js: list<string>,
     *   source_css: list<string>,
     *   source_js: list<string>,
     *   fp: string,
     *   node_uids: list<string>
     * }
     */
    public function collectFromNodes(array $nodes, bool $enforceLayoutGate = true): array
    {
        $layoutCss = [];
        $layoutJs = [];
        $sourceCss = [];
        $sourceJs = [];
        $uids = [];
        $seen = [];
        $layoutSeen = [];
        $positions = [];

        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            if (\array_key_exists('is_active', $node) && empty($node['is_active'])) {
                continue;
            }
            $uid = \strtolower(\trim((string)($node['node_uid'] ?? '')));
            if ($uid !== '') {
                $uids[] = $uid;
            }

            $module = \trim((string)($node['widget_module'] ?? ''));
            $code = \trim((string)($node['widget_code'] ?? ''));
            $type = \strtolower(\trim((string)($node['widget_type'] ?? '')));

            $layoutRaw = $this->firstNonEmptyString(
                $node['layout_source'] ?? null,
                $node['layout-source'] ?? null,
                \is_array($node['config'] ?? null) ? ($node['config']['_layout_source'] ?? null) : null,
                $this->registryMeta($module, $code, 'layout_source'),
            );
            $sourceRaw = $this->firstNonEmptyString(
                $node['source'] ?? null,
                \is_array($node['config'] ?? null) ? ($node['config']['_source'] ?? null) : null,
                $this->registryMeta($module, $code, 'source'),
            );

            if ($layoutRaw !== '') {
                if ($enforceLayoutGate && !$this->allowsLayoutSource($type, $code)) {
                    // Skip illegal layout-source rather than baking poison into head.
                    $layoutRaw = '';
                }
                foreach ($this->splitPaths($layoutRaw) as $path) {
                    $this->bucketPath($path, self::BUCKET_LAYOUT, $layoutCss, $layoutJs, $layoutSeen);
                }
            }
            $position = $this->normalizePosition($this->firstNonEmptyString(
                $node['source-postion'] ?? null, $node['source-position'] ?? null, $node['source_position'] ?? null,
                $node['config']['_source_position'] ?? null, $this->registryMeta($module, $code, 'source_position'),
            ));
            if ($sourceRaw !== '') {
                foreach ($this->splitPaths($sourceRaw) as $path) {
                    $this->bucketPath($path, self::BUCKET_SOURCE, $sourceCss, $sourceJs, $seen);
                    $rank = ['head' => 0, 'footer' => 1, 'body' => 2];
                    if (!isset($positions[$path]) || $rank[$position] < $rank[$positions[$path]]) {
                        $positions[$path] = $position;
                    }
                }
            }
        }

        $sourceCss = array_values(array_filter($sourceCss, static fn(string $p): bool => !isset($layoutSeen[strtolower($p)])));
        $sourceJs = array_values(array_filter($sourceJs, static fn(string $p): bool => !isset($layoutSeen[strtolower($p)])));
        $positions = array_intersect_key($positions, array_flip(array_merge($sourceCss, $sourceJs)));
        $manifest = [
            'layout_css' => \array_values($layoutCss),
            'layout_js' => \array_values($layoutJs),
            'source_css' => \array_values($sourceCss),
            'source_js' => \array_values($sourceJs),
            'source_positions' => $positions,
            'node_uids' => \array_values(\array_unique($uids)),
            'fp' => '',
        ];
        $manifest['fp'] = \hash('sha256', \json_encode([
            $manifest['layout_css'],
            $manifest['layout_js'],
            $manifest['source_css'],
            $manifest['source_js'],
            $manifest['source_positions'],
        ], JSON_UNESCAPED_SLASHES) ?: '');

        return $manifest;
    }

    /**
     * @param array{
     *   layout_css?: list<string>,
     *   layout_js?: list<string>,
     *   source_css?: list<string>,
     *   source_js?: list<string>,
     *   fp?: string
     * } $manifest
     */
    public function emitHtml(array $manifest, ?string $position = null): string
    {
        $chunks = [];
        $fp = \trim((string)($manifest['fp'] ?? ''));
        if ($fp !== '') {
            $chunks[] = '<!-- data-weline-widget-assets-fp="' . \htmlspecialchars($fp, ENT_QUOTES, 'UTF-8') . '" -->';
        }
        foreach (['layout_css', 'layout_js', 'source_css', 'source_js'] as $key) {
            foreach (($manifest[$key] ?? []) as $path) {
                $layout = str_starts_with($key, 'layout');
                $assetPosition = $layout ? 'head' : ($manifest['source_positions'][$path] ?? 'head');
                if ($position !== null && $assetPosition !== $this->normalizePosition($position)) { continue; }
                $url = $this->resolveStaticUrl((string)$path);
                if ($url === '') { continue; }
                $attributes = ' data-weline-widget-asset="' . ($layout ? 'layout' : 'source')
                    . '" data-weline-source-position="' . $assetPosition . '" data-weline-module-source="' . htmlspecialchars((string)$path, ENT_QUOTES, 'UTF-8') . '"';
                $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                $chunks[] = str_ends_with($key, '_css')
                    ? '<link rel="stylesheet" href="' . $url . '"' . $attributes . '>'
                    : '<script src="' . $url . '" defer' . $attributes . '></script>';
            }
        }

        return $chunks === [] ? '' : \implode("\n", $chunks) . "\n";
    }

    public static function normalizePosition(string $position): string
    {
        $position = strtolower(trim($position));
        return in_array($position, ['body', 'end-body'], true) ? 'body' : ($position === 'footer' ? 'footer' : 'head');
    }

    public function allowsLayoutSource(string $widgetType, string $widgetCode): bool
    {
        $type = \strtolower(\trim($widgetType));
        $code = \strtolower(\trim($widgetCode));
        if ($type !== '' && \in_array($type, self::LAYOUT_TYPES, true)) {
            return true;
        }
        if ($code !== '' && \in_array($code, self::LAYOUT_CODE_ALLOWLIST, true)) {
            return true;
        }
        foreach (self::LAYOUT_CODE_ALLOWLIST as $prefix) {
            if ($code !== '' && \str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chrome payload can be truncated (e.g. only Visitor pixel). Still bake layout CSS/JS
     * declared on registry chrome/header/footer widgets so every storefront page head is complete.
     *
     * @param array<string|int, mixed> $nodes
     * @return array<string|int, mixed>
     */
    public function withChromeRegistryBaseline(array $nodes): array
    {
        if ($this->registry === null) {
            return $nodes;
        }
        $seen = [];
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            $code = \strtolower(\trim((string)($node['widget_code'] ?? '')));
            if ($code !== '') {
                $seen[$code] = true;
            }
        }
        $extra = [];
        $all = $this->registry->getRegistry();
        foreach ($all as $type => $bucket) {
            if (!\is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $entryCode => $entry) {
                if (!\is_array($entry)) {
                    continue;
                }
                $code = \strtolower(\trim((string)($entry['code'] ?? $entryCode)));
                $widgetType = \strtolower(\trim((string)($entry['type'] ?? $type)));
                if ($code === '' || isset($seen[$code])) {
                    continue;
                }
                if (!$this->allowsLayoutSource($widgetType, $code)) {
                    continue;
                }
                $layout = $this->firstNonEmptyString(
                    $entry['layout_source'] ?? null,
                    \is_array($entry['config'] ?? null) ? ($entry['config']['layout_source'] ?? null) : null,
                );
                $source = $this->firstNonEmptyString(
                    $entry['source'] ?? null,
                    \is_array($entry['config'] ?? null) ? ($entry['config']['source'] ?? null) : null,
                );
                if ($layout === '' && $source === '') {
                    continue;
                }
                $seen[$code] = true;
                $extra[] = [
                    'node_uid' => \md5($widgetType . '|' . $code),
                    'widget_module' => (string)($entry['module'] ?? ''),
                    'widget_code' => (string)($entry['code'] ?? $code),
                    'widget_type' => (string)($entry['type'] ?? $widgetType),
                    'is_active' => true,
                    'source_position' => $entry['source_position'] ?? $entry['config']['source_position'] ?? 'head',
                    'layout_source' => $layout,
                    'source' => $source,
                ];
            }
        }

        return $extra === [] ? $nodes : \array_merge($nodes, $extra);
    }

    /**
     * @return list<string>
     */
    public function splitPaths(string $raw): array
    {
        $parts = \preg_split('/\s*,\s*/', \trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = \trim((string)$part);
            if ($part === '') {
                continue;
            }
            // Normalize Module:path → Module::path
            if (\preg_match('/^([A-Za-z0-9_]+):(?!:)(.+)$/', $part, $m) === 1) {
                $part = $m[1] . '::' . \ltrim($m[2], '/');
            }
            $out[] = $part;
        }

        return $out;
    }

    /**
     * @param array<string, true> $seen
     * @param list<string> $css
     * @param list<string> $js
     */
    private function bucketPath(
        string $path,
        string $bucket,
        array &$css,
        array &$js,
        array &$seen,
    ): void {
        $key = \strtolower($path);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $lower = \strtolower($path);
        if (\str_ends_with($lower, '.css')) {
            $css[] = $path;
        } elseif (\str_ends_with($lower, '.js')) {
            $js[] = $path;
        }
    }

    private function resolveStaticUrl(string $modulePath): string
    {
        $modulePath = \trim($modulePath);
        if ($modulePath === '' || !\str_contains($modulePath, '::')) {
            return '';
        }
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);
            $url = (string)$template->fetchTagSource(DataInterface::dir_type_STATICS, $modulePath);

            return \trim($url);
        } catch (\Throwable) {
            return '';
        }
    }

    private function registryMeta(string $module, string $code, string $key): ?string
    {
        if ($this->registry === null || $code === '') {
            return null;
        }
        $all = $this->registry->getRegistry();
        foreach ($all as $typeBucket) {
            if (!\is_array($typeBucket)) {
                continue;
            }
            // Flat entry (defensive) or type => code => entry
            if (isset($typeBucket['module'], $typeBucket['code'])) {
                $entryModule = (string)$typeBucket['module'];
                $entryCode = (string)$typeBucket['code'];
                if ($entryCode !== $code) {
                    continue;
                }
                if ($module !== '' && $entryModule !== $module) {
                    continue;
                }
                $val = $typeBucket[$key] ?? null;
                if (!\is_string($val) || \trim($val) === '') {
                    $nested = \is_array($typeBucket['config'] ?? null) ? ($typeBucket['config'][$key] ?? null) : null;
                    $val = \is_string($nested) ? $nested : null;
                }
                if (\is_string($val) && \trim($val) !== '') {
                    return \trim($val);
                }
                continue;
            }
            foreach ($typeBucket as $entryCode => $entry) {
                if (!\is_array($entry)) {
                    continue;
                }
                $entryModule = (string)($entry['module'] ?? '');
                $entryCodeName = (string)($entry['code'] ?? $entryCode);
                if ($entryCodeName !== $code) {
                    continue;
                }
                if ($module !== '' && $entryModule !== $module) {
                    continue;
                }
                $val = $entry[$key] ?? null;
                if (!\is_string($val) || \trim($val) === '') {
                    $nested = \is_array($entry['config'] ?? null) ? ($entry['config'][$key] ?? null) : null;
                    $val = \is_string($nested) ? $nested : null;
                }
                if (\is_string($val) && \trim($val) !== '') {
                    return \trim($val);
                }
            }
        }

        return null;
    }

    private function firstNonEmptyString(mixed ...$candidates): string
    {
        foreach ($candidates as $c) {
            if (\is_string($c) && \trim($c) !== '') {
                return \trim($c);
            }
        }

        return '';
    }
}
