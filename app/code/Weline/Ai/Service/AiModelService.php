<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\ConnectionFactory;

/**
 * AI Model Service
 * 
 * Handles business logic for AI model management including copying and validation.
 * 
 * @package Weline_Ai
 */
class AiModelService
{
    private AiModel $aiModel;

    public function __construct(AiModel $aiModel)
    {
        $this->aiModel = $aiModel;
    }
    
    /**
     * Get database connection
     */
    private function getConnection()
    {
        $connFactory = ObjectManager::getInstance(ConnectionFactory::class);
        return $connFactory->getConnection();
    }

    /**
     * Get model by ID
     *
     * @param int $modelId
     * @return AiModel
     * @throws \RuntimeException
     */
    public function getById(int $modelId): AiModel
    {
        $model = clone $this->aiModel;
        $model->load($modelId);
        
        if (!$model->getId()) {
            throw new \RuntimeException("Model with ID {$modelId} not found");
        }

        return $model;
    }

    /**
     * Get model by code
     *
     * @param string $modelCode
     * @return AiModel
     * @throws \RuntimeException
     */
    public function getByCode(string $modelCode): AiModel
    {
        $model = clone $this->aiModel;
        $model->load($modelCode, 'model_code');
        
        if (!$model->getId()) {
            throw new \RuntimeException("Model with code {$modelCode} not found");
        }

        return $model;
    }

    /**
     * Copy a model
     *
     * @param int $originModelId
     * @param string $newName
     * @param array $config Additional configuration
     * @return AiModel
     * @throws \RuntimeException
     */
    public function copyModel(int $originModelId, string $newName, array $config = []): AiModel
    {
        $originModel = $this->getById($originModelId);

        // Verify origin model is not a copy
        if ($originModel->isCopy()) {
            throw new \RuntimeException('Cannot copy a copy model. Only original models can be copied.');
        }

        // Create new model as copy
        $copyModel = clone $this->aiModel;
        $copyModel->setData([
            'supplier' => $originModel->getData('supplier'),
            'model_code' => $originModel->getData('model_code') . '_copy_' . time(),
            'name' => $newName,
            'version' => $originModel->getData('version'),
            'is_copy' => true,
            'origin_model_id' => $originModelId,
            'config' => array_merge($originModel->getConfig(), $config),
            'capabilities' => $originModel->getCapabilities(),
            'max_tokens' => $originModel->getData('max_tokens'),
            'cost_per_token' => $originModel->getData('cost_per_token'),
            'status' => AiModel::STATUS_ACTIVE,
        ]);

        $copyModel->save();

        return $copyModel;
    }

    /**
     * Delete a model
     *
     * @param int $modelId
     * @return bool
     * @throws \RuntimeException
     */
    public function deleteModel(int $modelId): bool
    {
        $model = $this->getById($modelId);

        if (!$model->canDelete()) {
            throw new \RuntimeException(
                'Cannot delete original model. Original models are protected from deletion.'
            );
        }

        return $model->delete();
    }

    /**
     * Get all active models
     *
     * @return array
     */
    public function getActiveModels(): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from('ai_model')
            ->where('status', AiModel::STATUS_ACTIVE)
            ->order('supplier ASC')
            ->order('name ASC');

        $results = $connection->fetch($select);
        
        $models = [];
        foreach ($results as $data) {
            $model = clone $this->aiModel;
            $model->setData($data);
            $models[] = $model;
        }

        return $models;
    }

    /**
     * Get all copy models for an origin model
     *
     * @param int $originModelId
     * @return array
     */
    public function getCopyModels(int $originModelId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from('ai_model')
            ->where('origin_model_id', $originModelId)
            ->where('is_copy', 1)
            ->order('created_at DESC');

        $results = $connection->fetch($select);
        
        $models = [];
        foreach ($results as $data) {
            $model = clone $this->aiModel;
            $model->setData($data);
            $models[] = $model;
        }

        return $models;
    }

    /**
     * Update model configuration
     *
     * @param int $modelId
     * @param array $config
     * @return AiModel
     */
    public function updateConfig(int $modelId, array $config): AiModel
    {
        $model = $this->getById($modelId);
        $currentConfig = $model->getConfig();
        $newConfig = array_merge($currentConfig, $config);
        
        $model->setData('config', $newConfig);
        $model->save();

        return $model;
    }
}

