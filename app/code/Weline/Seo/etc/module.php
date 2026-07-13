<?php

return [
    "name" => 'Weline_Seo',
    "version" => '1.1.0',
    "requires" => [
        'Weline_Ai' => '*',
        'Weline_Backend' => '*',
        'Weline_UrlManager' => '*',
    ],
    "optional" => [
        'Weline_Websites' => '*',
        'WeShop_Store' => '*',
    ],
    "provides" => [
        \Weline\Seo\Api\Url\UrlChangeNotifierInterface::class => \Weline\Seo\Service\SeoUrlChangeService::class,
        \Weline\Seo\Api\Head\PageContextResolverInterface::class => \Weline\Seo\Api\Head\PageContextResolver::class,
        \Weline\Seo\Api\Protocol\WebsiteProtocolResolverInterface::class => \Weline\Seo\Api\Protocol\WebsiteProtocolResolver::class,
        \Weline\Seo\Api\Sitemap\WebsiteDirectoryInterface::class => \Weline\Seo\Api\Sitemap\WebsiteDirectory::class,
    ],
];
