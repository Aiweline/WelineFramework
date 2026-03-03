<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

use WeShop\Search\Api\SearchEngineInterface;
use WeShop\Search\Engine\MeilisearchEngine;
use WeShop\Search\Engine\MysqlEngine;
use WeShop\Search\Engine\ElasticsearchEngine;
use WeShop\Search\Engine\AlgoliaEngine;
use WeShop\Search\Model\SearchEngineConfig;
use Weline\Framework\Manager\ObjectManager;

/**
 * 搜索引擎工厂类
 * 支持驱动模式，类似数据库驱动机制
 */
class SearchEngineFactory
{
    /**
     * 创建搜索引擎实例
     * 
     * @param string $scope 作用域
     * @return SearchEngineInterface|null
     */
    public static function create(string $scope = 'default'): ?SearchEngineInterface
    {
        /** @var SearchEngineConfig $configModel */
        $configModel = ObjectManager::getInstance(SearchEngineConfig::class);
        $config = $configModel->getActiveEngineConfig($scope);
        
        if (!$config) {
            // 如果没有配置，默认使用 Meilisearch 引擎
            $engine = self::createEngineByType(SearchEngineConfig::ENGINE_MEILISEARCH);
            if ($engine) {
                $engine->initConfig([]);
            }
            return $engine;
        }
        
        $engineType = $config[SearchEngineConfig::fields_ENGINE_TYPE];
        $configData = $configModel->setData($config)->getConfigData();
        
        $engine = self::createEngineByType($engineType);
        if ($engine) {
            $engine->initConfig($configData);
        }
        
        return $engine;
    }
    
    /**
     * 根据类型创建搜索引擎实例（支持驱动模式）
     * 
     * @param string $engineType 引擎类型
     * @return SearchEngineInterface|null
     */
    public static function createEngineByType(string $engineType): ?SearchEngineInterface
    {
        // 优先从驱动注册表加载
        try {
            /** @var SearchEngineDriverRegistry $driverRegistry */
            $driverRegistry = ObjectManager::getInstance(SearchEngineDriverRegistry::class);
            $driverClass = $driverRegistry->getDriverClass($engineType);
            
            if ($driverClass && class_exists($driverClass, false)) {
                try {
                    return ObjectManager::getInstance($driverClass);
                } catch (\Throwable $e) {
                    // 如果实例化失败，回退到硬编码方式
                    w_log_error("搜索引擎驱动实例化失败: {$driverClass} - " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            // 如果驱动注册表加载失败，静默回退到硬编码方式
        }
        
        // 回退到硬编码方式（向后兼容）
        return match (strtolower($engineType)) {
            SearchEngineConfig::ENGINE_MEILISEARCH => ObjectManager::getInstance(MeilisearchEngine::class),
            SearchEngineConfig::ENGINE_MYSQL => ObjectManager::getInstance(MysqlEngine::class),
            SearchEngineConfig::ENGINE_ELASTICSEARCH => ObjectManager::getInstance(ElasticsearchEngine::class),
            SearchEngineConfig::ENGINE_ALGOLIA => ObjectManager::getInstance(AlgoliaEngine::class),
            default => ObjectManager::getInstance(MeilisearchEngine::class), // 默认使用 Meilisearch
        };
    }
    
    /**
     * 获取所有可用的搜索引擎类型
     * 
     * @return array
     */
    public static function getAvailableEngines(): array
    {
        return [
            SearchEngineConfig::ENGINE_MEILISEARCH => [
                'name' => 'Meilisearch',
                'class' => MeilisearchEngine::class,
                'description' => '快速、相关且容错的搜索引擎，提供即时搜索体验',
            ],
            SearchEngineConfig::ENGINE_MYSQL => [
                'name' => 'MySQL全文搜索',
                'class' => MysqlEngine::class,
                'description' => '使用MySQL数据库进行全文搜索，无需额外配置',
            ],
            SearchEngineConfig::ENGINE_ELASTICSEARCH => [
                'name' => 'Elasticsearch',
                'class' => ElasticsearchEngine::class,
                'description' => '强大的分布式搜索引擎，支持全文搜索和分析',
            ],
            SearchEngineConfig::ENGINE_ALGOLIA => [
                'name' => 'Algolia',
                'class' => AlgoliaEngine::class,
                'description' => '云端搜索服务，提供即时的搜索体验',
            ],
        ];
    }
}
