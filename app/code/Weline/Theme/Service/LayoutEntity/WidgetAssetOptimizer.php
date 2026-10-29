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

        // head 内所有 widget JS（合包或单文件）统一改声明加载标记：
        // 未合并的单文件不会走 publishBatch，这里兜底，保证 head 不直出业务脚本。
        foreach ($result as &$asset) {
            if (($asset['type'] ?? '') !== 'js' || (string)($asset['position'] ?? '') !== 'head') {
                continue;
            }
            $tag = (string)($asset['tag'] ?? '');
            if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $match) !== 1) {
                continue;
            }
            $url = \html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
            $asset['tag'] = '<script type="application/x-weline-theme-js" data-weline-theme-js="'
                . \htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" data-order="30"></script>';
        }
        unset($asset);

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
            // 2026-10-29 全局默认（marker: widget-css-never-blocking-v1）：
            // 部件 CSS 包一律非阻塞：这些是浮动层/客服/音乐等装饰性部件样式，首屏骨架与版心/hero
            // 几何已由 layout-critical + 内联 tokens 兜住；阻塞它们等于让首屏多等 1 个 RTT
            // （PIC 首页 PSI「渲染阻塞请求 160–450ms」即来自这类合并包）。
            $deferred = $type === 'css'
                || (($pending[0]['kind'] ?? '') === 'source'
                    && ($pending[0]['first_widget_index'] ?? 0) > $earlyCount);
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

    /**
     * Layout 合包（版心/栅格/浮动层/客服等几何关键 CSS）改为**内联**进 head。
     *
     * 2026-10-30 实测（marker: inline-layout-pack-v1）：该包约 10 KB(gzip)/60–80 KB(原文)，
     * 原本是 `<link rel=stylesheet>` 阻塞渲染，PSI「渲染阻塞请求」实测 450 ms —— 主要是
     * 一个额外 RTT（4G 150 ms）+ 排队。几何关键 CSS 必须阻塞，那就把它内联：语义等价、
     * 少一个请求，HTML 体积的增加会被 gzip/brotli 摊薄。
     *
     * 安全边界：只读已发布产物；读不到 / 空 / >96KB 一律回退原 `<link>`，行为不变。
     * 链接形态保持可识别：内联后仍带 data-weline-layout-pack 与 data-weline-widget-asset="layout"。
     */
    private function markLayoutPack(string $tag): string
    {
        // 2026-10-30 A/B 开关（marker: css-inline-toggles-v1）：`wls.performance.inline_layout_pack`
        // 置 false 时 layout 合包退回阻塞 <link>，用于实测「HTML 体积 vs 请求数」（默认 true = 内联）。
        if (!$this->inlineLayoutPackEnabled()) {
            return $tag;
        }
        // 注意：标签可能在更早阶段已带 data-weline-layout-pack（合包标记先于本方法），
        // 因此「已标记」也必须继续走内联分支；否则内联永不生效（2026-10-30 实测踩过）。
        if (str_contains($tag, 'data-weline-layout-pack=')) {
            $inlinedExisting = $this->tryInlineLayoutPack($tag);
            return $inlinedExisting ?? $tag;
        }
        $marked = preg_replace(
            '/^<link\b/i',
            '<link data-weline-layout-pack="1" data-weline-widget-asset="layout"',
            $tag
        ) ?? $tag;
        $inlined = $this->tryInlineLayoutPack($marked);
        if ($inlined !== null) {
            return $inlined;
        }

        return $marked;
    }

    /**
     * layout 合包是否内联（env `wls.performance.inline_layout_pack`，默认 true）。
     */
    private function inlineLayoutPackEnabled(): bool
    {
        if (!\defined('BP')) {
            return true;
        }
        try {
            $value = \Weline\Framework\App\Env::get('wls.performance.inline_layout_pack', true);
        } catch (\Throwable $e) {
            return true;
        }
        if (\is_bool($value)) {
            return $value;
        }
        $normalized = \strtolower(\trim((string)$value));

        return !\in_array($normalized, ['0', 'false', 'off', 'no'], true);
    }

    /**
     * 把已发布的 layout 合包读成 &lt;style&gt;；任一前提不满足返回 null（调用方回退 link）。
     */
    private function tryInlineLayoutPack(string $linkTag): ?string
    {
        if (!\preg_match('/\bhref=["\']([^"\']+)["\']/i', $linkTag, $m)) {
            return null;
        }
        $url = \html_entity_decode($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $path = (string)\parse_url($url, PHP_URL_PATH);
        if ($path === '' || !\str_starts_with($path, '/')) {
            return null;
        }
        if (!\defined('BP')) {
            return null;
        }
        $file = \rtrim(BP, '/\\') . '/pub' . $path;
        if (!\is_file($file)) {
            return null;
        }
        $raw = @\file_get_contents($file);
        if (!\is_string($raw) || $raw === '' || \strlen($raw) > 98304) {
            return null;
        }
        // 只内联纯 CSS，避免把 404 页 / HTML 错误页灌进 <style>。
        if (\stripos($raw, '<html') !== false || \stripos($raw, '<!doctype') !== false) {
            return null;
        }
        $attrs = ' data-weline-layout-pack="1" data-weline-widget-asset="layout"'
            . ' data-weline-inline-layout="1"';

        return '<style' . $attrs . '>' . $raw . '</style>';
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
