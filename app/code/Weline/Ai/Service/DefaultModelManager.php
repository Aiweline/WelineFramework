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
}
