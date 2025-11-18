<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiModel;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Acl\Acl;

/**
 * AI模块测试工具控制器
 * 
 * 功能：
 * - 模型连接测试
 * - API接口测试
 * - 性能测试
 * - 功能测试
 */
#[Acl('Weline_Ai::ai_test_tools', '测试工具', 'mdi-test-tube', '测试工具', 'Weline_Ai::ai')]
class Test extends BackendController
{
    /**
     * 获取AI模型（懒加载）
     */
    private function getAiModel(): AiModel
    {
        return ObjectManager::getInstance(AiModel::class);
    }

    /**
     * 测试工具首页
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_test_tools_index', '查看测试工具', 'mdi-view-dashboard', '查看测试工具')]
    public function index(): string
    {
        try {
            // 获取可用模型列表
            $models = $this->getAiModel()->reset()
                ->where('is_active', 1)
                ->select()
                ->fetchArray();
            
            $this->assign('models', $models);
            
            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载测试工具失败：%{1}', $e->getMessage()));
            $this->assign('models', []);
            return $this->fetch();
        }
    }

    /**
     * 测试模型连接
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_test_tools_test_connection', '测试模型连接', 'mdi-connection', '测试模型连接')]
    public function testConnection(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $modelId = (int)$this->request->getPost('model_id');

        try {
            $model = $this->getAiModel()->reset()->load($modelId);
            
            if (!$model->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('模型不存在')
                ]);
            }

            // TODO: 实现实际的连接测试逻辑
            // 这里可以调用模型的测试方法
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('连接测试成功'),
                'data' => [
                    'model_id' => $modelId,
                    'model_name' => $model->getName(),
                    'test_time' => date('Y-m-d H:i:s'),
                    'response_time' => 0.5, // 模拟响应时间
                ]
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('测试失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * JSON响应
     * 
     * @param array $data
     * @return string
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}
