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
     * @param AiModel|null $existingModel 已存在的模型实例（已注入配置），避免重新从数据库加载
     * @return array 解析后的配置
     */
    public function resolveConfig(string $modelCode, array $userConfig = [], ?int $userId = null, bool $isBackend = false, ?AiModel $existingModel = null): array
    {
        $config = [];
        
        // 1. 获取模型信息
        // 如果传入了已存在的模型实例（已注入账户配置），直接使用
        if ($existingModel !== null && $existingModel->getId()) {
            $model = $existingModel;
        } else {
            // 测试场景（例如后台连通性测试）不强制要求模型为激活状态
            $isTestMode = (bool)($userConfig['test_mode'] ?? false);
            $model = $this->getModel($modelCode, $isTestMode);
            if (!$model->getId()) {
                throw new \Exception(__('模型不存在: %{code}', ['code' => $modelCode]));
            }
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
     * 
     * 优先级（从低到高）：
     * 1. 模型基础配置
     * 2. 后台默认供应商账户（api_key、base_url 等）
     * 3. 模型关联的 provider_config
     * 4. 用户提供的配置（最高优先级）
     * 
     * array_merge 中后面的值会覆盖前面的值，所以低优先级的先合并
     */
    private function resolveBackendConfig(AiModel $model, string $providerCode, array $userConfig): array
    {
        // 优先级1（最低）: 模型的基础配置
        // 过滤空值，避免后续 array_merge 时用空字符串覆盖有效值
        $config = $this->filterEmptyValues($model->getConfig());
        
        // 优先级2: 供应商账户（指定 account_id 或默认账户）
        $modelProviderConfig = $model->getProviderConfig();
        $accountId = isset($modelProviderConfig['account_id']) ? (int)$modelProviderConfig['account_id'] : 0;
        $account = null;
        if ($accountId > 0) {
            $account = $this->getAccountById($accountId);
        }
        if (!$account || !$account->getId()) {
            $account = $this->getDefaultProviderAccount($providerCode, (bool)($userConfig['allow_zero_balance_provider'] ?? false));
        }
        if ($account && $account->getId()) {
            $config = array_merge($config, $this->extractAccountConfig($account));
        }
        
        // 优先级3: 模型关联的 provider_config 覆盖（排除 account_id，仅用于展示）
        if (!empty($modelProviderConfig)) {
            $overrides = $modelProviderConfig;
            unset($overrides['account_id']);
            if ($account && $account->getId()) {
                unset($overrides['api_key'], $overrides['base_url'], $overrides['api_url'], $overrides['image_api_url'], $overrides['proxy']);
            }
            $config = array_merge($config, $this->filterEmptyValues($overrides));
        }
        
        // 优先级4（最高）: 用户提供的配置
        if (!empty($userConfig)) {
            $config = array_merge($config, $userConfig);
        }
        
        return $config;
    }
    
    /**
     * 解析前端配置
     * 
     * 优先级（从低到高）：
     * 1. 模型基础配置
     * 2. 后台默认供应商账户
     * 3. 模型关联的 provider_config
     * 4. 用户模型账户
     * 5. 用户提供的配置（最高优先级）
     */
    private function resolveFrontendConfig(AiModel $model, string $providerCode, array $userConfig, ?int $userId): array
    {
        // 优先级1（最低）: 模型的基础配置
        $config = $this->filterEmptyValues($model->getConfig());
        
        // 优先级2: 供应商账户（指定 account_id 或默认）
        $modelProviderConfig = $model->getProviderConfig();
        $accountId = isset($modelProviderConfig['account_id']) ? (int)$modelProviderConfig['account_id'] : 0;
        $account = ($accountId > 0) ? $this->getAccountById($accountId) : null;
        if (!$account || !$account->getId()) {
            $account = $this->getDefaultProviderAccount($providerCode, (bool)($userConfig['allow_zero_balance_provider'] ?? false));
        }
        if ($account && $account->getId()) {
            $config = array_merge($config, $this->extractAccountConfig($account));
        }
        
        // 优先级3: 模型关联的配置覆盖（排除 account_id）
        if (!empty($modelProviderConfig)) {
            $overrides = $modelProviderConfig;
            unset($overrides['account_id']);
            if ($account && $account->getId()) {
                unset($overrides['api_key'], $overrides['base_url'], $overrides['api_url'], $overrides['image_api_url'], $overrides['proxy']);
            }
            $config = array_merge($config, $this->filterEmptyValues($overrides));
        }
        
        // 优先级4: 用户为模型配置的供应商账户
        if ($userId) {
            $userModelAccount = $this->getUserModelAccount($userId, $model->getId());
            if ($userModelAccount && $userModelAccount->getId()) {
                $config = array_merge($config, $this->extractAccountConfig($userModelAccount));
            }
        }
        
        // 优先级5（最高）: 用户提供的配置
        if (!empty($userConfig)) {
            $config = array_merge($config, $userConfig);
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
        $model = $model->where(AiModel::schema_fields_MODEL_CODE, $modelCode);
        if (!$allowInactive) {
            $model = $model->where(AiModel::schema_fields_IS_ACTIVE, 1);
        }
        return $model->find()->fetch();
    }
    
    /**
     * 根据 ID 获取供应商账户
     */
    private function getAccountById(int $id): ?Account
    {
        /** @var Account $account */
        $account = ObjectManager::getInstance(Account::class)->load($id);
        return ($account->getId() && $account->getData(Account::schema_fields_IS_ACTIVE)) ? $account : null;
    }

    /**
     * 获取默认供应商账户
     * 
     * 首先尝试获取默认账户，如果没有则获取任意可用账户
     * 可用条件：激活 + 连接成功；默认要求余额 > 0，显式 allowZeroBalance 时放宽余额门禁。
     */
    private function getDefaultProviderAccount(string $providerCode, bool $allowZeroBalance = false): ?Account
    {
        /** @var Account $account */
        $account = ObjectManager::getInstance(Account::class);
        
        // 首先尝试获取默认且可用的账户
        $defaultAccount = $account->reset()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_DEFAULT, 1)
            ->where(Account::schema_fields_IS_ACTIVE, 1)
            ->where(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS);
        if (!$allowZeroBalance) {
            $defaultAccount->where(Account::schema_fields_BALANCE, 0, '>');
        }
        $defaultAccount = $defaultAccount->find()->fetch();
        
        if ($defaultAccount && $defaultAccount->getId()) {
            return $defaultAccount;
        }
        
        // 如果没有默认账户，获取任意可用账户
        $availableAccount = $account->reset()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_ACTIVE, 1)
            ->where(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS);
        if (!$allowZeroBalance) {
            $availableAccount->where(Account::schema_fields_BALANCE, 0, '>');
        }
        $availableAccount = $availableAccount
            ->order(Account::schema_fields_BALANCE, 'DESC')
            ->find()
            ->fetch();
        
        return ($availableAccount && $availableAccount->getId()) ? $availableAccount : null;
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
                      ->where(Account::schema_fields_IS_ACTIVE, 1) // 使用IS_ACTIVE字段
                      ->find()
                      ->fetch();
    }
    
    /**
     * 从账户中提取配置
     */
    private function extractAccountConfig(Account $account): array
    {
        $config = [];
        
        // API 密钥
        $decryptedKey = $account->getDecryptedApiKey();
        if (!empty($decryptedKey)) {
            $config['api_key'] = $decryptedKey;
        }
        
        // API 基础 URL
        $baseUrl = $account->getData(Account::schema_fields_BASE_URL);
        if (!empty($baseUrl)) {
            $config['base_url'] = $baseUrl;
        }
        
        // API Secret（如有）
        $apiSecret = $account->getData(Account::schema_fields_API_SECRET);
        if (!empty($apiSecret)) {
            $config['api_secret'] = $apiSecret;
        }
        
        // 代理配置
        $proxyConfig = $account->getData(Account::schema_fields_PROXY_CONFIG);
        if (!empty($proxyConfig)) {
            $proxyData = is_string($proxyConfig) ? json_decode($proxyConfig, true) : $proxyConfig;
            if (is_array($proxyData) && !empty($proxyData['enabled'])) {
                $config['proxy'] = $proxyData;
            }
        }
        
        // 额外配置（从 config 字段）
        $extraConfig = $account->getData(Account::schema_fields_CONFIG);
        if (!empty($extraConfig)) {
            $extraData = is_string($extraConfig) ? json_decode($extraConfig, true) : $extraConfig;
            if (is_array($extraData)) {
                $config = array_merge($config, $extraData);
            }
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
     * 过滤掉空值（空字符串和 null）
     * 用于 array_merge 前过滤配置，避免用空值覆盖有效值
     */
    private function filterEmptyValues(array $config): array
    {
        return array_filter($config, function ($value) {
            return $value !== '' && $value !== null;
        });
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
