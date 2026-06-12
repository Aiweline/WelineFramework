<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'doc/扩展规约说明.md',
    'extends' => [
        'FeedProvider' => [
            'path' => 'extends/module/Weline_Seo/FeedProvider',
            'interface' => 'Weline\Seo\Interface\FeedProviderInterface',
            'description' => 'SEO feed provider extension point.',
            'required' => true,
            'multiple' => true,
        ],
        'SitemapProvider' => [
            'path' => 'extends/module/Weline_Seo/SitemapProvider',
            'interface' => 'Weline\Seo\Interface\SitemapProviderInterface',
            'description' => 'Legacy sitemap provider extension point.',
            'required' => false,
            'multiple' => true,
        ],
        'SitemapUrlProvider' => [
            'path' => 'extends/module/Weline_Seo/SitemapUrlProvider',
            'interface' => 'Weline\Seo\Interface\SitemapUrlProviderInterface',
            'description' => 'Sitemap URL provider extension point.',
            'required' => false,
            'multiple' => true,
        ],
        'SitemapAdapter' => [
            'path' => 'extends/module/Weline_Seo/SitemapAdapter',
            'interface' => 'Weline\Seo\Interface\SitemapPlatformAdapterInterface',
            'description' => 'Search engine sitemap/catalog/IndexNow platform adapter extension point.',
            'required' => false,
            'multiple' => true,
        ],
        'SearchEngineAdapter' => [
            'path' => 'extends/module/Weline_Seo/SearchEngineAdapter',
            'interface' => 'Weline\Seo\Interface\SearchEngineAdapterInterface',
            'description' => 'Search engine URL push adapter extension point.',
            'required' => false,
            'multiple' => true,
        ],
        'SeoProfileProvider' => [
            'path' => 'extends/module/Weline_Seo/SeoProfileProvider',
            'interface' => 'Weline\Seo\Interface\SeoProfileProviderInterface',
            'description' => 'SEO/GEO profile provider for page-type facts, robots policy, sitemap metadata, and schema graph data.',
            'required' => false,
            'multiple' => true,
        ],
        'SeoSlotProvider' => [
            'path' => 'extends/module/Weline_Seo/SeoSlotProvider',
            'interface' => 'Weline\Seo\Interface\SeoSlotProviderInterface',
            'description' => 'Structured SEO tag custom slot provider.',
            'required' => false,
            'multiple' => true,
        ],
        'SeoStructureNodeBuilder' => [
            'path' => 'extends/module/Weline_Seo/SeoStructureNodeBuilder',
            'interface' => 'Weline\Seo\Structure\SeoStructureNodeBuilderInterface',
            'description' => 'SEO structured data node builder for schema.org graph nodes.',
            'required' => false,
            'multiple' => true,
        ],
    ],
];
