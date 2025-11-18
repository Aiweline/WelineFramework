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
use Weline\Ai\Model\AiModelDeployment;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Acl\Acl;

/**
 * 模型部署管理控制器
 * 
 * 功能：
 * - 模型部署列表
 * - 部署模型到生产环境
 * - 部署状态管理
 * - 部署历史记录
 */
#[Acl('Weline_Ai::ai_model_deployment', '模型部署', 'mdi-rocket-launch', '模型部署', 'Weline_Ai::ai')]
class ModelDeployment extends BackendController
{
    /**
     * 获取AI模型（懒加载）
     */
    private function getAiModel(): AiModel
    {
        return ObjectManager::getInstance(AiModel::class);
    }

    /**
     * 获取模型部署模型（懒加载）
     */
    private function getModelDeployment(): AiModelDeployment
    {
        return ObjectManager::getInstance(AiModelDeployment::class);
    }

    /**
     * 模型部署列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_deployment_list', '查看模型部署列表', 'mdi-view-list', '查看模型部署列表')]
    public function index(): string
    {
        try {
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = 20;
            $status = $this->request->getGet('status', '');

            // 获取部署记录列表（关联模型信息）
            // 只显示本地模型的部署记录
            $deploymentModel = $this->getModelDeployment();
            $query = $deploymentModel->reset();
            
            if ($status !== '') {
                $query->where(AiModelDeployment::fields_DEPLOYMENT_STATUS, $status);
            }

            $deployments = $query->pagination($page, $pageSize)
                ->order(AiModelDeployment::fields_CREATED_AT, 'DESC')
                ->select()
                ->fetch();

            $pagination = $deployments->getPagination();
            
            // 获取模型信息并合并到部署记录中
            $models = [];
            $modelIds = [];
            if ($deployments && $deployments->getItems()) {
                foreach ($deployments->getItems() as $deployment) {
                    $modelId = $deployment->getData(AiModelDeployment::fields_MODEL_ID);
                    if ($modelId && !in_array($modelId, $modelIds)) {
                        $modelIds[] = $modelId;
                    }
                }
                
                // 批量获取模型信息
                if (!empty($modelIds)) {
                    $modelCollection = $this->getAiModel()->reset()
                        ->where('id', $modelIds, 'IN')
                        ->select()
                        ->fetch();
                    
                    $modelMap = [];
                    if ($modelCollection && $modelCollection->getItems()) {
                        foreach ($modelCollection->getItems() as $model) {
                            $modelMap[$model->getId()] = $model->getData();
                        }
                    }
                    
                    // 合并部署记录和模型信息（只包含本地模型）
                    foreach ($deployments->getItems() as $deployment) {
                        $deploymentData = $deployment->getData();
                        $modelId = $deploymentData[AiModelDeployment::fields_MODEL_ID];
                        $modelData = $modelMap[$modelId] ?? [];
                        
                        // 只显示本地模型的部署记录
                        $modelSource = $modelData[AiModel::fields_MODEL_SOURCE] ?? AiModel::SOURCE_REMOTE;
                        if ($modelSource !== AiModel::SOURCE_LOCAL) {
                            continue;
                        }
                        
                        $models[] = array_merge($deploymentData, [
                            'id' => $deploymentData[AiModelDeployment::fields_ID],
                            'name' => $modelData['name'] ?? '',
                            'model_code' => $modelData['model_code'] ?? '',
                            'supplier' => $modelData['supplier'] ?? '',
                            'version' => $modelData['version'] ?? '',
                            'model_source' => $modelSource,
                            'status' => $deploymentData[AiModelDeployment::fields_DEPLOYMENT_STATUS],
                            'deployed_environment' => $deploymentData[AiModelDeployment::fields_DEPLOYMENT_TYPE] ?? '',
                            'deployed_at' => $deploymentData[AiModelDeployment::fields_DEPLOYED_AT] 
                                ? (is_numeric($deploymentData[AiModelDeployment::fields_DEPLOYED_AT]) 
                                    ? $deploymentData[AiModelDeployment::fields_DEPLOYED_AT] 
                                    : strtotime($deploymentData[AiModelDeployment::fields_DEPLOYED_AT]))
                                : null,
                        ]);
                    }
                }
            }

            // 统计信息
            $stats = [
                'total' => (int)$this->getModelDeployment()->reset()->count(),
                'deployed' => (int)$this->getModelDeployment()->reset()
                    ->where(AiModelDeployment::fields_DEPLOYMENT_STATUS, AiModelDeployment::STATUS_ACTIVE)
                    ->count(),
                'pending' => (int)$this->getModelDeployment()->reset()
                    ->where(AiModelDeployment::fields_DEPLOYMENT_STATUS, AiModelDeployment::STATUS_PENDING)
                    ->count(),
                'failed' => (int)$this->getModelDeployment()->reset()
                    ->where(AiModelDeployment::fields_DEPLOYMENT_STATUS, AiModelDeployment::STATUS_FAILED)
                    ->count(),
            ];

            $this->assign('models', $models);
            $this->assign('stats', $stats);
            $this->assign('pagination', $pagination);
            $this->assign('current_status', $status);

            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载部署列表失败：%{1}', $e->getMessage()));
            $this->assign('models', []);
            $this->assign('stats', ['total' => 0, 'deployed' => 0, 'pending' => 0, 'failed' => 0]);
            return $this->fetch();
        }
    }

    /**
     * 部署模型
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_deployment_deploy', '部署模型', 'mdi-rocket-launch', '部署模型')]
    public function deploy(): string
    {
        try {
            $modelId = (int)$this->request->getPost('model_id');
            
            if (!$modelId) {
                return $this->fetchJson(['success' => false, 'message' => __('模型ID不能为空')]);
            }

            // 检查模型是否存在
            $model = $this->getAiModel()->reset()->load($modelId);
            if (!$model->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('模型不存在')]);
            }

            // 检查模型是否为本地模型（只有本地模型才能部署）
            if (!$model->isLocal()) {
                return $this->fetchJson(['success' => false, 'message' => __('只有本地模型才能部署，该模型是远程第三方模型')]);
            }

            // 检查是否已有部署记录
            $existingDeployment = $this->getModelDeployment()->reset()
                ->where(AiModelDeployment::fields_MODEL_ID, $modelId)
                ->where(AiModelDeployment::fields_DEPLOYMENT_STATUS, AiModelDeployment::STATUS_ACTIVE)
                ->find()
                ->fetch();

            if ($existingDeployment && $existingDeployment->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('该模型已经部署')]);
            }

            // 创建部署记录
            $deployment = $this->getModelDeployment();
            $deployment->setData([
                AiModelDeployment::fields_MODEL_ID => $modelId,
                AiModelDeployment::fields_DEPLOYMENT_NAME => $model->getData('name') . ' 部署',
                AiModelDeployment::fields_DEPLOYMENT_TYPE => AiModelDeployment::DEPLOYMENT_TYPE_CLOUD,
                AiModelDeployment::fields_DEPLOYMENT_STATUS => AiModelDeployment::STATUS_ACTIVE,
                AiModelDeployment::fields_DEPLOYED_AT => date('Y-m-d H:i:s'),
            ]);
            $deployment->save();

            return $this->fetchJson([
                'success' => true,
                'message' => __('模型部署成功')
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('部署失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 回滚部署
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_deployment_rollback', '回滚部署', 'mdi-undo', '回滚部署')]
    public function rollback(): string
    {
        try {
            $modelId = (int)$this->request->getPost('model_id');
            
            if (!$modelId) {
                return $this->fetchJson(['success' => false, 'message' => __('模型ID不能为空')]);
            }

            // 查找部署记录
            $deployment = $this->getModelDeployment()->reset()
                ->where(AiModelDeployment::fields_MODEL_ID, $modelId)
                ->where(AiModelDeployment::fields_DEPLOYMENT_STATUS, AiModelDeployment::STATUS_ACTIVE)
                ->find()
                ->fetch();

            if (!$deployment || !$deployment->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('未找到部署记录')]);
            }

            // 更新状态为已回滚
            $deployment->setData(AiModelDeployment::fields_DEPLOYMENT_STATUS, AiModelDeployment::STATUS_FAILED);
            $deployment->save();

            return $this->fetchJson([
                'success' => true,
                'message' => __('部署回滚成功')
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('回滚失败：%{1}', $e->getMessage())
            ]);
        }
    }
}
