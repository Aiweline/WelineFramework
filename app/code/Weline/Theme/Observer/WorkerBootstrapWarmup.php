<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\QueryProviderRegistry;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePageTypeResolver;

class WorkerBootstrapWarmup implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (!Runtime::isPersistent()) {
            return;
        }

        $warmed = 0;
        $warmed += $this->tryWarm(function (): void {
            $this->warmCoreServices();
        });
        SchedulerSystem::yield();
        $warmed += $this->tryWarm(function (): void {
            $this->warmFrontendThemeData();
        });
        SchedulerSystem::yield();
        $warmed += $this->tryWarm(function (): void {
            $this->warmHotTemplateFiles();
        });
        SchedulerSystem::yield();
        $warmed += $this->tryWarm(function (): void {
            $this->warmHotStaticFiles();
        });
        SchedulerSystem::yield();
        $warmed += $this->tryWarm(function (): void {
            $this->warmHotHookRegistries();
        });
        SchedulerSystem::yield();
        $warmed += $this->tryWarm(function (): void {
            $this->warmRoutingCaches();
        });
        SchedulerSystem::yield();
        $warmed += $this->tryWarm(function (): void {
            $this->warmFpcFastPathPayloads();
        });

        if ($warmed > 0 && \function_exists('w_log_info')) {
            \w_log_info('[ThemeWorkerWarmup] warmed=' . $warmed);
        }
    }

    private function warmCoreServices(): void
    {
        ObjectManager::getInstance(RuntimeCachePolicy::class);
        ObjectManager::getInstance(Template::class);
        Template::getInstance();
        ObjectManager::getInstance(ThemeContextService::class);
        ObjectManager::getInstance(ThemePageTypeResolver::class);
        ObjectManager::getInstance(WelineTheme::class);
        ObjectManager::getInstance(ControllerFetchFileBefore::class);
        ObjectManager::getInstance(ControllerFetchFileAfter::class);
        ObjectManager::getInstance(TemplateFetchFile::class);
        ObjectManager::getInstance(LayoutSlotRenderer::class);
        ObjectManager::getInstance(QueryProviderRegistry::class)->getAllDescriptors();
        ObjectManager::getInstance(FrontendWorkerSessionService::class)->cleanupExpired();
    }

    private function warmFrontendThemeData(): void
    {
        /** @var ThemeContextService $themeContext */
        $themeContext = ObjectManager::getInstance(ThemeContextService::class);
        $theme = $themeContext->resolveTheme('frontend', null, false);
        if ($theme instanceof WelineTheme && $theme->getId()) {
            ThemeData::setCurrentTheme($theme);
        }

        ThemeData::setCurrentArea('frontend');
        ThemeData::performanceLoad('theme.frontend', 'theme.frontend.*', 'default', null);

        $layoutPath = LayoutPathResolver::buildLayoutPath('', 'frontend', 'category', 'default');
        if ($theme instanceof WelineTheme && $theme->getId()) {
            $resolved = LayoutPathResolver::resolveLayoutTemplate($layoutPath, $theme, 'frontend');
            if (\is_string($resolved) && $resolved !== '') {
                $source = LayoutPathResolver::getLayoutFilePath($resolved, $theme, 'frontend');
                if (\is_string($source) && $source !== '') {
                    @\is_file($source);
                    @\filemtime($source);
                    @\filesize($source);
                }
            }
        }
    }

    private function warmHotTemplateFiles(): void
    {
        $template = Template::getInstance();
        foreach ([
            'Weline_Theme::theme/frontend/layouts/category/default.phtml',
            'Weline_Theme::theme/frontend/layouts/product/default.phtml',
            'Weline_Theme::theme/frontend/partials/head/default.phtml',
            'Weline_Theme::theme/frontend/partials/head/minimal.phtml',
            'Weline_Theme::theme/frontend/partials/header/default.phtml',
            'Weline_Theme::theme/frontend/partials/breadcrumb/default.phtml',
            'Weline_Theme::theme/frontend/partials/footer/default.phtml',
            'Weline_Theme::theme/frontend/widgets/product/related-products/default.phtml',
            'Weline_Theme::theme/frontend/widgets/product/bestsellers/default.phtml',
            'Weline_Theme::theme/frontend/layouts/account/default.phtml',
            'Weline_Theme::theme/frontend/layouts/account/dashboard.phtml',
            'Weline_Theme::theme/frontend/layouts/account_orders/default.phtml',
            'Weline_Theme::theme/frontend/layouts/account_profile/default.phtml',
            'Weline_Customer::templates/frontend/account/sidebar/side.phtml',
            'Weline_Customer::templates/frontend/account/index.phtml',
            'WeShop_Catalog::templates/Frontend/Category/content.phtml',
            'WeShop_Product::templates/frontend/product/view.phtml',
            'WeShop_Filters::templates/Frontend/filters.phtml',
        ] as $fileName) {
            $this->warmCompiledFile((string)$template->getFetchFile($fileName));
            SchedulerSystem::yield();
        }

        foreach ([
            'WeShop_Catalog::WeShop_Catalog/frontend/layouts/category/products-content.phtml',
            'WeShop_Catalog::Weline_Theme/frontend/layouts/category/subcategories-filter.phtml',
            'WeShop_Catalog::Weline_Theme/frontend/partials/breadcrumb/items.phtml',
            'WeShop_Catalog::Weline_Theme/frontend/partials/header/categories-before.phtml',
            'WeShop_Catalog::Weline_Theme/frontend/partials/header/search-form-before.phtml',
            'WeShop_Product::WeShop_Product/frontend/layouts/product/main-content.phtml',
            'Weline_Order::hooks/account.sidebar.phtml',
            'Weline_Order::hooks/account.sidebar.content.phtml',
            'WeShop_Order::hooks/account.sidebar.phtml',
            'WeShop_Order::hooks/account.sidebar.content.phtml',
            'Weline_Shipping::hooks/account.sidebar.phtml',
            'Weline_Shipping::hooks/account.sidebar.content.phtml',
            'WeShop_Filters::Weline_Theme/frontend/layouts/category/filters-sidebar.phtml',
            'WeShop_Filters::Weline_Theme/frontend/layouts/base/body-end.phtml',
        ] as $hookFile) {
            $this->warmCompiledFile((string)$template->fetchTagSource('hooks', $hookFile));
            SchedulerSystem::yield();
        }
    }

    private function warmHotHookRegistries(): void
    {
        foreach ([
            'header-account',
            'header-account-links',
            'header-language-switcher',
            'header-currency-switcher',
            'Weline_Theme::frontend::layouts::base::head-before',
            'Weline_Theme::frontend::layouts::base::head-after',
            'Weline_Theme::frontend::layouts::base::body-start',
            'Weline_Theme::frontend::layouts::base::body-end',
            'Weline_Theme::frontend::layouts::base::footer-after',
            'Weline_Theme::frontend::partials::head::module-declarations',
            'Weline_Theme::frontend::partials::breadcrumb::items',
            'Weline_Theme::frontend::layouts::category::subcategories-filter',
            'Weline_Theme::frontend::layouts::account::head-before',
            'Weline_Theme::frontend::layouts::account::head-after',
            'Weline_Theme::frontend::layouts::account::body-start',
            'Weline_Theme::frontend::layouts::account::body-end',
            'Weline_Theme::frontend::layouts::account::content-before',
            'Weline_Theme::frontend::layouts::account::content-after',
            'Weline_Theme::frontend::layouts::account::sidebar-before',
            'Weline_Theme::frontend::layouts::account::sidebar-after',
            'account.sidebar',
            'account.sidebar.content',
        ] as $hookName) {
            /** @var HookReader $reader */
            $reader = ObjectManager::make(HookReader::class);
            $reader->setPath($hookName);
            $reader->getFileListWithMeta();
            $reader->getFileList();
            SchedulerSystem::yield();
        }
    }

    private function warmHotStaticFiles(): void
    {
        foreach ([
            BP . '/app/code/Weline/Customer/view/statics/css/account-index.css',
            BP . '/app/code/Weline/Customer/view/statics/css/account-sidebar.css',
            BP . '/app/code/Weline/Customer/view/statics/js/account-index.js',
            BP . '/app/code/Weline/Frontend/view/statics/base/weline.modules.js',
            BP . '/app/code/Weline/Frontend/view/statics/js/weline-api.js',
            BP . '/app/code/Weline/Frontend/view/statics/js/weline-api-worker.js',
            BP . '/app/code/Weline/Theme/view/theme/frontend/assets/js/theme.js',
        ] as $path) {
            if (!\is_file($path)) {
                continue;
            }

            @\filemtime($path);
            @\filesize($path);
            @\file_get_contents($path, false, null, 0, 262144);
            SchedulerSystem::yield();
        }
    }

    private function warmRoutingCaches(): void
    {
        if (\class_exists(\Weline\ModuleRouter\Config\ModuleRouterReader::class)) {
            ObjectManager::getInstance(\Weline\ModuleRouter\Config\ModuleRouterReader::class)->read();
        }

        try {
            \w_cache('module_router')->get('routers_rules_cache');
        } catch (\Throwable) {
        }
    }

    private function warmFpcFastPathPayloads(): void
    {
        if (!\class_exists(FullPageCacheCoordinator::class)) {
            return;
        }

        $paths = $this->resolveFpcWarmupPaths();
        $maxPaths = (int)(\Weline\Framework\App\Env::get('wls.worker.fpc_warmup_max_paths', 96) ?: 96);
        $maxPaths = \max(1, \min($maxPaths, 384));
        $paths = \array_slice($paths, 0, $maxPaths);

        $hosts = $this->resolveFpcWarmupHosts();
        $coordinator = ObjectManager::getInstance(FullPageCacheCoordinator::class);
        if (!$coordinator instanceof FullPageCacheCoordinator) {
            return;
        }

        $warmed = 0;
        foreach ($hosts as $host) {
            foreach ($paths as $path) {
                $fullUri = 'https://' . $host . $path;
                $hit = $coordinator->getFormattedCachedResponseForFullUri(
                    $fullUri,
                    'GET',
                    'text/html,application/xhtml+xml',
                    'gzip',
                    '',
                    true
                );
                if ($hit !== null) {
                    $warmed++;
                }
                SchedulerSystem::yield();
            }
        }

        if ($warmed > 0 && \function_exists('w_log_info')) {
            \w_log_info('[ThemeWorkerWarmup] fpc_fastpath=' . $warmed);
        }
    }

    public function warmFpcFastPathPayloadsForReady(): void
    {
        $this->warmFpcFastPathPayloads();
    }

    /**
     * @return list<string>
     */
    public function getFpcWarmupHosts(): array
    {
        return $this->resolveFpcWarmupHosts();
    }

    /**
     * @return list<string>
     */
    public function getFpcWarmupPaths(): array
    {
        return $this->resolveFpcWarmupPaths();
    }

    /**
     * @return list<string>
     */
    private function resolveFpcWarmupHosts(): array
    {
        $hosts = ['127.0.0.1'];
        $configured = \Weline\Framework\App\Env::get('wls.worker.fpc_warmup_hosts', []);
        if (\is_string($configured)) {
            $decoded = \json_decode($configured, true);
            $configured = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (\is_array($configured)) {
            foreach ($configured as $host) {
                if (!\is_scalar($host)) {
                    continue;
                }
                $host = \trim((string)$host);
                if ($host !== '') {
                    $hosts[] = $host;
                }
            }
        }

        try {
            $serverHost = (string)(\Weline\Framework\App\Env::get('server.host', '') ?: \Weline\Framework\App\Env::get('wls.host', ''));
            if ($serverHost !== '') {
                $hosts[] = $serverHost;
            }
        } catch (\Throwable) {
        }

        $normalized = [];
        foreach ($hosts as $host) {
            if (\str_contains($host, '://')) {
                $parsed = \parse_url($host, \PHP_URL_HOST);
                $host = \is_string($parsed) ? $parsed : '';
            }
            $host = \trim($host, " \t\n\r\0\x0B[]/");
            if ($host === '' || $host === '0.0.0.0' || $host === '::') {
                continue;
            }
            $normalized[$host] = $host;
            if (\count($normalized) >= 3) {
                break;
            }
        }

        return \array_values($normalized);
    }

    /**
     * @return list<string>
     */
    private function resolveFpcWarmupPaths(): array
    {
        $paths = [
            '/',
            '/en_US/catalog/category/sports',
            '/USD/en_US/catalog/category/sports',
            '/zh_Hans_CN/catalog/category/sports',
            '/CNY/zh_Hans_CN/catalog/category/sports',
            '/en_US/catalog/category/men/shirts',
            '/USD/en_US/catalog/category/men/shirts',
            '/zh_Hans_CN/catalog/category/men/shirts',
            '/CNY/zh_Hans_CN/catalog/category/men/shirts',
            '/en_US/catalog/category/women',
            '/USD/en_US/catalog/category/women',
            '/zh_Hans_CN/catalog/category/women',
            '/CNY/zh_Hans_CN/catalog/category/women',
            '/en_US/catalog/category/gear',
            '/en_US/catalog/category/running-gear',
            '/USD/en_US/catalog/category/running-gear',
            '/zh_Hans_CN/catalog/category/running-gear',
            '/CNY/zh_Hans_CN/catalog/category/running-gear',
            '/en_US/product/demo-category-81-sports',
            '/en_US/product/demo-category-45-clothing',
            '/product/demo-category-81-sports',
            '/product/demo-category-45-clothing',
        ];

        $configured = \Weline\Framework\App\Env::get('wls.worker.fpc_warmup_paths', []);
        if (\is_string($configured)) {
            $decoded = \json_decode($configured, true);
            $configured = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (\is_array($configured)) {
            foreach ($configured as $path) {
                if (\is_scalar($path)) {
                    $paths[] = (string)$path;
                }
            }
        }

        $paths = $this->applyDispatcherWarmupPathObservers($paths);

        $normalized = [];
        foreach ($paths as $path) {
            $path = \str_replace(["\r", "\n", "\t"], '', \trim((string)$path));
            if ($path === '') {
                continue;
            }
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }
            $normalized[$path] = $path;
        }

        return $this->prioritizeFpcWarmupPaths(\array_values($normalized));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function prioritizeFpcWarmupPaths(array $paths): array
    {
        $buckets = [[], [], [], [], []];
        foreach ($paths as $path) {
            $rank = $this->fpcWarmupPathRank($path);
            $buckets[$rank][] = $path;
        }

        return \array_values(\array_merge(...$buckets));
    }

    private function fpcWarmupPathRank(string $path): int
    {
        $pathOnly = (string)(\parse_url($path, \PHP_URL_PATH) ?: $path);
        $trimmed = \trim($pathOnly, '/');
        if ($trimmed === ''
            || \preg_match('/^(?:[A-Z]{3}\/)?[a-z]{2}(?:_[A-Za-z]+){1,2}$/', $trimmed) === 1
        ) {
            return 0;
        }

        if (\str_contains($pathOnly, '/catalog/category/')) {
            return 1;
        }

        if (\str_contains($pathOnly, '/product/') && !\str_contains($pathOnly, '/product/view')) {
            return 2;
        }

        if (\str_contains($pathOnly, '/product/view')) {
            return 4;
        }

        return 3;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function applyDispatcherWarmupPathObservers(array $paths): array
    {
        try {
            $eventsManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $data = new \Weline\Framework\DataObject\DataObject([
                'paths' => $paths,
                'instance_name' => (string)(\getenv('WLS_INSTANCE_NAME') ?: ''),
                'port' => (int)(\getenv('WLS_WORKER_PORT') ?: 0),
                'hosts' => $this->resolveFpcWarmupHosts(),
            ]);
            $events = $eventsManager->scanEvents();
            $eventName = 'Weline_Server::dispatcher::warmup_paths';
            $observers = \is_array($events[$eventName] ?? null) ? $events[$eventName] : [];
            if ($observers === []) {
                $eventsManager->dispatch($eventName, $data);
            } else {
                $event = new \Weline\Framework\Event\Event([
                    'data' => &$data,
                    'observers' => $observers,
                ]);
                $event->setName($eventName)->dispatch();
            }

            $eventPaths = $data->getData('paths');
            return \is_array($eventPaths) ? \array_values($eventPaths) : $paths;
        } catch (\Throwable) {
            return $paths;
        }
    }

    /**
     * @param callable(): void $callback
     */
    private function tryWarm(callable $callback): int
    {
        try {
            $callback();
            return 1;
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[ThemeWorkerWarmup] ' . $e->getMessage());
            }
            return 0;
        }
    }

    private function warmCompiledFile(string $compiledFile): void
    {
        if ($compiledFile === '' || !\is_file($compiledFile)) {
            return;
        }

        if (\function_exists('opcache_compile_file')) {
            @\opcache_compile_file($compiledFile);
        }
    }
}
