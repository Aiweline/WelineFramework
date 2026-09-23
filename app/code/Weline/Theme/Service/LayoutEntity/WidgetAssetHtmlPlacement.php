<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

/** Moves only declared widget resources; ordinary page assets remain untouched. */
final class WidgetAssetHtmlPlacement
{
    public function __construct(private readonly ?WidgetAssetOptimizer $optimizer = null) {}

    /** A preview is its own document for ordering, then returns only its fragment. */
    public function optimizeFragment(string $html, array $options): string
    {
        $document = $this->inject('<html><head></head><body>' . $html . '</body></html>', '', $options);
        return str_replace(['<html>', '</html>', '<head>', '</head>', '<body>', '</body>'], '', $document);
    }

    public function inject(string $html, string $bakedAssets = '', array $options = []): string
    {
        if (stripos($html, '</head>') === false || stripos($html, '</body>') === false) {
            return $html;
        }
        $html = preg_replace('~<(?:template|span)\b[^>]*data-weline-widget-assets=[^>]*>\s*</(?:template|span)>~i', '', $html) ?? $html;
        $assets = [];
        $widgetIndex = 0;
        $stack = [];
        $rank = ['head' => 0, 'footer' => 1, 'body' => 2];
        $extract = static function (array $m) use (&$assets, $rank, &$widgetIndex, &$stack): string {
            $tag = $m[0];
            if ($tag === '<!--weline-widget:start-->') { $stack[] = ++$widgetIndex; return ''; }
            if ($tag === '<!--weline-widget:end-->') { array_pop($stack); return ''; }
            if (!preg_match('/\bdata-weline-widget-asset=["\'](layout|source)["\']/i', $tag, $kind)) { return $tag; }
            if (!preg_match('/\b(?:src|href)=["\']([^"\']+)["\']/i', $tag, $url)) { return $tag; }
            preg_match('/\bdata-weline-source-position=["\']([^"\']+)["\']/i', $tag, $position);
            $pos = $kind[1] === 'layout' ? 'head' : ($position[1] ?? 'head');
            $pos = $pos === 'end-body' ? 'body' : $pos;
            $pos = isset($rank[$pos]) ? $pos : 'head';
            $key = html_entity_decode($url[1], ENT_QUOTES, 'UTF-8');
            $old = $assets[$key] ?? null;
            $index = $stack === [] ? 0 : end($stack);
            $firstIndex = $old['first_widget_index'] ?? 0;
            if ($index > 0 && ($firstIndex === 0 || $index < $firstIndex)) { $firstIndex = $index; }
            preg_match('/data-weline-module-source=["\']([^"\']+)["\']/', $tag, $moduleSource);
            $productCard = str_contains($tag, 'data-weline-product-card-css') || ($old !== null && str_contains($old['tag'], 'data-weline-product-card-css'));
            if ($old === null || ($kind[1] === 'layout' && $old['kind'] !== 'layout')
                || ($kind[1] === $old['kind'] && $rank[$pos] < $rank[$old['position']])) {
                $assets[$key] = ['tag' => $tag, 'url' => $key, 'kind' => $kind[1], 'position' => $pos, 'module_source' => html_entity_decode($moduleSource[1] ?? '', ENT_QUOTES, 'UTF-8')];
            }
            $assets[$key]['first_widget_index'] = $firstIndex;
            if (($assets[$key]['module_source'] ?? '') === '' && isset($moduleSource[1])) { $assets[$key]['module_source'] = html_entity_decode($moduleSource[1], ENT_QUOTES, 'UTF-8'); }
            // Preserve the canonical card marker used by the later FPC admission pass.
            if ($productCard && !str_contains($assets[$key]['tag'], 'data-weline-product-card-css')) {
                $assets[$key]['tag'] = preg_replace('/^<link\b/', '<link data-weline-product-card-css="1"', $assets[$key]['tag']) ?? $assets[$key]['tag'];
            }
            return '';
        };
        $pattern = '~<!--weline-widget:(?:start|end)-->|<link\b[^>]*>|<script\b[^>]*>\s*</script\s*>~i';
        preg_replace_callback($pattern, $extract, $bakedAssets);
        $html = preg_replace_callback($pattern, $extract, $html) ?? $html;
        $ordered = [];
        foreach (['layout', 'source'] as $kind) {
            foreach ($assets as $asset) { if ($asset['kind'] === $kind) { $ordered[] = $asset; } }
        }
        if ($options !== []) {
            $optimizer = $this->optimizer ?? \Weline\Framework\Manager\ObjectManager::getInstance(WidgetAssetOptimizer::class);
            $ordered = $optimizer->transform($ordered, $options);
        }
        $assets = $ordered;
        $groups = ['head' => [], 'footer' => [], 'body' => []];
        // Stable partition puts layout resources ahead of normal head resources.
        foreach (['layout', 'source'] as $kind) {
            foreach ($assets as $asset) {
                if ($asset['kind'] === $kind) { $groups[$asset['position']][] = $asset['tag']; }
            }
        }
        if (stripos($html, '</footer>') === false) {
            $groups['body'] = array_merge($groups['footer'], $groups['body']);
            $groups['footer'] = [];
        }
        foreach ($groups as $position => $tags) {
            if ($tags === []) { continue; }
            $needle = '</' . $position . '>';
            $offset = strripos($html, $needle);
            if ($offset !== false) {
                $html = substr_replace($html, implode("\n", $tags) . "\n", $offset, 0);
            }
        }
        return $html;
    }
}
