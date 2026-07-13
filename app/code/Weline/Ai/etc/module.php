<?php

return [
    "name" => 'Weline_Ai',
    "version" => '1.1.0',
    "requires" => [
        'Weline_Admin' => '*',
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
    ],
    "optional" => [
        'Weline_I18n' => '*',
    ],
    "provides" => [
        \Weline\Ai\Api\AgentModelExecutorInterface::class => \Weline\Ai\Api\AgentModelExecutor::class,
        \Weline\Ai\Api\AiRuntimeInterface::class => \Weline\Ai\Api\AiRuntime::class,
        \Weline\Ai\Api\Configuration\ScenarioConfigurationInterface::class => \Weline\Ai\Service\Configuration\ScenarioConfiguration::class,
        \Weline\Ai\Api\Image\ImageRuntimeInterface::class => \Weline\Ai\Api\Image\ImageRuntime::class,
        \Weline\Ai\Api\Image\TextToImageScenarioBindingInterface::class => \Weline\Ai\Service\Image\TextToImageScenarioBindingManager::class,
        \Weline\Ai\Api\Provider\ProviderRuntimeInterface::class => \Weline\Ai\Service\Provider\ProviderRuntime::class,
        \Weline\Ai\Api\SecretStoreInterface::class => \Weline\Ai\Service\SecretStoreService::class,
        \Weline\Ai\Api\StyleRuntimeInterface::class => \Weline\Ai\Api\StyleRuntime::class,
        'request_resetter.Weline_Ai' => \Weline\Ai\Api\Runtime\RequestResetter::class,
    ],
];
