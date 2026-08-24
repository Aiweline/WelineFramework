<?php

return [
    "name" => 'Weline_Widget',
    "version" => '1.0.1',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Ai' => '*',
        'Weline_Extends' => '*',
        'Weline_Framework' => '*',
        'Weline_Meta' => '*',
        'Weline_Taglib' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Widget\Api\WidgetRegistryInterface::class => \Weline\Widget\Service\WidgetRegistry::class,
        // File-image preview is provided by Weline_FileManager when installed;
        // NullFileImagePreviewResolver remains a local fallback class only.
        \Weline\Widget\Api\Param\ParamFormRendererInterface::class => \Weline\Widget\Service\ParamTypeRenderer::class,
        \Weline\Widget\Api\Rendering\RuntimeTemplateRendererInterface::class => \Weline\Widget\Service\WidgetRuntimeTemplateRenderer::class,
        'request_resetter.Weline_Widget' => \Weline\Widget\Api\Runtime\RequestResetter::class,
        'process_cache_resetter.Weline_Widget' => \Weline\Widget\Api\Runtime\ProcessCacheResetter::class,
    ],
];
