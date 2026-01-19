<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Helper\ErrorMessageHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;

/**
 * AI配置解析服务
 * 处理多层级配置优先级：
 * 1. 前端用户模型配置 (最高优先级)
 * 2. 前端用户供应商账户
 * 3. 后台模型关联配置
 * 4. 后台默认供应商账户 (最低优先级)
 */
class ConfigResolver
{
    /**
     * 解析AI模型配置
     *
     * @param string $modelCode 模型代码
     * @param array $userConfig 用户提供的配置
     * @param int|null $userId 用户ID (前端用户)
     * @param bool $isBackend 是否为后端调用
     * @return array 解析后的配置
     */
    public function resolveConfig(string $modelCode, array $userConfig = [], ?int $userId = null, bool $isBackend = false): array
    {
        $config = [];
        
        // 1. 获取模型信息
        // 测试场景（例如后台连通性测试）不强制要求模型为激活状态
        $isTestMode = (bool)($userConfig['test_mode'] ?? false);
        $model = $this->getModel($modelCode, $isTestMode);
        if (!$model->getId()) {
            throw new \Exception(__('模型不存在: %{code}', ['code' => $modelCode]));
        }
        // 2. 获取供应商代码
        $providerCode = $model->getSupplier();
        // 3. 按优先级解析配置
        if ($isBackend) {
            // 后端调用：使用后台配置
            $config = $this->resolveBackendConfig($model, $providerCode, $userConfig);
        } else {
            // 前端调用：使用前端用户配置
            $config = $this->resolveFrontendConfig($model, $providerCode, $userConfig, $userId);
        }
        
        // 4. 验证必要配置
        $this->validateConfig($config, $modelCode);
        
        return $config;
    }
    
    /**
     * 解析后端配置
     */
    private function resolveBackendConfig(AiModel $model, string $providerCode, array $userConfig): array
    {
        // 优先级0: 模型的基础配置（包含 timeout 等配置项）
        $config = $model->getConfig();
        
        // 优先级1: 用户提供的配置 (最高优先级)
        if (!empty($userConfig)) {
            $config = array_merge($config, $userConfig);
        }
        
        // 优先级2: 模型关联的配置
        $modelProviderConfig = $model->getProviderConfig();
        if (!empty($modelProviderConfig)) {
            $config = array_merge($config, $modelProviderConfig);
        }
        
        // 优先级3: 后台默认供应商账户
        $defaultAccount = $this->getDefaultProviderAccount($providerCode);
        if ($defaultAccount) {
            $accountConfig = $this->extractAccountConfig($defaultAccount);
            $config = array_merge($config, $accountConfig);
        }
        
        return $config;
    }
    
    /**
     * 解析前端配置
     */
    private function resolveFrontendConfig(AiModel $model, string $providerCode, array $userConfig, ?int $userId): array
    {
        // 优先级0: 模型的基础配置（包含 timeout 等配置项）
        $config = $model->getConfig();
        
        // 优先级1: 用户提供的配置 (最高优先级)
        if (!empty($userConfig)) {
            $config = array_merge($config, $userConfig);
            Env::log('ai_config.log', "使用用户提供的配置", 'DEBUG');
            return $config;
        }
        
        // 优先级2: 用户为模型配置的供应商账户
        if ($userId) {
            $userModelAccount = $this->getUserModelAccount($userId, $model->getId());
            if ($userModelAccount) {
                $accountConfig = $this->extractAccountConfig($userModelAccount);
                $config = array_merge($config, $accountConfig);
                Env::log('ai_config.log', "使用用户模型账户配置", 'DEBUG');
                return $config;
            }
        }
        
        // 优先级3: 模型关联的配置
        $modelProviderConfig = $model->getProviderConfig();
        if (!empty($modelProviderConfig)) {
            $config = array_merge($config, $modelProviderConfig);
            Env::log('ai_config.log', "使用模型关联配置", 'DEBUG');
        }
        
        // 优先级4: 后台默认供应商账户
        $defaultAccount = $this->getDefaultProviderAccount($providerCode);
        if ($defaultAccount) {
            $accountConfig = $this->extractAccountConfig($defaultAccount);
            $config = array_merge($config, $accountConfig);
            Env::log('ai_config.log', "使用后台默认供应商账户", 'DEBUG');
        }
        
        return $config;
    }
    
    /**
     * 获取模型信息
     */
    private function getModel(string $modelCode, bool $allowInactive = false): ?AiModel
    {
        /** @var AiModel $model */
        $model = ObjectManager::getInstance(AiModel::class);
        $model = $model->where(AiModel::fields_MODEL_CODE, $modelCode);
        if (!$allowInactive) {
            $model = $model->where(AiModel::fields_IS_ACTIVE, 1);
        }
        return $model->find()->fetch();
    }
    
    /**
     * 获取默认供应商账户
     */
    private function getDefaultProviderAccount(string $providerCode): ?Account
    {
        /** @var Account $account */
        $account = ObjectManager::getInstance(Account::class);
        return $account->where(Account::fields_PROVIDER_CODE, $providerCode)
                      ->where(Account::fields_IS_DEFAULT, 1)
                      ->where(Account::fields_IS_ACTIVE, 1) // 使用IS_ACTIVE字段
                      ->find()
                      ->fetch();
    }
    
    /**
     * 获取用户模型账户
     */
    private function getUserModelAccount(int $userId, int $modelId): ?Account
    {
        /** @var Account $account */
        $account = ObjectManager::getInstance(Account::class);
        return $account->where('user_id', $userId)
                      ->where('model_id', $modelId)
                      ->where(Account::fields_IS_ACTIVE, 1) // 使用IS_ACTIVE字段
                      ->find()
                      ->fetch();
    }
    
    /**
     * 从账户中提取配置
     */
    private function extractAccountConfig(Account $account): array
    {
        $config = [];
        
        // 基本配置
        $decryptedKey = method_exists($account, 'getDecryptedApiKey') ? $account->getDecryptedApiKey() : ($account->getData(Account::fields_API_KEY) ?? '');
        if (!empty($decryptedKey)) {
            $config['api_key'] = $decryptedKey;
        }
        
        if ($account->getBaseUrl()) {
            $config['base_url'] = $account->getBaseUrl();
        }
        
        // 扩展配置
        $extendedConfig = $account->getExtendedConfig();
        if (!empty($extendedConfig)) {
            $config = array_merge($config, $extendedConfig);
        }
        
        return $config;
    }
    
    /**
     * 验证配置
     */
    private function validateConfig(array $config, string $modelCode): void
    {
        if (empty($config['api_key'])) {
            $message = __('模型 %{code} 缺少API密钥配置', ['code' => $modelCode]);
            throw new \Exception(ErrorMessageHelper::getErrorMessageWithConfigLink($message, 'provider', ['model_code' => $modelCode]));
        }
        
        if (empty($config['base_url'])) {
            $message = __('模型 %{code} 缺少API基础URL配置', ['code' => $modelCode]);
            throw new \Exception(ErrorMessageHelper::getErrorMessageWithConfigLink($message, 'provider', ['model_code' => $modelCode]));
        }
    }
    
    /**
     * 检查用户余额（前端调用时）
     */
    public function checkUserBalance(int $userId, string $modelCode, int $estimatedTokens = 0): bool
    {
        // TODO: 实现用户余额检查逻辑
        // 这里需要根据您的用户余额系统来实现
        
        Env::log('ai_config.log', "检查用户 {$userId} 余额，预估消耗 {$estimatedTokens} tokens", 'DEBUG');
        
        // 临时返回true，实际实现需要查询用户余额
        return true;
    }
    
    /**
     * 记录使用情况（前端调用时）
     */
    public function recordUsage(int $userId, string $modelCode, int $inputTokens, int $outputTokens, float $cost): void
    {
        // TODO: 实现使用记录逻辑
        // 这里需要根据您的计费系统来实现
        
        Env::log('ai_config.log', "记录用户 {$userId} 使用情况: 输入 {$inputTokens} tokens, 输出 {$outputTokens} tokens, 费用 {$cost}", 'INFO');
    }
}
