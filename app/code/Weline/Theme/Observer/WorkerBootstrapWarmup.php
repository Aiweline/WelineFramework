<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
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
        $warmed += $this->tryWarm(function (): void {
            $this->warmFrontendThemeData();
        });
        $warmed += $this->tryWarm(function (): void {
            $this->warmHotTemplateFiles();
        });
        $warmed += $this->tryWarm(function (): void {
            $this->warmHotHookRegistries();
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
            'Weline_Theme::theme/frontend/partials/header/default.phtml',
            'Weline_Theme::theme/frontend/partials/breadcrumb/default.phtml',
            'Weline_Theme::theme/frontend/partials/footer/default.phtml',
            'WeShop_Catalog::templates/Frontend/Category/content.phtml',
            'WeShop_Filters::templates/Frontend/filters.phtml',
        ] as $fileName) {
            $this->warmCompiledFile((string)$template->getFetchFile($fileName));
        }

        foreach ([
            'WeShop_Catalog::WeShop_Catalog/frontend/layouts/category/products-content.phtml',
            'WeShop_Catalog::Weline_Theme/frontend/layouts/category/subcategories-filter.phtml',
            'WeShop_Catalog::Weline_Theme/frontend/partials/breadcrumb/items.phtml',
            'WeShop_Catalog::Weline_Theme/frontend/partials/header/categories-before.phtml',
            'WeShop_Catalog::Weline_Theme/frontend/partials/header/search-form-before.phtml',
            'WeShop_Filters::Weline_Theme/frontend/layouts/category/filters-sidebar.phtml',
            'WeShop_Filters::Weline_Theme/frontend/layouts/base/body-end.phtml',
        ] as $hookFile) {
            $this->warmCompiledFile((string)$template->fetchTagSource('hooks', $hookFile));
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
        ] as $hookName) {
            /** @var HookReader $reader */
            $reader = ObjectManager::make(HookReader::class);
            $reader->setPath($hookName);
            $reader->getFileListWithMeta();
            $reader->getFileList();
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
