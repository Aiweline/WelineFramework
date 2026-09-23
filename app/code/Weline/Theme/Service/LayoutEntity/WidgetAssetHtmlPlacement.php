<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

/** Moves only declared widget resources; ordinary page assets remain untouched. */
final class WidgetAssetHtmlPlacement
{
    public function inject(string $html, string $bakedAssets = ''): string
    {
        if (stripos($html, '</head>') === false || stripos($html, '</body>') === false) {
            return $html;
        }
        $html = preg_replace('~<(?:template|span)\b[^>]*data-weline-widget-assets=[^>]*>\s*</(?:template|span)>~i', '', $html) ?? $html;
        $assets = [];
        $rank = ['head' => 0, 'footer' => 1, 'body' => 2];
        $extract = static function (array $m) use (&$assets, $rank): string {
            $tag = $m[0];
            if (!preg_match('/\bdata-weline-widget-asset=["\'](layout|source)["\']/i', $tag, $kind)) { return $tag; }
            if (!preg_match('/\b(?:src|href)=["\']([^"\']+)["\']/i', $tag, $url)) { return $tag; }
            preg_match('/\bdata-weline-source-position=["\']([^"\']+)["\']/i', $tag, $position);
            $pos = $kind[1] === 'layout' ? 'head' : ($position[1] ?? 'head');
            $pos = $pos === 'end-body' ? 'body' : $pos;
            $pos = isset($rank[$pos]) ? $pos : 'head';
            $key = html_entity_decode($url[1], ENT_QUOTES, 'UTF-8');
            $old = $assets[$key] ?? null;
            $productCard = str_contains($tag, 'data-weline-product-card-css') || ($old !== null && str_contains($old['tag'], 'data-weline-product-card-css'));
            if ($old === null || ($kind[1] === 'layout' && $old['kind'] !== 'layout')
                || ($kind[1] === $old['kind'] && $rank[$pos] < $rank[$old['position']])) {
                $assets[$key] = ['tag' => $tag, 'kind' => $kind[1], 'position' => $pos];
            }
            // Preserve the canonical card marker used by the later FPC admission pass.
            if ($productCard && !str_contains($assets[$key]['tag'], 'data-weline-product-card-css')) {
                $assets[$key]['tag'] = preg_replace('/^<link\b/', '<link data-weline-product-card-css="1"', $assets[$key]['tag']) ?? $assets[$key]['tag'];
            }
            return '';
        };
        $pattern = '~<link\b[^>]*>|<script\b[^>]*>\s*</script\s*>~i';
        preg_replace_callback($pattern, $extract, $bakedAssets);
        $html = preg_replace_callback($pattern, $extract, $html) ?? $html;
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
