<?php

return [
    "name" => 'Weline_UrlManager',
    "version" => '1.0.8',
    "requires" => [
        'Weline_Admin' => '*',
        'Weline_ModuleManager' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\UrlManager\Api\Rewrite\UrlRewriteDirectoryInterface::class => \Weline\UrlManager\Api\Rewrite\UrlRewriteDirectory::class,
        'process_cache_resetter.Weline_UrlManager' => \Weline\UrlManager\Api\Runtime\ProcessCacheResetter::class,
    ],
];
