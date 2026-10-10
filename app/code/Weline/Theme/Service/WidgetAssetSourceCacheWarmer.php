<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\Contract\CacheWarmerInterface;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityAssetCollector;
use Weline\Theme\Service\LayoutEntity\WidgetAssetArtifactPublisher;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Widget\Service\WidgetRegistry;

/**
 * Seeds theme.widget_asset_source HotCache after cache:clear.
 *
 * Policy is global / lang-invariant, but shared L2 still requires a frozen
 * storefront cache key context (request-fence cannot publish). Discover
 * registry layout-source/source paths, freeze default channel identity, then
 * prime via WidgetAssetArtifactPublisher::prefetchSources.
 */
final class WidgetAssetSourceCacheWarmer implements CacheWarmerInterface
{
    public function getName(): string
    {
        return 'theme.widget_asset_sources';
    }

    public function getTargetPool(): string
    {
        return StorefrontThemeCacheCoordinator::LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL;
    }

    public function getPriority(): int
    {
        return 250;
    }

    public function canWarm(): bool
    {
        return \class_exists(WidgetAssetArtifactPublisher::class);
    }

    public function warm(): array
    {
        $hadRequest = RequestContext::isInitialized();
        if (!$hadRequest) {
            try {
                RequestContext::init();
            } catch (\Throwable $e) {
                return [
                    'warmed' => 0,
                    'skipped' => 1,
                    'message' => 'request_init_fail:' . $e->getMessage(),
                ];
            }
        }

        $primed = 0;
        $skipped = 0;
        $notes = [];

        try {
            $this->installDefaultChannelIdentity();
            $resolver = ObjectManager::getInstance(StorefrontCacheKeyContextResolver::class);
            if ($resolver instanceof StorefrontCacheKeyContextResolver) {
                $ctx = $resolver->freezeCurrent();
                if (!$ctx->cacheable) {
                    return [
                        'warmed' => 0,
                        'skipped' => 1,
                        'message' => 'freeze_not_cacheable:' . ($ctx->failureCode ?: 'unknown'),
                    ];
                }
            }

            $assets = $this->discoverRegistryAssets();
            $notes[] = 'assets=' . \count($assets);
            if ($assets === []) {
                return [
                    'warmed' => 0,
                    'skipped' => 1,
                    'message' => 'no_registry_assets',
                ];
            }

            /** @var WidgetAssetArtifactPublisher $publisher */
            $publisher = ObjectManager::getInstance(WidgetAssetArtifactPublisher::class);
            $primed = $publisher->prefetchSources($assets);
            $notes[] = 'primed=' . $primed;
        } catch (\Throwable $e) {
            ++$skipped;
            $notes[] = 'error:' . $e->getMessage();
        } finally {
            try {
                RequestContext::resetWelineVars();
            } catch (\Throwable) {
            }
        }

        return [
            'warmed' => \max(0, $primed),
            'skipped' => $skipped,
            'message' => \implode(';', $notes) ?: 'ok',
        ];
    }

    private function installDefaultChannelIdentity(): void
    {
        $storeMode = Store::MODE_NORMAL;
        $identity = ScopeIdentity::channel(
            Website::ID_DEFAULT,
            Website::CODE_DEFAULT,
            Store::CODE_DEFAULT,
            SalesChannel::CODE_DEFAULT,
            $storeMode,
        );
        RequestContext::setWelineWebsiteId(Website::ID_DEFAULT);
        RequestContext::setWelineWebsiteCode(Website::CODE_DEFAULT);
        RequestContext::setWelineStoreId(0);
        RequestContext::setWelineStoreCode(Store::CODE_DEFAULT);
        RequestContext::setWelineStoreMode($storeMode);
        RequestContext::setWelineChannelId(0);
        RequestContext::setWelineChannelCode(SalesChannel::CODE_DEFAULT);
        RequestContext::installScopeIdentity($identity);
    }

    /**
     * @return list<array{module_source:string,tag:string,type:string}>
     */
    private function discoverRegistryAssets(): array
    {
        try {
            /** @var WidgetRegistry $registry */
            $registry = ObjectManager::getInstance(WidgetRegistry::class);
            $collector = new ThemeLayoutEntityAssetCollector(
                $registry instanceof WidgetRegistry ? $registry : null,
            );
        } catch (\Throwable) {
            return [];
        }

        $nodes = [];
        $tree = [];
        try {
            // generated/widgets.php shape: type => code => meta
            $tree = $registry->getRegistry(false);
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($tree)) {
            return [];
        }

        foreach ($tree as $type => $widgets) {
            if (!\is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $code => $meta) {
                if (!\is_array($meta)) {
                    continue;
                }
                $config = \is_array($meta['config'] ?? null) ? $meta['config'] : [];
                $layout = \trim((string)($meta['layout_source'] ?? $config['_layout_source'] ?? $config['layout_source'] ?? ''));
                $source = \trim((string)($meta['source'] ?? $config['_source'] ?? $config['source'] ?? ''));
                if ($layout === '' && $source === '') {
                    continue;
                }
                $nodes[] = [
                    'widget_type' => (string)($meta['type'] ?? $type),
                    'widget_code' => (string)($meta['code'] ?? $code),
                    'widget_module' => (string)($meta['module'] ?? ''),
                    'is_active' => true,
                    'layout_source' => $layout,
                    'source' => $source,
                    'source_position' => (string)($meta['source_position'] ?? $config['_source_position'] ?? 'head'),
                ];
            }
        }

        if ($nodes === []) {
            return [];
        }

        $manifest = $collector->collectFromNodes($nodes, false);
        $assets = [];
        foreach (['layout_css', 'source_css'] as $key) {
            foreach (($manifest[$key] ?? []) as $path) {
                $path = \trim((string)$path);
                if ($path === '' || !\str_contains($path, '::')) {
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
                $path = \trim((string)$path);
                if ($path === '' || !\str_contains($path, '::')) {
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
