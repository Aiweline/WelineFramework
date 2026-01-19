<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiUsageLog;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Service\DefaultModelManager;
use Weline\Ai\Service\AdapterScanner;
use Weline\Ai\Service\I18nIntegration;
use Weline\Ai\Service\Provider\ProviderFactory;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Ai\Service\ConfigResolver;
use Weline\Ai\Helper\ErrorMessageHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Exception;

/**
 * AI服务核心类
 * 
 * 功能：
 * - 提供统一的AI服务接口
 * - 支持多种服务模式（API和PHP静态方法）
 * - 集成场景适配器系统
 * - 支持模型选择和回退机制
 * - 提供多语言支持
 */
class AiService
{
    /**
     * 服务模式：API接口模式
     */
    public const MODE_API = 'api';
    
    /**
     * 服务模式：PHP服务模式
     */
    public const MODE_PHP = 'php';

    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * @var DefaultModelManager
     */
    private DefaultModelManager $defaultModelManager;

    /**
     * @var AdapterScanner
     */
    private AdapterScanner $adapterScanner;

    /**
     * @var I18nIntegration
     */
    private I18nIntegration $i18nIntegration;

    /**
     * @var ProviderFactory
     */
    private ProviderFactory $providerFactory;

    /**
     * @var AiUsageLog
     */
    private AiUsageLog $usageLog;

    /**
     * @var AccountService
     */
    private AccountService $accountService;

    /**
     * 构造函数
     * 
     * @param AiModel $aiModel
     * @param DefaultModelManager $defaultModelManager
     * @param AdapterScanner $adapterScanner
     * @param I18nIntegration $i18nIntegration
     * @param ProviderFactory $providerFactory
     * @param AiUsageLog $usageLog
     * @param AccountService $accountService
     */
    public function __construct(
        AiModel $aiModel,
        DefaultModelManager $defaultModelManager,
        AdapterScanner $adapterScanner,
        I18nIntegration $i18nIntegration,
        ProviderFactory $providerFactory,
        AiUsageLog $usageLog,
        AccountService $accountService
    ) {
        $this->aiModel = $aiModel;
        $this->defaultModelManager = $defaultModelManager;
        $this->adapterScanner = $adapterScanner;
        $this->i18nIntegration = $i18nIntegration;
        $this->providerFactory = $providerFactory;
        $this->usageLog = $usageLog;
        $this->accountService = $accountService;
    }

    /**
     * 生成文本内容（静态方法 - PHP服务模式）
     * 
     * @param string $prompt 提示词
     * @param string|null $modelCode 指定模型代码
     * @param string|null $scenarioCode 场景代码
     * @param string|null $locale 语言代码
     * @param array $params 额外参数
     * @return string
     * @throws Exception
     */
    public static function generateText(
        string $prompt, 
        ?string $modelCode = null, 
        ?string $scenarioCode = null, 
        ?string $locale = null,
        array $params = []
    ): string {
        /** @var AiService $instance */
        $instance = ObjectManager::getInstance()->get(self::class);
        return $instance->generate($prompt, $modelCode, $scenarioCode, $locale, $params);
    }

    /**
     * 流式生成文本内容（静态方法 - PHP服务模式）
     * 
     * @param string $prompt 提示词
     * @param callable $callback 回调函数
     * @param string|null $modelCode 指定模型代码
     * @param string|null $scenarioCode 场景代码
     * @param string|null $locale 语言代码
     * @param array $params 额外参数
     * @return void
     * @throws Exception
     */
    public static function generateTextStream(
        string $prompt, 
        callable $callback, 
        ?string $modelCode = null, 
        ?string $scenarioCode = null, 
        ?string $locale = null,
        array $params = []
    ): void {
        /** @var AiService $instance */
        $instance = ObjectManager::getInstance()->get(self::class);
        $instance->generateStream($prompt, $callback, $modelCode, $scenarioCode, $locale, $params);
    }

    /**
     * 生成文本内容（实例方法）
     * 
     * @param string $prompt 提示词
     * @param string|null $modelCode 指定模型代码
     * @param string|null $scenarioCode 场景代码
     * @param string|null $locale 语言代码
     * @param array $params 额外参数
     * @return string
     * @throws Exception
     */
    public function generate(
        string $prompt, 
        ?string $modelCode = null, 
        ?string $scenarioCode = null, 
        ?string $locale = null,
        array $params = [],
        ?int $userId = null,
        bool $isBackend = false
    ): string {
        // 1. 模型选择
        $model = $this->selectModel($modelCode, $scenarioCode);
        if (!$model) {
            $reason = $this->getModelSelectionFailureReason($modelCode, $scenarioCode);
            throw new Exception($reason);
        }

        // 2. 配置解析
        $configResolver = ObjectManager::getInstance(ConfigResolver::class);
        $userConfig = $params['user_config'] ?? [];
        // 解析测试模式（多来源并规范化）：
        // - 顶层 params['test_mode']
        // - user_config['test_mode']
        // 支持 true/false、'1'/'0'、'true'/'false'
        $isTestMode = false;
        if (array_key_exists('test_mode', $params)) {
            $isTestMode = in_array($params['test_mode'], [true, 1, '1', 'true', 'TRUE'], true);
        } elseif (isset($userConfig['test_mode'])) {
            $isTestMode = in_array($userConfig['test_mode'], [true, 1, '1', 'true', 'TRUE'], true);
        }
        if ($isTestMode) {
            $userConfig['test_mode'] = true;
        } else {
            // 避免脏值残留
            if (isset($userConfig['test_mode'])) {
                unset($userConfig['test_mode']);
            }
        }
        $resolvedConfig = $configResolver->resolveConfig(
            $model->getModelCode(), 
            $userConfig, 
            $userId, 
            $isBackend
        );

        // 3. 前端用户余额检查
        if (!$isBackend && $userId) {
            $estimatedTokens = $this->estimateTokens($prompt);
            if (!$configResolver->checkUserBalance($userId, $model->getModelCode(), $estimatedTokens)) {
                throw new Exception('用户余额不足，请充值后使用');
            }
        }

        // 4. 场景适配器处理
        $adaptedPrompt = $this->applyScenarioAdapter($prompt, $scenarioCode, $params);

        // 5. 语言验证
        if ($locale && !$this->i18nIntegration->isLocaleSupported($locale)) {
            throw new Exception("不支持的语言: {$locale}");
        }

        // 6. 调用AI模型API
        $response = $this->callModelApi($model, $adaptedPrompt, $resolvedConfig, $params);

        // 7. 场景适配器后处理
        $processedResponse = $this->processScenarioResponse($response, $scenarioCode, $params);

        // 8. 语言处理
        if ($locale) {
            $processedResponse = $this->processLanguageResponse($processedResponse, $locale);
        }

        // 9. 记录使用情况
        $this->recordUsage($model, $adaptedPrompt, $processedResponse, $userId, $isBackend);

        return $processedResponse;
    }

    /**
     * 流式生成文本内容（实例方法）
     * 
     * @param string $prompt 提示词
     * @param callable $callback 回调函数
     * @param string|null $modelCode 指定模型代码
     * @param string|null $scenarioCode 场景代码
     * @param string|null $locale 语言代码
     * @param array $params 额外参数
     * @return void
     * @throws Exception
     */
    public function generateStream(
        string $prompt, 
        callable $callback, 
        ?string $modelCode = null, 
        ?string $scenarioCode = null, 
        ?string $locale = null,
        array $params = []
    ): void {
        // 1. 模型选择
        $model = $this->selectModel($modelCode, $scenarioCode);
        if (!$model) {
            $reason = $this->getModelSelectionFailureReason($modelCode, $scenarioCode);
            throw new Exception($reason);
        }

        // 2. 场景适配器处理
        $adaptedPrompt = $this->applyScenarioAdapter($prompt, $scenarioCode, $params);

        // 3. 语言验证
        if ($locale && !$this->i18nIntegration->isLocaleSupported($locale)) {
            throw new Exception("不支持的语言: {$locale}");
        }

        // 4. 流式调用AI模型API
        $this->callModelApiStream($model, $adaptedPrompt, $callback, $scenarioCode, $locale, $params);
    }

    /**
     * 选择合适的模型
     * 
     * @param string|null $modelCode 指定模型代码
     * @param string|null $scenarioCode 场景代码
     * @return AiModel|null
     */
    private function selectModel(?string $modelCode, ?string $scenarioCode): ?AiModel
    {
        $model = null;
        
        // 1. 如果指定了模型代码，优先使用
        if ($modelCode) {
            // 优先取已激活模型
            $model = $this->aiModel->reset()
                ->where(AiModel::fields_MODEL_CODE, $modelCode)
                ->where(AiModel::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();

            if (!$model->getId()) {
                // 若未激活，则放宽激活限制，用于连接测试等场景
                $model = $this->aiModel->reset()
                    ->where(AiModel::fields_MODEL_CODE, $modelCode)
                    ->find()
                    ->fetch();
            }
        }

        // 2. 根据场景代码选择默认模型
        if (!$model || !$model->getId()) {
            if ($scenarioCode) {
                $model = $this->defaultModelManager->getDefaultModel($scenarioCode);
            }
        }

        // 3. 使用全局默认模型
        if (!$model || !$model->getId()) {
            $model = $this->defaultModelManager->getDefaultModel(DefaultModelManager::SERVICE_TYPE_DEFAULT);
        }

        // 4. 如果找不到默认模型，使用任意一个已激活的默认标记模型
        if (!$model || !$model->getId()) {
            $model = $this->aiModel->reset()
                ->where(AiModel::fields_IS_ACTIVE, 1)
                ->where(AiModel::fields_IS_DEFAULT, 1)
                ->find()
                ->fetch();
        }

        // 5. 如果找到模型，用 config 覆盖 provider_config（读取时覆盖，不保存到数据库）
        if ($model && $model->getId()) {
            $this->mergeConfigToProviderConfig($model);
            return $model;
        }

        return null;
    }
    
    /**
     * 将模型的 config 配置合并到 provider_config 中（读取时覆盖）
     * config 的值会覆盖 provider_config 的对应键
     * 
     * @param AiModel $model
     * @return void
     */
    private function mergeConfigToProviderConfig(AiModel $model): void
    {
        $config = $model->getConfig();
        $providerConfig = $model->getProviderConfig();
        
        // 如果 config 不为空，用 config 的值覆盖 provider_config 的对应键
        if (!empty($config)) {
            // 合并配置：config 的值覆盖 provider_config 的值
            $mergedProviderConfig = array_merge($providerConfig, $config);
            // 更新 provider_config（仅在内存中，不保存到数据库）
            $model->setData(AiModel::fields_PROVIDER_CONFIG, json_encode($mergedProviderConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
    
    /**
     * 获取模型选择失败的原因
     * 
     * @param string|null $modelCode 指定模型代码
     * @param string|null $scenarioCode 场景代码
     * @return string
     */
    private function getModelSelectionFailureReason(?string $modelCode, ?string $scenarioCode): string
    {
        $reasons = [];
        
        // 检查指定模型
        if ($modelCode) {
            $model = $this->aiModel->reset()
                ->where(AiModel::fields_MODEL_CODE, $modelCode)
                ->find()
                ->fetch();
            if (!$model->getId()) {
                $reasons[] = __('指定的模型 "%{1}" 不存在', [$modelCode]);
            } elseif (!$model->getData(AiModel::fields_IS_ACTIVE)) {
                $reasons[] = __('指定的模型 "%{1}" 未激活', [$modelCode]);
            }
        }
        
        // 检查场景默认模型
        if ($scenarioCode) {
            $defaultConfig = $this->defaultModelManager->getDefaultModelForService($scenarioCode);
            if (!$defaultConfig) {
                $reasons[] = __('场景 "%{1}" 未配置默认模型', [$scenarioCode]);
            } else {
                $modelCode = $defaultConfig->getData(\Weline\Ai\Model\AiDefaultModel::fields_MODEL_CODE);
                $model = $this->aiModel->reset()
                    ->where(AiModel::fields_MODEL_CODE, $modelCode)
                    ->find()
                    ->fetch();
                if (!$model->getId()) {
                    $reasons[] = __('场景 "%{1}" 的默认模型 "%{2}" 不存在', [$scenarioCode, $modelCode]);
                } elseif (!$model->getData(AiModel::fields_IS_ACTIVE)) {
                    $reasons[] = __('场景 "%{1}" 的默认模型 "%{2}" 未激活', [$scenarioCode, $modelCode]);
                }
            }
        }
        
        // 检查全局默认模型
        $globalDefault = $this->defaultModelManager->getDefaultModelForService(DefaultModelManager::SERVICE_TYPE_DEFAULT);
        if (!$globalDefault) {
            $reasons[] = __('未配置全局默认模型');
        } else {
            $modelCode = $globalDefault->getData(\Weline\Ai\Model\AiDefaultModel::fields_MODEL_CODE);
            $model = $this->aiModel->reset()
                ->where(AiModel::fields_MODEL_CODE, $modelCode)
                ->find()
                ->fetch();
            if (!$model->getId()) {
                $reasons[] = __('全局默认模型 "%{1}" 不存在', [$modelCode]);
            } elseif (!$model->getData(AiModel::fields_IS_ACTIVE)) {
                $reasons[] = __('全局默认模型 "%{1}" 未激活', [$modelCode]);
            }
        }
        
        // 检查是否有已激活的默认标记模型
        $activeDefaultCount = $this->aiModel->reset()
            ->where(AiModel::fields_IS_ACTIVE, 1)
            ->where(AiModel::fields_IS_DEFAULT, 1)
            ->count();
        if ($activeDefaultCount == 0) {
            $reasons[] = __('系统中没有已激活的默认模型');
        }
        
        // 检查是否有任何已激活的模型
        $activeModelCount = $this->aiModel->reset()
            ->where(AiModel::fields_IS_ACTIVE, 1)
            ->count();
        if ($activeModelCount == 0) {
            $reasons[] = __('系统中没有任何已激活的AI模型');
        }
        
        if (empty($reasons)) {
            return __('无法选择合适的AI模型，请检查模型配置');
        }
        
        return __('无法选择合适的AI模型。原因：%{1}。请前往后台 AI 模块配置默认模型。', [implode('；', $reasons)]);
    }

    /**
     * 应用场景适配器
     * 
     * @param string $prompt 原始提示词
     * @param string|null $scenarioCode 场景代码
     * @param array $params 参数
     * @return string 适配后的提示词
     */
    private function applyScenarioAdapter(string $prompt, ?string $scenarioCode, array $params): string
    {
        if (!$scenarioCode) {
            return $prompt;
        }

        $adapter = $this->adapterScanner->getAdapter($scenarioCode);
        if (!$adapter) {
            throw new Exception(__("场景适配器不存在: %{code}", ['code' => $scenarioCode]));
        }

        // 验证参数
        $validationErrors = $adapter->validateParams($params);
        if (!empty($validationErrors)) {
            throw new Exception('参数验证失败: ' . implode(', ', $validationErrors));
        }

        return $adapter->adaptPrompt($prompt, $params);
    }

    /**
     * 处理场景适配器响应
     * 
     * @param string $response 原始响应
     * @param string|null $scenarioCode 场景代码
     * @param array $params 参数
     * @return string 处理后的响应
     */
    private function processScenarioResponse(string $response, ?string $scenarioCode, array $params): string
    {
        if (!$scenarioCode) {
            return $response;
        }

        $adapter = $this->adapterScanner->getAdapter($scenarioCode);
        if (!$adapter) {
            throw new Exception(__("场景适配器不存在: %{code}", ['code' => $scenarioCode]));
        }

        return $adapter->processResponse($response, $params);
    }

    /**
     * 处理语言响应
     * 
     * @param string $response 响应内容
     * @param string $locale 目标语言
     * @return string 处理后的响应
     */
    private function processLanguageResponse(string $response, string $locale): string
    {
        // 这里可以集成翻译服务
        // 如果响应不是目标语言，可以进行翻译
        // 简化实现，直接返回原响应
        return $response;
    }

    /**
     * 调用模型API
     * 
     * @param AiModel $model 模型
     * @param string $prompt 提示词
     * @param array $params 参数
     * @return string 响应内容
     */
    private function callModelApi(AiModel $model, string $prompt, array $params): string
    {
        $startTime = microtime(true);
        $account = null;
        $usage = [];
        try {
            // 1. 获取供应商代码
            $providerCode = $this->accountService->getProviderByModelCode($model->getData(AiModel::fields_MODEL_CODE));
            if (!$providerCode) {
                throw new Exception('无法确定模型的供应商');
            }
            
            // 2. 获取该供应商的账户列表（用于回退重试）
            $allAccounts = $this->accountService->getProviderAccounts($providerCode);
            if (empty($allAccounts)) {
                throw new Exception(ErrorMessageHelper::getMissingAccountMessage($providerCode));
            }

            // 测试模式放宽筛选：仅要求激活；非测试模式要求激活+连通成功+余额>0
            $isTestMode = (bool)($params['test_mode'] ?? false);
            $candidateAccounts = array_values(array_filter($allAccounts, function ($acc) use ($isTestMode) {
                if ((int)($acc['is_active'] ?? 0) !== 1) {
                    return false;
                }
                if ($isTestMode) {
                    return true;
                }
                return (($acc['connection_status'] ?? '') === 'success') && (float)($acc['balance'] ?? 0) > 0;
            }));
            if (empty($candidateAccounts)) {
                $message = $isTestMode 
                    ? __("没有满足条件的%{provider}供应商账户（需激活）", ['provider' => $providerCode])
                    : __("没有满足条件的%{provider}供应商账户（需激活、连通成功且余额>0）", ['provider' => $providerCode]);
                throw new Exception(ErrorMessageHelper::getErrorMessageWithConfigLink($message, 'provider', ['provider_code' => $providerCode]));
            }
            usort($candidateAccounts, function ($a, $b) {
                $d1 = (int)($a['is_default'] ?? 0);
                $d2 = (int)($b['is_default'] ?? 0);
                if ($d1 !== $d2) { return $d2 <=> $d1; }
                $bal1 = (float)($a['balance'] ?? 0);
                $bal2 = (float)($b['balance'] ?? 0);
                return $bal2 <=> $bal1;
            });

            // 3. 获取提供者实例一次
            $provider = null;
            $lastError = null;

            foreach ($candidateAccounts as $accData) {
                // 将数组账户加载为模型实例以便复用现有注入逻辑
                /** @var Account $accModel */
                $accModel = ObjectManager::getInstance(Account::class);
                $accModel->setData($accData);

                // 注入账户配置并尝试请求
                $this->injectAccountConfig($model, $accModel);

                if ($provider === null) {
                    $provider = $this->providerFactory->getProvider($model);
                }

                try {
                    $result = $provider->generate($model, $prompt, $params);
                    $usage = $result['usage'] ?? [];

                    // 记录使用量（兼容旧系统）
                    $this->logUsage($model, $usage, $params);

                    // 记录到新的供应商使用记录
                    $account = $accModel; // 成功的账户
                    $requestTime = (int)((microtime(true) - $startTime) * 1000);
                    $this->accountService->recordUsage($account, $model, $usage, [
                        'request_type' => 'chat',
                        'user_id' => $params['user_id'] ?? null,
                        'user_name' => $params['user_name'] ?? null,
                        'request_time' => $requestTime,
                        'status' => 'success'
                    ]);

                    return $result['content'];
                } catch (\Exception $eTry) {
                    $lastError = $eTry;
                    $msg = $eTry->getMessage();
                    // 遇到认证失败/密钥无效时继续尝试下一个账户；其它错误直接抛出
                    $isAuthError = stripos($msg, 'Authentication') !== false
                        || stripos($msg, 'api key') !== false
                        || stripos($msg, 'unauthorized') !== false
                        || stripos($msg, '401') !== false;
                    if (!$isAuthError) {
                        throw $eTry;
                    }
                    // 继续下一账户
                }
            }

            // 若所有账户均失败，抛出最后一次错误
            if ($lastError) {
                throw $lastError;
            }
            throw new Exception('未能使用任何账户完成请求');
            
        } catch (\Exception $e) {
            // 记录错误到供应商使用记录
            if ($account) {
                $requestTime = (int)((microtime(true) - $startTime) * 1000);
                $this->accountService->recordUsage($account, $model, $usage, [
                    'request_type' => 'chat',
                    'user_id' => $params['user_id'] ?? null,
                    'user_name' => $params['user_name'] ?? null,
                    'request_time' => $requestTime,
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);
            }
            
            // 记录错误
            error_log("AI API调用失败: " . $e->getMessage());
            throw new Exception("AI生成失败: " . $e->getMessage());
        }
    }

    /**
     * 流式调用模型API
     * 
     * @param AiModel $model 模型
     * @param string $prompt 提示词
     * @param callable $callback 回调函数
     * @param string|null $scenarioCode 场景代码
     * @param string|null $locale 语言代码
     * @param array $params 参数
     * @return void
     */
    private function callModelApiStream(
        AiModel $model, 
        string $prompt, 
        callable $callback, 
        ?string $scenarioCode, 
        ?string $locale, 
        array $params
    ): void {
        $startTime = microtime(true);
        $account = null;
        $usage = [];
        
        try {
            // 1. 获取供应商代码
            $providerCode = $this->accountService->getProviderByModelCode($model->getData(AiModel::fields_MODEL_CODE));
            if (!$providerCode) {
                throw new Exception('无法确定模型的供应商');
            }
            
            // 2. 获取可用的供应商账户
            $account = $this->accountService->getAvailableAccount($providerCode);
            if (!$account) {
                throw new Exception(ErrorMessageHelper::getMissingAccountMessage($providerCode));
            }
            
            // 3. 将账户配置注入到模型中
            $this->injectAccountConfig($model, $account);
            
            // 4. 获取合适的提供者
            $provider = $this->providerFactory->getProvider($model);
            
            // 5. 流式调用API
            $result = $provider->generateStream($model, $prompt, $callback, $params);
            $usage = $result['usage'] ?? [];
            
            // 6. 记录使用量（兼容旧系统）
            $this->logUsage($model, $usage, $params);
            
            // 7. 记录到新的供应商使用记录
            $requestTime = (int)((microtime(true) - $startTime) * 1000);
            $this->accountService->recordUsage($account, $model, $usage, [
                'request_type' => 'stream',
                'user_id' => $params['user_id'] ?? null,
                'user_name' => $params['user_name'] ?? null,
                'request_time' => $requestTime,
                'status' => 'success'
            ]);
            
        } catch (\Exception $e) {
            // 记录错误到供应商使用记录
            if ($account) {
                $requestTime = (int)((microtime(true) - $startTime) * 1000);
                $this->accountService->recordUsage($account, $model, $usage, [
                    'request_type' => 'stream',
                    'user_id' => $params['user_id'] ?? null,
                    'user_name' => $params['user_name'] ?? null,
                    'request_time' => $requestTime,
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);
            }
            
            // 记录错误
            error_log("AI流式API调用失败: " . $e->getMessage());
            throw new Exception("AI流式生成失败: " . $e->getMessage());
        }
    }

    /**
     * 将供应商账户配置注入到模型中
     * 
     * @param AiModel $model
     * @param Account $account
     * @return void
     */
    private function injectAccountConfig(AiModel $model, Account $account): void
    {
        // 获取模型原有配置
        $modelConfig = $model->getConfig();
        
        // 注入账户配置
        $modelConfig['api_key'] = $account->getDecryptedApiKey();
        if ($account->getData(Account::fields_API_SECRET)) {
            $modelConfig['api_secret'] = $account->getData(Account::fields_API_SECRET);
        }
        if ($account->getData(Account::fields_BASE_URL)) {
            $modelConfig['base_url'] = $account->getData(Account::fields_BASE_URL);
            $modelConfig['api_url'] = $account->getData(Account::fields_BASE_URL);
        }
        
        // 注入代理配置
        $proxyConfig = $account->getProxyConfig();
        if (!empty($proxyConfig['enabled'])) {
            $model->setData(AiModel::fields_PROXY_INFO, json_encode($proxyConfig));
        }
        
        // 更新模型配置
        $model->setData(AiModel::fields_CONFIG, json_encode($modelConfig));
        
        // 保存账户引用（供后续使用）
        $model->setData('_provider_account', $account);
    }

    /**
     * 记录使用量
     * 
     * @param AiModel $model
     * @param array $usage
     * @param array $params
     * @return void
     */
    private function logUsage(AiModel $model, array $usage, array $params = []): void
    {
        try {
            $this->usageLog->reset();
            $this->usageLog->setData([
                'model_code' => $model->getModelCode(),
                'vendor' => $model->getVendor(),
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
                'input_cost' => ($usage['prompt_tokens'] ?? 0) * $model->getData('token_price_input') / 1000,
                'output_cost' => ($usage['completion_tokens'] ?? 0) * $model->getData('token_price_output') / 1000,
                'total_cost' => (($usage['prompt_tokens'] ?? 0) * $model->getData('token_price_input') + 
                               ($usage['completion_tokens'] ?? 0) * $model->getData('token_price_output')) / 1000,
                'scenario_code' => $params['scenario_code'] ?? null,
                'locale' => $params['locale'] ?? null,
                'user_id' => $params['user_id'] ?? 0,
                'created_time' => time(),
            ]);
            $this->usageLog->save();
        } catch (\Exception $e) {
            // 记录失败不影响主流程
            error_log("记录AI使用量失败: " . $e->getMessage());
        }
    }

    /**
     * 获取可用的场景适配器列表
     * 
     * @return array
     */
    public function getAvailableAdapters(): array
    {
        return $this->adapterScanner->getAllActiveAdapters();
    }

    /**
     * 获取场景适配器信息
     * 
     * @param string $scenarioCode 场景代码
     * @return array|null
     */
    public function getAdapterInfo(string $scenarioCode): ?array
    {
        $adapter = $this->adapterScanner->getAdapter($scenarioCode);
        if (!$adapter) {
            return null;
        }

        return [
            'code' => $adapter->getCode(),
            'name' => $adapter->getName(),
            'description' => $adapter->getDescription(),
            'version' => $adapter->getVersion(),
            'supported_models' => $adapter->getSupportedModelTypes(),
            'param_template' => $adapter->getParamTemplate(),
            'examples' => $adapter->getExamples()
        ];
    }

    /**
     * 获取支持的语言列表
     * 
     * @return array
     */
    public function getSupportedLocales(): array
    {
        return $this->i18nIntegration->getSupportedLocales();
    }

    /**
     * 获取默认语言
     * 
     * @return string
     */
    public function getDefaultLocale(): string
    {
        return $this->i18nIntegration->getDefaultLocale();
    }

    /**
     * 验证服务参数
     * 
     * @param array $params 参数
     * @return array 验证错误列表
     */
    public function validateServiceParams(array $params): array
    {
        $errors = [];

        // 验证模型代码
        if (isset($params['model_code']) && !empty($params['model_code'])) {
            $model = $this->aiModel->reset()
                ->where(AiModel::fields_CODE, $params['model_code'])
                ->where(AiModel::fields_STATUS, 'active')
                ->find()
                ->fetch();
            
            if (!$model->getId()) {
                $errors[] = '指定的模型不存在或未激活';
            }
        }

        // 验证场景代码
        if (isset($params['scenario_code']) && !empty($params['scenario_code'])) {
            $adapter = $this->adapterScanner->getAdapter($params['scenario_code']);
            if (!$adapter) {
                $errors[] = '指定的场景适配器不存在或未激活';
            }
        }

        // 验证语言代码
        if (isset($params['locale']) && !empty($params['locale'])) {
            if (!$this->i18nIntegration->isLocaleSupported($params['locale'])) {
                $errors[] = '不支持的语言代码';
            }
        }

        return $errors;
    }

    /**
     * 获取服务统计信息
     * 
     * @return array
     */
    public function getServiceStats(): array
    {
        $modelStats = [];
        $models = $this->aiModel->reset()
            ->where(AiModel::fields_STATUS, 'active')
            ->select()
            ->fetch();

        foreach ($models as $model) {
            $modelStats[] = [
                'code' => $model->getModelCode(),
                'name' => $model->getName(),
                'vendor' => $model->getVendor()
            ];
        }

        $adapterStats = $this->adapterScanner->getAdapterStats();
        $localeStats = count($this->i18nIntegration->getSupportedLocales());

        return [
            'models' => [
                'total' => count($modelStats),
                'list' => $modelStats
            ],
            'adapters' => $adapterStats,
            'locales' => [
                'total' => $localeStats,
                'default' => $this->getDefaultLocale()
            ]
        ];
    }

    /**
     * 估算token数量
     */
    private function estimateTokens(string $text): int
    {
        // 简单的token估算：中文字符按2个token计算，英文按1个token计算
        $chineseChars = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $text);
        $englishChars = preg_match_all('/[a-zA-Z]/', $text);
        $otherChars = strlen($text) - $chineseChars - $englishChars;
        
        return $chineseChars * 2 + $englishChars + $otherChars;
    }

    /**
     * 记录使用情况
     */
    private function recordUsage(AiModel $model, string $prompt, string $response, ?int $userId = null, bool $isBackend = false): void
    {
        try {
            $inputTokens = $this->estimateTokens($prompt);
            $outputTokens = $this->estimateTokens($response);
            
            // 计算费用（这里需要根据模型的价格配置来计算）
            $cost = $this->calculateCost($model, $inputTokens, $outputTokens);
            
            // 如果是前端用户调用，记录到用户账户
            if (!$isBackend && $userId) {
                $configResolver = ObjectManager::getInstance(ConfigResolver::class);
                $configResolver->recordUsage($userId, $model->getModelCode(), $inputTokens, $outputTokens, $cost);
            }
            
            // 记录到系统使用日志
            $this->logSystemUsage($model, $prompt, $response, $inputTokens, $outputTokens, $cost, $userId, $isBackend);
            
        } catch (\Exception $e) {
            // 记录使用情况失败不应该影响主要功能
            error_log("记录AI使用情况失败: " . $e->getMessage());
        }
    }
    
    /**
     * 计算费用
     */
    private function calculateCost(AiModel $model, int $inputTokens, int $outputTokens): float
    {
        $inputPrice = $model->getTokenPriceInput() ?: 0;
        $outputPrice = $model->getTokenPriceOutput() ?: 0;
        
        return ($inputTokens * $inputPrice) + ($outputTokens * $outputPrice);
    }
    
    /**
     * 记录系统使用日志
     */
    private function logSystemUsage(AiModel $model, string $prompt, string $response, int $inputTokens, int $outputTokens, float $cost, ?int $userId, bool $isBackend): void
    {
        /** @var AiUsageLog $usageLog */
        $usageLog = ObjectManager::getInstance(AiUsageLog::class);
        $usageLog->setData([
            'model_id' => $model->getId(),
            'model_code' => $model->getModelCode(),
            'user_id' => $userId,
            'is_backend' => $isBackend ? 1 : 0,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'cost' => $cost,
            'prompt_length' => strlen($prompt),
            'response_length' => strlen($response),
            'created_at' => date('Y-m-d H:i:s')
        ]);
        $usageLog->save();
    }
}