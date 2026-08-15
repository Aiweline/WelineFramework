<?php

return [
    "name" => 'Weline_SessionManager',
    "version" => '1.1.0',
    "requires" => [
        'Weline_Framework' => '^2.4',
        'Weline_Backend' => '*',
        'Weline_Customer' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Framework\Session\Auth\Device\AuthenticatedDeviceRegistryInterface::class
            => \Weline\SessionManager\Service\AuthenticatedDeviceRegistry::class,
        \Weline\Framework\Session\Auth\Device\RememberedDeviceCredentialProviderInterface::class
            => \Weline\SessionManager\Service\AuthenticatedDeviceRegistry::class,
        \Weline\SessionManager\Api\Persistence\DeviceRepositoryInterface::class
            => \Weline\SessionManager\Service\Persistence\OrmDeviceRepository::class,
        \Weline\SessionManager\Api\DeviceMetadataProviderInterface::class
            => \Weline\SessionManager\Service\RequestDeviceMetadataProvider::class,
    ],
];
