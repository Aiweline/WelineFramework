<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Ai\Service\AiModelService;

/**
 * Model API Controller
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
     * GET /ai/rest/v1/model/{id}
     */
    public function getModel()
    {
        try {
            $modelId = (int) $this->request->getParam('id');
            
            if (!$modelId) {
                return $this->fetch(['success' => false, 'message' => 'Model ID is required', 'code' => 400]);
            }

            $model = $this->modelService->getById($modelId);

            return $this->fetch([
                'success' => true,
                'message' => '请求成功',
                'data' => [
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
                ]
            ]);

        } catch (\RuntimeException $e) {
            return $this->fetch(['success' => false, 'message' => $e->getMessage(), 'code' => 404]);
        } catch (\Exception $e) {
            return $this->fetch(['success' => false, 'message' => 'Internal server error', 'code' => 500]);
        }
    }

    /**
     * POST /ai/rest/v1/model (for copy action)
     */
    public function postIndex()
    {
        try {
            $modelId = (int) $this->request->getParam('id');
            $data = $this->request->getBodyParams();

            if (!$modelId) {
                return $this->fetch(['success' => false, 'message' => 'Model ID is required', 'code' => 400]);
            }

            if (empty($data['new_name'])) {
                return $this->fetch(['success' => false, 'message' => 'New name is required', 'code' => 400]);
            }

            $newName = (string) $data['new_name'];
            $config = $data['config'] ?? [];

            $copyModel = $this->modelService->copyModel($modelId, $newName, $config);

            return $this->fetch([
                'success' => true,
                'message' => '模型拷贝成功',
                'data' => [
                    'model_id' => $copyModel->getId(),
                    'origin_model_id' => $copyModel->getOriginModelId(),
                    'name' => $copyModel->getData('name'),
                    'is_copy' => true,
                    'model_code' => $copyModel->getData('model_code'),
                    'status' => $copyModel->getData('status'),
                    'created_at' => $copyModel->getData('created_at'),
                ]
            ]);

        } catch (\RuntimeException $e) {
            return $this->fetch(['success' => false, 'message' => $e->getMessage(), 'code' => 400]);
        } catch (\Exception $e) {
            return $this->fetch(['success' => false, 'message' => 'Internal server error', 'code' => 500]);
        }
    }

    /**
     * DELETE (handled via action parameter)
     */
    public function deleteIndex()
    {
        try {
            $modelId = (int) $this->request->getParam('id');

            if (!$modelId) {
                return $this->fetch(['success' => false, 'message' => 'Model ID is required', 'code' => 400]);
            }

            $this->modelService->deleteModel($modelId);

            return $this->fetch([
                'success' => true,
                'message' => '模型删除成功',
                'data' => [
                    'model_id' => $modelId,
                ]
            ]);

        } catch (\RuntimeException $e) {
            return $this->fetch(['success' => false, 'message' => $e->getMessage(), 'code' => 400]);
        } catch (\Exception $e) {
            return $this->fetch(['success' => false, 'message' => 'Internal server error', 'code' => 500]);
        }
    }
}

