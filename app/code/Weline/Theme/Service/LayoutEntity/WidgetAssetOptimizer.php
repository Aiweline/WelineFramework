<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

/** Stable transformation of the already deduplicated, positioned asset sequence. */
final class WidgetAssetOptimizer
{
    public function __construct(private readonly WidgetAssetArtifactPublisher $publisher) {}

    public function transform(array $assets, array $options): array
    {
        $streams = [];
        foreach (array_values($assets) as $index => $asset) {
            $asset['_order'] = $index;
            $asset['area'] = ($options['_area'] ?? 'frontend') === 'backend' ? 'backend' : 'frontend';
            $type = str_starts_with(strtolower($asset['tag']), '<link') ? 'css' : 'js';
            $streams[$asset['position'] . '|' . $type][] = $asset;
        }
        $result = [];
        foreach ($streams as $stream) { array_push($result, ...$this->transformSequence($stream, $options)); }
        usort($result, static fn(array $a, array $b): int => $a['_order'] <=> $b['_order']);
        return $result;
    }

    private function transformSequence(array $assets, array $options): array
    {
        $output = [];
        $pending = [];
        $pendingKey = null;
        $flush = function () use (&$output, &$pending, &$pendingKey, $options): void {
            if ($pending === []) { return; }
            $type = $pending[0]['type'];
            $minify = !empty($options[$type . '_minify']);
            $url = count($pending) > 1 || $minify || (defined('PROD') && PROD) ? $this->publisher->publish($pending, $minify) : null;
            if ($url !== null) {
                $asset = $pending[0];
                $asset['tag'] = preg_replace_callback('/\b(src|href)=["\'][^"\']*["\']/i',
                    static fn(array $m): string => $m[1] . '="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"', $asset['tag']) ?? $asset['tag'];
                if (array_filter($pending, static fn(array $item): bool => str_contains($item['tag'], 'data-weline-product-card-css'))) {
                    $asset['tag'] = str_contains($asset['tag'], 'data-weline-product-card-css') ? $asset['tag']
                        : str_replace('<link ', '<link data-weline-product-card-css="1" ', $asset['tag']);
                }
                $asset['tag'] = preg_replace('/\sdata-weline-module-source=["\'][^"\']*["\']/', '', $asset['tag']) ?? $asset['tag'];
                $asset['module_source'] = '';
                $asset['url'] = $url;
                $output[] = $asset;
            } else {
                array_push($output, ...$pending);
            }
            $pending = [];
            $pendingKey = null;
        };
        foreach ($assets as $asset) {
            $asset['type'] = str_starts_with(strtolower($asset['tag']), '<link') ? 'css' : 'js';
            $type = $asset['type'];
            $eligible = $asset['kind'] === 'source' && !empty($options[$type . '_merge'])
                && ($asset['first_widget_index'] ?? 0) >= max(1, (int)($options[$type . '_merge_start_widget'] ?? 6))
                && $this->publisher->canMerge($asset);
            $key = $eligible ? $asset['position'] . '|' . $type : null;
            if (!$eligible || $key !== $pendingKey) { $flush(); }
            $pending[] = $asset;
            $pendingKey = $key;
            if (!$eligible) { $flush(); }
        }
        $flush();
        return $output;
    }
}
