<?php

return [
    "name" => 'Weline_Widget',
    "version" => '1.0.32',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Acl' => '*',
        'Weline_Ai' => '*',
        'Weline_Extends' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
        'Weline_Meta' => '*',
        'Weline_Product' => '*',
        'Weline_Taglib' => '*',
    ],
    "optional" => [
        'Weline_Theme' => '*',
    ],
    "provides" => [
        \Weline\Widget\Api\WidgetRegistryInterface::class => \Weline\Widget\Service\WidgetRegistry::class,
        'worker_preload_provider.Weline_Widget_Catalogue'
            => \Weline\Widget\Service\Preload\WidgetCataloguePreloadProvider::class,
        // File-image preview is provided by Weline_FileManager when installed;
        // NullFileImagePreviewResolver remains a local fallback class only.
        \Weline\Widget\Api\Param\ParamFormRendererInterface::class => \Weline\Widget\Service\ParamTypeRenderer::class,
        \Weline\Widget\Api\Rendering\RuntimeTemplateRendererInterface::class => \Weline\Widget\Service\WidgetRuntimeTemplateRenderer::class,
        'request_resetter.Weline_Widget' => \Weline\Widget\Api\Runtime\RequestResetter::class,
        'process_cache_resetter.Weline_Widget' => \Weline\Widget\Api\Runtime\ProcessCacheResetter::class,
    ],
];
