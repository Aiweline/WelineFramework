<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiModelDeployment;
use Weline\Framework\Manager\ObjectManager;

/**
 * Model Deployment Service
 * 
 * Manages AI model deployment lifecycle.
 * 
 * @package Weline_Ai
 */
class ModelDeploymentService
{
    private AiModelDeployment $deployment;

    public function __construct(AiModelDeployment $deployment)
    {
        $this->deployment = $deployment;
    }

    /**
     * Create a new deployment
     *
     * @param int $modelId
     * @param string $deploymentName
     * @param string $deploymentType
     * @param string|null $deploymentUrl
     * @return AiModelDeployment
     */
    public function createDeployment(
        int $modelId,
        string $deploymentName,
        string $deploymentType,
        ?string $deploymentUrl = null
    ): AiModelDeployment {
        $deployment = clone $this->deployment;
        $deployment->setData([
            AiModelDeployment::fields_MODEL_ID => $modelId,
            AiModelDeployment::fields_DEPLOYMENT_NAME => $deploymentName,
            AiModelDeployment::fields_DEPLOYMENT_TYPE => $deploymentType,
            AiModelDeployment::fields_DEPLOYMENT_STATUS => AiModelDeployment::STATUS_PENDING,
            AiModelDeployment::fields_DEPLOYMENT_URL => $deploymentUrl,
        ]);
        $deployment->save();

        return $deployment;
    }

    /**
     * Update deployment status
     *
     * @param int $deploymentId
     * @param string $status
     * @return bool
     */
    public function updateStatus(int $deploymentId, string $status): bool
    {
        $deployment = clone $this->deployment;
        $deployment->load($deploymentId);
        
        if (!$deployment->getId()) {
            return false;
        }

        $deployment->setData(AiModelDeployment::fields_DEPLOYMENT_STATUS, $status);
        
        // Set deployed_at timestamp if status is active
        if ($status === AiModelDeployment::STATUS_ACTIVE) {
            $deployment->setData(AiModelDeployment::fields_DEPLOYED_AT, date('Y-m-d H:i:s'));
        }
        
        return $deployment->save();
    }

    /**
     * Get deployments by model ID
     *
     * @param int $modelId
     * @return array
     */
    public function getByModelId(int $modelId): array
    {
        $results = [];
        $collection = clone $this->deployment;
        $items = $collection->where(AiModelDeployment::fields_MODEL_ID, $modelId)
            ->order(AiModelDeployment::fields_CREATED_AT, 'DESC')
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
     * Get active deployments
     *
     * @return array
     */
    public function getActiveDeployments(): array
    {
        $results = [];
        $collection = clone $this->deployment;
        $items = $collection->where(AiModelDeployment::fields_DEPLOYMENT_STATUS, AiModelDeployment::STATUS_ACTIVE)
            ->order(AiModelDeployment::fields_DEPLOYED_AT, 'DESC')
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
     * Delete deployment
     *
     * @param int $deploymentId
     * @return bool
     */
    public function deleteDeployment(int $deploymentId): bool
    {
        $deployment = clone $this->deployment;
        $deployment->load($deploymentId);
        
        if (!$deployment->getId()) {
            return false;
        }

        return $deployment->delete();
    }
}
