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
use Weline\Ai\Service\DefaultModelManager;
use Weline\Framework\System\File\Scan;
use Weline\Framework\App\Exception;

/**
 * AI模型收集器服务
 * 
 * 功能：
 * - 自动扫描模型配置文件
 * - 注册新发现的模型到数据库
 * - 更新现有模型信息
 * - 支持模型版本控制
 * - 提供模型删除保护机制
 */
class ModelCollector
{
    /**
     * 模型配置目录
     */
    private const MODEL_CONFIG_DIR = 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Ai' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR;
    
    /**
     * 配置文件扩展名
     */
    private const CONFIG_FILE_EXTENSION = '.json';

    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * @var Scan
     */
    private Scan $fileScanner;

    /**
     * @var DefaultModelManager
     */
    private DefaultModelManager $defaultModelManager;

    /**
     * 构造函数
     * 
     * @param AiModel $aiModel
     * @param Scan $fileScanner
     * @param DefaultModelManager $defaultModelManager
     */
    public function __construct(
        AiModel $aiModel,
        Scan $fileScanner,
        DefaultModelManager $defaultModelManager
    ) {
        $this->aiModel = $aiModel;
        $this->fileScanner = $fileScanner;
        $this->defaultModelManager = $defaultModelManager;
    }

    /**
     * 收集所有模型
     * 
     * @return array
     * @throws Exception
     */
    public function collectAllModels(): array
    {
        $configDir = BP . DIRECTORY_SEPARATOR . self::MODEL_CONFIG_DIR;
        
        if (!is_dir($configDir)) {
            throw new Exception(__('模型配置目录不存在: %{dir}', ['dir' => $configDir]));
        }

        $pattern = $configDir . '*' . self::CONFIG_FILE_EXTENSION;
        $configFiles = glob($pattern);
        
        if ($configFiles === false) {
            $configFiles = [];
        }
        
        $collectedModels = [];

        foreach ($configFiles as $configFile) {
            try {
                $modelData = $this->parseModelConfig($configFile);
                if ($modelData) {
                    $result = $this->registerModel($modelData);
                    if (!empty($result['model'])) {
                        $collectedModels[] = $result['model'];
                    }
                }
            } catch (\Exception $e) {
                // 记录错误但继续处理其他模型
                w_log_error(__('处理模型配置文件失败: %{file}, 错误: %{msg}', ['file' => $configFile, 'msg' => $e->getMessage()]));
            }
        }

        return $collectedModels;
    }

    /**
     * 按数组直接注册/更新模型到数据库（不读写 etc/models 文件）
     * 供同步服务等调用，入参与 parseModelConfig / buildModelConfig 产出结构兼容。
     *
     * @param array $modelData 须含 model_code、vendor；可选 name/model_name、config、token_price 等
     * @return array{model: AiModel|null, created: bool}
     */
    public function registerModelFromArray(array $modelData): array
    {
        if (empty($modelData['model_code']) || empty($modelData['vendor'])) {
            return ['model' => null, 'created' => false];
        }
        if (isset($modelData['model_name']) && !isset($modelData['name'])) {
            $modelData['name'] = $modelData['model_name'];
        }
        return $this->registerModel($modelData);
    }

    /**
     * 删除模型（带保护检查）
     * 
     * @param string $modelCode
     * @return array 返回操作结果
     */
    public function deleteModel(string $modelCode): array
    {
        // 检查模型是否存在
        $model = $this->aiModel->reset()
            ->where('model_code', $modelCode)
            ->find()
            ->fetch();

        if (!$model->getId()) {
            return [
                'success' => false,
                'message' => __('模型不存在'),
                'protected' => false
            ];
        }

        // 检查模型是否受保护
        if ($this->defaultModelManager->isProtectedModel($modelCode)) {
            $reason = $this->defaultModelManager->getProtectionReason($modelCode);
            return [
                'success' => false,
                'message' => __('无法删除受保护的模型'),
                'reason' => $reason,
                'protected' => true,
                'usage' => $this->defaultModelManager->getModelUsageAsDefault($modelCode)
            ];
        }

        try {
            // 执行删除
            $model->delete();
            
            return [
                'success' => true,
                'message' => __('模型删除成功'),
                'protected' => false
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('删除模型时发生错误: %{msg}', ['msg' => $e->getMessage()]),
                'protected' => false
            ];
        }
    }

    /**
     * 批量删除模型（带保护检查）
     * 
     * @param array $modelCodes
     * @return array
     */
    public function batchDeleteModels(array $modelCodes): array
    {
        $results = [];
        $successCount = 0;
        $protectedCount = 0;
        $errorCount = 0;

        foreach ($modelCodes as $modelCode) {
            $result = $this->deleteModel($modelCode);
            $results[$modelCode] = $result;

            if ($result['success']) {
                $successCount++;
            } elseif ($result['protected']) {
                $protectedCount++;
            } else {
                $errorCount++;
            }
        }

        return [
            'results' => $results,
            'summary' => [
                'total' => count($modelCodes),
                'success' => $successCount,
                'protected' => $protectedCount,
                'error' => $errorCount
            ]
        ];
    }

    /**
     * 检查模型保护状态
     * 
     * @param string $modelCode
     * @return array
     */
    public function checkModelProtection(string $modelCode): array
    {
        $isProtected = $this->defaultModelManager->isProtectedModel($modelCode);
        
        if (!$isProtected) {
            return [
                'protected' => false,
                'reason' => '',
                'usage' => []
            ];
        }

        return [
            'protected' => true,
            'reason' => $this->defaultModelManager->getProtectionReason($modelCode),
            'usage' => $this->defaultModelManager->getModelUsageAsDefault($modelCode)
        ];
    }

    /**
     * 获取所有受保护的模型
     * 
     * @return array
     */
    public function getProtectedModels(): array
    {
        $allModels = $this->aiModel->reset()
            ->select()
            ->fetch();

        $protectedModels = [];

        foreach ($allModels->getItems() as $model) {
            $modelCode = $model->getData('model_code');
            if ($this->defaultModelManager->isProtectedModel($modelCode)) {
                $protectedModels[] = [
                    'model_code' => $modelCode,
                    'model_name' => $model->getName(),
                    'vendor' => $model->getVendor(),
                    'reason' => $this->defaultModelManager->getProtectionReason($modelCode),
                    'usage' => $this->defaultModelManager->getModelUsageAsDefault($modelCode)
                ];
            }
        }

        return $protectedModels;
    }

    /**
     * 解析模型配置文件
     * 
     * @param string $configFile
     * @return array|null
     */
    private function parseModelConfig(string $configFile): ?array
    {
        if (!file_exists($configFile)) {
            return null;
        }

        $content = file_get_contents($configFile);
        if (!$content) {
            return null;
        }

        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('JSON解析错误: %{err}, 文件: %{file}', ['err' => json_last_error_msg(), 'file' => $configFile]));
        }

        // 验证必需字段（兼容name和model_name）
        $requiredFields = ['model_code', 'vendor'];
        foreach ($requiredFields as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                throw new Exception(__('缺少必需字段 %{field} 在文件: %{file}', ['field' => $field, 'file' => $configFile]));
            }
        }
        
        // 兼容name和model_name字段
        if (isset($config['model_name'])) {
            $config['name'] = $config['model_name'];
        } elseif (!isset($config['name']) || empty($config['name'])) {
            throw new Exception(__('缺少必需字段 name 或 model_name 在文件: %{file}', ['file' => $configFile]));
        }

        return $config;
    }

    /**
     * 注册模型到数据库
     *
     * @param array $modelData
     * @return array{model: AiModel|null, created: bool}
     */
    private function registerModel(array $modelData): array
    {
        $modelCode = $modelData['model_code'];

        // 先查询是否存在同 model_code 的多条记录，若有则去重（兼容不同返回类型）
        $items = [];
        $maybeCollection = $this->aiModel->reset()
            ->where('model_code', $modelCode)
            ->select()
            ->fetch();

        if ($maybeCollection) {
            if (is_array($maybeCollection)) {
                $items = $maybeCollection;
            } elseif (method_exists($maybeCollection, 'getItems')) {
                $items = $maybeCollection->getItems();
            } else {
                // 单条或未知类型，统一转数组
                $items = [$maybeCollection];
            }
        }

        // 过滤无效项
        $items = array_values(array_filter($items, function ($m) {
            return $m && is_object($m) && method_exists($m, 'getId') && $m->getId();
        }));

        if (count($items) > 1) {
            /** @var AiModel $keep */
            $keep = array_shift($items);
            foreach ($items as $dup) {
                try {
                    $dup->delete();
                } catch (\Exception $e) { /* ignore */
                }
            }
            $model = $this->updateExistingModel($keep, $modelData);
            return ['model' => $model, 'created' => false];
        }
        if (count($items) === 1) {
            $model = $this->updateExistingModel($items[0], $modelData);
            return ['model' => $model, 'created' => false];
        }

        // 若不存在任何记录，则单独查询一条（兼容不同驱动返回）
        $existingModel = $this->aiModel->reset()
            ->where('model_code', $modelCode)
            ->find()
            ->fetch();

        if ($existingModel && $existingModel->getId()) {
            $model = $this->updateExistingModel($existingModel, $modelData);
            return ['model' => $model, 'created' => false];
        }

        // 创建新模型
        $model = $this->createNewModel($modelData);
        return ['model' => $model, 'created' => true];
    }

    /**
     * 创建新模型
     * 
     * @param array $modelData
     * @return AiModel
     */
    private function createNewModel(array $modelData): AiModel
    {
        // 新收集的模型一律置为未激活，等待连通性测试通过后再启用
        $isActive = (int)($modelData['is_active'] ?? 0) === 1 ? 1 : 0;

        $data = [
            'model_code' => $modelData['model_code'],
            'name' => $modelData['name'] ?? $modelData['model_code'], // 如果没有name，使用model_code作为name
            'supplier' => $modelData['vendor'],
            'version' => $modelData['model_version'] ?? '1.0',
            'primary_modality' => AiModel::normalizePrimaryModality((string)($modelData['primary_modality'] ?? '')),
            'config' => json_encode($modelData['config'] ?? []),
            'cost_per_token' => (string)($modelData['token_price_input'] ?? $modelData['token_price'] ?? 0.000000),
            // TODO(临时): 注释掉这两个字段，因为ORM报告字段不存在（虽然数据库中确实有这些字段）
            // 'token_price_input' => (float)($modelData['token_price_input'] ?? $modelData['token_price'] ?? 0.000000),
            // 'token_price_output' => (float)($modelData['token_price_output'] ?? $modelData['token_price'] ?? 0.000000),
            'status' => $isActive === 1 ? 'active' : 'inactive',
            'is_active' => $isActive,
            'is_default' => (int)($modelData['is_default'] ?? 0) === 1 ? 1 : 0,
            'is_copy' => 0,
            'origin_model_id' => null,
            'capabilities' => json_encode($modelData['capabilities'] ?? []),
            'max_tokens' => $modelData['max_tokens'] ?? null,
            // 兼容字段：确保外部查询能正常工作
            'vendor' => $modelData['vendor'],
            'product' => $modelData['product'] ?? '',
            'model' => $modelData['model'] ?? $modelData['model_code'],
            'class' => $modelData['class'] ?? '',
            'default_api_key' => $modelData['default_api_key'] ?? '',
            'default_api_url' => $modelData['default_api_url'] ?? ($modelData['config']['base_url'] ?? ''),
        ];

        $model = $this->aiModel->reset();
        
        foreach ($data as $key => $value) {
            $model->setData($key, $value);
        }
        
        $model->save();
        
        return $model;
    }

    /**
     * 更新现有模型
     * 
     * @param AiModel $existingModel
     * @param array $modelData
     * @return AiModel
     */
    private function updateExistingModel(AiModel $existingModel, array $modelData): AiModel
    {
        // 更新时不改动激活状态，保持现状（避免收集动作把模型激活）

        // 只更新允许更新的字段，且只覆盖没有配置的项
        // 1. 基本字段：只更新空值或未设置的字段
        if (empty($existingModel->getData('name')) && !empty($modelData['name'])) {
            $existingModel->setData('name', $modelData['name']);
        }
        
        if (empty($existingModel->getData('supplier')) && !empty($modelData['vendor'])) {
            $existingModel->setData('supplier', $modelData['vendor']);
        }
        
        if (empty($existingModel->getData('version')) && !empty($modelData['model_version'])) {
            $existingModel->setData('version', $modelData['model_version'] ?? '1.0');
        }

        if (
            empty($existingModel->getData(AiModel::schema_fields_PRIMARY_MODALITY))
            && !empty($modelData['primary_modality'])
        ) {
            $existingModel->setPrimaryModality((string)$modelData['primary_modality']);
        }

        // 2. 配置字段：合并配置，只添加不存在的项
        $existingConfig = [];
        $existingConfigJson = $existingModel->getData('config');
        if (!empty($existingConfigJson)) {
            if (is_string($existingConfigJson)) {
                $decoded = json_decode($existingConfigJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $existingConfig = $decoded;
                }
            } elseif (is_array($existingConfigJson)) {
                $existingConfig = $existingConfigJson;
            }
        }
        
        $newConfig = $modelData['config'] ?? [];
        // 合并配置：新配置只填充现有配置中不存在的键
        $mergedConfig = $existingConfig;
        foreach ($newConfig as $key => $value) {
            if (!isset($mergedConfig[$key]) || empty($mergedConfig[$key])) {
                $mergedConfig[$key] = $value;
            }
        }
        $existingModel->setData('config', json_encode($mergedConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // 3. 价格字段：只更新空值或0值
        $existingCostPerToken = $existingModel->getData('cost_per_token');
        if (empty($existingCostPerToken) || $existingCostPerToken == '0' || $existingCostPerToken == '0.000000') {
            $newCostPerToken = (string)($modelData['token_price_input'] ?? $modelData['token_price'] ?? 0.000000);
            if ($newCostPerToken != '0' && $newCostPerToken != '0.000000') {
                $existingModel->setData('cost_per_token', $newCostPerToken);
            }
        }
        
        $existingTokenPriceInput = $existingModel->getData('token_price_input');
        if (empty($existingTokenPriceInput) || $existingTokenPriceInput == 0) {
            $newTokenPriceInput = (float)($modelData['token_price_input'] ?? $modelData['token_price'] ?? 0.000000);
            if ($newTokenPriceInput > 0) {
                $existingModel->setData('token_price_input', $newTokenPriceInput);
            }
        }
        
        $existingTokenPriceOutput = $existingModel->getData('token_price_output');
        if (empty($existingTokenPriceOutput) || $existingTokenPriceOutput == 0) {
            $newTokenPriceOutput = (float)($modelData['token_price_output'] ?? $modelData['token_price'] ?? 0.000000);
            if ($newTokenPriceOutput > 0) {
                $existingModel->setData('token_price_output', $newTokenPriceOutput);
            }
        }

        // 4. capabilities 字段：合并配置
        $existingCapabilities = [];
        $existingCapabilitiesJson = $existingModel->getData('capabilities');
        if (!empty($existingCapabilitiesJson)) {
            if (is_string($existingCapabilitiesJson)) {
                $decoded = json_decode($existingCapabilitiesJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $existingCapabilities = $decoded;
                }
            } elseif (is_array($existingCapabilitiesJson)) {
                $existingCapabilities = $existingCapabilitiesJson;
            }
        }
        
        $newCapabilities = $modelData['capabilities'] ?? [];
        // 合并 capabilities：新配置只填充现有配置中不存在的键
        $mergedCapabilities = $existingCapabilities;
        $removeCapabilities = \array_map(
            static fn(mixed $value): string => \strtolower(\trim((string)$value)),
            \is_array($modelData['capabilities_remove'] ?? null) ? $modelData['capabilities_remove'] : []
        );
        $removeCapabilities = \array_values(\array_filter($removeCapabilities, static fn(string $value): bool => $value !== ''));
        if ($removeCapabilities !== []) {
            foreach ($mergedCapabilities as $key => $value) {
                $capability = \is_string($key) ? $key : (string)$value;
                if (\in_array(\strtolower(\trim($capability)), $removeCapabilities, true)) {
                    unset($mergedCapabilities[$key]);
                }
            }
            $mergedCapabilities = \array_values($mergedCapabilities);
        }
        foreach ($newCapabilities as $key => $value) {
            if (!isset($mergedCapabilities[$key]) || empty($mergedCapabilities[$key])) {
                $mergedCapabilities[$key] = $value;
            }
        }
        $existingModel->setData('capabilities', json_encode($mergedCapabilities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // 5. max_tokens：只更新空值或0值
        $existingMaxTokens = $existingModel->getData('max_tokens');
        if (empty($existingMaxTokens) || $existingMaxTokens == 0) {
            $newMaxTokens = $modelData['max_tokens'] ?? null;
            if (!empty($newMaxTokens) && $newMaxTokens > 0) {
                $existingModel->setData('max_tokens', $newMaxTokens);
            }
        }

        // 注意：不更新 is_default、status、is_active 字段，避免覆盖用户设置

        $existingModel->save();
        
        return $existingModel;
    }

    /**
     * 获取模型配置模板
     * 
     * @return array
     */
    public function getModelConfigTemplate(): array
    {
        return [
            'model_code' => 'openai_gpt-3.5-turbo',
            'name' => 'OpenAI GPT-3.5 Turbo',
            'vendor' => 'OpenAI',
            'config' => [
                'api_key_env' => 'OPENAI_API_KEY',
                'base_url' => 'https://api.openai.com/v1',
                'model_id' => 'gpt-3.5-turbo',
                'max_tokens' => 4096,
                'temperature' => 0.7
            ],
            'token_price' => 0.0015,
            'proxy_info' => [],
            'status' => 'active',
            'is_default' => 0
        ];
    }

    /**
     * 创建模型配置文件
     * 
     * @param string $modelCode
     * @param array $config
     * @return bool
     */
    public function createModelConfigFile(string $modelCode, array $config): bool
    {
        $configDir = BP . DIRECTORY_SEPARATOR . self::MODEL_CONFIG_DIR;
        
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $filename = $modelCode . self::CONFIG_FILE_EXTENSION;
        $filepath = $configDir . $filename;

        $jsonContent = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        return file_put_contents($filepath, $jsonContent) !== false;
    }

    /**
     * 验证模型配置
     * 
     * @param array $config
     * @return array 验证错误列表
     */
    public function validateModelConfig(array $config): array
    {
        $errors = [];

        // 检查必需字段
        $requiredFields = [
            'model_code' => '模型代码',
            'name' => '模型名称',
            'vendor' => '供应商'
        ];

        foreach ($requiredFields as $field => $label) {
            if (!isset($config[$field]) || empty($config[$field])) {
                $errors[] = __('缺少必需字段: %{label}', ['label' => $label]);
            }
        }

        // 检查数据类型
        if (isset($config['token_price']) && !is_numeric($config['token_price'])) {
            $errors[] = __('Token价格必须是数字');
        }

        if (isset($config['is_default']) && !is_bool($config['is_default']) && !in_array($config['is_default'], [0, 1])) {
            $errors[] = __('默认状态必须是布尔值或0/1');
        }

        // 检查状态值
        if (isset($config['status']) && !in_array($config['status'], ['active', 'inactive'])) {
            $errors[] = __('状态值必须是 active 或 inactive');
        }

        return $errors;
    }

    /**
     * 获取模型统计信息
     * 
     * @return array
     */
    public function getModelStats(): array
    {
        $totalCount = $this->aiModel->reset()
            ->select()
            ->fetch()
            ->count();

        $activeCount = $this->aiModel->reset()
            ->where('status', 'active')
            ->select()
            ->fetch()
            ->count();

        $protectedModels = $this->getProtectedModels();
        $protectedCount = count($protectedModels);

        return [
            'total' => $totalCount,
            'active' => $activeCount,
            'inactive' => $totalCount - $activeCount,
            'protected' => $protectedCount,
            'deletable' => $totalCount - $protectedCount
        ];
    }
}
