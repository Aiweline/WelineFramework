<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Storefront;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityAssetCollector;
use Weline\Theme\Service\LayoutEntity\WidgetAssetArtifactPublisher;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutStorefrontHeadAssets;
use Weline\Framework\Runtime\RequestContext;
use Weline\Widget\Service\WidgetData;
use Weline\Widget\Service\WidgetRegistry;

/**
 * N3 小步：StorefrontWidgetRuntimeSchedule 的 Theme 侧 asset 预取协作。
 * 只走 {@see WidgetAssetArtifactPublisher::prefetchSources} → HotCache prefetchPolicy（协议 MGET）。
 */
final class StorefrontWidgetRuntimeAssetPrimer
{
    public function __construct(
        private readonly ?WidgetAssetArtifactPublisher $publisher = null,
        private readonly ?WidgetData $widgetData = null,
        private readonly ?WidgetRegistry $registry = null,
        private readonly ?ThemeLayoutEntityAssetCollector $collector = null,
    ) {
    }

    /**
     * @param list<array{type?:string,name?:string,code?:string,module?:string}> $specs
     * @return int 交给 prefetchSources 的去重 module_source 键数
     */
    public function prefetchForInlineSpecs(array $specs): int
    {
        $publisher = $this->publisher
            ?? ObjectManager::getInstance(WidgetAssetArtifactPublisher::class);
        if (!$publisher instanceof WidgetAssetArtifactPublisher) {
            return 0;
        }

        $assets = $this->assetsFromPageManifest();
        $assets = array_merge($assets, $this->assetsFromInlineSpecs($specs));
        if ($assets === []) {
            return 0;
        }

        $unique = [];
        foreach ($assets as $asset) {
            $ms = trim((string)($asset['module_source'] ?? ''));
            if ($ms === '' || isset($unique[$ms])) {
                continue;
            }
            $unique[$ms] = $asset;
        }
        $list = array_values($unique);
        if ($list === []) {
            return 0;
        }

        $publisher->prefetchSources($list);

        return \count($list);
    }

    /**
     * @param list<array{type?:string,name?:string,code?:string,module?:string}> $specs
     * @return list<array{module_source:string,tag:string,type:string}>
     */
    private function assetsFromInlineSpecs(array $specs): array
    {
        if ($specs === []) {
            return [];
        }

        $widgetData = $this->widgetData ?? ObjectManager::getInstance(WidgetData::class);
        $registry = $this->registry ?? ObjectManager::getInstance(WidgetRegistry::class);
        $collector = $this->collector
            ?? new ThemeLayoutEntityAssetCollector($registry instanceof WidgetRegistry ? $registry : null);

        $nodes = [];
        foreach ($specs as $spec) {
            if (!\is_array($spec)) {
                continue;
            }
            $type = trim((string)($spec['type'] ?? ''));
            $name = trim((string)($spec['name'] ?? ''));
            $code = trim((string)($spec['code'] ?? $name));
            if ($type === '' || $name === '') {
                continue;
            }
            $widget = null;
            if ($widgetData instanceof WidgetData) {
                try {
                    $widget = $widgetData->getWidget($type, $name);
                } catch (\Throwable) {
                    $widget = null;
                }
            }
            if (!\is_array($widget)) {
                $widget = [
                    'type' => $type,
                    'code' => $code,
                    'name' => $name,
                    'module' => (string)($spec['module'] ?? ''),
                ];
            }
            $meta = array_merge(\is_array($widget['config'] ?? null) ? $widget['config'] : [], $widget);
            $nodes[] = [
                'widget_type' => (string)($widget['type'] ?? $type),
                'widget_code' => (string)($widget['code'] ?? $code),
                'widget_module' => (string)($widget['module'] ?? ($spec['module'] ?? '')),
                'is_active' => true,
                'layout_source' => (string)($meta['layout_source'] ?? ''),
                'source' => (string)($meta['source'] ?? ''),
                'source_position' => (string)($meta['source_position'] ?? $meta['source-position'] ?? 'head'),
            ];
        }

        if ($nodes === []) {
            return [];
        }

        $manifest = $collector->collectFromNodes($nodes, false);

        return $this->manifestToAssets($manifest);
    }

    /**
     * 已记住的页级 / chrome 资源清单（若有）一并预热，覆盖固化页头资产热点。
     *
     * @return list<array{module_source:string,tag:string,type:string}>
     */
    private function assetsFromPageManifest(): array
    {
        $ptr = RequestContext::get(ThemeLayoutStorefrontHeadAssets::CTX_PTR);
        if (!\is_array($ptr)) {
            return [];
        }
        try {
            // 只读 sidecar 清单；不触发 head HTML 渲染。
            $configStore = ObjectManager::getInstance(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore::class
            );
            $page = [];
            if (($ptr['binding'] ?? null) instanceof \Weline\Theme\Service\LayoutEntity\EntityRenderBinding) {
                $page = $configStore->readBoundAssets($ptr['binding']);
            }
            if (!\is_array($page) || $page === []) {
                return [];
            }

            return $this->manifestToAssets($page);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array{module_source:string,tag:string,type:string}>
     */
    private function manifestToAssets(array $manifest): array
    {
        $assets = [];
        foreach (['layout_css', 'source_css'] as $key) {
            foreach (($manifest[$key] ?? []) as $path) {
                $path = trim((string)$path);
                if ($path === '') {
                    continue;
                }
                $assets[] = [
                    'module_source' => $path,
                    'tag' => '<link rel="stylesheet" href="#">',
                    'type' => 'css',
                ];
            }
        }
        foreach (['layout_js', 'source_js'] as $key) {
            foreach (($manifest[$key] ?? []) as $path) {
                $path = trim((string)$path);
                if ($path === '') {
                    continue;
                }
                $assets[] = [
                    'module_source' => $path,
                    'tag' => '<script src="#" defer></script>',
                    'type' => 'js',
                ];
            }
        }

        return $assets;
    }
}
