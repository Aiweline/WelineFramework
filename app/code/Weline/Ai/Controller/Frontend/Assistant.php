<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Controller\Frontend;

use Weline\Ai\Model\AiAssistant;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\AdapterScanner;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\Message;

/**
 * AI助手使用控制器
 * 
 * 功能：
 * - 创建和管理个人助手
 * - 配置助手参数
 * - 选择MCP工具
 * - 使用助手对话
 */
class Assistant extends FrontendController
{
    /**
     * @var AiAssistant
     */
    private AiAssistant $aiAssistant;

    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * @var AdapterScanner
     */
    private AdapterScanner $adapterScanner;

    /**
     * 构造函数
     * 
     * @param AiAssistant $aiAssistant
     * @param AiModel $aiModel
     * @param AdapterScanner $adapterScanner
     */
    public function __construct(
        AiAssistant $aiAssistant,
        AiModel $aiModel,
        AdapterScanner $adapterScanner
    ) {
        $this->aiAssistant = $aiAssistant;
        $this->aiModel = $aiModel;
        $this->adapterScanner = $adapterScanner;
    }

    /**
     * 助手列表
     * 
     * @return string
     */
    public function index(): string
    {
        if (!$this->session->isLogin()) {
            Message::warning(__('请先登录'));
            return $this->redirect($this->_url->getFrontendUrl('*/frontend/index'));
        }

        $userId = $this->session->getLoginUserData('entity_id');

        // 获取用户的助手列表
        $assistants = $this->aiAssistant->reset()
            ->where('user_id', $userId)
            ->order('created_time', 'DESC')
            ->select()
            ->fetch();

        $this->assign('page_title', __('我的助手'));
        $this->assign('assistants', $assistants->getItems());

        return $this->fetch();
    }

    /**
     * 创建助手页面
     * 
     * @return string
     */
    public function create(): string
    {
        if (!$this->session->isLogin()) {
            Message::warning(__('请先登录'));
            return $this->redirect($this->_url->getFrontendUrl('*/frontend/index'));
        }

        // 获取可用的AI模型
        $models = $this->aiModel->reset()
            ->where(AiModel::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        // 获取可用的场景适配器
        $adapters = $this->adapterScanner->getAllActiveAdapters();

        $this->assign('page_title', __('创建助手'));
        $this->assign('models', $models->getItems());
        $this->assign('adapters', $adapters);

        return $this->fetch();
    }

    /**
     * 保存助手
     * 
     * @return string
     */
    public function save(): string
    {
        if (!$this->session->isLogin()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $userId = $this->session->getLoginUserData('entity_id');
        $name = $this->request->getPost('name', '');
        $prompt = $this->request->getPost('prompt', '');
        $modelCode = $this->request->getPost('model_code', '');
        $description = $this->request->getPost('description', '');
        $mcpConfig = $this->request->getPost('mcp_config', '[]');

        // 验证必填字段
        if (empty($name) || empty($prompt) || empty($modelCode)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请填写所有必填字段')
            ]);
        }

        try {
            $assistant = $this->aiAssistant->reset();
            $assistant->setData([
                'name' => $name,
                'prompt' => $prompt,
                'model_code' => $modelCode,
                'description' => $description,
                'mcp_config' => $mcpConfig,
                'user_id' => $userId,
                'is_active' => 1,
                'created_time' => time(),
                'updated_time' => time()
            ]);
            $assistant->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('助手创建成功'),
                'data' => [
                    'id' => $assistant->getId()
                ]
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('创建失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 编辑助手页面
     * 
     * @return string
     */
    public function edit(): string
    {
        if (!$this->session->isLogin()) {
            Message::warning(__('请先登录'));
            return $this->redirect($this->_url->getFrontendUrl('*/frontend/index'));
        }

        $id = (int)$this->request->getGet('id');
        $userId = $this->session->getLoginUserData('entity_id');

        $assistant = $this->aiAssistant->reset()->load($id);

        // 验证助手所有权
        if ($assistant->getData('user_id') != $userId) {
            Message::error(__('无权访问此助手'));
            return $this->redirect($this->_url->getFrontendUrl('*/frontend/assistant'));
        }

        // 获取可用的AI模型
        $models = $this->aiModel->reset()
            ->where(AiModel::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        // 获取可用的场景适配器
        $adapters = $this->adapterScanner->getAllActiveAdapters();

        $this->assign('page_title', __('编辑助手'));
        $this->assign('assistant', $assistant);
        $this->assign('models', $models->getItems());
        $this->assign('adapters', $adapters);

        return $this->fetch();
    }

    /**
     * 删除助手
     * 
     * @return string
     */
    public function delete(): string
    {
        if (!$this->session->isLogin()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $userId = $this->session->getLoginUserData('entity_id');

        try {
            $assistant = $this->aiAssistant->reset()->load($id);

            // 验证助手所有权
            if ($assistant->getData('user_id') != $userId) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('无权操作此助手')
                ]);
            }

            $assistant->delete();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('助手已删除')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('删除失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 使用助手对话
     * 
     * @return string
     */
    public function chat(): string
    {
        if (!$this->session->isLogin()) {
            Message::warning(__('请先登录'));
            return $this->redirect($this->_url->getFrontendUrl('*/frontend/index'));
        }

        $id = (int)$this->request->getGet('id');
        $userId = $this->session->getLoginUserData('entity_id');

        $assistant = $this->aiAssistant->reset()->load($id);

        // 验证助手所有权
        if ($assistant->getData('user_id') != $userId) {
            Message::error(__('无权访问此助手'));
            return $this->redirect($this->_url->getFrontendUrl('*/frontend/assistant'));
        }

        $this->assign('page_title', $assistant->getData('name'));
        $this->assign('assistant', $assistant);

        return $this->fetch();
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

