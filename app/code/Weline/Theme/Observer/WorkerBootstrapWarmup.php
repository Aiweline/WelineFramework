<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Manager\ObjectManager;
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
            'Weline_Theme::theme/frontend/partials/head/default.phtml',
            'Weline_Theme::theme/frontend/partials/head/minimal.phtml',
            'Weline_Theme::theme/frontend/partials/header/default.phtml',
            'Weline_Theme::theme/frontend/partials/breadcrumb/default.phtml',
            'Weline_Theme::theme/frontend/partials/footer/default.phtml',
            'Weline_Theme::theme/frontend/layouts/account/default.phtml',
            'Weline_Theme::theme/frontend/layouts/account/dashboard.phtml',
            'Weline_Theme::theme/frontend/layouts/account_orders/default.phtml',
            'Weline_Theme::theme/frontend/layouts/account_profile/default.phtml',
            'Weline_Customer::templates/frontend/account/sidebar/side.phtml',
            'Weline_Customer::templates/frontend/account/index.phtml',
            'WeShop_Catalog::templates/Frontend/Category/content.phtml',
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
