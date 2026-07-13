<?php

return [
    "name" => 'Weline_Acl',
    "version" => '1.0.5',
    "requires" => [
        'Weline_Framework' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Acl\Api\Authorization\AuthorizationServiceInterface::class => \Weline\Acl\Service\AclService::class,
        \Weline\Acl\Api\Role\RoleCatalogInterface::class => \Weline\Acl\Service\RoleCatalog::class,
        \Weline\Acl\Api\Role\RoleAdministrationInterface::class => \Weline\Acl\Service\RoleAdministration::class,
        \Weline\Acl\Api\Scope\ScopeCatalogInterface::class => \Weline\Acl\Service\ScopeCatalog::class,
        \Weline\Acl\Api\ResourceTreeServiceInterface::class => \Weline\Acl\Service\ResourceTreeService::class,
        \Weline\Acl\Api\Resource\MenuResourceServiceInterface::class => \Weline\Acl\Service\MenuResourceService::class,
        \Weline\Acl\Api\Resource\MenuRegistryInterface::class => \Weline\Acl\Service\MenuRegistry::class,
        \Weline\Acl\Api\Resource\WhitelistServiceInterface::class => \Weline\Acl\Service\WhitelistService::class,
        \Weline\Acl\Api\Statistics\MenuStatisticsInterface::class => \Weline\Acl\Service\MenuStatistics::class,
        'request_resetter.Weline_Acl' => \Weline\Acl\Api\Runtime\RequestResetter::class,
    ],
];
