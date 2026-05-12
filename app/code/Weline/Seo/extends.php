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
        'HeadContextProvider' => [
            'path' => 'extends/module/Weline_Seo/HeadContextProvider',
            'interface' => 'Weline\Seo\Interface\HeadContextProviderInterface',
            'description' => 'SEO head context provider for title, description, canonical, breadcrumbs, subject data, and alternates.',
            'required' => false,
            'multiple' => true,
        ],
        'StructuredDataProvider' => [
            'path' => 'extends/module/Weline_Seo/StructuredDataProvider',
            'interface' => 'Weline\Seo\Interface\StructuredDataProviderInterface',
            'description' => 'JSON-LD graph provider for schema.org nodes.',
            'required' => false,
            'multiple' => true,
        ],
    ],
];
