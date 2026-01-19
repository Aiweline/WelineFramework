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
 * 模型基准测试控制器
 * 
 * 功能：
 * - 模型性能测试
 * - 基准测试任务管理
 * - 测试结果对比
 * - 性能报告
 */
#[Acl('Weline_Ai::ai_model_benchmark', '模型基准测试', 'mdi-speedometer', '模型基准测试', 'Weline_Ai::ai')]
class ModelBenchmark extends BackendController
{
    /**
     * 获取AI模型（懒加载）
     */
    private function getAiModel(): AiModel
    {
        return ObjectManager::getInstance(AiModel::class);
    }

    /**
     * 基准测试列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_benchmark_list', '查看基准测试列表', 'mdi-view-list', '查看基准测试列表')]
    public function index(): string
    {
        try {
            // 获取可用模型列表
            $modelData = $this->getAiModel()->reset()
                ->where('is_active', 1)
                ->select()
                ->fetchArray();
            
            // 规范化模型数据，确保包含必要的字段
            $models = [];
            foreach ($modelData as $data) {
                $models[] = [
                    'id' => $data['id'] ?? $data['model_id'] ?? '',
                    'name' => $data['name'] ?? '',
                    'model_code' => $data['model_code'] ?? '',
                    'vendor' => $data['vendor'] ?? ($data['supplier'] ?? ''),
                    'version' => $data['version'] ?? '',
                ];
            }
            
            // TODO: 获取历史测试记录
            $testHistory = [];
            
            $this->assign('models', $models);
            $this->assign('test_history', $testHistory);
            
            // 统计
            $stats = [
                'total_tests' => count($testHistory),
                'total_models' => count($models),
            ];
            $this->assign('stats', $stats);

            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载基准测试失败：%{1}', $e->getMessage()));
            $this->assign('models', []);
            $this->assign('test_history', []);
            $this->assign('stats', ['total_tests' => 0, 'total_models' => 0]);
            return $this->fetch();
        }
    }

    /**
     * 运行基准测试
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_benchmark_run', '运行基准测试', 'mdi-play', '运行基准测试')]
    public function postRunBenchmark(): string
    {

        $modelIds = $this->request->getPost('model_ids', []);
        $testSuite = $this->request->getPost('test_suite', 'default');

        try {
            if (empty($modelIds)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('请选择要测试的模型')
                ]);
            }

            // TODO: 实现基准测试逻辑
            // 这里可以创建测试任务，异步执行测试
            
            Message::success(__('基准测试任务已创建'));

            return $this->jsonResponse([
                'success' => true,
                'message' => __('基准测试任务已创建'),
                'data' => [
                    'task_id' => uniqid('benchmark_'),
                    'model_count' => count($modelIds),
                    'test_suite' => $testSuite,
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
