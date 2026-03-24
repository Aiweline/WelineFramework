<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

use WeShop\Search\Model\SearchEngineConfig;
use Weline\Framework\App\Env;

class SearchEngineEnvConfig
{
    /**
     * @return array<string, mixed>
     */
    public function getMergedConfig(): array
    {
        return array_replace_recursive(
            $this->loadModuleDefaults(),
            $this->loadRuntimeOverrides()
        );
    }

    public function getDefaultScope(): string
    {
        $scope = trim((string) ($this->getMergedConfig()['default_scope'] ?? 'default'));

        return $scope !== '' ? $scope : 'default';
    }

    public function getDefaultEngineType(): string
    {
        $engineType = $this->normalizeEngineType((string) ($this->getMergedConfig()['default_engine'] ?? ''));

        return $engineType !== '' ? $engineType : SearchEngineConfig::ENGINE_OPENSEARCH;
    }

    public function normalizeEngineType(string $engineType): string
    {
        $engineType = strtolower(trim($engineType));
        $supported = [
            SearchEngineConfig::ENGINE_OPENSEARCH,
            SearchEngineConfig::ENGINE_MEILISEARCH,
            SearchEngineConfig::ENGINE_MYSQL,
            SearchEngineConfig::ENGINE_ELASTICSEARCH,
            SearchEngineConfig::ENGINE_ALGOLIA,
        ];

        return in_array($engineType, $supported, true) ? $engineType : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getEngineConfig(string $engineType): array
    {
        $engineType = $this->normalizeEngineType($engineType);
        if ($engineType === '') {
            $engineType = $this->getDefaultEngineType();
        }

        $engines = $this->getMergedConfig()['engines'] ?? [];
        $engineConfig = is_array($engines[$engineType] ?? null) ? $engines[$engineType] : [];

        return $this->normalizeEngineConfig($engineType, $engineConfig);
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadModuleDefaults(): array
    {
        $moduleEnv = Env::module_env('WeShop_Search');
        $config = $moduleEnv['config'] ?? [];

        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadRuntimeOverrides(): array
    {
        $config = Env::get('search', []);

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeEngineConfig(string $engineType, array $config): array
    {
        return match ($engineType) {
            SearchEngineConfig::ENGINE_OPENSEARCH,
            SearchEngineConfig::ENGINE_ELASTICSEARCH => [
                'host' => rtrim(trim((string) ($config['host'] ?? 'http://127.0.0.1')) ?: 'http://127.0.0.1', '/'),
                'port' => max(1, (int) ($config['port'] ?? 9200)),
                'index' => trim((string) ($config['index'] ?? $config['index_name'] ?? 'products')) ?: 'products',
                'username' => trim((string) ($config['username'] ?? '')),
                'password' => (string) ($config['password'] ?? ''),
                'timeout' => max(1, (int) ($config['timeout'] ?? 5)),
                'version' => trim((string) ($config['version'] ?? '')),
                'install_dir' => trim((string) ($config['install_dir'] ?? '')),
                'config_file' => trim((string) ($config['config_file'] ?? '')),
            ],
            SearchEngineConfig::ENGINE_MEILISEARCH => [
                'host' => trim((string) ($config['host'] ?? 'http://127.0.0.1:7700')) ?: 'http://127.0.0.1:7700',
                'api_key' => (string) ($config['api_key'] ?? ''),
                'index_name' => trim((string) ($config['index_name'] ?? 'products')) ?: 'products',
            ],
            SearchEngineConfig::ENGINE_ALGOLIA => [
                'application_id' => trim((string) ($config['application_id'] ?? '')),
                'api_key' => (string) ($config['api_key'] ?? ''),
                'index_name' => trim((string) ($config['index_name'] ?? 'products')) ?: 'products',
            ],
            SearchEngineConfig::ENGINE_MYSQL => [],
            default => $config,
        };
    }
}
