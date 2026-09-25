<?php
return array (
  'name' => 'Weline_Ai',
  'version' => '1.3.27',
  'requires' => 
  array (
    'Weline_Admin' => '*',
    'Weline_Backend' => '*',
    'Weline_Framework' => '*',
  ),
  'optional' => 
  array (
    'Weline_I18n' => '*',
  ),
  'provides' => 
  array (
    'Weline\\Ai\\Api\\AgentCatalogInterface' => 'Weline\\Ai\\Service\\Agent\\AgentCatalogService',
    'Weline\\Ai\\Api\\AgentModelExecutorInterface' => 'Weline\\Ai\\Api\\AgentModelExecutor',
    'Weline\\Ai\\Api\\AiRuntimeInterface' => 'Weline\\Ai\\Api\\AiRuntime',
    'Weline\\Ai\\Api\\Configuration\\ScenarioConfigurationInterface' => 'Weline\\Ai\\Service\\Configuration\\ScenarioConfiguration',
    'Weline\\Ai\\Api\\Image\\ImageRuntimeInterface' => 'Weline\\Ai\\Api\\Image\\ImageRuntime',
    'Weline\\Ai\\Api\\Image\\TextToImageScenarioBindingInterface' => 'Weline\\Ai\\Service\\Image\\TextToImageScenarioBindingManager',
    'Weline\\Ai\\Api\\Provider\\ProviderRuntimeInterface' => 'Weline\\Ai\\Service\\Provider\\ProviderRuntime',
    'Weline\\Ai\\Api\\SecretStoreInterface' => 'Weline\\Ai\\Service\\SecretStoreService',
    'Weline\\Ai\\Api\\StyleRuntimeInterface' => 'Weline\\Ai\\Api\\StyleRuntime',
    'request_resetter.Weline_Ai' => 'Weline\\Ai\\Api\\Runtime\\RequestResetter',
  ),
);
