<?php

return [
    "name" => 'Weline_Theme',
    "version" => '2.3.41',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '>=2.5.0',
        'Weline_I18n' => '*',
        'Weline_Meta' => '*',
        'Weline_Product' => '*',
        'Weline_Search' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Widget' => '*',
    ],
    "optional" => [
        'Aiweline_PlayinChina' => '*',
        'Weline_Ai' => '*',
        'Weline_Cdn' => '*',
        'Weline_Cron' => '*',
        'Weline_Currency' => '*',
        'Weline_Dashboard' => '*',
        'Weline_Eav' => '*',
        'Weline_FileManager' => '*',
        'Weline_ModuleRouter' => '*',
        'Weline_Promotion' => '*',
        'Weline_Review' => '*',
        'Weline_Seo' => '*',
        'Weline_Server' => '*',
        'Weline_Smtp' => '*',
        'Weline_Websites' => '*',
    ],
    "provides" => [
        \Weline\Theme\Api\I18n\WidgetTranslationInterface::class
            => \Weline\Theme\Service\I18n\WidgetTranslation::class,
        \Weline\Framework\Runtime\GlobalRegionInjectorInterface::class
            => \Weline\Theme\Region\GlobalRegionHtmlInjector::class,
        \Weline\Framework\Deploy\ThemeRuntimeStateInterface::class
            => \Weline\Theme\Service\ThemeUpdate\ThemeRuntimeState::class,
        \Weline\Framework\View\GlobalRegionContractInterface::class
            => \Weline\Theme\Region\GlobalRegionSourceRewriter::class,
        \Weline\Framework\Runtime\ThemeIdProviderInterface::class
            => \Weline\Theme\Integration\Framework\ThemeFrameworkReadProvider::class,
        \Weline\Framework\View\ThemeSpecGateInterface::class
            => \Weline\Theme\Integration\Framework\ThemeFrameworkReadProvider::class,
        \Weline\Framework\Runtime\ThemePublishedVersionResolverInterface::class
            => \Weline\Theme\Integration\Framework\ThemeFrameworkReadProvider::class,
        \Weline\Framework\Runtime\ThemeRuntimeCacheResetInterface::class
            => \Weline\Theme\Integration\Framework\ThemeRuntimeCacheReset::class,
        // Worker start-up preload owned by this module: publishes the theme
        // component/widget catalogue before the first request builds it.
        'worker_preload_provider.Weline_Theme' =>
            \Weline\Theme\Service\Preload\ThemeCataloguePreloadProvider::class,
        // Storefront chrome render built before READY: `theme.partials.fetch.head`
        // is keyed by request path, so the first request in a Worker otherwise
        // pays 113-306 ms to build it (measured on /best-sellers).
        'worker_preload_provider.Weline_Theme_StorefrontRender' =>
            \Weline\Theme\Service\Preload\StorefrontRenderWarmupPreloadProvider::class,
        \Weline\Theme\Api\DefaultThemeInterface::class => \Weline\Theme\Service\RegisteredDefaultTheme::class,
        \Weline\Framework\Runtime\DefaultThemeConfigProviderInterface::class => \Weline\Theme\Service\RegisteredDefaultTheme::class,
        'schedule_window_utc_migration.Weline_Theme'
            => \Weline\Theme\Api\Runtime\ScheduleWindowUtcMigrationContribution::class,
        \Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface::class => \Weline\Theme\Service\Version\ThemeApplicationReferenceReader::class,
        \Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface::class => \Weline\Theme\Service\Scoped\ThemeScopedResourceProjector::class,
        \Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface::class => \Weline\Theme\Service\Scoped\ThemeScopedWorkspace::class,
        \Weline\Widget\Api\WidgetLibraryProviderInterface::class => \Weline\Theme\Integration\Widget\ThemeWidgetLibraryProvider::class,
        'theme.target_type.website' => \Weline\Theme\Integration\Websites\WebsiteTargetTypeProvider::class,
        \Weline\Websites\Api\AiWorkbench\VirtualThemeStoreInterface::class => \Weline\Theme\Integration\Websites\VirtualThemeStore::class,
        \Weline\Backend\Api\View\ThemePreviewModeProviderInterface::class => \Weline\Theme\Integration\Backend\ThemePreviewModeProvider::class,
        \Weline\Framework\Runtime\ThemeContextProviderInterface::class => \Weline\Theme\Service\ThemeContextService::class,
        \Weline\Framework\Runtime\FpcWarmupProviderInterface::class => \Weline\Theme\Api\Runtime\FpcWarmupProvider::class,
        'cache.warmer.theme.storefront_fpc' => \Weline\Theme\Service\StorefrontFpcWarmer::class,
        'cache.warmer.theme.widget_asset_sources' => \Weline\Theme\Service\WidgetAssetSourceCacheWarmer::class,
        'cache.warmer.theme.storefront_chrome_locales' => \Weline\Theme\Service\StorefrontChromeLocaleCacheWarmer::class,
        'storefront.page_prefetch.Weline_Theme'
            => \Weline\Theme\Api\Runtime\StorefrontPagePrefetchContribution::class,
        'storefront_hot_cache_bag_warmup.Weline_Theme'
            => \Weline\Theme\Api\Runtime\StorefrontHotCacheBagWarmupProvider::class,
        \Weline\Framework\View\FrontendLayoutProviderInterface::class => \Weline\Theme\Api\View\FrontendLayoutProvider::class,
        \Weline\Framework\View\BackendLayoutProviderInterface::class => \Weline\Theme\Api\View\BackendLayoutProvider::class,
        \Weline\Theme\Api\Asset\StaticAssetPublisherInterface::class => \Weline\Theme\Api\Asset\StaticAssetPublisher::class,
        \Weline\Theme\Api\Layout\LayoutWorkspaceInterface::class => \Weline\Theme\Service\LayoutWorkspace::class,
        \Weline\Theme\Api\Layout\LayoutScopeNormalizerInterface::class => \Weline\Theme\Service\ThemeLayoutScopeNormalizer::class,
        \Weline\Theme\Api\View\PreviewThemeModeResolverInterface::class => \Weline\Theme\Service\PreviewThemeModeResolver::class,
        \Weline\Theme\Api\View\ComponentMetaReaderInterface::class => \Weline\Theme\Service\ComponentMetaReader::class,
        \Weline\Framework\View\CompileStateResetterInterface::class => \Weline\Theme\Api\Runtime\CompileStateResetter::class,
        'request_resetter.Weline_Theme' => \Weline\Theme\Api\Runtime\RequestResetter::class,
        'process_cache_resetter.Weline_Theme' => \Weline\Theme\Api\Runtime\ProcessCacheResetter::class,
        'deploy.flat_static.Weline_Theme' => \Weline\Theme\Api\Deploy\FlatStaticRuntimeFilesProvider::class,
        'deploy.static_asset_mode.Weline_Theme' => \Weline\Theme\Integration\Deploy\ThemeStaticAssetModeContributor::class,
        'resource_compiler.less' => \Weline\Theme\Console\Resource\Compiler\Less::class,
        'resource_compiler.welineModules' => \Weline\Theme\Console\Resource\Compiler\WelineModules::class,
        'resource_compiler.welineUi' => \Weline\Theme\Console\Resource\Compiler\WelineUi::class,
        'template_cache_policy.Weline_Theme' => \Weline\Theme\Api\View\TemplateCachePolicyProvider::class,
        'theme.layout_content_validator.widget_images' => \Weline\Theme\Service\WidgetImageContentContractValidator::class,
        'i18n.javascript_module_config.Weline_Theme' => \Weline\Theme\Api\I18n\ThemeJavascriptModuleConfigProvider::class,
    ],
];
