<?php

return [
    "name" => 'Weline_Acl',
    "version" => '1.0.9',
    "requires" => [
        'Weline_Framework' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Acl\Api\Authorization\AuthorizationServiceInterface::class => \Weline\Acl\Service\AclService::class,
        \Weline\Acl\Api\Authorization\ResourceAuthorizationServiceInterface::class => \Weline\Acl\Service\ResourceAuthorizationService::class,
        \Weline\Acl\Api\Authorization\ObjectAuthorizationServiceInterface::class => \Weline\Acl\Service\ObjectAuthorizationService::class,
        \Weline\Acl\Api\Authorization\ObjectScopeGrantStoreInterface::class => \Weline\Acl\Service\ModelObjectScopeGrantStore::class,
        \Weline\Acl\Api\Authorization\ObjectAuthorizationAuditInterface::class => \Weline\Acl\Service\SecurityLogObjectAuthorizationAudit::class,
        \Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface::class => \Weline\Acl\Service\BackendObjectAuthorizationGuard::class,
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
