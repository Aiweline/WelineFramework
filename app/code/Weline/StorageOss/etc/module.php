<?php

declare(strict_types=1);

return [
    'name' => 'Weline_StorageOss',
    'version' => '1.0.1',
    'requires' => [
        'Weline_Framework' => '>=2.5.0',
        'Weline_Storage' => '>=1.2.1',
    ],
    'provides' => [
        'storage.driver_provider.oss_aliyun' => \Weline\StorageOss\Provider\AliyunOssProvider::class,
    ],
];
