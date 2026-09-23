<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/** Resource declarations travel with the rendered fragment, including cached fragments. */
final class WidgetAssetRenderer
{
    public function render(array $widget, array $config = [], string $templatePath = ''): string
    {
        $meta = array_merge(is_array($widget['config'] ?? null) ? $widget['config'] : [], $widget);
        $selectedAssets = [];
        $path = $templatePath ?: (string)($widget['template'] ?? $widget['template_path'] ?? '');
        if ($path !== '') {
            try {
                $file = is_file($path) ? $path : (ObjectManager::getInstance(Template::class)->convertFetchFileName($path)[1] ?? '');
                if (is_string($file) && is_file($file)) {
                    // Read the selected template, so design overrides keep their own dependencies.
                    $head = (string)file_get_contents($file, false, null, 0, 16384);
                    foreach (['layout_source', 'source', 'source_position'] as $key) {
                        $pattern = $key === 'source_position' ? 'source[_-](?:postion|position)' : $key;
                        if (preg_match('/@widget\.' . $pattern . '\s*\{([^}]+)\}/', $head, $m) === 1) {
                            $meta[$key] = trim($m[1]);
                            $selectedAssets[$key] = $meta[$key];
                        }
                    }
                }
            } catch (\Throwable) {
                // Registry declarations remain usable if a Block has no template.
            }
        }
        $node = [
            'widget_type' => $widget['type'] ?? '',
            'widget_code' => $widget['code'] ?? '',
            'layout_source' => implode(',', array_filter([$selectedAssets['layout_source'] ?? '', $config['_layout_source'] ?? $meta['layout_source'] ?? ''])),
            'source' => implode(',', array_filter([$selectedAssets['source'] ?? '', $config['_source'] ?? $meta['source'] ?? ''])),
            'source_position' => $config['_source_position'] ?? $meta['source-postion'] ?? $meta['source-position'] ?? $meta['source_position'] ?? 'head',
        ];
        $collector = new ThemeLayoutEntityAssetCollector(null);
        $html = $collector->emitHtml($collector->collectFromNodes([$node]));
        return $html . $this->descriptor($html);
    }
    public function wrap(string $html, string $assets): string
    {
        return '<!--weline-widget:start-->' . $assets . $html . '<!--weline-widget:end-->';
    }

    public function descriptor(string $html): string
    {
        $items = [];
        preg_match_all('~<link\b[^>]*>|<script\b[^>]*>\s*</script\s*>~i', $html, $tags);
        foreach ($tags[0] as $tag) {
            if (!preg_match('/\b(?:src|href)=["\']([^"\']+)["\']/i', $tag, $url)) { continue; }
            preg_match('/data-weline-source-position=["\']([^"\']+)["\']/', $tag, $position);
            $items[] = ['url' => html_entity_decode($url[1], ENT_QUOTES, 'UTF-8'), 'type' => str_starts_with($tag, '<link') ? 'css' : 'js', 'position' => $position[1] ?? 'head'];
        }
        return $items === [] ? '' : '<span hidden data-weline-widget-assets="' . htmlspecialchars(json_encode($items, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '"></span>';
    }
}
