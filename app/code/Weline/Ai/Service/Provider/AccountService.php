<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\ProviderInterface;
use Weline\Ai\Service\Provider\VendorConfigManager;
use Weline\Ai\Service\Provider\OpenAiProvider;
use Weline\Ai\Service\Provider\AnthropicProvider;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Provider Account Service
 * 
 * 管理AI供应商账户的核心服务
 * 
 * @package Weline_Ai
 */
class AccountService
{
    /**
     * @var ObjectManager
     */
    private ObjectManager $objectManager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->objectManager = ObjectManager::getInstance();
    }

    /**
     * 获取支持的供应商列表
     * 
     * @return array
     */
    public function getSupportedProviders(): array
    {
        return VendorConfigManager::getSupportedProviders();
    }

    /**
     * 根据模型代码获取供应商代码
     * 
     * @param string $modelCode
     * @return string|null
     */
    public function getProviderByModelCode(string $modelCode): ?string
    {
        /** @var AiModel $model */
        $model = $this->objectManager->make(AiModel::class)
            ->reset()
            ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
            ->find()
            ->fetch();
        if ($model && $model->getId()) {
            $supplier = (string)$model->getData(AiModel::schema_fields_SUPPLIER);
            if ($supplier !== '') {
                return $supplier;
            }
        }
        return VendorConfigManager::getProviderByModelCode($modelCode);
    }

    /**
     * 校验供应商是否支持指定模型代码
     * 优先使用 vendor 配置中的 models 列表；若无配置则从账户缓存的 supported_models 判断。
     */
    public function supportsModel(string $providerCode, string $modelCode): bool
    {
        $providerModels = VendorConfigManager::getProviderModels($providerCode);
        if (is_array($providerModels) && !empty($providerModels)) {
            foreach ($providerModels as $item) {
                $code = is_array($item) ? (string)($item['code'] ?? '') : (string)$item;
                if ($code === $modelCode) {
                    return true;
                }
            }
            return false;
        }

        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        $accounts = $accountModel->reset()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->select()
            ->fetch()
            ->getItems();
        foreach ($accounts as $account) {
            $cfg = $account->getConfig();
            $supported = $cfg['supported_models'] ?? [];
            if (is_array($supported) && in_array($modelCode, $supported, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * OpenAI 兼容模式：从供应商账户直接拉取支持模型，并缓存到账户 config.supported_models
     * 返回拉取到的模型 code 列表。
     */
    public function refreshSupportedModels(Account $account): array
    {
        $apiKey = $account->getDecryptedApiKey();
        if ($apiKey === '') {
            return [];
        }
        $baseUrl = (string)$account->getData(Account::schema_fields_BASE_URL);
        if ($baseUrl === '') {
            return [];
        }

        $url = rtrim($baseUrl, '/') . '/models';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $resp = $this->executeCurl($ch);
        if ($resp === false) {
            curl_close($ch);
            return [];
        }
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http < 200 || $http >= 300) {
            return [];
        }

        $json = json_decode($resp, true);
        $rows = $json['data'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $codes = [];
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['id'])) {
                $codes[] = (string)$row['id'];
            }
        }
        $codes = array_values(array_unique(array_filter($codes)));

        $cfg = $account->getConfig();
        $cfg['supported_models'] = $codes;
        $cfg['models_synced_at'] = time();
        $account->setData(Account::schema_fields_CONFIG, json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $account->setData(Account::schema_fields_UPDATED_AT, time());
        $account->save();

        return $codes;
    }

    private function executeCurl(\CurlHandle $ch): string|bool
    {
        if (!SchedulerSystem::isSchedulerActive() || !\Fiber::getCurrent()) {
            return curl_exec($ch);
        }

        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $ch);

        $running = 0;
        $multiResult = \CURLM_OK;
        $curlResult = \CURLE_OK;

        do {
            do {
                $multiResult = curl_multi_exec($multi, $running);
            } while ($multiResult === \CURLM_CALL_MULTI_PERFORM);

            while ($info = curl_multi_info_read($multi)) {
                if (($info['handle'] ?? null) === $ch) {
                    $curlResult = (int)($info['result'] ?? \CURLE_OK);
                }
            }

            if ($multiResult !== \CURLM_OK || $curlResult !== \CURLE_OK) {
                break;
            }

            if ($running > 0) {
                SchedulerSystem::yieldDelay(10);
            }
        } while ($running > 0);

        $content = curl_multi_getcontent($ch);
        curl_multi_remove_handle($multi, $ch);
        curl_multi_close($multi);

        if ($multiResult !== \CURLM_OK || $curlResult !== \CURLE_OK) {
            return false;
        }

        return $content;
    }

    /**
     * 获取指定供应商的可用账户
     * 
     * @param string $providerCode
     * @return Account|null
     */
    public function getAvailableAccount(string $providerCode): ?Account
    {
        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        
        // 首先尝试获取默认账户
        $defaultAccount = $accountModel->clear()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_DEFAULT, 1)
            ->where(Account::schema_fields_IS_ACTIVE, 1)
            ->where(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS)
            ->where(Account::schema_fields_BALANCE, 0, '>')
            ->find()
            ->fetch();
            
        if ($defaultAccount->getId()) {
            return $defaultAccount;
        }
        
        // 如果没有默认账户，获取任意可用账户
        $availableAccount = $accountModel->clear()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_ACTIVE, 1)
            ->where(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS)
            ->where(Account::schema_fields_BALANCE, 0, '>')
            ->order(Account::schema_fields_BALANCE, 'DESC')
            ->find()
            ->fetch();
            
        return $availableAccount->getId() ? $availableAccount : null;
    }

    /**
     * 测试账户连通性
     * 
     * @param Account $account
     * @return array ['success' => bool, 'message' => string, 'model_code' => string]
     */
    public function testConnection(Account $account): array
    {
        try {
            $providerCode = $account->getData(Account::schema_fields_PROVIDER_CODE);
            $providerInfo = VendorConfigManager::getProviderConfig($providerCode);
            if (!$providerInfo) {
                throw new Exception(__('不支持的供应商: %{provider}', ['provider' => $providerCode]));
            }
            
            $testModelCode = $providerInfo['test_model'];
            $apiKeyPlain = $account->getDecryptedApiKey();
            $apiKeyTail = $apiKeyPlain ? substr($apiKeyPlain, -4) : '';
            $baseUrl = $account->getData(Account::schema_fields_BASE_URL) ?: ($providerInfo['base_url'] ?? '');
            Env::log('ai_provider_test.log', sprintf('[testConnection] account_id=%s provider=%s model=%s base_url=%s api_key_tail=%s',
                (string)$account->getId(), $providerCode, $testModelCode, $baseUrl, $apiKeyTail
            ));
            
            // 创建临时模型用于测试
            /** @var AiModel $testModel */
            $testModel = $this->objectManager->make(AiModel::class);
            $testModel->setData([
                AiModel::schema_fields_SUPPLIER => $providerCode,
                AiModel::schema_fields_MODEL_CODE => $testModelCode,
                AiModel::schema_fields_CONFIG => json_encode([
                    'api_key' => $apiKeyPlain,
                    'base_url' => $baseUrl,
                    'model' => $testModelCode  // 使用model字段而不是model_id
                ])
            ]);
            
            // 设置代理配置
            $proxyConfig = $account->getProxyConfig();
            if (!empty($proxyConfig)) {
                $testModel->setData(AiModel::schema_fields_PROXY_INFO, json_encode($proxyConfig));
            }
            
            // 获取对应的Provider
            $provider = $this->getProviderInstance($account->getData(Account::schema_fields_PROVIDER_CODE));
            if (!$provider) {
                throw new Exception(__('无法创建供应商实例'));
            }
            
            // 执行测试请求
            $result = $provider->generate($testModel, '请回复"OK"表示连接成功', [
                'max_tokens' => 10,
                'temperature' => 0,
                'test_mode' => true
            ]);
            
            if (!empty($result['content'])) {
                // 更新连接状态
                $account->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS);
                $account->setData(Account::schema_fields_CONNECTION_TEST_TIME, time());
                $account->setData(Account::schema_fields_CONNECTION_TEST_MESSAGE, __('连接成功'));
                $account->setData(Account::schema_fields_UPDATED_AT, time());
                
                // 保存并记录日志
                $saveResult = $account->save();
                Env::log('ai_provider_test.log', sprintf('[testConnection][success] account_id=%s provider=%s status=%s save_result=%s', 
                    (string)$account->getId(), 
                    $providerCode, 
                    Account::STATUS_SUCCESS,
                    $saveResult ? 'true' : 'false'
                ));
                
                // 验证保存是否成功
                $account->reset()->load($account->getId());
                $actualStatus = $account->getData(Account::schema_fields_CONNECTION_STATUS);
                if ($actualStatus !== Account::STATUS_SUCCESS) {
                    Env::log('ai_provider_test.log', sprintf('[testConnection][warning] account_id=%s status_not_saved expected=%s actual=%s', 
                        (string)$account->getId(), 
                        Account::STATUS_SUCCESS,
                        $actualStatus
                    ));
                }
                
                return [
                    'success' => true,
                    'message' => __('连接测试成功'),
                    'model_code' => $testModelCode,
                    'account_id' => $account->getId(),
                    'provider' => $providerCode,
                    'base_url' => $baseUrl,
                    'api_key_tail' => $apiKeyTail,
                    'connection_status' => Account::STATUS_SUCCESS,
                    'connection_test_time' => time(),
                    'connection_test_message' => __('连接成功')
                ];
            } else {
                throw new Exception(__('API响应为空'));
            }
            
        } catch (\Exception $e) {
            Env::log('ai_provider_test.log', sprintf('[testConnection][error] account_id=%s provider=%s error=%s',
                (string)$account->getId(), (string)$account->getData(Account::schema_fields_PROVIDER_CODE), $e->getMessage()
            ));
            // 更新连接状态
            $account->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_FAILED);
            $account->setData(Account::schema_fields_CONNECTION_TEST_TIME, time());
            $account->setData(Account::schema_fields_CONNECTION_TEST_MESSAGE, $e->getMessage());
            $account->save();
            
            return [
                'success' => false,
                'message' => __('连接测试失败: %{msg}', ['msg' => $e->getMessage()]),
                'model_code' => $testModelCode ?? 'unknown',
                'account_id' => $account->getId(),
                'provider' => $account->getData(Account::schema_fields_PROVIDER_CODE),
                'base_url' => $account->getData(Account::schema_fields_BASE_URL) ?: ($providerInfo['base_url'] ?? ''),
                'api_key_tail' => $apiKeyTail ?? ''
            ];
        }
    }

    /**
     * 记录使用情况
     * 
     * @param Account $account
     * @param AiModel $model
     * @param array $usage
     * @param array $context
     * @return UsageRecord
     */
    public function recordUsage(Account $account, AiModel $model, array $usage, array $context = []): UsageRecord
    {
        /** @var UsageRecord $record */
        $record = $this->objectManager->make(UsageRecord::class);
        
        // 计算费用
        $inputPrice = (float)$model->getData(AiModel::schema_fields_TOKEN_PRICE_INPUT);
        $outputPrice = (float)$model->getData(AiModel::schema_fields_TOKEN_PRICE_OUTPUT);
        
        $record->setData([
            UsageRecord::schema_fields_ACCOUNT_ID => $account->getId(),
            UsageRecord::schema_fields_PROVIDER_CODE => $account->getData(Account::schema_fields_PROVIDER_CODE),
            UsageRecord::schema_fields_MODEL_CODE => $model->getData(AiModel::schema_fields_MODEL_CODE),
            UsageRecord::schema_fields_MODEL_NAME => $model->getData(AiModel::schema_fields_NAME),
            UsageRecord::schema_fields_REQUEST_ID => $context['request_id'] ?? uniqid('req_'),
            UsageRecord::schema_fields_USER_ID => $context['user_id'] ?? null,
            UsageRecord::schema_fields_USER_NAME => $context['user_name'] ?? null,
            UsageRecord::schema_fields_REQUEST_TYPE => $context['request_type'] ?? 'chat',
            UsageRecord::schema_fields_PROMPT_TOKENS => $usage['prompt_tokens'] ?? 0,
            UsageRecord::schema_fields_COMPLETION_TOKENS => $usage['completion_tokens'] ?? 0,
            UsageRecord::schema_fields_TOTAL_TOKENS => $usage['total_tokens'] ?? 0,
            UsageRecord::schema_fields_CURRENCY => $account->getData(Account::schema_fields_CURRENCY),
            UsageRecord::schema_fields_REQUEST_TIME => $context['request_time'] ?? null,
            UsageRecord::schema_fields_STATUS => $context['status'] ?? 'success',
            UsageRecord::schema_fields_ERROR_MESSAGE => $context['error_message'] ?? null,
            UsageRecord::schema_fields_CREATED_AT => time()
        ]);
        
        // 计算费用
        $record->calculateCost($inputPrice, $outputPrice);
        $record->save();
        
        // 更新账户余额
        if ($record->getData(UsageRecord::schema_fields_STATUS) === 'success') {
            $account->updateBalance((float)$record->getData(UsageRecord::schema_fields_TOTAL_COST));
            $account->save();
        }
        
        return $record;
    }

    /**
     * 获取Provider实例
     * 
     * @param string $providerCode
     * @return ProviderInterface|null
     */
    public function getProviderInstance(string $providerCode): ?ProviderInterface
    {
        try {
            // 根据供应商代码返回对应的Provider实例
            $providerClass = match ($providerCode) {
                'anthropic' => AnthropicProvider::class,
                'openai', 'deepseek' => OpenAiProvider::class,
                default => OpenAiProvider::class, // 默认使用OpenAI兼容的Provider
            };
            
            $provider = $this->objectManager->make($providerClass);
            if (!$provider instanceof ProviderInterface) {
                Env::log('ai_provider_test.log', sprintf('[getProviderInstance][error] provider=%s error=返回的对象不是ProviderInterface实例', $providerCode));
                return null;
            }
            return $provider;
        } catch (\Exception $e) {
            Env::log('ai_provider_test.log', sprintf('[getProviderInstance][error] provider=%s error=%s trace=%s', 
                $providerCode, 
                $e->getMessage(), 
                $e->getTraceAsString()
            ));
            return null;
        } catch (\Throwable $e) {
            Env::log('ai_provider_test.log', sprintf('[getProviderInstance][fatal] provider=%s error=%s trace=%s', 
                $providerCode, 
                $e->getMessage(), 
                $e->getTraceAsString()
            ));
            return null;
        }
    }

    /**
     * 设置默认账户
     * 
     * @param Account $account
     * @return void
     */
    public function setDefaultAccount(Account $account): void
    {
        $providerCode = $account->getData(Account::schema_fields_PROVIDER_CODE);
        
        // 先取消该供应商的所有默认账户（使用批量更新避免迭代对象类型不一致）
        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        $accountModel->reset()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_DEFAULT, 1)
            ->update([Account::schema_fields_IS_DEFAULT => 0])
            ->fetch();
        
        // 设置新的默认账户
        $account->setData(Account::schema_fields_IS_DEFAULT, 1)->save();
    }

    /**
     * 获取供应商的所有账户
     * 
     * @param string $providerCode
     * @return array
     */
    public function getProviderAccounts(string $providerCode): array
    {
        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        
        $rows = $accountModel->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->order(Account::schema_fields_IS_DEFAULT, 'DESC')
            ->order(Account::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();
        return is_array($rows) ? $rows : [];
    }

    /**
     * 获取同步模型列表用的可用账户（仅要求激活）
     *
     * @param string $providerCode
     * @return Account|null
     */
    public function getActiveAccountForSync(string $providerCode): ?Account
    {
        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        $account = $accountModel->reset()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_ACTIVE, 1)
            ->order(Account::schema_fields_IS_DEFAULT, 'DESC')
            ->order(Account::schema_fields_CREATED_AT, 'DESC')
            ->find()
            ->fetch();

        return $account && $account->getId() ? $account : null;
    }

    /**
     * 获取指定供应商支持的模型列表
     * 
     * @param string $providerCode
     * @return array
     */
    public function getProviderModels(string $providerCode): array
    {
        return VendorConfigManager::getProviderModels($providerCode);
    }

    /**
     * 获取所有供应商及其支持的模型列表
     * 
     * @return array
     */
    public function getAllProvidersWithModels(): array
    {
        return VendorConfigManager::getAllProvidersWithModels();
    }
}
