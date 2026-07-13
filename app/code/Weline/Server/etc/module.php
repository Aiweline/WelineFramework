<?php

return [
    "name" => 'Weline_Server',
    "version" => '1.5.2',
    "requires" => [
        'Weline_Framework' => '*',
    ],
    "optional" => [
        'Weline_AppStore' => '*',
        'Weline_Backend' => '*',
    ],
    "provides" => [
        'cache.adapter_provider.200.Weline_Server' => \Weline\Server\Api\Cache\WlsMemoryAdapterProvider::class,
        'cache.edge_adapter.200.wls_memory' => \Weline\Server\Api\Cache\WlsMemory::class,
        'request_resetter.Weline_Server' => \Weline\Server\Api\Runtime\RequestResetter::class,
        \Weline\Framework\Runtime\WlsRuntimeAdapterInterface::class => \Weline\Server\Api\Runtime\WlsRuntimeAdapter::class,
        \Weline\Framework\Runtime\RuntimeRoutingPolicyInterface::class => \Weline\Server\Api\Runtime\RuntimeRoutingPolicy::class,
        \Weline\Framework\Runtime\RuntimeControlBroadcasterInterface::class => \Weline\Server\Api\Runtime\RuntimeControlBroadcaster::class,
        \Weline\Framework\Runtime\RuntimeDeploymentControlInterface::class => \Weline\Server\Api\Runtime\RuntimeDeploymentControl::class,
        \Weline\Framework\Runtime\MaintenanceRoutingBroadcasterInterface::class => \Weline\Server\Api\Runtime\MaintenanceRoutingBroadcaster::class,
        \Weline\Framework\Runtime\SharedStateAdminProviderInterface::class => \Weline\Server\Api\Runtime\SharedStateAdminProvider::class,
        \Weline\Framework\Cache\Contract\SharedBufferStateFactoryInterface::class => \Weline\Server\Api\Runtime\SharedBufferStateProvider::class,
        \Weline\Framework\Cache\Contract\SharedCacheStateFactoryInterface::class => \Weline\Server\Api\Runtime\SharedCacheStateProvider::class,
        \Weline\Framework\Log\RuntimeLoggerProviderInterface::class => \Weline\Server\Api\Log\RuntimeLoggerProvider::class,
        \Weline\Framework\Cache\Contract\SharedCacheStateInterface::class => \Weline\Server\Service\MemoryStateFacade::class,
        \Weline\Framework\Session\Storage\SharedSessionStateInterface::class => \Weline\Server\Service\SessionStateFacade::class,
    ],
];
