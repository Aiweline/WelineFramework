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
use Weline\Ai\Model\AiDefaultModel;
use Weline\Framework\App\Exception;

/**
 * 默认模型管理服务
 * 
 * 功能：
 * - 管理不同服务类型的默认模型
 * - 提供模型选择和回退机制
 * - 支持优先级排序
 * - 保护默认模型不被删除
 */
class DefaultModelManager
{
    /**
     * 预定义服务类型
     */
    public const SERVICE_TYPE_DEFAULT = 'default';
    public const SERVICE_TYPE_TRANSLATION = 'translation';
    public const SERVICE_TYPE_CODE_GENERATION = 'code_generation';
    public const SERVICE_TYPE_TEXT_GENERATION = 'text_generation';
    public const SERVICE_TYPE_CHAT = 'chat';
    public const SERVICE_TYPE_COMPLETION = 'completion';

    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * @var AiDefaultModel
     */
    private AiDefaultModel $aiDefaultModel;

    /**
     * 默认模型缓存
     * 
     * @var array
     */
    private array $defaultModelCache = [];

    /**
     * 构造函数
     * 
     * @param AiModel $aiModel
     * @param AiDefaultModel $aiDefaultModel
     */
    public function __construct(
        AiModel $aiModel,
        AiDefaultModel $aiDefaultModel
    ) {
        $this->aiModel = $aiModel;
        $this->aiDefaultModel = $aiDefaultModel;
    }

    /**
     * 获取指定服务类型的默认模型
     * 
     * @param string $serviceType
     * @return AiModel|null
     */
    public function getDefaultModel(string $serviceType = self::SERVICE_TYPE_DEFAULT): ?AiModel
    {
        // 先从缓存获取
        if (isset($this->defaultModelCache[$serviceType])) {
            return $this->defaultModelCache[$serviceType];
        }

        // 查找指定服务类型的默认模型
        $defaultModelRecord = $this->aiDefaultModel->reset()
            ->where(AiDefaultModel::fields_SERVICE_TYPE, $serviceType)
            ->where(AiDefaultModel::fields_IS_ACTIVE, 1)
            ->order(AiDefaultModel::fields_PRIORITY, 'ASC')
            ->find()
            ->fetch();

        if ($defaultModelRecord->getId()) {
            $model = $this->aiModel->reset()
                ->where(AiModel::fields_MODEL_CODE, $defaultModelRecord->getModelCode())
                ->where(AiModel::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();

            if ($model->getId()) {
                $this->defaultModelCache[$serviceType] = $model;
                return $model;
            }
        }

        // 如果没有找到指定类型的默认模型，尝试获取全局默认模型
        if ($serviceType !== self::SERVICE_TYPE_DEFAULT) {
            return $this->getDefaultModel(self::SERVICE_TYPE_DEFAULT);
        }

        return null;
    }

    /**
     * 设置默认模型
     * 
     * @param string $serviceType
     * @param string $modelCode
     * @param int $priority
     * @return bool
     * @throws Exception
     */
    public function setDefaultModel(string $serviceType, string $modelCode, int $priority = 100): bool
    {
        // 验证模型是否存在且激活
        $model = $this->aiModel->reset()
            ->where(AiModel::fields_MODEL_CODE, $modelCode)
            ->where(AiModel::fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        if (!$model->getId()) {
            throw new Exception("模型 {$modelCode} 不存在或未激活");
        }

        // 检查是否已存在该服务类型的默认模型配置
        $existingDefault = $this->aiDefaultModel->reset()
            ->where(AiDefaultModel::fields_SERVICE_TYPE, $serviceType)
            ->find()
            ->fetch();

        if ($existingDefault->getId()) {
            // 更新现有配置
            $existingDefault->setData([
                AiDefaultModel::fields_MODEL_CODE => $modelCode,
                AiDefaultModel::fields_PRIORITY => $priority,
                AiDefaultModel::fields_IS_ACTIVE => 1,
                AiDefaultModel::fields_UPDATED_TIME => time()
            ])->save();
        } else {
            // 创建新配置
            $this->aiDefaultModel->reset()->setData([
                AiDefaultModel::fields_SERVICE_TYPE => $serviceType,
                AiDefaultModel::fields_MODEL_CODE => $modelCode,
                AiDefaultModel::fields_PRIORITY => $priority,
                AiDefaultModel::fields_IS_ACTIVE => 1,
                AiDefaultModel::fields_CREATED_TIME => time(),
                AiDefaultModel::fields_UPDATED_TIME => time()
            ])->save();
        }

        // 清除缓存
        unset($this->defaultModelCache[$serviceType]);

        return true;
    }

    /**
     * 移除默认模型配置
     * 
     * @param string $serviceType
     * @return bool
     * @throws Exception
     */
    public function removeDefaultModel(string $serviceType): bool
    {
        // 不允许移除全局默认模型
        if ($serviceType === self::SERVICE_TYPE_DEFAULT) {
            throw new Exception('不能移除全局默认模型配置');
        }

        $defaultModel = $this->aiDefaultModel->reset()
            ->where(AiDefaultModel::fields_SERVICE_TYPE, $serviceType)
            ->find()
            ->fetch();

        if ($defaultModel->getId()) {
            $defaultModel->setData(AiDefaultModel::fields_IS_ACTIVE, 0)->save();
            
            // 清除缓存
            unset($this->defaultModelCache[$serviceType]);
            
            return true;
        }

        return false;
    }

    /**
     * 获取所有默认模型配置
     * 
     * @return array
     */
    public function getAllDefaultModels(): array
    {
        try {
            $defaultModels = $this->aiDefaultModel->reset()
                ->where(AiDefaultModel::fields_IS_ACTIVE, 1)
                ->select()
                ->fetch();

            $result = [];
            
            if ($defaultModels && is_iterable($defaultModels)) {
                foreach ($defaultModels as $defaultModel) {
                    if (!is_object($defaultModel)) {
                        continue;
                    }
                    
                    $serviceType = $defaultModel->getData(AiDefaultModel::fields_SERVICE_TYPE);
                    $modelCode = $defaultModel->getData(AiDefaultModel::fields_MODEL_CODE);
                    
                    // 获取模型详细信息
                    $model = $this->aiModel->reset()
                        ->where(AiModel::fields_MODEL_CODE, $modelCode)
                        ->find()
                        ->fetch();

                    if ($model && $model->getId()) {
                        $result[] = [
                            'service_type' => $serviceType,
                            'service_type_name' => $this->getServiceTypeName($serviceType),
                            'model_code' => $modelCode,
                            'model_name' => $model->getData(AiModel::fields_MODEL_NAME),
                            'model_vendor' => $model->getData(AiModel::fields_VENDOR),
                            'priority' => $defaultModel->getData(AiDefaultModel::fields_PRIORITY),
                            'is_protected' => $this->isProtectedModel($modelCode)
                        ];
                    }
                }
            }

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 检查模型是否被设置为默认模型
     * 
     * @param string $modelCode
     * @return array 返回使用该模型作为默认的服务类型列表
     */
    public function getModelUsageAsDefault(string $modelCode): array
    {
        $usages = $this->aiDefaultModel->reset()
            ->where(AiDefaultModel::fields_MODEL_CODE, $modelCode)
            ->where(AiDefaultModel::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        $serviceTypes = [];
        
        foreach ($usages as $usage) {
            $serviceTypes[] = $usage->getData(AiDefaultModel::fields_SERVICE_TYPE);
        }

        return $serviceTypes;
    }

    /**
     * 检查模型是否受保护（不能删除）
     * 
     * @param string $modelCode
     * @return bool
     */
    public function isProtectedModel(string $modelCode): bool
    {
        $usages = $this->getModelUsageAsDefault($modelCode);
        return !empty($usages);
    }

    /**
     * 获取保护模型的原因
     * 
     * @param string $modelCode
     * @return string
     */
    public function getProtectionReason(string $modelCode): string
    {
        $usages = $this->getModelUsageAsDefault($modelCode);
        
        if (empty($usages)) {
            return '';
        }

        $serviceNames = array_map([$this, 'getServiceTypeName'], $usages);
        
        return '该模型被设置为以下服务的默认模型：' . implode('、', $serviceNames);
    }

    /**
     * 获取可用的服务类型列表
     * 
     * @return array
     */
    public function getAvailableServiceTypes(): array
    {
        return [
            self::SERVICE_TYPE_DEFAULT => '全局默认',
            self::SERVICE_TYPE_TRANSLATION => '翻译服务',
            self::SERVICE_TYPE_CODE_GENERATION => '代码生成',
            self::SERVICE_TYPE_TEXT_GENERATION => '文本生成',
            self::SERVICE_TYPE_CHAT => '聊天对话',
            self::SERVICE_TYPE_COMPLETION => '文本补全'
        ];
    }

    /**
     * 获取服务类型名称
     * 
     * @param string $serviceType
     * @return string
     */
    public function getServiceTypeName(string $serviceType): string
    {
        $serviceTypes = $this->getAvailableServiceTypes();
        return $serviceTypes[$serviceType] ?? $serviceType;
    }

    /**
     * 初始化默认配置
     * 
     * @return bool
     */
    public function initializeDefaults(): bool
    {
        // 检查是否已有全局默认模型
        $globalDefault = $this->aiDefaultModel->reset()
            ->where(AiDefaultModel::fields_SERVICE_TYPE, self::SERVICE_TYPE_DEFAULT)
            ->where(AiDefaultModel::fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        if (!$globalDefault->getId()) {
            // 查找第一个可用的模型作为全局默认
            try {
                $firstModel = $this->aiModel->reset()
                    ->where(AiModel::fields_IS_ACTIVE, 1)
                    ->select()
                    ->fetch();

                if ($firstModel && is_iterable($firstModel)) {
                    foreach ($firstModel as $model) {
                        if ($model && $model->getId()) {
                            $this->setDefaultModel(
                                self::SERVICE_TYPE_DEFAULT,
                                $model->getData(AiModel::fields_MODEL_CODE),
                                100
                            );
                            return true;
                        }
                    }
                }
            } catch (\Exception $e) {
                // 如果没有模型，返回false
                return false;
            }
        }

        return false;
    }

    /**
     * 清除所有缓存
     * 
     * @return void
     */
    public function clearCache(): void
    {
        $this->defaultModelCache = [];
    }

    /**
     * 验证默认模型配置的完整性
     * 
     * @return array 返回问题列表
     */
    public function validateDefaultModels(): array
    {
        $issues = [];

        $defaultModels = $this->aiDefaultModel->reset()
            ->where(AiDefaultModel::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        foreach ($defaultModels as $defaultModel) {
            $serviceType = $defaultModel->getData(AiDefaultModel::fields_SERVICE_TYPE);
            $modelCode = $defaultModel->getData(AiDefaultModel::fields_MODEL_CODE);

            // 检查对应的模型是否存在
            $model = $this->aiModel->reset()
                ->where(AiModel::fields_MODEL_CODE, $modelCode)
                ->find()
                ->fetch();

            if (!$model->getId()) {
                $issues[] = "服务类型 '{$serviceType}' 的默认模型 '{$modelCode}' 不存在";
            } elseif ($model->getStatus() !== 'active') {
                $issues[] = "服务类型 '{$serviceType}' 的默认模型 '{$modelCode}' 未激活";
            }
        }

        // 检查是否有全局默认模型
        $hasGlobalDefault = false;
        foreach ($defaultModels as $defaultModel) {
            if ($defaultModel->getData(AiDefaultModel::fields_SERVICE_TYPE) === self::SERVICE_TYPE_DEFAULT) {
                $hasGlobalDefault = true;
                break;
            }
        }

        if (!$hasGlobalDefault) {
            $issues[] = '缺少全局默认模型配置';
        }

        return $issues;
    }
}
