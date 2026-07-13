<?php

return [
    "name" => 'Weline_Storage',
    "version" => '1.0.0',
    "requires" => [
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Storage\Api\StorageCatalogInterface::class => \Weline\Storage\Service\StorageCatalog::class,
    ],
];
