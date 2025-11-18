<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Service\AiService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;

/**
 * 后端AI服务控制器
 * 处理后端管理员的AI调用请求
 */
class ApiController extends BackendController
{
    /**
     * 生成AI内容（后端调用）
     */
    public function generate()
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(['success' => false, 'message' => __('仅支持POST请求')]);
        }

        try {
            $data = $this->request->getParams();
            
            // 验证必要参数
            $prompt = $data['prompt'] ?? '';
            if (empty($prompt)) {
                return $this->jsonResponse(['success' => false, 'message' => __('请输入提示词')]);
            }

            $modelCode = $data['model_code'] ?? '';
            $scenarioCode = $data['scenario_code'] ?? '';
            $locale = $data['locale'] ?? 'zh_Hans_CN';
            $userConfig = $data['user_config'] ?? [];

            // 调用AI服务（后端调用）
            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);
            
            $response = $aiService->generate(
                $prompt,
                $modelCode ?: null,
                $scenarioCode ?: null,
                $locale,
                ['user_config' => $userConfig],
                null, // userId - 后端调用不需要用户ID
                true  // isBackend - 后端调用
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => __('生成成功'),
                'data' => [
                    'content' => $response,
                    'model_code' => $modelCode,
                    'is_backend' => true
                ]
            ]);

        } catch (\Exception $e) {
            Env::log('ai_backend.log', "后端AI调用失败: " . $e->getMessage(), 'ERROR');
            
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 测试模型连接
     */
    public function testConnection()
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(['success' => false, 'message' => __('仅支持POST请求')]);
        }

        try {
            $data = $this->request->getParams();
            $modelCode = $data['model_code'] ?? '';
            $userConfig = $data['user_config'] ?? [];

            if (empty($modelCode)) {
                return $this->jsonResponse(['success' => false, 'message' => __('请选择模型')]);
            }

            // 使用简单的测试提示词
            $testPrompt = "Hello, this is a connection test. Please respond with 'OK'.";
            
            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);
            
            $response = $aiService->generate(
                $testPrompt,
                $modelCode,
                null,
                'en_US',
                ['user_config' => $userConfig],
                null, // userId - 后端调用
                true  // isBackend - 后端调用
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => __('连接测试成功'),
                'data' => [
                    'model_code' => $modelCode,
                    'test_response' => $response,
                    'test_time' => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            Env::log('ai_backend.log', "模型连接测试失败: " . $e->getMessage(), 'ERROR');
            
            return $this->jsonResponse([
                'success' => false,
                'message' => __('连接测试失败: %{msg}', ['msg' => $e->getMessage()])
            ]);
        }
    }

    /**
     * 获取系统配置的模型列表
     */
    public function getModels()
    {
        try {
            /** @var \Weline\Ai\Model\AiModel $model */
            $model = ObjectManager::getInstance(\Weline\Ai\Model\AiModel::class);
            
            $search = trim((string)$this->request->getParam('search', ''));
            $limit  = (int)$this->request->getParam('limit', 0); // 0 表示不限制

            $query = $model->reset()->where(\Weline\Ai\Model\AiModel::fields_IS_ACTIVE, 1);

            if ($search !== '') {
                $like = "%{$search}%";
                // 按框架常用写法：concat 多字段进行一次 like 匹配
                $query->where("concat(name,supplier,model_code,version)", $like, 'like');
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

    /**
     * JSON响应
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}
