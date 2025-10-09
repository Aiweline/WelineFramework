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

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiAssistant;
use Weline\Ai\Model\AiModel;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;

/**
 * AI助手管理后台控制器
 * 
 * 功能：
 * - 助手列表展示
 * - 助手创建和编辑
 * - 助手状态管理
 * - 助手测试
 */
#[Acl('Weline_Ai::ai_assistant_manager', '助手管理', 'mdi-account-supervisor', '助手管理', 'Weline_Ai::ai')]
class Assistant extends BackendController
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
     * 构造函数
     * 
     * @param AiAssistant $aiAssistant
     * @param AiModel $aiModel
     */
    public function __construct(
        AiAssistant $aiAssistant,
        AiModel $aiModel
    ) {
        $this->aiAssistant = $aiAssistant;
        $this->aiModel = $aiModel;
    }

    /**
     * 助手列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_assistant_list', '查看助手列表', 'mdi-view-list', '查看助手列表')]
    public function index(): string
    {
        try {
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = 20;
            $status = $this->request->getGet('status', '');

            // 构建查询
            $query = $this->aiAssistant->reset();
            
            if ($status !== '') {
                $query->where('is_active', (int)$status);
            }

            $assistants = $query->pagination($page, $pageSize)
                ->order('created_time', 'DESC')
                ->select()
                ->fetch();

            $pagination = $assistants->getPagination();
            $total = is_object($pagination) && method_exists($pagination, 'getTotal') 
                ? $pagination->getTotal() 
                : count($assistants->getItems());

            $this->assign('assistants', $assistants->getItems());
            $this->assign('pagination', $pagination);
            $this->assign('total', $total);
            $this->assign('current_status', $status);

            // 统计
            $stats = [
                'total' => $total,
                'active' => $this->aiAssistant->reset()->where('is_active', 1)->select()->fetch()->count(),
                'inactive' => $this->aiAssistant->reset()->where('is_active', 0)->select()->fetch()->count(),
            ];
            $this->assign('stats', $stats);

            return $this->fetch();

        } catch (\Exception $e) {
            $this->messageManager->addError(__('加载助手列表失败：%1', $e->getMessage()));
            return $this->fetch();
        }
    }

    /**
     * 创建助手页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_assistant_create', '创建助手', 'mdi-plus', '创建助手')]
    public function create(): string
    {
        try {
            // 获取可用的AI模型
            $models = $this->aiModel->reset()
                ->where('is_active', 1)
                ->select()
                ->fetch();

            $this->assign('models', $models->getItems());
            $this->assign('assistant', null);

            return $this->fetch('edit');

        } catch (\Exception $e) {
            $this->messageManager->addError(__('加载失败：%1', $e->getMessage()));
            return $this->redirect($this->getBackendUrl('*/backend/assistant'));
        }
    }

    /**
     * 编辑助手页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_assistant_edit', '编辑助手', 'mdi-pencil', '编辑助手')]
    public function edit(): string
    {
        $id = (int)$this->request->getGet('id');

        try {
            $assistant = $this->aiAssistant->reset()->load($id);
            
            if (!$assistant->getId()) {
                $this->messageManager->addError(__('助手不存在'));
                return $this->redirect($this->getBackendUrl('*/backend/assistant'));
            }

            // 获取可用的AI模型
            $models = $this->aiModel->reset()
                ->where('is_active', 1)
                ->select()
                ->fetch();

            $this->assign('assistant', $assistant);
            $this->assign('models', $models->getItems());

            return $this->fetch();

        } catch (\Exception $e) {
            $this->messageManager->addError(__('加载失败：%1', $e->getMessage()));
            return $this->redirect($this->getBackendUrl('*/backend/assistant'));
        }
    }

    /**
     * 保存助手
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_assistant_save', '保存助手', 'mdi-content-save', '保存助手')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id', 0);
        $name = $this->request->getPost('name', '');
        $prompt = $this->request->getPost('prompt', '');
        $modelCode = $this->request->getPost('model_code', '');
        $description = $this->request->getPost('description', '');
        $mcpConfig = $this->request->getPost('mcp_config', '[]');
        $isActive = (int)$this->request->getPost('is_active', 1);

        try {
            if ($id > 0) {
                $assistant = $this->aiAssistant->reset()->load($id);
                if (!$assistant->getId()) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('助手不存在')
                    ]);
                }
            } else {
                $assistant = $this->aiAssistant->reset();
                $assistant->setData('created_time', time());
            }

            $assistant->setData([
                'name' => $name,
                'prompt' => $prompt,
                'model_code' => $modelCode,
                'description' => $description,
                'mcp_config' => $mcpConfig,
                'is_active' => $isActive,
                'updated_time' => time()
            ]);

            $assistant->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('保存成功'),
                'data' => ['id' => $assistant->getId()]
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('保存失败：%1', $e->getMessage())
            ]);
        }
    }

    /**
     * 删除助手
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_assistant_delete', '删除助手', 'mdi-delete', '删除助手')]
    public function delete(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id');

        try {
            $assistant = $this->aiAssistant->reset()->load($id);
            
            if (!$assistant->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('助手不存在')
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
                'message' => __('删除失败：%1', $e->getMessage())
            ]);
        }
    }

    /**
     * 切换助手状态
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_assistant_toggle', '切换助手状态', 'mdi-toggle-switch', '切换助手状态')]
    public function toggleStatus(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id');

        try {
            $assistant = $this->aiAssistant->reset()->load($id);
            
            if (!$assistant->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('助手不存在')
                ]);
            }

            $newStatus = $assistant->getData('is_active') ? 0 : 1;
            $assistant->setData('is_active', $newStatus);
            $assistant->setData('updated_time', time());
            $assistant->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => $newStatus ? __('已激活') : __('已停用')
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('操作失败：%1', $e->getMessage())
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

