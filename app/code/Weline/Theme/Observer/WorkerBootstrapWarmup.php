<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Cache\RuntimeCachePolicy;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionRegistry;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\QueryProviderRegistry;
use Weline\Framework\View\Template;
use Weline\ModuleRouter\Api\RouterRulesReaderInterface;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePageTypeResolver;

class WorkerBootstrapWarmup implements ObserverInterface
{
    private ?ViewWarmupContribution $viewWarmupContribution = null;

    public function __construct(
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

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
            $this->warmBackendThemeData();
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
        ObjectManager::getInstance(\Weline\Framework\Ui\FormKey::class);
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

    private function warmBackendThemeData(): void
    {
        /** @var ThemeContextService $themeContext */
        $themeContext = ObjectManager::getInstance(ThemeContextService::class);
        $theme = $themeContext->resolveTheme('backend', null, false);
        if ($theme instanceof WelineTheme && $theme->getId()) {
            ThemeData::setCurrentTheme($theme);
        }

        ThemeData::setCurrentArea('backend');
        ThemeData::performanceLoad('theme.backend', 'theme.backend.*', 'default', null);

        $layoutPath = LayoutPathResolver::buildLayoutPath('', 'backend', 'default', 'default');
        if ($theme instanceof WelineTheme && $theme->getId()) {
            $resolved = LayoutPathResolver::resolveLayoutTemplate($layoutPath, $theme, 'backend');
            if (\is_string($resolved) && $resolved !== '') {
                $source = LayoutPathResolver::getLayoutFilePath($resolved, $theme, 'backend');
                if (\is_string($source) && $source !== '') {
                    @\is_file($source);
                    @\filemtime($source);
                    @\filesize($source);
                }
            }
        }

        ThemeData::resetRequestState();
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
        ] as $fileName) {
            $this->warmViewTemplate($template, $fileName);
            SchedulerSystem::yield();
        }

        foreach ($this->viewWarmupContribution()->templates as $fileName) {
            $this->warmViewTemplate($template, $fileName);
            SchedulerSystem::yield();
        }

        foreach ($this->viewWarmupContribution()->tagTemplates as $type => $sources) {
            foreach ($sources as $source) {
                $this->warmTagTemplate($template, $type, $source);
                SchedulerSystem::yield();
            }
        }

        foreach ([
            'Weline_Theme::theme/backend/layouts/default/default.phtml',
            'Weline_Theme::theme/backend/layouts/default/blank.phtml',
            'Weline_Theme::theme/backend/layouts/login/default.phtml',
            'Weline_Theme::theme/backend/partials/head/default.phtml',
            'Weline_Theme::theme/backend/partials/loading/default.phtml',
            'Weline_Theme::theme/backend/partials/topbar/default.phtml',
            'Weline_Theme::theme/backend/partials/topnav/default.phtml',
            'Weline_Theme::theme/backend/partials/sidebar/left.phtml',
            'Weline_Theme::theme/backend/partials/sidebar/default.phtml',
            'Weline_Theme::theme/backend/partials/right-sidebar/default.phtml',
            'Weline_Theme::theme/backend/partials/layout/main-content.phtml',
            'Weline_Theme::theme/backend/partials/layout/content.phtml',
            'Weline_Theme::theme/backend/partials/scripts/default.phtml',
            'Weline_Theme::theme/backend/partials/footer/default.phtml',
            'Weline_Theme::theme/backend/components/card.phtml',
            'Weline_Theme::theme/backend/components/table.phtml',
            'Weline_Theme::theme/backend/components/button.phtml',
            'Weline_Theme::theme/backend/components/form-group.phtml',
        ] as $fileName) {
            $this->warmViewTemplate($template, $fileName);
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
            'Weline_Theme::backend::layouts::base::head-before',
            'Weline_Theme::backend::layouts::base::head-after',
            'Weline_Theme::backend::layouts::base::body-start',
            'Weline_Theme::backend::layouts::base::body-end',
            'Weline_Theme::backend::layouts::base::content-before',
            'Weline_Theme::backend::layouts::base::content-after',
            'Weline_Theme::backend::partials::head::module-declarations',
            'Weline_Theme::backend::partials::topbar::logo',
            'Weline_Theme::backend::partials::topbar::before',
            'Weline_Theme::backend::partials::topbar::after',
            'Weline_Theme::backend::partials::topnav::before',
            'Weline_Theme::backend::partials::topnav::after',
            'Weline_Theme::backend::partials::sidebar::before',
            'Weline_Theme::backend::partials::sidebar::after',
            'Weline_Theme::backend::partials::right-sidebar::before',
            'Weline_Theme::backend::partials::right-sidebar::after',
            'Weline_Theme::backend::partials::scripts::before',
            'Weline_Theme::backend::partials::scripts::after',
        ] as $hookName) {
            $this->warmHookRegistry($hookName);
        }

        foreach ($this->viewWarmupContribution()->hookNames as $hookName) {
            $this->warmHookRegistry($hookName);
        }
    }

    private function warmHookRegistry(string $hookName): void
    {
        /** @var HookReader $reader */
        $reader = ObjectManager::make(HookReader::class);
        $reader->setPath($hookName);
        $reader->getFileListWithMeta();
        $reader->getFileList();
        SchedulerSystem::yield();
    }

    private function warmHotStaticFiles(): void
    {
        $paths = [
            BP . '/app/code/Weline/Frontend/view/statics/base/weline.modules.js',
            BP . '/app/code/Weline/Frontend/view/statics/js/weline-api.js',
            BP . '/app/code/Weline/Frontend/view/statics/js/weline-api-worker.js',
            BP . '/app/code/Weline/Theme/view/theme/frontend/assets/js/theme.js',
            BP . '/app/code/Weline/Theme/view/theme/backend/assets/css/theme.css',
            BP . '/app/code/Weline/Theme/view/theme/backend/assets/js/theme.js',
            BP . '/app/code/Weline/Theme/view/theme/backend/assets/js/backend-components.js',
            BP . '/app/code/Weline/Theme/view/theme/backend/colors/_default.css',
            BP . '/app/code/Weline/Theme/view/theme/backend/colors/_dark.css',
            BP . '/app/code/Weline/Theme/view/theme/backend/colors/_light.css',
            BP . '/app/code/Weline/Theme/view/theme/backend/variables/_colors.css',
            BP . '/app/code/Weline/Theme/view/theme/backend/variables/_spacing.css',
            BP . '/app/code/Weline/Theme/view/theme/backend/variables/_typography.css',
        ];
        foreach ($this->viewWarmupContribution()->staticFiles as $relativePath) {
            $paths[] = BP . '/' . $relativePath;
        }

        foreach ($paths as $path) {
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
        $routerRules = $this->runtimeProviders->resolve(RouterRulesReaderInterface::class);
        if ($routerRules instanceof RouterRulesReaderInterface) {
            $routerRules->read();
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
        $paths = ['/', ...$this->viewWarmupContribution()->fpcPaths];

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

    private function viewWarmupContribution(): ViewWarmupContribution
    {
        if ($this->viewWarmupContribution instanceof ViewWarmupContribution) {
            return $this->viewWarmupContribution;
        }

        $registry = ObjectManager::getInstance(ViewWarmupContributionRegistry::class);
        return $this->viewWarmupContribution = $registry->aggregate();
    }

    private function warmViewTemplate(Template $template, string $fileName): void
    {
        try {
            $this->warmCompiledFile((string)$template->getFetchFile($fileName));
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[ThemeWorkerWarmup] template ' . $fileName . ': ' . $e->getMessage());
            }
        }
    }

    private function warmTagTemplate(Template $template, string $type, string $source): void
    {
        try {
            $this->warmCompiledFile((string)$template->fetchTagSource($type, $source));
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[ThemeWorkerWarmup] ' . $type . ' ' . $source . ': ' . $e->getMessage());
            }
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
