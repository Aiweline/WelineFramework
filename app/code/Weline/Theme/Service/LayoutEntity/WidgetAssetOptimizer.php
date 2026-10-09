<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

/** Stable transformation of the already deduplicated, positioned asset sequence. */
final class WidgetAssetOptimizer
{
    public function __construct(private readonly WidgetAssetArtifactPublisher $publisher) {}

    public function transform(array $assets, array $options): array
    {
        $this->publisher->prefetchSources($assets);
        $layoutCss = [];
        $streams = [];
        foreach (array_values($assets) as $index => $asset) {
            $asset['_order'] = $index;
            $asset['area'] = ($options['_area'] ?? 'frontend') === 'backend' ? 'backend' : 'frontend';
            $type = str_starts_with(strtolower($asset['tag']), '<link') ? 'css' : 'js';
            $asset['type'] = $type;
            if (($asset['kind'] ?? '') === 'layout' && $type === 'css') {
                $layoutCss[] = $asset;
                continue;
            }
            $streams[$asset['position'] . '|' . $type][] = $asset;
        }
        $result = [];
        if ($layoutCss !== []) {
            array_push($result, ...$this->transformLayoutCss($layoutCss, $options));
        }
        foreach ($streams as $stream) {
            array_push($result, ...$this->transformSequence($stream, $options));
        }
        usort($result, static fn(array $a, array $b): int => $a['_order'] <=> $b['_order']);

        return $result;
    }

    /**
     * Whole-page layout CSS → one pack when css_merge is on (ignores merge_start).
     *
     * @param list<array<string,mixed>> $assets
     * @return list<array<string,mixed>>
     */
    private function transformLayoutCss(array $assets, array $options): array
    {
        if (empty($options['css_merge'])) {
            return $assets;
        }
        $mergeable = [];
        $kept = [];
        foreach ($assets as $asset) {
            if ($this->publisher->canMerge($asset)) {
                $mergeable[] = $asset;
            } else {
                $kept[] = $asset;
            }
        }
        if ($mergeable === []) {
            return $assets;
        }
        $published = $this->publishBatch($mergeable, $options, false);
        if ($published === null) {
            return $assets;
        }
        $published['tag'] = $this->markLayoutPack($published['tag']);
        $published['kind'] = 'layout';
        $published['position'] = 'head';

        return array_merge([$published], $kept);
    }

    /**
     * @param list<array<string,mixed>> $assets
     * @return list<array<string,mixed>>
     */
    private function transformSequence(array $assets, array $options): array
    {
        $output = [];
        $pending = [];
        $pendingKey = null;
        $flush = function () use (&$output, &$pending, &$pendingKey, $options): void {
            if ($pending === []) {
                return;
            }
            $type = $pending[0]['type'];
            $deferred = ($pending[0]['kind'] ?? '') === 'source'
                && ($pending[0]['first_widget_index'] ?? 0) >= max(1, (int)($options[$type . '_merge_start_widget'] ?? 6));
            $published = $this->publishBatch($pending, $options, $deferred);
            if ($published !== null) {
                $output[] = $published;
            } else {
                array_push($output, ...$pending);
            }
            $pending = [];
            $pendingKey = null;
        };
        foreach ($assets as $asset) {
            $type = $asset['type'];
            $start = max(1, (int)($options[$type . '_merge_start_widget'] ?? 6));
            $eligible = ($asset['kind'] ?? '') === 'source'
                && !empty($options[$type . '_merge'])
                && ($asset['first_widget_index'] ?? 0) >= $start
                && $this->publisher->canMerge($asset);
            $key = $eligible ? $asset['position'] . '|' . $type : null;
            if (!$eligible || $key !== $pendingKey) {
                $flush();
            }
            $pending[] = $asset;
            $pendingKey = $key;
            if (!$eligible) {
                $flush();
            }
        }
        $flush();

        return $output;
    }

    /**
     * @param list<array<string,mixed>> $pending
     * @return array<string,mixed>|null
     */
    private function publishBatch(array $pending, array $options, bool $deferredCss): ?array
    {
        $type = $pending[0]['type'];
        $minify = !empty($options[$type . '_minify']);
        // Merge path already decided this batch should pack (layout single pack / late source /
        // multi-file). Always publish so DEV matches PROD tags (incl. deferred CSS rewrite).
        $url = $this->publisher->publish($pending, $minify);
        if ($url === null) {
            return null;
        }
        $asset = $pending[0];
        $asset['tag'] = preg_replace_callback(
            '/\b(src|href)=["\'][^"\']*["\']/i',
            static fn(array $m): string => $m[1] . '="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"',
            $asset['tag']
        ) ?? $asset['tag'];
        if (array_filter($pending, static fn(array $item): bool => str_contains($item['tag'], 'data-weline-product-card-css'))) {
            $asset['tag'] = str_contains($asset['tag'], 'data-weline-product-card-css')
                ? $asset['tag']
                : str_replace('<link ', '<link data-weline-product-card-css="1" ', $asset['tag']);
        }
        $asset['tag'] = preg_replace('/\sdata-weline-module-source=["\'][^"\']*["\']/', '', $asset['tag']) ?? $asset['tag'];
        if ($type === 'js' && !preg_match('/\sdefer(?:\s|=|>|\/)/i', $asset['tag'])) {
            $asset['tag'] = preg_replace('/^<script\b/i', '<script defer', $asset['tag']) ?? $asset['tag'];
        }
        if ($deferredCss && $type === 'css') {
            $asset['tag'] = $this->applyDeferredCss($asset['tag'], $url);
        }
        $asset['module_source'] = '';
        $asset['url'] = $url;

        return $asset;
    }

    private function markLayoutPack(string $tag): string
    {
        if (str_contains($tag, 'data-weline-layout-pack=')) {
            return $tag;
        }

        return preg_replace(
            '/^<link\b/i',
            '<link data-weline-layout-pack="1" data-weline-widget-asset="layout"',
            $tag
        ) ?? $tag;
    }

    private function applyDeferredCss(string $tag, string $url): string
    {
        $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tag = preg_replace('/\smedia=["\'][^"\']*["\']/i', '', $tag) ?? $tag;
        if (!preg_match('/\sonload=/i', $tag)) {
            $tag = preg_replace(
                '/^<link\b/i',
                '<link media="print" onload="this.media=\'all\'"',
                $tag
            ) ?? $tag;
        }
        $noscript = '<noscript><link rel="stylesheet" href="' . $safe . '"/></noscript>';

        return $tag . $noscript;
    }
}
