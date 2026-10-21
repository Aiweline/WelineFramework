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
     * Source CSS/JS: first N widgets → one blocking pack; remainder → one deferred pack.
     * N = {css|js}_merge_start_widget (default 6). Non-mergeable leaves keep file boundaries.
     *
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
            $earlyCount = max(1, (int)($options[$type . '_merge_start_widget'] ?? 6));
            // Late pack only: source after the first N widgets (deferred CSS via media=print).
            $deferred = ($pending[0]['kind'] ?? '') === 'source'
                && ($pending[0]['first_widget_index'] ?? 0) > $earlyCount;
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
            $earlyCount = max(1, (int)($options[$type . '_merge_start_widget'] ?? 6));
            $index = (int)($asset['first_widget_index'] ?? 0);
            $phase = $index > $earlyCount ? 'late' : 'early';
            $eligible = ($asset['kind'] ?? '') === 'source'
                && !empty($options[$type . '_merge'])
                && $this->publisher->canMerge($asset);
            // Early and late never share a batch — two packs max per position|type stream.
            $key = $eligible ? $asset['position'] . '|' . $type . '|' . $phase : null;
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
        $theme = $options['_theme'] ?? null;
        $theme = $theme instanceof \Weline\Theme\Model\WelineTheme ? $theme : null;
        $url = $this->publisher->publish($pending, $minify, $theme);
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
        if ($type === 'css') {
            $asset['tag'] = $this->stampMergedCssBasenames($asset['tag'], $pending);
        }
        $asset['tag'] = preg_replace('/\sdata-weline-module-source=["\'][^"\']*["\']/', '', $asset['tag']) ?? $asset['tag'];
        if ($type === 'js' && !preg_match('/\sdefer(?:\s|=|>|\/)/i', $asset['tag'])) {
            $asset['tag'] = preg_replace('/^<script\b/i', '<script defer', $asset['tag']) ?? $asset['tag'];
        }
        if ($deferredCss && $type === 'css') {
            $asset['tag'] = $this->applyDeferredCss($asset['tag'], $url);
        }
        if ($type === 'js' && (string)($asset['position'] ?? '') === 'head') {
            // head 内的 widget JS 改声明加载：运行时包（data-order=20）先于业务包（30），
            // 由 weline.js 在 DOM ready 后按序插入，不再占用 head 外链。
            $isRuntimePack = false;
            $publishedProbe = '';
            if (str_starts_with($url, '/')) {
                $publishedProbe = (string)@file_get_contents(BP . 'pub' . strtok($url, '?'));
            }
            if ($publishedProbe !== '') {
                $definesWidgetRuntime = str_contains($publishedProbe, 'WelineWidgetInstanceStyles')
                    || (str_contains($publishedProbe, 'WelineWidgetAssets') && !str_contains($publishedProbe, '.register('));
                if ($definesWidgetRuntime) {
                    $isRuntimePack = true;
                }
            }
            $asset['tag'] = '<script type="application/x-weline-theme-js" data-weline-theme-js="'
                . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" data-order="' . ($isRuntimePack ? '20' : '30') . '"></script>';
        }
        $asset['module_source'] = '';
        $asset['url'] = $url;

        return $asset;
    }

    /**
     * Stamp leaf basenames on the merged &lt;link&gt; so runtime JS can skip re-injecting
     * the same tiny sheets (href no longer contains the original filenames).
     *
     * @param list<array<string,mixed>> $pending
     */
    private function stampMergedCssBasenames(string $tag, array $pending): string
    {
        if (str_contains($tag, 'data-weline-merged-css=')) {
            return $tag;
        }
        $basenames = [];
        foreach ($pending as $item) {
            $moduleSource = \trim((string)($item['module_source'] ?? ''));
            if ($moduleSource !== '' && \str_contains($moduleSource, '::')) {
                $rel = \explode('::', $moduleSource, 2)[1];
                $base = \basename(\str_replace('\\', '/', $rel));
                if ($base !== '' && \preg_match('/\.css$/i', $base)) {
                    $basenames[$base] = true;
                }
            }
            $leafUrl = (string)($item['url'] ?? '');
            // Delimiter must not be # — character class includes # and would truncate the pattern.
            if ($leafUrl !== '' && \preg_match('~/([^/?#]+\.css)~i', $leafUrl, $m)) {
                $basenames[$m[1]] = true;
            }
        }
        if ($basenames === []) {
            return $tag;
        }
        $list = \htmlspecialchars(\implode(' ', \array_keys($basenames)), ENT_QUOTES, 'UTF-8');

        return \preg_replace('/^<link\b/i', '<link data-weline-merged-css="' . $list . '"', $tag) ?? $tag;
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
