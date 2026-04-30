<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Service\ModelCollector;

/**
 * AI 供应商模型同步服务
 *
 * 功能：
 * - 调用供应商模型列表 API
 * - 将合并后的模型仅写入数据库（不写 etc/models / etc/vendors 配置文件）
 * - 支持批量同步与干跑模式
 */
class ModelSyncService
{
    private AccountService $accountService;
    private ModelCollector $modelCollector;

    public function __construct(
        AccountService $accountService,
        ModelCollector $modelCollector
    ) {
        $this->accountService = $accountService;
        $this->modelCollector = $modelCollector;
    }

    /**
     * 同步全部供应商模型
     *
     * @param array $options
     * @return array
     */
    public function syncAllProviders(array $options = []): array
    {
        $providers = VendorConfigManager::getSupportedProviders();
        $providerCodes = $options['providers'] ?? array_keys($providers);
        $results = [];

        foreach ($providerCodes as $providerCode) {
            $results[$providerCode] = $this->syncProvider($providerCode, $options);
        }

        $dbUpdatedCount = 0;
        foreach ($results as $res) {
            if (is_array($res) && isset($res['updated'], $res['created'])) {
                $dbUpdatedCount += (int)($res['updated'] ?? 0) + (int)($res['created'] ?? 0);
            }
        }

        return [
            'providers' => $results,
            'collected_count' => $dbUpdatedCount,
        ];
    }

    /**
     * 同步指定供应商模型
     *
     * @param string $providerCode
     * @param array $options
     * @return array
     */
    public function syncProvider(string $providerCode, array $options = []): array
    {
        $providerConfig = VendorConfigManager::getProviderConfig($providerCode);
        if (!$providerConfig) {
            return [
                'success' => false,
                'message' => __('不支持的供应商: %{1}', [$providerCode]),
            ];
        }

        $apiInfo = $providerConfig['models_api'] ?? [];
        if (empty($apiInfo)) {
            return [
                'success' => false,
                'message' => __('供应商 %{1} 未配置模型列表接口', [$providerCode]),
            ];
        }

        $existingModels = $providerConfig['models'] ?? [];
        $keepExisting = $options['keep_existing'] ?? true;

        $account = $this->accountService->getActiveAccountForSync($providerCode);
        $apiKey = $this->resolveApiKey($providerConfig, $account, $options);
        $authType = $apiInfo['auth_type'] ?? 'bearer';
        if ($authType !== 'none' && empty($apiKey)) {
            if ($keepExisting && !empty($existingModels)) {
                return $this->syncWithExistingModels($providerCode, $providerConfig, $existingModels, $options);
            }
            return [
                'success' => false,
                'message' => __('供应商 %{1} 缺少可用API Key，无法同步模型列表', [$providerCode]),
            ];
        }

        $baseUrl = $this->resolveBaseUrl($providerConfig, $account);
        if (empty($baseUrl)) {
            return [
                'success' => false,
                'message' => __('供应商 %{1} 未配置 base_url', [$providerCode]),
            ];
        }

        $apiPath = $apiInfo['path'] ?? '/models';
        $url = rtrim($baseUrl, '/') . $apiPath;
        if ($authType === 'query_key') {
            $param = $apiInfo['auth_param'] ?? 'key';
            $url .= (str_contains($url, '?') ? '&' : '?') . $param . '=' . urlencode((string)$apiKey);
        }

        $headers = $this->buildHeaders($providerCode, $apiInfo, (string)$apiKey);
        $proxyConfig = $account ? $account->getProxyConfig() : [];
        $timeout = (int)($apiInfo['timeout'] ?? 30);

        try {
            $response = $this->requestJson($url, $headers, $timeout, $proxyConfig);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('供应商 %{1} 获取模型失败: %{2}', [$providerCode, $e->getMessage()]),
            ];
        }

        $models = $this->parseModelsFromResponse($response, $apiInfo);
        if (empty($models) && $keepExisting && !empty($existingModels)) {
            $models = [];
        } elseif (empty($models)) {
            return [
                'success' => false,
                'message' => __('供应商 %{1} 返回模型列表为空', [$providerCode]),
            ];
        }

        $mergedModels = $this->mergeModels($existingModels, $models, $keepExisting);
        $updated = 0;
        $created = 0;
        $skipped = 0;

        $modelDefaults = $providerConfig['model_config_defaults'] ?? [];
        foreach ($mergedModels as $modelMeta) {
            $modelCode = (string)($modelMeta['code'] ?? '');
            if ($modelCode === '') {
                $skipped++;
                continue;
            }

            $modelConfig = $this->buildModelConfig($providerCode, $modelMeta, $providerConfig, $modelDefaults);
            if (!($options['dry_run'] ?? false)) {
                $result = $this->modelCollector->registerModelFromArray($modelConfig);
                if (!empty($result['model'])) {
                    if ($result['created']) {
                        $created++;
                    } else {
                        $updated++;
                    }
                } else {
                    $skipped++;
                }
            }
        }

        return [
            'success' => true,
            'count' => count($mergedModels),
            'updated' => $updated,
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * 使用已有模型完成同步（无API Key兜底）
     *
     * @param string $providerCode
     * @param array $providerConfig
     * @param array $existingModels
     * @param array $options
     * @return array
     */
    private function syncWithExistingModels(string $providerCode, array $providerConfig, array $existingModels, array $options): array
    {
        $mergedModels = $this->mergeModels($existingModels, [], true);
        $updated = 0;
        $created = 0;
        $skipped = 0;

        $modelDefaults = $providerConfig['model_config_defaults'] ?? [];
        foreach ($mergedModels as $modelMeta) {
            $modelCode = (string)($modelMeta['code'] ?? '');
            if ($modelCode === '') {
                $skipped++;
                continue;
            }
            $modelConfig = $this->buildModelConfig($providerCode, $modelMeta, $providerConfig, $modelDefaults);
            if (!($options['dry_run'] ?? false)) {
                $result = $this->modelCollector->registerModelFromArray($modelConfig);
                if (!empty($result['model'])) {
                    if ($result['created']) {
                        $created++;
                    } else {
                        $updated++;
                    }
                } else {
                    $skipped++;
                }
            }
        }

        return [
            'success' => true,
            'count' => count($mergedModels),
            'updated' => $updated,
            'created' => $created,
            'skipped' => $skipped,
            'message' => __('未配置API Key，使用已有模型列表完成同步'),
        ];
    }

    /**
     * 合并已有模型与API模型
     *
     * @param array $existing
     * @param array $apiModels
     * @return array
     */
    public function mergeModels(array $existing, array $apiModels, bool $keepExisting = true): array
    {
        $existingMap = [];
        foreach ($existing as $item) {
            $code = $item['code'] ?? '';
            if ($code !== '') {
                $existingMap[$code] = $item;
            }
        }

        $merged = [];
        foreach ($apiModels as $item) {
            $code = $item['code'] ?? '';
            if ($code === '') {
                continue;
            }
            $old = $existingMap[$code] ?? [];
            $merged[$code] = $this->mergeModelMeta($old, $item);
        }

        if ($keepExisting) {
            foreach ($existingMap as $code => $item) {
                if (!isset($merged[$code])) {
                    $merged[$code] = $item;
                }
            }
        }

        return array_values($merged);
    }

    /**
     * 合并单个模型元信息
     *
     * @param array $old
     * @param array $new
     * @return array
     */
    public function mergeModelMeta(array $old, array $new): array
    {
        $merged = $old;
        foreach ($new as $key => $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                $merged[$key] = $value;
            } elseif (!isset($merged[$key])) {
                $merged[$key] = $value;
            }
        }
        if (!isset($merged['code']) && isset($new['code'])) {
            $merged['code'] = $new['code'];
        }
        if (!isset($merged['name']) && isset($new['name'])) {
            $merged['name'] = $new['name'];
        }
        return $merged;
    }

    /**
     * 构建模型配置文件内容
     *
     * @param string $providerCode
     * @param array $modelMeta
     * @param array $providerConfig
     * @param array $defaults
     * @return array
     */
    public function buildModelConfig(string $providerCode, array $modelMeta, array $providerConfig, array $defaults = []): array
    {
        $modelCode = (string)($modelMeta['code'] ?? '');
        $modelName = (string)($modelMeta['name'] ?? $modelCode);
        $modelField = $providerConfig['model_field'] ?? 'model';
        $baseUrl = $providerConfig['base_url'] ?? '';
        $timeout = (int)($defaults['timeout'] ?? 180);

        $config = array_merge([
            'api_key' => '',
            'base_url' => $baseUrl,
            'max_tokens' => $modelMeta['max_tokens'] ?? ($defaults['max_tokens'] ?? 4096),
            'temperature' => $defaults['temperature'] ?? 0.7,
            'top_p' => $defaults['top_p'] ?? 1.0,
            'stream' => $defaults['stream'] ?? true,
            'timeout' => $timeout,
            'max_retries' => $defaults['max_retries'] ?? 3,
        ], $defaults['extra'] ?? []);

        $config[$modelField] = $modelCode;
        if (!isset($config['model']) && $modelField !== 'model') {
            $config['model'] = $modelCode;
        }
        if (!isset($config['model_id'])) {
            $config['model_id'] = $modelCode;
        }

        return [
            'vendor' => $providerCode,
            'model_code' => $modelCode,
            'model_name' => $modelName,
            'model_version' => $modelMeta['version'] ?? '1.0',
            'token_price_input' => $modelMeta['input_price'] ?? 0,
            'token_price_output' => $modelMeta['output_price'] ?? 0,
            'max_tokens' => $modelMeta['max_tokens'] ?? ($defaults['max_tokens'] ?? 4096),
            'is_active' => 0,
            'is_default' => 0,
            'capabilities' => $modelMeta['capabilities'] ?? ($defaults['capabilities'] ?? ['chat']),
            'config' => $config,
            'proxy_info' => [
                'enabled' => false,
                'host' => '',
                'port' => 0,
                'username' => '',
                'password' => '',
            ],
        ];
    }

    /**
     * 解析API响应中的模型列表
     *
     * @param array $response
     * @param array $apiInfo
     * @return array
     */
    private function parseModelsFromResponse(array $response, array $apiInfo): array
    {
        $dataKey = $apiInfo['data_key'] ?? 'data';
        $items = $response[$dataKey] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $idKey = $apiInfo['id_key'] ?? 'id';
        $nameKey = $apiInfo['name_key'] ?? 'name';
        $descKey = $apiInfo['desc_key'] ?? 'description';
        $contextKey = $apiInfo['context_key'] ?? 'context_window';
        $maxTokensKey = $apiInfo['max_tokens_key'] ?? 'max_tokens';
        $trimPrefix = $apiInfo['id_prefix_trim'] ?? '';

        $models = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $models[] = [
                    'code' => $item,
                    'name' => $item,
                ];
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $code = $item[$idKey] ?? $item['id'] ?? $item['model'] ?? '';
            if (!$code) {
                continue;
            }
            if ($trimPrefix && str_starts_with($code, $trimPrefix)) {
                $code = substr($code, strlen($trimPrefix));
            }
            $models[] = [
                'code' => $code,
                'name' => $item[$nameKey] ?? $item['display_name'] ?? $code,
                'description' => $item[$descKey] ?? '',
                'context_window' => $item[$contextKey] ?? null,
                'max_tokens' => $item[$maxTokensKey] ?? null,
                'capabilities' => $item['capabilities'] ?? [],
            ];
        }

        return $models;
    }

    /**
     * 请求模型列表
     *
     * @param string $url
     * @param array $headers
     * @param int $timeout
     * @param array $proxy
     * @return array
     * @throws \Exception
     */
    private function requestJson(string $url, array $headers, int $timeout, array $proxy = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(1, $timeout));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(max(1, $timeout), 60));

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }

        if (!empty($proxy['enabled'])) {
            $proxyStr = $proxy['host'] . ':' . $proxy['port'];
            curl_setopt($ch, CURLOPT_PROXY, $proxyStr);
            if (!empty($proxy['username']) && !empty($proxy['password'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['username'] . ':' . $proxy['password']);
            }
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            throw new \Exception($error ?: __('模型列表请求失败'));
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status < 200 || $status >= 300) {
            throw new \Exception(__('模型列表请求失败，HTTP状态码: %{1}', [$status]));
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(__('模型列表响应解析失败: %{1}', [json_last_error_msg()]));
        }

        return $decoded;
    }

    /**
     * 构建请求头
     *
     * @param string $providerCode
     * @param array $apiInfo
     * @param string $apiKey
     * @return array
     */
    private function buildHeaders(string $providerCode, array $apiInfo, string $apiKey): array
    {
        $headers = $apiInfo['headers'] ?? [];
        $authType = $apiInfo['auth_type'] ?? 'bearer';
        if ($authType === 'bearer' && $apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        if ($authType === 'anthropic' && $apiKey !== '') {
            $headers[] = 'x-api-key: ' . $apiKey;
            $headers[] = 'anthropic-version: ' . ($apiInfo['version'] ?? '2023-06-01');
        }
        $headers[] = 'Content-Type: application/json';

        return $headers;
    }

    /**
     * 获取API Key
     *
     * @param array $providerConfig
     * @param Account|null $account
     * @param array $options
     * @return string
     */
    private function resolveApiKey(array $providerConfig, ?Account $account, array $options = []): string
    {
        $apiKey = $options['api_key'] ?? '';
        if ($apiKey) {
            return $apiKey;
        }

        if ($account) {
            return $account->getDecryptedApiKey();
        }

        $envKey = $providerConfig['api_key_env'] ?? '';
        if ($envKey) {
            $envValue = getenv($envKey);
            return $envValue ?: '';
        }

        return '';
    }

    /**
     * 获取 base_url
     *
     * @param array $providerConfig
     * @param Account|null $account
     * @return string
     */
    private function resolveBaseUrl(array $providerConfig, ?Account $account): string
    {
        if ($account && $account->getData(Account::schema_fields_BASE_URL)) {
            return (string)$account->getData(Account::schema_fields_BASE_URL);
        }
        return (string)($providerConfig['base_url'] ?? '');
    }

    /**
     * 写入供应商配置文件
     *
     * @param string $providerCode
     * @param array $providerConfig
     * @param array $models
     * @return bool
     */
    private function writeVendorConfig(string $providerCode, array $providerConfig, array $models): bool
    {
        $path = VendorConfigManager::getProviderConfigPath($providerCode);
        if (!$path) {
            return false;
        }
        $providerConfig['models'] = $models;
        $providerConfig['models_updated_at'] = time();
        $json = json_encode($providerConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        return file_put_contents($path, $json) !== false;
    }

    /**
     * 构建模型配置文件名
     *
     * @param string $providerCode
     * @param string $modelCode
     * @return string
     */
    private function buildModelConfigFileName(string $providerCode, string $modelCode): string
    {
        $safeCode = preg_replace('/[^a-zA-Z0-9\-_\.]+/', '_', $modelCode);
        return $providerCode . '_' . $safeCode;
    }
}
