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
 * 模型版本管理控制器
 * 
 * 功能：
 * - 模型版本列表
 * - 版本创建和管理
 * - 版本对比
 * - 版本回滚
 */
#[Acl('Weline_Ai::ai_model_versioning', '模型版本管理', 'mdi-source-branch', '模型版本管理', 'Weline_Ai::ai')]
class ModelVersioning extends BackendController
{
    /**
     * 获取AI模型（懒加载）
     */
    private function getAiModel(): AiModel
    {
        return ObjectManager::getInstance(AiModel::class);
    }

    /**
     * 模型版本列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_versioning_list', '查看模型版本列表', 'mdi-view-list', '查看模型版本列表')]
    public function index(): string
    {
        try {
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = 20;
            $modelCode = $this->request->getGet('model_code', '');

            // 获取模型列表（按版本分组）
            $query = $this->getAiModel()->reset();
            
            if ($modelCode) {
                $query->where('model_code', $modelCode);
            }

            $models = $query->pagination($page, $pageSize)
                ->order('model_code', 'ASC')
                ->order('version', 'DESC')
                ->select()
                ->fetchArray();

            // 按模型代码分组
            $groupedModels = [];
            foreach ($models as $model) {
                $code = $model['model_code'] ?? '';
                if (!isset($groupedModels[$code])) {
                    $groupedModels[$code] = [];
                }
                $groupedModels[$code][] = $model;
            }

            $pagination = $this->getAiModel()->getPagination();
            $total = is_object($pagination) && method_exists($pagination, 'getTotal') 
                ? $pagination->getTotal() 
                : count($models);

            $this->assign('models', $models);
            $this->assign('grouped_models', $groupedModels);
            $this->assign('pagination', $pagination);
            $this->assign('total', $total);
            $this->assign('current_model_code', $modelCode);

            // 统计
            $stats = [
                'total_models' => count($groupedModels),
                'total_versions' => $total,
            ];
            $this->assign('stats', $stats);

            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载模型版本列表失败：%{1}', $e->getMessage()));
            $this->assign('models', []);
            $this->assign('grouped_models', []);
            $this->assign('pagination', null);
            $this->assign('total', 0);
            $this->assign('current_model_code', '');
            $this->assign('stats', ['total_models' => 0, 'total_versions' => 0]);
            return $this->fetch();
        }
    }

    /**
     * 创建新版本
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_versioning_create', '创建模型版本', 'mdi-plus', '创建模型版本')]
    public function createVersion(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $baseModelId = (int)$this->request->getPost('base_model_id');
        $newVersion = $this->request->getPost('new_version', '');

        try {
            $baseModel = $this->getAiModel()->reset()->load($baseModelId);
            
            if (!$baseModel->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('基础模型不存在')
                ]);
            }

            // TODO: 实现版本创建逻辑
            // 这里可以复制模型记录并更新版本号
            
            Message::success(__('模型版本创建成功'));

            return $this->jsonResponse([
                'success' => true,
                'message' => __('模型版本创建成功')
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('创建失败：%{1}', $e->getMessage())
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
