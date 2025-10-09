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
use Weline\Ai\Service\DefaultModelManager;
use Weline\Ai\Service\AdapterScanner;
use Weline\Ai\Service\I18nIntegration;
use Weline\Ai\Service\Provider\ProviderFactory;
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
     * 构造函数
     * 
     * @param AiModel $aiModel
     * @param DefaultModelManager $defaultModelManager
     * @param AdapterScanner $adapterScanner
     * @param I18nIntegration $i18nIntegration
     * @param ProviderFactory $providerFactory
     * @param AiUsageLog $usageLog
     */
    public function __construct(
        AiModel $aiModel,
        DefaultModelManager $defaultModelManager,
        AdapterScanner $adapterScanner,
        I18nIntegration $i18nIntegration,
        ProviderFactory $providerFactory,
        AiUsageLog $usageLog
    ) {
        $this->aiModel = $aiModel;
        $this->defaultModelManager = $defaultModelManager;
        $this->adapterScanner = $adapterScanner;
        $this->i18nIntegration = $i18nIntegration;
        $this->providerFactory = $providerFactory;
        $this->usageLog = $usageLog;
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
        array $params = []
    ): string {
        // 1. 模型选择
        $model = $this->selectModel($modelCode, $scenarioCode);
        if (!$model) {
            throw new Exception('无法选择合适的AI模型');
        }

        // 2. 场景适配器处理
        $adaptedPrompt = $this->applyScenarioAdapter($prompt, $scenarioCode, $params);

        // 3. 语言验证
        if ($locale && !$this->i18nIntegration->isLocaleSupported($locale)) {
            throw new Exception("不支持的语言: {$locale}");
        }

        // 4. 调用AI模型API
        $response = $this->callModelApi($model, $adaptedPrompt, $params);

        // 5. 场景适配器后处理
        $processedResponse = $this->processScenarioResponse($response, $scenarioCode, $params);

        // 6. 语言处理
        if ($locale) {
            $processedResponse = $this->processLanguageResponse($processedResponse, $locale);
        }

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
            throw new Exception('无法选择合适的AI模型');
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
        // 1. 如果指定了模型代码，优先使用
        if ($modelCode) {
            $model = $this->aiModel->reset()
                ->where(AiModel::fields_MODEL_CODE, $modelCode)
                ->where(AiModel::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($model->getId()) {
                return $model;
            }
        }

        // 2. 根据场景代码选择默认模型
        if ($scenarioCode) {
            $model = $this->defaultModelManager->getDefaultModel($scenarioCode);
            if ($model) {
                return $model;
            }
        }

        // 3. 使用全局默认模型
        return $this->defaultModelManager->getDefaultModel();
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
            return $prompt;
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
            return $response;
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
        try {
            // 获取合适的提供者
            $provider = $this->providerFactory->getProvider($model);
            
            // 调用API
            $result = $provider->generate($model, $prompt, $params);
            
            // 记录使用量
            $this->logUsage($model, $result['usage'], $params);
            
            return $result['content'];
            
        } catch (\Exception $e) {
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
        try {
            // 获取合适的提供者
            $provider = $this->providerFactory->getProvider($model);
            
            // 流式调用API
            $result = $provider->generateStream($model, $prompt, $callback, $params);
            
            // 记录使用量
            $this->logUsage($model, $result['usage'], $params);
            
        } catch (\Exception $e) {
            // 记录错误
            error_log("AI流式API调用失败: " . $e->getMessage());
            throw new Exception("AI流式生成失败: " . $e->getMessage());
        }
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
                'code' => $model->getData(AiModel::fields_CODE),
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
}