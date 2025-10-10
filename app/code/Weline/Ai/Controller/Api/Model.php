<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Ai\Service\AiModelService;

/**
 * Model API Controller
 * 
 * Handles model retrieval and copying operations.
 * 
 * @package Weline_Ai
 */
class Model extends FrontendRestController
{
    public function __construct(
        private readonly AiModelService $modelService
    ) {
    }

    /**
     * GET /api/v1/model/{id}
     * 
     * Get model information by ID
     *
     * @return array
     */
    public function get(): array
    {
        try {
            $modelId = (int) $this->request->getParam('id');
            
            if (!$modelId) {
                return $this->error('Model ID is required', 400);
            }

            $model = $this->modelService->getById($modelId);

            return $this->success('请求成功', [
                'id' => $model->getId(),
                'supplier' => $model->getData('supplier'),
                'name' => $model->getData('name'),
                'model_code' => $model->getData('model_code'),
                'version' => $model->getData('version'),
                'is_copy' => $model->isCopy(),
                'origin_model_id' => $model->getOriginModelId(),
                'config' => $model->getConfig(),
                'capabilities' => $model->getCapabilities(),
                'max_tokens' => $model->getData('max_tokens'),
                'cost_per_token' => $model->getData('cost_per_token'),
                'status' => $model->getData('status'),
                'created_at' => $model->getData('created_at'),
                'updated_at' => $model->getData('updated_at'),
            ]);

        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\Exception $e) {
            return $this->error('Internal server error', 500);
        }
    }

    /**
     * POST /api/v1/model/{id}/copy
     * 
     * Copy an existing model
     *
     * @return array
     */
    public function copy(): array
    {
        try {
            $modelId = (int) $this->request->getParam('id');
            $data = $this->request->getBodyParams();

            if (!$modelId) {
                return $this->error('Model ID is required', 400);
            }

            if (empty($data['new_name'])) {
                return $this->error('New name is required', 400);
            }

            $newName = (string) $data['new_name'];
            $config = $data['config'] ?? [];

            // Copy the model
            $copyModel = $this->modelService->copyModel($modelId, $newName, $config);

            return $this->success('模型拷贝成功', [
                'model_id' => $copyModel->getId(),
                'origin_model_id' => $copyModel->getOriginModelId(),
                'name' => $copyModel->getData('name'),
                'is_copy' => true,
                'model_code' => $copyModel->getData('model_code'),
                'status' => $copyModel->getData('status'),
                'created_at' => $copyModel->getData('created_at'),
            ]);

        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->error('Internal server error', 500);
        }
    }

    /**
     * DELETE /api/v1/model/{id}
     * 
     * Delete a model (only copy models can be deleted)
     *
     * @return array
     */
    public function delete(): array
    {
        try {
            $modelId = (int) $this->request->getParam('id');

            if (!$modelId) {
                return $this->error('Model ID is required', 400);
            }

            $this->modelService->deleteModel($modelId);

            return $this->success('模型删除成功', [
                'model_id' => $modelId,
            ]);

        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->error('Internal server error', 500);
        }
    }

}

