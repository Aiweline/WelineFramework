<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\ModelCollector;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Acl\Acl;

/**
 * AI模型管理后台控制器
 * 
 * 功能：
 * - AI模型列表展示
 * - 模型详情查看
 * - 模型状态管理
 * - 模型收集和更新
 */
#[Acl('Weline_Ai::ai_model_manager', 'AI模型管理', 'mdi-robot', 'AI模型管理', 'Weline_Ai::ai')]
class Model extends BackendController
{
    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * @var ModelCollector
     */
    private ModelCollector $modelCollector;

    /**
     * 构造函数
     * 
     * @param AiModel $aiModel
     * @param ModelCollector $modelCollector
     */
    public function __construct(
        AiModel $aiModel,
        ModelCollector $modelCollector
    ) {
        $this->aiModel = $aiModel;
        $this->modelCollector = $modelCollector;
    }

    /**
     * 确保AI模型对象有效
     * 
     * @return AiModel
     */
    private function getAiModel(): AiModel
    {
        if (!$this->aiModel || $this->aiModel === false) {
            $this->aiModel = ObjectManager::getInstance(AiModel::class);
        }
        return $this->aiModel;
    }

    /**
     * 模型列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_list', '查看AI模型列表', 'mdi-view-list', '查看AI模型列表')]
    public function index(): string
    {
        try {
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = 20;

            // 获取模型列表
            $models = $this->getAiModel()->reset()
                ->pagination($page, $pageSize)
                ->order(AiModel::fields_CREATED_TIME, 'DESC')
                ->select()
                ->fetch();

            $this->assign('models', $models->getItems());
            $this->assign('pagination', $models->getPagination());

            return $this->fetch();
        } catch (\Exception $e) {
            // 如果出现错误，显示错误信息
            $this->assign('error', $e->getMessage());
            $this->assign('models', []);
            return $this->fetch();
        }
    }

    /**
     * 模型详情页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_detail', '查看AI模型详情', 'mdi-information', '查看AI模型详情')]
    public function detail(): string
    {
        $id = (int)$this->request->getGet('id');
        
        if (!$id) {
            $this->messageManager->addError(__('模型ID不能为空'));
            return $this->redirect($this->getBackendUrl('*/backend/model'));
        }

        $model = $this->getAiModel()->reset()->load($id);
        
        if (!$model->getId()) {
            $this->messageManager->addError(__('模型不存在'));
            return $this->redirect($this->getBackendUrl('*/backend/model'));
        }

        $this->assign('model', $model);
        $this->assign('config', $model->getConfig());
        $this->assign('proxyInfo', $model->getProxyInfo());

        return $this->fetch();
    }

    /**
     * 收集模型
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_collect', '收集AI模型', 'mdi-download', '收集AI模型配置')]
    public function collect(): string
    {
        try {
            $collectedModels = $this->modelCollector->collectAllModels();
            
            $this->messageManager->addSuccess(
                __('成功收集 %{count} 个模型', ['count' => count($collectedModels)])
            );
        } catch (\Exception $e) {
            $this->messageManager->addError(__('模型收集失败: %{error}', ['error' => $e->getMessage()]));
        }

        return $this->redirect($this->getBackendUrl('*/backend/model'));
    }

    /**
     * 切换模型状态
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_toggle', '切换AI模型状态', 'mdi-toggle-switch', '启用或禁用AI模型')]
    public function toggleStatus(): string
    {
        $id = (int)$this->request->getPost('id');
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '模型ID不能为空'
            ]);
        }

        $model = $this->getAiModel()->reset()->load($id);
        
        if (!$model->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '模型不存在'
            ]);
        }

        try {
            $newStatus = $model->isActive() ? 0 : 1;
            $model->setData(AiModel::fields_IS_ACTIVE, $newStatus);
            $model->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => '状态更新成功',
                'status' => $newStatus
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '状态更新失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 设置默认模型
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_default', '设置默认AI模型', 'mdi-star', '设置默认AI模型')]
    public function setDefault(): string
    {
        $id = (int)$this->request->getPost('id');
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '模型ID不能为空'
            ]);
        }

        $model = $this->getAiModel()->reset()->load($id);
        
        if (!$model->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '模型不存在'
            ]);
        }

        try {
            // 取消其他模型的默认状态
            $this->getAiModel()->reset()
                ->where(AiModel::fields_IS_DEFAULT, 1)
                ->update([AiModel::fields_IS_DEFAULT => 0])
                ->fetch();

            // 设置当前模型为默认
            $model->setData(AiModel::fields_IS_DEFAULT, 1);
            $model->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => '默认模型设置成功'
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '设置失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取模型配置模板
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_template', '获取AI模型配置模板', 'mdi-file-document', '获取AI模型配置模板')]
    public function getConfigTemplate(): string
    {
        $template = $this->modelCollector->getModelConfigTemplate();
        
        return $this->jsonResponse([
            'success' => true,
            'template' => $template
        ]);
    }

    /**
     * 测试模型连接
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_test', '测试AI模型连接', 'mdi-connection', '测试AI模型连接')]
    public function testConnection(): string
    {
        $id = (int)$this->request->getPost('id');
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '模型ID不能为空'
            ]);
        }

        $model = $this->getAiModel()->reset()->load($id);
        
        if (!$model->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '模型不存在'
            ]);
        }

        try {
            // 使用AI服务测试连接
            $aiService = ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
            $testPrompt = "Hello, this is a connection test.";
            $response = $aiService->generate($testPrompt, $model->getData(AiModel::fields_MODEL_CODE));

            return $this->jsonResponse([
                'success' => true,
                'message' => '连接测试成功',
                'response' => $response
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '连接测试失败: ' . $e->getMessage()
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
        $this->response->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}
