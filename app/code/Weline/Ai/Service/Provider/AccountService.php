<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\ProviderInterface;

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
     * 支持的供应商列表
     */
    private const SUPPORTED_PROVIDERS = [
        'openai' => [
            'name' => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'models_prefix' => ['gpt-', 'text-', 'davinci', 'curie', 'babbage', 'ada'],
            'test_model' => 'gpt-3.5-turbo'
        ],
        'deepseek' => [
            'name' => 'DeepSeek',
            'base_url' => 'https://api.deepseek.com/v1',
            'models_prefix' => ['deepseek-'],
            'test_model' => 'deepseek-chat'
        ],
        'google' => [
            'name' => 'Google',
            'base_url' => 'https://generativelanguage.googleapis.com/v1',
            'models_prefix' => ['gemini-', 'bard-'],
            'test_model' => 'gemini-pro'
        ],
        'anthropic' => [
            'name' => 'Anthropic',
            'base_url' => 'https://api.anthropic.com/v1',
            'models_prefix' => ['claude-'],
            'test_model' => 'claude-2'
        ]
    ];

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
        return self::SUPPORTED_PROVIDERS;
    }

    /**
     * 根据模型代码获取供应商代码
     * 
     * @param string $modelCode
     * @return string|null
     */
    public function getProviderByModelCode(string $modelCode): ?string
    {
        $modelCode = strtolower($modelCode);
        
        foreach (self::SUPPORTED_PROVIDERS as $providerCode => $providerInfo) {
            foreach ($providerInfo['models_prefix'] as $prefix) {
                if (str_starts_with($modelCode, $prefix)) {
                    return $providerCode;
                }
            }
        }
        
        // 特殊处理一些模型
        if (str_contains($modelCode, 'openai')) {
            return 'openai';
        }
        
        return null;
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
            ->where(Account::fields_PROVIDER_CODE, $providerCode)
            ->where(Account::fields_IS_DEFAULT, 1)
            ->where(Account::fields_IS_ACTIVE, 1)
            ->where(Account::fields_CONNECTION_STATUS, Account::STATUS_SUCCESS)
            ->where(Account::fields_BALANCE . ' > 0')
            ->find()
            ->fetch();
            
        if ($defaultAccount->getId()) {
            return $defaultAccount;
        }
        
        // 如果没有默认账户，获取任意可用账户
        $availableAccount = $accountModel->clear()
            ->where(Account::fields_PROVIDER_CODE, $providerCode)
            ->where(Account::fields_IS_ACTIVE, 1)
            ->where(Account::fields_CONNECTION_STATUS, Account::STATUS_SUCCESS)
            ->where(Account::fields_BALANCE . ' > 0')
            ->order(Account::fields_BALANCE, 'DESC')
            ->find()
            ->fetch();
            
        return $availableAccount->getId() ? $availableAccount : null;
    }

    /**
     * 测试账户连通性
     * 
     * @param Account $account
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection(Account $account): array
    {
        try {
            $providerInfo = self::SUPPORTED_PROVIDERS[$account->getData(Account::fields_PROVIDER_CODE)] ?? null;
            if (!$providerInfo) {
                throw new Exception('不支持的供应商');
            }
            
            // 创建临时模型用于测试
            /** @var AiModel $testModel */
            $testModel = $this->objectManager->make(AiModel::class);
            $testModel->setData([
                AiModel::fields_SUPPLIER => $account->getData(Account::fields_PROVIDER_CODE),
                AiModel::fields_MODEL_CODE => $providerInfo['test_model'],
                AiModel::fields_CONFIG => json_encode([
                    'api_key' => $account->getDecryptedApiKey(),
                    'base_url' => $account->getData(Account::fields_BASE_URL) ?: $providerInfo['base_url']
                ])
            ]);
            
            // 设置代理配置
            $proxyConfig = $account->getProxyConfig();
            if (!empty($proxyConfig)) {
                $testModel->setData(AiModel::fields_PROXY_INFO, json_encode($proxyConfig));
            }
            
            // 获取对应的Provider
            $provider = $this->getProviderInstance($account->getData(Account::fields_PROVIDER_CODE));
            if (!$provider) {
                throw new Exception('无法创建供应商实例');
            }
            
            // 执行测试请求
            $result = $provider->generate($testModel, '请回复"OK"表示连接成功', [
                'max_tokens' => 10,
                'temperature' => 0
            ]);
            
            if (!empty($result['content'])) {
                // 更新连接状态
                $account->setData(Account::fields_CONNECTION_STATUS, Account::STATUS_SUCCESS);
                $account->setData(Account::fields_CONNECTION_TEST_TIME, time());
                $account->setData(Account::fields_CONNECTION_TEST_MESSAGE, '连接成功');
                $account->save();
                
                return [
                    'success' => true,
                    'message' => '连接测试成功'
                ];
            } else {
                throw new Exception('API响应为空');
            }
            
        } catch (\Exception $e) {
            // 更新连接状态
            $account->setData(Account::fields_CONNECTION_STATUS, Account::STATUS_FAILED);
            $account->setData(Account::fields_CONNECTION_TEST_TIME, time());
            $account->setData(Account::fields_CONNECTION_TEST_MESSAGE, $e->getMessage());
            $account->save();
            
            return [
                'success' => false,
                'message' => '连接测试失败: ' . $e->getMessage()
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
        $inputPrice = (float)$model->getData(AiModel::fields_TOKEN_PRICE_INPUT);
        $outputPrice = (float)$model->getData(AiModel::fields_TOKEN_PRICE_OUTPUT);
        
        $record->setData([
            UsageRecord::fields_ACCOUNT_ID => $account->getId(),
            UsageRecord::fields_PROVIDER_CODE => $account->getData(Account::fields_PROVIDER_CODE),
            UsageRecord::fields_MODEL_CODE => $model->getData(AiModel::fields_MODEL_CODE),
            UsageRecord::fields_MODEL_NAME => $model->getData(AiModel::fields_NAME),
            UsageRecord::fields_REQUEST_ID => $context['request_id'] ?? uniqid('req_'),
            UsageRecord::fields_USER_ID => $context['user_id'] ?? null,
            UsageRecord::fields_USER_NAME => $context['user_name'] ?? null,
            UsageRecord::fields_REQUEST_TYPE => $context['request_type'] ?? 'chat',
            UsageRecord::fields_PROMPT_TOKENS => $usage['prompt_tokens'] ?? 0,
            UsageRecord::fields_COMPLETION_TOKENS => $usage['completion_tokens'] ?? 0,
            UsageRecord::fields_TOTAL_TOKENS => $usage['total_tokens'] ?? 0,
            UsageRecord::fields_CURRENCY => $account->getData(Account::fields_CURRENCY),
            UsageRecord::fields_REQUEST_TIME => $context['request_time'] ?? null,
            UsageRecord::fields_STATUS => $context['status'] ?? 'success',
            UsageRecord::fields_ERROR_MESSAGE => $context['error_message'] ?? null,
            UsageRecord::fields_CREATED_AT => time()
        ]);
        
        // 计算费用
        $record->calculateCost($inputPrice, $outputPrice);
        $record->save();
        
        // 更新账户余额
        if ($record->getData(UsageRecord::fields_STATUS) === 'success') {
            $account->updateBalance((float)$record->getData(UsageRecord::fields_TOTAL_COST));
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
    private function getProviderInstance(string $providerCode): ?ProviderInterface
    {
        // 目前所有供应商都使用OpenAI兼容的API
        // 后续可以根据providerCode返回不同的实现
        try {
            return $this->objectManager->make(OpenAiProvider::class);
        } catch (\Exception $e) {
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
        $providerCode = $account->getData(Account::fields_PROVIDER_CODE);
        
        // 先取消该供应商的所有默认账户
        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        $accounts = $accountModel->where(Account::fields_PROVIDER_CODE, $providerCode)
            ->where(Account::fields_IS_DEFAULT, 1)
            ->select();
            
        foreach ($accounts as $acc) {
            $acc->setData(Account::fields_IS_DEFAULT, 0)->save();
        }
        
        // 设置新的默认账户
        $account->setData(Account::fields_IS_DEFAULT, 1)->save();
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
        
        return $accountModel->where(Account::fields_PROVIDER_CODE, $providerCode)
            ->order(Account::fields_IS_DEFAULT, 'DESC')
            ->order(Account::fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchOrigin();
    }
}
