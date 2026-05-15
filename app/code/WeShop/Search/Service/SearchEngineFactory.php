<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

use WeShop\Search\Api\SearchBrowseEngineInterface;
use WeShop\Search\Api\SearchEngineInterface;
use WeShop\Search\Engine\AlgoliaEngine;
use WeShop\Search\Engine\ElasticsearchEngine;
use WeShop\Search\Engine\MeilisearchEngine;
use WeShop\Search\Engine\MysqlEngine;
use WeShop\Search\Engine\OpenSearchEngine;
use WeShop\Search\Model\SearchEngineConfig;
use Weline\Framework\Manager\ObjectManager;

class SearchEngineFactory
{
    private const USABILITY_CACHE_TTL_SECONDS = 30;

    private const BROWSE_ENGINE_TYPES = [
        SearchEngineConfig::ENGINE_OPENSEARCH => true,
        SearchEngineConfig::ENGINE_ELASTICSEARCH => true,
    ];

    /**
     * @var array<string, array{usable: bool, expires_at: int}>
     */
    private static array $usabilityCache = [];

    public static function create(string $scope = 'default'): ?SearchEngineInterface
    {
        [$engineType, $configData, $defaultEngineType, $envConfig] = self::resolveEngineConfig($scope);
        $engine = self::createEngineByType($engineType);
        if ($engine) {
            $engine->initConfig($configData);
        }

        if ($engine && $engineType !== $defaultEngineType && !self::isEngineUsableCached($engine, $engineType, $configData)) {
            $fallback = self::createEngineByType($defaultEngineType);
            $fallbackConfig = $envConfig->getEngineConfig($defaultEngineType);
            if ($fallback) {
                $fallback->initConfig($fallbackConfig);
                if (self::isEngineUsableCached($fallback, $defaultEngineType, $fallbackConfig)) {
                    return $fallback;
                }
            }
        }

        return $engine;
    }

    public static function createBrowse(string $scope = 'default'): ?SearchBrowseEngineInterface
    {
        [$engineType, $configData] = self::resolveEngineConfig($scope);
        if (!isset(self::BROWSE_ENGINE_TYPES[$engineType])) {
            return null;
        }

        $engine = self::createEngineByType($engineType);
        if (!$engine instanceof SearchBrowseEngineInterface) {
            return null;
        }

        $engine->initConfig($configData);

        return $engine;
    }

    public static function createEngineByType(string $engineType): ?SearchEngineInterface
    {
        try {
            /** @var SearchEngineDriverRegistry $driverRegistry */
            $driverRegistry = ObjectManager::getInstance(SearchEngineDriverRegistry::class);
            $driverClass = $driverRegistry->getDriverClass($engineType);

            if ($driverClass && class_exists($driverClass)) {
                try {
                    return ObjectManager::getInstance($driverClass);
                } catch (\Throwable $e) {
                    w_log_error("搜索引擎驱动实例化失败 {$driverClass}: " . $e->getMessage());
                }
            }
        } catch (\Throwable) {
        }

        return match (strtolower($engineType)) {
            SearchEngineConfig::ENGINE_OPENSEARCH => ObjectManager::getInstance(OpenSearchEngine::class),
            SearchEngineConfig::ENGINE_MEILISEARCH => ObjectManager::getInstance(MeilisearchEngine::class),
            SearchEngineConfig::ENGINE_MYSQL => ObjectManager::getInstance(MysqlEngine::class),
            SearchEngineConfig::ENGINE_ELASTICSEARCH => ObjectManager::getInstance(ElasticsearchEngine::class),
            SearchEngineConfig::ENGINE_ALGOLIA => ObjectManager::getInstance(AlgoliaEngine::class),
            default => ObjectManager::getInstance(OpenSearchEngine::class),
        };
    }

    /**
     * @return array<string, array{name:string,class:string,description:string}>
     */
    public static function getAvailableEngines(): array
    {
        return [
            SearchEngineConfig::ENGINE_OPENSEARCH => [
                'name' => 'OpenSearch',
                'class' => OpenSearchEngine::class,
                'description' => '默认搜索引擎，安装链路会自动下载对应平台发行包并写入 env 配置。',
            ],
            SearchEngineConfig::ENGINE_MEILISEARCH => [
                'name' => 'Meilisearch',
                'class' => MeilisearchEngine::class,
                'description' => '轻量快速的即时搜索引擎，适合已有 Meilisearch 运行环境。',
            ],
            SearchEngineConfig::ENGINE_MYSQL => [
                'name' => 'MySQL全文搜索',
                'class' => MysqlEngine::class,
                'description' => '使用 MySQL 自身能力，无需额外服务，适合作为兜底方案。',
            ],
            SearchEngineConfig::ENGINE_ELASTICSEARCH => [
                'name' => 'Elasticsearch',
                'class' => ElasticsearchEngine::class,
                'description' => '兼容 Elasticsearch 集群，保留对已有部署的兼容支持。',
            ],
            SearchEngineConfig::ENGINE_ALGOLIA => [
                'name' => 'Algolia',
                'class' => AlgoliaEngine::class,
                'description' => '托管式云搜索服务，适合已有 Algolia 账号和索引环境。',
            ],
        ];
    }

    private static function isEngineUsable(SearchEngineInterface $engine): bool
    {
        try {
            return $engine->testConnection();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string, 3: SearchEngineEnvConfig}
     */
    private static function resolveEngineConfig(string $scope): array
    {
        /** @var SearchEngineConfig $configModel */
        $configModel = ObjectManager::getInstance(SearchEngineConfig::class);
        /** @var SearchEngineEnvConfig $envConfig */
        $envConfig = ObjectManager::getInstance(SearchEngineEnvConfig::class);
        $defaultEngineType = $envConfig->getDefaultEngineType();

        $config = $configModel->getActiveEngineConfig($scope);
        if (!$config) {
            return [
                $defaultEngineType,
                $envConfig->getEngineConfig($defaultEngineType),
                $defaultEngineType,
                $envConfig,
            ];
        }

        $engineType = $envConfig->normalizeEngineType((string) ($config[SearchEngineConfig::schema_fields_ENGINE_TYPE] ?? ''));
        if ($engineType === '') {
            $engineType = $defaultEngineType;
        }

        return [
            $engineType,
            $configModel->setData($config)->getConfigData(),
            $defaultEngineType,
            $envConfig,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function isEngineUsableCached(SearchEngineInterface $engine, string $engineType, array $config): bool
    {
        $cacheKey = $engineType . ':' . sha1((string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $now = time();
        $cached = self::$usabilityCache[$cacheKey] ?? null;
        if (is_array($cached) && ($cached['expires_at'] ?? 0) >= $now) {
            return (bool) ($cached['usable'] ?? false);
        }

        $usable = self::isEngineUsable($engine);
        self::$usabilityCache[$cacheKey] = [
            'usable' => $usable,
            'expires_at' => $now + self::USABILITY_CACHE_TTL_SECONDS,
        ];

        return $usable;
    }
}
