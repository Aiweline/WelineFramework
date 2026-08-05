<?php

return [
    "name" => 'Weline_SystemConfig',
    "version" => '1.0.3',
    "requires" => [
        'Weline_Acl' => '*',
        'Weline_Framework' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\SystemConfig\Api\Scope\ScopedConfigRepositoryInterface::class => \Weline\SystemConfig\Service\ScopedConfigRepository::class,
        \Weline\SystemConfig\Api\CommerceRolloutGateInterface::class => \Weline\SystemConfig\Service\CommerceRolloutGate::class,
        \Weline\Framework\Http\Security\SecurityPolicyLkgRepositoryInterface::class
            => \Weline\SystemConfig\Service\OrmSecurityPolicyLkgRepository::class,
        \Weline\Framework\Http\Security\SecurityHeaderPolicyOverrideProviderInterface::class
            => \Weline\SystemConfig\Service\SystemConfigSecurityHeaderPolicyOverrideProvider::class,
    ],
];
