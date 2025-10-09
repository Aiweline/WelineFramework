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
            throw new Exception("模型配置目录不存在: {$configDir}");
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
                    $model = $this->registerModel($modelData);
                    if ($model) {
                        $collectedModels[] = $model;
                    }
                }
            } catch (\Exception $e) {
                // 记录错误但继续处理其他模型
                error_log("处理模型配置文件失败: {$configFile}, 错误: " . $e->getMessage());
            }
        }

        return $collectedModels;
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
            ->where(AiModel::fields_MODEL_CODE, $modelCode)
            ->find()
            ->fetch();

        if (!$model->getId()) {
            return [
                'success' => false,
                'message' => '模型不存在',
                'protected' => false
            ];
        }

        // 检查模型是否受保护
        if ($this->defaultModelManager->isProtectedModel($modelCode)) {
            $reason = $this->defaultModelManager->getProtectionReason($modelCode);
            return [
                'success' => false,
                'message' => '无法删除受保护的模型',
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
                'message' => '模型删除成功',
                'protected' => false
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '删除模型时发生错误: ' . $e->getMessage(),
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
            $modelCode = $model->getData(AiModel::fields_MODEL_CODE);
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
            throw new Exception("JSON解析错误: " . json_last_error_msg() . ", 文件: {$configFile}");
        }

        // 验证必需字段（兼容name和model_name）
        $requiredFields = ['model_code', 'vendor'];
        foreach ($requiredFields as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                throw new Exception("缺少必需字段 '{$field}' 在文件: {$configFile}");
            }
        }
        
        // 兼容name和model_name字段
        if (isset($config['model_name'])) {
            $config['name'] = $config['model_name'];
        } elseif (!isset($config['name']) || empty($config['name'])) {
            throw new Exception("缺少必需字段 'name' 或 'model_name' 在文件: {$configFile}");
        }

        return $config;
    }

    /**
     * 注册模型到数据库
     * 
     * @param array $modelData
     * @return AiModel|null
     */
    private function registerModel(array $modelData): ?AiModel
    {
        $modelCode = $modelData['model_code'];
        
        // 检查模型是否已存在
        $existingModel = $this->aiModel->reset()
            ->where(AiModel::fields_MODEL_CODE, $modelCode)
            ->find()
            ->fetch();

        if ($existingModel->getId()) {
            // 更新现有模型
            return $this->updateExistingModel($existingModel, $modelData);
        } else {
            // 创建新模型
            return $this->createNewModel($modelData);
        }
    }

    /**
     * 创建新模型
     * 
     * @param array $modelData
     * @return AiModel
     */
    private function createNewModel(array $modelData): AiModel
    {
        // 处理is_active字段，兼容status和is_active两种格式
        $isActive = 1;
        if (isset($modelData['status'])) {
            $isActive = $modelData['status'] === 'active' ? 1 : 0;
        } elseif (isset($modelData['is_active'])) {
            $isActive = (int)$modelData['is_active'];
        }

        $data = [
            AiModel::fields_MODEL_CODE => $modelData['model_code'],
            AiModel::fields_MODEL_NAME => $modelData['name'],
            AiModel::fields_VENDOR => $modelData['vendor'],
            AiModel::fields_MODEL_VERSION => $modelData['model_version'] ?? '1.0',
            AiModel::fields_CONFIG_JSON => json_encode($modelData['config'] ?? []),
            AiModel::fields_TOKEN_PRICE_INPUT => $modelData['token_price_input'] ?? $modelData['token_price'] ?? 0.000000,
            AiModel::fields_TOKEN_PRICE_OUTPUT => $modelData['token_price_output'] ?? $modelData['token_price'] ?? 0.000000,
            AiModel::fields_PROXY_INFO => json_encode($modelData['proxy_info'] ?? []),
            AiModel::fields_IS_ACTIVE => $isActive,
            AiModel::fields_IS_DEFAULT => $modelData['is_default'] ?? 0,
            AiModel::fields_CREATED_TIME => time(),
            AiModel::fields_UPDATED_TIME => time()
        ];

        $model = $this->aiModel->reset();
        $model->setData($data)->save();
        
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
        // 处理is_active字段，兼容status和is_active两种格式
        $isActive = 1;
        if (isset($modelData['status'])) {
            $isActive = $modelData['status'] === 'active' ? 1 : 0;
        } elseif (isset($modelData['is_active'])) {
            $isActive = (int)$modelData['is_active'];
        }

        // 只更新允许更新的字段
        $updateData = [
            AiModel::fields_MODEL_NAME => $modelData['name'],
            AiModel::fields_VENDOR => $modelData['vendor'],
            AiModel::fields_MODEL_VERSION => $modelData['model_version'] ?? '1.0',
            AiModel::fields_CONFIG_JSON => json_encode($modelData['config'] ?? []),
            AiModel::fields_TOKEN_PRICE_INPUT => $modelData['token_price_input'] ?? $modelData['token_price'] ?? 0.000000,
            AiModel::fields_TOKEN_PRICE_OUTPUT => $modelData['token_price_output'] ?? $modelData['token_price'] ?? 0.000000,
            AiModel::fields_PROXY_INFO => json_encode($modelData['proxy_info'] ?? []),
            AiModel::fields_IS_ACTIVE => $isActive,
            AiModel::fields_UPDATED_TIME => time()
        ];

        // 注意：不更新 is_default 字段，避免覆盖用户设置

        foreach ($updateData as $field => $value) {
            $existingModel->setData($field, $value);
        }

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
                $errors[] = "缺少必需字段: {$label}";
            }
        }

        // 检查数据类型
        if (isset($config['token_price']) && !is_numeric($config['token_price'])) {
            $errors[] = 'Token价格必须是数字';
        }

        if (isset($config['is_default']) && !is_bool($config['is_default']) && !in_array($config['is_default'], [0, 1])) {
            $errors[] = '默认状态必须是布尔值或0/1';
        }

        // 检查状态值
        if (isset($config['status']) && !in_array($config['status'], ['active', 'inactive'])) {
            $errors[] = '状态值必须是 active 或 inactive';
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
            ->where(AiModel::fields_IS_ACTIVE, 1)
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