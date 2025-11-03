<?php
declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Framework\App\Env;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

class Api extends BackendController
{
    /**
     * 获取系统配置的模型列表（小写方法名，遵循路由生成规则）
     */
    public function models()
    {
        try {
            /** @var \Weline\Ai\Model\AiModel $model */
            $model = ObjectManager::getInstance(\Weline\Ai\Model\AiModel::class);

            $search = trim((string)$this->request->getParam('search', ''));
            $limit  = (int)$this->request->getParam('limit', 0); // 0 表示不限制

            $query = $model->reset()->where(\Weline\Ai\Model\AiModel::fields_IS_ACTIVE, 1);

            if ($search !== '') {
                $like = "%{$search}%";
                $query->where('concat(name,supplier,model_code,version)', $like, 'like');
            }

            $query->order(\Weline\Ai\Model\AiModel::fields_CREATED_AT, 'DESC');
            if ($limit > 0) {
                $query->limit($limit);
            }

            $models = $query->select()->fetch();

            $modelList = [];
            $items = method_exists($models, 'getItems') ? $models->getItems() : (array)$models;
            foreach ($items as $modelItem) {
                $modelList[] = [
                    'id' => $modelItem->getId(),
                    'code' => $modelItem->getModelCode(),
                    'name' => $modelItem->getName(),
                    'supplier' => $modelItem->getSupplier(),
                    'version' => $modelItem->getVersion(),
                    'max_tokens' => $modelItem->getMaxTokens(),
                    'has_provider_config' => !empty($modelItem->getProviderConfig()),
                    'status' => $modelItem->getStatus()
                ];
            }

            return $this->jsonResponse([
                'success' => true,
                'message' => __('获取成功'),
                'data' => $modelList
            ]);

        } catch (\Exception $e) {
            Env::log('ai_backend.log', "获取模型列表失败: " . $e->getMessage(), 'ERROR');

            return $this->jsonResponse([
                'success' => false,
                'message' => __('获取模型列表失败')
            ]);
        }
    }

    // 兼容旧调用：/api/getModels
    public function getModels()
    {
        return $this->models();
    }

    /**
     * JSON响应
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}


