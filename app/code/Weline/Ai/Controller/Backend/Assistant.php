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
use Weline\Ai\Service\BillingService;
use Weline\Frontend\Model\FrontendUser;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

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
    /**
     * 获取助手模型（懒加载）
     */
    private function getAiAssistant(): AiAssistant
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(AiAssistant::class);
    }

    /**
     * 获取AI模型（懒加载）
     */
    private function getAiModel(): AiModel
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(AiModel::class);
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
            $query = $this->getAiAssistant()->reset();
            
            if ($status !== '') {
                $query->where('is_active', (int)$status);
            }

            $assistants = $query->pagination($page, $pageSize)
                ->order('assistant_id', 'DESC')
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
                'active' => $this->getAiAssistant()->reset()->where('is_active', 1)->select()->fetch()->count(),
                'inactive' => $this->getAiAssistant()->reset()->where('is_active', 0)->select()->fetch()->count(),
            ];
            $this->assign('stats', $stats);

            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载助手列表失败：%{1}', $e->getMessage()));
            $this->assign('assistants', []);
            $this->assign('pagination', null);
            $this->assign('total', 0);
            $this->assign('current_status', '');
            $this->assign('stats', ['total' => 0, 'active' => 0, 'inactive' => 0]);
            return $this->fetch();
        }
    }

    /**
     * Offcanvas 表单页面（创建/编辑）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_assistant_form', '助手表单', 'mdi-form-select', '助手表单')]
    public function form(): string
    {
        $id = (int)$this->request->getGet('id', 0);
        
        try {
            $assistant = null;
            
            if ($id > 0) {
                $assistant = $this->getAiAssistant()->reset()->load($id);
                if (!$assistant->getId()) {
                    Message::error(__('助手不存在'));
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('助手不存在')
                    ]);
                }
            }
            
            // 获取可用的AI模型
            $models = $this->getAiModel()->reset()
                ->where('is_active', 1)
                ->select()
                ->fetch();
            
            // 获取当前用户余额
            // TODO: 从后台登录session获取用户ID
            $userId = 1; // 暂时使用默认用户ID
            $user = ObjectManager::getInstance(FrontendUser::class)->load($userId);
            $userBalance = (float)($user->getData('balance') ?? 0.0);

            $this->assign('assistant', $assistant);
            $this->assign('models', $models->getItems());
            $this->assign('isEdit', $id > 0);
            $this->assign('user_balance', $userBalance);
            $this->assign('user_id', $userId);

            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载失败：%{1}', $e->getMessage()));
            return $this->jsonResponse([
                'success' => false,
                'message' => __('加载失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 创建助手页面（保留用于直接访问）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_assistant_create', '创建助手', 'mdi-plus', '创建助手')]
    public function create(): string
    {
        try {
            // 获取可用的AI模型
            $models = $this->getAiModel()->reset()
                ->where('is_active', 1)
                ->select()
                ->fetch();

            $this->assign('models', $models->getItems());
            $this->assign('assistant', null);

            return $this->fetch('edit');

        } catch (\Exception $e) {
            Message::error(__('加载失败：%{1}', $e->getMessage()));
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
            $assistant = $this->getAiAssistant()->reset()->load($id);
            
            if (!$assistant->getId()) {
                Message::error(__('助手不存在'));
                return $this->redirect($this->getBackendUrl('*/backend/assistant'));
            }

            // 获取可用的AI模型
            $models = $this->getAiModel()->reset()
                ->where('is_active', 1)
                ->select()
                ->fetch();

            $this->assign('assistant', $assistant);
            $this->assign('models', $models->getItems());

            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载失败：%{1}', $e->getMessage()));
            return $this->redirect($this->getBackendUrl('*/backend/assistant'));
        }
    }

    /**
     * 获取模型配置（AJAX）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_assistant_get_model_config', '获取模型配置', '', '获取模型配置')]
    public function getModelConfig(): string
    {
        $modelCode = $this->request->getGet('model_code', '');
        
        if (empty($modelCode)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('模型代码不能为空')
            ]);
        }
        
        try {
            // 通过model_code查找模型
            // 由于数据库中code字段可能为空，我们需要同时匹配code和name
            // 前端传来的model_code可能是生成的code（如"gpt-3_5_turbo"）
            $modelObj = $this->getAiModel()->reset();
            $models = $modelObj->where('is_active', 1)->select()->fetch();
            
            $model = null;
            foreach ($models->getItems() as $m) {
                $name = strtolower($m->getData('name'));
                $code = strtolower($m->getData('code') ?: '');
                $searchTerm = strtolower($modelCode);
                
                // 如果code为空，尝试生成code并匹配
                if (empty($code)) {
                    $generatedCode = strtolower(str_replace([' ', '.'], ['_', '_'], $name));
                    if ($generatedCode === $searchTerm) {
                        $model = $m;
                        break;
                    }
                }
                
                // 精确匹配code或name包含搜索词
                if ($code === $searchTerm || strpos($name, $searchTerm) !== false) {
                    $model = $m;
                    break;
                }
            }
            
            if (!$model || !$model->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '模型不存在：' . $modelCode
                ]);
            }
            
            // 获取模型配置
            $config = $model->getConfig();
            
            return $this->jsonResponse([
                'success' => true,
                'config' => $config,
                'model' => [
                    'id' => $model->getId(),
                    'code' => $model->getData('code'),
                    'name' => $model->getData('name'),
                    'supplier' => $model->getData('supplier'),
                    'max_tokens' => $model->getData('max_tokens'),
                    'cost_per_token' => $model->getData('cost_per_token')
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '获取配置失败：' . $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    /**
     * 搜索适配器（AJAX）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_assistant_search_adapters', '搜索适配器', '', '搜索适配器')]
    public function searchAdapters(): string
    {
        $keyword = $this->request->getGet('keyword', '');
        
        try {
            $adapterModel = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Ai\Model\AiScenarioAdapter::class);
            
            $query = $adapterModel->reset()->where('is_active', 1);
            
            if (!empty($keyword)) {
                $query->where('name', 'like', "%{$keyword}%")
                      ->orWhere('code', 'like', "%{$keyword}%")
                      ->orWhere('description', 'like', "%{$keyword}%");
            }
            
            $adapters = $query->select()->fetch();
            
            $result = [];
            foreach ($adapters->getItems() as $adapter) {
                $result[] = [
                    'code' => $adapter->getData('code'),
                    'name' => $adapter->getData('name'),
                    'description' => $adapter->getData('description'),
                    'version' => $adapter->getData('version'),
                    'is_active' => $adapter->getData('is_active'),
                ];
            }
            
            return $this->jsonResponse([
                'success' => true,
                'adapters' => $result
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('搜索适配器失败：%{1}', $e->getMessage()),
                'adapters' => []
            ]);
        }
    }

    /**
     * 获取适配器参数模板
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_assistant_adapter_params', '获取适配器参数模板', '', '获取适配器参数模板')]
    public function getAdapterParams(): string
    {
        $adapterCode = $this->request->getGet('code', '');
        
        if (empty($adapterCode)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('适配器代码不能为空')
            ]);
        }
        
        try {
            // 获取适配器记录
            $adapterModel = ObjectManager::getInstance(\Weline\Ai\Model\AiScenarioAdapter::class);
            $adapter = $adapterModel->reset()
                ->where('code', $adapterCode)
                ->where('is_active', 1)
                ->fetchOne();
            
            if (!$adapter || !$adapter->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('适配器不存在')
                ]);
            }
            
            // 实例化适配器类
            $className = $adapter->getData('class_name');
            if (!class_exists($className)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('适配器类不存在：%{1}', $className)
                ]);
            }
            
            $adapterInstance = ObjectManager::getInstance($className);
            
            // 获取参数模板
            $paramTemplate = $adapterInstance->getParamTemplate();
            
            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'code' => $adapterCode,
                    'name' => $adapter->getData('name'),
                    'param_template' => $paramTemplate,
                    'description' => $adapter->getData('description'),
                    'examples' => $adapterInstance->getExamples()
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('获取失败：%{1}', $e->getMessage())
            ]);
        }
    }
    
    /**
     * 搜索AI模型（AJAX）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_assistant_search_models', '搜索AI模型', '', '搜索AI模型')]
    public function searchModels(): string
    {
        $keyword = $this->request->getGet('keyword', '');
        
        try {
            $modelObj = $this->getAiModel()->reset();
            
            if (!empty($keyword)) {
                $modelObj->where('name', 'LIKE', "%{$keyword}%")
                         ->orWhere('code', 'LIKE', "%{$keyword}%")
                         ->orWhere('supplier', 'LIKE', "%{$keyword}%");
            }
            
            $models = $modelObj->where('is_active', 1)
                              ->select()
                              ->fetch();
            
            $modelList = [];
            foreach ($models->getItems() as $model) {
                // 如果code为空，使用name作为code
                $code = $model->getData('code');
                if (empty($code)) {
                    $code = strtolower(str_replace([' ', '.'], ['_', '_'], $model->getData('name')));
                }
                
                $modelList[] = [
                    'id' => $model->getId(),
                    'code' => $code,
                    'name' => $model->getData('name'),
                    'supplier' => $model->getData('supplier'),
                    'is_active' => $model->getData('is_active')
                ];
            }
            
            return $this->jsonResponse([
                'success' => true,
                'models' => $modelList
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('搜索失败：%{1}', $e->getMessage())
            ]);
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
        // 兼容GET和POST请求（方便调试）
        $id = (int)($this->request->getPost('id', 0) ?: $this->request->getGet('id', 0));
        $name = $this->request->getPost('name', '') ?: $this->request->getGet('name', '');
        $prompt = $this->request->getPost('prompt', '') ?: $this->request->getGet('prompt', '');
        $adapterCode = $this->request->getPost('adapter_code', 'default') ?: $this->request->getGet('adapter_code', 'default');
        $modelCode = $this->request->getPost('model_code', '') ?: $this->request->getGet('model_code', '');
        $description = $this->request->getPost('description', '') ?: $this->request->getGet('description', '');
        $modelConfig = $this->request->getPost('model_config', '{}') ?: $this->request->getGet('model_config', '{}');
        $mcpConfig = $this->request->getPost('mcp_config', '[]') ?: $this->request->getGet('mcp_config', '[]');
        $isActive = (int)($this->request->getPost('is_active', 1) ?: $this->request->getGet('is_active', 1));
        
        // API配置字段
        $apiKey = $this->request->getPost('api_key', '') ?: $this->request->getGet('api_key', '');
        $proxyConfig = $this->request->getPost('proxy_config', '') ?: $this->request->getGet('proxy_config', '');
        
        // 适配器参数
        $adapterParams = $this->request->getPost('adapter_params', []) ?: $this->request->getGet('adapter_params', []);
        
        // 租赁相关字段
        $isRentable = (int)($this->request->getPost('is_rentable', 0) ?: $this->request->getGet('is_rentable', 0));
        $rentalType = $this->request->getPost('rental_type', 'monthly') ?: $this->request->getGet('rental_type', 'monthly');
        $rentalPrice = (float)($this->request->getPost('rental_price', 0) ?: $this->request->getGet('rental_price', 0));
        $category = $this->request->getPost('category', '') ?: $this->request->getGet('category', '');
        $tags = $this->request->getPost('tags', '') ?: $this->request->getGet('tags', '');

        try {
            // 如果用户没有配置自定义API密钥，需要检查余额
            if (empty($apiKey)) {
                // 获取当前用户ID（从session或默认为1）
                // TODO: 从后台登录session获取用户ID
                $userId = 1; // 暂时使用默认用户ID
                
                // 检查用户余额
                $billingService = ObjectManager::getInstance(BillingService::class);
                $minBalance = 1.0; // 最低余额要求：1元
                
                if (!$billingService->checkBalance($userId, $minBalance)) {
                    // 获取当前余额
                    $user = ObjectManager::getInstance(FrontendUser::class)->load($userId);
                    $currentBalance = (float)($user->getData('balance') ?? 0.0);
                    
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('账户余额不足，无法创建助手。当前余额：¥%{1}，最低要求：¥%{2}。请先充值！', 
                            number_format($currentBalance, 2), 
                            number_format($minBalance, 2)
                        ),
                        'error_code' => 'INSUFFICIENT_BALANCE',
                        'current_balance' => $currentBalance,
                        'required_balance' => $minBalance,
                        'recharge_url' => $this->getBackendUrl('ai/backend/recharge')
                    ]);
                }
            }
            
            if ($id > 0) {
                $assistant = $this->getAiAssistant()->reset()->load($id);
                if (!$assistant->getId()) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('助手不存在')
                    ]);
                }
            } else {
                $assistant = $this->getAiAssistant()->reset();
                // 确保表名被正确设置
                $assistant->setTableName('ai_assistant');
            }

            // 设置数据
            $currentTime = time();
            $data = [
                'name' => $name,
                'prompt' => $prompt,
                'adapter_code' => $adapterCode,
                'adapter_params' => $adapterParams, // 适配器参数
                'model_code' => $modelCode,
                'description' => $description,
                'model_config' => $modelConfig,
                'mcp_config' => $mcpConfig,
                'is_active' => $isActive,
                // API配置字段
                'api_key' => $apiKey,
                'proxy_config' => $proxyConfig,
                // 租赁相关字段
                'is_rentable' => $isRentable,
                'rental_type' => $rentalType,
                'rental_price' => $rentalPrice,
                'category' => $category,
                'tags' => $tags,
                'updated_time' => $currentTime
            ];
            
            // 如果是新建，添加created_time和owner_id
            if (!$assistant->getId()) {
                $data['created_time'] = $currentTime;
                // TODO: 从session获取当前登录用户ID作为owner_id
                $data['owner_id'] = 1; // 暂时硬编码
            }
            
            $assistant->setData($data);
            
            // 调试输出
            $debug_data = [
                'input' => $data,
                'model_data' => $assistant->getData(),
                'model_data_for_save' => $assistant->getModelData(),
                'table' => $assistant->getTableName(),
                'has_id' => $assistant->getId() ? true : false,
                'primary_key' => $assistant->_primary_key ?? 'unknown'
            ];

            $assistant->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('保存成功'),
                'data' => ['id' => $assistant->getId()]
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '保存失败：' . $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'debug_data' => isset($debug_data) ? $debug_data : null
                ]
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
            $assistant = $this->getAiAssistant()->reset()->load($id);
            
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
                'message' => __('删除失败：%{1}', $e->getMessage())
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
            $assistant = $this->getAiAssistant()->reset()->load($id);
            
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
                'message' => __('操作失败：%{1}', $e->getMessage())
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

