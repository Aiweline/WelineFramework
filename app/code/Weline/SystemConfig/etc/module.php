<?php

return [
    "name" => 'Weline_SystemConfig',
    "version" => '1.2.0',
    "requires" => [
        'Weline_Acl' => '*',
        'Weline_Framework' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class => \Weline\SystemConfig\Service\SystemConfigScopeResolver::class,
        \Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface::class => \Weline\SystemConfig\Service\GlobalOnlyScopeIdentityCatalog::class,
        \Weline\SystemConfig\Api\Scope\ScopeSelectorCatalogInterface::class => \Weline\SystemConfig\Service\ScopeSelectorCatalog::class,
        \Weline\SystemConfig\Api\Scope\ScopeUiStateInterface::class => \Weline\SystemConfig\Service\SessionScopeUiState::class,
        \Weline\SystemConfig\Api\Scope\ScopedConfigRepositoryInterface::class => \Weline\SystemConfig\Service\ScopedConfigRepository::class,
        \Weline\SystemConfig\Api\CommerceRolloutGateInterface::class => \Weline\SystemConfig\Service\CommerceRolloutGate::class,
        \Weline\Framework\Http\Security\SecurityPolicyLkgRepositoryInterface::class
            => \Weline\SystemConfig\Service\OrmSecurityPolicyLkgRepository::class,
        \Weline\Framework\Http\Security\SecurityHeaderPolicyOverrideProviderInterface::class
            => \Weline\SystemConfig\Service\SystemConfigSecurityHeaderPolicyOverrideProvider::class,
    ],
];
