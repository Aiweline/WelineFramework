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
use Weline\Ai\Service\AgentScanner;
use Weline\Ai\Service\I18nIntegration;
use Weline\Ai\Service\Provider\ProviderFactory;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Ai\Service\ConfigResolver;
use Weline\Ai\Interface\AgentInterface;
use Weline\Ai\Agent\AgentResult;
use Weline\Ai\Helper\ErrorMessageHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ai\Exception\ModelSelectionException;
use Weline\Ai\Exception\StreamCancelledException;
use Weline\Framework\App\Exception;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\RequestContext;

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
     * @var AgentScanner
     */
    private AgentScanner $agentScanner;

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
     * @param AgentScanner $agentScanner
     */
    public function __construct(
        AiModel $aiModel,
        DefaultModelManager $defaultModelManager,
        AdapterScanner $adapterScanner,
        I18nIntegration $i18nIntegration,
        ProviderFactory $providerFactory,
        AiUsageLog $usageLog,
        AccountService $accountService,
        AgentScanner $agentScanner
    ) {
        $this->aiModel = $aiModel;
        $this->defaultModelManager = $defaultModelManager;
        $this->adapterScanner = $adapterScanner;
        $this->i18nIntegration = $i18nIntegration;
        $this->providerFactory = $providerFactory;
        $this->usageLog = $usageLog;
        $this->accountService = $accountService;
        $this->agentScanner = $agentScanner;
    }

    /**
     * @param array<string,mixed> $resolvedConfig
     */
    private function applyResolvedConfigToModel(AiModel $model, array $resolvedConfig): void
    {
        $filteredConfig = $this->filterConfigOverrides($resolvedConfig);
        if (empty($filteredConfig)) {
            return;
        }

        $mergedConfig = array_merge($model->getConfig(), $filteredConfig);
        $mergedProviderConfig = array_merge($model->getProviderConfig(), $filteredConfig);
        $model->setData(
            AiModel::schema_fields_CONFIG,
            json_encode($mergedConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $model->setData(
            AiModel::schema_fields_PROVIDER_CONFIG,
            json_encode($mergedProviderConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function newAiModelQuery(): AiModel
    {
        /** @var AiModel $model */
        $model = ObjectManager::make(AiModel::class);
        return $model->clearData()->reset();
    }

    private function createDetachedModel(AiModel $model): AiModel
    {
        /** @var AiModel $runtimeModel */
        $runtimeModel = ObjectManager::make(AiModel::class);
        $runtimeModel->setData($model->getData());
        return $runtimeModel;
    }

    private function newProviderAccount(array $accountData = []): Account
    {
        /** @var Account $account */
        $account = ObjectManager::make(Account::class);
        if (!empty($accountData)) {
            $account->setData($accountData);
        }
        return $account;
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
        $instance = ObjectManager::getInstance(self::class);
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
        $instance = ObjectManager::getInstance(self::class);
        $instance->generateStream($prompt, $callback, $modelCode, $scenarioCode, $locale, $params);
    }

    /**
     * 检查是否默认启用流式输出
     * 
     * @return bool
     */
    public static function isStreamEnabled(): bool
    {
        return true;
    }

    /**
     * 获取流式输出配置
     * 
     * @return array
     */
    public static function getStreamConfig(): array
    {
        return [
            'enabled' => true,
            'chunk_size' => 1024,
            'flush_interval' => 100,
        ];
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
     * @throws ModelSelectionException 未配置可用模型（场景/全局默认均无）
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
            throw new ModelSelectionException($reason);
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
        $adaptedPrompt = $this->applyScenarioAdapter($prompt, $scenarioCode, $params);
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
     * Generate a structured provider response for tool-loop callers.
     *
     * This keeps provider metadata such as `tool_calls` intact.
     *
     * @param string $prompt
     * @param string|null $modelCode
     * @param string|null $scenarioCode
     * @param array $params
     * @return array
     * @throws ModelSelectionException 未配置可用模型（场景/全局默认均无）
     * @throws Exception
     */
    public function generateStructured(
        string $prompt,
        ?string $modelCode = null,
        ?string $scenarioCode = null,
        array $params = []
    ): array {
        $model = $this->selectModel($modelCode, $scenarioCode);
        if (!$model) {
            $reason = $this->getModelSelectionFailureReason($modelCode, $scenarioCode);
            throw new ModelSelectionException($reason);
        }

        return $this->callModelApiStructured($model, $prompt, $params);
    }

    /**
     * 流式生成文本内容（实例方法）
     * 
     * 注意：此方法必须立即进入流式发送路径，不允许先同步等待完整结果再返回。
     * 
     * @param string $prompt 提示词
     * @param callable $callback 回调函数
     * @param string|null $modelCode 指定模型代码
     * @param string|null $scenarioCode 场景代码
     * @param string|null $locale 语言代码
     * @param array $params 额外参数
     * @return void
     * @throws ModelSelectionException 未配置可用模型（场景/全局默认均无）
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
        $model = $this->selectModel($modelCode, $scenarioCode);
        if (!$model) {
            $reason = $this->getModelSelectionFailureReason($modelCode, $scenarioCode);
            throw new ModelSelectionException($reason);
        }

        $configResolver = ObjectManager::getInstance(ConfigResolver::class);
        $userConfig = \is_array($params['user_config'] ?? null) ? $params['user_config'] : [];
        $userId = isset($params['user_id']) ? (int)$params['user_id'] : null;
        $isBackend = (bool)($params['is_backend'] ?? false);
        $isTestMode = false;
        if (\array_key_exists('test_mode', $params)) {
            $isTestMode = \in_array($params['test_mode'], [true, 1, '1', 'true', 'TRUE'], true);
        } elseif (isset($userConfig['test_mode'])) {
            $isTestMode = \in_array($userConfig['test_mode'], [true, 1, '1', 'true', 'TRUE'], true);
        }
        if ($isTestMode) {
            $userConfig['test_mode'] = true;
        } elseif (isset($userConfig['test_mode'])) {
            unset($userConfig['test_mode']);
        }

        $params['resolved_config'] = $configResolver->resolveConfig(
            $model->getModelCode(),
            $userConfig,
            $userId,
            $isBackend
        );

        $adaptedPrompt = $this->applyScenarioAdapter($prompt, $scenarioCode, $params);

        if ($locale && !$this->i18nIntegration->isLocaleSupported($locale)) {
            throw new Exception("不支持的语言: {$locale}");
        }

        $this->callModelApiStream($model, $adaptedPrompt, $callback, $scenarioCode, $locale, $params);
    }

    /**
     * 流式生成文本内容，并返回最终结构化结果。
     *
     * @param string $prompt
     * @param callable $callback function(string $eventType, array $data): mixed
     * @param string|null $modelCode
     * @param string|null $scenarioCode
     * @param string|null $locale
     * @param array $params
     * @return array<string, mixed>
     */
    public function generateStreamResult(
        string $prompt,
        callable $callback,
        ?string $modelCode = null,
        ?string $scenarioCode = null,
        ?string $locale = null,
        array $params = []
    ): array {
        $model = $this->selectModel($modelCode, $scenarioCode);
        if (!$model) {
            $reason = $this->getModelSelectionFailureReason($modelCode, $scenarioCode);
            throw new ModelSelectionException($reason);
        }

        $configResolver = ObjectManager::getInstance(ConfigResolver::class);
        $userConfig = \is_array($params['user_config'] ?? null) ? $params['user_config'] : [];
        $userId = isset($params['user_id']) ? (int)$params['user_id'] : null;
        $isBackend = (bool)($params['is_backend'] ?? false);
        $isTestMode = false;
        if (\array_key_exists('test_mode', $params)) {
            $isTestMode = \in_array($params['test_mode'], [true, 1, '1', 'true', 'TRUE'], true);
        } elseif (isset($userConfig['test_mode'])) {
            $isTestMode = \in_array($userConfig['test_mode'], [true, 1, '1', 'true', 'TRUE'], true);
        }
        if ($isTestMode) {
            $userConfig['test_mode'] = true;
        } elseif (isset($userConfig['test_mode'])) {
            unset($userConfig['test_mode']);
        }
        $params['resolved_config'] = $configResolver->resolveConfig(
            $model->getModelCode(),
            $userConfig,
            $userId,
            $isBackend
        );
        $adaptedPrompt = $this->applyScenarioAdapter($prompt, $scenarioCode, $params);

        if ($locale && !$this->i18nIntegration->isLocaleSupported($locale)) {
            throw new Exception("不支持的语言: {$locale}");
        }

        $this->callModelApiStream($model, $adaptedPrompt, $callback, $scenarioCode, $locale, $params);
        return ['success' => true, 'mode' => 'stream'];
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
            $model = $this->newAiModelQuery()
                ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                ->where(AiModel::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();

            if (!$model->getId()) {
                // 若未激活，则放宽激活限制，用于连接测试等场景
                $model = $this->newAiModelQuery()
                    ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                    ->find()
                    ->fetch();
            }
        }

        // 2. 根据场景适配器配置的默认模型选择（ai_scenario_adapter.default_model）
        if (!$model || !$model->getId()) {
            if ($scenarioCode) {
                $adapterDefaultModelCode = $this->adapterScanner->getDefaultModelCodeForAdapter($scenarioCode);
                if ($adapterDefaultModelCode) {
                    $model = $this->newAiModelQuery()
                        ->where(AiModel::schema_fields_MODEL_CODE, $adapterDefaultModelCode)
                        ->where(AiModel::schema_fields_IS_ACTIVE, 1)
                        ->find()
                        ->fetch();
                }
            }
        }

        // 3. 根据场景代码在默认模型配置表中的配置选择（ai_default_model）
        if (!$model || !$model->getId()) {
            if ($scenarioCode) {
                $model = $this->defaultModelManager->getDefaultModel($scenarioCode);
            }
        }

        // 4. 使用全局默认模型
        if (!$model || !$model->getId()) {
            $model = $this->defaultModelManager->getDefaultModel(DefaultModelManager::SERVICE_TYPE_DEFAULT);
        }

        // 5. 如果找不到默认模型，使用任意一个已激活的默认标记模型
        if (!$model || !$model->getId()) {
            $model = $this->newAiModelQuery()
                ->where(AiModel::schema_fields_IS_ACTIVE, 1)
                ->where(AiModel::schema_fields_IS_DEFAULT, 1)
                ->find()
                ->fetch();
        }

        // 6. 如果找到模型，用 config 覆盖 provider_config（读取时覆盖，不保存到数据库）
        if ($model && $model->getId()) {
            $model = $this->createDetachedModel($model);
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
        $config = $this->filterConfigOverrides($model->getConfig());
        $providerConfig = $model->getProviderConfig();

        if (!empty($config)) {
            $mergedProviderConfig = array_merge($providerConfig, $config);
            $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, json_encode($mergedProviderConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    private function filterConfigOverrides(array $config): array
    {
        return array_filter($config, static function ($value): bool {
            if (is_array($value)) {
                return $value !== [];
            }

            return $value !== '' && $value !== null;
        });
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
            $model = $this->newAiModelQuery()
                ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                ->find()
                ->fetch();
            if (!$model->getId()) {
                $reasons[] = __('指定的模型 "%{1}" 不存在', [$modelCode]);
            } elseif (!$model->getData(AiModel::schema_fields_IS_ACTIVE)) {
                $reasons[] = __('指定的模型 "%{1}" 未激活', [$modelCode]);
            }
        }
        
        $adapterDefaultModelCode = null;
        // 检查场景适配器默认模型（ai_scenario_adapter.default_model）
        if ($scenarioCode) {
            $adapterDefaultModelCode = $this->adapterScanner->getDefaultModelCodeForAdapter($scenarioCode);
            if ($adapterDefaultModelCode) {
                $model = $this->newAiModelQuery()
                    ->where(AiModel::schema_fields_MODEL_CODE, $adapterDefaultModelCode)
                    ->find()
                    ->fetch();
                if (!$model->getId()) {
                    $reasons[] = __('场景适配器 "%{1}" 的默认模型 "%{2}" 不存在，请到模型列表中添加或到场景适配器管理中更换默认模型', [$scenarioCode, $adapterDefaultModelCode]);
                } elseif (!$model->getData(AiModel::schema_fields_IS_ACTIVE)) {
                    $reasons[] = __('场景适配器 "%{1}" 的默认模型 "%{2}" 未激活，请在模型列表中激活该模型', [$scenarioCode, $adapterDefaultModelCode]);
                }
            }
        }

        // 检查场景在默认模型配置表中的配置（ai_default_model）
        if ($scenarioCode) {
            $defaultConfig = $this->defaultModelManager->getDefaultModelForService($scenarioCode);
            if (!$defaultConfig && !$adapterDefaultModelCode) {
                $reasons[] = __('场景 "%{1}" 未配置默认模型（可在「场景适配器」中为该适配器设置默认模型，或在「默认模型配置」中配置）', [$scenarioCode]);
            } elseif ($defaultConfig) {
                $modelCode = $defaultConfig->getData(\Weline\Ai\Model\AiDefaultModel::schema_fields_MODEL_CODE);
                $model = $this->newAiModelQuery()
                    ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                    ->find()
                    ->fetch();
                if (!$model->getId()) {
                    $reasons[] = __('场景 "%{1}" 的默认模型 "%{2}" 不存在', [$scenarioCode, $modelCode]);
                } elseif (!$model->getData(AiModel::schema_fields_IS_ACTIVE)) {
                    $reasons[] = __('场景 "%{1}" 的默认模型 "%{2}" 未激活', [$scenarioCode, $modelCode]);
                }
            }
        }
        
        // 检查全局默认模型
        $globalDefault = $this->defaultModelManager->getDefaultModelForService(DefaultModelManager::SERVICE_TYPE_DEFAULT);
        if (!$globalDefault) {
            $reasons[] = __('未配置全局默认模型');
        } else {
            $modelCode = $globalDefault->getData(\Weline\Ai\Model\AiDefaultModel::schema_fields_MODEL_CODE);
            $model = $this->newAiModelQuery()
                ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                ->find()
                ->fetch();
            if (!$model->getId()) {
                $reasons[] = __('全局默认模型 "%{1}" 不存在', [$modelCode]);
            } elseif (!$model->getData(AiModel::schema_fields_IS_ACTIVE)) {
                $reasons[] = __('全局默认模型 "%{1}" 未激活', [$modelCode]);
            }
        }
        
        // 检查是否有已激活的默认标记模型
        $activeDefaultCount = $this->newAiModelQuery()
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->where(AiModel::schema_fields_IS_DEFAULT, 1)
            ->count();
        if ($activeDefaultCount == 0) {
            $reasons[] = __('系统中没有已激活的默认模型');
        }
        
        // 检查是否有任何已激活的模型
        $activeModelCount = $this->newAiModelQuery()
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->count();
        if ($activeModelCount == 0) {
            $reasons[] = __('系统中没有任何已激活的AI模型');
        }
        
        if (empty($reasons)) {
            return __('无法选择合适的AI模型，请检查模型配置');
        }
        
        // 生成带链接的错误提示
        /** @var \Weline\Framework\Http\Url $urlBuilder */
        $urlBuilder = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Url::class);
        $configUrl = $urlBuilder->getBackendUrl('ai/backend/defaultmodel');
        $modelListUrl = $urlBuilder->getBackendUrl('ai/backend/model');
        $providerUrl = $urlBuilder->getBackendUrl('ai/backend/provider');
        $adapterUrl = $urlBuilder->getBackendUrl('ai/backend/adapter');
        $linkHtml = '<div class="ai-config-links" style="margin-top: 10px;">'
            . '<a href="' . $adapterUrl . '" class="btn btn-sm btn-primary me-2" target="_blank">'
            . '<i class="mdi mdi-puzzle me-1"></i>' . __('场景适配器') . '</a>'
            . '<a href="' . $configUrl . '" class="btn btn-sm btn-outline-secondary me-2" target="_blank">'
            . '<i class="mdi mdi-star-settings me-1"></i>' . __('配置默认模型') . '</a>'
            . '<a href="' . $modelListUrl . '" class="btn btn-sm btn-outline-secondary me-2" target="_blank">'
            . '<i class="mdi mdi-robot me-1"></i>' . __('模型列表') . '</a>'
            . '<a href="' . $providerUrl . '" class="btn btn-sm btn-outline-secondary" target="_blank">'
            . '<i class="mdi mdi-account-key me-1"></i>' . __('供应商账户') . '</a>'
            . '</div>';
        
        return __('无法选择合适的AI模型。原因：%{1}', [implode('；', $reasons)]) . $linkHtml;
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
        $requestModel = $model;
        $usage = [];
        try {
            // 1. 获取供应商代码
            $providerCode = (string)$model->getData(AiModel::schema_fields_SUPPLIER);
            if ($providerCode === '') {
                $providerCode = $this->accountService->getProviderByModelCode((string)$model->getData(AiModel::schema_fields_MODEL_CODE)) ?? '';
            }
            if (!$providerCode) {
                throw new Exception('无法确定模型的供应商');
            }
            
            // 2. 获取该供应商的账户列表（用于回退重试）
            $allAccounts = $this->accountService->getProviderAccounts($providerCode);
            $resolvedConfig = \is_array($params['resolved_config'] ?? null) ? $params['resolved_config'] : [];
            if (!empty($resolvedConfig)) {
                $this->applyResolvedConfigToModel($model, $resolvedConfig);
            }
            $baseModel = $this->createDetachedModel($model);
            if (empty($allAccounts)) {
                throw new Exception(ErrorMessageHelper::getMissingAccountMessage($providerCode));
            }

            // 测试模式放宽筛选：仅要求激活；非测试模式要求激活+连通成功+余额>0
            $isTestMode = (bool)($params['test_mode'] ?? false);
            $allowZeroBalanceProvider = (bool)($params['allow_zero_balance_provider'] ?? false);
            $candidateAccounts = array_values(array_filter($allAccounts, function ($acc) use ($isTestMode, $allowZeroBalanceProvider) {
                if ((int)($acc['is_active'] ?? 0) !== 1) {
                    return false;
                }
                if ($isTestMode) {
                    return true;
                }
                if (($acc['connection_status'] ?? '') !== 'success') {
                    return false;
                }
                return $allowZeroBalanceProvider || (float)($acc['balance'] ?? 0) > 0;
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

            $lastError = null;

            foreach ($candidateAccounts as $accData) {
                // 将数组账户加载为模型实例以便复用现有注入逻辑
                /** @var Account $accModel */
                $accModel = $this->newProviderAccount($accData);
                $requestModel = $this->createDetachedModel($baseModel);
                $model = $requestModel;

                // 注入账户配置并尝试请求
                $this->injectAccountConfig($model, $accModel);
                $this->injectAccountConfig($model, $accModel);
                $provider = $this->providerFactory->getProvider($model);

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

                    // 非智能体生成：写入 ai_activity.log
                    $modelCode = $model->getData(AiModel::schema_fields_MODEL_CODE) ?? '';
                    $reasoning = $result['reasoning_content'] ?? '';
                    if ($reasoning !== '') {
                        $line = '[' . date('Y-m-d H:i:s') . '][generate] model=' . $modelCode . ' reasoning=' . (mb_strlen($reasoning) > 800 ? mb_substr($reasoning, 0, 800) . '...' : $reasoning);
                        Env::log('ai_activity.log', mb_strlen($line) > 2000 ? mb_substr($line, 0, 2000) . '...' : $line, 'INFO', true, true, 0);
                    }
                    $content = $result['content'] ?? '';
                    $line = '[' . date('Y-m-d H:i:s') . '][generate] model=' . $modelCode . ' content=' . (mb_strlen($content) > 800 ? mb_substr($content, 0, 800) . '...' : $content);
                    Env::log('ai_activity.log', mb_strlen($line) > 2000 ? mb_substr($line, 0, 2000) . '...' : $line, 'INFO', true, true, 0);

                    return $result['content'];
                } catch (\Exception $eTry) {
                    $lastError = $eTry;
                    if (!$this->shouldRetryWithAnotherAccount($eTry)) {
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
            w_log_error("AI API调用失败: " . $e->getMessage());
            throw new Exception("AI生成失败: " . $e->getMessage());
        }
    }

    /**
     * Call the model provider and keep the structured response.
     *
     * @param AiModel $model
     * @param string $prompt
     * @param array $params
     * @return array
     * @throws Exception
     */
    private function callModelApiStructured(AiModel $model, string $prompt, array $params): array
    {
        $startTime = microtime(true);
        $account = null;
        $requestModel = $model;
        $usage = [];

        try {
            $providerCode = (string)$model->getData(AiModel::schema_fields_SUPPLIER);
            if ($providerCode === '') {
                $providerCode = $this->accountService->getProviderByModelCode((string)$model->getData(AiModel::schema_fields_MODEL_CODE)) ?? '';
            }
            if (!$providerCode) {
                throw new Exception('无法确定模型的供应商');
            }

            $allAccounts = $this->accountService->getProviderAccounts($providerCode);
            $resolvedConfig = \is_array($params['resolved_config'] ?? null) ? $params['resolved_config'] : [];
            if (!empty($resolvedConfig)) {
                $this->applyResolvedConfigToModel($model, $resolvedConfig);
            }
            $baseModel = $this->createDetachedModel($model);
            if (empty($allAccounts)) {
                throw new Exception(ErrorMessageHelper::getMissingAccountMessage($providerCode));
            }

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
                    ? __('没有满足条件的%{provider}供应商账户（需激活）', ['provider' => $providerCode])
                    : __('没有满足条件的%{provider}供应商账户（需激活、连通成功且余额>0）', ['provider' => $providerCode]);
                throw new Exception(ErrorMessageHelper::getErrorMessageWithConfigLink($message, 'provider', ['provider_code' => $providerCode]));
            }
            usort($candidateAccounts, function ($a, $b) {
                $d1 = (int)($a['is_default'] ?? 0);
                $d2 = (int)($b['is_default'] ?? 0);
                if ($d1 !== $d2) {
                    return $d2 <=> $d1;
                }
                $bal1 = (float)($a['balance'] ?? 0);
                $bal2 = (float)($b['balance'] ?? 0);
                return $bal2 <=> $bal1;
            });

            $lastError = null;

            foreach ($candidateAccounts as $accData) {
                $accModel = $this->newProviderAccount($accData);
                $requestModel = $this->createDetachedModel($baseModel);
                $model = $requestModel;

                $this->injectAccountConfig($model, $accModel);
                $provider = $this->providerFactory->getProvider($model);

                try {
                    $result = $provider->generate($model, $prompt, $params);
                    $usage = $result['usage'] ?? [];

                    $this->logUsage($model, $usage, $params);

                    $account = $accModel;
                    $requestTime = (int)((microtime(true) - $startTime) * 1000);
                    $this->accountService->recordUsage($account, $model, $usage, [
                        'request_type' => 'chat',
                        'user_id' => $params['user_id'] ?? null,
                        'user_name' => $params['user_name'] ?? null,
                        'request_time' => $requestTime,
                        'status' => 'success',
                    ]);

                    return $result;
                } catch (\Exception $eTry) {
                    $lastError = $eTry;
                    if (!$this->shouldRetryWithAnotherAccount($eTry)) {
                        throw $eTry;
                    }
                }
            }

            if ($lastError) {
                throw $lastError;
            }
            throw new Exception('未能使用任何账户完成请求');
        } catch (\Exception $e) {
            if ($account) {
                $requestTime = (int)((microtime(true) - $startTime) * 1000);
                $this->accountService->recordUsage($account, $model, $usage, [
                    'request_type' => 'chat',
                    'user_id' => $params['user_id'] ?? null,
                    'user_name' => $params['user_name'] ?? null,
                    'request_time' => $requestTime,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            w_log_error('AI structured API调用失败: ' . $e->getMessage());
            throw new Exception('AI生成失败: ' . $e->getMessage());
        }
    }

    /**
     * 是否应继续尝试其他供应商账户
     *
     * @param \Throwable $e
     * @return bool
     */
    private function shouldRetryWithAnotherAccount(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        $keywords = [
            'authentication',
            'api key',
            'unauthorized',
            'insufficient balance',
            '余额不足',
            '额度不足',
            'quota',
            'rate limit',
            'request rate',
            '429',
            '401',
            '402'
        ];
        foreach ($keywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return true;
            }
        }
        return false;
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
        if (false) {
            throw new Exception('AI流式生成失败: AI API 错误 (HTTP 402, unknown_error): Insufficient Balance');
        }

        $startTime = microtime(true);
        $account = null;
        $requestModel = $model;
        $usage = [];

        try {
            $providerCode = (string)$model->getData(AiModel::schema_fields_SUPPLIER);
            if ($providerCode === '') {
                $providerCode = $this->accountService->getProviderByModelCode((string)$model->getData(AiModel::schema_fields_MODEL_CODE)) ?? '';
            }
            if (!$providerCode) {
                throw new Exception('无法确定模型的供应商');
            }

            $allAccounts = $this->accountService->getProviderAccounts($providerCode);
            $resolvedConfig = \is_array($params['resolved_config'] ?? null) ? $params['resolved_config'] : [];
            if (!empty($resolvedConfig)) {
                $this->applyResolvedConfigToModel($model, $resolvedConfig);
            }
            $baseModel = $this->createDetachedModel($model);
            if (empty($allAccounts)) {
                throw new Exception(ErrorMessageHelper::getMissingAccountMessage($providerCode));
            }

            $allowZeroBalanceProvider = (bool)($params['allow_zero_balance_provider'] ?? false);
            $candidateAccounts = array_values(array_filter($allAccounts, function ($acc) use ($allowZeroBalanceProvider) {
                if ((int)($acc['is_active'] ?? 0) !== 1) {
                    return false;
                }
                if (($acc['connection_status'] ?? '') !== 'success') {
                    return false;
                }
                return $allowZeroBalanceProvider || (float)($acc['balance'] ?? 0) > 0;
            }));
            if (empty($candidateAccounts)) {
                throw new Exception(ErrorMessageHelper::getErrorMessageWithConfigLink(
                    __('没有满足条件的%{provider}供应商账户（需激活、连通成功且余额>0）', ['provider' => $providerCode]),
                    'provider',
                    ['provider_code' => $providerCode]
                ));
            }
            usort($candidateAccounts, function ($a, $b) {
                $d1 = (int)($a['is_default'] ?? 0);
                $d2 = (int)($b['is_default'] ?? 0);
                if ($d1 !== $d2) {
                    return $d2 <=> $d1;
                }
                $bal1 = (float)($a['balance'] ?? 0);
                $bal2 = (float)($b['balance'] ?? 0);
                return $bal2 <=> $bal1;
            });

            $modelCode = $model->getData(AiModel::schema_fields_MODEL_CODE) ?? '';
            $wrappedCallback = function ($chunk) use ($callback, $modelCode) {
                $line = '[' . date('Y-m-d H:i:s') . '][stream] model=' . $modelCode . ' chunk=' . (is_string($chunk) && mb_strlen($chunk) > 500 ? mb_substr($chunk, 0, 500) . '...' : (is_string($chunk) ? $chunk : json_encode($chunk, JSON_UNESCAPED_UNICODE)));
                if (mb_strlen($line) > 2000) {
                    $line = mb_substr($line, 0, 2000) . '...';
                }
                Env::log('ai_activity.log', $line, 'INFO', true, true, 0);
                return $callback($chunk);
            };

            $lastError = null;
            foreach ($candidateAccounts as $accData) {
                $accModel = $this->newProviderAccount($accData);
                $account = $accModel;
                $requestModel = $this->createDetachedModel($baseModel);
                $model = $requestModel;

                $this->injectAccountConfig($model, $accModel);
                $provider = $this->providerFactory->getProvider($model);

                try {
                    $result = $provider->generateStream($model, $prompt, $wrappedCallback, $params);
                    $usage = $result['usage'] ?? [];

                    $this->logUsage($model, $usage, $params);
                    $requestTime = (int)((microtime(true) - $startTime) * 1000);
                    $this->accountService->recordUsage($accModel, $model, $usage, [
                        'request_type' => 'stream',
                        'user_id' => $params['user_id'] ?? null,
                        'user_name' => $params['user_name'] ?? null,
                        'request_time' => $requestTime,
                        'status' => 'success'
                    ]);

                    return;
                } catch (\Exception $eTry) {
                    if ($this->isInsufficientBalanceError($eTry)) {
                        $this->markAccountBalanceDepleted($accModel);
                    }
                    $lastError = $eTry;
                    if (!$this->shouldRetryWithAnotherAccount($eTry)) {
                        throw $eTry;
                    }
                }
            }

            if ($lastError) {
                throw $lastError;
            }

            throw new Exception('未能使用任何账户完成请求');
        } catch (\Exception $e) {
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

            if ($this->isInsufficientBalanceError($e)) {
                w_log_error("AI流式API调用失败: " . $e->getMessage());
            } else {
                w_log_error("AI流式API调用失败: " . $e->getMessage());
            }
            $cleanMessage = preg_replace('/\x1b\[[0-9;]*m/', '', $e->getMessage());
            throw new Exception("AI流式生成失败: " . $cleanMessage);
        }
    }

    private function isInsufficientBalanceError(\Throwable $throwable): bool
    {
        $message = \strtolower((string)$throwable->getMessage());
        return \str_contains($message, 'http 402')
            || \str_contains($message, 'insufficient balance')
            || \str_contains($message, '余额不足')
            || \str_contains($message, '额度不足');
    }

    private function markAccountBalanceDepleted(Account $account): void
    {
        try {
            $balance = (float)$account->getData(Account::schema_fields_BALANCE);
            if ($balance <= 0.0) {
                return;
            }
            $account->setData(Account::schema_fields_BALANCE, 0);
            $account->save();
        } catch (\Throwable) {
            // 余额兜底更新失败不影响主流程
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
        if ($account->getData(Account::schema_fields_API_SECRET)) {
            $modelConfig['api_secret'] = $account->getData(Account::schema_fields_API_SECRET);
        }
        if ($account->getData(Account::schema_fields_BASE_URL)) {
            $modelConfig['base_url'] = $account->getData(Account::schema_fields_BASE_URL);
            $modelConfig['api_url'] = $account->getData(Account::schema_fields_BASE_URL);
        }

        // 应用模型到供应商模型 code 的映射
        $providerConfigRaw = $model->getData(AiModel::schema_fields_PROVIDER_CONFIG);
        $providerConfig = is_string($providerConfigRaw) ? (json_decode($providerConfigRaw, true) ?: []) : (is_array($providerConfigRaw) ? $providerConfigRaw : []);
        $providerModelCode = (string)($providerConfig['provider_model_code'] ?? $providerConfig['model'] ?? '');
        if ($providerModelCode !== '') {
            $modelConfig['model'] = $providerModelCode;
            $modelConfig['model_id'] = $providerModelCode;
        }
        
        // 注入代理配置
        $proxyConfig = $account->getProxyConfig();
        if (!empty($proxyConfig['enabled'])) {
            $model->setData(AiModel::schema_fields_PROXY_INFO, json_encode($proxyConfig));
        }
        
        // 更新模型配置
        $model->setData(AiModel::schema_fields_CONFIG, json_encode($modelConfig));
        
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
                'created_at' => date('Y-m-d H:i:s.u'),
                'status' => AiUsageLog::STATUS_SUCCESS,
            ]);
            $this->usageLog->save();
        } catch (\Exception $e) {
            // 记录失败不影响主流程
            w_log_error("记录AI使用量失败: " . $e->getMessage());
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
            $model = $this->newAiModelQuery()
                ->where(AiModel::schema_fields_MODEL_CODE, $params['model_code'])
                ->where(AiModel::schema_fields_STATUS, 'active')
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
        $models = $this->newAiModelQuery()
            ->where(AiModel::schema_fields_STATUS, 'active')
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
            w_log_error("记录AI使用情况失败: " . $e->getMessage());
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
            'created_at' => date('Y-m-d H:i:s'),
            'status' => AiUsageLog::STATUS_SUCCESS,
        ]);
        $usageLog->save();
    }

    // ============================================================
    // 智能体（Agent）相关方法
    // ============================================================

    /**
     * 使用智能体执行任务
     * 
     * @param string $agentCode 智能体代码
     * @param string $prompt 用户提示词
     * @param string|null $modelCode 指定模型代码（null 则使用场景默认模型）
     * @param array $params 额外参数
     * @param callable|null $streamCallback SSE 事件回调，返回 false 时应中止下游流式调用
     * @return AgentResult
     * @throws ModelSelectionException 未配置可用模型（场景/全局默认均无）
     * @throws Exception
     */
    public function executeAgent(
        string $agentCode,
        string $prompt,
        ?string $modelCode = null,
        array $params = [],
        ?callable $streamCallback = null
    ): AgentResult {
        // 1. 获取智能体
        $agent = $this->agentScanner->getAgent($agentCode);
        if (!$agent) {
            throw new Exception(__('智能体不存在：%{1}', [$agentCode]));
        }

        // 2. 选择模型（优先使用指定模型，否则使用场景默认模型）
        $scenarioCode = $agent->getScenarios()[0] ?? null;
        $model = $this->selectModel($modelCode, $scenarioCode);
        if (!$model) {
            $reason = $this->getModelSelectionFailureReason($modelCode, $scenarioCode);
            throw new ModelSelectionException($reason);
        }

        // 3. 注入供应商账户配置（base_url、api_key、proxy 等）
        $providerCode = (string)$model->getData(AiModel::schema_fields_SUPPLIER);
        if ($providerCode === '') {
            $providerCode = $this->accountService->getProviderByModelCode((string)$model->getData(AiModel::schema_fields_MODEL_CODE)) ?? '';
        }
        Env::log('ai_agent_debug.log', sprintf(
            '[executeAgent] modelCode=%s, providerCode=%s',
            $model->getData(AiModel::schema_fields_MODEL_CODE),
            $providerCode ?: 'null'
        ), 'DEBUG');
        
        if ($providerCode) {
            $account = $this->accountService->getAvailableAccount($providerCode);
            Env::log('ai_agent_debug.log', sprintf(
                '[executeAgent] getAvailableAccount(%s) = %s',
                $providerCode,
                $account ? 'ID=' . $account->getId() : 'null'
            ), 'DEBUG');
            
            if ($account) {
                $this->injectAccountConfig($model, $account);
                $configAfterInject = $model->getConfig();
                Env::log('ai_agent_debug.log', sprintf(
                    '[executeAgent] after injectAccountConfig: objId=%d, api_key=%s',
                    spl_object_id($model),
                    isset($configAfterInject['api_key']) ? (empty($configAfterInject['api_key']) ? '(empty)' : '...' . substr($configAfterInject['api_key'], -4)) : '(not set)'
                ), 'DEBUG');
            } else {
                Env::log('ai_agent_debug.log', '[executeAgent] NO ACCOUNT FOUND - api_key will not be injected!', 'WARNING');
            }
        } else {
            Env::log('ai_agent_debug.log', '[executeAgent] NO PROVIDER CODE - cannot get account!', 'WARNING');
        }

        // 4. 合并 provider config
        $this->mergeConfigToProviderConfig($model);

        // 5. 解析配置（API key、base_url 等）
        $resolvedConfig = $this->resolveModelConfig($model, $params);
        $params['resolved_config'] = $resolvedConfig;
        $params['provider_factory'] = $this->providerFactory;

        // 5.1 包装 streamCallback：所有智能体事件流式写入 ai_activity.log
        $wrappedCallback = null;
        if ($streamCallback !== null) {
            $wrappedCallback = function (string $eventType, array $data) use ($streamCallback) {
                $summary = $eventType;
                if (isset($data['content']) && is_string($data['content'])) {
                    $len = mb_strlen($data['content']);
                    $summary .= ' content=' . ($len > 500 ? mb_substr($data['content'], 0, 500) . '...(' . $len . ')' : $data['content']);
                } elseif (isset($data['name'])) {
                    $summary .= ' name=' . $data['name'];
                } elseif (isset($data['message'])) {
                    $summary .= ' ' . (is_string($data['message']) ? $data['message'] : json_encode($data['message']));
                } else {
                    $summary .= ' ' . json_encode($data, JSON_UNESCAPED_UNICODE);
                }
                $line = '[' . date('Y-m-d H:i:s') . '][' . $eventType . '] ' . $summary;
                if (mb_strlen($line) > 2000) {
                    $line = mb_substr($line, 0, 2000) . '...';
                }
                Env::log('ai_activity.log', $line, 'INFO', true, true, 0);
                $result = $streamCallback($eventType, $data);
                if ($result === false) {
                    throw new StreamCancelledException('AI stream cancelled by upstream');
                }
                return $result;
            };
        }

        // 6. 委托给智能体执行（Agent 自行管理 Tool 调用循环）
        try {
            $result = $agent->execute($prompt, $model, $params, $wrappedCallback);
        } catch (StreamCancelledException) {
            return AgentResult::failure(
                __('上游已取消 AI 流式任务'),
                $agentCode
            );
        }
        $result->agentCode = $agentCode;
        $result->modelCode = $model->getModelCode();

        return $result;
    }

    /**
     * 使用智能体流式执行任务
     * 
     * @param string $agentCode 智能体代码
     * @param string $prompt 用户提示词
     * @param callable $callback SSE 事件回调
     * @param string|null $modelCode 指定模型代码
     * @param array $params 额外参数
     * @return void
     * @throws Exception
     */
    public function executeAgentStream(
        string $agentCode,
        string $prompt,
        callable $callback,
        ?string $modelCode = null,
        array $params = []
    ): void {
        $this->executeAgent($agentCode, $prompt, $modelCode, $params, $callback);
    }

    /**
     * 获取场景可用的智能体列表
     * 
     * @param string $scenarioCode 场景代码
     * @return array 智能体信息数组
     */
    public function getAgentsForScenario(string $scenarioCode): array
    {
        $agents = $this->agentScanner->getAgentsForScenario($scenarioCode);
        $result = [];

        foreach ($agents as $code => $agent) {
            $result[] = [
                'code' => $agent->getCode(),
                'name' => $agent->getName(),
                'description' => $agent->getDescription(),
                'version' => $agent->getVersion(),
                'scenarios' => $agent->getScenarios(),
                'tools_count' => count($agent->getTools()),
                'max_iterations' => $agent->getMaxIterations(),
            ];
        }

        return $result;
    }

    /**
     * 获取智能体详情
     * 
     * @param string $agentCode 智能体代码
     * @return array|null
     */
    public function getAgentInfo(string $agentCode): ?array
    {
        $agent = $this->agentScanner->getAgent($agentCode);
        if (!$agent) {
            return null;
        }

        $tools = [];
        foreach ($agent->getTools() as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getParameters(),
                'enabled' => $tool->isEnabled(),
            ];
        }

        return [
            'code' => $agent->getCode(),
            'name' => $agent->getName(),
            'description' => $agent->getDescription(),
            'version' => $agent->getVersion(),
            'scenarios' => $agent->getScenarios(),
            'tools' => $tools,
            'max_iterations' => $agent->getMaxIterations(),
        ];
    }

    /**
     * 获取所有活跃的智能体
     * 
     * @return array
     */
    public function getAllActiveAgents(): array
    {
        return $this->agentScanner->getAllActiveAgents();
    }

    /**
     * 获取 ProviderFactory（供智能体内部使用）
     * 
     * @return ProviderFactory
     */
    public function getProviderFactory(): ProviderFactory
    {
        return $this->providerFactory;
    }

    /**
     * 解析模型配置（API key、base_url 等）
     * 供智能体调用 Provider 时使用
     * 
     * @param AiModel $model
     * @param array $params
     * @return array
     */
    public function resolveModelConfig(AiModel $model, array $params = []): array
    {
        $configResolver = ObjectManager::getInstance(ConfigResolver::class);
        $userId = $params['user_id'] ?? null;
        $isBackend = $params['is_backend'] ?? true;

        // 传递已注入配置的 model 实例，避免 ConfigResolver 重新从数据库加载
        return $configResolver->resolveConfig(
            $model->getModelCode(),
            $params['user_config'] ?? [],
            $userId ? (int)$userId : null,
            $isBackend,
            $model  // 传递已注入账户配置的模型实例
        );
    }
}
