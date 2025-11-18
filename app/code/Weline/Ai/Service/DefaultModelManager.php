<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiDefaultModel;
use Weline\Framework\Manager\ObjectManager;

/**
 * Default Model Manager Service
 * 
 * Manages default model configurations for different service types.
 * 
 * @package Weline_Ai
 */
class DefaultModelManager
{
    private AiDefaultModel $defaultModel;

    public function __construct(AiDefaultModel $defaultModel)
    {
        $this->defaultModel = $defaultModel;
    }

    /**
     * Get default model for a service type
     *
     * @param string $serviceType
     * @return AiDefaultModel|null
     */
    public function getDefaultModelForService(string $serviceType): ?AiDefaultModel
    {
        $model = clone $this->defaultModel;
        $result = $model->where(AiDefaultModel::fields_SERVICE_TYPE, $serviceType)
            ->where(AiDefaultModel::fields_IS_DEFAULT, 1)
            ->order(AiDefaultModel::fields_PRIORITY, 'DESC')
            ->find()
            ->fetch();

        return $result && $result->getId() ? $result : null;
    }

    /**
     * Get all default models by priority
     *
     * @param string $serviceType
     * @return array
     */
    public function getAllByServiceType(string $serviceType): array
    {
        $results = [];
        $collection = clone $this->defaultModel;
        $items = $collection->where(AiDefaultModel::fields_SERVICE_TYPE, $serviceType)
            ->order(AiDefaultModel::fields_PRIORITY, 'DESC')
            ->select()
            ->fetch();

        if ($items) {
            foreach ($items as $item) {
                $results[] = $item;
            }
        }

        return $results;
    }

    /**
     * Set default model for service type
     *
     * @param string $modelCode
     * @param string $serviceType
     * @param int $priority
     * @return AiDefaultModel
     */
    public function setDefault(string $modelCode, string $serviceType, int $priority = 0): AiDefaultModel
    {
        // Clear existing defaults for this service type if setting priority 0
        if ($priority === 0) {
            $this->clearDefaults($serviceType);
        }

        $model = clone $this->defaultModel;
        $model->setData([
            AiDefaultModel::fields_MODEL_CODE => $modelCode,
            AiDefaultModel::fields_SERVICE_TYPE => $serviceType,
            AiDefaultModel::fields_IS_DEFAULT => 1,
            AiDefaultModel::fields_PRIORITY => $priority,
        ]);
        $model->save();

        return $model;
    }

    /**
     * Clear all defaults for a service type
     *
     * @param string $serviceType
     * @return void
     */
    public function clearDefaults(string $serviceType): void
    {
        $model = clone $this->defaultModel;
        $model->where(AiDefaultModel::fields_SERVICE_TYPE, $serviceType)
            ->update([AiDefaultModel::fields_IS_DEFAULT => 0]);
    }

    /**
     * Remove default model configuration
     *
     * @param int $configId
     * @return bool
     */
    public function remove(int $configId): bool
    {
        $model = clone $this->defaultModel;
        $model->load($configId);
        
        if (!$model->getId()) {
            return false;
        }

        return $model->delete();
    }

    /**
     * Get all default models
     *
     * @return array
     */
    public function getAllDefaultModels(): array
    {
        $collection = clone $this->defaultModel;
        $items = $collection->where(AiDefaultModel::fields_IS_DEFAULT, 1)
            ->order(AiDefaultModel::fields_SERVICE_TYPE, 'ASC')
            ->order(AiDefaultModel::fields_PRIORITY, 'DESC')
            ->select()
            ->fetch();

        if ($items && method_exists($items, 'getItems')) {
            return $items->getItems();
        }

        return is_array($items) ? $items : [];
    }

    /**
     * Get available service types
     *
     * @return array
     */
    public function getAvailableServiceTypes(): array
    {
        return [
            'chat' => __('聊天服务'),
            'translation' => __('翻译服务'),
            'code_generation' => __('代码生成'),
            'content_generation' => __('内容生成'),
            'image_generation' => __('图片生成'),
            'analysis' => __('数据分析'),
            'default' => __('默认服务'),
        ];
    }

    /**
     * Set default model for service type
     *
     * @param string $serviceType
     * @param string $modelCode
     * @param int $priority
     * @return bool
     */
    public function setDefaultModel(string $serviceType, string $modelCode, int $priority = 100): bool
    {
        try {
            // Find existing configuration
            $model = clone $this->defaultModel;
            $existing = $model->where(AiDefaultModel::fields_SERVICE_TYPE, $serviceType)
                ->where(AiDefaultModel::fields_MODEL_CODE, $modelCode)
                ->find()
                ->fetch();

            if ($existing && $existing->getId()) {
                // Update existing
                $existing->setData(AiDefaultModel::fields_IS_DEFAULT, 1);
                $existing->setData(AiDefaultModel::fields_PRIORITY, $priority);
                $existing->save();
            } else {
                // Create new
                $this->setDefault($modelCode, $serviceType, $priority);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Remove default model for service type
     *
     * @param string $serviceType
     * @return bool
     */
    public function removeDefaultModel(string $serviceType): bool
    {
        try {
            $model = clone $this->defaultModel;
            $model->where(AiDefaultModel::fields_SERVICE_TYPE, $serviceType)
                ->update([AiDefaultModel::fields_IS_DEFAULT => 0])
                ->fetch();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if model is protected
     *
     * @param string $modelCode
     * @return bool
     */
    public function isProtectedModel(string $modelCode): bool
    {
        $model = clone $this->defaultModel;
        $count = $model->where(AiDefaultModel::fields_MODEL_CODE, $modelCode)
            ->where(AiDefaultModel::fields_IS_DEFAULT, 1)
            ->count();

        return $count > 0;
    }

    /**
     * Get protection reason
     *
     * @param string $modelCode
     * @return string
     */
    public function getProtectionReason(string $modelCode): string
    {
        $model = clone $this->defaultModel;
        $configs = $model->where(AiDefaultModel::fields_MODEL_CODE, $modelCode)
            ->where(AiDefaultModel::fields_IS_DEFAULT, 1)
            ->select()
            ->fetch();

        $serviceTypes = [];
        if ($configs && method_exists($configs, 'getItems')) {
            foreach ($configs->getItems() as $config) {
                $serviceTypes[] = $config->getData(AiDefaultModel::fields_SERVICE_TYPE);
            }
        }

        if (empty($serviceTypes)) {
            return '';
        }

        return __('此模型被用作以下服务的默认模型：%{1}', implode(', ', $serviceTypes));
    }

    /**
     * Get model usage as default
     *
     * @param string $modelCode
     * @return array
     */
    public function getModelUsageAsDefault(string $modelCode): array
    {
        $model = clone $this->defaultModel;
        $configs = $model->where(AiDefaultModel::fields_MODEL_CODE, $modelCode)
            ->where(AiDefaultModel::fields_IS_DEFAULT, 1)
            ->select()
            ->fetch();

        $usage = [];
        if ($configs && method_exists($configs, 'getItems')) {
            foreach ($configs->getItems() as $config) {
                $usage[] = [
                    'service_type' => $config->getData(AiDefaultModel::fields_SERVICE_TYPE),
                    'priority' => $config->getData(AiDefaultModel::fields_PRIORITY),
                ];
            }
        }

        return $usage;
    }

    /**
     * Initialize defaults
     *
     * @return bool
     */
    public function initializeDefaults(): bool
    {
        // Check if defaults already exist
        $model = clone $this->defaultModel;
        $count = $model->where(AiDefaultModel::fields_IS_DEFAULT, 1)->count();

        if ($count > 0) {
            return false; // Already initialized
        }

        // Set default configurations
        // This is just a placeholder, actual defaults should be configured based on business requirements
        return true;
    }

    /**
     * Validate default models
     *
     * @return array
     */
    public function validateDefaultModels(): array
    {
        $issues = [];
        $serviceTypes = array_keys($this->getAvailableServiceTypes());

        foreach ($serviceTypes as $serviceType) {
            $defaultModel = $this->getDefaultModelForService($serviceType);
            if (!$defaultModel) {
                $issues[] = __('服务类型 %{1} 没有配置默认模型', $serviceType);
            }
        }

        return $issues;
    }

    /**
     * Get default model
     *
     * @param string $serviceType
     * @return \Weline\Ai\Model\AiModel|null
     */
    public function getDefaultModel(string $serviceType): ?\Weline\Ai\Model\AiModel
    {
        $defaultModelConfig = $this->getDefaultModelForService($serviceType);
        
        if (!$defaultModelConfig) {
            return null;
        }

        $modelCode = $defaultModelConfig->getData(AiDefaultModel::fields_MODEL_CODE);
        $aiModel = ObjectManager::getInstance(\Weline\Ai\Model\AiModel::class);
        $model = $aiModel->where(\Weline\Ai\Model\AiModel::fields_MODEL_CODE, $modelCode)
            ->find()
            ->fetch();

        return $model && $model->getId() ? $model : null;
    }

    /**
     * Clear cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        // Clear any cached default model configurations
        // This is a placeholder for cache clearing logic
    }

    /**
     * Service type constant
     */
    public const SERVICE_TYPE_DEFAULT = 'default';
}
