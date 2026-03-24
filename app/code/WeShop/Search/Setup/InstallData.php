<?php

declare(strict_types=1);

namespace WeShop\Search\Setup;

use WeShop\Search\Engine\AlgoliaEngine;
use WeShop\Search\Engine\ElasticsearchEngine;
use WeShop\Search\Engine\MeilisearchEngine;
use WeShop\Search\Engine\MysqlEngine;
use WeShop\Search\Engine\OpenSearchEngine;
use WeShop\Search\Model\SearchEngineConfig;
use WeShop\Search\Service\SearchEngineDriverRegistry;
use WeShop\Search\Service\SearchEngineEnvConfig;
use Weline\Framework\Manager\ObjectManager;

class InstallData
{
    public function install(): void
    {
        $this->registerDrivers();
        $this->installDefaultConfig();
    }

    private function registerDrivers(): void
    {
        try {
            /** @var SearchEngineDriverRegistry $registry */
            $registry = ObjectManager::getInstance(SearchEngineDriverRegistry::class);
            $registry->updateDrivers([
                SearchEngineConfig::ENGINE_OPENSEARCH => OpenSearchEngine::class,
                SearchEngineConfig::ENGINE_MEILISEARCH => MeilisearchEngine::class,
                SearchEngineConfig::ENGINE_MYSQL => MysqlEngine::class,
                SearchEngineConfig::ENGINE_ELASTICSEARCH => ElasticsearchEngine::class,
                SearchEngineConfig::ENGINE_ALGOLIA => AlgoliaEngine::class,
            ]);
        } catch (\Throwable $throwable) {
            w_log_error('注册搜索引擎驱动失败: ' . $throwable->getMessage());
        }
    }

    private function installDefaultConfig(): void
    {
        try {
            /** @var SearchEngineConfig $configModel */
            $configModel = ObjectManager::getInstance(SearchEngineConfig::class);
            /** @var SearchEngineEnvConfig $envConfig */
            $envConfig = ObjectManager::getInstance(SearchEngineEnvConfig::class);

            $scope = $envConfig->getDefaultScope();
            $existing = $configModel->getActiveEngineConfig($scope);
            if ($existing !== null) {
                return;
            }

            $defaultEngineType = $envConfig->getDefaultEngineType();
            $configModel->saveConfig(
                $defaultEngineType,
                $scope,
                $envConfig->getEngineConfig($defaultEngineType),
                true,
                100
            );
        } catch (\Throwable $throwable) {
            w_log_error('安装默认搜索配置失败: ' . $throwable->getMessage());
        }
    }
}
