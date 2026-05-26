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
        'SeoProfileProvider' => [
            'path' => 'extends/module/Weline_Seo/SeoProfileProvider',
            'interface' => 'Weline\Seo\Interface\SeoProfileProviderInterface',
            'description' => 'SEO/GEO profile provider for page-type facts, robots policy, sitemap metadata, and schema graph data.',
            'required' => false,
            'multiple' => true,
        ],
    ],
];
