<?php

return [
    "name" => 'Weline_UrlManager',
    "version" => '1.0.2',
    "requires" => [
        'Weline_Admin' => '*',
        'Weline_ModuleManager' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\UrlManager\Api\Rewrite\UrlRewriteDirectoryInterface::class => \Weline\UrlManager\Api\Rewrite\UrlRewriteDirectory::class,
    ],
];
