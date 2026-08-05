<?php

return [
    "name" => 'Weline_Storage',
    "version" => '1.0.0',
    "requires" => [
    ],
    "optional" => [
        // MediaUrlCowResolver：有则按 Scope COW 媒体基址；无则回退共享 base_url
        'Weline_Cdn' => '*',
    ],
    "provides" => [
        \Weline\Storage\Api\StorageCatalogInterface::class => \Weline\Storage\Service\StorageCatalog::class,
    ],
];
